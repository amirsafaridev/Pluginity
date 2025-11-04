<?php

/**
 * Admin menu class for displaying logs with tabs
 */
class Plugitify_Admin_Menu {
    
    private $current_tab = 'migrations';
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_actions'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            'Plugitify',
            'Plugitify',
            'manage_options',
            'plugitify',
            array($this, 'render_main_page'),
            'dashicons-admin-plugins',
            30
        );
        
        // Logs submenu
        add_submenu_page(
            'plugitify',
            'Logs',
            'Logs',
            'manage_options',
            'plugitify-logs',
            array($this, 'render_logs_page')
        );
    }
    
    /**
     * Handle actions (clear logs)
     */
    public function handle_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'plugitify-logs') {
            return;
        }
        
        // Get current tab
        $this->current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'migrations';
        
        // Handle clear logs action
        if (isset($_POST['clear_logs']) && check_admin_referer('plugitify_clear_logs')) {
            if ($this->current_tab === 'migrations' && class_exists('Plugitify_Migrate')) {
                Plugitify_Migrate::clearLogs();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>Migration logs cleared successfully.</p></div>';
                });
            } elseif ($this->current_tab === 'chat' && class_exists('Plugitify_Chat_Logs')) {
                Plugitify_Chat_Logs::clearLogs();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>Chat logs cleared successfully.</p></div>';
                });
            }
        }
    }
    
    /**
     * Render main page
     */
    public function render_main_page() {
        ?>
        <div class="wrap">
            <h1>Plugitify</h1>
            <p>Welcome to Plugitify dashboard.</p>
        </div>
        <?php
    }
    
    /**
     * Render logs page with tabs
     */
    public function render_logs_page() {
        // Get current tab
        $this->current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'migrations';
        
        // Define available tabs
        $tabs = array(
            'migrations' => 'Migrations',
            'chat' => 'Chat',
            'tasks' => 'Tasks',
            'system' => 'System'
        );
        
        ?>
        <div class="wrap">
            <h1>Logs</h1>
            
            <!-- Tabs Navigation -->
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab_label): ?>
                    <a href="?page=plugitify-logs&tab=<?php echo esc_attr($tab_key); ?>" 
                       class="nav-tab <?php echo $this->current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            
            <!-- Tab Content -->
            <div class="tab-content" style="margin-top: 20px;">
                <?php
                switch ($this->current_tab) {
                    case 'migrations':
                        $this->render_migrations_tab();
                        break;
                    case 'chat':
                        $this->render_chat_tab();
                        break;
                    case 'tasks':
                        $this->render_tasks_tab();
                        break;
                    case 'system':
                        $this->render_system_tab();
                        break;
                    default:
                        $this->render_migrations_tab();
                }
                ?>
            </div>
        </div>
        
        <style>
            .wp-list-table th {
                font-weight: 600;
            }
            .wp-list-table code {
                background: #f0f0f1;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
            }
            .status-badge {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
            }
            .status-success {
                background: #46b450;
                color: white;
            }
            .status-error {
                background: #dc3232;
                color: white;
            }
            .status-info {
                background: #2271b1;
                color: white;
            }
            .status-warning {
                background: #f0b849;
                color: white;
            }
        </style>
        <?php
    }
    
    /**
     * Render migrations tab
     */
    private function render_migrations_tab() {
        if (!class_exists('Plugitify_Migrate')) {
            echo '<div class="notice notice-error"><p>Plugitify_Migrate class not found.</p></div>';
            return;
        }
        
        $logs = Plugitify_Migrate::getLogs(100);
        
        ?>
        <div class="migrations-tab">
            <div style="margin-bottom: 20px;">
                <form method="post" action="">
                    <?php wp_nonce_field('plugitify_clear_logs'); ?>
                    <input type="hidden" name="tab" value="migrations">
                    <!-- <input type="submit" name="clear_logs" class="button button-secondary" value="Clear All Logs" 
                           onclick="return confirm('Are you sure you want to clear all migration logs?');"> -->
                </form>
            </div>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <p><strong>Total Logs: <?php echo count($logs); ?></strong></p>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 150px;">Time</th>
                        <th style="width: 100px;">Action</th>
                        <th style="width: 100px;">Status</th>
                        <th style="width: 200px;">File</th>
                        <th>Message</th>
                        <th style="width: 80px;">Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px;">
                                <p>No logs found.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <?php 
                                    if (isset($log['timestamp'])) {
                                        $timestamp = strtotime($log['timestamp']);
                                        echo $timestamp ? date_i18n('Y/m/d H:i:s', $timestamp) : $log['timestamp'];
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-<?php echo $log['action'] === 'up' ? 'arrow-up-alt' : 'arrow-down-alt'; ?>"></span>
                                    <?php echo $log['action'] === 'up' ? 'Run' : 'Rollback'; ?>
                                </td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    $status_text = '';
                                    switch ($log['status']) {
                                        case 'success':
                                            $status_class = 'success';
                                            $status_text = 'Success';
                                            break;
                                        case 'error':
                                            $status_class = 'error';
                                            $status_text = 'Error';
                                            break;
                                        case 'info':
                                            $status_class = 'info';
                                            $status_text = 'Info';
                                            break;
                                        default:
                                            $status_class = '';
                                            $status_text = ucfirst($log['status']);
                                    }
                                    ?>
                                    <span class="status-badge status-<?php echo $status_class; ?>">
                                        <?php echo esc_html($status_text); ?>
                                    </span>
                                </td>
                                <td>
                                    <code><?php echo isset($log['file']) ? esc_html($log['file']) : '-'; ?></code>
                                </td>
                                <td>
                                    <?php echo esc_html($log['message']); ?>
                                    <?php if (isset($log['error'])): ?>
                                        <br><small style="color: #dc3232;"><strong>Error:</strong> <?php echo esc_html($log['error']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo isset($log['duration']) ? number_format($log['duration'], 2) . 's' : '-'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render chat tab
     */
    private function render_chat_tab() {
        if (!class_exists('Plugitify_Chat_Logs')) {
            echo '<div class="notice notice-error"><p>Plugitify_Chat_Logs class not found.</p></div>';
            return;
        }
        
        $logs = Plugitify_Chat_Logs::getLogs(100);
        $counts = Plugitify_Chat_Logs::getLogCounts();
        
        ?>
        <div class="chat-tab">
            <div style="margin-bottom: 20px;">
                <form method="post" action="">
                    <?php wp_nonce_field('plugitify_clear_logs'); ?>
                    <input type="hidden" name="tab" value="chat">
                    <input type="submit" name="clear_logs" class="button button-secondary" value="Clear All Chat Logs" 
                           onclick="return confirm('Are you sure you want to clear all chat logs?');">
                </form>
            </div>
            
            <!-- Statistics -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <p><strong>Total Logs: <?php echo esc_html($counts['total']); ?></strong> | 
                       Errors: <?php echo esc_html($counts['error']); ?>
                    </p>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 150px;">Time</th>
                        <th style="width: 120px;">Action</th>
                        <th style="width: 100px;">Status</th>
                        <th style="width: 80px;">Chat ID</th>
                        <th style="width: 100px;">User ID</th>
                        <th>Message</th>
                        <th style="width: 150px;">Metadata</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 20px;">
                                <p>No chat logs found.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <?php 
                                    if (isset($log['timestamp'])) {
                                        $timestamp = strtotime($log['timestamp']);
                                        echo $timestamp ? date_i18n('Y/m/d H:i:s', $timestamp) : esc_html($log['timestamp']);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $action = isset($log['action']) ? $log['action'] : 'unknown';
                                    $action_labels = array(
                                        'error' => 'Error',
                                        'warning' => 'Warning'
                                    );
                                    echo esc_html(isset($action_labels[$action]) ? $action_labels[$action] : ucfirst($action));
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $status = isset($log['status']) ? $log['status'] : 'info';
                                    $status_class = '';
                                    $status_text = '';
                                    switch ($status) {
                                        case 'success':
                                            $status_class = 'success';
                                            $status_text = 'Success';
                                            break;
                                        case 'error':
                                            $status_class = 'error';
                                            $status_text = 'Error';
                                            break;
                                        case 'warning':
                                            $status_class = 'warning';
                                            $status_text = 'Warning';
                                            break;
                                        default:
                                            $status_class = 'info';
                                            $status_text = ucfirst($status);
                                    }
                                    ?>
                                    <span class="status-badge status-<?php echo esc_attr($status_class); ?>">
                                        <?php echo esc_html($status_text); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo isset($log['chat_id']) ? esc_html($log['chat_id']) : '-'; ?>
                                </td>
                                <td>
                                    <?php 
                                    $user_id = isset($log['user_id']) ? $log['user_id'] : 0;
                                    if ($user_id > 0) {
                                        $user = get_userdata($user_id);
                                        echo esc_html($user ? $user->user_login : $user_id);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php echo esc_html(isset($log['message']) ? $log['message'] : '-'); ?>
                                </td>
                                <td>
                                    <?php 
                                    if (isset($log['metadata']) && is_array($log['metadata']) && !empty($log['metadata'])) {
                                        $meta_parts = array();
                                        foreach ($log['metadata'] as $key => $value) {
                                            if (is_array($value)) {
                                                $value = json_encode($value);
                                            }
                                            $meta_parts[] = esc_html($key . ': ' . $value);
                                        }
                                        echo implode('<br>', $meta_parts);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render tasks tab
     */
    private function render_tasks_tab() {
        if (!class_exists('Plugitify_DB')) {
            echo '<div class="notice notice-error"><p>Plugitify_DB class not found.</p></div>';
            return;
        }
        
        if (!Plugitify_DB::tableExists('tasks')) {
            echo '<div class="notice notice-warning"><p>Tasks table does not exist. Please run migrations first.</p></div>';
            return;
        }
        
        // Get last 100 tasks
        $tasks = Plugitify_DB::table('tasks')
            ->orderBy('created_at', 'DESC')
            ->limit(100)
            ->get();
        
        if (!$tasks) {
            $tasks = [];
        }
        
        // Convert to array if single object
        if (!is_array($tasks)) {
            $tasks = [$tasks];
        }
        
        // Reverse array to show oldest first (newest last)
        $tasks = array_reverse($tasks);
        
        // Get steps for each task
        global $wpdb;
        $steps_table = Plugitify_DB::getFullTableName('steps');
        
        foreach ($tasks as &$task) {
            $task_id = is_object($task) ? $task->id : $task['id'];
            
            // Get steps for this task
            $steps_query = $wpdb->prepare(
                "SELECT * FROM {$steps_table} WHERE task_id = %d ORDER BY `order` ASC, created_at ASC",
                $task_id
            );
            $steps = $wpdb->get_results($steps_query);
            
            // Ensure steps is an array
            if (is_object($steps)) {
                $steps = [$steps];
            } elseif (!is_array($steps)) {
                $steps = [];
            }
            
            // Add steps to task
            if (is_object($task)) {
                $task->steps = $steps;
            } else {
                $task['steps'] = $steps;
            }
        }
        
        ?>
        <div class="tasks-tab">
            <div class="tablenav top">
                <div class="alignleft actions">
                    <p><strong>Total Tasks: <?php echo count($tasks); ?></strong> (Showing last 100)</p>
                </div>
            </div>
            
            <?php if (empty($tasks)): ?>
                <div class="notice notice-info">
                    <p>No tasks found.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th style="width: 200px;">Task Name</th>
                            <th style="width: 120px;">Type</th>
                            <th style="width: 100px;">Status</th>
                            <th style="width: 80px;">Progress</th>
                            <th>Description</th>
                            <th style="width: 150px;">Created</th>
                            <th style="width: 100px;">Steps</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <?php
                            $task_id = is_object($task) ? $task->id : $task['id'];
                            $task_name = is_object($task) ? $task->task_name : $task['task_name'];
                            $task_type = is_object($task) ? $task->task_type : $task['task_type'];
                            $description = is_object($task) ? $task->description : $task['description'];
                            $status = is_object($task) ? $task->status : $task['status'];
                            $progress = is_object($task) ? $task->progress : $task['progress'];
                            $created_at = is_object($task) ? $task->created_at : $task['created_at'];
                            $updated_at = is_object($task) ? $task->updated_at : $task['updated_at'];
                            $result = is_object($task) ? $task->result : $task['result'];
                            $error_message = is_object($task) ? $task->error_message : $task['error_message'];
                            $steps = is_object($task) ? $task->steps : $task['steps'];
                            
                            // Status badge class
                            $status_class = '';
                            $status_text = '';
                            switch ($status) {
                                case 'completed':
                                    $status_class = 'success';
                                    $status_text = 'Completed';
                                    break;
                                case 'in_progress':
                                    $status_class = 'info';
                                    $status_text = 'In Progress';
                                    break;
                                case 'failed':
                                    $status_class = 'error';
                                    $status_text = 'Failed';
                                    break;
                                case 'cancelled':
                                    $status_class = 'warning';
                                    $status_text = 'Cancelled';
                                    break;
                                case 'pending':
                                default:
                                    $status_class = 'warning';
                                    $status_text = 'Pending';
                                    break;
                            }
                            
                            $created_timestamp = $created_at ? strtotime($created_at) : 0;
                            $steps_count = is_array($steps) ? count($steps) : 0;
                            ?>
                            <tr class="task-row" data-task-id="<?php echo esc_attr($task_id); ?>">
                                <td>
                                    <strong>#<?php echo esc_html($task_id); ?></strong>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($task_name); ?></strong>
                                </td>
                                <td>
                                    <code><?php echo esc_html($task_type ?: '-'); ?></code>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($status_class); ?>">
                                        <?php echo esc_html($status_text); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($progress !== null): ?>
                                        <div style="width: 60px; background: #f0f0f1; border-radius: 3px; height: 20px; position: relative;">
                                            <div style="width: <?php echo esc_attr($progress); ?>%; background: <?php echo $progress == 100 ? '#46b450' : '#2271b1'; ?>; height: 100%; border-radius: 3px;"></div>
                                            <span style="position: absolute; top: 0; left: 0; right: 0; text-align: center; line-height: 20px; font-size: 11px; color: <?php echo $progress > 50 ? 'white' : '#333'; ?>;">
                                                <?php echo esc_html($progress); ?>%
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html($description ?: '-'); ?>
                                    <?php if ($error_message): ?>
                                        <br><small style="color: #dc3232;"><strong>Error:</strong> <?php echo esc_html($error_message); ?></small>
                                    <?php endif; ?>
                                    <?php if ($result): ?>
                                        <br><small style="color: #2271b1;"><strong>Result:</strong> <?php echo esc_html(wp_trim_words($result, 20)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $created_timestamp ? date_i18n('Y/m/d H:i', $created_timestamp) : '-'; ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small toggle-steps" data-task-id="<?php echo esc_attr($task_id); ?>">
                                        <?php echo esc_html($steps_count); ?> step(s)
                                        <span class="dashicons dashicons-arrow-down-alt" style="font-size: 14px; vertical-align: middle;"></span>
                                    </button>
                                </td>
                            </tr>
                            <?php if ($steps_count > 0): ?>
                                <tr class="steps-row" id="steps-<?php echo esc_attr($task_id); ?>" style="display: none;">
                                    <td colspan="8" style="padding: 0; background: #f9f9f9;">
                                        <div style="padding: 20px;">
                                            <h4 style="margin-top: 0;">Steps for Task #<?php echo esc_html($task_id); ?>: <?php echo esc_html($task_name); ?></h4>
                                            <table class="wp-list-table widefat fixed" style="margin-top: 10px;">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 60px;">Order</th>
                                                        <th style="width: 200px;">Step Name</th>
                                                        <th style="width: 100px;">Type</th>
                                                        <th style="width: 100px;">Status</th>
                                                        <th>Content</th>
                                                        <th style="width: 150px;">Created</th>
                                                        <th style="width: 80px;">Duration</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($steps as $step): ?>
                                                        <?php
                                                        $step_id = is_object($step) ? $step->id : $step['id'];
                                                        $step_name = is_object($step) ? $step->step_name : $step['step_name'];
                                                        $step_type = is_object($step) ? $step->step_type : $step['step_type'];
                                                        $step_order = is_object($step) ? $step->order : $step['order'];
                                                        $step_status = is_object($step) ? $step->status : $step['status'];
                                                        $step_content = is_object($step) ? $step->content : $step['content'];
                                                        $step_data = is_object($step) ? $step->data : $step['data'];
                                                        $step_result = is_object($step) ? $step->result : $step['result'];
                                                        $step_error = is_object($step) ? $step->error_message : $step['error_message'];
                                                        $step_duration = is_object($step) ? $step->duration : $step['duration'];
                                                        $step_created = is_object($step) ? $step->created_at : $step['created_at'];
                                                        
                                                        // Step status badge
                                                        $step_status_class = '';
                                                        $step_status_text = '';
                                                        switch ($step_status) {
                                                            case 'completed':
                                                                $step_status_class = 'success';
                                                                $step_status_text = 'Completed';
                                                                break;
                                                            case 'in_progress':
                                                                $step_status_class = 'info';
                                                                $step_status_text = 'In Progress';
                                                                break;
                                                            case 'failed':
                                                                $step_status_class = 'error';
                                                                $step_status_text = 'Failed';
                                                                break;
                                                            case 'skipped':
                                                                $step_status_class = 'warning';
                                                                $step_status_text = 'Skipped';
                                                                break;
                                                            case 'pending':
                                                            default:
                                                                $step_status_class = 'warning';
                                                                $step_status_text = 'Pending';
                                                                break;
                                                        }
                                                        
                                                        $step_created_timestamp = $step_created ? strtotime($step_created) : 0;
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?php echo esc_html($step_order); ?></strong>
                                                            </td>
                                                            <td>
                                                                <strong><?php echo esc_html($step_name); ?></strong>
                                                            </td>
                                                            <td>
                                                                <code><?php echo esc_html($step_type ?: '-'); ?></code>
                                                            </td>
                                                            <td>
                                                                <span class="status-badge status-<?php echo esc_attr($step_status_class); ?>">
                                                                    <?php echo esc_html($step_status_text); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php echo esc_html(wp_trim_words($step_content ?: '-', 30)); ?>
                                                                <?php if ($step_data): ?>
                                                                    <?php $data_json = is_string($step_data) ? $step_data : json_encode($step_data); ?>
                                                                    <br><small style="color: #666;"><strong>Data:</strong> <?php echo esc_html(wp_trim_words($data_json, 15)); ?></small>
                                                                <?php endif; ?>
                                                                <?php if ($step_result): ?>
                                                                    <?php $result_json = is_string($step_result) ? $step_result : json_encode($step_result); ?>
                                                                    <br><small style="color: #2271b1;"><strong>Result:</strong> <?php echo esc_html(wp_trim_words($result_json, 15)); ?></small>
                                                                <?php endif; ?>
                                                                <?php if ($step_error): ?>
                                                                    <br><small style="color: #dc3232;"><strong>Error:</strong> <?php echo esc_html($step_error); ?></small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php echo $step_created_timestamp ? date_i18n('Y/m/d H:i', $step_created_timestamp) : '-'; ?>
                                                            </td>
                                                            <td>
                                                                <?php echo $step_duration ? esc_html($step_duration) . 's' : '-'; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.toggle-steps').on('click', function() {
                var taskId = $(this).data('task-id');
                var stepsRow = $('#steps-' + taskId);
                var icon = $(this).find('.dashicons');
                
                if (stepsRow.is(':visible')) {
                    stepsRow.slideUp();
                    icon.removeClass('dashicons-arrow-up-alt').addClass('dashicons-arrow-down-alt');
                } else {
                    stepsRow.slideDown();
                    icon.removeClass('dashicons-arrow-down-alt').addClass('dashicons-arrow-up-alt');
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render system tab
     */
    private function render_system_tab() {
        ?>
        <div class="system-tab">
            <p>System logs will be displayed here.</p>
            <p><em>This feature is coming soon.</em></p>
        </div>
        <?php
    }
}

new Plugitify_Admin_Menu();
