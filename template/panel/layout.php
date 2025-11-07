<?php include_once PLUGITIFY_DIR . 'template/panel/header.php'; ?>
<!-- Loading Overlay -->
<div id="page-loader" class="page-loader">
    <div class="page-loader__content">
        <div class="page-loader__dots">
            <div class="page-loader__dot"></div>
            <div class="page-loader__dot"></div>
            <div class="page-loader__dot"></div>
        </div>
        <div class="page-loader__text" id="loader-text"><span>Loading...</span></div>
    </div>
</div>
<div id="app">
    <div class="ai-app">
        <aside class="ai-sidebar">
            <div class="ai-sidebar__top">
                <div class="ai-sidebar__logo">
                    <img src="<?php echo esc_url(PLUGITIFY_URL.'assets/img/logo-1024x328.webp'); ?>" alt="Pluginity" loading="lazy" />
                </div>
                <button class="ai-btn ai-btn--primary" type="button" @click="newChat">New chat</button>
            </div>
            <div class="ai-sidebar__section">
                <div class="ai-sidebar__label">My chats</div>
                <ul class="ai-chatlist">
                    <li v-for="chat in chats" 
                        :key="chat.id"
                        :class="['ai-chatlist__item', { 'ai-chatlist__item--active': chat.id === currentChatId }]"
                        @click="selectChat(chat.id)"
                        :title="chat.title">
                        <span class="ai-chatlist__icon">🧩</span>
                        <span class="ai-chatlist__title">{{ chat.title }}</span>
                        <button class="ai-chatlist__delete" @click.stop="deleteChat(chat.id)" title="Delete chat">×</button>
                    </li>
                </ul>
            </div>
        </aside>
        <main class="ai-main">
            <div class="ai-main__inner">
                <div class="ai-settings-header">
                    <button class="ai-settings-btn" @click="showSettingsModal = true" type="button" title="AI Settings">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M12 1v6m0 6v6M5.64 5.64l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M5.64 18.36l4.24-4.24m4.24-4.24l4.24-4.24"></path>
                        </svg>
                    </button>
                </div>
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
                        <div class="ai-bubble" :dir="detectDirection(message.content)">
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
                <div v-if="errorMessage" class="ai-error-message">
                    <span class="ai-error-message__text">{{ errorMessage }}</span>
                    <button class="ai-error-message__retry" @click="retryLastMessage" type="button" aria-label="Retry">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <polyline points="1 20 1 14 7 14"></polyline>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                    </button>
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
    
    <!-- Settings Modal -->
    <div v-if="showSettingsModal" class="ai-settings-modal" @click.self="showSettingsModal = false">
    <div class="ai-settings-modal__content">
        <div class="ai-settings-modal__header">
            <h2>Settings</h2>
            <button class="ai-settings-modal__close" @click="showSettingsModal = false" type="button" aria-label="Close">×</button>
        </div>
        <div class="ai-settings-modal__body">
            <div v-if="settingsMessage" :class="['ai-settings-message', settingsMessageType === 'success' ? 'ai-settings-message--success' : 'ai-settings-message--error']">
                {{ settingsMessage }}
            </div>
            <div class="ai-settings-field">
                <label for="ai-model">Model</label>
                <select id="ai-model" v-model="aiSettings.model" @change="onModelChange" class="ai-settings-select">
                    <optgroup label="OpenAI">
                        <option value="gpt-4">GPT-4</option>
                        <option value="gpt-4-turbo">GPT-4 Turbo</option>
                        <option value="gpt-4o">GPT-4o</option>
                        <option value="gpt-4o-mini">GPT-4o Mini</option>
                        <option value="gpt-3.5-turbo">GPT-3.5 Turbo</option>
                    </optgroup>
                    <optgroup label="Claude (Anthropic)">
                        <option value="claude-3-opus-20240229">Claude 3 Opus</option>
                        <option value="claude-3-sonnet-20240229">Claude 3 Sonnet</option>
                        <option value="claude-3-haiku-20240307">Claude 3 Haiku</option>
                        <option value="claude-3-5-sonnet-20240620">Claude 3.5 Sonnet</option>
                    </optgroup>
                    <optgroup label="Gemini (Google)">
                        <option value="gemini-pro">Gemini Pro</option>
                        <option value="gemini-pro-vision">Gemini Pro Vision</option>
                        <option value="gemini-1.5-pro">Gemini 1.5 Pro</option>
                        <option value="gemini-1.5-flash">Gemini 1.5 Flash</option>
                    </optgroup>
                    <optgroup label="Deepseek">
                        <option value="deepseek-chat">Deepseek Chat</option>
                        <option value="deepseek-coder">Deepseek Coder</option>
                    </optgroup>
                </select>
            </div>
            <div class="ai-settings-field">
                <label for="ai-api-key">API Key</label>
                <input 
                    id="ai-api-key" 
                    type="password" 
                    v-model="aiSettings.apiKey" 
                    placeholder="Enter your API key"
                    autocomplete="off"
                />
            </div>
            <div class="ai-settings-info">
                <p><strong>Provider:</strong> {{ getProviderName() }}</p>
                <p><strong>Base URL:</strong> {{ getBaseURL() }}</p>
            </div>
        </div>
        <div class="ai-settings-modal__footer">
            <button class="ai-btn ai-btn--secondary" @click="showSettingsModal = false" type="button" :disabled="savingSettings">Cancel</button>
            <button class="ai-btn ai-btn--primary" @click.prevent="saveSettings" type="button" :disabled="savingSettings">
                <span v-if="savingSettings" class="ai-btn__loading">
                    <span class="ai-btn__spinner"></span>
                    Saving...
                </span>
                <span v-else>Save Settings</span>
            </button>
        </div>
    </div>
    </div>
