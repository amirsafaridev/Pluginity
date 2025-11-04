<?php
use \NeuronAI\Chat\Messages\UserMessage;

class Plugitify_Main {
    public function __construct() {
        $this->include_files();
       $this->create_table();
  
    }

    public function include_files() {
        include_once PLUGITIFY_DIR . 'vendor/autoload.php';
        $includes = [
            'migrations/migrate.php',
            'include/class/Plugitify_DB.php',
            'include/class/Plugitify-chat-logs.php',
            'include/class/Plugitify-panel.php',
            'include/class/Plugitify-agent.php',
            'include/class/Plugitify-admin-menu.php',
        ];
        foreach ($includes as $include) {
            include PLUGITIFY_DIR . $include;
        }
    }
    
    public function create_table() {
        // اجرای migrationها
        if (class_exists('Plugitify_Migrate')) {
            Plugitify_Migrate::run();
        }
    }

    
}
new Plugitify_Main();