<?php

/*
Plugin Name: plugitify
Plugin URI: https://wpagentify.com
Description: Create, customize, and export WordPress plugins with AI
Version: 1.0.0
Author: Amir safari
Author URI: https://amirsafaridev.github.io/
License: GPLv2 or later
Text Domain: plugitify
*/


define('PLUGITIFY_DIR', plugin_dir_path(__FILE__));
define('PLUGITIFY_URL', plugin_dir_url(__FILE__));
define('PLUGITIFY_VERSION', '1.0.0');
define('PLUGITIFY_TEXT_DOMAIN', 'plugitify');
define('PLUGITIFY_PLUGIN_NAME', 'Plugitify');
define('PLUGITIFY_PLUGIN_SLUG', 'plugitify');
define('PLUGITIFY_PLUGIN_VERSION', '1.0.0');

include PLUGITIFY_DIR . 'include/main.php';
