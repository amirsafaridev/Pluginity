/**
 * Agent Framework - A comprehensive framework for building AI agents
 * Similar to neuron-core, providing tools, chat history, and event management
 */

/**
 * Message Types
 */
class Message {
    constructor(role, content) {
        this.role = role;
        this.content = content;
        this.timestamp = new Date();
    }

    getRole() {
        return this.role;
    }

    getContent() {
        return this.content;
    }

    toJSON() {
        return {
            role: this.role,
            content: this.content,
            timestamp: this.timestamp.toISOString()
        };
    }
}

class UserMessage extends Message {
    constructor(content) {
        super('user', content);
    }
}

class AssistantMessage extends Message {
    constructor(content, usage = null) {
        super('assistant', content);
        this.usage = usage;
    }

    setUsage(usage) {
        this.usage = usage;
        return this;
    }

    getUsage() {
        return this.usage;
    }

    toJSON() {
        return {
            ...super.toJSON(),
            usage: this.usage
        };
    }
}

class ToolCallMessage extends Message {
    constructor(tools = []) {
        super('assistant', '');
        this.tools = tools;
    }

    getTools() {
        return this.tools;
    }

    addTool(tool) {
        this.tools.push(tool);
        return this;
    }

    toJSON() {
        return {
            ...super.toJSON(),
            tools: this.tools.map(tool => tool.toJSON())
        };
    }
}

class ToolCallResultMessage extends Message {
    constructor(tools = []) {
        super('tool', '');
        this.tools = tools;
    }

    getTools() {
        return this.tools;
    }

    toJSON() {
        return {
            ...super.toJSON(),
            tools: this.tools.map(tool => tool.toJSON())
        };
    }
}

/**
 * Tool Property - Defines a parameter for a tool
 */
class ToolProperty {
    constructor(name, type, description, required = false, enumValues = null) {
        this.name = name;
        this.type = type;
        this.description = description;
        this.required = required;
        this.enum = enumValues;
    }

    getName() {
        return this.name;
    }

    getType() {
        return this.type;
    }

    getDescription() {
        return this.description;
    }

    isRequired() {
        return this.required;
    }

    getEnum() {
        return this.enum;
    }

    toJSON() {
        const schema = {
            type: this.type,
            description: this.description
        };
        
        if (this.enum) {
            schema.enum = this.enum;
        }

        return schema;
    }
}

/**
 * Tool - Represents an executable tool/function
 */
class Tool {
    constructor(name, description) {
        this.name = name;
        this.description = description;
        this.properties = [];
        this.callback = null;
        this.inputs = {};
        this.callId = null;
        this.result = null;
    }

    getName() {
        return this.name;
    }

    getDescription() {
        return this.description;
    }

    addProperty(property) {
        if (!(property instanceof ToolProperty)) {
            throw new Error('Property must be an instance of ToolProperty');
        }
        this.properties.push(property);
        return this;
    }

    getProperties() {
        return this.properties;
    }

    getRequiredProperties() {
        return this.properties
            .filter(prop => prop.isRequired())
            .map(prop => prop.getName());
    }

    setCallable(callback) {
        if (typeof callback !== 'function') {
            throw new Error('Callback must be a function');
        }
        this.callback = callback;
        return this;
    }

    getInputs() {
        return this.inputs || {};
    }

    setInputs(inputs) {
        this.inputs = inputs || {};
        return this;
    }

    getCallId() {
        return this.callId;
    }

    setCallId(callId) {
        this.callId = callId;
        return this;
    }

    getResult() {
        return this.result;
    }

    setResult(result) {
        this.result = result;
        return this;
    }

    /**
     * Execute the tool with provided inputs
     * @throws {Error} If callback is not set or required parameters are missing
     */
    async execute() {
        if (!this.callback) {
            throw new Error('No callback defined for execution.');
        }

        // Validate required parameters
        const requiredProps = this.getRequiredProperties();
        for (const propName of requiredProps) {
            if (!(propName in this.inputs)) {
                throw new Error(`Missing required parameter: ${propName}`);
            }
        }

        // Execute callback
        if (this.callback.constructor.name === 'AsyncFunction' || this.callback instanceof Promise) {
            this.result = await this.callback(...Object.values(this.inputs));
        } else {
            this.result = this.callback(...Object.values(this.inputs));
        }

        return this.result;
    }

    /**
     * Get tool schema for AI provider
     */
    getSchema() {
        const properties = {};
        const required = [];

        this.properties.forEach(prop => {
            properties[prop.getName()] = prop.toJSON();
            if (prop.isRequired()) {
                required.push(prop.getName());
            }
        });

        return {
            type: 'function',
            function: {
                name: this.name,
                description: this.description,
                parameters: {
                    type: 'object',
                    properties,
                    required: required.length > 0 ? required : undefined
                }
            }
        };
    }

    toJSON() {
        return {
            name: this.name,
            description: this.description,
            inputs: this.inputs,
            callId: this.callId,
            result: this.result
        };
    }
}

/**
 * Chat History - Manages conversation history
 */
class ChatHistory {
    constructor() {
        this.messages = [];
    }

    addMessage(message) {
        if (!(message instanceof Message)) {
            throw new Error('Message must be an instance of Message');
        }
        this.messages.push(message);
        return this;
    }

    getMessages() {
        return [...this.messages];
    }

    clear() {
        this.messages = [];
        return this;
    }

    getLastMessage() {
        return this.messages.length > 0 ? this.messages[this.messages.length - 1] : null;
    }

