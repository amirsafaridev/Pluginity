<?php

/**
 * Migration Runner
 * 
 * This file is used to run migrations
 * 
 * Usage:
 * include_once PLUGITIFY_DIR . 'migrations/migrate.php';
 * Plugitify_Migrate::run(); // Run all migrations
 * Plugitify_Migrate::rollback(); // Rollback last migration
 */

class Plugitify_Migrate {
    
    /**
     * Run all migrations
     */
    public static function run() {
        $migrations = self::getMigrations();
        
        foreach ($migrations as $migration_file) {
            $migration = self::loadMigration($migration_file);
            
            if ($migration && method_exists($migration, 'up')) {
                try {
                    $start_time = microtime(true);
                    $result = $migration->up();
                    $end_time = microtime(true);
                    $duration = round($end_time - $start_time, 2);
                    
                    // Only log if table was actually created (result is true)
                    // If table already exists, result will be false and we skip logging
                    if ($result !== false) {
                        $message = "✓ Migration executed: " . basename($migration_file);
                        
                        // Save log in option
                        self::logMigration([
                            'action' => 'up',
                            'file' => basename($migration_file),
                            'status' => 'success',
                            'message' => $message,
                            'duration' => $duration,
                            'timestamp' => current_time('mysql')
                        ]);
                    }
                    // If result is false, table already exists, so we skip logging
                } catch (Exception $e) {
                    $message = "✗ Error in migration " . basename($migration_file) . ": " . $e->getMessage();
                                       
                    // Save error log
                    self::logMigration([
                        'action' => 'up',
                        'file' => basename($migration_file),
                        'status' => 'error',
                        'message' => $message,
                        'error' => $e->getMessage(),
                        'timestamp' => current_time('mysql')
                    ]);
                }
            }
        }
    }
    
    /**
     * Rollback last migration
     */
    public static function rollback() {
        $migrations = self::getMigrations();
        
        if (empty($migrations)) {
            $message = "No migrations to rollback.";
            echo esc_html($message) . "\n";
            self::logMigration([
                'action' => 'rollback',
                'status' => 'info',
                'message' => $message,
                'timestamp' => current_time('mysql')
            ]);
            return;
        }
        
        // Rollback last migration
        $last_migration = end($migrations);
        $migration = self::loadMigration($last_migration);
        
        if ($migration && method_exists($migration, 'down')) {
            try {
                $start_time = microtime(true);
                $migration->down();
                $end_time = microtime(true);
                $duration = round($end_time - $start_time, 2);
                
                $message = "✓ Migration rolled back: " . basename($last_migration);
                echo esc_html($message) . "\n";
                
                // Save log
                self::logMigration([
                    'action' => 'rollback',
                    'file' => basename($last_migration),
                    'status' => 'success',
                    'message' => $message,
                    'duration' => $duration,
                    'timestamp' => current_time('mysql')
                ]);
            } catch (Exception $e) {
                $message = "✗ Error rolling back " . basename($last_migration) . ": " . $e->getMessage();
                echo esc_html($message) . "\n";
                
                // Save error log
                self::logMigration([
                    'action' => 'rollback',
                    'file' => basename($last_migration),
                    'status' => 'error',
                    'message' => $message,
                    'error' => $e->getMessage(),
                    'timestamp' => current_time('mysql')
                ]);
            }
        }
    }
    
    /**
     * Get list of migrations in order
     */
    protected static function getMigrations() {
        $migrations_dir = PLUGITIFY_DIR . 'migrations/';
        $files = glob($migrations_dir . '*_*.php');
        
        // Sort by filename (which includes date)
        sort($files);
        
        return $files;
    }
    
    /**
     * Load migration class
     */
    protected static function loadMigration($file) {
        if (!file_exists($file)) {
            return null;
        }
        
        include_once $file;
        
        // Extract class name from file
        $content = file_get_contents($file);
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $class_name = $matches[1];
            if (class_exists($class_name)) {
                return new $class_name();
            }
        }
        
        return null;
    }
    
    /**
     * Save migration log in option
     */
    protected static function logMigration($log_data) {
        $option_name = 'plugitify_migration_logs';
        $logs = get_option($option_name, []);
        
        // Add new log to beginning of array
        array_unshift($logs, $log_data);
        
        // Limit number of logs (last 100 logs)
        if (count($logs) > 100) {
            $logs = array_slice($logs, 0, 100);
        }
        
        // Save in option
        update_option($option_name, $logs);
    }
    
    /**
     * Get migration logs
     */
    public static function getLogs($limit = 50) {
        $option_name = 'plugitify_migration_logs';
        $logs = get_option($option_name, []);
        
        if ($limit > 0) {
            $logs = array_slice($logs, 0, $limit);
        }
        
        return $logs;
    }
    
    /**
     * Clear all logs
     */
    public static function clearLogs() {
        delete_option('plugitify_migration_logs');
    }
}

