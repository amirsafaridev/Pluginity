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

<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<script>
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
            taskUpdateInterval: null,
            lastTaskUpdate: 0,
            hasReceivedFirstUpdate: false,
            typingMessageId: null,
            currentPendingStep: null
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
    beforeUnmount() {
        this.stopTaskUpdatePolling();
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
                alert('خطا در بارگذاری چت‌ها: ' + error.message);
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
                    this.$refs.messageInput?.focus();
                } else {
                    throw new Error('Invalid response from server');
                }
            } catch (error) {
                console.error('Error creating new chat:', error);
                alert('خطا در ایجاد چت جدید: ' + error.message);
            }
        },
        async selectChat(chatId) {
            this.currentChatId = chatId;
            this.lastTaskUpdate = 0;
            this.hasReceivedFirstUpdate = false;
            this.currentPendingStep = null;
            if (this.typingMessageId) {
                this.removeMessage(this.typingMessageId);
                this.typingMessageId = null;
            }
            this.isLoading = false;
            await this.loadMessages();
            // Don't load task updates when switching chats - only when sending new message
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
        async loadTaskUpdates() {
            if (!this.currentChatId) return;
            
            try {
                const response = await this.callAPI('plugitify_get_task_updates', {
                    chat_id: this.currentChatId,
                    last_update: this.lastTaskUpdate
                }, 'GET');
                console.log('Task updates main response:', response);
                if (response.success) {
                    const updates = response.data.updates || [];
                    console.log('Task updates received:', updates.length, updates);
                    let hasUpdates = false;
                    let hasFinalMessage = false;
                    
                    // Add updates as bot messages (avoid duplicates)
                    // Sort updates by timestamp to maintain chronological order
                    const sortedUpdates = [...updates].sort((a, b) => {
                        const timeA = new Date(a.timestamp).getTime();
                        const timeB = new Date(b.timestamp).getTime();
                        return timeA - timeB;
                    });
                    
                    // Separate final messages to check if we should stop polling
                    for (const update of sortedUpdates) {
                        if (update.type === 'final_message') {
                            hasFinalMessage = true;
                        }
                    }
                    
                    // Show all updates in chronological order
                    console.log('Processing updates:', sortedUpdates.length, 'updates');
                    for (const update of sortedUpdates) {
                        // Generate stable ID based on type and identifiers
                        let updateId;
                        if (update.type === 'final_message' && update.message_id) {
                            updateId = 'msg_' + update.message_id;
                        } else if (update.type === 'task_created' && update.task_id) {
                            updateId = 'task_' + update.task_id + '_created';
                        } else if (update.type === 'task_complete' && update.task_id) {
                            updateId = 'task_' + update.task_id + '_complete';
                        } else if (update.type === 'task_failed' && update.task_id) {
                            updateId = 'task_' + update.task_id + '_failed';
                        } else if (update.type === 'step_complete' && update.step_id) {
                            updateId = 'step_' + update.step_id + '_complete';
                        } else if (update.type === 'step_failed' && update.step_id) {
                            updateId = 'step_' + update.step_id + '_failed';
                        } else if (update.type === 'step_update' && update.step_id) {
                            updateId = 'step_' + update.step_id + '_update';
                        } else {
                            // Fallback: use timestamp + type + content hash
                            const contentHash = update.content ? update.content.substring(0, 20).replace(/\s/g, '') : '';
                            updateId = 'update_' + update.timestamp + '_' + (update.type || 'update') + '_' + contentHash;
                        }
                        
                        // Check if this update already exists
                        const exists = this.currentMessages.some(msg => msg.id === updateId);
                        
                        console.log('Update:', {
                            type: update.type,
                            step_id: update.step_id,
                            updateId: updateId,
                            exists: exists,
                            content_preview: update.content ? update.content.substring(0, 30) : 'N/A'
                        });
                        
                        if (!exists) {
                            hasUpdates = true;
                            const updateMessage = {
                                id: updateId,
                                role: 'bot',
                                content: update.content,
                                timestamp: new Date(update.timestamp),
                                isUpdate: update.type !== 'final_message',
                                isFinalMessage: update.type === 'final_message'
                            };
                            this.addMessage(updateMessage);
                        }
                    }
                    
                    // Update last pending step for loading indicator
                    if (response.data.last_pending_step) {
                        this.currentPendingStep = response.data.last_pending_step.step_name;
                    } else {
                        this.currentPendingStep = null;
                    }
                    
                    // If we received updates, mark that we've received first update
                    // BUT DON'T remove typing indicator yet - keep it until final message
                    if (hasUpdates) {
                        this.hasReceivedFirstUpdate = true;
                    }
                    
                    // Check if we should stop polling:
                    // Only stop if: we received a final message AND we're NOT waiting for response
                    const isWaitingForResponse = response.data.is_waiting_for_response === true;
                    
                    if (hasFinalMessage && !isWaitingForResponse) {
                        // Final message received and last message is not from user - agent finished
                        console.log('Final message received and conversation complete, stopping polling');
                        this.stopTaskUpdatePolling();
                        this.isLoading = false;
                        // NOW remove typing indicator since we have final message
                        if (this.typingMessageId) {
                            this.removeMessage(this.typingMessageId);
                            this.typingMessageId = null;
                        }
                        this.currentPendingStep = null;
                        // Reload all messages to ensure consistency
                        await this.loadMessages();
                    } else {
                        // Keep loading indicator and show pending step if available
                        this.isLoading = true;
                    }
                    // If isWaitingForResponse is true, keep polling even if we got updates
                    
                    // Update last update timestamp
                    if (response.data.last_update > this.lastTaskUpdate) {
                        // Convert to timestamp if it's a date string, otherwise use as is
                        if (typeof response.data.last_update === 'string') {
                            this.lastTaskUpdate = Math.floor(new Date(response.data.last_update).getTime() / 1000);
                        } else {
                            this.lastTaskUpdate = response.data.last_update;
                        }
                    }
                }
            } catch (error) {
                console.error('Error loading task updates:', error);
            }
        },
        startTaskUpdatePolling() {
            // Stop existing polling
            if (this.taskUpdateInterval) {
                clearInterval(this.taskUpdateInterval);
            }
            
            // Poll every 15 seconds for task updates
            this.taskUpdateInterval = setInterval(() => {
                if (this.currentChatId) {
                    this.loadTaskUpdates();
                }
            }, 15000); // 15 seconds
        },
        stopTaskUpdatePolling() {
            if (this.taskUpdateInterval) {
                clearInterval(this.taskUpdateInterval);
                this.taskUpdateInterval = null;
            }
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
                    this.chats = this.chats.filter(c => c.id !== chatId);
                    if (this.currentChatId === chatId) {
                        if (this.chats.length > 0) {
                            this.currentChatId = this.chats[0].id;
                            await this.loadMessages();
                        } else {
                            this.currentChatId = null;
                            this.currentMessages = [];
                        }
                    }
                }
            } catch (error) {
                console.error('Error deleting chat:', error);
            }
        },
        async sendMessage() {
            if (!this.messageInput.trim() || this.isLoading) return;
            
            // Ensure we have a chat_id
            if (!this.currentChatId || this.currentChatId <= 0) {
                await this.newChat();
                // Wait a bit for chat to be created
                await new Promise(resolve => setTimeout(resolve, 100));
            }
            
            const messageContent = this.messageInput.trim();
            this.messageInput = '';
            
            // Show user message immediately (will be saved by API)
            const userMessage = {
                id: 'temp_' + Date.now(),
                role: 'user',
                content: messageContent,
                timestamp: new Date()
            };
            this.addMessage(userMessage);
            
            // Update chat title if it's the first user message
            const chat = this.chats.find(c => c.id === this.currentChatId);
            if (chat && chat.title === 'New Chat' && this.currentMessages.filter(m => m.role === 'user').length === 1) {
                const newTitle = messageContent.substring(0, 30) + (messageContent.length > 30 ? '...' : '');
                chat.title = newTitle;
                // Update title in database
                this.callAPI('plugitify_save_chat', {
                    chat_id: this.currentChatId,
                    title: newTitle
                }).catch(err => console.error('Error updating chat title:', err));
            }
            
            // Reset update tracking
            this.hasReceivedFirstUpdate = false;
            // Don't set lastTaskUpdate here - wait for API response to get user message timestamp
            
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
            
            // Start polling for task updates
            this.startTaskUpdatePolling();
            
            try {
                const response = await this.sendToAPI(messageContent);
                
                // Set lastTaskUpdate to user message timestamp (if provided)
                // This ensures we only get updates after the user sent the message
                if (response.data && response.data.user_message_timestamp) {
                    this.lastTaskUpdate = response.data.user_message_timestamp;
                } else {
                    // Fallback to current time if timestamp not provided
                    this.lastTaskUpdate = Math.floor(Date.now() / 1000);
                }
                
                // Don't remove typing indicator here - wait for first update
                // The typing indicator will be removed when first update is received
                
                // Small delay before first poll to let cron process start
                await new Promise(resolve => setTimeout(resolve, 500));
                
                // Initial load of task updates
                await this.loadTaskUpdates();
                
            } catch (error) {
                console.error('Error sending message:', error);
                // Remove typing indicator on error
                if (this.typingMessageId) {
                    this.removeMessage(this.typingMessageId);
                    this.typingMessageId = null;
                }
                
                // Add error message
                const errorMessage = {
                    id: 'error_' + Date.now(),
                    role: 'bot',
                    content: 'Sorry, I encountered an error. Please try again.',
                    timestamp: new Date()
                };
                this.addMessage(errorMessage);
                this.isLoading = false;
                this.stopTaskUpdatePolling();
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
    }
}).mount('#app');
</script>
<?php include_once PLUGITIFY_DIR . 'template/panel/footer.php'; ?>