    toJSON() {
        return this.messages.map(msg => msg.toJSON());
    }
}

/**
 * In-Memory Chat History
 */
class InMemoryChatHistory extends ChatHistory {
    constructor() {
        super();
    }
}

/**
 * Agent - Main framework class for building AI agents
 */
class Agent {
    constructor(provider = null) {
        this.provider = provider;
        this.instructions = 'You are a helpful and friendly AI assistant built with Agent Framework.';
        this.tools = [];
        this.chatHistory = null;
        this.observers = {};
        this.taskManager = null;
        this.chatId = null;
        // Circuit Breaker: Track consecutive errors to prevent infinite loops
        this.errorTracker = {
            consecutiveErrors: 0,
            lastError: null,
            maxRetries: 1  // Maximum consecutive errors before throwing
        };
    }

    /**
     * Set task manager
     */
    setTaskManager(taskManager) {
        this.taskManager = taskManager;
        return this;
    }

    /**
     * Set chat ID for task management
     */
    setChatId(chatId) {
        this.chatId = chatId;
        return this;
    }

    /**
     * Set the AI provider
     */
    setProvider(provider) {
        this.provider = provider;
        return this;
    }

    /**
     * Get the AI provider
     */
    getProvider() {
        if (!this.provider) {
            throw new Error('Provider is not set. Please set a provider using setProvider().');
        }
        return this.provider;
    }

    /**
     * Get or create chat history
     */
    resolveChatHistory() {
        if (!this.chatHistory) {
            this.chatHistory = new InMemoryChatHistory();
        }
        return this.chatHistory;
    }

    /**
     * Set custom chat history
     */
    withChatHistory(chatHistory) {
        if (!(chatHistory instanceof ChatHistory)) {
            throw new Error('Chat history must be an instance of ChatHistory');
        }
        this.chatHistory = chatHistory;
        return this;
    }

    /**
     * Get instructions/system prompt
     */
    getInstructions() {
        return this.instructions;
    }

    /**
     * Set instructions/system prompt
     */
    setInstructions(instructions) {
        this.instructions = instructions;
        return this;
    }

    /**
     * Get all registered tools
     */
    getTools() {
        return [...this.tools];
    }

    /**
     * Add a tool to the agent
     */
    addTool(tool) {
        if (!(tool instanceof Tool)) {
            throw new Error('Tool must be an instance of Tool');
        }
        this.tools.push(tool);
        return this;
    }

    /**
     * Remove a tool by name
     */
    removeTool(toolName) {
        this.tools = this.tools.filter(tool => tool.getName() !== toolName);
        return this;
    }

    /**
     * Get tool schemas for AI provider
     */
    getToolSchemas() {
        return this.tools.map(tool => tool.getSchema());
    }

