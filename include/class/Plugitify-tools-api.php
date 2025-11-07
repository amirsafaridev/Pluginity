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
        add_action('wp_ajax_plugitify_tool_toggle_wp_debug', array($this, 'handle_toggle_wp_debug'));
        add_action('wp_ajax_plugitify_tool_read_debug_log', array($this, 'handle_read_debug_log'));
        add_action('wp_ajax_plugitify_tool_check_wp_debug_status', array($this, 'handle_check_wp_debug_status'));
        add_action('wp_ajax_plugitify_tool_search_replace_in_file', array($this, 'handle_search_replace_in_file'));
        add_action('wp_ajax_plugitify_tool_update_chat_title', array($this, 'handle_update_chat_title'));
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
            $steps_table_safe = esc_sql($steps_table);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is escaped with esc_sql(), direct query needed for custom table
            $maxOrder = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(`order`) FROM {$steps_table_safe} WHERE task_id = %d",
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
            // Debug: error_log('Plugitify: Failed to auto manage task/step: ' . $e->getMessage());
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
     * Check if a path is within the Pluginity plugin directory (protected)
     * Returns true if the path is protected and should be blocked
     */
    private function isProtectedPath(string $path): bool {
        // Get the Pluginity plugin directory
        $plugitifyDir = defined('PLUGITIFY_DIR') ? PLUGITIFY_DIR : __DIR__ . '/../../';
        $plugitifyDir = realpath($plugitifyDir);
        
        if (!$plugitifyDir) {
            // Fallback: check by name if realpath fails
            $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            if (stripos($normalizedPath, 'plugitify') !== false || 
                stripos($normalizedPath, 'Pluginity') !== false) {
                return true;
            }
            return false;
        }
        
        // Normalize paths for comparison
        $pathReal = realpath($path);
        if ($pathReal) {
            // Check if the path is within the Pluginity directory
            if (strpos($pathReal, $plugitifyDir) === 0) {
                return true;
            }
        }
        
        // Also check by name as a fallback (case-insensitive)
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (stripos($normalizedPath, 'plugitify') !== false || 
            stripos($normalizedPath, 'Pluginity') !== false) {
            return true;
        }
        
        return false;
    }

    /**
     * Handle create_directory tool
     */
    public function handle_create_directory() {
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        $path = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
        
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
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($fullPath)) {
            wp_send_json_error(array('message' => 'ERROR: Cannot create directories in the Pluginity/plugitify plugin directory. This is a protected system plugin.'));
            return;
        }
        
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
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
        
        // Decode base64 content if it was encoded
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        if (isset($_POST['content_encoded']) && wp_unslash($_POST['content_encoded']) === '1') {
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
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($fullPath)) {
            wp_send_json_error(array('message' => 'ERROR: Cannot create files in the Pluginity/plugitify plugin directory. This is a protected system plugin.'));
            return;
        }
        
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
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        
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
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($fullPath)) {
            wp_send_json_error(array('message' => 'ERROR: Cannot delete files in the Pluginity/plugitify plugin directory. This is a protected system plugin.'));
            return;
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
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $path = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
        
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
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($fullPath)) {
            wp_send_json_error(array('message' => 'ERROR: Cannot delete directories in the Pluginity/plugitify plugin directory. This is a protected system plugin.'));
            return;
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
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        
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
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($fullPath)) {
            wp_send_json_error(array('message' => 'ERROR: Cannot read files in the Pluginity/plugitify plugin directory. This is a protected system plugin.'));
            return;
        }
        
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
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $line_number = isset($_POST['line_number']) ? intval($_POST['line_number']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $new_content = isset($_POST['new_content']) ? wp_unslash($_POST['new_content']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $line_count = isset($_POST['line_count']) ? intval($_POST['line_count']) : 1;
        
        // Decode base64 content if it was encoded
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        if (isset($_POST['new_content_encoded']) && wp_unslash($_POST['new_content_encoded']) === '1') {
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
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($fullPath)) {
            wp_send_json_error(array('message' => 'ERROR: Cannot edit files in the Pluginity/plugitify plugin directory. This is a protected system plugin.'));
            return;
        }
        
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
            wp_send_json_error(array('message' => "Line number must be greater than 0. File has {$total_lines} lines."));
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
        try {
            $write_result = @file_put_contents($fullPath, $new_content_full);
            if ($write_result === false) {
                // Try alternative method: use file handle
                $handle = @fopen($fullPath, 'w');
                if ($handle === false) {
                    wp_send_json_error(array('message' => "Failed to write file: {$fullPath}. Check file permissions."));
                    return;
                }
                $write_result = @fwrite($handle, $new_content_full);
                @fclose($handle);
                
                if ($write_result === false) {
                    wp_send_json_error(array('message' => "Failed to write file: {$fullPath}. Check file permissions."));
                    return;
                }
            }
            
            wp_send_json_success(array('result' => "Successfully {$action_message} in file: {$fullPath}"));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => "Error writing file: {$fullPath}. " . $e->getMessage()));
        }
    }

    /**
     * Handle list_plugins tool
     */
    public function handle_list_plugins() {
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : null;

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
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $plugin_file = isset($_POST['plugin_file']) ? sanitize_text_field(wp_unslash($_POST['plugin_file'])) : '';
        
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
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $plugin_name = isset($_POST['plugin_name']) ? sanitize_text_field(wp_unslash($_POST['plugin_name'])) : '';
        
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
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($plugin_path)) {
            wp_send_json_error(array('message' => 'ERROR: Cannot extract structure from the Pluginity/plugitify plugin directory. This is a protected system plugin.'));
            return;
        }
        
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

    /**
     * Handle toggle_wp_debug tool
     */
    public function handle_toggle_wp_debug() {
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $enable = isset($_POST['enable']) ? filter_var(wp_unslash($_POST['enable']), FILTER_VALIDATE_BOOLEAN) : null;
        
        if ($enable === null) {
            wp_send_json_error(array('message' => 'Enable parameter is required (true or false)'));
            return;
        }

        // Auto manage task/step
        $this->autoManageTaskStep('toggle_wp_debug', ['enable' => $enable]);

        // Get wp-config.php path
        $wpConfigPath = defined('ABSPATH') ? ABSPATH . 'wp-config.php' : '';
        
        if (!file_exists($wpConfigPath)) {
            wp_send_json_error(array('message' => "wp-config.php not found at: {$wpConfigPath}"));
            return;
        }
        
        if (!is_writable($wpConfigPath)) {
            wp_send_json_error(array('message' => "wp-config.php is not writable. Please check file permissions."));
            return;
        }
        
        // Read wp-config.php
        $content = file_get_contents($wpConfigPath);
        if ($content === false) {
            wp_send_json_error(array('message' => "Failed to read wp-config.php"));
            return;
        }
        
        // If enabling, clear the debug.log file first
        if ($enable) {
            $debugLogPath = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : ABSPATH . 'wp-content/debug.log';
            if (file_exists($debugLogPath)) {
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
        $result = file_put_contents($wpConfigPath, $content);
        if ($result === false) {
            wp_send_json_error(array('message' => "Failed to write to wp-config.php"));
            return;
        }
        
        $status = $enable ? 'enabled' : 'disabled';
        $message = "WP_DEBUG has been {$status} successfully.";
        
        if ($enable) {
            $message .= "\nDebug logs will be saved to wp-content/debug.log";
            $message .= "\nPrevious log file has been cleared.";
        }
        
        wp_send_json_success(array('result' => $message));
    }

    /**
     * Handle read_debug_log tool
     */
    public function handle_read_debug_log() {
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $lines = isset($_POST['lines']) ? intval($_POST['lines']) : 100; // Default last 100 lines
        
        // Auto manage task/step
        $this->autoManageTaskStep('read_debug_log', ['lines' => $lines]);

        // Get debug.log path
        $debugLogPath = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : ABSPATH . 'wp-content/debug.log';
        
        if (!file_exists($debugLogPath)) {
            wp_send_json_error(array('message' => "Debug log file not found at: {$debugLogPath}\n\nMake sure WP_DEBUG and WP_DEBUG_LOG are enabled in wp-config.php"));
            return;
        }
        
        if (!is_readable($debugLogPath)) {
            wp_send_json_error(array('message' => "Debug log file is not readable. Please check file permissions."));
            return;
        }
        
        // Get file size
        $fileSize = filesize($debugLogPath);
        if ($fileSize === 0) {
            wp_send_json_success(array('result' => "Debug log file is empty.\n\nNo errors have been logged yet."));
            return;
        }
        
        // Read the file
        $content = file_get_contents($debugLogPath);
        if ($content === false) {
            wp_send_json_error(array('message' => "Failed to read debug log file"));
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
        
        wp_send_json_success(array('result' => $output));
    }

    /**
     * Handle check_wp_debug_status tool
     */
    public function handle_check_wp_debug_status() {
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        
        // Auto manage task/step
        $this->autoManageTaskStep('check_wp_debug_status', []);

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
        
        wp_send_json_success(array('result' => $output));
    }

    /**
     * Handle search_replace_in_file tool
     */
    public function handle_search_replace_in_file() {
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $replacements = isset($_POST['replacements']) ? wp_unslash($_POST['replacements']) : '';
        
        // Decode replacements if it was JSON encoded
        if (is_string($replacements)) {
            $replacements = json_decode(stripslashes($replacements), true);
        }
        
        if (empty($file_path)) {
            wp_send_json_error(array('message' => 'File path is required'));
            return;
        }
        
        if (empty($replacements) || !is_array($replacements)) {
            wp_send_json_error(array('message' => 'Replacements array is required. Format: [{"search": "old text", "replace": "new text"}, ...]'));
            return;
        }

        // Auto manage task/step
        $this->autoManageTaskStep('search_replace_in_file', [
            'file_path' => $file_path,
            'replacements_count' => count($replacements)
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
        
        // Check if path is protected (Pluginity plugin directory)
        if ($this->isProtectedPath($fullPath)) {
            wp_send_json_error(array('message' => 'ERROR: Cannot edit files in the Pluginity/plugitify plugin directory. This is a protected system plugin.'));
            return;
        }
        
        if (!file_exists($fullPath)) {
            wp_send_json_error(array('message' => "File not found: {$fullPath}"));
            return;
        }
        
        if (!is_file($fullPath)) {
            wp_send_json_error(array('message' => "Path is not a file: {$fullPath}"));
            return;
        }
        
        // Read file content
        $content = file_get_contents($fullPath);
        if ($content === false) {
            wp_send_json_error(array('message' => "Failed to read file: {$fullPath}"));
            return;
        }
        
        $originalContent = $content;
        $totalReplacements = 0;
        $replacementDetails = [];
        
        // Process each replacement
        foreach ($replacements as $index => $replacement) {
            if (!isset($replacement['search']) || !isset($replacement['replace'])) {
                wp_send_json_error(array('message' => "Invalid replacement at index {$index}. Each replacement must have 'search' and 'replace' keys."));
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
        if ($content === $originalContent) {
            wp_send_json_error(array('message' => "No replacements were made. None of the search patterns were found in the file."));
            return;
        }
        
        // Write back to file
        $result = file_put_contents($fullPath, $content);
        if ($result === false) {
            wp_send_json_error(array('message' => "Failed to write to file: {$fullPath}"));
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
        
        wp_send_json_success(array('result' => $message));
    }

    /**
     * Handle update_chat_title tool
     */
    public function handle_update_chat_title() {
        $context = $this->validateRequest(); // Nonce verified in validateRequest()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in validateRequest()
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        
        if (empty($title)) {
            wp_send_json_error(array('message' => 'Title is required'));
            return;
        }
        
        if ($chat_id <= 0) {
            wp_send_json_error(array('message' => 'Valid chat_id is required'));
            return;
        }

        // Auto manage task/step
        $this->autoManageTaskStep('update_chat_title', ['title' => $title, 'chat_id' => $chat_id]);

        // Check if table exists
        if (!\Plugitify_DB::tableExists('chat_history')) {
            wp_send_json_error(array('message' => 'Database table does not exist'));
            return;
        }
        
        $user_id = get_current_user_id();
        
        // Update chat title
        $updated = \Plugitify_DB::table('chat_history')
            ->where('id', $chat_id)
            ->where('user_id', $user_id)
            ->update(array('title' => $title));

        if ($updated) {
            wp_send_json_success(array('result' => "Chat title updated successfully to: {$title}", 'title' => $title));
        } else {
            wp_send_json_error(array('message' => 'Failed to update chat title'));
        }
    }
}

new Plugitify_Tools_API();

