<?php

namespace Plugitify\Classes;

class Plugitify_Tools_API {
    private static $currentChatId = null;
    private static $currentUserId = null;
    private static $currentMessageId = null;

    public function __construct() {
        // Register AJAX endpoints for all tools
        add_action('wp_ajax_plugitify_tool_create_directory', array($this, 'handle_create_directory'));
        add_action('wp_ajax_plugitify_tool_create_file', array($this, 'handle_create_file'));
        add_action('wp_ajax_plugitify_tool_delete_file', array($this, 'handle_delete_file'));
        add_action('wp_ajax_plugitify_tool_delete_directory', array($this, 'handle_delete_directory'));
        add_action('wp_ajax_plugitify_tool_read_file', array($this, 'handle_read_file'));
        add_action('wp_ajax_plugitify_tool_edit_file_line', array($this, 'handle_edit_file_line'));
        add_action('wp_ajax_plugitify_tool_list_plugins', array($this, 'handle_list_plugins'));
        add_action('wp_ajax_plugitify_tool_deactivate_plugin', array($this, 'handle_deactivate_plugin'));
        add_action('wp_ajax_plugitify_tool_extract_plugin_structure', array($this, 'handle_extract_plugin_structure'));
        add_action('wp_ajax_plugitify_tool_toggle_wp_debug', array($this, 'handle_toggle_wp_debug'));
        add_action('wp_ajax_plugitify_tool_read_debug_log', array($this, 'handle_read_debug_log'));
        add_action('wp_ajax_plugitify_tool_check_wp_debug_status', array($this, 'handle_check_wp_debug_status'));
        add_action('wp_ajax_plugitify_tool_search_replace_in_file', array($this, 'handle_search_replace_in_file'));
        add_action('wp_ajax_plugitify_tool_update_chat_title', array($this, 'handle_update_chat_title'));
        add_action('wp_ajax_plugitify_tool_get_chat_tasks', array($this, 'handle_get_chat_tasks'));
    }

    /**
     * Set context for task tracking
     */
    public static function setContext(?int $chatId = null, ?int $userId = null, ?int $messageId = null): void {
        self::$currentChatId = $chatId;
        self::$currentUserId = $userId ?: get_current_user_id();
        self::$currentMessageId = $messageId;
    }

    /**
     * Create task and step after tool execution completes
     * Creates a task with success or error status based on execution result
     */
    private function createTaskAfterExecution(string $toolName, array $details, bool $success, string $resultOrError, float $duration = 0): ?array {
        try {
            $chatId = self::$currentChatId;
            $userId = self::$currentUserId ?: get_current_user_id();
            $messageId = self::$currentMessageId;

            if (!class_exists('\Plugitify_DB')) {
                return null;
            }

            if (!\Plugitify_DB::tableExists('tasks') || !\Plugitify_DB::tableExists('steps')) {
                return null;
            }

            // If message_id is not set in context, try to find the latest pending bot message for this chat
            if (!$messageId && $chatId) {
                $latestMessage = \Plugitify_DB::table('messages')
                    ->where('chat_history_id', $chatId)
                    ->where('role', 'assistant')
                    ->where('status', 'pending')
                    ->orderBy('created_at', 'DESC')
                    ->first();
                
                if ($latestMessage) {
                    $messageId = is_object($latestMessage) ? ($latestMessage->id ?? null) : ($latestMessage['id'] ?? null);
                }
            }

            // Build detailed task/step name
            $baseName = ucfirst(str_replace('_', ' ', $toolName));
            $taskName = $this->buildDetailedTaskName($toolName, $details, $baseName);
            $description = $this->buildDetailedDescription($toolName, $details);

            // Determine status and fields based on success/error
            $taskStatus = $success ? 'completed' : 'failed';
            $stepStatus = $success ? 'completed' : 'failed';
            $progress = $success ? 100 : 0;
            
            // Get current timestamp
            $currentTime = current_time('mysql');
            
            // Prepare task data
            $taskData = [
                'chat_history_id' => $chatId,
                'message_id' => $messageId,
                'user_id' => $userId,
                'task_name' => $taskName,
                'task_type' => 'tool_execution',
                'description' => $description,
                'status' => $taskStatus,
                'progress' => $progress,
                'created_at' => $currentTime,
                'updated_at' => $currentTime
            ];

            // Add result or error message
            if ($success) {
                $taskData['result'] = $resultOrError;
            } else {
                $taskData['error_message'] = $resultOrError;
                $taskData['result'] = null;
            }

            $taskId = \Plugitify_DB::table('tasks')->insert($taskData);

            if (!$taskId) {
                return null;
            }

            // Create a new step for this tool call
            global $wpdb;
            $steps_table = \Plugitify_DB::getFullTableName('steps');
            $steps_table_safe = esc_sql($steps_table);
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            // Table name is escaped with esc_sql(), query is prepared with $wpdb->prepare(), direct query needed for custom table
            $maxOrder = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(`order`) FROM {$steps_table_safe} WHERE task_id = %d",
                $taskId
            ));
            // phpcs:enable
            $order = ($maxOrder !== null && $maxOrder !== false) ? intval($maxOrder) + 1 : 1;

            $stepData = [
                'task_id' => $taskId,
                'step_name' => $taskName,
                'step_type' => 'tool_call',
                'order' => $order,
                'status' => $stepStatus,
                'duration' => intval($duration)
            ];

            // Add result or error message to step
            if ($success) {
                $stepData['result'] = $resultOrError;
            } else {
                $stepData['error_message'] = $resultOrError;
                $stepData['result'] = null;
            }

            $stepId = \Plugitify_DB::table('steps')->insert($stepData);

            if (!$stepId) {
                return null;
            }