    /**
     * Execute tools from a tool call message
     */
    async executeTools(toolCallMessage) {
        if (!(toolCallMessage instanceof ToolCallMessage)) {
            throw new Error('Must be a ToolCallMessage instance');
        }

        const toolCallResult = new ToolCallResultMessage(toolCallMessage.getTools());

        for (const toolCall of toolCallResult.getTools()) {
            // Find the registered tool
            const registeredTool = this.tools.find(t => t.getName() === toolCall.getName());
            
            if (!registeredTool) {
                throw new Error(`Tool "${toolCall.getName()}" not found`);
            }

            // Set inputs and call ID
            registeredTool.setInputs(toolCall.getInputs());
            registeredTool.setCallId(toolCall.getCallId());

            // Create task if task manager is available
            let task = null;
            let step = null;
            if (this.taskManager && this.chatId) {
                // Generate better task name from tool description and inputs
                const taskName = this._generateTaskName(registeredTool, toolCall.getInputs());
                
                task = this.taskManager.createTask(
                    this.chatId,
                    taskName,
                    toolCall.getName(),
                    toolCall.getInputs()
                );
                step = task.steps[0];
                this.notify('task-created', { task, step });
            }

            // Notify tool calling
            this.notify('tool-calling', { tool: registeredTool, task, step });

            // Execute the tool
            try {
                const startTime = Date.now();
                const result = await registeredTool.execute();
                const duration = Date.now() - startTime;

                // Store result in toolCall (which is in toolCallResult) so it can be formatted correctly
                toolCall.setResult(result);

                // Update task step on success
                if (this.taskManager && this.chatId && task && step) {
                    // Truncate large results to prevent localStorage overflow
                    const MAX_RESULT_LENGTH = 2000;
                    let resultStr = typeof result === 'string' ? result : JSON.stringify(result);
                    if (resultStr.length > MAX_RESULT_LENGTH) {
                        resultStr = resultStr.substring(0, MAX_RESULT_LENGTH) + `\n\n... (truncated, original length: ${resultStr.length} characters)`;
                    }
                    
                    this.taskManager.updateStep(this.chatId, task.id, step.id, {
                        status: 'completed',
                        result: resultStr,
                        content: `Successfully executed ${toolCall.getName()}. Result: ${resultStr}`
                    });
                    this.notify('task-updated', { task, step });
                }

                this.notify('tool-called', { tool: registeredTool, result, task, step });
                // Reset error tracker on success
                this.errorTracker.consecutiveErrors = 0;
                this.errorTracker.lastError = null;
            } catch (error) {
                // CIRCUIT BREAKER: Check for infinite loop
                const errorSignature = `${toolCall.getName()}:${error.message}`;
                
                // Check if this is the same error repeating
                if (this.errorTracker.lastError === errorSignature) {
                    this.errorTracker.consecutiveErrors++;
                } else {
                    // Different error, reset counter
                    this.errorTracker.consecutiveErrors = 1;
                    this.errorTracker.lastError = errorSignature;
                }
                
                // If too many consecutive errors, throw to stop the loop
                if (this.errorTracker.consecutiveErrors >= this.errorTracker.maxRetries) {
                    console.error(`Circuit breaker activated: ${this.errorTracker.consecutiveErrors} consecutive errors for ${toolCall.getName()}`);
                    
                    // Update task as failed
                    if (this.taskManager && this.chatId && task && step) {
                        this.taskManager.updateStep(this.chatId, task.id, step.id, {
                            status: 'failed',
                            error: error.message,
                            content: `Circuit breaker activated: Too many consecutive errors (${this.errorTracker.consecutiveErrors}). Error: ${error.message}`
                        });
                        this.notify('task-updated', { task, step });
                    }
                    
                    // Reset tracker
                    this.errorTracker.consecutiveErrors = 0;
                    this.errorTracker.lastError = null;
                    
                    // Throw to stop the loop
                    throw new Error(`Circuit breaker: Tool "${toolCall.getName()}" failed ${this.errorTracker.maxRetries} times consecutively. Last error: ${error.message}`);
                }
                
                // AUTO-RECOVERY: Provide error details to the AI model for recovery
                const errorDetails = `ERROR: ${error.message}\n\nThis tool execution failed (Attempt ${this.errorTracker.consecutiveErrors}/${this.errorTracker.maxRetries}). Please try a DIFFERENT approach:\n- If file not found, try using create_file tool to create it first\n- If directory doesn't exist, create the file (it will auto-create directories)\n- If missing required parameter, check the tool definition and provide ALL required parameters\n- If one approach fails, DO NOT retry the same tool with same parameters - try something else!\n- Consider using a different tool or approach to accomplish the goal\n\nIMPORTANT: Do NOT call the same tool with the same missing parameters again!`;
                
                // Store error details as the result so AI can read it
                toolCall.setResult(errorDetails);
                toolCall.error = error.message;

                // Update task step on error - but mark as 'needs_recovery' not 'failed'
                if (this.taskManager && this.chatId && task && step) {
                    this.taskManager.updateStep(this.chatId, task.id, step.id, {
                        status: 'needs_recovery',
                        error: error.message,
                        content: `Tool ${toolCall.getName()} encountered an error (Attempt ${this.errorTracker.consecutiveErrors}/${this.errorTracker.maxRetries}). Error: ${error.message}\nAttempting auto-recovery...`
                    });
                    this.notify('task-updated', { task, step });
                }

                this.notify('tool-error', { tool: registeredTool, error, task, step });
                console.warn(`Tool ${toolCall.getName()} failed (${this.errorTracker.consecutiveErrors}/${this.errorTracker.maxRetries}), passing error to AI for recovery:`, error.message);
            }
        }

        return toolCallResult;
    }

    /**
     * Main chat method
     */
    async chat(messages) {
        this.notify('chat-start');

        // Normalize messages to array
        const messageArray = Array.isArray(messages) ? messages : [messages];

        // Validate and add messages to history
        for (const message of messageArray) {
            if (!(message instanceof Message)) {
                throw new Error('All messages must be instances of Message');
            }
            
            this.notify('message-saving', { message });
            this.resolveChatHistory().addMessage(message);
            this.notify('message-saved', { message });
        }

        const lastMessage = messageArray[messageArray.length - 1];

        this.notify('message-sending', { message: lastMessage });

        // Get provider and make chat request
        const provider = this.getProvider();
        
        // Prepare messages for provider
        const historyMessages = this.resolveChatHistory().getMessages();
        
        // Call provider's chat method
        // The provider should handle system prompt, tools, and messages
        let response;
        
        if (typeof provider.chat === 'function') {
            // Provider has a chat method
            response = await provider.chat({
                systemPrompt: this.instructions,
                tools: this.getToolSchemas(),
                messages: historyMessages
            });
        } else {
            // Generic provider interface
            response = await this._callProvider(provider, historyMessages);
        }

        this.notify('message-sent', { message: lastMessage, response });

        // Handle tool calls
        if (response instanceof ToolCallMessage || (response.tools && response.tools.length > 0)) {
            let toolCallMsg;
            
            if (response instanceof ToolCallMessage) {
                toolCallMsg = response;
            } else {
                // Convert provider response to ToolCallMessage
                toolCallMsg = new ToolCallMessage();
                for (const toolCall of response.tools) {
                    const tool = new Tool(toolCall.name, toolCall.description || '');
                    tool.setInputs(toolCall.inputs || {});
                    tool.setCallId(toolCall.callId || null);
                    toolCallMsg.addTool(tool);
                }
            }

            const toolCallResult = await this.executeTools(toolCallMsg);
            
            // Recursively call chat with tool results
            response = await this.chat([toolCallMsg, toolCallResult]);
        } else {
            // Convert response to AssistantMessage if needed
            if (!(response instanceof Message)) {
                response = new AssistantMessage(
                    response.content || response.text || JSON.stringify(response),
                    response.usage || null
                );
            }

            // Save response to history
            this.notify('message-saving', { message: response });
            this.resolveChatHistory().addMessage(response);
            this.notify('message-saved', { message: response });
        }

        this.notify('chat-stop');
        return response;
    }

