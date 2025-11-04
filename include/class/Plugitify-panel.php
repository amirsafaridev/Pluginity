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
        add_action('wp_ajax_plugitify_get_tasks', array($this, 'handle_get_tasks'));
        add_action('wp_ajax_plugitify_get_task_updates', array($this, 'handle_get_task_updates'));
        add_action('wp_ajax_plugitify_process_message', array($this, 'handle_process_message'));
        // Register WP Cron hook for background processing
        add_action('plugitify_process_message_cron', array($this, 'process_message_cron'), 10, 3);
    }

    public function prevent_404($preempt, $wp_query) {
        $request_uri = trim($_SERVER['REQUEST_URI'], '/');
        $home_path = trim(parse_url(home_url(), PHP_URL_PATH), '/');
        
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
        $request_uri = trim($_SERVER['REQUEST_URI'], '/');
        $home_path = trim(parse_url(home_url(), PHP_URL_PATH), '/');
        
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
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'plugitify_chat_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        // Check user permissions
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        // Get message
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;

        if (empty($message)) {
            wp_send_json_error(array('message' => 'Message is required'));
            return;
        }

        try {
            $user_id = get_current_user_id();
            $start_time = microtime(true);
            
            // Save user message to database
            $user_message_timestamp = null;
            if ($chat_id > 0) {
                $inserted = \Plugitify_DB::table('messages')->insert(array(
                    'chat_history_id' => $chat_id,
                    'role' => 'user',
                    'content' => $message
                ));
                
                // Get the timestamp of the inserted message to use as last_update
                if ($inserted) {
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

            // Check if PlugitifyAgent is available
            if (!class_exists('\App\Neuron\PlugitifyAgent')) {
                
                wp_send_json_error(array('message' => 'AI Agent not available'));
                return;
            }

            // Instead of processing here, trigger async background processing
            // This prevents timeout issues
            $this->trigger_background_processing($chat_id, $message, $user_id);

            // Return immediately - processing happens in background
            wp_send_json_success(array(
                'processing' => true,
                'chat_id' => $chat_id,
                'message' => 'Message received, processing in background...',
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
                error_log('Failed to log chat error: ' . $log_error->getMessage());
            }
            
            wp_send_json_error(array(
                'message' => 'Error processing message: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Trigger background processing of message using WP Cron
     */
    private function trigger_background_processing($chat_id, $message, $user_id) {
        // Log: Triggering background processing
       
        
        // Clear any existing scheduled event for this chat (to avoid duplicates)
        $hook = 'plugitify_process_message_cron';
        $args = array($chat_id, $message, $user_id);
        wp_clear_scheduled_hook($hook, $args);
        
        // Schedule the event to run immediately (next tick)
        $scheduled = wp_schedule_single_event(time(), $hook, $args);
        
        // Force cron to run on next request if DISABLE_WP_CRON is false
        // Otherwise it will run on the next page load
        if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
            // Spawn a cron request if possible
            spawn_cron();
        }
        
        // Log: Cron event scheduled
       
    }
    
    /**
     * WP Cron callback to process message in background
     * 
     * @param int $chat_id
     * @param string $message
     * @param int $user_id
     */
    public function process_message_cron($chat_id, $message, $user_id) {
        // Log: Cron started
       

        // Set execution time limit for long-running processes
        set_time_limit(300); // 5 minutes
        ignore_user_abort(true);

        try {
            // Log: Setting agent context
           
            
            // Set agent context for task tracking
            \App\Neuron\PlugitifyAgent::setContext($chat_id, $user_id);
            
          
            
            // Create agent instance and send message
            $agent = \App\Neuron\PlugitifyAgent::make();
            
            // Log: Agent created, starting chat
          
            
            // Load previous messages from database (including the new message that was already saved)
            $chatMessages = array();
            if ($chat_id > 0 && \Plugitify_DB::tableExists('messages')) {
                $previousMessages = \Plugitify_DB::table('messages')
                    ->where('chat_history_id', $chat_id)
                    ->orderBy('created_at', 'ASC')
                    ->get();
                
                if ($previousMessages) {
                    // Ensure it's an array
                    if (!is_array($previousMessages)) {
                        $previousMessages = array($previousMessages);
                    }
                    
                    foreach ($previousMessages as $msg) {
                        $msgRole = is_object($msg) ? $msg->role : $msg['role'];
                        $msgContent = is_object($msg) ? $msg->content : $msg['content'];
                        
                        // Convert database messages to Message objects
                        if ($msgRole === 'user') {
                            $chatMessages[] = new \NeuronAI\Chat\Messages\UserMessage($msgContent);
                        } elseif ($msgRole === 'assistant') {
                            $chatMessages[] = new \NeuronAI\Chat\Messages\AssistantMessage($msgContent);
                        } elseif ($msgRole === 'system') {
                            $chatMessages[] = new \NeuronAI\Chat\Messages\Message(\NeuronAI\Chat\Messages\Message::ROLE_SYSTEM, $msgContent);
                        }
                    }
                }
            }
            error_log('Plugitify: chatMessages ' . print_r($chatMessages, true));
            // If no messages found in database, add the new message
            if (empty($chatMessages)) {
                $chatMessages[] = new \NeuronAI\Chat\Messages\UserMessage($message);
            }
            
            // Send all messages to agent
            $response = $agent->chat($chatMessages);

            // Log: Chat completed
            try {
                if (class_exists('\Plugitify_Chat_Logs')) {
                    \Plugitify_Chat_Logs::log(array(
                        'action' => 'agent_chat_complete',
                        'chat_id' => $chat_id,
                        'user_id' => $user_id,
                        'message' => 'Agent chat completed successfully',
                        'status' => 'success',
                        'metadata' => array(
                            'response_length' => strlen($response->getContent())
                        )
                    ));
                }
            } catch (\Exception $e) {
                // Silent fail
            }

            // Get response content
            $responseContent = $response->getContent();

            // Save bot response to database
            if ($chat_id > 0) {
                $saved = \Plugitify_DB::table('messages')->insert(array(
                    'chat_history_id' => $chat_id,
                    'role' => 'assistant',
                    'content' => $responseContent
                ));
                
                // Log: Response saved
                try {
                    if (class_exists('\Plugitify_Chat_Logs')) {
                        \Plugitify_Chat_Logs::log(array(
                            'action' => 'response_saved',
                            'chat_id' => $chat_id,
                            'user_id' => $user_id,
                            'message' => $saved ? 'Bot response saved to database' : 'Failed to save bot response',
                            'status' => $saved ? 'success' : 'error'
                        ));
                    }
                } catch (\Exception $e) {
                    // Silent fail
                }
            }

        } catch (\Exception $e) {
            // Log error
            error_log('Plugitify cron processing error: ' . $e->getMessage());
            
            // Log exception details
            try {
                if (class_exists('\Plugitify_Chat_Logs')) {
                    \Plugitify_Chat_Logs::log(array(
                        'action' => 'background_error',
                        'chat_id' => $chat_id,
                        'user_id' => $user_id,
                        'message' => 'WP Cron processing error: ' . $e->getMessage(),
                        'status' => 'error',
                        'metadata' => array(
                            'exception' => get_class($e),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => substr($e->getTraceAsString(), 0, 500)
                        )
                    ));
                }
            } catch (\Exception $log_error) {
                error_log('Plugitify: Failed to log error: ' . $log_error->getMessage());
            }
            
            // Save error message to chat
            if ($chat_id > 0) {
                try {
                    \Plugitify_DB::table('messages')->insert(array(
                        'chat_history_id' => $chat_id,
                        'role' => 'assistant',
                        'content' => 'Sorry, I encountered an error processing your request: ' . $e->getMessage()
                    ));
                } catch (\Exception $save_error) {
                    error_log('Plugitify: Failed to save error message: ' . $save_error->getMessage());
                }
            }
        }
    }

    /**
     * Handle background message processing
     */
    public function handle_process_message() {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';

        // Log: Background processing started
        try {
            if (class_exists('\Plugitify_Chat_Logs')) {
                \Plugitify_Chat_Logs::log(array(
                    'action' => 'background_start',
                    'chat_id' => $chat_id,
                    'user_id' => $user_id,
                    'message' => 'Background message processing started',
                    'status' => 'info',
                    'metadata' => array(
                        'has_nonce' => !empty($nonce),
                        'has_message' => !empty($message)
                    )
                ));
            }
        } catch (\Exception $e) {
            error_log('Plugitify: Failed to log background start: ' . $e->getMessage());
        }

        if (empty($nonce) || !wp_verify_nonce($nonce, 'plugitify_background_' . $chat_id . '_' . $user_id)) {
            // Log: Invalid nonce
            try {
                if (class_exists('\Plugitify_Chat_Logs')) {
                    \Plugitify_Chat_Logs::log(array(
                        'action' => 'background_error',
                        'chat_id' => $chat_id,
                        'user_id' => $user_id,
                        'message' => 'Invalid nonce for background processing',
                        'status' => 'error'
                    ));
                }
            } catch (\Exception $e) {
                // Silent fail
            }
            error_log('Plugitify: Invalid nonce for background processing');
            exit;
        }

        // Set execution time limit for long-running processes
        set_time_limit(300); // 5 minutes
        ignore_user_abort(true);

        try {
            // Set agent context for task tracking
            \App\Neuron\PlugitifyAgent::setContext($chat_id, $user_id);
            
            // Log: Creating agent instance
            try {
                if (class_exists('\Plugitify_Chat_Logs')) {
                    \Plugitify_Chat_Logs::log(array(
                        'action' => 'agent_create',
                        'chat_id' => $chat_id,
                        'user_id' => $user_id,
                        'message' => 'Creating agent instance',
                        'status' => 'info'
                    ));
                }
            } catch (\Exception $e) {
                // Silent fail
            }
            
            // Create agent instance and send message
            $agent = \App\Neuron\PlugitifyAgent::make();
            
            // Log: Agent created, starting chat
            try {
                if (class_exists('\Plugitify_Chat_Logs')) {
                    \Plugitify_Chat_Logs::log(array(
                        'action' => 'agent_chat_start',
                        'chat_id' => $chat_id,
                        'user_id' => $user_id,
                        'message' => 'Starting agent chat',
                        'status' => 'info',
                        'metadata' => array(
                            'message_preview' => substr($message, 0, 100)
                        )
                    ));
                }
            } catch (\Exception $e) {
                // Silent fail
            }
            
            // Load previous messages from database (including the new message if it was already saved)
            $chatMessages = array();
            if ($chat_id > 0 && \Plugitify_DB::tableExists('messages')) {
                $previousMessages = \Plugitify_DB::table('messages')
                    ->where('chat_history_id', $chat_id)
                    ->orderBy('created_at', 'ASC')
                    ->get();
                
                if ($previousMessages) {
                    // Ensure it's an array
                    if (!is_array($previousMessages)) {
                        $previousMessages = array($previousMessages);
                    }
                    
                    foreach ($previousMessages as $msg) {
                        $msgRole = is_object($msg) ? $msg->role : $msg['role'];
                        $msgContent = is_object($msg) ? $msg->content : $msg['content'];
                        
                        // Convert database messages to Message objects
                        if ($msgRole === 'user') {
                            $chatMessages[] = new \NeuronAI\Chat\Messages\UserMessage($msgContent);
                        } elseif ($msgRole === 'assistant') {
                            $chatMessages[] = new \NeuronAI\Chat\Messages\AssistantMessage($msgContent);
                        } elseif ($msgRole === 'system') {
                            $chatMessages[] = new \NeuronAI\Chat\Messages\Message(\NeuronAI\Chat\Messages\Message::ROLE_SYSTEM, $msgContent);
                        }
                    }
                }
            }
            error_log('Plugitify: chatMessages ' . print_r($chatMessages, true));
            // If no messages found in database, add the new message
            if (empty($chatMessages)) {
                $chatMessages[] = new \NeuronAI\Chat\Messages\UserMessage($message);
            }
            
            // Send all messages to agent
            $response = $agent->chat($chatMessages);

            // Log: Chat completed
            try {
                if (class_exists('\Plugitify_Chat_Logs')) {
                    \Plugitify_Chat_Logs::log(array(
                        'action' => 'agent_chat_complete',
                        'chat_id' => $chat_id,
                        'user_id' => $user_id,
                        'message' => 'Agent chat completed successfully',
                        'status' => 'success',
                        'metadata' => array(
                            'response_length' => strlen($response->getContent())
                        )
                    ));
                }
            } catch (\Exception $e) {
                // Silent fail
            }

            // Get response content
            $responseContent = $response->getContent();

            // Save bot response to database
            if ($chat_id > 0) {
                $saved = \Plugitify_DB::table('messages')->insert(array(
                    'chat_history_id' => $chat_id,
                    'role' => 'assistant',
                    'content' => $responseContent
                ));
                
                // Log: Response saved
                try {
                    if (class_exists('\Plugitify_Chat_Logs')) {
                        \Plugitify_Chat_Logs::log(array(
                            'action' => 'response_saved',
                            'chat_id' => $chat_id,
                            'user_id' => $user_id,
                            'message' => $saved ? 'Bot response saved to database' : 'Failed to save bot response',
                            'status' => $saved ? 'success' : 'error'
                        ));
                    }
                } catch (\Exception $e) {
                    // Silent fail
                }
            }

            // No response needed - this is background processing
        } catch (\Exception $e) {
            // Log error
            error_log('Plugitify background processing error: ' . $e->getMessage());
            
            // Log exception details
            try {
                if (class_exists('\Plugitify_Chat_Logs')) {
                    \Plugitify_Chat_Logs::log(array(
                        'action' => 'background_error',
                        'chat_id' => $chat_id,
                        'user_id' => $user_id,
                        'message' => 'Background processing error: ' . $e->getMessage(),
                        'status' => 'error',
                        'metadata' => array(
                            'exception' => get_class($e),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => substr($e->getTraceAsString(), 0, 500)
                        )
                    ));
                }
            } catch (\Exception $log_error) {
                error_log('Plugitify: Failed to log error: ' . $log_error->getMessage());
            }
            
            // Save error message to chat
            if ($chat_id > 0) {
                try {
                    \Plugitify_DB::table('messages')->insert(array(
                        'chat_history_id' => $chat_id,
                        'role' => 'assistant',
                        'content' => 'Sorry, I encountered an error processing your request: ' . $e->getMessage()
                    ));
                } catch (\Exception $save_error) {
                    error_log('Plugitify: Failed to save error message: ' . $save_error->getMessage());
                }
            }
        }
        
        // Exit to prevent any output
        exit;
    }

    /**
     * Handle AJAX request for getting all chats
     */
    public function handle_get_chats() {
        // Verify nonce
        $nonce = isset($_GET['nonce']) ? $_GET['nonce'] : (isset($_POST['nonce']) ? $_POST['nonce'] : '');
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
            error_log('Plugitify get_chats error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => 'Error loading chats: ' . $e->getMessage()));
        } catch (\Error $e) {
            // Catch fatal errors too
            error_log('Plugitify get_chats fatal error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Handle AJAX request for saving/creating a chat
     */
    public function handle_save_chat() {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
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
                    error_log('Failed to log chat error: ' . $e->getMessage());
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
            
            $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : 'New Chat';
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
                    
                    error_log('Plugitify insert chat error: ' . $error);
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
                error_log('Failed to log chat error: ' . $log_error->getMessage());
            }
            
            error_log('Plugitify save_chat error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
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
                error_log('Failed to log chat fatal error: ' . $log_error->getMessage());
            }
            
            error_log('Plugitify save_chat fatal error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => $error_msg));
        }
    }

    /**
     * Handle AJAX request for deleting a chat
     */
    public function handle_delete_chat() {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
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
        $nonce = isset($_GET['nonce']) ? $_GET['nonce'] : (isset($_POST['nonce']) ? $_POST['nonce'] : '');
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
     * Handle AJAX request for getting tasks and stages for a chat
     */
    public function handle_get_tasks() {
        // Verify nonce
        $nonce = isset($_GET['nonce']) ? $_GET['nonce'] : (isset($_POST['nonce']) ? $_POST['nonce'] : '');
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

            // Check if tables exist
            if (!\Plugitify_DB::tableExists('tasks') || !\Plugitify_DB::tableExists('steps')) {
                wp_send_json_success(array('tasks' => array()));
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

            // Get tasks for this chat
            $tasks = \Plugitify_DB::table('tasks')
                ->where('chat_history_id', $chat_id)
                ->where('user_id', $user_id)
                ->orderBy('created_at', 'ASC')
                ->get();

            $tasks_array = array();
            if ($tasks) {
                foreach ($tasks as $task) {
                    $task_id = is_object($task) ? $task->id : $task['id'];
                    
                    // Get steps for this task
                    // Use raw query for 'order' column since it's a MySQL reserved word
                    global $wpdb;
                    $steps_table = \Plugitify_DB::getFullTableName('steps');
                    $steps_query = $wpdb->prepare(
                        "SELECT * FROM {$steps_table} WHERE task_id = %d ORDER BY `order` ASC, created_at ASC",
                        $task_id
                    );
                    $steps = $wpdb->get_results($steps_query);

                    $steps_array = array();
                    if ($steps) {
                        foreach ($steps as $step) {
                            $steps_array[] = array(
                                'id' => intval(is_object($step) ? $step->id : $step['id']),
                                'step_name' => is_object($step) ? $step->step_name : $step['step_name'],
                                'step_type' => is_object($step) ? $step->step_type : $step['step_type'],
                                'order' => intval(is_object($step) ? $step->order : $step['order']),
                                'status' => is_object($step) ? $step->status : $step['status'],
                                'content' => is_object($step) ? $step->content : $step['content'],
                                'result' => is_object($step) ? $step->result : $step['result'],
                                'error_message' => is_object($step) ? $step->error_message : $step['error_message'],
                                'duration' => intval(is_object($step) ? $step->duration : $step['duration']),
                                'created_at' => is_object($step) ? $step->created_at : $step['created_at'],
                                'updated_at' => is_object($step) ? $step->updated_at : $step['updated_at']
                            );
                        }
                    }

                    $tasks_array[] = array(
                        'id' => intval($task_id),
                        'task_name' => is_object($task) ? $task->task_name : $task['task_name'],
                        'task_type' => is_object($task) ? $task->task_type : $task['task_type'],
                        'description' => is_object($task) ? $task->description : $task['description'],
                        'requirements' => is_object($task) ? $task->requirements : $task['requirements'],
                        'status' => is_object($task) ? $task->status : $task['status'],
                        'progress' => intval(is_object($task) ? $task->progress : $task['progress']),
                        'result' => is_object($task) ? $task->result : $task['result'],
                        'error_message' => is_object($task) ? $task->error_message : $task['error_message'],
                        'created_at' => is_object($task) ? $task->created_at : $task['created_at'],
                        'updated_at' => is_object($task) ? $task->updated_at : $task['updated_at'],
                        'steps' => $steps_array
                    );
                }
            }

            wp_send_json_success(array('tasks' => $tasks_array));
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => 'Error loading tasks: ' . $e->getMessage()));
        }
    }

    /**
     * Handle AJAX request for getting task updates as chat messages
     * This converts tasks/steps into readable chat-like messages
     */
    public function handle_get_task_updates() {
        // Verify nonce
        $nonce = isset($_GET['nonce']) ? $_GET['nonce'] : (isset($_POST['nonce']) ? $_POST['nonce'] : '');
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
            $last_update = isset($_GET['last_update']) ? intval($_GET['last_update']) : 0;
            $user_id = get_current_user_id();

            if ($chat_id <= 0) {
                wp_send_json_error(array('message' => 'Invalid chat ID'));
                return;
            }

            // Check if tables exist
            if (!\Plugitify_DB::tableExists('tasks') || !\Plugitify_DB::tableExists('steps')) {
                wp_send_json_success(array('updates' => array(), 'last_update' => $last_update));
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

            $updates = array();
            $new_last_update = $last_update;

            // Always get all tasks for this chat (we'll filter updates based on timestamps)
            // This ensures we don't miss tasks that were created before last_update but have new steps
            $tasks = \Plugitify_DB::table('tasks')
                ->where('chat_history_id', $chat_id)
                ->where('user_id', $user_id)
                ->orderBy('created_at', 'ASC')
                ->get();
            
            // Ensure it's an array
            if (is_object($tasks)) {
                $tasks = array($tasks);
            } elseif (!is_array($tasks)) {
                $tasks = array();
            }
            
           
            if ($tasks && is_array($tasks)) {
                foreach ($tasks as $task) {
                    $task_id = is_object($task) ? $task->id : $task['id'];
                    $task_created_str = is_object($task) ? $task->created_at : $task['created_at'];
                    $task_updated_str = is_object($task) ? $task->updated_at : $task['updated_at'];
                    $task_created = $task_created_str ? strtotime($task_created_str) : 0;
                    $task_updated = $task_updated_str ? strtotime($task_updated_str) : 0;
                    
                    // Get all steps for this task (we'll filter by timestamp later)
                    // Use raw query for 'order' column since it's a MySQL reserved word
                    global $wpdb;
                    $steps_table = \Plugitify_DB::getFullTableName('steps');
                    $steps_query = $wpdb->prepare(
                        "SELECT * FROM {$steps_table} WHERE task_id = %d ORDER BY `order` ASC, created_at ASC",
                        $task_id
                    );
                    $steps = $wpdb->get_results($steps_query);
                    
                    // Ensure steps is an array
                    if (is_object($steps)) {
                        $steps = array($steps);
                    } elseif (!is_array($steps)) {
                        $steps = array();
                    }
                    
                 

                    // Create update messages
                    $taskName = is_object($task) ? $task->task_name : $task['task_name'];
                    $taskStatus = is_object($task) ? $task->status : $task['status'];
                    $taskProgress = intval(is_object($task) ? $task->progress : $task['progress']);
                    
                    // Check if task has any steps that were created/updated after last_update
                    $hasRecentSteps = false;
                    if ($steps && (is_array($steps) || is_object($steps))) {
                        foreach ($steps as $step) {
                            $step_created_at = is_object($step) ? ($step->created_at ?? null) : ($step['created_at'] ?? null);
                            $step_updated_at = is_object($step) ? ($step->updated_at ?? null) : ($step['updated_at'] ?? null);
                            $step_created = $step_created_at ? strtotime($step_created_at) : 0;
                            $step_updated = $step_updated_at ? strtotime($step_updated_at) : 0;
                            if ($last_update == 0 || $step_created > $last_update || $step_updated > $last_update) {
                                $hasRecentSteps = true;
                                break;
                            }
                        }
                    }
                    
                 

                    // Don't show task status updates - only show steps
                    // User wants to see steps progress, not task status

                    // Step updates
                    $steps_processed = 0;
                    $steps_shown = 0;
                    
                   
                    if ($steps && (is_array($steps) || is_object($steps))) {
                        foreach ($steps as $step) {
                            $steps_processed++;
                            $step_created_str = is_object($step) ? ($step->created_at ?? null) : ($step['created_at'] ?? null);
                            $step_updated_str = is_object($step) ? ($step->updated_at ?? null) : ($step['updated_at'] ?? null);
                            $step_created = $step_created_str ? strtotime($step_created_str) : 0;
                            $step_updated = $step_updated_str ? strtotime($step_updated_str) : 0;
                            $step_id = is_object($step) ? $step->id : $step['id'];
                            $stepName = is_object($step) ? $step->step_name : $step['step_name'];
                            $stepStatus = is_object($step) ? $step->status : $step['status'];
                            $stepContent = is_object($step) ? $step->content : $step['content'];
                            
                            // Show step if:
                            // 1. last_update is 0 (first load) - show all steps with meaningful status
                            // 2. OR step was created/updated after last_update (regardless of status)
                            // We want to show ALL steps, not just completed ones, so user can see progress
                            $shouldShowStep = false;
                            if ($last_update == 0) {
                                // First load: show all steps except pending (show in_progress, completed, failed)
                                $shouldShowStep = ($stepStatus !== 'pending');
                            } else {
                                // Subsequent loads: show if step was created or updated after last_update
                                // This ensures we show all new steps, even if they're still in_progress
                                $shouldShowStep = ($step_created > $last_update || $step_updated > $last_update);
                            }
                          
                            
                            if ($shouldShowStep) {
                                $steps_shown++;
                                // Show all steps with meaningful status
                                if ($stepStatus === 'completed') {
                                    $content = "✓ {$stepName}";
                                    if ($stepContent && strlen($stepContent) < 200) {
                                        $content .= "\n   {$stepContent}";
                                    }
                                    $updates[] = array(
                                        'type' => 'step_complete',
                                        'content' => $content,
                                        'timestamp' => $step_updated_str,
                                        'step_id' => $step_id,
                                        'task_id' => $task_id
                                    );
                                } elseif ($stepStatus === 'failed') {
                                    $updates[] = array(
                                        'type' => 'step_failed',
                                        'content' => "✗ {$stepName} - ناموفق",
                                        'timestamp' => $step_updated_str,
                                        'step_id' => $step_id,
                                        'task_id' => $task_id
                                    );
                                } elseif ($stepStatus === 'in_progress') {
                                    // Show in_progress steps - user wants to see all progress
                                    $content = "⏳ {$stepName}";
                                    if ($stepContent && strlen($stepContent) < 200) {
                                        $content .= "\n   {$stepContent}";
                                    }
                                    $updates[] = array(
                                        'type' => 'step_update',
                                        'content' => $content,
                                        'timestamp' => $step_updated_str,
                                        'step_id' => $step_id,
                                        'task_id' => $task_id
                                    );
                                }
                                
                              
                            }
                        }
                    }

                    // Update new_last_update with latest task/step timestamps
                    if ($task_updated > $new_last_update) {
                        $new_last_update = $task_updated;
                    }
                    if ($task_created > $new_last_update) {
                        $new_last_update = $task_created;
                    }
                    
                    if ($steps && (is_array($steps) || is_object($steps))) {
                        foreach ($steps as $step) {
                            $step_updated_at = is_object($step) ? ($step->updated_at ?? null) : ($step['updated_at'] ?? null);
                            $step_updated = $step_updated_at ? strtotime($step_updated_at) : 0;
                            if ($step_updated > $new_last_update) {
                                $new_last_update = $step_updated;
                            }
                        }
                    }
                    
                   
                }
            }

            // Also check for new assistant messages (final response)
            // Only check if last_update > 0 (means we're in an active polling session)
            if ($last_update > 0 && \Plugitify_DB::tableExists('messages')) {
                $lastUpdateDate = date('Y-m-d H:i:s', $last_update);
                global $wpdb;
                $messages_table = \Plugitify_DB::getFullTableName('messages');
                $messages_query = $wpdb->prepare(
                    "SELECT * FROM {$messages_table} WHERE chat_history_id = %d AND role = %s AND created_at > %s ORDER BY created_at ASC",
                    $chat_id,
                    'assistant',
                    $lastUpdateDate
                );
                $new_messages = $wpdb->get_results($messages_query);
                
                if ($new_messages) {
                    foreach ($new_messages as $msg) {
                        $msg_created_at = is_object($msg) ? ($msg->created_at ?? null) : ($msg['created_at'] ?? null);
                        $msg_created = $msg_created_at ? strtotime($msg_created_at) : 0;
                        $msg_content = is_object($msg) ? $msg->content : $msg['content'];
                        $msg_id = is_object($msg) ? $msg->id : $msg['id'];
                        
                        // Only add if it's a substantial message (likely final response)
                        if (strlen($msg_content) > 20) {
                            $updates[] = array(
                                'type' => 'final_message',
                                'content' => $msg_content,
                                'timestamp' => is_object($msg) ? $msg->created_at : $msg['created_at'],
                                'message_id' => $msg_id,
                                'is_complete' => true
                            );
                            
                            if ($msg_created > $new_last_update) {
                                $new_last_update = $msg_created;
                            }
                        }
                    }
                }
            }

            // Sort updates by timestamp to ensure proper order
            if (!empty($updates)) {
                usort($updates, function($a, $b) {
                    $timestampA = isset($a['timestamp']) ? $a['timestamp'] : null;
                    $timestampB = isset($b['timestamp']) ? $b['timestamp'] : null;
                    $timeA = $timestampA ? strtotime($timestampA) : 0;
                    $timeB = $timestampB ? strtotime($timestampB) : 0;
                    return $timeA - $timeB;
                });
            }
            
          

            // Check if there's a final message in this batch of updates
            // Only final_message type indicates the agent has finished responding
            $has_final_message = false;
            foreach ($updates as $update) {
                if (isset($update['type']) && $update['type'] === 'final_message') {
                    $has_final_message = true;
                    break;
                }
            }

            // Check if we're still waiting for a response
            // If last message in chat is from user, we're waiting for agent response
            $is_waiting_for_response = false;
            if (\Plugitify_DB::tableExists('messages')) {
                $last_message = \Plugitify_DB::table('messages')
                    ->where('chat_history_id', $chat_id)
                    ->orderBy('created_at', 'DESC')
                    ->first();
                
                if ($last_message) {
                    $last_role = is_object($last_message) ? $last_message->role : $last_message['role'];
                    $is_waiting_for_response = ($last_role === 'user');
                }
            }

            // Get last step for current task (to show under loading indicator)
            $last_pending_step = null;
                // Get the most recent task
                $most_recent_task = end($tasks);
                $task_id = is_object($most_recent_task) ? $most_recent_task->id : $most_recent_task['id'];
                
                // Get last step for this task
                global $wpdb;
                $steps_table = \Plugitify_DB::getFullTableName('steps');
                $pending_step_query = $wpdb->prepare(
                    "SELECT * FROM {$steps_table} WHERE task_id = %d ORDER BY id DESC LIMIT 1",
                    $task_id
                );
                $pending_step = $wpdb->get_row($pending_step_query);
                
                if ($pending_step) {
                    $last_pending_step = array(
                        'step_name' => is_object($pending_step) ? $pending_step->step_name : $pending_step['step_name'],
                        'step_id' => is_object($pending_step) ? $pending_step->id : $pending_step['id']
                    );
                }
                error_log('Plugitify: last_pending_step ' . print_r($last_pending_step, true));

            wp_send_json_success(array(
                'updates' => $updates,
                'last_update' => $new_last_update,
                'has_final_message' => $has_final_message,
                'is_waiting_for_response' => $is_waiting_for_response,
                'last_pending_step' => $last_pending_step
            ));
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => 'Error loading task updates: ' . $e->getMessage()));
        }
    }

    /**
     * Format task status for display
     */
    private function formatTaskStatus($status) {
        $statusMap = array(
            'pending' => 'در انتظار',
            'in_progress' => 'در حال انجام',
            'completed' => 'تکمیل شده',
            'failed' => 'ناموفق',
            'cancelled' => 'لغو شده'
        );
        return isset($statusMap[$status]) ? $statusMap[$status] : $status;
    }
}
new Plugitify_Panel();