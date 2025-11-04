<?php
namespace App\Neuron;

use \NeuronAI\Agent;
use \NeuronAI\Chat\Messages\UserMessage;
use \NeuronAI\Chat\Messages\Message;
use \NeuronAI\Chat\Messages\ToolCallMessage;
use \NeuronAI\Chat\Messages\ToolCallResultMessage;
use \NeuronAI\Events\MessageSaving;
use \NeuronAI\Events\MessageSaved;
use \NeuronAI\Events\MessageSending;
use \NeuronAI\Events\MessageSent;
use \NeuronAI\Providers\AIProviderInterface;
use \NeuronAI\Providers\Deepseek;
use \NeuronAI\SystemPrompt;
use \NeuronAI\Tools\Tool;
use \NeuronAI\Tools\ToolProperty;
use \GuzzleHttp\Client;

class PlugitifyAgent extends Agent
{
    /**
     * Current chat ID for linking tasks to chats
     */
    protected static $currentChatId = null;
    protected static $currentUserId = null;

    /**
     * Set current chat ID and user ID
     */
    public static function setContext(?int $chatId = null, ?int $userId = null): void
    {
        self::$currentChatId = $chatId;
        self::$currentUserId = $userId ?: get_current_user_id();
    }

    /**
     * Override chat method to add error handling
     * 
     * @param Message|array $messages
     * @return Message
     * @throws \Throwable
     */
    public function chat(Message|array $messages): Message
    {
        try {
            // Call parent chat method
            return parent::chat($messages);
        } catch (\Throwable $e) {
            // Handle error: update chat status and log error
            $this->handleError($e);
            // Re-throw exception to let caller handle it
            throw $e;
        }
    }

    /**
     * Handle errors by updating chat status and logging
     * 
     * @param \Throwable $exception
     */
    protected function handleError(\Throwable $exception): void
    {
        $chatId = self::$currentChatId;
        $userId = self::$currentUserId ?: get_current_user_id();

        // 1. Update chat status to 'error'
        if ($chatId && $chatId > 0) {
            $this->updateChatStatus($chatId, $userId, 'error');
        }

        // 2. Log error to Plugitify_Chat_Logs
        if (class_exists('\Plugitify_Chat_Logs')) {
            try {
                \Plugitify_Chat_Logs::log(array(
                    'action' => 'agent_error',
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'message' => 'Agent error: ' . $exception->getMessage(),
                    'status' => 'error',
                    'metadata' => array(
                        'exception' => get_class($exception),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'trace' => substr($exception->getTraceAsString(), 0, 1000)
                    )
                ));
            } catch (\Exception $log_error) {
                // If logging fails, at least log to error_log
                error_log('Plugitify: Failed to log error to Plugitify_Chat_Logs: ' . $log_error->getMessage());
            }
        }
    }

    /**
     * Update chat status in database
     * 
     * @param int $chatId
     * @param int $userId
     * @param string $status
     * @return bool
     */
    protected function updateChatStatus(int $chatId, int $userId, string $status): bool
    {
        try {
            if (!class_exists('\Plugitify_DB')) {
                return false;
            }

            if (!\Plugitify_DB::tableExists('chat_history')) {
                return false;
            }

            $updated = \Plugitify_DB::table('chat_history')
                ->where('id', $chatId)
                ->where('user_id', $userId)
                ->update(array('status' => $status));

            return $updated !== false;
        } catch (\Exception $e) {
            error_log('Plugitify: Failed to update chat status: ' . $e->getMessage());
            return false;
        }
    }

    public function provider(): AIProviderInterface
    {
        $deepseek = new Deepseek(
            key: 'sk-93c6a02788dd454baa0f34a07b9ca3c7',
            model: 'deepseek-chat',
            parameters: [], // Add custom params (temperature, logprobs, etc)
        );
        
        // Create a custom Guzzle client with SSL verification disabled
        $client = new Client([
            'base_uri' => 'https://api.deepseek.com/v1/',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer sk-93c6a02788dd454baa0f34a07b9ca3c7',
            ],
            'verify' => false, // Disable SSL certificate verification
        ]);
        