    /**
     * Stream chat method
     */
    async *stream(messages) {
        this.notify('stream-start');

        const messageArray = Array.isArray(messages) ? messages : [messages];

        // Add messages to history
        for (const message of messageArray) {
            if (!(message instanceof Message)) {
                throw new Error('All messages must be instances of Message');
            }
            
            this.notify('message-saving', { message });
            this.resolveChatHistory().addMessage(message);
            this.notify('message-saved', { message });
        }

        const provider = this.getProvider();
        const historyMessages = this.resolveChatHistory().getMessages();

        let streamGenerator;
        
        if (typeof provider.stream === 'function') {
            streamGenerator = provider.stream({
                systemPrompt: this.instructions,
                tools: this.getToolSchemas(),
                messages: historyMessages
            });
        } else {
            streamGenerator = this._streamProvider(provider, historyMessages);
        }

        let content = '';
        let usage = { inputTokens: 0, outputTokens: 0 };

        for await (const chunk of streamGenerator) {
            // Handle error chunks from provider
            if (chunk.error && chunk.toolResult) {
                // Save tool result to history and continue stream
                this.notify('message-saving', { message: chunk.toolResult });
                this.resolveChatHistory().addMessage(chunk.toolResult);
                this.notify('message-saved', { message: chunk.toolResult });
                
                // Continue stream to let AI see the error and respond
                yield* this.stream([]);
                return;
            }
            
            // Handle usage data
            if (chunk.usage) {
                usage.inputTokens += chunk.usage.inputTokens || 0;
                usage.outputTokens += chunk.usage.outputTokens || 0;
                continue;
            }

            // Handle tool calls during streaming
            if (chunk.tools && chunk.tools.length > 0) {
                // Yield tool call info first
                yield chunk;
                
                const toolCallMsg = new ToolCallMessage();
                for (const toolCall of chunk.tools) {
                    const tool = new Tool(toolCall.name, toolCall.description || '');
                    tool.setInputs(toolCall.inputs || {});
                    tool.setCallId(toolCall.callId || null);
                    toolCallMsg.addTool(tool);
                }
                
                const toolCallResult = await this.executeTools(toolCallMsg);
                yield* this.stream([toolCallMsg, toolCallResult]);
                continue;
            }

            // Yield text content
            if (chunk.content) {
                content += chunk.content;
                yield chunk;
            } else if (typeof chunk === 'string') {
                content += chunk;
                yield chunk;
            }
        }

        // Create final response message
        const response = new AssistantMessage(content, usage);

        // Avoid double saving
        const history = this.resolveChatHistory().getMessages();
        const lastMessage = history[history.length - 1];
        
        if (!lastMessage || response.getRole() !== lastMessage.getRole()) {
            this.notify('message-saving', { message: response });
            this.resolveChatHistory().addMessage(response);
            this.notify('message-saved', { message: response });
        }

        this.notify('stream-stop');
    }

    /**
     * Generic provider call method (override if needed)
     */
    async _callProvider(provider, messages) {
        // Check if provider has a chat method
        if (provider && typeof provider.chat === 'function') {
            // Convert messages to provider format
            const formattedMessages = this._formatMessagesForProvider(messages);
            return await provider.chat({
                systemPrompt: this.instructions,
                tools: this.getToolSchemas(),
                messages: formattedMessages
            });
        }
        throw new Error('Provider must implement chat() method or override _callProvider()');
    }

    /**
     * Generic provider stream method (override if needed)
     */
    async *_streamProvider(provider, messages) {
        // Check if provider has a stream method
        if (provider && typeof provider.stream === 'function') {
            const formattedMessages = this._formatMessagesForProvider(messages);
            const stream = provider.stream({
                systemPrompt: this.instructions,
                tools: this.getToolSchemas(),
                messages: formattedMessages
            });
            
            for await (const chunk of stream) {
                yield chunk;
            }
            return;
        }
        throw new Error('Provider must implement stream() method or override _streamProvider()');
    }

    /**
     * Format messages for provider
     */
    _formatMessagesForProvider(messages) {
        const formatted = [];
        for (const msg of messages) {
            if (msg instanceof UserMessage) {
                formatted.push({ role: 'user', content: msg.getContent() });
            } else if (msg instanceof AssistantMessage) {
                formatted.push({ role: 'assistant', content: msg.getContent() });
            } else if (msg instanceof ToolCallMessage) {
                formatted.push({ role: 'assistant', tool_calls: this._formatToolCalls(msg.getTools()) });
            } else if (msg instanceof ToolCallResultMessage) {
                // Each tool result needs its own message with the correct tool_call_id
                for (const tool of msg.getTools()) {
                    const toolCallId = tool.getCallId();
                    if (!toolCallId) {
                        console.warn('Tool result missing tool_call_id:', tool);
                        continue;
                    }
                    const result = tool.getResult();
                    // Handle error case - if there's an error property, format it as error message
                    let content;
                    if (tool.error) {
                        content = JSON.stringify({ error: tool.error });
                    } else if (result === null || result === undefined) {
                        content = JSON.stringify({ error: 'Tool execution returned no result' });
                    } else {
                        content = typeof result === 'string' ? result : JSON.stringify(result);
                    }
                    formatted.push({
                        role: 'tool',
                        tool_call_id: toolCallId,
                        content: content
                    });
                }
            } else {
                formatted.push({ role: msg.getRole(), content: msg.getContent() });
            }
        }
        return formatted;
    }

    /**
     * Format tool calls for provider
     */
    _formatToolCalls(tools) {
        return tools.map(tool => ({
            id: tool.getCallId() || `call_${Date.now()}_${Math.random()}`,
            type: 'function',
            function: {
                name: tool.getName(),
                arguments: JSON.stringify(tool.getInputs())
            }
        }));
    }

