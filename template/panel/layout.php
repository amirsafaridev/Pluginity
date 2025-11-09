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



<?php include_once PLUGITIFY_DIR . 'template/panel/footer.php'; ?>