<?php

namespace Plugitify\Classes;

class Plugitify_Tools_API {
    private static $currentChatId = null;
    private static $currentUserId = null;

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
    }

    /**
     * Set context for task tracking
     */
    public static function setContext(?int $chatId = null, ?int $userId = null): void {
        self::$currentChatId = $chatId;
        self::$currentUserId = $userId ?: get_current_user_id();
    }

    /**
     * Auto manage task and step for tool calls
     */
    private function autoManageTaskStep(string $toolName, array $details = []): ?array {
        try {
            $chatId = self::$currentChatId;
            $userId = self::$currentUserId ?: get_current_user_id();

            if (!class_exists('\Plugitify_DB')) {
                return null;
            }

            if (!\Plugitify_DB::tableExists('tasks') || !\Plugitify_DB::tableExists('steps')) {
                return null;
            }

            // Complete all previous pending/in_progress tasks for this chat
            if ($chatId) {
                $pendingTasks = \Plugitify_DB::table('tasks')
                    ->where('chat_history_id', $chatId)
                    ->where('user_id', $userId)
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->get();

                if ($pendingTasks) {
                    if (!is_array($pendingTasks)) {
                        $pendingTasks = [$pendingTasks];
                    }

                    foreach ($pendingTasks as $task) {
                        $taskId = is_object($task) ? $task->id : $task['id'];

                        // Complete the task
                        \Plugitify_DB::table('tasks')
                            ->where('id', $taskId)
                            ->where('user_id', $userId)
                            ->update([
                                'status' => 'completed',
                                'progress' => 100
                            ]);

                        // Complete all steps for this task
                        $pendingSteps = \Plugitify_DB::table('steps')
                            ->where('task_id', $taskId)
                            ->whereIn('status', ['pending', 'in_progress'])
                            ->get();

                        if ($pendingSteps) {
                            if (!is_array($pendingSteps)) {
                                $pendingSteps = [$pendingSteps];
                            }

                            foreach ($pendingSteps as $step) {
                                $stepId = is_object($step) ? $step->id : $step['id'];
                                \Plugitify_DB::table('steps')
                                    ->where('id', $stepId)
                                    ->update([
                                        'status' => 'completed'
                                    ]);
                            }
                        }
                    }
                }
            }

            // Build detailed task/step name
            $baseName = ucfirst(str_replace('_', ' ', $toolName));
            $taskName = $this->buildDetailedTaskName($toolName, $details, $baseName);
            $description = $this->buildDetailedDescription($toolName, $details);

            // Create a new task for this tool call
            $taskData = [
                'chat_history_id' => $chatId,
                'user_id' => $userId,
                'task_name' => $taskName,
                'task_type' => 'tool_execution',
                'description' => $description,
                'status' => 'in_progress',
                'progress' => 0
            ];

            $taskId = \Plugitify_DB::table('tasks')->insert($taskData);

            if (!$taskId) {
                return null;
            }

            // Create a new step for this tool call
            global $wpdb;
            $steps_table = \Plugitify_DB::getFullTableName('steps');
            $maxOrder = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(`order`) FROM {$steps_table} WHERE task_id = %d",
                $taskId
            ));
            $order = ($maxOrder !== null && $maxOrder !== false) ? intval($maxOrder) + 1 : 1;

            $stepData = [
                'task_id' => $taskId,
                'step_name' => $taskName,
                'step_type' => 'tool_call',
                'order' => $order,
                'status' => 'pending'
            ];

            $stepId = \Plugitify_DB::table('steps')->insert($stepData);

            if (!$stepId) {
                return null;
            }

            return [
                'task_id' => $taskId,
                'step_id' => $stepId
            ];
        } catch (\Exception $e) {
            error_log('Plugitify: Failed to auto manage task/step: ' . $e->getMessage());
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
     * Common validation for all tool endpoints
     */
    private function validateRequest(): array {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
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
        self::setContext($chat_id, $user_id);

        return ['chat_id' => $chat_id, 'user_id' => $user_id];
    }

    /**
     * Get plugins directory path
     */
    private function getPluginsDir(): string {
        return defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('ABSPATH') ? ABSPATH . 'wp-content/plugins/' : __DIR__ . '/../../../../plugins/');
    }

    /**
     * Handle create_directory tool
     */
    public function handle_create_directory() {
        $context = $this->validateRequest();
        $path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '';
        
        if (empty($path)) {
            wp_send_json_error(array('message' => 'Path is required'));
            return;
        }

        // Auto manage task/step
        $this->autoManageTaskStep('create_directory', ['path' => $path]);

        $pluginsDir = $this->getPluginsDir();
        $path = ltrim($path, '/\\');
        
        if (strpos($path, $pluginsDir) !== 0) {
            $fullPath = rtrim($pluginsDir, '/\\') . '/' . $path;
        } else {
            $fullPath = $path;
        }
        
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        if (!is_dir($fullPath)) {
            if (mkdir($fullPath, 0755, true)) {
                wp_send_json_success(array('result' => "Directory created successfully: {$fullPath}"));
            } else {
                wp_send_json_error(array('message' => "Failed to create directory: {$fullPath}"));
            }
        } else {
            wp_send_json_success(array('result' => "Directory already exists: {$fullPath}"));
        }
    }

    /**
     * Handle create_file tool
     */
    public function handle_create_file() {
        $context = $this->validateRequest();
        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        
        // Decode base64 content if it was encoded
        if (isset($_POST['content_encoded']) && $_POST['content_encoded'] === '1') {
            // Decode base64 (UTF-8 safe - JavaScript used btoa(unescape(encodeURIComponent(...))))
            $decoded = base64_decode($content, true);
            if ($decoded === false) {
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
        
        if (empty($file_path)) {
            wp_send_json_error(array('message' => 'File path is required'));
            return;
        }
        
        if (empty($content) && $content !== '0') {
            wp_send_json_error(array('message' => 'Content is required'));
            return;
        }

        // Auto manage task/step
        $this->autoManageTaskStep('create_file', [
            'file_path' => $file_path,
            'content' => $content,
            'file_size' => strlen($content)
        ]);

        $pluginsDir = $this->getPluginsDir();
        $file_path = ltrim($file_path, '/\\');
        
        if (strpos($file_path, $pluginsDir) !== 0) {
            $fullPath = rtrim($pluginsDir, '/\\') . '/' . $file_path;
        } else {
            $fullPath = $file_path;
        }
        
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        if (file_put_contents($fullPath, $content) !== false) {
            wp_send_json_success(array('result' => "File created successfully: {$fullPath}"));
        } else {
            wp_send_json_error(array('message' => "Failed to create file: {$fullPath}"));
        }
    }

    /**
     * Handle delete_file tool
     */
    public function handle_delete_file() {
        $context = $this->validateRequest();
        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';
        
        if (empty($file_path)) {
            wp_send_json_error(array('message' => 'File path is required'));
            return;
        }

        // Auto manage task/step
        $this->autoManageTaskStep('delete_file', ['file_path' => $file_path]);

        $pluginsDir = $this->getPluginsDir();
        $file_path = ltrim($file_path, '/\\');
        
        if (strpos($file_path, $pluginsDir) !== 0) {
            $fullPath = rtrim($pluginsDir, '/\\') . '/' . $file_path;
        } else {
            $fullPath = $file_path;
        }
        
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        // Check if path is in Pluginity/plugitify directory (CRITICAL: Never allow deletion)
        $plugitifyDir = defined('PLUGITIFY_DIR') ? PLUGITIFY_DIR : __DIR__ . '/../../';
        $plugitifyDir = realpath($plugitifyDir);
        $fullPathReal = realpath($fullPath);
        
        if ($plugitifyDir && $fullPathReal) {
            if (strpos($fullPathReal, $plugitifyDir) === 0 || 
                stripos($fullPath, 'plugitify') !== false || 
                stripos($fullPath, 'Pluginity') !== false) {
                wp_send_json_error(array('message' => 'ERROR: Cannot delete files in the Pluginity/plugitify plugin directory. This is a protected system plugin.'));
                return;
            }
        }
        
        if (!file_exists($fullPath)) {
            wp_send_json_error(array('message' => "File does not exist: {$fullPath}"));
            return;
        }
        
        if (!is_file($fullPath)) {
            wp_send_json_error(array('message' => "Path is not a file: {$fullPath}. Use delete_directory to remove directories."));
            return;
        }
        
        if (unlink($fullPath)) {
            wp_send_json_success(array('result' => "File deleted successfully: {$fullPath}"));
        } else {
            wp_send_json_error(array('message' => "Failed to delete file: {$fullPath}"));
        }
    }

    /**
     * Handle delete_directory tool
     */
    public function handle_delete_directory() {
        $context = $this->validateRequest();
        $path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '';
        
        if (empty($path)) {
            wp_send_json_error(array('message' => 'Path is required'));
            return;
        }

        // Auto manage task/step
        $this->autoManageTaskStep('delete_directory', ['path' => $path]);

        $pluginsDir = $this->getPluginsDir();
        $path = ltrim($path, '/\\');
        
        if (strpos($path, $pluginsDir) !== 0) {
            $fullPath = rtrim($pluginsDir, '/\\') . '/' . $path;
        } else {
            $fullPath = $path;
        }
        
        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        
        // Check if path is in Pluginity/plugitify directory (CRITICAL: Never allow deletion)
        $plugitifyDir = defined('PLUGITIFY_DIR') ? PLUGITIFY_DIR : __DIR__ . '/../../';
        $plugitifyDir = realpath($plugitifyDir);
        $fullPathReal = realpath($fullPath);
        
        if ($plugitifyDir && $fullPathReal) {
            if (strpos($fullPathReal, $plugitifyDir) === 0 || 
                stripos($fullPath, 'plugitify') !== false || 
                stripos($fullPath, 'Pluginity') !== false) {
                wp_send_json_error(array('message' => 'ERROR: Cannot delete directories in the Pluginity/plugitify plugin directory. This is a protected system plugin.'));
                return;
            }
        }
        
        if (!is_dir($fullPath)) {
            wp_send_json_error(array('message' => "Directory does not exist: {$fullPath}"));
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
                    unlink($filePath);
                }
            }
            
            return rmdir($dir);
        };
        
        if ($deleteRecursive($fullPath)) {
            wp_send_json_success(array('result' => "Directory deleted successfully: {$fullPath}"));
        } else {
            wp_send_json_error(array('message' => "Failed to delete directory: {$fullPath}"));
        }
    }

    /**
     * Handle read_file tool
     */
    public function handle_read_file() {
        $context = $this->validateRequest();
        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';
        
        if (empty($file_path)) {
            wp_send_json_error(array('message' => 'File path is required'));
            return;
        }

        // Auto manage task/step
        $this->autoManageTaskStep('read_file', ['file_path' => $file_path]);

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
        
        if (!file_exists($fullPath)) {
            wp_send_json_error(array('message' => "File not found: {$fullPath}"));
            return;
        }
        
        if (!is_file($fullPath)) {
            wp_send_json_error(array('message' => "Path is not a file: {$fullPath}"));
            return;
        }
        
        $content = file_get_contents($fullPath);
        if ($content === false) {
            wp_send_json_error(array('message' => "Failed to read file: {$fullPath}"));
            return;
        }
        
        wp_send_json_success(array('result' => "File content ({$fullPath}):\n\n" . $content));
    }

    /**
     * Handle edit_file_line tool
     */
    public function handle_edit_file_line() {
        $context = $this->validateRequest();
        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';
        $line_number = isset($_POST['line_number']) ? intval($_POST['line_number']) : 0;
        $new_content = isset($_POST['new_content']) ? $_POST['new_content'] : '';
        $line_count = isset($_POST['line_count']) ? intval($_POST['line_count']) : 1;
        
        // Decode base64 content if it was encoded
        if (isset($_POST['new_content_encoded']) && $_POST['new_content_encoded'] === '1') {
            // Decode base64 (UTF-8 safe - JavaScript used btoa(unescape(encodeURIComponent(...))))
            $decoded = base64_decode($new_content, true);
            if ($decoded === false) {
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
        
        if (empty($file_path) || $line_number < 1) {
            wp_send_json_error(array('message' => 'File path and valid line number are required'));
            return;
        }

        // Auto manage task/step
        $this->autoManageTaskStep('edit_file_line', [
            'file_path' => $file_path,
            'line_number' => $line_number,
            'line_count' => $line_count
        ]);

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
        
        if (!file_exists($fullPath)) {
            wp_send_json_error(array('message' => "File not found: {$fullPath}"));
            return;
        }
        
        if (!is_file($fullPath)) {
            wp_send_json_error(array('message' => "Path is not a file: {$fullPath}"));
            return;
        }
        
        $content = file_get_contents($fullPath);
        if ($content === false) {
            wp_send_json_error(array('message' => "Failed to read file: {$fullPath}"));
            return;
        }
        
        $lines = explode("\n", $content);
        
        if ($line_number < 1 || $line_number > count($lines)) {
            wp_send_json_error(array('message' => "Line number {$line_number} is out of range. File has " . count($lines) . " lines."));
            return;
        }
        
        $line_count = max(1, $line_count);
        $end_line = min($line_number + $line_count - 1, count($lines));
        
        $new_lines = explode("\n", $new_content);
        
        $before = array_slice($lines, 0, $line_number - 1);
        $after = array_slice($lines, $end_line);
        $updated_lines = array_merge($before, $new_lines, $after);
        
        $new_content_full = implode("\n", $updated_lines);
        if (file_put_contents($fullPath, $new_content_full) !== false) {
            wp_send_json_success(array('result' => "Successfully edited line(s) {$line_number}" . ($line_count > 1 ? "-{$end_line}" : "") . " in file: {$fullPath}"));
        } else {
            wp_send_json_error(array('message' => "Failed to write file: {$fullPath}"));
        }
    }

    /**
     * Handle list_plugins tool
     */
    public function handle_list_plugins() {
        $context = $this->validateRequest();
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : null;

        // Auto manage task/step
        $this->autoManageTaskStep('list_plugins', ['status' => $status ?? 'all']);

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
        
        wp_send_json_success(array('result' => $response));
    }

    /**
     * Handle deactivate_plugin tool
     */
    public function handle_deactivate_plugin() {
        $context = $this->validateRequest();
        $plugin_file = isset($_POST['plugin_file']) ? sanitize_text_field($_POST['plugin_file']) : '';
        
        if (empty($plugin_file)) {
            wp_send_json_error(array('message' => 'Plugin file is required'));
            return;
        }

        // Auto manage task/step
        $this->autoManageTaskStep('deactivate_plugin', ['plugin_file' => $plugin_file]);

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
            wp_send_json_error(array('message' => "Plugin not found: {$plugin_file}. Use list_plugins to see available plugins."));
            return;
        }
        
        $activePlugins = get_option('active_plugins', []);
        $isNetworkActive = false;
        if (is_multisite()) {
            $networkActivePlugins = get_site_option('active_sitewide_plugins', []);
            $isNetworkActive = isset($networkActivePlugins[$pluginPath]);
        }
        
        $isActive = in_array($pluginPath, $activePlugins);
        
        if (!$isActive && !$isNetworkActive) {
            $pluginName = $allPlugins[$pluginPath]['Name'] ?? $pluginPath;
            wp_send_json_success(array('result' => "Plugin '{$pluginName}' is already inactive."));
            return;
        }
        
        // Prevent deactivating Plugitify itself
        if (stripos($pluginPath, 'plugitify') !== false || stripos($pluginPath, 'Pluginity') !== false) {
            wp_send_json_error(array('message' => 'ERROR: Cannot deactivate the Pluginity/plugitify plugin. This is a protected system plugin.'));
            return;
        }
        
        $result = deactivate_plugins($pluginPath);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => "Failed to deactivate plugin: " . $result->get_error_message()));
            return;
        }
        
        $pluginName = $allPlugins[$pluginPath]['Name'] ?? $pluginPath;
        wp_send_json_success(array('result' => "Plugin '{$pluginName}' ({$pluginPath}) has been successfully deactivated."));
    }

    /**
     * Handle extract_plugin_structure tool
     */
    public function handle_extract_plugin_structure() {
        $context = $this->validateRequest();
        $plugin_name = isset($_POST['plugin_name']) ? sanitize_text_field($_POST['plugin_name']) : '';
        
        if (empty($plugin_name)) {
            wp_send_json_error(array('message' => 'Plugin name is required'));
            return;
        }

        // Auto manage task/step
        $this->autoManageTaskStep('extract_plugin_structure', ['plugin_name' => $plugin_name]);

        $pluginsDir = $this->getPluginsDir();
        $plugin_name = trim($plugin_name, '/\\');
        $plugin_path = rtrim($pluginsDir, '/\\') . '/' . $plugin_name;
        $plugin_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $plugin_path);
        
        if (!is_dir($plugin_path)) {
            wp_send_json_error(array('message' => "Plugin directory not found: {$plugin_path}"));
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
        
        wp_send_json_success(array('result' => $output));
    }
}

new Plugitify_Tools_API();