</div>

<?php
/**
 * Note: Vue.js is loaded from local file (assets/js/vue.global.prod.js)
 * This template file is loaded directly via template_include filter,
 * not through WordPress's normal template hierarchy. Therefore, wp_enqueue_script()
 * cannot be used here. The script URL is properly escaped using esc_url().
 */
?>
<script src="<?php echo esc_url(PLUGITIFY_URL.'assets/js/vue.global.prod.js'); ?>"></script>
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
            errorMessage: null,
            lastUserMessage: null, // Store last user message for retry
            ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
            nonce: '<?php echo esc_js(wp_create_nonce('plugitify_chat_nonce')); ?>',
            agentUrl: '<?php echo esc_url(PLUGITIFY_URL.'assets/js/agent.js'); ?>',
            showSettingsModal: false,
            aiSettings: {
                apiKey: '',
                model: 'deepseek-chat'
            },
            savingSettings: false,
            settingsMessage: '',
            settingsMessageType: 'success'
        }
    },
    mounted() {
        // Hide loading overlay when Vue is mounted
        this.$nextTick(() => {
            const loader = document.getElementById('page-loader');
            if (loader) {
                loader.classList.add('page-loader--hidden');
                // Remove from DOM after animation completes
                setTimeout(() => {
                    if (loader.parentNode) {
                        loader.parentNode.removeChild(loader);
                    }
                }, 500);
            }
        });
        
        this.loadChats();
        this.loadAISettings();
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
                    })).reverse(); // Reverse to show latest chats first
                    
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
            this.errorMessage = null; // Clear error when switching chats
            
            // Reset agent's chat history when switching chats
            if (this.agent) {
                const agentChatHistory = this.agent.resolveChatHistory();
                agentChatHistory.clear();
                this.agent.setChatId(chatId);
            }
            
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
                
                // Clean up old tasks on initialization to prevent localStorage overflow
                try {
                    this.taskManager.cleanupAllOldTasks();
                } catch (e) {
                    console.log('Error during initial cleanup:', e);
                }
                
                // Get AI settings
                const settings = this.aiSettings;
                
                // Auto-detect provider and base URL from model
                const provider = this.getProviderFromModel(settings.model);
                const baseURL = this.getBaseURLFromModel(settings.model);
                
                // Create provider based on settings
                // Note: Currently only DeepseekProvider is available, but it's OpenAI-compatible
                // So we can use it for OpenAI, Deepseek, and other OpenAI-compatible APIs
                const providerConfig = {
                    apiKey: settings.apiKey || 'sk-93c6a02788dd454baa0f34a07b9ca3c7',
                    baseURL: baseURL,
                    model: settings.model || 'deepseek-chat',
                    temperature: 0.7
                };
                
                const providerInstance = new DeepseekProvider(providerConfig);
                
                // Create agent
                this.agent = new Agent(providerInstance);
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
            createDirectoryTool.addProperty(new ToolProperty('path', 'string', 'REQUIRED: The full path of the directory to create (relative to plugins directory or absolute path). Example: \'my-plugin\' or \'my-plugin/includes\'. This parameter is MANDATORY and must be provided.', true));
            createDirectoryTool.setCallable(async (path) => await callToolAPI('create_directory', { path }));
            
            const createFileTool = new Tool('create_file', 'Create a PHP file with code content for the WordPress plugin. Use this to create main plugin file, class files, and any other PHP files needed.');
            createFileTool.addProperty(new ToolProperty('file_path', 'string', 'REQUIRED: The file path relative to plugins directory or absolute path. Example: \'my-plugin/my-plugin.php\'. This parameter is MANDATORY and must be provided.', true));
            createFileTool.addProperty(new ToolProperty('content', 'string', 'REQUIRED: The complete PHP code content to write to the file. Include PHP opening tag and all necessary code. This parameter is MANDATORY and must be provided.', true));
            createFileTool.setCallable(async (file_path, content) => await callToolAPI('create_file', { file_path, content }));
            
            const deleteFileTool = new Tool('delete_file', 'Delete a file from the WordPress plugin directory. Use this to remove unwanted files or clean up plugin files.');
            deleteFileTool.addProperty(new ToolProperty('file_path', 'string', 'REQUIRED: The file path relative to plugins directory or absolute path. Example: \'my-plugin/old-file.php\'. This parameter is MANDATORY and must be provided.', true));
            deleteFileTool.setCallable(async (file_path) => await callToolAPI('delete_file', { file_path }));
            
            const deleteDirectoryTool = new Tool('delete_directory', 'Delete a directory and all its contents recursively from the WordPress plugin directory. Use this to remove plugin folders or clean up directories.');
            deleteDirectoryTool.addProperty(new ToolProperty('path', 'string', 'REQUIRED: The directory path relative to plugins directory or absolute path. Example: \'my-plugin\' or \'my-plugin/includes\'. This parameter is MANDATORY and must be provided.', true));
            deleteDirectoryTool.setCallable(async (path) => await callToolAPI('delete_directory', { path }));
            
            const readFileTool = new Tool('read_file', 'Read the content of any file. Use this to read PHP files, text files, JSON files, or any other file type.');
            readFileTool.addProperty(new ToolProperty('file_path', 'string', 'REQUIRED: The full path of a FILE (NOT a directory) to read. Must be a file path ending with an extension like .php, .js, .css, .txt, etc. (relative to plugins directory, WordPress root, or absolute path). Example: \'my-plugin/my-plugin.php\'. CRITICAL: This must be a FILE path, NOT a directory path. If you need to list directory contents, use a different approach. This parameter is MANDATORY and must be provided.', true));
            readFileTool.setCallable(async (file_path) => await callToolAPI('read_file', { file_path }));
            
            const editFileLineTool = new Tool('edit_file_line', 'Edit a specific line or lines in a file, or append new lines to the end. Use this to modify existing code in a file by replacing specific line(s) with new content. If the line number exceeds the file length, the content will be automatically appended to the end of the file.');
            editFileLineTool.addProperty(new ToolProperty('file_path', 'string', 'REQUIRED: The full path of the file to edit. Example: \'my-plugin/my-plugin.php\'. This parameter is MANDATORY and must be provided.', true));
            editFileLineTool.addProperty(new ToolProperty('line_number', 'number', 'REQUIRED: The line number to edit (1-based index). If the line number is greater than the file length, the content will be appended to the end of the file. This parameter is MANDATORY and must be provided.', true));
            editFileLineTool.addProperty(new ToolProperty('new_content', 'string', 'REQUIRED: The new content to replace the line with. Can be empty string to delete the line. Can contain multiple lines (use \\n for line breaks). This parameter is MANDATORY and must be provided.', true));
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
            
            const toggleWpDebugTool = new Tool('toggle_wp_debug', 'Enable or disable WordPress debug mode (WP_DEBUG). When enabling, it clears previous log files and configures WordPress to log errors to wp-content/debug.log without displaying them on screen. When disabling, it turns off debug mode.');
            toggleWpDebugTool.addProperty(new ToolProperty('enable', 'boolean', 'True to enable WP_DEBUG, false to disable it. When enabled, debug.log will be cleared and WP_DEBUG_LOG will be set to true and WP_DEBUG_DISPLAY will be set to false.', true));
            toggleWpDebugTool.setCallable(async (enable) => await callToolAPI('toggle_wp_debug', { enable }));
            
            const readDebugLogTool = new Tool('read_debug_log', 'Read WordPress debug log file (wp-content/debug.log). This reads error logs, warnings, and notices that WordPress has recorded. Useful for debugging plugin issues and errors.');
            readDebugLogTool.addProperty(new ToolProperty('lines', 'number', 'Number of lines to read from the end of the log file. Default is 100. Set to 0 or a large number to read the entire file.', false));
            readDebugLogTool.setCallable(async (lines = 100) => await callToolAPI('read_debug_log', { lines }));
            
            const checkWpDebugStatusTool = new Tool('check_wp_debug_status', 'Check the current status of WordPress debug mode. This shows whether WP_DEBUG, WP_DEBUG_LOG, and WP_DEBUG_DISPLAY are enabled or disabled, and provides information about the debug.log file (if it exists). Use this before enabling/disabling debug mode to see the current state.');
            checkWpDebugStatusTool.setCallable(async () => await callToolAPI('check_wp_debug_status', {}));
            
            const searchReplaceInFileTool = new Tool('search_replace_in_file', '⚠️ STRICT SIZE LIMITS - READ CAREFULLY: This tool is ONLY for SMALL text replacements. MAX 5 replacements, MAX 300 chars per search string, MAX 300 chars per replace string, MAX 2000 total chars. If ANY string exceeds 300 chars OR total exceeds 2000 chars, you MUST use edit_file_line instead. Count characters BEFORE using this tool. Each replacement can replace all occurrences or just the first one.');
            searchReplaceInFileTool.addProperty(new ToolProperty('file_path', 'string', 'The full path of the file to edit. Example: \'my-plugin/my-plugin.php\'', true));
            searchReplaceInFileTool.addProperty(new ToolProperty('replacements', 'array', '⚠️ MANDATORY SIZE CHECK BEFORE USE: Array of replacement objects (MAX 5). Each "search" MUST be ≤300 chars. Each "replace" MUST be ≤300 chars. Total of all search+replace strings MUST be ≤2000 chars. If ANY exceeds these limits, DO NOT use this tool - use edit_file_line instead. Format: [{"search": "text≤300chars", "replace": "text≤300chars", "replace_all": true}]. Example: [{"search": "old", "replace": "new"}]', true));
            searchReplaceInFileTool.setCallable(async (file_path, replacements) => {
                // Strict validation before sending
                const MAX_REPLACEMENTS = 5;
                const MAX_CHARS_PER_STRING = 300;
                const MAX_TOTAL_CHARS = 2000;
                
                if (!Array.isArray(replacements)) {
                    throw new Error('Replacements must be an array');
                }
                
                if (replacements.length > MAX_REPLACEMENTS) {
                    throw new Error(`Maximum ${MAX_REPLACEMENTS} replacements allowed per call. You provided ${replacements.length}.`);
                }
                
                let totalChars = 0;
                for (let i = 0; i < replacements.length; i++) {
                    const r = replacements[i];
                    if (!r.search || !r.replace) {
                        throw new Error(`Replacement at index ${i} must have both "search" and "replace" keys`);
                    }
                    
                    const searchLen = String(r.search).length;
                    const replaceLen = String(r.replace).length;
                    
                    if (searchLen > MAX_CHARS_PER_STRING) {
                        throw new Error(`❌ VIOLATION: Search string at index ${i} is ${searchLen} characters, but maximum allowed is ${MAX_CHARS_PER_STRING}. You MUST use edit_file_line tool instead of search_replace_in_file for strings longer than ${MAX_CHARS_PER_STRING} characters.`);
                    }
                    
                    if (replaceLen > MAX_CHARS_PER_STRING) {
                        throw new Error(`❌ VIOLATION: Replace string at index ${i} is ${replaceLen} characters, but maximum allowed is ${MAX_CHARS_PER_STRING}. You MUST use edit_file_line tool instead of search_replace_in_file for strings longer than ${MAX_CHARS_PER_STRING} characters.`);
                    }
                    
                    totalChars += searchLen + replaceLen;
                }
                
                if (totalChars > MAX_TOTAL_CHARS) {
                    throw new Error(`❌ VIOLATION: Total characters (${totalChars}) exceeds maximum limit of ${MAX_TOTAL_CHARS}. You MUST use edit_file_line tool instead, or split into multiple smaller calls.`);
                }
                
                return await callToolAPI('search_replace_in_file', { file_path, replacements: JSON.stringify(replacements) });
            });
            
            const updateChatTitleTool = new Tool('update_chat_title', 'Update the title of the current chat. Use this tool when the chat title is "New Chat" or "New chat" to change it to a more descriptive title based on the user\'s first message.');
            updateChatTitleTool.addProperty(new ToolProperty('title', 'string', 'REQUIRED: The new title for the chat. Should be a concise, descriptive title based on the user\'s message (max 50 characters). This parameter is MANDATORY and must be provided.', true));
            updateChatTitleTool.setCallable(async (title) => {
                const result = await callToolAPI('update_chat_title', { 
                    title: title,
                    chat_id: this.currentChatId 
                });
                // Update the chat title in the UI immediately
                const chat = this.chats.find(c => c.id === this.currentChatId);
                if (chat) {
                    chat.title = title;
                }
                return result;
            });
            
            return [
                createDirectoryTool,
                createFileTool,
                deleteFileTool,
                deleteDirectoryTool,
                readFileTool,
                editFileLineTool,
                listPluginsTool,
                deactivatePluginTool,
                extractPluginStructureTool,
                toggleWpDebugTool,
                readDebugLogTool,
                checkWpDebugStatusTool,
                searchReplaceInFileTool,
                updateChatTitleTool
            ];
        },
        getSystemPrompt() {
            // Get current chat title
            const currentChat = this.chats.find(c => c.id === this.currentChatId);
            const currentTitle = currentChat ? currentChat.title : 'New Chat';
            
            return this.getSystemPromptBase() + `\n\nCRITICAL CHAT TITLE RULE: The current chat title is "${currentTitle}". Do NOT use the update_chat_title tool unless explicitly asked by the user or if this is the first message and the title is "New Chat".`;
        },
        getSystemPromptBase() {
            return `You are an expert WordPress plugin developer AI agent. Your role is to analyze user requirements, design plugin architecture, generate appropriate plugin names, and create complete WordPress plugin code.

You understand WordPress coding standards, hooks, filters, best practices, and plugin structure.

🚨🚨🚨 CRITICAL TOOL LIMITATION - READ FIRST 🚨🚨🚨
The search_replace_in_file tool has STRICT size limits that CANNOT be violated:
- Each "search" string MUST be ≤300 characters (not 301, not 420, not any number above 300)
- Each "replace" string MUST be ≤300 characters (not 301, not 420, not any number above 300)
- Maximum 5 replacements per call
- Maximum 2000 total characters (all search+replace combined)
BEFORE using search_replace_in_file, you MUST count characters. If ANY string exceeds 300 chars, you MUST use edit_file_line instead. Violating these limits will cause the tool to FAIL.

🔴🔴🔴 CRITICAL TOOL PARAMETER RULE - ABSOLUTELY MANDATORY 🔴🔴🔴
When calling ANY tool, you MUST provide ALL required parameters as specified in the tool schema. The tool schema clearly shows which parameters are marked as "required" and MUST be provided:
- ALWAYS check the tool definition before calling it
- ALL parameters marked as "REQUIRED" or "MANDATORY" in the tool description MUST be provided
- If a tool fails with "Missing required parameter: X", you MUST check the tool definition and provide parameter X correctly in your next call
- NEVER call a tool without its required parameters - this will cause the tool to fail immediately
- For read_file tool: The file_path parameter MUST be a FILE path (ending with .php, .js, .css, etc.), NOT a directory path
- For create_directory tool: The path parameter is REQUIRED and must be provided
- For create_file tool: Both file_path AND content parameters are REQUIRED and must be provided
- If you receive an error about missing parameters, DO NOT retry the same call - instead, check the tool schema and provide ALL required parameters correctly

You can create new plugins OR update/modify existing plugins based on user requirements. Not every request requires creating a new plugin - you should update existing plugins when that makes more sense.

CRITICAL: You must NEVER modify, edit, delete, or change ANY files in the plugin directory that matches PLUGITIFY_DIR or contains 'plugitify' or 'Pluginity' in its path. This is the core system plugin and must remain untouched.

ABSOLUTELY FORBIDDEN: You are STRICTLY PROHIBITED from modifying, editing, deactivating, or changing the Pluginity/plugitify plugin in ANY way. This plugin is completely off-limits and must never be touched, edited, or deactivated under any circumstances.

CRITICAL PLUGIN EDITING RULE: Before editing, modifying, or changing ANY code in an existing plugin, you MUST first deactivate that plugin using the deactivate_plugin tool. This prevents potential errors, conflicts, or issues during code modification. The workflow is: 1) Deactivate the plugin, 2) Make your code changes, 3) Optionally reactivate after completion.

IMPORTANT FILE EDITING RULE: You have two tools for editing files:
  - edit_file_line: Use this when you need to edit specific lines by line number (good for small, precise changes, or when strings are longer than 300 chars)
  - search_replace_in_file: Use this ONLY for SMALL text replacements (MAX 5 replacements, MAX 300 chars per string, MAX 2000 total chars). This tool can perform multiple search/replace operations in a single call, but ONLY if all strings are ≤300 chars. Example: [{"search": "old_function()", "replace": "new_function()", "replace_all": true}]
  - ⚠️ CRITICAL: Before using search_replace_in_file, you MUST verify that ALL strings are ≤300 chars. If ANY string exceeds 300 chars, you MUST use edit_file_line instead. Do NOT attempt to use search_replace_in_file with large strings - it will fail.

🚨 CRITICAL search_replace_in_file LIMITATIONS - ABSOLUTELY MANDATORY - READ THIS BEFORE USING:
  
  BEFORE using search_replace_in_file, you MUST:
  1. Count the character length of EVERY "search" string - if ANY exceeds 300 chars, DO NOT use this tool
  2. Count the character length of EVERY "replace" string - if ANY exceeds 300 chars, DO NOT use this tool
  3. Add up ALL search + replace string lengths - if total exceeds 2000 chars, DO NOT use this tool
  4. Count the number of replacements - if more than 5, DO NOT use this tool
  
  STRICT LIMITS (NO EXCEPTIONS):
  - MAXIMUM 5 replacements per single call - NEVER exceed this limit
  - MAXIMUM 300 characters per search string - Each "search" field must be EXACTLY 300 characters or less (not 301, not 420, not any number above 300)
  - MAXIMUM 300 characters per replace string - Each "replace" field must be EXACTLY 300 characters or less (not 301, not 420, not any number above 300)
  - MAXIMUM 2000 total characters - The sum of ALL search + replace strings combined must be EXACTLY 2000 characters or less
  
  MANDATORY WORKFLOW:
  - Step 1: Before calling search_replace_in_file, manually count characters in each string
  - Step 2: If ANY string is >300 chars OR total >2000 chars OR count >5, STOP and use edit_file_line instead
  - Step 3: Only call search_replace_in_file if ALL limits are satisfied
  
  IF YOU VIOLATE THESE LIMITS:
  - The tool will FAIL and return an error
  - You will waste a tool call attempt
  - You MUST then use edit_file_line to complete the task
  
  WHEN TO USE edit_file_line INSTEAD:
  - If ANY search string > 300 characters → use edit_file_line
  - If ANY replace string > 300 characters → use edit_file_line
  - If total characters > 2000 → use edit_file_line
  - If more than 5 replacements needed → use edit_file_line (or split into multiple calls)
  - When in doubt → ALWAYS use edit_file_line
  
  REMEMBER: This tool is ONLY for SMALL, SHORT text replacements. For anything larger, use edit_file_line.

CRITICAL: Break down work into logical, sequential tool calls. Each tool call will automatically create its own task/step.

CRITICAL LANGUAGE RULE: Your conversational responses to users should match the language they used in their message.
  - If they write in Persian/Farsi, respond in Persian; if English, respond in English

CRITICAL CONVERSATION CONTINUITY RULE - ABSOLUTE PRIORITY: This is ALWAYS a CONTINUATION of an existing conversation. You MUST:
  - ABSOLUTELY NEVER greet the user with "Hello", "Hi", "سلام", "خوبم", "چطوری", or ANY greeting words/phrases
  - ABSOLUTELY NEVER introduce yourself, say who you are, or list your capabilities unless explicitly asked
  - ABSOLUTELY NEVER start responses with greetings or pleasantries
  - ALWAYS respond directly to the user's question or request without any greetings, introductions, or pleasantries
  - If the conversation history shows ANY previous messages (user or assistant), treat this as a continuation and skip ALL greetings
  - The ONLY exception: if you can 100% confirm this is the VERY FIRST message ever in a completely empty conversation (no history at all)
  - When in doubt, assume it's a continuation and skip greetings completely

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

CRITICAL NAMING CONVENTION RULE: To prevent conflicts with other plugins, you MUST use a random prefix for ALL naming:
  - PHP class names: Generate a random 3-5 character prefix (e.g., 'Xyz_', 'Abc_', 'Mnp_') and use it for ALL class names (e.g., 'Xyz_Plugin_Main', 'Xyz_Admin_Settings', 'Xyz_Database_Handler')
  - PHP function names: Use the same random prefix for ALL functions (e.g., 'xyz_plugin_init', 'xyz_enqueue_scripts', 'xyz_register_settings')
  - CSS class names and IDs: Use the same random prefix for ALL CSS selectors (e.g., '.xyz-plugin-container', '#xyz-settings-form', '.xyz-button-primary')
  - JavaScript variable names: Use the same random prefix for JavaScript variables and functions when applicable
  - The prefix should be unique and randomly generated for each plugin to ensure no conflicts
  - Example: If prefix is 'Qwe_', then use 'Qwe_Plugin_Class', 'qwe_init_function', '.qwe-css-class', etc.
  - This is MANDATORY to prevent naming collisions with other WordPress plugins and themes

WORDPRESS DEBUG TOOLS: You have access to three debugging tools:
  - check_wp_debug_status: Check current debug mode status and configuration. Use this to see if debug mode is enabled or disabled
  - toggle_wp_debug: Enable/disable WordPress debug mode. When enabling, it automatically clears old logs and configures proper logging (WP_DEBUG=true, WP_DEBUG_LOG=true, WP_DEBUG_DISPLAY=false)
  - read_debug_log: Read WordPress error logs from wp-content/debug.log. Useful for troubleshooting plugin issues and errors

CRITICAL AUTO-RECOVERY SYSTEM: When ANY tool execution fails with an error, you MUST automatically try alternative approaches. NEVER stop or give up after a single error. Instead:
  - Analyze the error message carefully to understand what went wrong
  - If a file is not found: Use create_file tool to create it first, then retry the original operation
  - If a directory doesn't exist: Use create_file (it auto-creates directories) or create_directory first
  - If a file cannot be edited: Try reading it first to verify it exists, or create it if missing
  - If missing required parameter error: READ the error carefully, check what parameter is missing, and provide it correctly - DO NOT retry the same call without fixing the parameters
  - If one approach fails: Think of 2-3 alternative methods and try them sequentially
  - Keep trying different approaches until you succeed or have exhausted all reasonable options
  - ALWAYS inform the user that you're trying an alternative approach (e.g., "The file doesn't exist yet, so I'll create it first...")
  - DO NOT just report the error and stop - proactively solve it!
  
CRITICAL ANTI-LOOP PROTECTION: You have a maximum of 3 attempts for the same error. After 3 consecutive identical errors, the system will stop automatically. Therefore:
  - NEVER call the same tool with the same incorrect parameters more than once
  - If a tool fails due to missing parameters, FIX the parameters before calling again
  - If you don't know a required parameter, either ask the user or use a different approach
  - DO NOT enter infinite retry loops - each retry must be meaningfully different from the previous attempt

Example Auto-Recovery Flow:
  1. Try to read file X → ERROR: File not found
  2. Recognize the error and explain: "The file doesn't exist yet, so I'll create it first"
  3. Use create_file to create file X with appropriate content
  4. Confirm success and continue with the original task
  5. Complete the user's request successfully

Remember: Your goal is to complete the user's request successfully, not to fail at the first obstacle. Be resourceful, adaptive, and persistent!

After completing all work, provide a clear, natural language summary. DO NOT return JSON format. Always use natural, conversational text.`;
        },
        async sendMessage() {
            if (!this.messageInput.trim() || this.isLoading) return;
            
            // Clear any previous error message
            this.errorMessage = null;
            
            // Ensure we have a chat_id
            if (!this.currentChatId || this.currentChatId <= 0) {
                await this.newChat();
                await new Promise(resolve => setTimeout(resolve, 100));
            }
            
            const messageContent = this.messageInput.trim();
            this.messageInput = '';
            
            // Store last user message for retry functionality
            this.lastUserMessage = messageContent;
            
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
            
            // Note: Chat title will be updated by AI using update_chat_title tool if title is "New Chat"
            
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
                
                // Build chat history from current messages (excluding the new user message we're about to send)
                const chatHistory = [];
                for (const msg of this.currentMessages) {
                    if (msg.isTyping) continue;
                    // Exclude the current user message since we'll add it separately
                    if (msg.id === userMessage.id) continue;
                    if (msg.role === 'user') {
                        chatHistory.push(new UserMessage(msg.content));
                    } else if (msg.role === 'bot' && msg.content) {
                        chatHistory.push(new AssistantMessage(msg.content));
                    }
                }
                
                // Check if this is the first user message (no previous user messages in history)
                const isFirstUserMessage = chatHistory.filter(m => m instanceof UserMessage).length === 0;
                
                // Update system prompt with current chat title and first message status
                if (this.agent) {
                    const currentChat = this.chats.find(c => c.id === this.currentChatId);
                    const currentTitle = currentChat ? currentChat.title : 'New Chat';
                    const shouldUpdateTitle = isFirstUserMessage && (currentTitle === 'New Chat' || currentTitle === 'New chat');
                    
                    const systemPrompt = this.getSystemPromptBase() + `\n\nCRITICAL CHAT TITLE RULE: The current chat title is "${currentTitle}". ${shouldUpdateTitle ? 'This is the FIRST user message in this chat. You MUST use the update_chat_title tool to change the title to a descriptive title based on the user\'s message. The title should be concise (max 50 characters) and reflect the main topic or request from the user\'s message. This should be done as one of your first actions when responding.' : 'Do NOT use the update_chat_title tool unless explicitly asked by the user. The chat title has already been set or this is not the first message.'}`;
                    this.agent.setInstructions(systemPrompt);
                }
                
                // Initialize agent's chat history with existing messages
                // This ensures the agent sees the full conversation context and doesn't greet
                const agentChatHistory = this.agent.resolveChatHistory();
                
                // Always sync chat history with current messages to ensure continuity
                // This prevents the agent from thinking it's a new conversation
                if (chatHistory.length > 0) {
                    // Clear and repopulate with current messages to ensure sync
                    agentChatHistory.clear();
                    for (const msg of chatHistory) {
                        agentChatHistory.addMessage(msg);
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
                    
                    // Stream the response - agent will use its internal chat history + new user message
                    const stream = this.agent.stream([userMsg]);
                    
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
                                const activeTasks = messageTasks.filter(t => t.status === 'in_progress' || t.status === 'needs_recovery');
                                
                                // Convert needs_recovery to failed if they've been sitting too long (circuit breaker kicked in)
                                let needsSave = false;
                                messageTasks.forEach(task => {
                                    if (task.status === 'needs_recovery') {
                                        // Mark as failed so it stops showing as "in progress"
                                        task.status = 'failed';
                                        task.steps.forEach(step => {
                                            if (step.status === 'needs_recovery') {
                                                step.status = 'failed';
                                            }
                                        });
                                        needsSave = true;
                                    }
                                });
                                
                                // Save to localStorage if any changes were made
                                if (needsSave && this.taskManager) {
                                    const allTasks = this.taskManager.getTasks(this.currentChatId);
                                    this.taskManager.saveTasks(this.currentChatId, allTasks);
                                }
                                
                                // Show all tasks for this message (both active and completed) until all are done
                                this.updateMessageTasks(botMessage.id, messageTasks);
                                
                                // Recheck active tasks after conversion
                                const stillActiveTasks = messageTasks.filter(t => t.status === 'in_progress');
                                
                                // If no active tasks and we have completed tasks, clear them after animation
                                if (stillActiveTasks.length === 0 && messageTasks.length > 0) {
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
                
                // Set error message instead of adding bot message
                this.errorMessage = error.message || 'An error occurred. Please try again.';
                this.isLoading = false;
            } finally {
                this.$refs.messageInput?.focus();
            }
        },
        async callAPI(action, data = {}, method = 'POST') {
            const ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            const nonce = '<?php echo esc_js(wp_create_nonce('plugitify_chat_nonce')); ?>';
            
            console.log('callAPI called:', { action, data, method });
            
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
                console.log('POST request to:', url, 'with action:', action);
            } else {
                // GET request
                url += '?action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(nonce);
                for (let key in data) {
                    url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(data[key]);
                }
                console.log('GET request to:', url);
            }
            
            try {
                console.log('Fetching:', url, options);
                const response = await fetch(url, options);
                console.log('Response received:', response.status, response.statusText);
                
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
            
            // Normalize line breaks first
            let formatted = content.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            
            // Apply markdown-like formatting first (before converting line breaks)
            formatted = formatted
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                .replace(/`(.*?)`/g, '<code>$1</code>');
            
            // Convert line breaks to <br> tags
            // Note: white-space: pre-wrap in CSS will preserve other whitespace
            formatted = formatted.replace(/\n/g, '<br>');
            
            return formatted;
        },
        detectDirection(content) {
            if (!content) return 'ltr';
            
            // Remove HTML tags for direction detection
            const textOnly = content.replace(/<[^>]*>/g, '');
            
            // RTL character ranges:
            // Persian/Farsi: \u0600-\u06FF
            // Arabic: \u0600-\u06FF, \u0750-\u077F, \u08A0-\u08FF, \uFB50-\uFDFF, \uFE70-\uFEFF
            // Hebrew: \u0590-\u05FF
            const rtlPattern = /[\u0590-\u05FF\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/;
            
            // Check if text contains RTL characters
            if (rtlPattern.test(textOnly)) {
                return 'rtl';
            }
            
            return 'ltr';
        },
        getTaskIcon(status) {
            const icons = {
                'in_progress': '⏳',
                'completed': '✓',
                'failed': '✗',
                'pending': '⏸',
                'needs_recovery': '⚠'
            };
            return icons[status] || '○';
        },
        getTaskIconClass(status) {
            const classes = {
                'in_progress': 'ai-tasklist__icon--in-progress',
                'completed': 'ai-tasklist__icon--completed',
                'failed': 'ai-tasklist__icon--failed',
                'pending': 'ai-tasklist__icon--pending',
                'needs_recovery': 'ai-tasklist__icon--failed'
            };
            return classes[status] || '';
        },
        getStepIcon(status) {
            const icons = {
                'in_progress': '⏳',
                'completed': '✓',
                'failed': '✗',
                'pending': '⏸',
                'needs_recovery': '⚠'
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
        async retryLastMessage() {
            if (!this.lastUserMessage || this.isLoading) return;
            
            // Clear error message
            this.errorMessage = null;
            
            // Set the message input to the last user message
            this.messageInput = this.lastUserMessage;
            
            // Trigger send message
            await this.sendMessage();
        },
        async loadAISettings() {
            try {
                console.log('Loading AI settings...');
                const response = await this.callAPI('plugitify_get_ai_settings', {}, 'GET');
                console.log('Load AI settings response:', response);
                
                if (response && response.success && response.data) {
                    console.log('Settings loaded - full response.data:', response.data);
                    console.log('Settings loaded - response.data.data:', response.data.data);
                    
                    // Check if data is nested (response.data.data) or direct (response.data)
                    const settingsData = response.data.data || response.data;
                    console.log('Using settings data:', settingsData);
                    
                    // Direct assignment - Vue 3 reactivity handles this automatically
                    this.aiSettings.apiKey = settingsData.apiKey || '';
                    this.aiSettings.model = settingsData.model || 'deepseek-chat';
                    console.log('AI Settings after load:', this.aiSettings);
                    // Use nextTick to ensure DOM updates after data change
                    this.$nextTick(() => {
                        console.log('DOM updated, checking form values...');
                        const modelSelect = document.getElementById('ai-model');
                        const apiKeyInput = document.getElementById('ai-api-key');
                        if (modelSelect) {
                            console.log('Model select value:', modelSelect.value, 'Expected:', this.aiSettings.model);
                            if (modelSelect.value !== this.aiSettings.model) {
                                modelSelect.value = this.aiSettings.model;
                            }
                        }
                        if (apiKeyInput) {
                            console.log('API Key input value length:', apiKeyInput.value.length, 'Expected length:', this.aiSettings.apiKey.length);
                        }
                    });
                } else {
                    console.warn('No settings found, using defaults');
                }
            } catch (error) {
                console.error('Error loading AI settings:', error);
            }
        },
        async saveSettings(event) {
            // Prevent default and stop propagation
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            console.log('saveSettings called', this.aiSettings);
            
            if (this.savingSettings) {
                console.log('Already saving, ignoring...');
                return;
            }
            
            // Validate
            if (!this.aiSettings.model) {
                this.showSettingsMessage('Please select a model', 'error');
                return;
            }
            
            this.savingSettings = true;
            console.log('Starting to save settings...');
            
            try {
                console.log('Saving settings:', {
                    apiKey: this.aiSettings.apiKey ? '***' : '(empty)',
                    model: this.aiSettings.model
                });
                
                const response = await this.callAPI('plugitify_save_ai_settings', {
                    apiKey: this.aiSettings.apiKey || '',
                    model: this.aiSettings.model
                });
                
                console.log('Save settings response:', response);
                
                if (response && response.success) {
                    // Update local settings with saved data if provided
                    if (response.data && response.data.data) {
                        this.aiSettings = {
                            apiKey: response.data.data.apiKey || this.aiSettings.apiKey,
                            model: response.data.data.model || this.aiSettings.model
                        };
                        console.log('Updated local settings:', this.aiSettings);
                    }
                    
                    // Reset agent so it uses new settings
                    this.agentInitialized = false;
                    this.agent = null;
                    
                    // Show success message
                    this.showSettingsMessage('Settings saved successfully!', 'success');
                    
                    // Close modal after a delay
                    setTimeout(() => {
                        this.showSettingsModal = false;
                        this.savingSettings = false;
                        this.clearSettingsMessage();
                    }, 1500);
                } else {
                    this.savingSettings = false;
                    const errorMsg = response?.data?.message || response?.message || 'Unknown error';
                    console.error('Save settings failed:', errorMsg, response);
                    this.showSettingsMessage('Error saving settings: ' + errorMsg, 'error');
                }
            } catch (error) {
                this.savingSettings = false;
                console.error('Error saving settings:', error);
                this.showSettingsMessage('Error saving settings: ' + (error.message || 'Unknown error occurred'), 'error');
            }
        },
        getProviderFromModel(model) {
            if (!model) return 'deepseek';
            
            // OpenAI models
            if (model.startsWith('gpt-')) {
                return 'openai';
            }
            // Claude models
            if (model.startsWith('claude-')) {
                return 'claude';
            }
            // Gemini models
            if (model.startsWith('gemini-')) {
                return 'gemini';
            }
            // Deepseek models
            if (model.startsWith('deepseek-')) {
                return 'deepseek';
            }
            
            return 'deepseek'; // default
        },
        getBaseURLFromModel(model) {
            const provider = this.getProviderFromModel(model);
            const baseURLs = {
                'openai': 'https://api.openai.com/v1',
                'claude': 'https://api.anthropic.com/v1',
                'gemini': 'https://generativelanguage.googleapis.com/v1',
                'deepseek': 'https://api.deepseek.com/v1'
            };
            return baseURLs[provider] || 'https://api.deepseek.com/v1';
        },
        getProviderName() {
            const provider = this.getProviderFromModel(this.aiSettings.model);
            const names = {
                'openai': 'OpenAI',
                'claude': 'Claude (Anthropic)',
                'gemini': 'Gemini (Google)',
                'deepseek': 'Deepseek'
            };
            return names[provider] || 'Deepseek';
        },
        getBaseURL() {
            return this.getBaseURLFromModel(this.aiSettings.model);
        },
        onModelChange() {
            // Model changed, provider and base URL will be auto-detected
        },
        showSettingsMessage(message, type = 'success') {
            this.settingsMessage = message;
            this.settingsMessageType = type;
            // Auto clear after 5 seconds for errors, 3 seconds for success
            const timeout = type === 'error' ? 5000 : 3000;
            setTimeout(() => {
                this.clearSettingsMessage();
            }, timeout);
        },
        clearSettingsMessage() {
            this.settingsMessage = '';
        },
    },
    watch: {
        showSettingsModal(newVal) {
            if (newVal === true) {
                // When modal opens, reload settings to ensure we have the latest
                console.log('Modal opened, reloading settings...');
                this.loadAISettings();
                // Clear any previous messages
                this.clearSettingsMessage();
            } else {
                // Clear message when modal closes
                this.clearSettingsMessage();
            }
        }
    }
}).mount('#app');
</script>
<?php include_once PLUGITIFY_DIR . 'template/panel/footer.php'; ?>