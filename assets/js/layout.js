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
            ajaxUrl: window.plugitifyConfig?.ajaxUrl || '',
            nonce: window.plugitifyConfig?.nonce || '',
            agentUrl: window.plugitifyConfig?.agentUrl || '',
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
                // Update chat title rule for the new chat
                this.updateChatTitleRule(false);
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
                    
                    // Sort messages by timestamp (oldest first) to ensure correct order
                    this.currentMessages.sort((a, b) => {
                        return new Date(a.timestamp).getTime() - new Date(b.timestamp).getTime();
                    });
                    
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
                this.agent.setTaskManager(this.taskManager);
                this.agent.setChatId(this.currentChatId);
                
                // Initialize structured prompts
                this.initializeAgentPrompts();
                
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
        /**
         * Update chat title rule in agent (called before sending messages)
         * This method updates the dynamic chat title rule based on current chat state
         */
        updateChatTitleRule(isFirstMessage = false) {
            if (!this.agent) return;
            
            // Ensure prompts array exists
            if (!this.agent.prompts) {
                this.agent.prompts = [];
            }
            
            // Check if addPrompt method exists
            if (typeof this.agent.addPrompt !== 'function') {
                console.warn('Agent does not have addPrompt method. Skipping chat title rule update.');
                return;
            }
            
            const currentChat = this.chats.find(c => c.id === this.currentChatId);
            const currentTitle = currentChat ? currentChat.title : 'New Chat';
            
            // Remove existing chat title rule if any
            this.agent.prompts = this.agent.prompts.filter(p => p.id !== 'chat_title_rule');
            
            // Add new chat title rule
            const ruleDescription = isFirstMessage && (currentTitle === 'New Chat' || currentTitle === 'New chat')
                ? `Current chat title is "${currentTitle}". This is the FIRST user message. You MUST use update_chat_title tool to change it to a descriptive title (max 50 chars) based on user's message. Do this as one of your first actions.`
                : `Current chat title is "${currentTitle}". Do NOT use update_chat_title tool unless explicitly asked by the user.`;
            
            this.agent.addPrompt(
                'Chat Title Management',
                'system',
                'high',
                ruleDescription,
                { id: 'chat_title_rule', order: 0, dynamic: true }
            );
        },
        /**
         * Initialize structured prompts in the agent
         * This method adds all system prompts to the agent using the new prompt management system
         */
        initializeAgentPrompts() {
            if (!this.agent) return;
            
            // Ensure prompts array exists
            if (!this.agent.prompts) {
                this.agent.prompts = [];
            }
            
            // Clear existing prompts first (if method exists)
            if (typeof this.agent.clearPrompts === 'function') {
                this.agent.clearPrompts();
            } else {
                // Fallback: manually clear prompts array
                this.agent.prompts = [];
            }
            
            // Check if addPrompt method exists
            if (typeof this.agent.addPrompt !== 'function') {
                console.error('Agent does not have addPrompt method. Make sure agent.js is loaded correctly.');
                return;
            }
            
            // Base instruction
            this.agent.setInstructions('You are an expert WordPress plugin developer AI agent. Your role is to analyze user requirements, design plugin architecture, generate appropriate plugin names, and create complete WordPress plugin code.\n\nYou understand WordPress coding standards, hooks, filters, best practices, and plugin structure.');
            
            // TOOLS SECTION - Critical Tool Limitations (Split into smaller prompts)
            this.agent.addPrompt(
                'search_replace_in_file: Size Limits',
                'tools',
                'critical',
                `search_replace_in_file has STRICT limits: Each search/replace string ≤300 chars, max 5 replacements, max 2000 total chars. If ANY limit exceeded, use edit_file_line instead.`,
                { order: 1 }
            );
            
            this.agent.addPrompt(
                'search_replace_in_file: Character Count Check',
                'tools',
                'critical',
                `BEFORE using search_replace_in_file: Count EVERY search string length, count EVERY replace string length, add total, count replacements. If ANY exceeds limits, use edit_file_line.`,
                { order: 2 }
            );
            
            this.agent.addPrompt(
                'search_replace_in_file: When to Use edit_file_line',
                'tools',
                'high',
                `Use edit_file_line if: search >300 chars, replace >300 chars, total >2000 chars, or >5 replacements. When in doubt, use edit_file_line.`,
                { order: 3 }
            );
            
            this.agent.addPrompt(
                'Tool Parameters: Required Fields',
                'tools',
                'critical',
                `ALWAYS provide ALL required parameters. Check tool definition first. If error says "Missing required parameter: X", check definition and provide X correctly. Never retry without fixing parameters.`,
                { order: 4 }
            );
            
            this.agent.addPrompt(
                'Tool Parameters: Specific Rules',
                'tools',
                'high',
                `read_file: file_path must be a FILE (ends with .php, .js, etc.), NOT directory. create_directory: path is REQUIRED. create_file: file_path AND content are REQUIRED.`,
                { order: 5 }
            );
            
            this.agent.addPrompt(
                'File Editing: Tool Selection',
                'tools',
                'high',
                `Two editing tools: edit_file_line (for line-by-line edits, strings >300 chars) and search_replace_in_file (ONLY for small replacements ≤300 chars each, max 5, max 2000 total).`,
                { order: 6 }
            );
            
            // SYSTEM SECTION
            this.agent.addPrompt(
                'Workflow: Confirm Scenario Before Starting',
                'system',
                'critical',
                `Before starting any work (creating plugins, editing plugins, making changes): First, provide a brief summary of the scenario/plan to the user and ask for confirmation. Wait for user approval before proceeding with tool calls. Only ask questions if information is truly needed to proceed - don't ask unnecessary questions. If all required information is clear, proceed with confirmation request.`,
                { order: 0 }
            );
            
            this.agent.addPrompt(
                'Plugin Strategy: Create vs Modify',
                'system',
                'high',
                `Create new plugins OR update existing ones based on requirements. Update existing plugins when it makes more sense than creating new ones.`,
                { order: 1 }
            );
            
            this.agent.addPrompt(
                'Forbidden: Core System Plugin',
                'system',
                'critical',
                `NEVER modify/edit/delete files in directories matching PLUGITIFY_DIR or containing 'plugitify'/'Pluginity'. This core system plugin is completely off-limits.`,
                { order: 2 }
            );
            
            this.agent.addPrompt(
                'Plugin Editing: Deactivate First',
                'system',
                'high',
                `Before editing existing plugin code: 1) Deactivate plugin using deactivate_plugin tool, 2) Make changes, 3) Optionally reactivate.`,
                { order: 3 }
            );
            
            this.agent.addPrompt(
                'Work Organization',
                'system',
                'medium',
                `Break work into logical, sequential tool calls. Each call creates its own task/step automatically.`,
                { order: 4 }
            );
            
            this.agent.addPrompt(
                'Language Matching',
                'system',
                'high',
                `Match user's language: If Persian/Farsi, respond in Persian; if English, respond in English.`,
                { order: 5 }
            );
            
            this.agent.addPrompt(
                'No Greetings Rule',
                'system',
                'critical',
                `NEVER greet ("Hello", "Hi", "سلام", etc.) or introduce yourself. Always respond directly. Only exception: confirmed first message in empty conversation.`,
                { order: 6 }
            );
            
            this.agent.addPrompt(
                'Plugin Creation: Check History First',
                'system',
                'high',
                `Before creating new plugin: Check if plugin already created in this chat. If yes, edit existing plugin instead. Only create NEW if user explicitly requests it or existing cannot be modified.`,
                { order: 7 }
            );
            
            this.agent.addPrompt(
                'Plugin Creation: Editing Workflow',
                'system',
                'high',
                `When editing existing plugin: Use read_file to read files, extract_plugin_structure to understand structure, then modify accordingly.`,
                { order: 8 }
            );
            
            this.agent.addPrompt(
                'Post-Creation: No Manual Steps',
                'system',
                'high',
                `After creating plugin: Files are ALREADY in plugins directory. Don't tell user to upload/install/activate manually. Say "Plugin created successfully" and focus on what it does.`,
                { order: 9 }
            );
            
            this.agent.addPrompt(
                'Plugin Headers',
                'system',
                'medium',
                `Plugin headers: Author='wpagentify', Author URI='https://wpagentify.com/', Plugin URI='https://wpagentify.com/'.`,
                { order: 10 }
            );
            
            this.agent.addPrompt(
                'Naming: Random Prefix Required',
                'system',
                'high',
                `Use random 3-5 char prefix for ALL names (PHP classes, functions, CSS classes, JS variables). Example: 'Xyz_' → 'Xyz_Plugin_Main', 'xyz_init', '.xyz-container'. Prevents conflicts.`,
                { order: 11 }
            );
            
            this.agent.addPrompt(
                'Debug Tools Available',
                'system',
                'medium',
                `Three debug tools: check_wp_debug_status (check status), toggle_wp_debug (enable/disable), read_debug_log (read error logs).`,
                { order: 12 }
            );
            
            // ERROR HANDLING SECTION
            this.agent.addPrompt(
                'Error Recovery: Never Give Up',
                'error_handling',
                'critical',
                `When tool fails: Try alternative approaches. Never stop after single error. Analyze error, try different method, inform user of alternative approach.`,
                { order: 1 }
            );
            
            this.agent.addPrompt(
                'Error Recovery: File Not Found',
                'error_handling',
                'high',
                `If file not found: Use create_file to create it, then retry. If directory missing: Use create_file (auto-creates dirs) or create_directory first.`,
                { order: 2 }
            );
            
            this.agent.addPrompt(
                'Error Recovery: File Cannot Be Edited',
                'error_handling',
                'high',
                `If file cannot be edited: Read it first to verify exists, or create if missing.`,
                { order: 3 }
            );
            
            this.agent.addPrompt(
                'Error Recovery: Missing Parameters',
                'error_handling',
                'critical',
                `If "Missing required parameter: X" error: Read error carefully, check tool definition, provide X correctly. DO NOT retry same call without fixing parameters.`,
                { order: 4 }
            );
            
            this.agent.addPrompt(
                'Error Recovery: Alternative Methods',
                'error_handling',
                'high',
                `If one approach fails: Think of 2-3 alternative methods, try sequentially. Keep trying until success or all options exhausted.`,
                { order: 5 }
            );
            
            this.agent.addPrompt(
                'Anti-Loop: Max 3 Attempts',
                'error_handling',
                'critical',
                `Maximum 3 attempts for same error. After 3 consecutive identical errors, system stops. Never retry same tool with same wrong parameters. Each retry must be different.`,
                { order: 6 }
            );
            
            this.agent.addPrompt(
                'Anti-Loop: Fix Parameters',
                'error_handling',
                'critical',
                `If tool fails due to missing parameters: FIX parameters before retrying. If parameter unknown: Ask user or use different approach.`,
                { order: 7 }
            );
            
            // CHAT HISTORY & CONTEXT AWARENESS SECTION
            this.agent.addPrompt(
                'Chat History: Always Review Before Responding',
                'system',
                'critical',
                `ALWAYS review the ENTIRE chat history before responding. Read ALL previous messages (user and assistant) to understand the full context. Never respond without checking what was said before.`,
                { order: 13 }
            );
            
            this.agent.addPrompt(
                'Chat History: Avoid Repetition',
                'system',
                'critical',
                `If a plugin/task was already created/completed in this chat, DO NOT create it again. If a question was already answered, DO NOT answer it again. Check chat history to see what was done before.`,
                { order: 14 }
            );
            
            this.agent.addPrompt(
                'Chat History: No Greetings Mid-Conversation',
                'system',
                'critical',
                `NEVER greet ("Hello", "Hi", "سلام") in the middle of a conversation. Only greet if this is the FIRST message in an empty chat. If chat history exists, respond directly to the user's question without greeting.`,
                { order: 15 }
            );
            
            this.agent.addPrompt(
                'Chat History: Remember Previous Answers',
                'system',
                'high',
                `If user asked "What is the plugin name?" and you already answered it, DO NOT answer again. If user asked "Is plugin active?" and you already answered, DO NOT answer again. Reference previous answers from chat history.`,
                { order: 16 }
            );
            
            this.agent.addPrompt(
                'Chat History: Continue Existing Work',
                'system',
                'high',
                `If you started creating a plugin and user asks about it, continue from where you left off. Don't start over. If plugin was created, don't create it again. Check chat history to see current status.`,
                { order: 17 }
            );
            
            this.agent.addPrompt(
                'Chat History: Context Continuity',
                'system',
                'critical',
                `Maintain context throughout the conversation. If user mentions "the plugin" or "it", refer to chat history to understand what they mean. Never lose track of what was discussed.`,
                { order: 18 }
            );
            
            // OUTPUT SECTION
            this.agent.addPrompt(
                'Output: Natural Language Only',
                'output',
                'high',
                `After completing work: Provide clear natural language summary. NO JSON format. Use conversational text.`,
                { order: 1 }
            );
            
            // CODE QUALITY & PLUGIN STRUCTURE SECTION
            this.agent.addPrompt(
                'Code Quality: WordPress Coding Standards',
                'code_quality',
                'critical',
                `Follow WordPress Coding Standards strictly: Use tabs (not spaces) for indentation, use single quotes for strings, follow naming conventions, use proper PHP tags, and ensure all code is properly formatted and readable.`,
                { order: 1 }
            );
            
            this.agent.addPrompt(
                'Code Quality: Security Best Practices',
                'code_quality',
                'critical',
                `Always implement security best practices: Sanitize all user inputs with sanitize_text_field(), sanitize_email(), etc. Escape all outputs with esc_html(), esc_attr(), esc_url(), etc. Use nonces for forms. Validate and verify user capabilities before actions. Never trust user input.`,
                { order: 2 }
            );
            
            this.agent.addPrompt(
                'Code Quality: Performance Optimization',
                'code_quality',
                'high',
                `Write performant code: Use WordPress transients for caching, avoid unnecessary database queries, use proper hooks and filters efficiently, minimize file includes, and optimize loops. Consider lazy loading for heavy operations.`,
                { order: 3 }
            );
            
            this.agent.addPrompt(
                'Plugin Structure: Standard Directory Layout',
                'code_quality',
                'high',
                `Follow standard WordPress plugin structure: Main plugin file in root, /includes for core classes, /admin for admin functionality, /public for frontend, /assets for CSS/JS/images, /languages for translations. Use proper file organization.`,
                { order: 4 }
            );
            
            this.agent.addPrompt(
                'Plugin Structure: File Organization',
                'code_quality',
                'high',
                `Organize plugin files logically: Separate concerns (admin vs public), use classes for major components, keep functions focused and single-purpose, avoid monolithic files. Group related functionality together.`,
                { order: 5 }
            );
            
            this.agent.addPrompt(
                'Plugin Structure: Class Architecture',
                'code_quality',
                'high',
                `Use proper OOP structure: Create main plugin class, use namespaces if appropriate, implement singleton pattern when needed, separate admin and public classes, use dependency injection where beneficial. Keep classes focused and cohesive.`,
                { order: 6 }
            );
            
            this.agent.addPrompt(
                'Code Quality: Error Handling',
                'code_quality',
                'high',
                `Implement proper error handling: Use try-catch blocks for critical operations, log errors appropriately, provide user-friendly error messages, validate data before processing, handle edge cases gracefully.`,
                { order: 7 }
            );
            
            this.agent.addPrompt(
                'Code Quality: Documentation',
                'code_quality',
                'medium',
                `Include proper code documentation: Add PHPDoc comments for all functions and classes, document parameters and return values, explain complex logic, include usage examples in comments where helpful.`,
                { order: 8 }
            );
            
            this.agent.addPrompt(
                'Plugin Structure: Hooks and Filters',
                'code_quality',
                'high',
                `Use WordPress hooks properly: Add actions and filters with appropriate priorities, use unique hook names with prefix, remove hooks when needed, document custom hooks. Follow WordPress hook naming conventions.`,
                { order: 9 }
            );
            
            this.agent.addPrompt(
                'Code Quality: Database Interactions',
                'code_quality',
                'high',
                `Use WordPress database API correctly: Use $wpdb for custom queries, use prepare() for all queries with user data, use proper table names with $wpdb->prefix, use WordPress functions (get_option, update_option) when possible instead of direct queries.`,
                { order: 10 }
            );
            
            this.agent.addPrompt(
                'Plugin Structure: Internationalization',
                'code_quality',
                'medium',
                `Make plugins translatable: Wrap all user-facing strings with __(), _e(), _n(), etc. Use text domain matching plugin slug. Load text domain properly. Consider RTL support for CSS.`,
                { order: 11 }
            );
            
            this.agent.addPrompt(
                'Code Quality: Version Compatibility',
                'code_quality',
                'high',
                `Ensure compatibility: Check WordPress version requirements, test with minimum required PHP version, use feature detection instead of version checks when possible, handle deprecated functions gracefully.`,
                { order: 12 }
            );
            
            this.agent.addPrompt(
                'Plugin Structure: Activation/Deactivation Hooks',
                'code_quality',
                'high',
                `Implement proper lifecycle hooks: Use register_activation_hook() and register_deactivation_hook() correctly, perform setup tasks on activation (create tables, set defaults), clean up on deactivation if needed. Never use __FILE__ directly, use plugin_dir_path(__FILE__) or similar.`,
                { order: 13 }
            );
            
            this.agent.addPrompt(
                'Code Quality: No Direct File Access',
                'code_quality',
                'critical',
                `Prevent direct file access: Add "if (!defined('ABSPATH')) exit;" at the top of all PHP files. This prevents direct access to plugin files outside WordPress context.`,
                { order: 14 }
            );
            
            this.agent.addPrompt(
                'Code Quality: Enqueue Scripts and Styles Properly',
                'code_quality',
                'high',
                `Enqueue assets correctly: Use wp_enqueue_script() and wp_enqueue_style() with proper dependencies, version numbers, and conditions. Use wp_localize_script() for passing PHP data to JavaScript. Never hardcode script/style tags.`,
                { order: 15 }
            );
            
            this.agent.addPrompt(
                'Database Changes: User Permission Required',
                'code_quality',
                'critical',
                `CRITICAL: Before making ANY database changes (creating tables, dropping tables, deleting data, truncating tables, altering table structure), you MUST ask the user for explicit permission first. Explain what changes will be made and wait for user confirmation before proceeding. Never create, drop, delete, or modify database tables or data without user approval.`,
                { order: 16 }
            );
            
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
                // Messages are already sorted by timestamp (oldest first) from loadMessages()
                const chatHistory = [];
                for (const msg of this.currentMessages) {
                    if (msg.isTyping) continue;
                    // Exclude the current user message since we'll add it separately
                    if (msg.id === userMessage.id) continue;
                    // Only include messages with content (skip empty messages)
                    if (!msg.content || msg.content.trim() === '') continue;
                    
                    if (msg.role === 'user') {
                        chatHistory.push(new UserMessage(msg.content));
                    } else if (msg.role === 'bot' || msg.role === 'assistant') {
                        chatHistory.push(new AssistantMessage(msg.content));
                    }
                }
                
                // Ensure chat history is in chronological order (oldest first)
                // This is critical for the AI to understand conversation flow
                // Note: currentMessages is already sorted, so chatHistory should be in order
                
                // Check if this is the first user message (no previous user messages in history)
                const isFirstUserMessage = chatHistory.filter(m => m instanceof UserMessage).length === 0;
                
                // Update chat title rule dynamically based on current state
                this.updateChatTitleRule(isFirstUserMessage);
                
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
            const ajaxUrl = this.ajaxUrl || window.plugitifyConfig?.ajaxUrl || '';
            const nonce = this.nonce || window.plugitifyConfig?.nonce || '';
            
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
