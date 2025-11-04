<?php include_once PLUGITIFY_DIR . 'template/panel/header.php'; ?>
<div id="app">
    <div class="ai-app">
        <aside class="ai-sidebar">
            <div class="ai-sidebar__top">
                <div class="ai-sidebar__logo">
                    <img src="<?= PLUGITIFY_URL.'assets/img/logo-1024x328.webp'; ?>" alt="Pluginity" loading="lazy" />
                </div>
                <button class="ai-btn ai-btn--primary" type="button" @click="newChat">New chat</button>
            </div>
            <div class="ai-sidebar__section">
                <div class="ai-sidebar__label">My chats</div>
                <ul class="ai-chatlist">
                    <li v-for="chat in chats" 
                        :key="chat.id"
                        :class="['ai-chatlist__item', { 'ai-chatlist__item--active': chat.id === currentChatId }]"
                        @click="selectChat(chat.id)">
                        <span class="ai-chatlist__icon">🧩</span>
                        <span class="ai-chatlist__title">{{ chat.title }}</span>
                        <button class="ai-chatlist__delete" @click.stop="deleteChat(chat.id)" title="Delete chat">×</button>
                    </li>
                </ul>
            </div>
        </aside>
        <main class="ai-main">
            <div class="ai-main__inner">
                <div class="ai-thread" ref="chatThread" aria-live="polite">
                    <div v-if="currentMessages.length === 0" class="ai-empty-state">
                        <div class="ai-empty-state__content">
                            <h2>Start a conversation</h2>
                            <p>Ask me anything about building WordPress plugins.</p>
                        </div>
                    </div>
                    <div v-for="message in currentMessages" 
                         :key="message.id"
                         :class="['ai-msg', message.role === 'user' ? 'ai-msg--user' : 'ai-msg--bot']">
                        <div v-if="message.role === 'bot'" class="ai-avatar ai-avatar--bot" aria-hidden="true"></div>
                        <div class="ai-bubble">
                            <div v-if="message.isTyping" class="ai-typing-indicator">
                                <span></span>
                                <span></span>
                                <span></span>
                                <div v-if="currentPendingStep" class="ai-typing-step ai-typing-step--shimmer" style="font-size: 15px; text-align: center;">
                                    {{ currentPendingStep }}
                                </div>
                            </div>
                            <div v-else v-html="formatMessage(message.content)"></div>
                            <!-- Show loading indicator from start until agent completely finishes (streaming or tasks) -->
                            <div v-if="!message.isTyping && (message.isStreaming || (message.tasks && message.tasks.length > 0 && hasActiveTasks(message.tasks)))" class="ai-loading-tasks">
                                <span class="ai-loading-spinner"></span>
                                <span>{{ getLoadingText(message) }}</span>
                            </div>
                            <!-- Tasks display below bot message bubble - outside the bubble -->
                            <div v-if="message.role === 'bot' && message.tasks && message.tasks.length > 0" class="ai-msg-tasks">
                                <transition-group name="task-list" tag="div" class="ai-task-list">
                                    <div v-for="task in getLastTasks(message.tasks, 3)" :key="task.id" class="ai-task">
                                        <div class="ai-task__header">
                                            <span class="ai-task__icon" :class="getTaskIconClass(task.status)">{{ getTaskIcon(task.status) }}</span>
                                            <span class="ai-task__title">{{ task.taskName }}</span>
                                        </div>
                                    </div>
                                </transition-group>
                            </div>
                            <div v-if="!message.isTyping" class="ai-meta">
                                <span class="ai-meta__clock" aria-hidden="true"></span>
                                {{ formatTime(message.timestamp) }}
                            </div>
                            
                        </div>
                        <div v-if="message.role === 'user'" class="ai-avatar ai-avatar--user" aria-hidden="true"></div>
                        
                        
                    </div>
                </div>
                <form class="ai-composer" @submit.prevent="sendMessage">
                    <label for="ai-input" class="screen-reader-text">Your message</label>
                    <textarea 
                        id="ai-input" 
                        v-model="messageInput"
                        @keydown.enter.exact.prevent="sendMessage"
                        @keydown.enter.shift.exact="messageInput += '\n'"
                        rows="1"
                        placeholder="Send a message..."
                        :disabled="isLoading"
                        ref="messageInput"
                        required></textarea>
                    <button type="submit" class="ai-btn ai-btn--send" :disabled="isLoading || !messageInput.trim()" aria-label="Send">
                        <span v-if="isLoading">⏳</span>
                        <span v-else>Send</span>
                    </button>
                </form>
            </div>
        </main>
    </div>