    /**
     * Generate a descriptive task name from tool and inputs
     */
    _generateTaskName(tool, inputs) {
        const toolName = tool.getName();
        const toolDescription = tool.getDescription() || '';
        
        // Create a more descriptive name based on tool type and inputs
        let taskName = '';
        
        if (toolName === 'create_directory') {
            const path = inputs.path || '';
            const pathParts = path.split('/').filter(p => p);
            const dirName = pathParts[pathParts.length - 1] || path;
            taskName = `Creating directory "${dirName}"`;
        } else if (toolName === 'create_file') {
            const filePath = inputs.file_path || '';
            const fileName = filePath.split('/').pop() || filePath;
            taskName = `Creating file "${fileName}"`;
        } else if (toolName === 'delete_file') {
            const filePath = inputs.file_path || '';
            const fileName = filePath.split('/').pop() || filePath;
            taskName = `Deleting file "${fileName}"`;
        } else if (toolName === 'delete_directory') {
            const path = inputs.path || '';
            const dirName = path.split('/').filter(p => p).pop() || path;
            taskName = `Deleting directory "${dirName}"`;
        } else if (toolName === 'read_file') {
            const filePath = inputs.file_path || '';
            const fileName = filePath.split('/').pop() || filePath;
            taskName = `Reading file "${fileName}"`;
        } else if (toolName === 'edit_file_line') {
            const filePath = inputs.file_path || '';
            const fileName = filePath.split('/').pop() || filePath;
            const lineNum = inputs.line_number || '';
            taskName = `Editing line ${lineNum} in file "${fileName}"`;
        } else if (toolName === 'list_plugins') {
            taskName = 'Listing WordPress plugins';
        } else if (toolName === 'deactivate_plugin') {
            const pluginFile = inputs.plugin_file || '';
            const pluginName = pluginFile.split('/').shift() || pluginFile;
            taskName = `Deactivating plugin "${pluginName}"`;
        } else if (toolName === 'extract_plugin_structure') {
            const pluginName = inputs.plugin_name || '';
            taskName = `Analyzing plugin structure "${pluginName}"`;
        } else {
            // Fallback: use first part of description or tool name
            const desc = toolDescription.split('.')[0] || toolName;
            taskName = desc.length > 50 ? desc.substring(0, 47) + '...' : desc;
        }
        
        return taskName || `Executing ${toolName}`;
    }

    /**
     * Event Observer Methods
     */
    initEventGroup(event = '*') {
        if (!this.observers[event]) {
            this.observers[event] = [];
        }
    }

    getEventObservers(event = '*') {
        this.initEventGroup(event);
        const group = this.observers[event] || [];
        const all = this.observers['*'] || [];
        return [...group, ...all];
    }

    /**
     * Observe an event
     */
    observe(callback, event = '*') {
        this.initEventGroup(event);
        this.observers[event].push(callback);
        return this;
    }

    /**
     * Attach an observer
     */
    attach(callback, event = '*') {
        this.observe(callback, event);
    }

    /**
     * Detach an observer
     */
    detach(callback, event = '*') {
        const observers = this.getEventObservers(event);
        for (let i = 0; i < observers.length; i++) {
            if (observers[i] === callback) {
                this.observers[event].splice(i, 1);
                break;
            }
        }
    }

    /**
     * Notify observers of an event
     */
    notify(event, data = null) {
        const observers = this.getEventObservers(event);
        for (const observer of observers) {
            if (typeof observer === 'function') {
                observer(this, event, data);
            } else if (observer && typeof observer.update === 'function') {
                observer.update(this, event, data);
            }
        }
    }

    /**
     * Remove all observers
     */
    clearObservers() {
        this.observers = {};
        return this;
    }
}

/**
 * Deepseek Provider - OpenAI-compatible API provider
 */
class DeepseekProvider {
    constructor(config = {}) {
        this.apiKey = config.apiKey || 'sk-93c6a02788dd454baa0f34a07b9ca3c7';
        this.baseURL = config.baseURL || 'https://api.deepseek.com/v1';
        this.model = config.model || 'deepseek-chat';
        this.temperature = config.temperature || 0;
    }