        return $deepseek->setClient($client);
    }



    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                "You are an expert WordPress plugin developer AI agent. Your role is to analyze user requirements, design plugin architecture, generate appropriate plugin names, and create complete WordPress plugin code.",
                "You understand WordPress coding standards, hooks, filters, best practices, and plugin structure.",
                "You can create new plugins OR update/modify existing plugins based on user requirements. Not every request requires creating a new plugin - you should update existing plugins when that makes more sense.",
                "CRITICAL: You must NEVER modify, edit, delete, or change ANY files in the plugin directory that matches PLUGITIFY_DIR or contains 'plugitify' or 'Pluginity' in its path. This is the core system plugin and must remain untouched.",
                "ABSOLUTELY FORBIDDEN: You are STRICTLY PROHIBITED from modifying, editing, deactivating, or changing the Pluginity/plugitify plugin in ANY way. This plugin is completely off-limits and must never be touched, edited, or deactivated under any circumstances.",
                "CRITICAL PLUGIN EDITING RULE: Before editing, modifying, or changing ANY code in an existing plugin, you MUST first deactivate that plugin using the deactivate_plugin tool. This prevents potential errors, conflicts, or issues during code modification. The workflow is: 1) Deactivate the plugin, 2) Make your code changes, 3) Optionally reactivate after completion.",
                "CRITICAL STEP MANAGEMENT RULE: You must NEVER create multiple steps at once. Always follow this workflow:",
                "  1. Create ONE step using create_stage",
                "  2. Complete ALL work for that step",
                "  3. Update the step status to 'completed' using update_stage_status",
                "  4. Only AFTER the current step is completed and its status is updated, you may create the next step",
                "Do NOT create a new step until you have finished the current step's work and updated its status to 'completed'.",
                "CRITICAL: Break down tasks into VERY SMALL and GRANULAR steps to avoid errors during execution.",
                "  - Each step should be so small that it can be completed reliably without errors",
                "  - Instead of 'Create plugin files', break it into: 'Create main plugin file', 'Create class file X', 'Create class file Y', etc.",
                "  - Instead of 'Create directory structure', break it into individual directory creation steps",
                "  - Each step should represent ONE specific action that can be verified and completed successfully",
                "  - Smaller steps = fewer errors, better progress tracking, and easier troubleshooting",
                "  - When in doubt, make the step even smaller. It's better to have many small steps than a few large steps that fail.",
                "CRITICAL LANGUAGE RULE: All task titles, step names, descriptions, and content in task/step management MUST be in English.",
                "  - When using create_task, create_stage, update_task_status, update_stage_status: ALL parameters (task_name, description, step_name, content, etc.) MUST be in English",
                "  - This ensures consistency in the database and system tracking",
                "  - However, your conversational responses to users should match the language they used in their message (if they write in Persian/Farsi, respond in Persian; if English, respond in English)",
                "  - Only the task/step metadata is in English - all user-facing messages follow the user's language",
             
            ],
            steps: [
                "First, carefully analyze the user's request to understand what functionality they need.",
                "CRITICAL: If you need to edit or modify code in an existing plugin, you MUST first deactivate that plugin using deactivate_plugin tool before making any code changes. Never edit active plugins without deactivating them first.",
                "ABSOLUTE RESTRICTION: Never attempt to modify, edit, or deactivate the Pluginity/plugitify plugin. Check plugin paths carefully and reject any request to modify Pluginity-related files.",
                "IMPORTANT: For any complex work, ALWAYS use the task management tools:",
                "  - Use create_task at the very beginning to define the work that needs to be done",
                "  - Use create_stage to break down the task into trackable steps",
                "  - Use update_task_status and update_stage_status to show progress as you work",
                "  - Use complete_task when all work is finished",
                "This helps users see progress in real-time instead of waiting for the final response.",
                  "Break down the requirements into specific features and components.",
                "Generate an appropriate plugin name based on the functionality (use lowercase, hyphens for spaces, e.g., 'my-custom-plugin').",
                "Plan the plugin structure: main plugin file, classes, includes, assets folders, etc.",
                "Create the plugin directory structure using create_directory tool.",
                "Generate all necessary PHP files with proper WordPress headers, hooks, filters, and code.",
                "Create the main plugin file with standard WordPress plugin headers (Plugin Name, Description, Version, Author, etc.).",
                "IMPORTANT: When creating plugin headers, always use: Author: 'wpagentify', Author URI: 'https://wpagentify.com/', and Plugin URI: 'https://wpagentify.com/'.",
                "Write organized, clean, commented code following WordPress coding standards.",
                "Use create_file tool to create each file with its complete code content.",
                "Update task and stage statuses as you complete each part of the work.",
                "Ensure all files are properly structured and ready to use.",
            ],
            output: [
                "After completing all work, provide a clear, natural language summary .",
                "DO NOT return JSON format. Always use natural, conversational text.",
                "Your final response should include:",
                "  1. A brief summary of what was accomplished (what plugin/features were created)",
                "  2. A list of files that were created with their purposes",
                "  3. Key features that were implemented",
                "  4. Clear instructions on what the user needs to do next (e.g., activate the plugin, configure settings, etc.)",
                "  5. Invite the user to ask questions if they need help or have any concerns",
                "Write in a friendly, helpful tone. IMPORTANT: Match the user's language in your responses - if they write in Persian/Farsi, respond in Persian; if English, respond in English. Only task/step metadata (titles, descriptions) must be in English, but all conversational messages should match the user's language.",
                "Example format (NOT JSON):",
                "  'The [plugin name] plugin has been successfully created! I've created the following files for you: ...'",
                "  'To use the plugin, you need to: ...'",
                "  'If you have any questions or need any modifications, please feel free to ask.'",
            ]
        );
    }

    public function tools(): array
    {
        $pluginsDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('ABSPATH') ? ABSPATH . 'wp-content/plugins/' : __DIR__ . '/../../../../plugins/');
        
        return [
            Tool::make(
                'create_directory',
                'Create a directory/folder for the WordPress plugin. Use this to create the main plugin folder and subdirectories like includes, assets, etc.',
            )->addProperty(
                new ToolProperty(
                    name: 'path',
                    type: 'string',
                    description: 'The full path of the directory to create (relative to plugins directory or absolute path). Example: "my-plugin" or "my-plugin/includes"',
                    required: true
                )
            )->setCallable(function (string $path) use ($pluginsDir) {
                // Remove leading slash and ensure path is safe
                $path = ltrim($path, '/\\');
                
                // If path doesn't start with plugins directory, prepend it
                if (strpos($path, $pluginsDir) !== 0) {
                    $fullPath = rtrim($pluginsDir, '/\\') . '/' . $path;
                } else {
                    $fullPath = $path;
                }
                
                // Normalize path separators
                $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
                
                // Create directory recursively
                if (!is_dir($fullPath)) {
                    if (mkdir($fullPath, 0755, true)) {
                        return "Directory created successfully: {$fullPath}";
                    } else {
                        return "Failed to create directory: {$fullPath}";
                    }
                } else {
                    return "Directory already exists: {$fullPath}";
                }
            }),

            Tool::make(
                'create_file',
                'Create a PHP file with code content for the WordPress plugin. Use this to create main plugin file, class files, and any other PHP files needed.',
            )->addProperty(
                new ToolProperty(
                    name: 'file_path',
                    type: 'string',
                    description: 'The file path relative to plugins directory or absolute path. Example: "my-plugin/my-plugin.php" or "my-plugin/includes/class-main.php"',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'content',
                    type: 'string',
                    description: 'The complete PHP code content to write to the file. Include <?php opening tag and all necessary code.',
                    required: true
                )
            )->setCallable(function (string $file_path, string $content) use ($pluginsDir) {
                // Remove leading slash and ensure path is safe
                $file_path = ltrim($file_path, '/\\');
                
                // If path doesn't start with plugins directory, prepend it
                if (strpos($file_path, $pluginsDir) !== 0) {
                    $fullPath = rtrim($pluginsDir, '/\\') . '/' . $file_path;
                } else {
                    $fullPath = $file_path;
                }
                
                // Normalize path separators
                $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
                
                // Create directory if it doesn't exist
                $dir = dirname($fullPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                // Write file
                if (file_put_contents($fullPath, $content) !== false) {
                    return "File created successfully: {$fullPath}";
                } else {
                    return "Failed to create file: {$fullPath}";
                }
            }),

            Tool::make(
                'delete_file',
                'Delete a file from the WordPress plugin directory. Use this to remove unwanted files or clean up plugin files.',
            )->addProperty(
                new ToolProperty(
                    name: 'file_path',
                    type: 'string',
                    description: 'The file path relative to plugins directory or absolute path. Example: "my-plugin/old-file.php"',
                    required: true
                )
            )->setCallable(function (string $file_path) use ($pluginsDir) {
                // Remove leading slash and ensure path is safe
                $file_path = ltrim($file_path, '/\\');
                
                // If path doesn't start with plugins directory, prepend it
                if (strpos($file_path, $pluginsDir) !== 0) {
                    $fullPath = rtrim($pluginsDir, '/\\') . '/' . $file_path;
                } else {
                    $fullPath = $file_path;
                }
                
                // Normalize path separators
                $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
                
                // Check if path is in Pluginity/plugitify directory (CRITICAL: Never allow deletion)
                $plugitifyDir = defined('PLUGITIFY_DIR') ? PLUGITIFY_DIR : __DIR__ . '/../../';
                $plugitifyDir = realpath($plugitifyDir);
                $fullPathReal = realpath($fullPath);
                
                if ($plugitifyDir && $fullPathReal) {
                    if (strpos($fullPathReal, $plugitifyDir) === 0 || 
                        stripos($fullPath, 'plugitify') !== false || 
                        stripos($fullPath, 'Pluginity') !== false) {
                        return "ERROR: Cannot delete files in the Pluginity/plugitify plugin directory. This is a protected system plugin.";
                    }
                }
                
                // Check if file exists
                if (!file_exists($fullPath)) {
                    return "File does not exist: {$fullPath}";
                }
                
                // Check if it's actually a file (not a directory)
                if (!is_file($fullPath)) {
                    return "Path is not a file: {$fullPath}. Use delete_directory to remove directories.";
                }
                
                // Delete file
                if (unlink($fullPath)) {
                    return "File deleted successfully: {$fullPath}";
                } else {
                    return "Failed to delete file: {$fullPath}";
                }
            }),

            Tool::make(
                'delete_directory',
                'Delete a directory and all its contents recursively from the WordPress plugin directory. Use this to remove plugin folders or clean up directories.',
            )->addProperty(
                new ToolProperty(
                    name: 'path',
                    type: 'string',
                    description: 'The directory path relative to plugins directory or absolute path. Example: "my-plugin" or "my-plugin/includes"',
                    required: true
                )
            )->setCallable(function (string $path) use ($pluginsDir) {
                // Remove leading slash and ensure path is safe
                $path = ltrim($path, '/\\');
                
                // If path doesn't start with plugins directory, prepend it
                if (strpos($path, $pluginsDir) !== 0) {
                    $fullPath = rtrim($pluginsDir, '/\\') . '/' . $path;
                } else {
                    $fullPath = $path;
                }
                
                // Normalize path separators
                $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
                
                // Check if path is in Pluginity/plugitify directory (CRITICAL: Never allow deletion)
                $plugitifyDir = defined('PLUGITIFY_DIR') ? PLUGITIFY_DIR : __DIR__ . '/../../';
                $plugitifyDir = realpath($plugitifyDir);
                $fullPathReal = realpath($fullPath);
                
                if ($plugitifyDir && $fullPathReal) {
                    if (strpos($fullPathReal, $plugitifyDir) === 0 || 
                        stripos($fullPath, 'plugitify') !== false || 
                        stripos($fullPath, 'Pluginity') !== false) {
                        return "ERROR: Cannot delete directories in the Pluginity/plugitify plugin directory. This is a protected system plugin.";
                    }
                }
                
                // Check if directory exists
                if (!is_dir($fullPath)) {
                    return "Directory does not exist: {$fullPath}";
                }
                
                // Recursive directory deletion function
                $deleteRecursive = function($dir) use (&$deleteRecursive) {
                    if (!is_dir($dir)) {
                        return false;
                    }
                    
                    $files = array_diff(scandir($dir), ['.', '..']);
                    foreach ($files as $file) {
                        $filePath = $dir . DIRECTORY_SEPARATOR . $file;
                        if (is_dir($filePath)) {
                            $deleteRecursive($filePath);
                        } else {
                            unlink($filePath);
                        }
                    }
                    
                    return rmdir($dir);
                };
                
                // Delete directory recursively
                if ($deleteRecursive($fullPath)) {
                    return "Directory deleted successfully: {$fullPath}";
                } else {
                    return "Failed to delete directory: {$fullPath}";
                }
            }),

            Tool::make(
                'list_plugins',
                'Get a list of all installed WordPress plugins with their details (name, version, description, status, etc.). Use this to check existing plugins before creating a new one to avoid name conflicts.',
            )->addProperty(
                new ToolProperty(
                    name: 'status',
                    type: 'string',
                    description: 'Filter plugins by status: "all" (default), "active", "inactive", or "active-network". Leave empty for all plugins.',
                    required: false
                )
            )->setCallable(function (?string $status = null) {
                // Include WordPress plugin functions if not already included
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                
                // Get all plugins
                $allPlugins = get_plugins();
                
                // Get active plugins
                $activePlugins = get_option('active_plugins', []);
                $networkActivePlugins = [];
                if (is_multisite()) {
                    $networkActivePlugins = get_site_option('active_sitewide_plugins', []);
                }
                
                $pluginsList = [];
                
                foreach ($allPlugins as $pluginFile => $pluginData) {
                    $isActive = in_array($pluginFile, $activePlugins);
                    $isNetworkActive = in_array($pluginFile, $networkActivePlugins);
                    
                    // Filter by status if specified
                    if ($status !== null && $status !== 'all') {
                        if ($status === 'active' && !$isActive && !$isNetworkActive) {
                            continue;
                        }
                        if ($status === 'inactive' && ($isActive || $isNetworkActive)) {
                            continue;
                        }
                        if ($status === 'active-network' && !$isNetworkActive) {
                            continue;
                        }
                    }
                    
                    $pluginsList[] = [
                        'file' => $pluginFile,
                        'name' => $pluginData['Name'] ?? 'Unknown',
                        'version' => $pluginData['Version'] ?? 'N/A',
                        'description' => $pluginData['Description'] ?? '',
                        'author' => $pluginData['Author'] ?? 'Unknown',
                        'status' => $isNetworkActive ? 'network-active' : ($isActive ? 'active' : 'inactive'),
                        'plugin_uri' => $pluginData['PluginURI'] ?? '',
                        'text_domain' => $pluginData['TextDomain'] ?? '',
                    ];
                }
                
                // Format the response
                $response = "Found " . count($pluginsList) . " plugin(s):\n\n";
                foreach ($pluginsList as $plugin) {
                    $response .= "• {$plugin['name']} (v{$plugin['version']}) - Status: {$plugin['status']}\n";
                    $response .= "  File: {$plugin['file']}\n";
                    if (!empty($plugin['description'])) {
                        $response .= "  Description: {$plugin['description']}\n";
                    }
                    if (!empty($plugin['author'])) {
                        $response .= "  Author: {$plugin['author']}\n";
                    }
                    $response .= "\n";
                }
                
                return $response;
            }),

            Tool::make(
                'deactivate_plugin',
                'Deactivate a WordPress plugin. Use this to disable a plugin that is currently active. The plugin files will remain but it will be deactivated.',
            )->addProperty(
                new ToolProperty(
                    name: 'plugin_file',
                    type: 'string',
                    description: 'The plugin file path (e.g., "my-plugin/my-plugin.php") or plugin name. You can get the exact plugin file path from the list_plugins tool.',
                    required: true
                )
            )->setCallable(function (string $plugin_file) {
                // Include WordPress plugin functions if not already included
                if (!function_exists('deactivate_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                
                // Get all plugins to find the plugin file
                $allPlugins = get_plugins();
                
                // Try to find the plugin by file path
                $pluginPath = null;
                if (isset($allPlugins[$plugin_file])) {
                    $pluginPath = $plugin_file;
                } else {
                    // Try to find by plugin name or partial match
                    foreach ($allPlugins as $file => $pluginData) {
                        if ($file === $plugin_file || 
                            $pluginData['Name'] === $plugin_file ||
                            strpos($file, $plugin_file) !== false) {
                            $pluginPath = $file;
                            break;
                        }
                    }
                }
                
                if (!$pluginPath) {
                    return "Plugin not found: {$plugin_file}. Use list_plugins to see available plugins.";
                }
                
                // Check if plugin is already inactive
                $activePlugins = get_option('active_plugins', []);
                $isNetworkActive = false;
                if (is_multisite()) {
                    $networkActivePlugins = get_site_option('active_sitewide_plugins', []);
                    $isNetworkActive = isset($networkActivePlugins[$pluginPath]);
                }
                
                $isActive = in_array($pluginPath, $activePlugins);
                
                if (!$isActive && !$isNetworkActive) {
                    $pluginName = $allPlugins[$pluginPath]['Name'] ?? $pluginPath;
                    return "Plugin '{$pluginName}' is already inactive.";
                }
                
                // Prevent deactivating Plugitify itself
                if (stripos($pluginPath, 'plugitify') !== false || stripos($pluginPath, 'Pluginity') !== false) {
                    return "ERROR: Cannot deactivate the Pluginity/plugitify plugin. This is a protected system plugin.";
                }
                
                // Deactivate the plugin
                $result = deactivate_plugins($pluginPath);
                
                if (is_wp_error($result)) {
                    return "Failed to deactivate plugin: " . $result->get_error_message();
                }
                
                $pluginName = $allPlugins[$pluginPath]['Name'] ?? $pluginPath;
                return "Plugin '{$pluginName}' ({$pluginPath}) has been successfully deactivated.";
            }),

            Tool::make(
                'read_file',
                'Read the content of any file. Use this to read PHP files, text files, JSON files, or any other file type.',
            )->addProperty(
                new ToolProperty(
                    name: 'file_path',
                    type: 'string',
                    description: 'The full path of the file to read (relative to plugins directory, WordPress root, or absolute path). Example: "my-plugin/my-plugin.php" or absolute path.',
                    required: true
                )
            )->setCallable(function (string $file_path) use ($pluginsDir) {
                // Remove leading slash and ensure path is safe
                $file_path = ltrim($file_path, '/\\');
                
                // Check if it's an absolute path
                if (file_exists($file_path)) {
                    $fullPath = $file_path;
                } elseif (defined('ABSPATH') && file_exists(ABSPATH . $file_path)) {
                    $fullPath = ABSPATH . $file_path;
                } elseif (strpos($file_path, $pluginsDir) !== 0) {
                    $fullPath = rtrim($pluginsDir, '/\\') . '/' . $file_path;
                } else {
                    $fullPath = $file_path;
                }
                
                // Normalize path separators
                $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
                
                // Check if file exists
                if (!file_exists($fullPath)) {
                    return "File not found: {$fullPath}";
                }
                
                // Check if it's a file (not directory)
                if (!is_file($fullPath)) {
                    return "Path is not a file: {$fullPath}";
                }
                
                // Read file content
                $content = file_get_contents($fullPath);
                if ($content === false) {
                    return "Failed to read file: {$fullPath}";
                }
                
                return "File content ({$fullPath}):\n\n" . $content;
            }),

            Tool::make(
                'edit_file_line',
                'Edit a specific line or lines in a file. Use this to modify existing code in a file by replacing specific line(s) with new content.',
            )->addProperty(
                new ToolProperty(
                    name: 'file_path',
                    type: 'string',
                    description: 'The full path of the file to edit (relative to plugins directory, WordPress root, or absolute path). Example: "my-plugin/my-plugin.php"',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'line_number',
                    type: 'integer',
                    description: 'The line number to edit (1-based index).',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'new_content',
                    type: 'string',
                    description: 'The new content to replace the line with. Can be empty string to delete the line.',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'line_count',
                    type: 'integer',
                    description: 'Number of lines to replace (default: 1). If greater than 1, replaces multiple consecutive lines.',
                    required: false
                )
            )->setCallable(function (string $file_path, int $line_number, string $new_content, ?int $line_count = 1) use ($pluginsDir) {
                // Remove leading slash and ensure path is safe
                $file_path = ltrim($file_path, '/\\');
                
                // Check if it's an absolute path
                if (file_exists($file_path)) {
                    $fullPath = $file_path;
                } elseif (defined('ABSPATH') && file_exists(ABSPATH . $file_path)) {
                    $fullPath = ABSPATH . $file_path;
                } elseif (strpos($file_path, $pluginsDir) !== 0) {
                    $fullPath = rtrim($pluginsDir, '/\\') . '/' . $file_path;
                } else {
                    $fullPath = $file_path;
                }
                
                // Normalize path separators
                $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
                
                // Check if file exists
                if (!file_exists($fullPath)) {
                    return "File not found: {$fullPath}";
                }
                
                // Check if it's a file (not directory)
                if (!is_file($fullPath)) {
                    return "Path is not a file: {$fullPath}";
                }
                
                // Read file content
                $lines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines === false) {
                    // Try reading with all lines (including empty ones)
                    $content = file_get_contents($fullPath);
                    if ($content === false) {
                        return "Failed to read file: {$fullPath}";
                    }
                    $lines = explode("\n", $content);
                } else {
                    // Re-read with all lines to preserve empty lines
                    $content = file_get_contents($fullPath);
                    $lines = explode("\n", $content);
                }
                
                // Validate line number (1-based)
                if ($line_number < 1 || $line_number > count($lines)) {
                    return "Line number {$line_number} is out of range. File has " . count($lines) . " lines.";
                }
                
                // Adjust line_count if it would go beyond file length
                $line_count = max(1, $line_count);
                $end_line = min($line_number + $line_count - 1, count($lines));
                
                // Split new_content into lines
                $new_lines = explode("\n", $new_content);
                
                // Replace lines (0-based index)
                $before = array_slice($lines, 0, $line_number - 1);
                $after = array_slice($lines, $end_line);
                $updated_lines = array_merge($before, $new_lines, $after);
                
                // Write back to file
                $new_content_full = implode("\n", $updated_lines);
                if (file_put_contents($fullPath, $new_content_full) !== false) {
                    return "Successfully edited line(s) {$line_number}" . ($line_count > 1 ? "-{$end_line}" : "") . " in file: {$fullPath}";
                } else {
                    return "Failed to write file: {$fullPath}";
                }
            }),

            Tool::make(
                'extract_plugin_structure',
                'Extract and analyze the structure/graph of a WordPress plugin. This analyzes the plugin directory to understand its architecture, classes, functions, hooks, and file organization.',
            )->addProperty(
                new ToolProperty(
                    name: 'plugin_name',
                    type: 'string',
                    description: 'The name/slug of the plugin directory. Example: "my-plugin" or the plugin folder name.',
                    required: true
                )
            )->setCallable(function (string $plugin_name) use ($pluginsDir) {
                // Clean plugin name
                $plugin_name = trim($plugin_name, '/\\');
                
                // Build plugin path
                $plugin_path = rtrim($pluginsDir, '/\\') . '/' . $plugin_name;
                $plugin_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $plugin_path);
                
                // Check if plugin directory exists
                if (!is_dir($plugin_path)) {
                    return "Plugin directory not found: {$plugin_path}";
                }
                
                $structure = [
                    'plugin_name' => $plugin_name,
                    'plugin_path' => $plugin_path,
                    'directories' => [],
                    'files' => [],
                    'main_file' => null,
                    'classes' => [],
                    'functions' => [],
                    'hooks' => [
                        'actions' => [],
                        'filters' => []
                    ],
                    'includes' => []
                ];
                
                // Recursive function to scan directory
                $scanDirectory = function($dir, $relative_path = '') use (&$scanDirectory, &$structure) {
                    $items = scandir($dir);
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') {
                            continue;
                        }
                        
                        $item_path = $dir . DIRECTORY_SEPARATOR . $item;
                        $relative_item_path = $relative_path ? $relative_path . '/' . $item : $item;
                        
                        if (is_dir($item_path)) {
                            // Skip vendor, node_modules, .git directories
                            if (in_array($item, ['vendor', 'node_modules', '.git', '.svn'])) {
                                continue;
                            }
                            
                            $structure['directories'][] = $relative_item_path;
                            $scanDirectory($item_path, $relative_item_path);
                        } elseif (is_file($item_path)) {
                            $structure['files'][] = $relative_item_path;
                            
                            // Check if it's a PHP file and analyze it
                            if (pathinfo($item_path, PATHINFO_EXTENSION) === 'php') {
                                $content = file_get_contents($item_path);
                                if ($content !== false) {
                                    // Check if it's the main plugin file
                                    if (preg_match('/Plugin Name:\s*(.+)/i', $content, $matches)) {
                                        $structure['main_file'] = $relative_item_path;
                                    }
                                    
                                    // Extract class names
                                    if (preg_match_all('/\bclass\s+(\w+)/i', $content, $class_matches)) {
                                        foreach ($class_matches[1] as $class_name) {
                                            $structure['classes'][] = [
                                                'name' => $class_name,
                                                'file' => $relative_item_path
                                            ];
                                        }
                                    }
                                    
                                    // Extract function names (excluding methods)
                                    if (preg_match_all('/\bfunction\s+(\w+)\s*\(/i', $content, $func_matches, PREG_OFFSET_CAPTURE)) {
                                        foreach ($func_matches[1] as $func_match) {
                                            $func_name = $func_match[0];
                                            $func_pos = $func_match[1];
                                            
                                            // Check if it's not a method (look backwards for class/function context)
                                            $before = substr($content, max(0, $func_pos - 100), $func_pos);
                                            if (!preg_match('/\b(class|function)\s+\w+\s*\{[^}]*$/', $before)) {
                                                $structure['functions'][] = [
                                                    'name' => $func_name,
                                                    'file' => $relative_item_path
                                                ];
                                            }
                                        }
                                    }
                                    
                                    // Extract WordPress hooks (actions and filters)
                                    if (preg_match_all('/(add_action|add_filter)\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $content, $hook_matches)) {
                                        foreach ($hook_matches[2] as $hook_name) {
                                            if (strpos($hook_matches[0][0], 'add_action') !== false) {
                                                $structure['hooks']['actions'][] = [
                                                    'hook' => $hook_name,
                                                    'file' => $relative_item_path
                                                ];
                                            } else {
                                                $structure['hooks']['filters'][] = [
                                                    'hook' => $hook_name,
                                                    'file' => $relative_item_path
                                                ];
                                            }
                                        }
                                    }
                                    
                                    // Extract include/require statements
                                    if (preg_match_all('/(include|require|include_once|require_once)\s+[\'"]([^\'"]+)[\'"]/i', $content, $include_matches)) {
                                        foreach ($include_matches[2] as $include_path) {
                                            $structure['includes'][] = [
                                                'path' => $include_path,
                                                'file' => $relative_item_path
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                    }
                };
                
                // Start scanning
                $scanDirectory($plugin_path);
                
                // Format output
                $output = "Plugin Structure Analysis: {$plugin_name}\n";
                $output .= "=" . str_repeat("=", 50) . "\n\n";
                
                $output .= "Plugin Path: {$structure['plugin_path']}\n";
                if ($structure['main_file']) {
                    $output .= "Main File: {$structure['main_file']}\n";
                }
                $output .= "\n";
                
                $output .= "Directories (" . count($structure['directories']) . "):\n";
                foreach ($structure['directories'] as $dir) {
                    $output .= "  📁 {$dir}\n";
                }
                $output .= "\n";
                
                $output .= "Files (" . count($structure['files']) . "):\n";
                foreach ($structure['files'] as $file) {
                    $output .= "  📄 {$file}\n";
                }
                $output .= "\n";
                
                $output .= "Classes (" . count($structure['classes']) . "):\n";
                foreach ($structure['classes'] as $class) {
                    $output .= "  🏛️  {$class['name']} (in {$class['file']})\n";
                }
                $output .= "\n";
                
                $output .= "Functions (" . count($structure['functions']) . "):\n";
                foreach ($structure['functions'] as $func) {
                    $output .= "  ⚡ {$func['name']}() (in {$func['file']})\n";
                }
                $output .= "\n";
                
                $output .= "WordPress Hooks:\n";
                $output .= "  Actions (" . count($structure['hooks']['actions']) . "):\n";
                foreach ($structure['hooks']['actions'] as $action) {
                    $output .= "    🔗 {$action['hook']} (in {$action['file']})\n";
                }
                $output .= "  Filters (" . count($structure['hooks']['filters']) . "):\n";
                foreach ($structure['hooks']['filters'] as $filter) {
                    $output .= "    🔗 {$filter['hook']} (in {$filter['file']})\n";
                }
                
                return $output;
            }),

            // Task Management Tools
            Tool::make(
                'create_task',
                'Create a new task to track work progress. Use this at the beginning of any complex work to define what needs to be done. This helps the user see progress in real-time.',
            )->addProperty(
                new ToolProperty(
                    name: 'task_name',
                    type: 'string',
                    description: 'The name/title of the task. Example: "Create WordPress Plugin"',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'description',
                    type: 'string',
                    description: 'Detailed description of what this task involves.',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'task_type',
                    type: 'string',
                    description: 'Type of task: plugin_creation, code_generation, analysis, etc.',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'requirements',
                    type: 'string',
                    description: 'JSON string or text describing the requirements for this task.',
                    required: false
                )
            )->setCallable(function (string $task_name, ?string $description = null, ?string $task_type = null, ?string $requirements = null) {
                $chatId = self::$currentChatId;
                $userId = self::$currentUserId ?: get_current_user_id();
                
                // Ensure tasks table exists
                if (!\Plugitify_DB::tableExists('tasks')) {
                    return "Tasks table does not exist. Please run migrations first.";
                }
                
                $taskData = [
                    'chat_history_id' => $chatId,
                    'user_id' => $userId,
                    'task_name' => $task_name,
                    'task_type' => $task_type ?: 'plugin_creation',
                    'description' => $description,
                    'requirements' => $requirements ?: null,
                    'status' => 'pending',
                    'progress' => 0
                ];
                
                $taskId = \Plugitify_DB::table('tasks')->insert($taskData);
                
                if ($taskId) {
                    return "Task created successfully (ID: {$taskId}): {$task_name}";
                } else {
                    return "Failed to create task: {$task_name}";
                }
            }),

            Tool::make(
                'update_task_status',
                'Update the status and progress of a task. Use this to show the user what stage the work is at.',
            )->addProperty(
                new ToolProperty(
                    name: 'status',
                    type: 'string',
                    description: 'Status: pending, in_progress, completed, failed, cancelled',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'task_id',
                    type: 'integer',
                    description: 'The ID of the task to update. If not provided, will update the most recent task for this chat.',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'progress',
                    type: 'integer',
                    description: 'Progress percentage (0-100)',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'result',
                    type: 'string',
                    description: 'Result data or summary (can be JSON string)',
                    required: false
                )
            )->setCallable(function (string $status, ?int $task_id = null, ?int $progress = null, ?string $result = null) {
                $chatId = self::$currentChatId;
                $userId = self::$currentUserId ?: get_current_user_id();
                
                if (!\Plugitify_DB::tableExists('tasks')) {
                    return "Tasks table does not exist.";
                }
                
                $query = \Plugitify_DB::table('tasks')->where('user_id', $userId);
                
                if ($task_id) {
                    $query->where('id', $task_id);
                } else {
                    // Get most recent task for this chat
                    if ($chatId) {
                        $query->where('chat_history_id', $chatId);
                    }
                    $query->orderBy('created_at', 'DESC');
                    $task = $query->first();
                    if (!$task) {
                        return "No task found to update.";
                    }
                    $task_id = is_object($task) ? $task->id : $task['id'];
                }
                
                $updateData = ['status' => $status];
                
                if ($progress !== null) {
                    $updateData['progress'] = max(0, min(100, $progress));
                }
                
                if ($result !== null) {
                    $updateData['result'] = $result;
                }
                
                $updated = \Plugitify_DB::table('tasks')
                    ->where('id', $task_id)
                    ->where('user_id', $userId)
                    ->update($updateData);
                
                if ($updated) {
                    return "Task #{$task_id} status updated to: {$status}" . ($progress !== null ? " (Progress: {$progress}%)" : "");
                } else {
                    return "Failed to update task #{$task_id}";
                }
            }),

            Tool::make(
                'create_stage',
                'Create a stage/step for a task. Use this to break down work into smaller, trackable steps.',
            )->addProperty(
                new ToolProperty(
                    name: 'step_name',
                    type: 'string',
                    description: 'Name of the stage/step. Example: "Create main plugin file"',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'task_id',
                    type: 'integer',
                    description: 'The ID of the task this stage belongs to. If not provided, will use the most recent task for this chat.',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'step_type',
                    type: 'string',
                    description: 'Type of stage: file_creation, directory_creation, code_generation, etc.',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'order',
                    type: 'integer',
                    description: 'Order/sequence number for this stage (lower numbers come first)',
                    required: false
                )
            )->setCallable(function (string $step_name, ?int $task_id = null, ?string $step_type = null, ?int $order = null) {
                $chatId = self::$currentChatId;
                $userId = self::$currentUserId ?: get_current_user_id();
                
                if (!\Plugitify_DB::tableExists('steps')) {
                    return "Steps table does not exist.";
                }
                
                if (!$task_id) {
                    // Get most recent task for this chat
                    $query = \Plugitify_DB::table('tasks')->where('user_id', $userId);
                    if ($chatId) {
                        $query->where('chat_history_id', $chatId);
                    }
                    $task = $query->orderBy('created_at', 'DESC')->first();
                    
                    if (!$task) {
                        return "No task found. Please create a task first.";
                    }
                    $task_id = is_object($task) ? $task->id : $task['id'];
                }
                
                // Get max order if not provided
                if ($order === null) {
                    global $wpdb;
                    $table_name = \Plugitify_DB::getFullTableName('steps');
                    $maxOrder = $wpdb->get_var($wpdb->prepare(
                        "SELECT MAX(`order`) FROM {$table_name} WHERE task_id = %d",
                        $task_id
                    ));
                    $order = ($maxOrder !== null && $maxOrder !== false) ? intval($maxOrder) + 1 : 1;
                }
                
                $stepData = [
                    'task_id' => $task_id,
                    'step_name' => $step_name,
                    'step_type' => $step_type,
                    'order' => $order,
                    'status' => 'pending'
                ];
                
                $stepId = \Plugitify_DB::table('steps')->insert($stepData);
                
                if ($stepId) {
                    return "Stage created successfully (ID: {$stepId}): {$step_name}";
                } else {
                    return "Failed to create stage: {$step_name}";
                }
            }),

            Tool::make(
                'update_stage_status',
                'Update the status of a stage/step. Use this to track progress of individual steps.',
            )->addProperty(
                new ToolProperty(
                    name: 'status',
                    type: 'string',
                    description: 'Status: pending, in_progress, completed, failed, skipped',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'step_id',
                    type: 'integer',
                    description: 'The ID of the stage to update. If not provided, will update the most recent stage for the current task.',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'content',
                    type: 'string',
                    description: 'Content or description of what was done in this stage',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'result',
                    type: 'string',
                    description: 'Result data (can be JSON string)',
                    required: false
                )
            )->setCallable(function (string $status, ?int $step_id = null, ?string $content = null, ?string $result = null) {
                $chatId = self::$currentChatId;
                $userId = self::$currentUserId ?: get_current_user_id();
                
                if (!\Plugitify_DB::tableExists('steps')) {
                    return "Steps table does not exist.";
                }
                
                if (!$step_id) {
                    // Get most recent task
                    $query = \Plugitify_DB::table('tasks')->where('user_id', $userId);
                    if ($chatId) {
                        $query->where('chat_history_id', $chatId);
                    }
                    $task = $query->orderBy('created_at', 'DESC')->first();
                    
                    if (!$task) {
                        return "No task found.";
                    }
                    $task_id = is_object($task) ? $task->id : $task['id'];
                    
                    // Get most recent step for this task
                    $step = \Plugitify_DB::table('steps')
                        ->where('task_id', $task_id)
                        ->orderBy('created_at', 'DESC')
                        ->first();
                    
                    if (!$step) {
                        return "No stage found to update.";
                    }
                    $step_id = is_object($step) ? $step->id : $step['id'];
                }
                
                $updateData = ['status' => $status];
                
                if ($content !== null) {
                    $updateData['content'] = $content;
                }
                
                if ($result !== null) {
                    $updateData['result'] = $result;
                }
                
                // Update duration if completing
                if ($status === 'completed' || $status === 'failed') {
                    $stepData = \Plugitify_DB::table('steps')
                        ->where('id', $step_id)
                        ->first();
                    
                    if ($stepData) {
                        $created_at_value = is_object($stepData) ? ($stepData->created_at ?? null) : ($stepData['created_at'] ?? null);
                        $createdAt = $created_at_value ? strtotime($created_at_value) : time();
                        $duration = time() - $createdAt;
                        $updateData['duration'] = $duration;
                    }
                }
                
                $updated = \Plugitify_DB::table('steps')
                    ->where('id', $step_id)
                    ->update($updateData);
                
                if ($updated) {
                    return "Stage #{$step_id} status updated to: {$status}";
                } else {
                    return "Failed to update stage #{$step_id}";
                }
            }),

            Tool::make(
                'complete_task',
                'Mark a task as completed. Use this when all work for a task is finished.',
            )->addProperty(
                new ToolProperty(
                    name: 'task_id',
                    type: 'integer',
                    description: 'The ID of the task to complete. If not provided, will complete the most recent task for this chat.',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'summary',
                    type: 'string',
                    description: 'Summary of what was accomplished',
                    required: false
                )
            )->setCallable(function (?int $task_id = null, ?string $summary = null) {
                $chatId = self::$currentChatId;
                $userId = self::$currentUserId ?: get_current_user_id();
                
                if (!\Plugitify_DB::tableExists('tasks')) {
                    return "Tasks table does not exist.";
                }
                
                if (!$task_id) {
                    // Get most recent task for this chat
                    $query = \Plugitify_DB::table('tasks')->where('user_id', $userId);
                    if ($chatId) {
                        $query->where('chat_history_id', $chatId);
                    }
                    $task = $query->orderBy('created_at', 'DESC')->first();
                    
                    if (!$task) {
                        return "No task found to complete.";
                    }
                    $task_id = is_object($task) ? $task->id : $task['id'];
                }
                
                $updateData = [
                    'status' => 'completed',
                    'progress' => 100
                ];
                
                if ($summary !== null) {
                    $updateData['result'] = $summary;
                }
                
                $updated = \Plugitify_DB::table('tasks')
                    ->where('id', $task_id)
                    ->where('user_id', $userId)
                    ->update($updateData);
                
                if ($updated) {
                    return "Task #{$task_id} marked as completed.";
                } else {
                    return "Failed to complete task #{$task_id}";
                }
            }),

            Tool::make(
                'update_chat_title',
                'Update the title of the current chat. Use this when the user requests to change the chat title or when you want to set a descriptive title based on the conversation.',
            )->addProperty(
                new ToolProperty(
                    name: 'title',
                    type: 'string',
                    description: 'The new title for the chat. Should be descriptive and concise (max 255 characters).',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'chat_id',
                    type: 'integer',
                    description: 'The ID of the chat to update. If not provided, will update the current chat.',
                    required: false
                )
            )->setCallable(function (string $title, ?int $chat_id = null) {
                $chatId = $chat_id ?: self::$currentChatId;
                $userId = self::$currentUserId ?: get_current_user_id();
                
                if (!$chatId) {
                    return "No chat ID provided. Please specify a chat_id or ensure you're in an active chat.";
                }
                
                if (!\Plugitify_DB::tableExists('chat_history')) {
                    return "Chat history table does not exist. Please run migrations first.";
                }
                
                // Sanitize title (max 255 characters as per database schema)
                $title = sanitize_text_field($title);
                if (strlen($title) > 255) {
                    $title = substr($title, 0, 252) . '...';
                }
                
                if (empty($title)) {
                    return "Title cannot be empty.";
                }
                
                $updated = \Plugitify_DB::table('chat_history')
                    ->where('id', $chatId)
                    ->where('user_id', $userId)
                    ->update(['title' => $title]);
                
                if ($updated) {
                    return "Chat title updated successfully to: {$title}";
                } else {
                    return "Failed to update chat title. The chat may not exist or you may not have permission to update it.";
                }
            }),

            Tool::make(
                'get_tasks',
                'Get a list of tasks. Use this to retrieve all tasks or filter tasks by chat, status, or user. This helps the agent understand what tasks exist and their current status.',
            )->addProperty(
                new ToolProperty(
                    name: 'chat_id',
                    type: 'integer',
                    description: 'Filter tasks by chat ID. If not provided, returns all tasks for the current user.',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'status',
                    type: 'string',
                    description: 'Filter tasks by status: "all" (default), "pending", "in_progress", "completed", "failed", or "cancelled".',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'task_id',
                    type: 'integer',
                    description: 'Get a specific task by ID. If provided, only that task will be returned.',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'include_steps',
                    type: 'boolean',
                    description: 'Whether to include steps for each task (default: false). If true, includes all steps for each task.',
                    required: false
                )
            )->setCallable(function (?int $chat_id = null, ?string $status = 'all', ?int $task_id = null, ?bool $include_steps = false) {
                $chatId = $chat_id ?: self::$currentChatId;
                $userId = self::$currentUserId ?: get_current_user_id();
                
                if (!\Plugitify_DB::tableExists('tasks')) {
                    return "Tasks table does not exist. Please run migrations first.";
                }
                
                $query = \Plugitify_DB::table('tasks')->where('user_id', $userId);
                
                // Filter by task_id if provided
                if ($task_id) {
                    $query->where('id', $task_id);
                }
                
                // Filter by chat_id if provided
                if ($chatId) {
                    $query->where('chat_history_id', $chatId);
                }
                
                // Filter by status
                if ($status !== null && $status !== 'all') {
                    $query->where('status', $status);
                }
                
                // Order by creation date (newest first)
                $query->orderBy('created_at', 'DESC');
                
                $tasks = $query->get();
                
                if (!$tasks || (is_array($tasks) && count($tasks) === 0)) {
                    return "No tasks found matching the criteria.";
                }
                
                // Convert to array if single object
                if (!is_array($tasks)) {
                    $tasks = [$tasks];
                }
                
                $output = "Found " . count($tasks) . " task(s):\n\n";
                
                foreach ($tasks as $task) {
                    $task_id = is_object($task) ? $task->id : $task['id'];
                    $task_name = is_object($task) ? $task->task_name : $task['task_name'];
                    $task_type = is_object($task) ? $task->task_type : $task['task_type'];
                    $description = is_object($task) ? $task->description : $task['description'];
                    $status_val = is_object($task) ? $task->status : $task['status'];
                    $progress = is_object($task) ? $task->progress : $task['progress'];
                    $created_at = is_object($task) ? $task->created_at : $task['created_at'];
                    $updated_at = is_object($task) ? $task->updated_at : $task['updated_at'];
                    $result = is_object($task) ? $task->result : $task['result'];
                    
                    $output .= "📋 Task #{$task_id}: {$task_name}\n";
                    $output .= "   Type: {$task_type}\n";
                    $output .= "   Status: {$status_val}\n";
                    $output .= "   Progress: {$progress}%\n";
                    
                    if ($description) {
                        $output .= "   Description: {$description}\n";
                    }
                    
                    if ($result) {
                        $output .= "   Result: {$result}\n";
                    }
                    
                    $output .= "   Created: {$created_at}\n";
                    $output .= "   Updated: {$updated_at}\n";
                    
                    // Include steps if requested
                    if ($include_steps) {
                        if (\Plugitify_DB::tableExists('steps')) {
                            $steps = \Plugitify_DB::table('steps')
                                ->where('task_id', $task_id)
                                ->orderBy('order', 'ASC')
                                ->orderBy('created_at', 'ASC')
                                ->get();
                            
                            if ($steps && (is_array($steps) ? count($steps) > 0 : true)) {
                                if (!is_array($steps)) {
                                    $steps = [$steps];
                                }
                                $output .= "   Steps (" . count($steps) . "):\n";
                                foreach ($steps as $step) {
                                    $step_id = is_object($step) ? $step->id : $step['id'];
                                    $step_name = is_object($step) ? $step->step_name : $step['step_name'];
                                    $step_status = is_object($step) ? $step->status : $step['status'];
                                    $step_order = is_object($step) ? $step->order : $step['order'];
                                    
                                    $output .= "      - Step #{$step_id} [Order: {$step_order}]: {$step_name} (Status: {$step_status})\n";
                                }
                            } else {
                                $output .= "   Steps: No steps found for this task.\n";
                            }
                        }
                    }
                    
                    $output .= "\n";
                }
                
                return $output;
            }),

            Tool::make(
                'get_steps',
                'Get a list of steps/stages for tasks. Use this to retrieve all steps or filter steps by task, status, or order. This helps the agent understand what steps exist and their current status.',
            )->addProperty(
                new ToolProperty(
                    name: 'task_id',
                    type: 'integer',
                    description: 'Filter steps by task ID. If not provided, returns steps for the most recent task in the current chat.',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'status',
                    type: 'string',
                    description: 'Filter steps by status: "all" (default), "pending", "in_progress", "completed", "failed", or "skipped".',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'step_id',
                    type: 'integer',
                    description: 'Get a specific step by ID. If provided, only that step will be returned.',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'order_by',
                    type: 'string',
                    description: 'Order steps by: "order" (default, by order field then created_at), "created" (by created_at), or "updated" (by updated_at).',
                    required: false
                )
            )->setCallable(function (?int $task_id = null, ?string $status = 'all', ?int $step_id = null, ?string $order_by = 'order') {
                $chatId = self::$currentChatId;
                $userId = self::$currentUserId ?: get_current_user_id();
                
                if (!\Plugitify_DB::tableExists('steps')) {
                    return "Steps table does not exist. Please run migrations first.";
                }
                
                $query = \Plugitify_DB::table('steps');
                
                // Filter by step_id if provided
                if ($step_id) {
                    $query->where('id', $step_id);
                } else {
                    // If task_id not provided, get most recent task for current chat
                    if (!$task_id) {
                        $taskQuery = \Plugitify_DB::table('tasks')->where('user_id', $userId);
                        if ($chatId) {
                            $taskQuery->where('chat_history_id', $chatId);
                        }
                        $task = $taskQuery->orderBy('created_at', 'DESC')->first();
                        
                        if (!$task) {
                            return "No task found. Please create a task first or specify a task_id.";
                        }
                        $task_id = is_object($task) ? $task->id : $task['id'];
                    }
                    
                    // Verify task belongs to user
                    $taskExists = \Plugitify_DB::table('tasks')
                        ->where('id', $task_id)
                        ->where('user_id', $userId)
                        ->first();
                    
                    if (!$taskExists) {
                        return "Task #{$task_id} not found or you don't have permission to view it.";
                    }
                    
                    $query->where('task_id', $task_id);
                }
                
                // Filter by status
                if ($status !== null && $status !== 'all') {
                    $query->where('status', $status);
                }
                
                // Order by - use raw query for 'order' column since it's a MySQL reserved word
                if ($order_by === 'order' || $order_by === null) {
                    // Use raw query for 'order' column since it's a MySQL reserved word
                    global $wpdb;
                    $steps_table = \Plugitify_DB::getFullTableName('steps');
                    $where_clauses = [];
                    $where_values = [];
                    
                    // Build where clauses manually
                    if ($step_id) {
                        $where_clauses[] = "id = %d";
                        $where_values[] = $step_id;
                    } else {
                        if ($task_id) {
                            $where_clauses[] = "task_id = %d";
                            $where_values[] = $task_id;
                        }
                        if ($status !== null && $status !== 'all') {
                            $where_clauses[] = "status = %s";
                            $where_values[] = $status;
                        }
                    }
                    
                    $query_str = "SELECT * FROM {$steps_table}";
                    if (!empty($where_clauses)) {
                        $query_str .= " WHERE " . implode(" AND ", $where_clauses);
                    }
                    $query_str .= " ORDER BY `order` ASC, created_at ASC";
                    
                    if (!empty($where_values)) {
                        $query_str = $wpdb->prepare($query_str, $where_values);
                    }
                    
                    $steps = $wpdb->get_results($query_str);
                } elseif ($order_by === 'created') {
                    $steps = $query->orderBy('created_at', 'ASC')->get();
                } elseif ($order_by === 'updated') {
                    $steps = $query->orderBy('updated_at', 'DESC')->get();
                } else {
                    $steps = $query->get();
                }
                
                if (!$steps || (is_array($steps) && count($steps) === 0)) {
                    if ($step_id) {
                        return "Step #{$step_id} not found.";
                    }
                    if ($task_id) {
                        return "No steps found for task #{$task_id}.";
                    }
                    return "No steps found matching the criteria.";
                }
                
                // Convert to array if single object
                if (!is_array($steps)) {
                    $steps = [$steps];
                }
                
                // Get task info for context
                $taskInfo = null;
                if ($task_id && !$step_id) {
                    $task = \Plugitify_DB::table('tasks')->where('id', $task_id)->first();
                    if ($task) {
                        $taskInfo = is_object($task) ? $task->task_name : $task['task_name'];
                    }
                } elseif ($step_id) {
                    $step = is_array($steps) ? $steps[0] : $steps;
                    $step_task_id = is_object($step) ? $step->task_id : $step['task_id'];
                    $task = \Plugitify_DB::table('tasks')->where('id', $step_task_id)->first();
                    if ($task) {
                        $taskInfo = is_object($task) ? $task->task_name : $task['task_name'];
                    }
                }
                
                $output = "Found " . count($steps) . " step(s)";
                if ($taskInfo) {
                    $output .= " for task: {$taskInfo}";
                }
                $output .= ":\n\n";
                
                foreach ($steps as $step) {
                    $step_id = is_object($step) ? $step->id : $step['id'];
                    $step_task_id = is_object($step) ? $step->task_id : $step['task_id'];
                    $step_name = is_object($step) ? $step->step_name : $step['step_name'];
                    $step_type = is_object($step) ? $step->step_type : $step['step_type'];
                    $step_status = is_object($step) ? $step->status : $step['status'];
                    $step_order = is_object($step) ? $step->order : $step['order'];
                    $content = is_object($step) ? $step->content : $step['content'];
                    $result = is_object($step) ? $step->result : $step['result'];
                    $duration = is_object($step) ? $step->duration : $step['duration'];
                    $created_at = is_object($step) ? $step->created_at : $step['created_at'];
                    $updated_at = is_object($step) ? $step->updated_at : $step['updated_at'];
                    
                    $output .= "📌 Step #{$step_id} [Order: {$step_order}]\n";
                    $output .= "   Name: {$step_name}\n";
                    $output .= "   Type: {$step_type}\n";
                    $output .= "   Status: {$step_status}\n";
                    $output .= "   Task ID: {$step_task_id}\n";
                    
                    if ($content) {
                        $output .= "   Content: {$content}\n";
                    }
                    
                    if ($result) {
                        $output .= "   Result: {$result}\n";
                    }
                    
                    if ($duration !== null && $duration > 0) {
                        $output .= "   Duration: {$duration} seconds\n";
                    }
                    
                    $output .= "   Created: {$created_at}\n";
                    $output .= "   Updated: {$updated_at}\n";
                    $output .= "\n";
                }
                
                return $output;
            }),
        ];
    }
}

