<?php

/**
 * Chat Logs Handler
 * 
 * این کلاس برای مدیریت لاگ‌های چت استفاده می‌شود
 * لاگ‌ها در WordPress options ذخیره می‌شوند (مشابه migration logs)
 */
class Plugitify_Chat_Logs {
    
    const OPTION_NAME = 'plugitify_chat_logs';
    const MAX_LOGS = 100;
    
    /**
     * لاگ یک رویداد چت را ذخیره می‌کند
     * 
     * @param array $log_data داده‌های لاگ شامل:
     *   - action: نوع عملیات (message_sent, message_received, chat_created, chat_deleted, error)
     *   - chat_id: ID چت
     *   - user_id: ID کاربر
     *   - message: پیام یا توضیحات
     *   - status: success, error, warning
     *   - metadata: داده‌های اضافی (اختیاری)
     */
    public static function log($log_data) {
        $option_name = self::OPTION_NAME;
        $logs = get_option($option_name, []);
        
        // اضافه کردن timestamp و user_id اگر موجود نیست
        if (!isset($log_data['timestamp'])) {
            $log_data['timestamp'] = current_time('mysql');
        }
        if (!isset($log_data['user_id'])) {
            $log_data['user_id'] = get_current_user_id();
        }
        if (!isset($log_data['status'])) {
            $log_data['status'] = 'success';
        }
        
        // اضافه کردن لاگ به ابتدای آرایه
        array_unshift($logs, $log_data);
        
        // محدود کردن به 100 لاگ آخر
        if (count($logs) > self::MAX_LOGS) {
            $logs = array_slice($logs, 0, self::MAX_LOGS);
        }
        
        // ذخیره در option (فقط یک query)
        update_option($option_name, $logs);
        // Debug: error_log('Plugitify: chat logs ' . print_r($log_data, true));
    }
    
    /**
     * دریافت لاگ‌های چت
     * 
     * @param int $limit تعداد لاگ‌ها (0 = همه)
     * @param string $action فیلتر بر اساس action (اختیاری)
     * @return array
     */
    public static function getLogs($limit = 100, $action = null) {
        $option_name = self::OPTION_NAME;
        $logs = get_option($option_name, []);
        
        // فیلتر بر اساس action اگر مشخص شده باشد
        if ($action !== null) {
            $logs = array_filter($logs, function($log) use ($action) {
                return isset($log['action']) && $log['action'] === $action;
            });
            // re-index array
            $logs = array_values($logs);
        }
        
        // محدود کردن تعداد
        if ($limit > 0) {
            $logs = array_slice($logs, 0, $limit);
        }
        
        return $logs;
    }
    
    /**
     * پاک کردن تمام لاگ‌ها
     */
    public static function clearLogs() {
        delete_option(self::OPTION_NAME);
    }
    
    /**
     * پاک کردن لاگ‌های قدیمی‌تر از X روز
     * 
     * @param int $days تعداد روز
     */
    public static function clearOldLogs($days = 30) {
        $option_name = self::OPTION_NAME;
        $logs = get_option($option_name, []);
        
        if (empty($logs)) {
            return;
        }
        
        $cutoff_time = strtotime("-{$days} days");
        
        $logs = array_filter($logs, function($log) use ($cutoff_time) {
            if (!isset($log['timestamp'])) {
                return false;
            }
            $log_time = strtotime($log['timestamp']);
            return $log_time >= $cutoff_time;
        });
        
        // re-index array
        $logs = array_values($logs);
        
        update_option($option_name, $logs);
    }
    
    /**
     * شمارش لاگ‌ها بر اساس action
     * 
     * @return array
     */
    public static function getLogCounts() {
        $logs = self::getLogs(0); // همه لاگ‌ها
        
        $counts = [
            'total' => count($logs),
            'error' => 0,
        ];
        
        foreach ($logs as $log) {
            if (isset($log['action'])) {
                $action = $log['action'];
                if (isset($counts[$action])) {
                    $counts[$action]++;
                }
            }
        }
        
        return $counts;
    }
}