            return [
                'task_id' => $taskId,
                'step_id' => $stepId
            ];
        } catch (\Exception $e) {
            // Debug: error_log('Plugitify: Failed to create task after execution: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build detailed task name
     */
    private function buildDetailedTaskName(string $toolName, array $details, string $baseName): string {
        $parts = [$baseName];
        
        switch ($toolName) {
            case 'create_directory':
                if (isset($details['path'])) {
                    $parts[] = "Directory: {$details['path']}";
                }
                break;
            case 'create_file':
                if (isset($details['file_path'])) {
                    $fileName = basename($details['file_path']);
                    $parts[] = "File: {$fileName}";
                }
                break;
            case 'delete_file':
            case 'read_file':
                if (isset($details['file_path'])) {
                    $fileName = basename($details['file_path']);
                    $parts[] = "File: {$fileName}";
                }
                break;
            case 'delete_directory':
                if (isset($details['path'])) {
                    $parts[] = "Directory: {$details['path']}";
                }
                break;
            case 'edit_file_line':
                if (isset($details['file_path'])) {
                    $fileName = basename($details['file_path']);
                    $parts[] = "File: {$fileName}";
                    if (isset($details['line_number'])) {
                        $parts[] = "Line: {$details['line_number']}";
                    }
                }
                break;
            case 'search_replace_in_file':
                if (isset($details['file_path'])) {
                    $fileName = basename($details['file_path']);
                    $parts[] = "File: {$fileName}";
                    if (isset($details['replacements_count'])) {
                        $parts[] = "Replacements: {$details['replacements_count']}";
                    }
                }
                break;
            case 'extract_plugin_structure':
                if (isset($details['plugin_name'])) {
                    $parts[] = "Plugin: {$details['plugin_name']}";
                }
                break;
            case 'list_plugins':
                if (isset($details['status'])) {
                    $parts[] = "Status: {$details['status']}";
                }
                break;
            case 'deactivate_plugin':
                if (isset($details['plugin_file'])) {
                    $parts[] = "Plugin: " . basename($details['plugin_file'], '.php');
                }
                break;
            case 'toggle_wp_debug':
                if (isset($details['enable'])) {
                    $parts[] = $details['enable'] ? 'Enable' : 'Disable';
                }
                break;
            case 'read_debug_log':
                if (isset($details['lines'])) {
                    $parts[] = "Lines: {$details['lines']}";
                }
                break;
            case 'check_wp_debug_status':
                // No additional details needed
                break;
        }
        
        return implode(' | ', $parts);
    }

    /**
     * Build detailed description
     */
    private function buildDetailedDescription(string $toolName, array $details): string {
        $descriptions = [
            'create_directory' => 'Creating directory',
            'create_file' => 'Creating file',
            'delete_file' => 'Deleting file',
            'delete_directory' => 'Deleting directory',
            'read_file' => 'Reading file',
            'edit_file_line' => 'Editing file lines',
            'extract_plugin_structure' => 'Extracting plugin structure',
            'list_plugins' => 'Listing WordPress plugins',
            'deactivate_plugin' => 'Deactivating WordPress plugin',
            'toggle_wp_debug' => 'Toggling WP_DEBUG mode',
            'read_debug_log' => 'Reading WordPress debug log',
            'check_wp_debug_status' => 'Checking WP_DEBUG status',
            'search_replace_in_file' => 'Searching and replacing text in file',
        ];
        
        $baseDesc = $descriptions[$toolName] ?? "Executing tool: {$toolName}";
        $detailsText = [];
        
        foreach ($details as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $keyLabel = ucfirst(str_replace('_', ' ', $key));
                $detailsText[] = "{$keyLabel}: {$value}";
            }
        }
        
        if (!empty($detailsText)) {
            return $baseDesc . ' | ' . implode(', ', $detailsText);
        }
        
        return $baseDesc;
    }

    /**
     * Helper method to intelligently prepare result for JSON response
     * If result is a JSON string, parse it to array/object for better performance
     * This reduces parsing overhead in frontend for large JSON responses
     * 
     * @param mixed $result The result to prepare (string, array, object, etc.)
     * @param int $minSize Minimum size in bytes to attempt JSON parsing (default: 1000 for 1KB)
     * @return mixed Parsed result (array/object if JSON string, otherwise original)
     */
    private function prepareResultForResponse($result, $minSize = 1000) {
        // If result is a string, check if it's JSON
        if (is_string($result) && !empty($result)) {
            $strLen = strlen($result);
            
            // Only attempt parsing for large strings (to avoid overhead on small strings)
            if ($strLen >= $minSize) {
                // Check if string looks like JSON (starts with { or [)
                $trimmed = trim($result);
                if (!empty($trimmed) && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                    // For large JSON strings, parse in backend (PHP is faster than JS for large JSON)
                    $decoded = json_decode($result, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        // Successfully parsed - return as array/object
                        // WordPress will JSON encode this, but frontend receives already-parsed structure
                        // This is more efficient than parsing huge JSON strings in browser
                        return $decoded;
                    }
                }
            }
        }
        
        // Return original result if not JSON, too small, or parsing failed
        return $result;
    }

    /**
     * Wrapper for wp_send_json_success that automatically optimizes large JSON results
     * Use this instead of wp_send_json_success when returning tool results
     * 
     * @param mixed $result The result to send (will be auto-optimized if large JSON string)
     * @param string $key The key in response array (default: 'result')
     */
    private function sendToolResult($result, $key = 'result') {
        $preparedResult = $this->prepareResultForResponse($result);
        wp_send_json_success(array($key => $preparedResult));
    }

    /**
     * Common validation for all tool endpoints
     */
    private function validateRequest(): array {
        // Verify nonce
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified, not sanitized
        $nonce = isset($_POST['nonce']) ? wp_unslash($_POST['nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'plugitify_chat_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            exit;
        }

        // Check user permissions
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            exit;
        }

        // Set context
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        $user_id = get_current_user_id();
        $message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : null;
        self::setContext($chat_id, $user_id, $message_id);

        return ['chat_id' => $chat_id, 'user_id' => $user_id, 'message_id' => $message_id];
    }

    /**
     * Get plugins directory path
     */
    private function getPluginsDir(): string {
        return defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('ABSPATH') ? ABSPATH . 'wp-content/plugins/' : __DIR__ . '/../../../../plugins/');
    }

    /**
     * Check if a path contains plugitify or Pluginity (protected)
     * Returns true if the path is protected and should be blocked
     * This checks the original path sent by the model, not the fullPath
     */
    private function isProtectedPath(string $path): bool {
        // Normalize the path for comparison
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        
        // Check if the path contains "plugitify" or "Pluginity" (case-insensitive)
        if (stripos($normalizedPath, 'plugitify') !== false || 
            stripos($normalizedPath, 'Pluginity') !== false) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if file/directory belongs to a plugin and deactivate it if active
     * This method silently handles deactivation - continues work even if deactivation fails
     * 
     * @param string $fullPath Full path to file or directory
     * @return void
     */
    private function deactivatePluginIfNeeded(string $fullPath): void {
        $pluginsDir = $this->getPluginsDir();
        $pluginsDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $pluginsDir);
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        // Check if path is within plugins directory
        if (strpos($fullPath, $pluginsDir) !== 0) {
            return;
        }
        
        // Get relative path from plugins directory
        $relativePath = substr($fullPath, strlen($pluginsDir));
        $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);
        
        // Extract plugin directory name (first segment)
        $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);
        if (empty($pathParts[0])) {
            return;
        }
        
        $pluginDir = $pathParts[0];
        $pluginPath = $pluginsDir . DIRECTORY_SEPARATOR . $pluginDir;
        
        // Check if it's a directory (plugin folder) - for existing directories
        // For files that don't exist yet, check parent directories
        $isPluginDir = is_dir($pluginPath);
        if (!$isPluginDir) {
            // For file paths, check if any parent directory is a plugin directory
            $currentPath = dirname($fullPath);
            while ($currentPath !== $pluginsDir && $currentPath !== dirname($currentPath)) {
                $currentRelative = substr($currentPath, strlen($pluginsDir));
                $currentRelative = ltrim($currentRelative, DIRECTORY_SEPARATOR);
                $currentParts = explode(DIRECTORY_SEPARATOR, $currentRelative);
                if (!empty($currentParts[0])) {
                    $potentialPluginDir = $currentParts[0];
                    $potentialPluginPath = $pluginsDir . DIRECTORY_SEPARATOR . $potentialPluginDir;
                    if (is_dir($potentialPluginPath)) {
                        $pluginDir = $potentialPluginDir;
                        $pluginPath = $potentialPluginPath;
                        $isPluginDir = true;
                        break;
                    }
                }
                $currentPath = dirname($currentPath);
            }
        }
        
        if (!$isPluginDir) {
            return;
        }
        
        // Try to find the main plugin file
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $allPlugins = get_plugins();
        $pluginFile = null;
        
        // Look for plugin file that matches this directory
        foreach ($allPlugins as $file => $pluginData) {
            // Plugin file format is: plugin-dir/plugin-file.php
            $pluginFileDir = dirname($file);
            
            // Handle root-level plugins (plugin-file.php)
            if ($pluginFileDir === '.' || $pluginFileDir === '') {
                $pluginFileDir = basename($file, '.php');
            }
            
            if ($pluginFileDir === $pluginDir) {
                $pluginFile = $file;
                break;
            }
        }
        
        if (!$pluginFile) {
            return;
        }
        
        // Prevent deactivating Plugitify itself
        if (stripos($pluginFile, 'plugitify') !== false || stripos($pluginFile, 'Pluginity') !== false) {
            return;
        }
        
        // Check if plugin is active
        $activePlugins = get_option('active_plugins', []);
        $isNetworkActive = false;
        if (is_multisite()) {
            $networkActivePlugins = get_site_option('active_sitewide_plugins', []);
            $isNetworkActive = isset($networkActivePlugins[$pluginFile]);
        }
        
        $isActive = in_array($pluginFile, $activePlugins);
        
        // If plugin is not active, nothing to do
        if (!$isActive && !$isNetworkActive) {
            return;
        }
        
        // Deactivate the plugin - silently continue even if it fails
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $result = deactivate_plugins($pluginFile);
        
        // Log error if deactivation failed, but continue anyway
        if (is_wp_error($result)) {
            error_log('Plugitify: Failed to deactivate plugin ' . $pluginFile . ' before file modification: ' . $result->get_error_message());
        }
    }

    /**
     * Handle create_directory tool
     */
    public function handle_create_directory() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $path = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
        
        $details = ['path' => $path];
        
        if (empty($path)) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('create_directory', $details, false, 'Path is required', $duration);
            wp_send_json_error(array('message' => 'Path is required'));
            return;
        }

        $pluginsDir = $this->getPluginsDir();
        $path = ltrim($path, '/\\');
        
        if (strpos($path, $pluginsDir) !== 0) {
            $fullPath = rtrim($pluginsDir, '/\\') . '/' . $path;
        } else {
            $fullPath = $path;
        }
        
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($path)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'ERROR: Cannot create directories in the Pluginity/plugitify plugin directory. This is a protected system plugin.';
            $this->createTaskAfterExecution('create_directory', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Deactivate plugin if needed before making changes
        $this->deactivatePluginIfNeeded($fullPath);
        
        $duration = microtime(true) - $startTime;
        if (!is_dir($fullPath)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem access required for plugin development tool
            if (mkdir($fullPath, 0755, true)) {
                $result = "Directory created successfully: {$fullPath}";
                $this->createTaskAfterExecution('create_directory', $details, true, $result, $duration);
                wp_send_json_success(array('result' => $result));
            } else {
                $errorMsg = "Failed to create directory: {$fullPath}";
                $this->createTaskAfterExecution('create_directory', $details, false, $errorMsg, $duration);
                wp_send_json_error(array('message' => $errorMsg));
            }
        } else {
            $result = "Directory already exists: {$fullPath}";
            $this->createTaskAfterExecution('create_directory', $details, true, $result, $duration);
            wp_send_json_success(array('result' => $result));
        }
    }

    /**
     * Handle create_file tool
     */
    public function handle_create_file() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in validateRequest(), content is file content that will be written to file
        $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
        
        // Decode base64 content if it was encoded
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $content_encoded = isset($_POST['content_encoded']) ? sanitize_text_field(wp_unslash($_POST['content_encoded'])) : '';
        if ($content_encoded === '1') {
            // Decode base64 (UTF-8 safe - JavaScript used btoa(unescape(encodeURIComponent(...))))
            $decoded = base64_decode($content, true);
            if ($decoded === false) {
                $duration = microtime(true) - $startTime;
                $this->createTaskAfterExecution('create_file', ['file_path' => $file_path], false, 'Failed to decode file content', $duration);
                wp_send_json_error(array('message' => 'Failed to decode file content'));
                return;
            }
            $content = $decoded;
        } else {
            // Handle magic quotes if enabled (for backward compatibility)
            if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()) {
                $content = stripslashes($content);
            }
            $content = wp_unslash($content);
        }
        
        $details = [
            'file_path' => $file_path,
            'file_size' => strlen($content)
        ];
        
        if (empty($file_path)) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('create_file', $details, false, 'File path is required', $duration);
            wp_send_json_error(array('message' => 'File path is required'));
            return;
        }
        
        if (empty($content) && $content !== '0') {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('create_file', $details, false, 'Content is required', $duration);
            wp_send_json_error(array('message' => 'Content is required'));
            return;
        }

        $pluginsDir = $this->getPluginsDir();
        $file_path = ltrim($file_path, '/\\');
        
        if (strpos($file_path, $pluginsDir) !== 0) {
            $fullPath = rtrim($pluginsDir, '/\\') . '/' . $file_path;
        } else {
            $fullPath = $file_path;
        }
        
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($file_path)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'ERROR: Cannot create files in the Pluginity/plugitify plugin directory. This is a protected system plugin.';
            $this->createTaskAfterExecution('create_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Deactivate plugin if needed before making changes
        $this->deactivatePluginIfNeeded($fullPath);
        
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem access required for plugin development tool
            mkdir($dir, 0755, true);
        }
        
        $duration = microtime(true) - $startTime;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem access required for plugin development tool
        if (file_put_contents($fullPath, $content) !== false) {
            $result = "File created successfully: {$fullPath}";
            $this->createTaskAfterExecution('create_file', $details, true, $result, $duration);
            wp_send_json_success(array('result' => $result));
        } else {
            $errorMsg = "Failed to create file: {$fullPath}";
            $this->createTaskAfterExecution('create_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
        }
    }

    /**
     * Handle delete_file tool
     */
    public function handle_delete_file() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        
        $details = ['file_path' => $file_path];
        
        if (empty($file_path)) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('delete_file', $details, false, 'File path is required', $duration);
            wp_send_json_error(array('message' => 'File path is required'));
            return;
        }

        $pluginsDir = $this->getPluginsDir();
        $file_path = ltrim($file_path, '/\\');
        
        if (strpos($file_path, $pluginsDir) !== 0) {
            $fullPath = rtrim($pluginsDir, '/\\') . '/' . $file_path;
        } else {
            $fullPath = $file_path;
        }
        
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($file_path)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'ERROR: Cannot delete files in the Pluginity/plugitify plugin directory. This is a protected system plugin.';
            $this->createTaskAfterExecution('delete_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Deactivate plugin if needed before making changes
        $this->deactivatePluginIfNeeded($fullPath);
        
        if (!file_exists($fullPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "File does not exist: {$fullPath}";
            $this->createTaskAfterExecution('delete_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        if (!is_file($fullPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Path is not a file: {$fullPath}. Use delete_directory to remove directories.";
            $this->createTaskAfterExecution('delete_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        $duration = microtime(true) - $startTime;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct filesystem access required for plugin development tool
        if (unlink($fullPath)) {
            $result = "File deleted successfully: {$fullPath}";
            $this->createTaskAfterExecution('delete_file', $details, true, $result, $duration);
            wp_send_json_success(array('result' => $result));
        } else {
            $errorMsg = "Failed to delete file: {$fullPath}";
            $this->createTaskAfterExecution('delete_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
        }
    }

    /**
     * Handle delete_directory tool
     */
    public function handle_delete_directory() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $path = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
        
        $details = ['path' => $path];
        
        if (empty($path)) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('delete_directory', $details, false, 'Path is required', $duration);
            wp_send_json_error(array('message' => 'Path is required'));
            return;
        }

        $pluginsDir = $this->getPluginsDir();
        $path = ltrim($path, '/\\');
        
        if (strpos($path, $pluginsDir) !== 0) {
            $fullPath = rtrim($pluginsDir, '/\\') . '/' . $path;
        } else {
            $fullPath = $path;
        }
        
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($path)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'ERROR: Cannot delete directories in the Pluginity/plugitify plugin directory. This is a protected system plugin.';
            $this->createTaskAfterExecution('delete_directory', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Deactivate plugin if needed before making changes
        $this->deactivatePluginIfNeeded($fullPath);
        
        if (!is_dir($fullPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Directory does not exist: {$fullPath}";
            $this->createTaskAfterExecution('delete_directory', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Recursive directory deletion
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
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct filesystem access required for plugin development tool
                    unlink($filePath);
                }
            }
            
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct filesystem access required for plugin development tool
            return rmdir($dir);
        };
        
        $duration = microtime(true) - $startTime;
        if ($deleteRecursive($fullPath)) {
            $result = "Directory deleted successfully: {$fullPath}";
            $this->createTaskAfterExecution('delete_directory', $details, true, $result, $duration);
            wp_send_json_success(array('result' => $result));
        } else {
            $errorMsg = "Failed to delete directory: {$fullPath}";
            $this->createTaskAfterExecution('delete_directory', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
        }
    }

    /**
     * Handle read_file tool
     */
    public function handle_read_file() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        
        $details = ['file_path' => $file_path];
        
        if (empty($file_path)) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('read_file', $details, false, 'File path is required', $duration);
            wp_send_json_error(array('message' => 'File path is required'));
            return;
        }

        $pluginsDir = $this->getPluginsDir();
        $file_path = ltrim($file_path, '/\\');
        
        if (file_exists($file_path)) {
            $fullPath = $file_path;
        } elseif (defined('ABSPATH') && file_exists(ABSPATH . $file_path)) {
            $fullPath = ABSPATH . $file_path;
        } elseif (strpos($file_path, $pluginsDir) !== 0) {
            $fullPath = rtrim($pluginsDir, '/\\') . '/' . $file_path;
        } else {
            $fullPath = $file_path;
        }
        
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($file_path)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'ERROR: Cannot read files in the Pluginity/plugitify plugin directory. This is a protected system plugin.';
            $this->createTaskAfterExecution('read_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        if (!file_exists($fullPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "File not found: {$fullPath}";
            $this->createTaskAfterExecution('read_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        if (!is_file($fullPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Path is not a file: {$fullPath}";
            $this->createTaskAfterExecution('read_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        $content = file_get_contents($fullPath);
        $duration = microtime(true) - $startTime;
        if ($content === false) {
            $errorMsg = "Failed to read file: {$fullPath}";
            $this->createTaskAfterExecution('read_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        $result = "File content ({$fullPath}):\n\n" . $content;
        $this->createTaskAfterExecution('read_file', $details, true, $result, $duration);
        wp_send_json_success(array('result' => $result));
    }

    /**
     * Handle edit_file_line tool
     */
    public function handle_edit_file_line() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $line_number = isset($_POST['line_number']) ? intval($_POST['line_number']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in validateRequest(), content is file content that will be written to file
        $new_content = isset($_POST['new_content']) ? wp_unslash($_POST['new_content']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $line_count = isset($_POST['line_count']) ? intval($_POST['line_count']) : 1;
        
        // Decode base64 content if it was encoded
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $new_content_encoded = isset($_POST['new_content_encoded']) ? sanitize_text_field(wp_unslash($_POST['new_content_encoded'])) : '';
        if ($new_content_encoded === '1') {
            // Decode base64 (UTF-8 safe - JavaScript used btoa(unescape(encodeURIComponent(...))))
            $decoded = base64_decode($new_content, true);
            if ($decoded === false) {
                $duration = microtime(true) - $startTime;
                $this->createTaskAfterExecution('edit_file_line', ['file_path' => $file_path], false, 'Failed to decode file content', $duration);
                wp_send_json_error(array('message' => 'Failed to decode file content'));
                return;
            }
            $new_content = $decoded;
        } else {
            // Handle magic quotes if enabled (for backward compatibility)
            if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()) {
                $new_content = stripslashes($new_content);
            }
            $new_content = wp_unslash($new_content);
        }
        
        $details = [
            'file_path' => $file_path,
            'line_number' => $line_number,
            'line_count' => $line_count
        ];
        
        if (empty($file_path) || $line_number < 1) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('edit_file_line', $details, false, 'File path and valid line number are required', $duration);
            wp_send_json_error(array('message' => 'File path and valid line number are required'));
            return;
        }

        $pluginsDir = $this->getPluginsDir();
        $file_path = ltrim($file_path, '/\\');
        
        if (file_exists($file_path)) {
            $fullPath = $file_path;
        } elseif (defined('ABSPATH') && file_exists(ABSPATH . $file_path)) {
            $fullPath = ABSPATH . $file_path;
        } elseif (strpos($file_path, $pluginsDir) !== 0) {
            $fullPath = rtrim($pluginsDir, '/\\') . '/' . $file_path;
        } else {
            $fullPath = $file_path;
        }
        
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($file_path)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'ERROR: Cannot edit files in the Pluginity/plugitify plugin directory. This is a protected system plugin.';
            $this->createTaskAfterExecution('edit_file_line', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Deactivate plugin if needed before making changes
        $this->deactivatePluginIfNeeded($fullPath);
        
        if (!file_exists($fullPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "File not found: {$fullPath}";
            $this->createTaskAfterExecution('edit_file_line', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        if (!is_file($fullPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Path is not a file: {$fullPath}";
            $this->createTaskAfterExecution('edit_file_line', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        $content = file_get_contents($fullPath);
        if ($content === false) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Failed to read file: {$fullPath}";
            $this->createTaskAfterExecution('edit_file_line', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Normalize line endings to \n (handle both \r\n and \n)
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);
        
        $lines = explode("\n", $content);
        
        // Remove empty line at the end if file ends with newline
        if (count($lines) > 0 && empty($lines[count($lines) - 1])) {
            array_pop($lines);
        }
        
        $total_lines = count($lines);
        
        // Handle out of range line numbers intelligently
        if ($line_number < 1) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Line number must be greater than 0. File has {$total_lines} lines.";
            $this->createTaskAfterExecution('edit_file_line', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // If line number exceeds file length, append to end of file instead of error
        if ($line_number > $total_lines) {
            // Append new content to end of file
            $new_lines = explode("\n", $new_content);
            $updated_lines = array_merge($lines, $new_lines);
            $action_message = "appended to end of file (line {$line_number} requested, file has {$total_lines} lines)";
        } else {
            // Normal edit operation
            $line_count = max(1, $line_count);
            $end_line = min($line_number + $line_count - 1, $total_lines);
            
            $new_lines = explode("\n", $new_content);
            
            $before = array_slice($lines, 0, $line_number - 1);
            $after = array_slice($lines, $end_line);
            $updated_lines = array_merge($before, $new_lines, $after);
            $action_message = "edited line(s) {$line_number}" . ($line_count > 1 ? "-{$end_line}" : "");
        }
        
        $new_content_full = implode("\n", $updated_lines);
        
        // Try to write file, with error handling
        $duration = microtime(true) - $startTime;
        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem access required for plugin development tool
            $write_result = @file_put_contents($fullPath, $new_content_full);
            if ($write_result === false) {
                // Try alternative method: use file handle
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct filesystem access required for plugin development tool
                $handle = @fopen($fullPath, 'w');
                if ($handle === false) {
                    $errorMsg = "Failed to write file: {$fullPath}. Check file permissions.";
                    $this->createTaskAfterExecution('edit_file_line', $details, false, $errorMsg, $duration);
                    wp_send_json_error(array('message' => $errorMsg));
                    return;
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct filesystem access required for plugin development tool
                $write_result = @fwrite($handle, $new_content_full);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct filesystem access required for plugin development tool
                @fclose($handle);
                
                if ($write_result === false) {
                    $errorMsg = "Failed to write file: {$fullPath}. Check file permissions.";
                    $this->createTaskAfterExecution('edit_file_line', $details, false, $errorMsg, $duration);
                    wp_send_json_error(array('message' => $errorMsg));
                    return;
                }
            }
            
            $result = "Successfully {$action_message} in file: {$fullPath}";
            $this->createTaskAfterExecution('edit_file_line', $details, true, $result, $duration);
            wp_send_json_success(array('result' => $result));
        } catch (Exception $e) {
            $errorMsg = "Error writing file: {$fullPath}. " . $e->getMessage();
            $this->createTaskAfterExecution('edit_file_line', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
        }
    }

    /**
     * Handle list_plugins tool
     */
    public function handle_list_plugins() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : null;

        $details = ['status' => $status ?? 'all'];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $allPlugins = get_plugins();
        $activePlugins = get_option('active_plugins', []);
        $networkActivePlugins = [];
        if (is_multisite()) {
            $networkActivePlugins = get_site_option('active_sitewide_plugins', []);
        }
        
        $pluginsList = [];
        
        foreach ($allPlugins as $pluginFile => $pluginData) {
            $isActive = in_array($pluginFile, $activePlugins);
            $isNetworkActive = in_array($pluginFile, $networkActivePlugins);
            
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
        
        $duration = microtime(true) - $startTime;
        $this->createTaskAfterExecution('list_plugins', $details, true, $response, $duration);
        wp_send_json_success(array('result' => $response));
    }

    /**
     * Handle deactivate_plugin tool
     */
    public function handle_deactivate_plugin() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $plugin_file = isset($_POST['plugin_file']) ? sanitize_text_field(wp_unslash($_POST['plugin_file'])) : '';
        
        $details = ['plugin_file' => $plugin_file];
        
        if (empty($plugin_file)) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('deactivate_plugin', $details, false, 'Plugin file is required', $duration);
            wp_send_json_error(array('message' => 'Plugin file is required'));
            return;
        }

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $allPlugins = get_plugins();
        
        $pluginPath = null;
        if (isset($allPlugins[$plugin_file])) {
            $pluginPath = $plugin_file;
        } else {
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
            $duration = microtime(true) - $startTime;
            $errorMsg = "Plugin not found: {$plugin_file}. Use list_plugins to see available plugins.";
            $this->createTaskAfterExecution('deactivate_plugin', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        $activePlugins = get_option('active_plugins', []);
        $isNetworkActive = false;
        if (is_multisite()) {
            $networkActivePlugins = get_site_option('active_sitewide_plugins', []);
            $isNetworkActive = isset($networkActivePlugins[$pluginPath]);
        }
        
        $isActive = in_array($pluginPath, $activePlugins);
        
        $duration = microtime(true) - $startTime;
        if (!$isActive && !$isNetworkActive) {
            $pluginName = $allPlugins[$pluginPath]['Name'] ?? $pluginPath;
            $result = "Plugin '{$pluginName}' is already inactive.";
            $this->createTaskAfterExecution('deactivate_plugin', $details, true, $result, $duration);
            wp_send_json_success(array('result' => $result));
            return;
        }
        
        // Prevent deactivating Plugitify itself
        if (stripos($pluginPath, 'plugitify') !== false || stripos($pluginPath, 'Pluginity') !== false) {
            $errorMsg = 'ERROR: Cannot deactivate the Pluginity/plugitify plugin. This is a protected system plugin.';
            $this->createTaskAfterExecution('deactivate_plugin', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        $result = deactivate_plugins($pluginPath);
        
        if (is_wp_error($result)) {
            $errorMsg = "Failed to deactivate plugin: " . $result->get_error_message();
            $this->createTaskAfterExecution('deactivate_plugin', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        $pluginName = $allPlugins[$pluginPath]['Name'] ?? $pluginPath;
        $resultMsg = "Plugin '{$pluginName}' ({$pluginPath}) has been successfully deactivated.";
        $this->createTaskAfterExecution('deactivate_plugin', $details, true, $resultMsg, $duration);
        wp_send_json_success(array('result' => $resultMsg));
    }

    /**
     * Handle extract_plugin_structure tool
     */
    public function handle_extract_plugin_structure() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $plugin_name = isset($_POST['plugin_name']) ? sanitize_text_field(wp_unslash($_POST['plugin_name'])) : '';
        
        $details = ['plugin_name' => $plugin_name];
        
        if (empty($plugin_name)) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('extract_plugin_structure', $details, false, 'Plugin name is required', $duration);
            wp_send_json_error(array('message' => 'Plugin name is required'));
            return;
        }

        $pluginsDir = $this->getPluginsDir();
        $plugin_name = trim($plugin_name, '/\\');
        $plugin_path = rtrim($pluginsDir, '/\\') . '/' . $plugin_name;
        $plugin_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $plugin_path);
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($plugin_name)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'ERROR: Cannot extract structure from the Pluginity/plugitify plugin directory. This is a protected system plugin.';
            $this->createTaskAfterExecution('extract_plugin_structure', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        if (!is_dir($plugin_path)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Plugin directory not found: {$plugin_path}";
            $this->createTaskAfterExecution('extract_plugin_structure', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
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
                    if (in_array($item, ['vendor', 'node_modules', '.git', '.svn'])) {
                        continue;
                    }
                    
                    $structure['directories'][] = $relative_item_path;
                    $scanDirectory($item_path, $relative_item_path);
                } elseif (is_file($item_path)) {
                    $structure['files'][] = $relative_item_path;
                    
                    if (pathinfo($item_path, PATHINFO_EXTENSION) === 'php') {
                        $content = file_get_contents($item_path);
                        if ($content !== false) {
                            if (preg_match('/Plugin Name:\s*(.+)/i', $content, $matches)) {
                                $structure['main_file'] = $relative_item_path;
                            }
                            
                            if (preg_match_all('/\bclass\s+(\w+)/i', $content, $class_matches)) {
                                foreach ($class_matches[1] as $class_name) {
                                    $structure['classes'][] = [
                                        'name' => $class_name,
                                        'file' => $relative_item_path
                                    ];
                                }
                            }
                            
                            if (preg_match_all('/\bfunction\s+(\w+)\s*\(/i', $content, $func_matches, PREG_OFFSET_CAPTURE)) {
                                foreach ($func_matches[1] as $func_match) {
                                    $func_name = $func_match[0];
                                    $func_pos = $func_match[1];
                                    
                                    $before = substr($content, max(0, $func_pos - 100), $func_pos);
                                    if (!preg_match('/\b(class|function)\s+\w+\s*\{[^}]*$/', $before)) {
                                        $structure['functions'][] = [
                                            'name' => $func_name,
                                            'file' => $relative_item_path
                                        ];
                                    }
                                }
                            }
                            
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
        
        $duration = microtime(true) - $startTime;
        $this->createTaskAfterExecution('extract_plugin_structure', $details, true, $output, $duration);
        wp_send_json_success(array('result' => $output));
    }

    /**
     * Handle toggle_wp_debug tool
     */
    public function handle_toggle_wp_debug() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $enable = isset($_POST['enable']) ? filter_var(wp_unslash($_POST['enable']), FILTER_VALIDATE_BOOLEAN) : null;
        
        $details = ['enable' => $enable];
        
        if ($enable === null) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('toggle_wp_debug', $details, false, 'Enable parameter is required (true or false)', $duration);
            wp_send_json_error(array('message' => 'Enable parameter is required (true or false)'));
            return;
        }

        // Get wp-config.php path
        $wpConfigPath = defined('ABSPATH') ? ABSPATH . 'wp-config.php' : '';
        
        if (!file_exists($wpConfigPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "wp-config.php not found at: {$wpConfigPath}";
            $this->createTaskAfterExecution('toggle_wp_debug', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Direct filesystem access required for plugin development tool
        if (!is_writable($wpConfigPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "wp-config.php is not writable. Please check file permissions.";
            $this->createTaskAfterExecution('toggle_wp_debug', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Read wp-config.php
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Direct filesystem access required for plugin development tool
        $content = file_get_contents($wpConfigPath);
        if ($content === false) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Failed to read wp-config.php";
            $this->createTaskAfterExecution('toggle_wp_debug', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // If enabling, clear the debug.log file first
        if ($enable) {
            $debugLogPath = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : ABSPATH . 'wp-content/debug.log';
            if (file_exists($debugLogPath)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct filesystem access required for plugin development tool
                @unlink($debugLogPath);
            }
        }
        
        // Patterns to find WP_DEBUG settings
        $patterns = [
            'WP_DEBUG' => "/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(?:true|false)\s*\)\s*;/i",
            'WP_DEBUG_LOG' => "/define\s*\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*(?:true|false)\s*\)\s*;/i",
            'WP_DEBUG_DISPLAY' => "/define\s*\(\s*['\"]WP_DEBUG_DISPLAY['\"]\s*,\s*(?:true|false)\s*\)\s*;/i",
        ];
        
        $debugValue = $enable ? 'true' : 'false';
        $logValue = $enable ? 'true' : 'false';
        $displayValue = $enable ? 'false' : 'false'; // Always false for display
        
        // Replace existing defines or add them
        $hasDebug = preg_match($patterns['WP_DEBUG'], $content);
        $hasLog = preg_match($patterns['WP_DEBUG_LOG'], $content);
        $hasDisplay = preg_match($patterns['WP_DEBUG_DISPLAY'], $content);
        
        if ($hasDebug) {
            $content = preg_replace($patterns['WP_DEBUG'], "define( 'WP_DEBUG', {$debugValue} );", $content);
        } else {
            // Add after database settings (before stop editing line)
            $content = preg_replace(
                "/(\/\* Add any custom values between this line.*?\*\/)/s",
                "define( 'WP_DEBUG', {$debugValue} );\n\n$1",
                $content
            );
        }
        
        if ($hasLog) {
            $content = preg_replace($patterns['WP_DEBUG_LOG'], "define( 'WP_DEBUG_LOG', {$logValue} );", $content);
        } else {
            // Add after WP_DEBUG
            $content = preg_replace(
                "/(define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(?:true|false)\s*\)\s*;)/i",
                "$1\ndefine( 'WP_DEBUG_LOG', {$logValue} );",
                $content
            );
        }
        
        if ($hasDisplay) {
            $content = preg_replace($patterns['WP_DEBUG_DISPLAY'], "define( 'WP_DEBUG_DISPLAY', {$displayValue} );", $content);
        } else {
            // Add after WP_DEBUG_LOG
            $content = preg_replace(
                "/(define\s*\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*(?:true|false)\s*\)\s*;)/i",
                "$1\ndefine( 'WP_DEBUG_DISPLAY', {$displayValue} );",
                $content
            );
        }
        
        // Write back to wp-config.php
        $duration = microtime(true) - $startTime;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem access required for plugin development tool
        $result = file_put_contents($wpConfigPath, $content);
        if ($result === false) {
            $errorMsg = "Failed to write to wp-config.php";
            $this->createTaskAfterExecution('toggle_wp_debug', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        $status = $enable ? 'enabled' : 'disabled';
        $message = "WP_DEBUG has been {$status} successfully.";
        
        if ($enable) {
            $message .= "\nDebug logs will be saved to wp-content/debug.log";
            $message .= "\nPrevious log file has been cleared.";
        }
        
        $this->createTaskAfterExecution('toggle_wp_debug', $details, true, $message, $duration);
        wp_send_json_success(array('result' => $message));
    }

    /**
     * Handle read_debug_log tool
     */
    public function handle_read_debug_log() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $lines = isset($_POST['lines']) ? intval($_POST['lines']) : 100; // Default last 100 lines
        
        $details = ['lines' => $lines];

        // Get debug.log path
        $debugLogPath = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : ABSPATH . 'wp-content/debug.log';
        
        if (!file_exists($debugLogPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Debug log file not found at: {$debugLogPath}\n\nMake sure WP_DEBUG and WP_DEBUG_LOG are enabled in wp-config.php";
            $this->createTaskAfterExecution('read_debug_log', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        if (!is_readable($debugLogPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Debug log file is not readable. Please check file permissions.";
            $this->createTaskAfterExecution('read_debug_log', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Get file size
        $fileSize = filesize($debugLogPath);
        $duration = microtime(true) - $startTime;
        if ($fileSize === 0) {
            $result = "Debug log file is empty.\n\nNo errors have been logged yet.";
            $this->createTaskAfterExecution('read_debug_log', $details, true, $result, $duration);
            wp_send_json_success(array('result' => $result));
            return;
        }
        
        // Read the file
        $content = file_get_contents($debugLogPath);
        if ($content === false) {
            $errorMsg = "Failed to read debug log file";
            $this->createTaskAfterExecution('read_debug_log', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Split into lines
        $allLines = explode("\n", $content);
        $totalLines = count($allLines);
        
        // Get last N lines if specified
        if ($lines > 0 && $lines < $totalLines) {
            $logLines = array_slice($allLines, -$lines);
            $output = "Debug Log (Last {$lines} of {$totalLines} lines | File size: " . size_format($fileSize) . "):\n";
            $output .= str_repeat("=", 80) . "\n\n";
            $output .= implode("\n", $logLines);
        } else {
            $output = "Debug Log (All {$totalLines} lines | File size: " . size_format($fileSize) . "):\n";
            $output .= str_repeat("=", 80) . "\n\n";
            $output .= $content;
        }
        
        $this->createTaskAfterExecution('read_debug_log', $details, true, $output, $duration);
        wp_send_json_success(array('result' => $output));
    }

    /**
     * Handle check_wp_debug_status tool
     */
    public function handle_check_wp_debug_status() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        
        $details = [];

        // Get current WordPress constants
        $wpDebug = defined('WP_DEBUG') ? WP_DEBUG : false;
        $wpDebugLog = defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : false;
        $wpDebugDisplay = defined('WP_DEBUG_DISPLAY') ? WP_DEBUG_DISPLAY : false;
        
        // Check debug.log file
        $debugLogPath = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : ABSPATH . 'wp-content/debug.log';
        $logExists = file_exists($debugLogPath);
        $logSize = $logExists ? filesize($debugLogPath) : 0;
        $logLines = 0;
        
        if ($logExists && $logSize > 0) {
            $content = file_get_contents($debugLogPath);
            if ($content !== false) {
                $logLines = count(explode("\n", $content));
            }
        }
        
        // Build status report
        $output = "WordPress Debug Status:\n";
        $output .= str_repeat("=", 80) . "\n\n";
        
        $output .= "📊 Debug Mode Configuration:\n";
        $output .= "  • WP_DEBUG: " . ($wpDebug ? "✅ Enabled (true)" : "❌ Disabled (false)") . "\n";
        $output .= "  • WP_DEBUG_LOG: " . ($wpDebugLog ? "✅ Enabled (true)" : "❌ Disabled (false)") . "\n";
        $output .= "  • WP_DEBUG_DISPLAY: " . ($wpDebugDisplay ? "⚠️ Enabled (true)" : "✅ Disabled (false)") . "\n";
        $output .= "\n";
        
        $output .= "📝 Debug Log File:\n";
        $output .= "  • Path: {$debugLogPath}\n";
        $output .= "  • Status: " . ($logExists ? "✅ File exists" : "❌ File not found") . "\n";
        
        if ($logExists) {
            $output .= "  • Size: " . size_format($logSize) . "\n";
            $output .= "  • Lines: " . number_format($logLines) . "\n";
            
            if ($logSize === 0) {
                $output .= "  • Content: Empty (no errors logged yet)\n";
            } else {
                $output .= "  • Content: Contains error logs\n";
            }
        }
        
        $output .= "\n";
        $output .= "💡 Overall Status: ";
        
        if ($wpDebug && $wpDebugLog && !$wpDebugDisplay) {
            $output .= "✅ Debug mode is properly configured (logging to file, not displaying on screen)\n";
        } elseif ($wpDebug && $wpDebugLog && $wpDebugDisplay) {
            $output .= "⚠️ Debug mode is enabled, but WP_DEBUG_DISPLAY is also enabled (errors will show on screen)\n";
        } elseif ($wpDebug && !$wpDebugLog) {
            $output .= "⚠️ Debug mode is enabled, but logging to file is disabled\n";
        } elseif (!$wpDebug) {
            $output .= "❌ Debug mode is disabled\n";
        } else {
            $output .= "⚠️ Debug configuration is incomplete\n";
        }
        
        $output .= "\n";
        $output .= "📌 Recommendations:\n";
        
        if (!$wpDebug) {
            $output .= "  • Use toggle_wp_debug tool with enable=true to enable debug mode\n";
        } elseif ($wpDebug && !$wpDebugLog) {
            $output .= "  • Enable WP_DEBUG_LOG to save errors to a log file\n";
        } elseif ($wpDebug && $wpDebugDisplay) {
            $output .= "  • Disable WP_DEBUG_DISPLAY to prevent errors from showing on screen (security risk)\n";
        } elseif ($logExists && $logSize > 1048576) { // 1MB
            $output .= "  • Debug log file is large (" . size_format($logSize) . "). Consider reviewing and clearing it.\n";
        } else {
            $output .= "  • Configuration looks good! ✅\n";
        }
        
        $duration = microtime(true) - $startTime;
        $this->createTaskAfterExecution('check_wp_debug_status', $details, true, $output, $duration);
        wp_send_json_success(array('result' => $output));
    }

    /**
     * Handle search_replace_in_file tool
     */
    public function handle_search_replace_in_file() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in validateRequest(), replacements is JSON that will be decoded and validated
        $replacements = isset($_POST['replacements']) ? wp_unslash($_POST['replacements']) : '';
        
        // Decode replacements if it was JSON encoded
        if (is_string($replacements)) {
            $replacements = json_decode(stripslashes($replacements), true);
        }
        
        $details = [
            'file_path' => $file_path,
            'replacements_count' => is_array($replacements) ? count($replacements) : 0
        ];
        
        if (empty($file_path)) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('search_replace_in_file', $details, false, 'File path is required', $duration);
            wp_send_json_error(array('message' => 'File path is required'));
            return;
        }
        
        if (empty($replacements) || !is_array($replacements)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'Replacements array is required. Format: [{"search": "old text", "replace": "new text"}, ...]';
            $this->createTaskAfterExecution('search_replace_in_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }

        $pluginsDir = $this->getPluginsDir();
        $file_path = ltrim($file_path, '/\\');
        
        if (file_exists($file_path)) {
            $fullPath = $file_path;
        } elseif (defined('ABSPATH') && file_exists(ABSPATH . $file_path)) {
            $fullPath = ABSPATH . $file_path;
        } elseif (strpos($file_path, $pluginsDir) !== 0) {
            $fullPath = rtrim($pluginsDir, '/\\') . '/' . $file_path;
        } else {
            $fullPath = $file_path;
        }
        
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($file_path)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'ERROR: Cannot edit files in the Pluginity/plugitify plugin directory. This is a protected system plugin.';
            $this->createTaskAfterExecution('search_replace_in_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Deactivate plugin if needed before making changes
        $this->deactivatePluginIfNeeded($fullPath);
        
        if (!file_exists($fullPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "File not found: {$fullPath}";
            $this->createTaskAfterExecution('search_replace_in_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        if (!is_file($fullPath)) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Path is not a file: {$fullPath}";
            $this->createTaskAfterExecution('search_replace_in_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Read file content
        $content = file_get_contents($fullPath);
        if ($content === false) {
            $duration = microtime(true) - $startTime;
            $errorMsg = "Failed to read file: {$fullPath}";
            $this->createTaskAfterExecution('search_replace_in_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        $originalContent = $content;
        $totalReplacements = 0;
        $replacementDetails = [];
        
        // Process each replacement
        foreach ($replacements as $index => $replacement) {
            if (!isset($replacement['search']) || !isset($replacement['replace'])) {
                $duration = microtime(true) - $startTime;
                $errorMsg = "Invalid replacement at index {$index}. Each replacement must have 'search' and 'replace' keys.";
                $this->createTaskAfterExecution('search_replace_in_file', $details, false, $errorMsg, $duration);
                wp_send_json_error(array('message' => $errorMsg));
                return;
            }
            
            $search = $replacement['search'];
            $replace = $replacement['replace'];
            $replaceAll = isset($replacement['replace_all']) ? (bool)$replacement['replace_all'] : true;
            
            // Decode if base64 encoded
            if (isset($replacement['search_encoded']) && $replacement['search_encoded'] === '1') {
                $search = base64_decode($search, true);
            }
            if (isset($replacement['replace_encoded']) && $replacement['replace_encoded'] === '1') {
                $replace = base64_decode($replace, true);
            }
            
            // Count occurrences before replacement
            $occurrencesBefore = substr_count($content, $search);
            
            if ($occurrencesBefore === 0) {
                $replacementDetails[] = [
                    'search' => substr($search, 0, 50) . (strlen($search) > 50 ? '...' : ''),
                    'found' => false,
                    'count' => 0
                ];
                continue;
            }
            
            // Perform replacement
            if ($replaceAll) {
                $content = str_replace($search, $replace, $content);
                $replacementCount = $occurrencesBefore;
            } else {
                // Replace only first occurrence
                $pos = strpos($content, $search);
                if ($pos !== false) {
                    $content = substr_replace($content, $replace, $pos, strlen($search));
                    $replacementCount = 1;
                } else {
                    $replacementCount = 0;
                }
            }
            
            $totalReplacements += $replacementCount;
            $replacementDetails[] = [
                'search' => substr($search, 0, 50) . (strlen($search) > 50 ? '...' : ''),
                'replace' => substr($replace, 0, 50) . (strlen($replace) > 50 ? '...' : ''),
                'found' => true,
                'count' => $replacementCount
            ];
        }
        
        // Check if any changes were made
        $duration = microtime(true) - $startTime;
        if ($content === $originalContent) {
            $errorMsg = "No replacements were made. None of the search patterns were found in the file.";
            $this->createTaskAfterExecution('search_replace_in_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Write back to file
        $result = file_put_contents($fullPath, $content);
        if ($result === false) {
            $errorMsg = "Failed to write to file: {$fullPath}";
            $this->createTaskAfterExecution('search_replace_in_file', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        // Build success message
        $message = "Successfully processed {$totalReplacements} replacement(s) in file: {$fullPath}\n\n";
        $message .= "Replacement Details:\n";
        $message .= str_repeat("-", 60) . "\n";
        
        foreach ($replacementDetails as $i => $detail) {
            $message .= "\n" . ($i + 1) . ". ";
            if ($detail['found']) {
                $message .= "✅ Replaced {$detail['count']} occurrence(s)\n";
                $message .= "   Search: {$detail['search']}\n";
                $message .= "   Replace: {$detail['replace']}\n";
            } else {
                $message .= "⚠️ Not found: {$detail['search']}\n";
            }
        }
        
        $this->createTaskAfterExecution('search_replace_in_file', $details, true, $message, $duration);
        wp_send_json_success(array('result' => $message));
    }

    /**
     * Handle update_chat_title tool
     */
    public function handle_update_chat_title() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        
        $details = ['title' => $title, 'chat_id' => $chat_id];
        
        if (empty($title)) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('update_chat_title', $details, false, 'Title is required', $duration);
            wp_send_json_error(array('message' => 'Title is required'));
            return;
        }
        
        if ($chat_id <= 0) {
            $duration = microtime(true) - $startTime;
            $this->createTaskAfterExecution('update_chat_title', $details, false, 'Valid chat_id is required', $duration);
            wp_send_json_error(array('message' => 'Valid chat_id is required'));
            return;
        }

        // Check if table exists
        if (!\Plugitify_DB::tableExists('chat_history')) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'Database table does not exist';
            $this->createTaskAfterExecution('update_chat_title', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }
        
        $user_id = get_current_user_id();
        
        // Update chat title
        $updated = \Plugitify_DB::table('chat_history')
            ->where('id', $chat_id)
            ->where('user_id', $user_id)
            ->update(array('title' => $title));

        $duration = microtime(true) - $startTime;
        if ($updated) {
            $result = "Chat title updated successfully to: {$title}";
            $this->createTaskAfterExecution('update_chat_title', $details, true, $result, $duration);
            wp_send_json_success(array('result' => $result, 'title' => $title));
        } else {
            $errorMsg = 'Failed to update chat title';
            $this->createTaskAfterExecution('update_chat_title', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
        }
    }

    /**
     * Handle get_chat_tasks tool
     * Retrieves all tasks for a specific chat with optional progress tracking
     */
    public function handle_get_chat_tasks() {
        $startTime = microtime(true);
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $track_progress = isset($_POST['track_progress']) ? filter_var(wp_unslash($_POST['track_progress']), FILTER_VALIDATE_BOOLEAN) : false;
        
        $details = ['chat_id' => $chat_id, 'track_progress' => $track_progress];
        
        if ($chat_id <= 0) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'Valid chat_id is required';
            $this->createTaskAfterExecution('get_chat_tasks', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }

        // Check if tables exist
        if (!\Plugitify_DB::tableExists('tasks')) {
            $duration = microtime(true) - $startTime;
            $errorMsg = 'Tasks table does not exist';
            $this->createTaskAfterExecution('get_chat_tasks', $details, false, $errorMsg, $duration);
            wp_send_json_error(array('message' => $errorMsg));
            return;
        }

        $user_id = get_current_user_id();
        
        // Get all tasks for this chat
        $tasks = \Plugitify_DB::table('tasks')
            ->where('chat_history_id', $chat_id)
            ->where('user_id', $user_id)
            ->orderBy('created_at', 'DESC')
            ->get();
        
        if (!$tasks) {
            $tasks = [];
        }
        
        // Convert to array if needed
        if (is_object($tasks)) {
            $tasks = [$tasks];
        }
        
        $tasksData = [];
        
        foreach ($tasks as $task) {
            $taskArray = is_object($task) ? (array)$task : $task;
            
            $taskData = [
                'id' => $taskArray['id'] ?? null,
                'task_name' => $taskArray['task_name'] ?? '',
                'task_type' => $taskArray['task_type'] ?? null,
                'description' => $taskArray['description'] ?? null,
                'status' => $taskArray['status'] ?? 'pending',
                'progress' => isset($taskArray['progress']) ? intval($taskArray['progress']) : 0,
                'result' => $taskArray['result'] ?? null,
                'error_message' => $taskArray['error_message'] ?? null,
                'created_at' => $taskArray['created_at'] ?? null,
                'updated_at' => $taskArray['updated_at'] ?? null,
            ];
            
            // If track_progress is true, get steps for this task
            if ($track_progress && \Plugitify_DB::tableExists('steps')) {
                $taskId = $taskArray['id'] ?? null;
                if ($taskId) {
                    $steps = \Plugitify_DB::table('steps')
                        ->where('task_id', $taskId)
                        ->orderBy('order', 'ASC')
                        ->orderBy('created_at', 'ASC')
                        ->get();
                    
                    if (!$steps) {
                        $steps = [];
                    }
                    
                    // Convert to array if needed
                    if (is_object($steps) && !is_array($steps)) {
                        $steps = [$steps];
                    }
                    
                    $stepsData = [];
                    foreach ($steps as $step) {
                        $stepArray = is_object($step) ? (array)$step : $step;
                        $stepsData[] = [
                            'id' => $stepArray['id'] ?? null,
                            'step_name' => $stepArray['step_name'] ?? '',
                            'step_type' => $stepArray['step_type'] ?? null,
                            'order' => isset($stepArray['order']) ? intval($stepArray['order']) : 0,
                            'status' => $stepArray['status'] ?? 'pending',
                            'content' => $stepArray['content'] ?? null,
                            'result' => $stepArray['result'] ?? null,
                            'error_message' => $stepArray['error_message'] ?? null,
                            'duration' => isset($stepArray['duration']) ? intval($stepArray['duration']) : 0,
                            'created_at' => $stepArray['created_at'] ?? null,
                            'updated_at' => $stepArray['updated_at'] ?? null,
                        ];
                    }
                    
                    $taskData['steps'] = $stepsData;
                    $taskData['steps_count'] = count($stepsData);
                } else {
                    $taskData['steps'] = [];
                    $taskData['steps_count'] = 0;
                }
            } else {
                $taskData['steps'] = [];
                $taskData['steps_count'] = 0;
            }
            
            $tasksData[] = $taskData;
        }
        
        // Build result message
        $totalTasks = count($tasksData);
        $completedTasks = count(array_filter($tasksData, function($t) { return $t['status'] === 'completed'; }));
        $inProgressTasks = count(array_filter($tasksData, function($t) { return $t['status'] === 'in_progress'; }));
        $failedTasks = count(array_filter($tasksData, function($t) { return $t['status'] === 'failed'; }));
        $pendingTasks = count(array_filter($tasksData, function($t) { return $t['status'] === 'pending'; }));
        
        $result = "Found {$totalTasks} task(s) for chat #{$chat_id}:\n\n";
        $result .= "Summary:\n";
        $result .= "  • Total: {$totalTasks}\n";
        $result .= "  • Completed: {$completedTasks}\n";
        $result .= "  • In Progress: {$inProgressTasks}\n";
        $result .= "  • Pending: {$pendingTasks}\n";
        $result .= "  • Failed: {$failedTasks}\n\n";
        
        if ($track_progress) {
            $result .= "Detailed Task List (with progress tracking):\n";
            $result .= str_repeat("=", 80) . "\n\n";
            
            foreach ($tasksData as $index => $task) {
                $taskNum = $index + 1;
                $result .= "Task #{$taskNum}: {$task['task_name']}\n";
                $result .= "  Status: {$task['status']}\n";
                $result .= "  Progress: {$task['progress']}%\n";
                
                if ($task['description']) {
                    $result .= "  Description: {$task['description']}\n";
                }
                
                if ($task['steps_count'] > 0) {
                    $result .= "  Steps ({$task['steps_count']}):\n";
                    foreach ($task['steps'] as $stepIndex => $step) {
                        $stepNum = $stepIndex + 1;
                        $statusIcon = $step['status'] === 'completed' ? '✅' : ($step['status'] === 'failed' ? '❌' : ($step['status'] === 'in_progress' ? '🔄' : '⏳'));
                        $result .= "    {$stepNum}. {$statusIcon} {$step['step_name']} [{$step['status']}]\n";
                        if ($step['result']) {
                            $resultPreview = strlen($step['result']) > 100 ? substr($step['result'], 0, 100) . '...' : $step['result'];
                            $result .= "       Result: {$resultPreview}\n";
                        }
                        if ($step['error_message']) {
                            $errorPreview = strlen($step['error_message']) > 100 ? substr($step['error_message'], 0, 100) . '...' : $step['error_message'];
                            $result .= "       Error: {$errorPreview}\n";
                        }
                    }
                }
                
                if ($task['result']) {
                    $resultPreview = strlen($task['result']) > 200 ? substr($task['result'], 0, 200) . '...' : $task['result'];
                    $result .= "  Result: {$resultPreview}\n";
                }
                
                if ($task['error_message']) {
                    $errorPreview = strlen($task['error_message']) > 200 ? substr($task['error_message'], 0, 200) . '...' : $task['error_message'];
                    $result .= "  Error: {$errorPreview}\n";
                }
                
                $result .= "\n";
            }
        } else {
            $result .= "Task List:\n";
            $result .= str_repeat("=", 80) . "\n\n";
            
            foreach ($tasksData as $index => $task) {
                $taskNum = $index + 1;
                $statusIcon = $task['status'] === 'completed' ? '✅' : ($task['status'] === 'failed' ? '❌' : ($task['status'] === 'in_progress' ? '🔄' : '⏳'));
                $result .= "{$taskNum}. {$statusIcon} {$task['task_name']} [{$task['status']}] - Progress: {$task['progress']}%\n";
            }
        }
        
        $duration = microtime(true) - $startTime;
        $this->createTaskAfterExecution('get_chat_tasks', $details, true, $result, $duration);
        wp_send_json_success(array(
            'result' => $result,
            'tasks' => $tasksData,
            'summary' => [
                'total' => $totalTasks,
                'completed' => $completedTasks,
                'in_progress' => $inProgressTasks,
                'pending' => $pendingTasks,
                'failed' => $failedTasks
            ],
            'track_progress' => $track_progress
        ));
    }
}

new Plugitify_Tools_API();

