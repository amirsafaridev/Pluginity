<?php

namespace Plugitify\Classes;
class Plugitify_Panel {
    public function __construct() {
        add_filter('pre_handle_404', array($this, 'prevent_404'), 10, 2);
        add_filter('template_include', array($this, 'template_include'));
        add_action('wp_ajax_plugitify_send_message', array($this, 'handle_send_message'));
        add_action('wp_ajax_plugitify_get_chats', array($this, 'handle_get_chats'));
        add_action('wp_ajax_plugitify_save_chat', array($this, 'handle_save_chat'));
        add_action('wp_ajax_plugitify_delete_chat', array($this, 'handle_delete_chat'));
        add_action('wp_ajax_plugitify_get_messages', array($this, 'handle_get_messages'));
        add_action('wp_ajax_plugitify_save_ai_settings', array($this, 'handle_save_ai_settings'));
        add_action('wp_ajax_plugitify_get_ai_settings', array($this, 'handle_get_ai_settings'));
        // Removed: Task management is now handled by frontend using localStorage
        // add_action('wp_ajax_plugitify_get_tasks', array($this, 'handle_get_tasks'));
        // add_action('wp_ajax_plugitify_get_task_updates', array($this, 'handle_get_task_updates'));
    }

    public function prevent_404($preempt, $wp_query) {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return $preempt;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is used for routing only, not output
        $request_uri = trim(wp_unslash($_SERVER['REQUEST_URI']), '/');
        $home_path = trim(wp_parse_url(home_url(), PHP_URL_PATH), '/');
        
        if ($home_path) {
            $request_uri = str_replace($home_path, '', $request_uri);
            $request_uri = trim($request_uri, '/');
        }
        
        if ($request_uri === 'plugitify') {
            return true;
        }
        
        return $preempt;
    }

    public function template_include($template) {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return $template;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is used for routing only, not output
        $request_uri = trim(wp_unslash($_SERVER['REQUEST_URI']), '/');
        $home_path = trim(wp_parse_url(home_url(), PHP_URL_PATH), '/');
        
        if ($home_path) {
            $request_uri = str_replace($home_path, '', $request_uri);
            $request_uri = trim($request_uri, '/');
        }
        
        if ($request_uri === 'plugitify') {
            if(!is_user_logged_in() || !current_user_can('manage_options')) {
                wp_redirect(home_url('/wp-login.php'));
                exit;
            }
            status_header(200);
            
            return PLUGITIFY_DIR . 'template/panel/layout.php';
        }
        
        return $template;
    }