</div>

<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
<script type="module">
const { createApp } = Vue;

createApp({
    data() {
        return {
            chats: [],
            currentChatId: null,
            currentMessages: [],
            messageInput: '',
            isLoading: false,
            nextChatId: 1,
            nextMessageId: 1,
            typingMessageId: null,
            currentPendingStep: null,
            agent: null,
            agentInitialized: false,
            taskManager: null,
            messageTasksMap: {}, // Map message IDs to their tasks
            ajaxUrl: '<?= admin_url('admin-ajax.php'); ?>',
            nonce: '<?= wp_create_nonce('plugitify_chat_nonce'); ?>',
            agentUrl: '<?= PLUGITIFY_URL.'assets/js/agent.js'; ?>'
        }
    },
    mounted() {
        this.loadChats();
        // Don't start polling on mount - only when user sends a message
        this.$nextTick(() => {
            // Auto-resize textarea
            const textarea = this.$refs.messageInput;
            if (textarea) {
                textarea.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
            }
        });
    },
    methods: {
        async loadChats() {
            try {
                const response = await this.callAPI('plugitify_get_chats', {}, 'GET');
                if (response && response.success && response.data && response.data.chats) {
                    this.chats = response.data.chats.map(chat => ({
                        id: chat.id,
                        title: chat.title,
                        createdAt: chat.createdAt,
                        messages: [] // Messages loaded separately
                    }));
                    
                    if (this.chats.length > 0) {
                        this.currentChatId = this.chats[0].id;
                        await this.loadMessages();
                    } else {
                        // Create default welcome chat
                        await this.newChat();
                    }
                } else {
                    // No chats, create default
                    await this.newChat();
                }
            } catch (error) {
                console.error('Error loading chats:', error);
                alert('Error loading chats: ' + error.message);
                // Create default chat on error
                await this.newChat();
            }
        },
        async newChat() {
            try {
                const response = await this.callAPI('plugitify_save_chat', {
                    title: 'New Chat'
                });
                if (response && response.success && response.data && response.data.chat_id) {
                    const newChat = {
                        id: response.data.chat_id,
                        title: response.data.title || 'New Chat',
                        createdAt: new Date().toISOString(),
                        messages: []
                    };
                    this.chats.unshift(newChat);
                    this.currentChatId = newChat.id;
                    this.currentMessages = [];
                    this.messageTasksMap = {};
                    
                    // Update agent chat ID
                    if (this.agent) {
                        this.agent.setChatId(this.currentChatId);
                    }
                    
                    this.$refs.messageInput?.focus();
                } else {
                    throw new Error('Invalid response from server');
                }
            } catch (error) {
                console.error('Error creating new chat:', error);
                alert('Error creating new chat: ' + error.message);
            }
        },
        async selectChat(chatId) {
            this.currentChatId = chatId;
            this.currentPendingStep = null;
            if (this.typingMessageId) {
                this.removeMessage(this.typingMessageId);
                this.typingMessageId = null;
            }
            this.isLoading = false;
            this.messageTasksMap = {};
            await this.loadMessages();
        },
        async loadMessages() {
            if (!this.currentChatId) return;
            
            try {
                const response = await this.callAPI('plugitify_get_messages', {
                    chat_id: this.currentChatId
                }, 'GET');
                
                if (response.success && response.data.messages) {
                    this.currentMessages = response.data.messages.map(msg => ({
                        id: msg.id,
                        role: msg.role,
                        content: msg.content,
                        timestamp: new Date(msg.timestamp)
                    }));
                    
                    if (this.currentMessages.length > 0) {
                        this.nextMessageId = Math.max(...this.currentMessages.map(m => m.id)) + 1;
                    } else {
                        this.nextMessageId = 1;
                    }
                    
                    this.$nextTick(() => {
                        this.scrollToBottom();
                    });
                }
            } catch (error) {
                console.error('Error loading messages:', error);
                this.currentMessages = [];
            }
        },
        updateMessageTasks(messageId, tasks) {
            if (!messageId) return;
            const message = this.currentMessages.find(m => m.id === messageId);
            if (message) {
                // Vue 3: Direct assignment works with reactivity
                message.tasks = tasks || [];
            }
            this.messageTasksMap[messageId] = tasks || [];
        },
        getActiveTasksForMessage(messageId) {
            if (!this.taskManager || !this.currentChatId) return [];
            const message = this.currentMessages.find(m => m.id === messageId);
            if (!message) return [];
            
            const allTasks = this.taskManager.getTasks(this.currentChatId);
            
            // If message has taskCountBefore, only return tasks created after this message
            if (message.taskCountBefore !== undefined) {
                // Return only tasks that were created after this message (new tasks)
                return allTasks.slice(message.taskCountBefore);
            }
            
            // Fallback: return all tasks (for backward compatibility)
            return allTasks;
        },
        async deleteChat(chatId) {
            if (!confirm('Are you sure you want to delete this chat?')) {
                return;
            }
            
            try {
                const response = await this.callAPI('plugitify_delete_chat', {
                    chat_id: chatId
                });
                
                if (response.success) {
                    // Clear tasks from localStorage
                    if (this.taskManager) {
                        this.taskManager.clearTasks(chatId);
                    }
                    
                    this.chats = this.chats.filter(c => c.id !== chatId);
                    if (this.currentChatId === chatId) {
                        if (this.chats.length > 0) {
                            this.currentChatId = this.chats[0].id;
                            await this.loadMessages();
                        } else {
                            this.currentChatId = null;
                            this.currentMessages = [];
                            this.messageTasksMap = {};
                        }
                    }
                }
            } catch (error) {
                console.error('Error deleting chat:', error);
            }
        },
        async initializeAgent() {
            if (this.agentInitialized) return;
            
            try {
                // Import Agent Framework
                const agentModule = await import(this.agentUrl);
                const { Agent, DeepseekProvider, Tool, ToolProperty, TaskManager } = agentModule;
                
                // Create TaskManager
                this.taskManager = new TaskManager();
                
                // Create Deepseek provider
                const provider = new DeepseekProvider({
                    apiKey: 'sk-93c6a02788dd454baa0f34a07b9ca3c7',
                    baseURL: 'https://api.deepseek.com/v1',
                    model: 'deepseek-chat',
                    temperature: 0.4
                });
                
                // Create agent
                this.agent = new Agent(provider);
                this.agent.setInstructions(this.getSystemPrompt());
                this.agent.setTaskManager(this.taskManager);
                this.agent.setChatId(this.currentChatId);
                
                // Create and add tools
                const tools = await this.createTools();
                for (const tool of tools) {
                    this.agent.addTool(tool);
                }
                
                // Setup event listeners
                this.agent.observe((agent, event, data) => {
                    console.log('Agent event:', event, data);
                    
                    // Handle task events
                    if (event === 'task-created' || event === 'task-updated') {
                        // Find the current bot message and update its tasks
                        const botMessages = this.currentMessages.filter(m => m.role === 'bot' && !m.isTyping);
                        if (botMessages.length > 0) {
                            const lastBotMessage = botMessages[botMessages.length - 1];
                            // Show only tasks created for this message
                            if (this.taskManager && this.currentChatId) {
                                const messageTasks = this.getActiveTasksForMessage(lastBotMessage.id);
                                this.updateMessageTasks(lastBotMessage.id, messageTasks);
                            }
                        }
                    }
                });
                
                this.agentInitialized = true;
                console.log('Agent initialized successfully');
            } catch (error) {
                console.error('Error initializing agent:', error);
                throw error;
            }
        },
        async createTools() {
            // Import Tool classes
            const agentModule = await import(this.agentUrl);
            const { Tool, ToolProperty } = agentModule;
            
            // Helper to call tool API
            const callToolAPI = async (toolName, params) => {
                const formData = new FormData();
                formData.append('action', `plugitify_tool_${toolName}`);
                formData.append('nonce', this.nonce);
                formData.append('chat_id', this.currentChatId || 0);
                for (let key in params) {
                    // For content field, use base64 encoding to preserve special characters
                    if (key === 'content' || key === 'new_content') {
                        // Encode content as base64 to preserve all special characters (UTF-8 safe)
                        let contentStr = params[key];
                        if (typeof contentStr !== 'string') {
                            contentStr = String(contentStr);
                        }
                        // Use UTF-8 safe base64 encoding
                        const encoded = btoa(unescape(encodeURIComponent(contentStr)));
                        formData.append(key, encoded);
                        formData.append(key + '_encoded', '1'); // Flag to indicate encoding
                    } else {
                        formData.append(key, params[key]);
                    }
                }
                
                const response = await fetch(this.ajaxUrl, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    return result.data.result || result.data.message || 'Success';
                } else {
                    throw new Error(result.data?.message || result.message || 'Tool execution failed');
                }
            };
            
            // Create tools using Agent Framework
            const createDirectoryTool = new Tool('create_directory', 'Create a directory/folder for the WordPress plugin. Use this to create the main plugin folder and subdirectories like includes, assets, etc.');
            createDirectoryTool.addProperty(new ToolProperty('path', 'string', 'The full path of the directory to create (relative to plugins directory or absolute path). Example: \'my-plugin\' or \'my-plugin/includes\'', true));
            createDirectoryTool.setCallable(async (path) => await callToolAPI('create_directory', { path }));
            
            const createFileTool = new Tool('create_file', 'Create a PHP file with code content for the WordPress plugin. Use this to create main plugin file, class files, and any other PHP files needed.');
            createFileTool.addProperty(new ToolProperty('file_path', 'string', 'The file path relative to plugins directory or absolute path. Example: \'my-plugin/my-plugin.php\'', true));
            createFileTool.addProperty(new ToolProperty('content', 'string', 'The complete PHP code content to write to the file. Include PHP opening tag and all necessary code.', true));
            createFileTool.setCallable(async (file_path, content) => await callToolAPI('create_file', { file_path, content }));
            
            const deleteFileTool = new Tool('delete_file', 'Delete a file from the WordPress plugin directory. Use this to remove unwanted files or clean up plugin files.');
            deleteFileTool.addProperty(new ToolProperty('file_path', 'string', 'The file path relative to plugins directory or absolute path. Example: \'my-plugin/old-file.php\'', true));
            deleteFileTool.setCallable(async (file_path) => await callToolAPI('delete_file', { file_path }));
            
            const deleteDirectoryTool = new Tool('delete_directory', 'Delete a directory and all its contents recursively from the WordPress plugin directory. Use this to remove plugin folders or clean up directories.');
            deleteDirectoryTool.addProperty(new ToolProperty('path', 'string', 'The directory path relative to plugins directory or absolute path. Example: \'my-plugin\' or \'my-plugin/includes\'', true));
            deleteDirectoryTool.setCallable(async (path) => await callToolAPI('delete_directory', { path }));
            
            const readFileTool = new Tool('read_file', 'Read the content of any file. Use this to read PHP files, text files, JSON files, or any other file type.');
            readFileTool.addProperty(new ToolProperty('file_path', 'string', 'The full path of the file to read (relative to plugins directory, WordPress root, or absolute path). Example: \'my-plugin/my-plugin.php\'', true));
            readFileTool.setCallable(async (file_path) => await callToolAPI('read_file', { file_path }));
            
            const editFileLineTool = new Tool('edit_file_line', 'Edit a specific line or lines in a file. Use this to modify existing code in a file by replacing specific line(s) with new content.');
            editFileLineTool.addProperty(new ToolProperty('file_path', 'string', 'The full path of the file to edit. Example: \'my-plugin/my-plugin.php\'', true));
            editFileLineTool.addProperty(new ToolProperty('line_number', 'number', 'The line number to edit (1-based index).', true));
            editFileLineTool.addProperty(new ToolProperty('new_content', 'string', 'The new content to replace the line with. Can be empty string to delete the line.', true));
            editFileLineTool.addProperty(new ToolProperty('line_count', 'number', 'Number of lines to replace (default: 1).', false));
            editFileLineTool.setCallable(async (file_path, line_number, new_content, line_count = 1) => await callToolAPI('edit_file_line', { file_path, line_number, new_content, line_count }));
            
            const listPluginsTool = new Tool('list_plugins', 'Get a list of all installed WordPress plugins with their details (name, version, description, status, etc.). Use this to check existing plugins before creating a new one to avoid name conflicts.');
            listPluginsTool.addProperty(new ToolProperty('status', 'string', 'Filter plugins by status: \'all\' (default), \'active\', \'inactive\', or \'active-network\'. Leave empty for all plugins.', false));
            listPluginsTool.setCallable(async (status) => await callToolAPI('list_plugins', { status: status || 'all' }));
            
            const deactivatePluginTool = new Tool('deactivate_plugin', 'Deactivate a WordPress plugin. Use this to disable a plugin that is currently active. The plugin files will remain but it will be deactivated.');
            deactivatePluginTool.addProperty(new ToolProperty('plugin_file', 'string', 'The plugin file path (e.g., \'my-plugin/my-plugin.php\') or plugin name. You can get the exact plugin file path from the list_plugins tool.', true));
            deactivatePluginTool.setCallable(async (plugin_file) => await callToolAPI('deactivate_plugin', { plugin_file }));
            
            const extractPluginStructureTool = new Tool('extract_plugin_structure', 'Extract and analyze the structure/graph of a WordPress plugin. This analyzes the plugin directory to understand its architecture, classes, functions, hooks, and file organization.');
            extractPluginStructureTool.addProperty(new ToolProperty('plugin_name', 'string', 'The name/slug of the plugin directory. Example: \'my-plugin\' or the plugin folder name.', true));
            extractPluginStructureTool.setCallable(async (plugin_name) => await callToolAPI('extract_plugin_structure', { plugin_name }));
            
            return [
                createDirectoryTool,
                createFileTool,
                deleteFileTool,
                deleteDirectoryTool,
                readFileTool,
                editFileLineTool,
                listPluginsTool,
                deactivatePluginTool,
                extractPluginStructureTool
            ];
        },
        getSystemPrompt() {
            return `You are an expert WordPress plugin developer AI agent. Your role is to analyze user requirements, design plugin architecture, generate appropriate plugin names, and create complete WordPress plugin code.

You understand WordPress coding standards, hooks, filters, best practices, and plugin structure.

You can create new plugins OR update/modify existing plugins based on user requirements. Not every request requires creating a new plugin - you should update existing plugins when that makes more sense.

CRITICAL: You must NEVER modify, edit, delete, or change ANY files in the plugin directory that matches PLUGITIFY_DIR or contains 'plugitify' or 'Pluginity' in its path. This is the core system plugin and must remain untouched.

ABSOLUTELY FORBIDDEN: You are STRICTLY PROHIBITED from modifying, editing, deactivating, or changing the Pluginity/plugitify plugin in ANY way. This plugin is completely off-limits and must never be touched, edited, or deactivated under any circumstances.

CRITICAL PLUGIN EDITING RULE: Before editing, modifying, or changing ANY code in an existing plugin, you MUST first deactivate that plugin using the deactivate_plugin tool. This prevents potential errors, conflicts, or issues during code modification. The workflow is: 1) Deactivate the plugin, 2) Make your code changes, 3) Optionally reactivate after completion.

CRITICAL: Break down work into logical, sequential tool calls. Each tool call will automatically create its own task/step.

CRITICAL LANGUAGE RULE: Your conversational responses to users should match the language they used in their message.
  - If they write in Persian/Farsi, respond in Persian; if English, respond in English

CRITICAL PLUGIN CREATION RULE: Before creating a new plugin, analyze the conversation history to understand if a plugin was already created in this chat session.
  - Check the conversation context to see if a plugin was mentioned or created earlier
  - If a plugin was created earlier in this chat, you MUST try to edit/modify that existing plugin instead of creating a new one
  - You should only create a NEW plugin if:
    1. The user explicitly requests a completely new plugin (e.g., 'create a new plugin', 'make another plugin', 'I want a different plugin')
    2. The existing plugin cannot be edited or modified to meet the new requirements (the plugin structure is incompatible or editing would break it)
  - If editing the existing plugin, read the plugin files using read_file tool, understand its structure using extract_plugin_structure tool, and then modify it accordingly

CRITICAL POST-CREATION MESSAGE RULE: When you create a plugin, the plugin files are ALREADY created in the WordPress plugins directory. You MUST NOT tell the user to:
  - Upload the plugin manually
  - Install the plugin
  - Manually activate the plugin (unless they explicitly ask)
  - Do any manual file operations

Instead, you should say:
  - "Plugin created successfully in the WordPress plugins directory"
  - "You can now activate it from the Plugins menu" (only if relevant)
  - Focus on what the plugin does and how it works, not installation steps

IMPORTANT: When creating plugin headers, always use: Author: 'wpagentify', Author URI: 'https://wpagentify.com/', and Plugin URI: 'https://wpagentify.com/'.

After completing all work, provide a clear, natural language summary. DO NOT return JSON format. Always use natural, conversational text.`;
        },
        async sendMessage() {
            if (!this.messageInput.trim() || this.isLoading) return;
            
            // Ensure we have a chat_id
            if (!this.currentChatId || this.currentChatId <= 0) {
                await this.newChat();
                await new Promise(resolve => setTimeout(resolve, 100));
            }
            
            const messageContent = this.messageInput.trim();
            this.messageInput = '';
            
            // Save user message to database
            try {
                await this.callAPI('plugitify_save_chat', {
                    chat_id: this.currentChatId,
                    title: messageContent.substring(0, 30) + (messageContent.length > 30 ? '...' : '')
                }).catch(() => {});
            } catch (e) {}
            
            // Show user message
            const userMessage = {
                id: 'user_' + Date.now(),
                role: 'user',
                content: messageContent,
                timestamp: new Date()
            };
            this.addMessage(userMessage);
            
            // Save user message to database
            try {
                await this.callAPI('plugitify_send_message', {
                    message: messageContent,
                    chat_id: this.currentChatId
                });
            } catch (e) {
                console.error('Error saving user message:', e);
            }
            
            // Update chat title if it's the first user message
            const chat = this.chats.find(c => c.id === this.currentChatId);
            if (chat && chat.title === 'New Chat' && this.currentMessages.filter(m => m.role === 'user').length === 1) {
                const newTitle = messageContent.substring(0, 30) + (messageContent.length > 30 ? '...' : '');
                chat.title = newTitle;
                this.callAPI('plugitify_save_chat', {
                    chat_id: this.currentChatId,
                    title: newTitle
                }).catch(err => console.error('Error updating chat title:', err));
            }
            
            // Show typing indicator
            const typingMessage = {
                id: 'typing_' + Date.now(),
                role: 'bot',
                content: '',
                isTyping: true,
                timestamp: new Date()
            };
            this.addMessage(typingMessage);
            this.typingMessageId = typingMessage.id;
            this.isLoading = true;
            
            try {
                // Initialize agent if not already done
                if (!this.agentInitialized) {
                    await this.initializeAgent();
                }
                
                // Update agent chat ID if changed
                if (this.agent && this.currentChatId) {
                    this.agent.setChatId(this.currentChatId);
                }
                
                // Import Agent Framework message classes
                const agentModule = await import(this.agentUrl);
                const { UserMessage, AssistantMessage } = agentModule;
                
                // Build chat history from current messages
                const chatHistory = [];
                for (const msg of this.currentMessages) {
                    if (msg.isTyping) continue;
                    if (msg.role === 'user') {
                        chatHistory.push(new UserMessage(msg.content));
                    } else if (msg.role === 'bot' && msg.content) {
                        chatHistory.push(new AssistantMessage(msg.content));
                    }
                }
                
                // Remove typing indicator
                if (this.typingMessageId) {
                    this.removeMessage(this.typingMessageId);
                    this.typingMessageId = null;
                }
                
                // Get task count before creating bot message to track only new tasks
                const taskCountBefore = this.taskManager && this.currentChatId ? this.taskManager.getTasks(this.currentChatId).length : 0;
                
                // Create bot message for streaming content
                const botMessage = {
                    id: 'bot_' + Date.now(),
                    role: 'bot',
                    content: '',
                    timestamp: new Date(),
                    isStreaming: true,
                    tasks: [],
                    taskCountBefore: taskCountBefore // Track task count before this message
                };
                this.addMessage(botMessage);
                const botMessageIndex = this.currentMessages.findIndex(m => m.id === botMessage.id);
                
                // Stream agent execution
                let fullOutput = '';
                let accumulatedContent = '';
                
                try {
                    // Create user message
                    const userMsg = new UserMessage(messageContent);
                    
                    // Stream the response
                    const stream = this.agent.stream([...chatHistory, userMsg]);
                    
                    for await (const chunk of stream) {
                        // Handle tool calls
                        if (chunk.tools && chunk.tools.length > 0) {
                            const toolName = chunk.tools[0].name || 'unknown';
                            const toolInfo = `\n\n🔧 Executing tool: ${toolName}...\n`;
                            //accumulatedContent += toolInfo;
                            if (botMessageIndex >= 0) {
                                this.currentMessages[botMessageIndex].content = accumulatedContent;
                            }
                            this.$forceUpdate();
                            this.$nextTick(() => {
                                this.scrollToBottom();
                            });
                            continue;
                        }
                        
                        // Handle content
                        if (chunk.content) {
                            accumulatedContent += chunk.content;
                            fullOutput = accumulatedContent;
                            if (botMessageIndex >= 0) {
                                this.currentMessages[botMessageIndex].content = fullOutput;
                            }
                            this.$forceUpdate();
                            this.$nextTick(() => {
                                this.scrollToBottom();
                            });
                        }
                        
                        // Handle usage
                        if (chunk.usage) {
                            // Usage data received, can be logged if needed
                            console.log('Usage:', chunk.usage);
                        }
                    }
                    
                    // Final update
                    if (botMessageIndex >= 0) {
                        this.currentMessages[botMessageIndex].content = fullOutput || accumulatedContent || 'Response received';
                        this.currentMessages[botMessageIndex].isStreaming = false;
                        
                        // Get only tasks created after this message and attach to message
                        if (this.taskManager && this.currentChatId) {
                            const messageTasks = this.getActiveTasksForMessage(botMessage.id);
                            this.updateMessageTasks(botMessage.id, messageTasks);
                        }
                        
                        // Keep checking for tasks and update UI until all are completed
                        const checkTasksInterval = setInterval(() => {
                            if (this.taskManager && this.currentChatId) {
                                const messageTasks = this.getActiveTasksForMessage(botMessage.id);
                                const activeTasks = messageTasks.filter(t => t.status === 'in_progress');
                                
                                // Show all tasks for this message (both active and completed) until all are done
                                this.updateMessageTasks(botMessage.id, messageTasks);
                                
                                // If no active tasks and we have completed tasks, clear them after animation
                                if (activeTasks.length === 0 && messageTasks.length > 0) {
                                    clearInterval(checkTasksInterval);
                                    
                                    // Wait for transition animation to complete, then clear tasks completely
                                    setTimeout(() => {
                                        // Clear tasks from message completely (this will hide the ai-msg-tasks box)
                                        this.updateMessageTasks(botMessage.id, []);
                                        
                                        // Also clear from localStorage for this message's tasks
                                        const allTasks = this.taskManager.getTasks(this.currentChatId);
                                        const remainingTasks = allTasks.slice(0, botMessage.taskCountBefore || 0);
                                        this.taskManager.saveTasks(this.currentChatId, remainingTasks);
                                    }, 1500); // Wait for animation to complete (400ms) + show completed (1100ms)
                                } else if (activeTasks.length === 0 && messageTasks.length === 0) {
                                    // No tasks at all for this message, clear interval immediately
                                    clearInterval(checkTasksInterval);
                                    // Clear tasks from message if somehow still set
                                    this.updateMessageTasks(botMessage.id, []);
                                }
                            } else {
                                clearInterval(checkTasksInterval);
                            }
                        }, 300); // Check every 300ms for faster updates
                    }
                    
                    // Save bot message to database
                    try {
                        await this.callAPI('plugitify_send_message', {
                            message: fullOutput || accumulatedContent || 'Response received',
                            chat_id: this.currentChatId,
                            role: 'assistant'
                        });
                    } catch (e) {
                        console.error('Error saving bot message:', e);
                    }
                    
                } catch (streamError) {
                    console.error('Streaming error:', streamError);
                    // Fallback to regular chat if streaming fails
                    const userMsg = new UserMessage(messageContent);
                    const response = await this.agent.chat([...chatHistory, userMsg]);
                    
                    fullOutput = response.getContent() || 'Response received';
                    if (botMessageIndex >= 0) {
                        this.currentMessages[botMessageIndex].content = fullOutput;
                        this.currentMessages[botMessageIndex].isStreaming = false;
                    }
                    
                    try {
                        await this.callAPI('plugitify_send_message', {
                            message: fullOutput,
                            chat_id: this.currentChatId,
                            role: 'assistant'
                        });
                    } catch (e) {
                        console.error('Error saving bot message:', e);
                    }
                }
                
                this.isLoading = false;
                
            } catch (error) {
                console.error('Error sending message:', error);
                if (this.typingMessageId) {
                    this.removeMessage(this.typingMessageId);
                    this.typingMessageId = null;
                }
                
                const errorMessage = {
                    id: 'error_' + Date.now(),
                    role: 'bot',
                    content: 'Sorry, I encountered an error. Please try again: ' + error.message,
                    timestamp: new Date()
                };
                this.addMessage(errorMessage);
                this.isLoading = false;
            } finally {
                this.$refs.messageInput?.focus();
            }
        },
        async callAPI(action, data = {}, method = 'POST') {
            const ajaxUrl = '<?= admin_url('admin-ajax.php'); ?>';
            const nonce = '<?= wp_create_nonce('plugitify_chat_nonce'); ?>';
            
            let url = ajaxUrl;
            let options = {
                method: method,
                credentials: 'same-origin',
                headers: {}
            };
            
            if (method === 'POST') {
                const formData = new FormData();
                formData.append('action', action);
                formData.append('nonce', nonce);
                for (let key in data) {
                    formData.append(key, data[key]);
                }
                options.body = formData;
            } else {
                // GET request
                url += '?action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(nonce);
                for (let key in data) {
                    url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(data[key]);
                }
            }
            
            try {
                const response = await fetch(url, options);
                
                // Try to parse response even if status is not ok
                let result;
                let responseText = '';
                try {
                    responseText = await response.text();
                    result = JSON.parse(responseText);
                } catch (e) {
                    throw new Error(`Invalid JSON response (Status: ${response.status}): ${responseText.substring(0, 500)}`);
                }
                
                // Log for debugging
                if (!response.ok || !result.success) {
                    console.error('API Error Response:', {
                        status: response.status,
                        statusText: response.statusText,
                        result: result,
                        url: url.substring(0, 200)
                    });
                }
                
                if (!response.ok) {
                    throw new Error(result.data?.message || result.message || `HTTP ${response.status}: ${response.statusText}`);
                }
                
                if (!result.success) {
                    throw new Error(result.data?.message || result.message || 'Unknown error');
                }
                
                return result;
            } catch (error) {
                console.error('API Call Error:', {
                    action: action,
                    method: method,
                    url: url.substring(0, 200),
                    error: error.message
                });
                throw error;
            }
        },
        async sendToAPI(message) {
            const response = await this.callAPI('plugitify_send_message', {
                message: message,
                chat_id: this.currentChatId
            });
            return response.data;
        },
        addMessage(message) {
            // Don't insert typing messages - they're handled separately
            if (message.isTyping) {
                this.currentMessages.push(message);
                this.$nextTick(() => {
                    this.scrollToBottom();
                });
                return;
            }
            
            // Insert message in correct position based on timestamp
            // But skip typing messages in comparison
            const messageTime = new Date(message.timestamp).getTime();
            let insertIndex = this.currentMessages.length;
            
            // Find the correct position to insert
            for (let i = 0; i < this.currentMessages.length; i++) {
                const existingMsg = this.currentMessages[i];
                // Skip typing messages in comparison
                if (existingMsg.isTyping) {
                    continue;
                }
                const existingTime = new Date(existingMsg.timestamp).getTime();
                if (messageTime < existingTime) {
                    insertIndex = i;
                    break;
                }
            }
            
            // Insert at the correct position
            this.currentMessages.splice(insertIndex, 0, message);
            
            this.$nextTick(() => {
                this.scrollToBottom();
            });
        },
        removeMessage(messageId) {
            // Remove from currentMessages only (messages are saved in DB)
            this.currentMessages = this.currentMessages.filter(m => m.id !== messageId);
        },
        scrollToBottom() {
            const thread = this.$refs.chatThread;
            if (thread) {
                thread.scrollTop = thread.scrollHeight;
            }
        },
        formatTime(date) {
            if (!date) return '';
            const d = new Date(date);
            const hours = d.getHours().toString().padStart(2, '0');
            const minutes = d.getMinutes().toString().padStart(2, '0');
            return `${hours}:${minutes}`;
        },
        formatMessage(content) {
            if (!content) return '';
            // Simple markdown-like formatting
            return content
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                .replace(/`(.*?)`/g, '<code>$1</code>')
                .replace(/\n/g, '<br>');
        },
        getTaskIcon(status) {
            const icons = {
                'in_progress': '⏳',
                'completed': '✓',
                'failed': '✗',
                'pending': '⏸'
            };
            return icons[status] || '○';
        },
        getTaskIconClass(status) {
            const classes = {
                'in_progress': 'ai-tasklist__icon--in-progress',
                'completed': 'ai-tasklist__icon--completed',
                'failed': 'ai-tasklist__icon--failed',
                'pending': 'ai-tasklist__icon--pending'
            };
            return classes[status] || '';
        },
        getStepIcon(status) {
            const icons = {
                'in_progress': '⏳',
                'completed': '✓',
                'failed': '✗',
                'pending': '⏸'
            };
            return icons[status] || '○';
        },
        hasActiveTasks(tasks) {
            if (!tasks || tasks.length === 0) return false;
            return tasks.some(t => t.status === 'in_progress');
        },
        getLoadingText(message) {
            // If streaming and no tasks yet
            if (message.isStreaming && (!message.tasks || message.tasks.length === 0)) {
                return 'Generating response...';
            }
            
            // If has active tasks
            if (message.tasks && message.tasks.length > 0) {
                const activeTasks = message.tasks.filter(t => t.status === 'in_progress');
                
                if (activeTasks.length > 0) {
                    // Get the first active task
                    const currentTask = activeTasks[0];
                    const taskName = currentTask.taskName || 'Processing';
                    
                    // If multiple tasks, show count
                    if (activeTasks.length > 1) {
                        return `${taskName} (${activeTasks.length} tasks in progress)`;
                    }
                    
                    return taskName;
                }
                
                // If streaming but tasks completed
                if (message.isStreaming) {
                    return 'Generating response...';
                }
            }
            
            // Default
            return 'Processing...';
        },
        getLastTasks(tasks, count = 3) {
            if (!tasks || tasks.length === 0) return [];
            // Return last N tasks (most recent)
            return tasks.slice(-count);
        },
    }
}).mount('#app');
</script>
<?php include_once PLUGITIFY_DIR . 'template/panel/footer.php'; ?>