    /**
     * Chat method
     */
    async chat(options) {
        const { systemPrompt, tools, messages } = options;
        
        // Format messages for OpenAI API
        const formattedMessages = [];
        
        // Add system message
        if (systemPrompt) {
            formattedMessages.push({
                role: 'system',
                content: systemPrompt
            });
        }
        
        // Add conversation messages
        // Handle both Message objects and plain objects
        for (const msg of messages) {
            // Check if it's a Message object
            if (msg instanceof UserMessage) {
                formattedMessages.push({
                    role: 'user',
                    content: msg.getContent()
                });
            } else if (msg instanceof AssistantMessage) {
                formattedMessages.push({
                    role: 'assistant',
                    content: msg.getContent()
                });
            } else if (msg instanceof ToolCallMessage) {
                // Format tool calls
                const toolCalls = msg.getTools().map(tool => ({
                    id: tool.getCallId() || `call_${Date.now()}_${Math.random()}`,
                    type: 'function',
                    function: {
                        name: tool.getName(),
                        arguments: JSON.stringify(tool.getInputs())
                    }
                }));
                formattedMessages.push({
                    role: 'assistant',
                    tool_calls: toolCalls
                });
            } else if (msg instanceof ToolCallResultMessage) {
                // Each tool result needs its own message with the correct tool_call_id
                for (const tool of msg.getTools()) {
                    const toolCallId = tool.getCallId();
                    if (!toolCallId) {
                        console.warn('Tool result missing tool_call_id:', tool);
                        continue;
                    }
                    const result = tool.getResult();
                    let content;
                    if (tool.error) {
                        content = JSON.stringify({ error: tool.error });
                    } else if (result === null || result === undefined) {
                        content = JSON.stringify({ error: 'Tool execution returned no result' });
                    } else {
                        content = typeof result === 'string' ? result : JSON.stringify(result);
                    }
                    formattedMessages.push({
                        role: 'tool',
                        tool_call_id: toolCallId,
                        content: content
                    });
                }
            } else if (msg && typeof msg === 'object') {
                // Plain object - check role property
                if (msg.role === 'user' || msg.role === 'assistant') {
                    formattedMessages.push({
                        role: msg.role,
                        content: msg.content
                    });
                } else if (msg.role === 'tool') {
                    if (!msg.tool_call_id) {
                        console.warn('Tool message missing tool_call_id:', msg);
                        continue;
                    }
                    formattedMessages.push({
                        role: 'tool',
                        tool_call_id: msg.tool_call_id,
                        content: msg.content
                    });
                } else if (msg.role === 'assistant' && msg.tool_calls) {
                    // Assistant message with tool_calls
                    formattedMessages.push({
                        role: 'assistant',
                        tool_calls: msg.tool_calls
                    });
                }
            }
        }
        
        // Prepare request body
        const body = {
            model: this.model,
            messages: formattedMessages,
            temperature: this.temperature,
            stream: false
        };
        
        // Add tools if available
        if (tools && tools.length > 0) {
            body.tools = tools;
            body.tool_choice = 'auto';
        }
        console.warn('Request Body:', body);
        try {
            const response = await fetch(`${this.baseURL}/chat/completions`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.apiKey}`
                },
                body: JSON.stringify(body)
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error?.message || `HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            const choice = data.choices[0];
            
            // Handle tool calls
            if (choice.message.tool_calls && choice.message.tool_calls.length > 0) {
                const toolCallMessage = new ToolCallMessage();
                for (const toolCall of choice.message.tool_calls) {
                    const tool = new Tool(toolCall.function.name, '');
                    tool.setInputs(JSON.parse(toolCall.function.arguments || '{}'));
                    tool.setCallId(toolCall.id);
                    toolCallMessage.addTool(tool);
                }
                return toolCallMessage;
            }
            
            // Return assistant message
            return new AssistantMessage(
                choice.message.content || '',
                data.usage ? {
                    inputTokens: data.usage.prompt_tokens || 0,
                    outputTokens: data.usage.completion_tokens || 0
                } : null
            );
        } catch (error) {
            console.error('Deepseek API Error:', error);
            
            // Return error as ToolCallResultMessage to continue to next step
            const errorTool = new Tool('api_error', 'API Error');
            errorTool.setCallId('error_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9));
            const errorDetails = `ERROR: ${error.message || 'Unknown error'}\n\nAPI request failed. Please try using a different approach or tool.`;
            errorTool.setResult(errorDetails);
            errorTool.error = error.message || 'Unknown error';
            
            const toolCallResult = new ToolCallResultMessage([errorTool]);
            return toolCallResult;
        }
    }

    /**
     * Stream method
     */
    async *stream(options) {
        const { systemPrompt, tools, messages } = options;
        
        // Format messages for OpenAI API
        const formattedMessages = [];
        
        // Add system message
        if (systemPrompt) {
            formattedMessages.push({
                role: 'system',
                content: systemPrompt
            });
        }
        
        // Add conversation messages
        // Handle both Message objects and plain objects
        for (const msg of messages) {
            // Check if it's a Message object
            if (msg instanceof UserMessage) {
                formattedMessages.push({
                    role: 'user',
                    content: msg.getContent()
                });
            } else if (msg instanceof AssistantMessage) {
                formattedMessages.push({
                    role: 'assistant',
                    content: msg.getContent()
                });
            } else if (msg instanceof ToolCallMessage) {
                // Format tool calls
                const toolCalls = msg.getTools().map(tool => ({
                    id: tool.getCallId() || `call_${Date.now()}_${Math.random()}`,
                    type: 'function',
                    function: {
                        name: tool.getName(),
                        arguments: JSON.stringify(tool.getInputs())
                    }
                }));
                formattedMessages.push({
                    role: 'assistant',
                    tool_calls: toolCalls
                });
            } else if (msg instanceof ToolCallResultMessage) {
                // Each tool result needs its own message with the correct tool_call_id
                for (const tool of msg.getTools()) {
                    const toolCallId = tool.getCallId();
                    if (!toolCallId) {
                        console.warn('Tool result missing tool_call_id:', tool);
                        continue;
                    }
                    const result = tool.getResult();
                    let content;
                    if (tool.error) {
                        content = JSON.stringify({ error: tool.error });
                    } else if (result === null || result === undefined) {
                        content = JSON.stringify({ error: 'Tool execution returned no result' });
                    } else {
                        content = typeof result === 'string' ? result : JSON.stringify(result);
                    }
                    formattedMessages.push({
                        role: 'tool',
                        tool_call_id: toolCallId,
                        content: content
                    });
                }
            } else if (msg && typeof msg === 'object') {
                // Plain object - check role property
                if (msg.role === 'user' || msg.role === 'assistant') {
                    formattedMessages.push({
                        role: msg.role,
                        content: msg.content
                    });
                } else if (msg.role === 'tool') {
                    if (!msg.tool_call_id) {
                        console.warn('Tool message missing tool_call_id:', msg);
                        continue;
                    }
                    formattedMessages.push({
                        role: 'tool',
                        tool_call_id: msg.tool_call_id,
                        content: msg.content
                    });
                } else if (msg.role === 'assistant' && msg.tool_calls) {
                    // Assistant message with tool_calls
                    formattedMessages.push({
                        role: 'assistant',
                        tool_calls: msg.tool_calls
                    });
                }
            }
        }
        
        // Prepare request body
        const body = {
            model: this.model,
            messages: formattedMessages,
            temperature: this.temperature,
            stream: true
        };
        
        // Add tools if available
        if (tools && tools.length > 0) {
            body.tools = tools;
            body.tool_choice = 'auto';
        }
        
        try {
            const response = await fetch(`${this.baseURL}/chat/completions`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.apiKey}`
                },
                body: JSON.stringify(body)
            });
            
            if (!response.ok) {
                const error = await response.json().catch(() => ({ error: { message: `HTTP ${response.status}` } }));
                throw new Error(error.error?.message || `HTTP ${response.status}: ${response.statusText}`);
            }
            
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            
            while (true) {
                const { done, value } = await reader.read();
                
                if (done) break;
                
                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';
                
                for (const line of lines) {
                    if (line.trim() === '' || line.startsWith(':')) continue;
                    
                    if (line.startsWith('data: ')) {
                        const data = line.slice(6);
                        if (data === '[DONE]') {
                            return;
                        }
                        
                        try {
                            const json = JSON.parse(data);
                            const delta = json.choices[0]?.delta;
                            
                            if (!delta) continue;
                            
                            // Handle tool calls
                            if (delta.tool_calls) {
                                for (const toolCall of delta.tool_calls) {
                                    yield {
                                        tools: [{
                                            name: toolCall.function?.name,
                                            callId: toolCall.id,
                                            inputs: toolCall.function?.arguments ? JSON.parse(toolCall.function.arguments) : {}
                                        }]
                                    };
                                }
                                continue;
                            }
                            
                            // Handle content
                            if (delta.content) {
                                yield { content: delta.content };
                            }
                            
                            // Handle usage
                            if (json.usage) {
                                yield {
                                    usage: {
                                        inputTokens: json.usage.prompt_tokens || 0,
                                        outputTokens: json.usage.completion_tokens || 0
                                    }
                                };
                            }
                        } catch (e) {
                            // Skip invalid JSON
                            continue;
                        }
                    }
                }
            }
        } catch (error) {
            console.error('Deepseek Stream Error:', error);
            
            // Yield error as a special chunk instead of throwing
            // This allows the Agent to handle it as a tool result
            const errorTool = new Tool('api_error', 'API Error');
            errorTool.setCallId('error_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9));
            const errorDetails = `ERROR: ${error.message || 'Unknown error'}\n\nAPI request failed. Please try using a different approach or tool.`;
            errorTool.setResult(errorDetails);
            errorTool.error = error.message || 'Unknown error';
            
            yield {
                error: true,
                toolResult: new ToolCallResultMessage([errorTool])
            };
        }
    }
}

/**
 * Task Manager - Manages tasks in localStorage
 */
class TaskManager {
    constructor(storageKey = 'plugitify_tasks') {
        this.storageKey = storageKey;
    }

    /**
     * Get storage key for a specific chat
     */
    getChatKey(chatId) {
        return `${this.storageKey}_chat_${chatId}`;
    }

    /**
     * Get all tasks for a chat
     */
    getTasks(chatId) {
        try {
            const key = this.getChatKey(chatId);
            const data = localStorage.getItem(key);
            return data ? JSON.parse(data) : [];
        } catch (e) {
            console.error('Error loading tasks from localStorage:', e);
            return [];
        }
    }

    /**
     * Save tasks for a chat
     */
    saveTasks(chatId, tasks) {
        try {
            const key = this.getChatKey(chatId);
            
            // Limit number of tasks per chat to prevent localStorage overflow
            const MAX_TASKS_PER_CHAT = 50;
            if (tasks.length > MAX_TASKS_PER_CHAT) {
                // Keep only the most recent tasks
                tasks = tasks.slice(-MAX_TASKS_PER_CHAT);
                console.warn(`Task limit reached for chat ${chatId}. Keeping only ${MAX_TASKS_PER_CHAT} most recent tasks.`);
            }
            
            // Truncate large results to prevent localStorage overflow
            const MAX_RESULT_LENGTH = 2000; // Maximum characters for result
            tasks.forEach(task => {
                if (task.steps) {
                    task.steps.forEach(step => {
                        if (step.result && typeof step.result === 'string' && step.result.length > MAX_RESULT_LENGTH) {
                            step.result = step.result.substring(0, MAX_RESULT_LENGTH) + `\n\n... (truncated, original length: ${step.result.length} characters)`;
                        }
                        if (step.content && typeof step.content === 'string' && step.content.length > MAX_RESULT_LENGTH) {
                            step.content = step.content.substring(0, MAX_RESULT_LENGTH) + `\n\n... (truncated, original length: ${step.content.length} characters)`;
                        }
                    });
                }
                if (task.result && typeof task.result === 'string' && task.result.length > MAX_RESULT_LENGTH) {
                    task.result = task.result.substring(0, MAX_RESULT_LENGTH) + `\n\n... (truncated, original length: ${task.result.length} characters)`;
                }
            });
            
            const dataToSave = JSON.stringify(tasks);
            const dataSize = new Blob([dataToSave]).size;
            const MAX_SIZE = 4 * 1024 * 1024; // 4MB limit (localStorage is usually 5-10MB)
            
            if (dataSize > MAX_SIZE) {
                // If still too large, remove oldest tasks
                console.warn(`Data size (${(dataSize / 1024 / 1024).toFixed(2)}MB) exceeds limit. Removing oldest tasks...`);
                while (tasks.length > 0 && new Blob([JSON.stringify(tasks)]).size > MAX_SIZE) {
                    tasks.shift(); // Remove oldest task
                }
            }
            
            localStorage.setItem(key, JSON.stringify(tasks));
        } catch (e) {
            if (e.name === 'QuotaExceededError') {
                console.error('localStorage quota exceeded. Attempting to clean up old tasks...');
                // Try to clean up old tasks
                this.cleanupOldTasks(chatId);
                // Try saving again with reduced data
                try {
                    const reducedTasks = this.getTasks(chatId).slice(-20); // Keep only 20 most recent
                    localStorage.setItem(this.getChatKey(chatId), JSON.stringify(reducedTasks));
                    console.warn('Successfully saved after cleanup. Only 20 most recent tasks kept.');
                } catch (e2) {
                    console.error('Failed to save even after cleanup:', e2);
                    // Last resort: clear all tasks for this chat
                    localStorage.removeItem(this.getChatKey(chatId));
                    console.warn('Cleared all tasks for this chat due to storage limit.');
                }
            } else {
                console.error('Error saving tasks to localStorage:', e);
            }
        }
    }

    /**
     * Create a new task
     */
    createTask(chatId, taskName, toolName, inputs = {}) {
        const tasks = this.getTasks(chatId);
        const task = {
            id: `task_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`,
            taskName: taskName || toolName,
            toolName: toolName,
            status: 'in_progress',
            steps: [],
            inputs: inputs,
            result: null,
            error: null,
            createdAt: new Date().toISOString(),
            updatedAt: new Date().toISOString()
        };

        // Create initial step
        const step = {
            id: `step_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`,
            stepName: `Executing ${toolName}`,
            stepType: 'tool_execution',
            status: 'in_progress',
            content: `Calling ${toolName} with inputs: ${JSON.stringify(inputs)}`,
            result: null,
            error: null,
            createdAt: new Date().toISOString(),
            updatedAt: new Date().toISOString()
        };

        task.steps.push(step);
        tasks.push(task);
        this.saveTasks(chatId, tasks);
        return task;
    }

    /**
     * Update task step
     */
    updateStep(chatId, taskId, stepId, updates) {
        const tasks = this.getTasks(chatId);
        const task = tasks.find(t => t.id === taskId);
        if (!task) return null;

        const step = task.steps.find(s => s.id === stepId);
        if (!step) return null;

        Object.assign(step, updates);
        step.updatedAt = new Date().toISOString();
        task.updatedAt = new Date().toISOString();

        // Update task status based on step status
        if (step.status === 'completed') {
            task.status = 'completed';
            task.result = step.result;
        } else if (step.status === 'failed') {
            task.status = 'failed';
            task.error = step.error;
        } else if (step.status === 'needs_recovery') {
            task.status = 'needs_recovery';
            task.error = step.error;
        }

        this.saveTasks(chatId, tasks);
        return { task, step };
    }

    /**
     * Find task by step ID (for tool execution)
     */
    findTaskByStepId(chatId, stepId) {
        const tasks = this.getTasks(chatId);
        for (const task of tasks) {
            const step = task.steps.find(s => s.id === stepId);
            if (step) return { task, step };
        }
        return null;
    }

    /**
     * Clear tasks for a chat
     */
    clearTasks(chatId) {
        const key = this.getChatKey(chatId);
        localStorage.removeItem(key);
    }

    /**
     * Get all chats with tasks
     */
    getAllChatsWithTasks() {
        const chats = [];
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && key.startsWith(this.storageKey + '_chat_')) {
                const chatId = key.replace(this.storageKey + '_chat_', '');
                const tasks = this.getTasks(chatId);
                if (tasks.length > 0) {
                    chats.push({ chatId, tasks });
                }
            }
        }
        return chats;
    }

    /**
     * Clean up old tasks for a chat
     */
    cleanupOldTasks(chatId) {
        const tasks = this.getTasks(chatId);
        if (tasks.length > 20) {
            // Keep only 20 most recent tasks
            const recentTasks = tasks.slice(-20);
            try {
                localStorage.setItem(this.getChatKey(chatId), JSON.stringify(recentTasks));
                console.warn(`Cleaned up tasks for chat ${chatId}. Kept ${recentTasks.length} most recent tasks.`);
            } catch (e) {
                console.error('Failed to save after cleanup:', e);
            }
        }
    }

    /**
     * Clean up old tasks across all chats (keep only recent ones)
     */
    cleanupAllOldTasks() {
        const chats = this.getAllChatsWithTasks();
        chats.forEach(({ chatId, tasks }) => {
            if (tasks.length > 20) {
                this.cleanupOldTasks(chatId);
            }
        });
    }
}

// Export all classes
export {
    Agent,
    Tool,
    ToolProperty,
    ChatHistory,
    InMemoryChatHistory,
    Message,
    UserMessage,
    AssistantMessage,
    ToolCallMessage,
    ToolCallResultMessage,
    DeepseekProvider,
    TaskManager
};

// Default export
export default Agent;