    /**
     * Handle AJAX request for sending chat messages
     */
    public function handle_send_message() {
        // Verify nonce
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified, not sanitized
        if (!isset($_POST['nonce']) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'plugitify_chat_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        // Check user permissions
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        // Get message - use sanitize_textarea_field to preserve whitespace and line breaks
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        $role = isset($_POST['role']) ? sanitize_text_field(wp_unslash($_POST['role'])) : 'user'; // Allow 'user' or 'assistant'

        if (empty($message)) {
            wp_send_json_error(array('message' => 'Message is required'));
            return;
        }

        try {
            $user_id = get_current_user_id();
            $start_time = microtime(true);
            
            // Save message to database (can be user or assistant message)
            $user_message_timestamp = null;
            if ($chat_id > 0) {
                $inserted = \Plugitify_DB::table('messages')->insert(array(
                    'chat_history_id' => $chat_id,
                    'role' => $role, // 'user' or 'assistant'
                    'content' => $message
                ));
                
                // Get the timestamp of the inserted message to use as last_update (only for user messages)
                if ($inserted && $role === 'user') {
                    $user_msg = \Plugitify_DB::table('messages')
                        ->where('chat_history_id', $chat_id)
                        ->where('role', 'user')
                        ->orderBy('created_at', 'DESC')
                        ->first();
                    
                    if ($user_msg) {
                        $user_msg_created_at = is_object($user_msg) ? ($user_msg->created_at ?? null) : ($user_msg['created_at'] ?? null);
                        $user_message_timestamp = $user_msg_created_at ? strtotime($user_msg_created_at) : 0;
                    }
                }
            }

            // Message is now processed by frontend Agent Framework
            // Just save the message and return success
            wp_send_json_success(array(
                'processing' => true,
                'chat_id' => $chat_id,
                'message' => 'Message received',
                'user_message_timestamp' => $user_message_timestamp // Return timestamp for frontend to use as last_update
            ));
        } catch (\Exception $e) {
            // Log error safely
            try {
                if (class_exists('\Plugitify_Chat_Logs')) {
                    \Plugitify_Chat_Logs::log(array(
                        'action' => 'error',
                        'chat_id' => isset($chat_id) ? $chat_id : 0,
                        'user_id' => isset($user_id) ? $user_id : get_current_user_id(),
                        'message' => 'Error processing message: ' . $e->getMessage(),
                        'status' => 'error',
                        'metadata' => array(
                            'exception' => get_class($e)
                        )
                    ));
                }
            } catch (\Exception $log_error) {
                // If logging fails, don't break the flow
                // Debug: error_log('Failed to log chat error: ' . $log_error->getMessage());
            }
            
            wp_send_json_error(array(
                'message' => 'Error processing message: ' . $e->getMessage()
            ));
        }
    }

   

    /**
     * Handle AJAX request for getting all chats
     */
    public function handle_get_chats() {
        // Verify nonce
        $nonce = '';
        if (isset($_GET['nonce'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- Nonce is verified via wp_verify_nonce(), not sanitized
            $nonce = wp_unslash($_GET['nonce']);
        } elseif (isset($_POST['nonce'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified, not sanitized
            $nonce = wp_unslash($_POST['nonce']);
        }
        if (empty($nonce) || !wp_verify_nonce($nonce, 'plugitify_chat_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            // Check if table exists
            if (!\Plugitify_DB::tableExists('chat_history')) {
                wp_send_json_success(array('chats' => array())); // Return empty array if table doesn't exist yet
                return;
            }
            
            $user_id = get_current_user_id();
            $chats = \Plugitify_DB::table('chat_history')
                ->where('user_id', $user_id)
                ->orderBy('created_at', 'DESC')
                ->get();

            // Convert to array format
            $chats_array = array();
            if ($chats) {
                // Handle both array and object results
                if (!is_array($chats)) {
                    $chats = array($chats);
                }
                
                foreach ($chats as $chat) {
                    // Handle both object and array format
                    $chat_id = is_object($chat) ? $chat->id : (isset($chat['id']) ? $chat['id'] : 0);
                    $chat_title = is_object($chat) ? $chat->title : (isset($chat['title']) ? $chat['title'] : null);
                    $chat_created = is_object($chat) ? $chat->created_at : (isset($chat['created_at']) ? $chat['created_at'] : null);
                    
                    $chats_array[] = array(
                        'id' => intval($chat_id),
                        'title' => $chat_title ? $chat_title : 'New Chat',
                        'createdAt' => $chat_created,
                        'messages' => array() // Will be loaded separately
                    );
                }
            }

            wp_send_json_success(array('chats' => $chats_array));
        } catch (\Exception $e) {
            // Log error for debugging
            // Debug: error_log('Plugitify get_chats error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => 'Error loading chats: ' . $e->getMessage()));
        } catch (\Error $e) {
            // Catch fatal errors too
            // Debug: error_log('Plugitify get_chats fatal error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Handle AJAX request for saving/creating a chat
     */
    public function handle_save_chat() {
        // Verify nonce
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified, not sanitized
        $nonce = isset($_POST['nonce']) ? wp_unslash($_POST['nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'plugitify_chat_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            // Helper function to safely log errors
            $log_error = function($action, $message, $metadata = array()) {
                try {
                    if (class_exists('\Plugitify_Chat_Logs')) {
                        \Plugitify_Chat_Logs::log(array(
                            'action' => $action,
                            'chat_id' => isset($GLOBALS['current_chat_id']) ? $GLOBALS['current_chat_id'] : 0,
                            'user_id' => get_current_user_id(),
                            'message' => $message,
                            'status' => 'error',
                            'metadata' => $metadata
                        ));
                    }
                } catch (\Exception $e) {
                    // If logging fails, don't break the flow
                    // Debug: error_log('Failed to log chat error: ' . $e->getMessage());
                }
            };
            
            // Check if Plugitify_DB class exists
            if (!class_exists('\Plugitify_DB')) {
                $error_msg = 'Plugitify_DB class not found';
                $log_error('error', $error_msg, array('exception' => 'ClassNotFoundException'));
                wp_send_json_error(array('message' => $error_msg));
                return;
            }
            
            // Check if table exists
            if (!\Plugitify_DB::tableExists('chat_history')) {
                $error_msg = 'Database table does not exist. Please run migrations.';
                $log_error('error', $error_msg);
                wp_send_json_error(array('message' => $error_msg));
                return;
            }
            
            $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'New Chat';
            $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
            $user_id = get_current_user_id();

            if ($chat_id > 0) {
                // Update existing chat
                $updated = \Plugitify_DB::table('chat_history')
                    ->where('id', $chat_id)
                    ->where('user_id', $user_id)
                    ->update(array('title' => $title));

                if ($updated) {
                    wp_send_json_success(array('chat_id' => $chat_id, 'title' => $title));
                } else {
                    wp_send_json_error(array('message' => 'Failed to update chat'));
                }
            } else {
                // Create new chat
                $new_chat_id = \Plugitify_DB::table('chat_history')->insert(array(
                    'user_id' => $user_id,
                    'title' => $title,
                    'status' => 'active'
                ));

                if ($new_chat_id) {
                    // Don't log successful chat creation
                    wp_send_json_success(array('chat_id' => $new_chat_id, 'title' => $title));
                } else {
                    global $wpdb;
                    $error = $wpdb->last_error ? $wpdb->last_error : 'Unknown error';
                    
                    // Log error
                    $log_error('error', 'Failed to create chat: ' . $error, array('db_error' => $error));
                    
                    // Debug: error_log('Plugitify insert chat error: ' . $error);
                    wp_send_json_error(array('message' => 'Failed to create chat: ' . $error));
                }
            }
        } catch (\Exception $e) {
            $error_msg = 'Error saving chat: ' . $e->getMessage();
            
            // Log error safely
            try {
                if (class_exists('\Plugitify_Chat_Logs')) {
                    \Plugitify_Chat_Logs::log(array(
                        'action' => 'error',
                        'chat_id' => isset($chat_id) ? $chat_id : 0,
                        'user_id' => isset($user_id) ? $user_id : get_current_user_id(),
                        'message' => $error_msg,
                        'status' => 'error',
                        'metadata' => array(
                            'exception' => get_class($e),
                            'trace' => substr($e->getTraceAsString(), 0, 500)
                        )
                    ));
                }
            } catch (\Exception $log_error) {
                // If logging fails, don't break the flow
                // Debug: error_log('Failed to log chat error: ' . $log_error->getMessage());
            }
            
            // Debug: error_log('Plugitify save_chat error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => $error_msg));
        } catch (\Error $e) {
            $error_msg = 'Fatal error: ' . $e->getMessage();
            
            // Log fatal error safely
            try {
                if (class_exists('\Plugitify_Chat_Logs')) {
                    \Plugitify_Chat_Logs::log(array(
                        'action' => 'error',
                        'chat_id' => isset($chat_id) ? $chat_id : 0,
                        'user_id' => isset($user_id) ? $user_id : get_current_user_id(),
                        'message' => $error_msg,
                        'status' => 'error',
                        'metadata' => array(
                            'exception' => get_class($e),
                            'trace' => substr($e->getTraceAsString(), 0, 500)
                        )
                    ));
                }
            } catch (\Exception $log_error) {
                // If logging fails, don't break the flow
                // Debug: error_log('Failed to log chat fatal error: ' . $log_error->getMessage());
            }
            
            // Debug: error_log('Plugitify save_chat fatal error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => $error_msg));
        }
    }

    /**
     * Handle AJAX request for deleting a chat
     */
    public function handle_delete_chat() {
        // Verify nonce
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified, not sanitized
        $nonce = isset($_POST['nonce']) ? wp_unslash($_POST['nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'plugitify_chat_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
            $user_id = get_current_user_id();

            if ($chat_id <= 0) {
                wp_send_json_error(array('message' => 'Invalid chat ID'));
                return;
            }

            // Verify chat belongs to user
            $chat = \Plugitify_DB::table('chat_history')
                ->where('id', $chat_id)
                ->where('user_id', $user_id)
                ->first();

            if (!$chat) {
                wp_send_json_error(array('message' => 'Chat not found'));
                return;
            }

            // Get chat info before deletion for logging
            $chat_title = $chat->title ? $chat->title : 'Unknown';
            
            // Delete messages first
            \Plugitify_DB::table('messages')
                ->where('chat_history_id', $chat_id)
                ->delete();

            // Delete chat
            \Plugitify_DB::table('chat_history')
                ->where('id', $chat_id)
                ->delete();

            // Don't log successful chat deletion
            wp_send_json_success();
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => 'Error deleting chat: ' . $e->getMessage()));
        }
    }

    /**
     * Handle AJAX request for getting messages of a chat
     */
    public function handle_get_messages() {
        // Verify nonce
        $nonce = '';
        if (isset($_GET['nonce'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- Nonce is verified via wp_verify_nonce(), not sanitized
            $nonce = wp_unslash($_GET['nonce']);
        } elseif (isset($_POST['nonce'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified, not sanitized
            $nonce = wp_unslash($_POST['nonce']);
        }
        if (empty($nonce) || !wp_verify_nonce($nonce, 'plugitify_chat_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            $chat_id = isset($_GET['chat_id']) ? intval($_GET['chat_id']) : 0;
            $user_id = get_current_user_id();

            if ($chat_id <= 0) {
                wp_send_json_error(array('message' => 'Invalid chat ID'));
                return;
            }

            // Verify chat belongs to user
            $chat = \Plugitify_DB::table('chat_history')
                ->where('id', $chat_id)
                ->where('user_id', $user_id)
                ->first();

            if (!$chat) {
                wp_send_json_error(array('message' => 'Chat not found'));
                return;
            }

            // Get messages
            $messages = \Plugitify_DB::table('messages')
                ->where('chat_history_id', $chat_id)
                ->orderBy('created_at', 'ASC')
                ->get();

            // Convert to array format
            $messages_array = array();
            if ($messages) {
                foreach ($messages as $msg) {
                    $messages_array[] = array(
                        'id' => intval($msg->id),
                        'role' => $msg->role,
                        'content' => $msg->content,
                        'timestamp' => $msg->created_at
                    );
                }
            }

            wp_send_json_success(array('messages' => $messages_array));
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => 'Error loading messages: ' . $e->getMessage()));
        }
    }

    /**
     * Handle AJAX request for saving AI settings
     */
    public function handle_save_ai_settings() {
        // Verify nonce
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified, not sanitized
        $nonce = isset($_POST['nonce']) ? wp_unslash($_POST['nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'plugitify_chat_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            $apiKey = isset($_POST['apiKey']) ? sanitize_text_field(wp_unslash($_POST['apiKey'])) : '';
            $model = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : 'deepseek-chat';

            // Validate model
            $allowed_models = array(
                // OpenAI
                'gpt-4', 'gpt-4-turbo', 'gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo',
                // Claude
                'claude-3-opus-20240229', 'claude-3-sonnet-20240229', 'claude-3-haiku-20240307', 'claude-3-5-sonnet-20240620',
                // Gemini
                'gemini-pro', 'gemini-pro-vision', 'gemini-1.5-pro', 'gemini-1.5-flash',
                // Deepseek
                'deepseek-chat', 'deepseek-coder'
            );

            if (!in_array($model, $allowed_models)) {
                wp_send_json_error(array('message' => 'Invalid model'));
                return;
            }

            // Save settings to WordPress options (only API key and model)
            $settings = array(
                'apiKey' => $apiKey,
                'model' => $model
            );

            // Save with autoload to ensure it's loaded on every page load
            $saved = update_option('plugitify_ai_settings', $settings, true);
            
            // Verify the save immediately
            $verify = get_option('plugitify_ai_settings', array());
            
            // Debug: error_log('Plugitify: Saving AI settings - apiKey: ' . ($apiKey ? '***' : '(empty)') . ', model: ' . $model);
            // Debug: error_log('Plugitify: Settings saved: ' . ($saved ? 'true' : 'false'));
            // Debug: error_log('Plugitify: Verified settings: ' . print_r($verify, true));
            
            // Double check - if verification failed, try again
            if (empty($verify) || !isset($verify['model'])) {
                // Debug: error_log('Plugitify: WARNING - Settings verification failed, retrying...');
                delete_option('plugitify_ai_settings');
                $saved = add_option('plugitify_ai_settings', $settings, '', 'yes');
                $verify = get_option('plugitify_ai_settings', array());
                // Debug: error_log('Plugitify: Retry result: ' . print_r($verify, true));
            }

            wp_send_json_success(array(
                'message' => 'Settings saved successfully',
                'data' => $settings
            ));
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => 'Error saving settings: ' . $e->getMessage()));
        }
    }

    /**
     * Handle AJAX request for getting AI settings
     */
    public function handle_get_ai_settings() {
        // Verify nonce
        $nonce = '';
        if (isset($_GET['nonce'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- Nonce is verified via wp_verify_nonce(), not sanitized
            $nonce = wp_unslash($_GET['nonce']);
        } elseif (isset($_POST['nonce'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified, not sanitized
            $nonce = wp_unslash($_POST['nonce']);
        }
        if (empty($nonce) || !wp_verify_nonce($nonce, 'plugitify_chat_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            // Get settings from WordPress options
            $settings = get_option('plugitify_ai_settings', array());
            
            // Debug: error_log('Plugitify: Loading AI settings - Raw: ' . print_r($settings, true));
            // Debug: error_log('Plugitify: Loading AI settings - apiKey exists: ' . (isset($settings['apiKey']) ? 'yes' : 'no'));
            // Debug: error_log('Plugitify: Loading AI settings - model exists: ' . (isset($settings['model']) ? 'yes' : 'no'));
            // if (isset($settings['apiKey'])) {
            //     error_log('Plugitify: Loading AI settings - apiKey length: ' . strlen($settings['apiKey']));
            // }
            // if (isset($settings['model'])) {
            //     error_log('Plugitify: Loading AI settings - model: ' . $settings['model']);
            // }

            // Set defaults if not set
            if (empty($settings) || !is_array($settings)) {
                // Debug: error_log('Plugitify: Settings empty or not array, using defaults');
                $settings = array(
                    'apiKey' => '',
                    'model' => 'deepseek-chat'
                );
            }
            
            // Ensure both keys exist
            if (!isset($settings['apiKey'])) {
                $settings['apiKey'] = '';
            }
            if (!isset($settings['model'])) {
                $settings['model'] = 'deepseek-chat';
            }
            
            // Debug: error_log('Plugitify: Final settings to return: ' . print_r($settings, true));

            wp_send_json_success(array('data' => $settings));
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => 'Error loading settings: ' . $e->getMessage()));
        }
    }

    /**
     * Removed: Task management methods
     * Task management is now handled entirely by frontend using localStorage.
     * Tasks are created when tools are executed and stored in browser localStorage.
     * 
     * Methods removed:
     * - handle_get_tasks()
     * - handle_get_task_updates()
     * - formatTaskStatus()
     */
}
new Plugitify_Panel();