=== Plugitify ===
Contributors: amirsafaridev
Tags: ai, chat, development, automation, code-generation, plugin-builder, artificial-intelligence, wordpress-development, developer-tools
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create, customize, and export WordPress plugins with AI-powered assistance

== Description ==

Plugitify is a revolutionary AI-powered WordPress plugin development tool that transforms the way developers create, customize, and manage WordPress plugins. Built with cutting-edge artificial intelligence technology, Plugitify provides an intelligent chat interface that understands your requirements and automatically generates complete, production-ready WordPress plugin code.

Whether you're a seasoned WordPress developer looking to accelerate your workflow or a beginner exploring plugin development, Plugitify empowers you to build sophisticated WordPress plugins through natural language conversations with an AI assistant that understands WordPress architecture, coding standards, and best practices.

**Key Capabilities:**

* **Intelligent Plugin Generation**: Describe your plugin idea in plain English, and watch as Plugitify's AI assistant creates complete plugin structures, generates code, and implements features automatically.

* **Advanced File Management**: The AI agent can create, read, edit, and delete files and directories within your WordPress installation, making it a true development assistant that works directly with your codebase.

* **Multi-Model AI Support**: Choose from leading AI providers including OpenAI (GPT-4, GPT-3.5), Anthropic (Claude), Google (Gemini), and Deepseek models, giving you flexibility in AI capabilities and pricing.

* **Task Management System**: Built-in task tracking and progress monitoring help you stay organized throughout the plugin development process, with automatic task creation and status updates.

* **Plugin Structure Analysis**: Extract and analyze existing plugin structures to understand their architecture, dependencies, and implementation patterns.

* **Debug Integration**: Seamlessly toggle WordPress debug mode, read debug logs, and troubleshoot issues directly from the interface.

* **Export & Review**: Generate ZIP archives of your plugins for easy distribution, review, or deployment.

* **Comprehensive Chat History**: All conversations are saved with full message history, allowing you to resume work on projects at any time.

* **Professional UI/UX**: Modern, responsive interface built with Vue.js that provides an intuitive and efficient development experience.

== Features ==

**AI-Powered Development Assistant**

* Natural language interface for plugin development
* Context-aware code generation following WordPress coding standards
* Automatic plugin structure creation based on requirements
* Intelligent code suggestions and improvements
* Support for multiple AI providers and models

**File Management Tools**

* Create files and directories programmatically
* Read and analyze existing plugin files
* Edit files with line-by-line precision
* Search and replace functionality across files
* Delete files and directories safely
* Full path validation and security checks

**Plugin Management**

* List all installed WordPress plugins
* Extract complete plugin structures and dependencies
* Analyze plugin architecture and code organization
* Deactivate plugins for testing purposes
* Generate plugin ZIP archives for distribution

**Task & Progress Tracking**

* Automatic task creation from AI conversations
* Real-time progress monitoring
* Task status tracking (pending, in-progress, completed)
* Step-by-step execution tracking
* Chat-based task management

**Debug & Development Tools**

* Toggle WordPress debug mode (WP_DEBUG)
* Read and analyze WordPress debug logs
* Check current debug status
* Integrated error tracking and reporting

**Chat & History Management**

* Persistent chat history with full message context
* Multiple concurrent chat sessions
* Chat title auto-generation and manual editing
* Message status tracking (pending, completed)
* Export chat conversations

**Database & Migration System**

* Automated database migrations
* Chat history storage
* Message metadata tracking
* Task and step persistence
* Migration logging and rollback support

**Security & Permissions**

* Role-based access control (requires manage_options capability)
* Nonce verification for all AJAX requests
* Input sanitization and validation
* Secure file path handling
* User-specific data isolation

**Modern Technology Stack**

* Vue.js-based frontend interface
* RESTful AJAX API architecture
* Modular PHP class structure
* Namespace-based code organization
* WordPress coding standards compliance

== Installation ==

**Method 1: WordPress Admin Dashboard (Recommended)**

1. Log in to your WordPress admin dashboard
2. Navigate to **Plugins** → **Add New**
3. Click **Upload Plugin**
4. Choose the `plugitify.zip` file
5. Click **Install Now**
6. After installation, click **Activate Plugin**

**Method 2: Manual Installation via FTP/SFTP**

1. Download the plugin files
2. Extract the ZIP archive
3. Upload the `plugitify` folder to `/wp-content/plugins/` directory on your server
4. Ensure the folder structure is: `/wp-content/plugins/plugitify/plugitify.php`
5. Log in to your WordPress admin dashboard
6. Navigate to **Plugins** → **Installed Plugins**
7. Find **Plugitify** in the list and click **Activate**

**Method 3: WordPress CLI**

If you have WP-CLI installed, you can install and activate the plugin using:

```
wp plugin install plugitify --activate
```

**Post-Installation Setup**

1. After activation, navigate to **Plugitify** in your WordPress admin menu
2. Access the plugin interface at `/plugitify` URL or via the admin menu
3. Configure your AI provider settings:
   * Go to the settings panel (gear icon)
   * Enter your API key for your preferred AI provider
   * Select your desired AI model
   * Save settings
4. Start creating plugins by chatting with the AI assistant!

**System Requirements**

* WordPress 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.6 or higher (or MariaDB equivalent)
* Administrator access to WordPress
* Valid API key for at least one AI provider (OpenAI, Anthropic, Google, or Deepseek)

== Frequently Asked Questions ==

= How do I get started with Plugitify? =

After installation and activation, navigate to the **Plugitify** menu in your WordPress admin dashboard. You'll be presented with a chat interface. Simply describe the plugin you want to create in natural language, and the AI assistant will guide you through the development process.

= What AI models are supported? =

Plugitify supports multiple AI providers and models:

**OpenAI Models:**
* GPT-4
* GPT-4 Turbo
* GPT-4o
* GPT-4o Mini
* GPT-3.5 Turbo

**Anthropic (Claude) Models:**
* Claude 3 Opus
* Claude 3 Sonnet
* Claude 3 Haiku
* Claude 3.5 Sonnet

**Google (Gemini) Models:**
* Gemini Pro
* Gemini Pro Vision
* Gemini 1.5 Pro
* Gemini 1.5 Flash

**Deepseek Models:**
* Deepseek Chat
* Deepseek Coder

= Do I need coding knowledge to use Plugitify? =

While basic understanding of WordPress helps, Plugitify is designed to be accessible to users with varying technical backgrounds. The AI assistant handles code generation, but understanding WordPress concepts (plugins, hooks, filters) will help you create more sophisticated plugins.

= Is my API key secure? =

Yes. API keys are stored securely in WordPress options table and are only transmitted over secure connections. The plugin follows WordPress security best practices including nonce verification, input sanitization, and role-based access control.

= Can I edit generated code? =

Absolutely! You can ask the AI assistant to modify any generated code, or you can manually edit files using the built-in file management tools. The AI can read existing code and make targeted modifications based on your requests.

= Does Plugitify work with existing plugins? =

Yes. Plugitify can analyze existing plugins, extract their structure, and help you modify or extend them. You can also use it to understand how other plugins are built.

= Can I export my plugins? =

Yes! Plugitify includes a built-in ZIP generation tool that creates distributable plugin packages. Simply ask the AI assistant to create a ZIP file of your plugin for review or distribution.

= How are my conversations stored? =

All chat conversations are stored in your WordPress database with full message history. Each chat session is associated with your user account, ensuring privacy and data isolation between users.

= What happens if the AI makes a mistake? =

You can always ask the AI to fix errors or revert changes. The chat history maintains context, so you can reference previous messages and corrections. Additionally, you have full control to manually edit any generated files.

= Can I use Plugitify on a production site? =

While Plugitify is fully functional, we recommend using it on development or staging environments when actively developing plugins. Once your plugin is complete and tested, you can deploy it to production.

= Does Plugitify require internet connection? =

Yes, an active internet connection is required to communicate with AI providers' APIs. The plugin itself runs locally, but AI processing happens through external API calls.

= Can I customize the AI's behavior? =

The AI assistant is pre-configured with WordPress development expertise, but you can guide its behavior through your conversation prompts. More advanced customization options may be available in future versions.

= Is there a limit on plugin size or complexity? =

There are no hard limits, but very large or complex plugins may require multiple conversations or sessions. The AI assistant will work through your requirements systematically.

= What file types can Plugitify work with? =

Plugitify can create and edit any text-based files (PHP, JavaScript, CSS, JSON, etc.) that are part of WordPress plugin development. Binary files are not supported.

= How do I report bugs or request features? =

Please visit the plugin's support page or GitHub repository to report issues or suggest improvements. Your feedback helps make Plugitify better for everyone.

== Screenshots ==

1. **Main Chat Interface** - The intuitive chat interface where you interact with the AI assistant to create plugins
2. **File Management** - View and manage plugin files directly from the interface
3. **Task Tracking** - Monitor development progress with built-in task management
4. **Settings Panel** - Configure AI provider and model preferences
5. **Chat History** - Access and resume previous development sessions
6. **Plugin Structure View** - Visualize and analyze plugin architecture

== Changelog ==

= 1.0.0 =
* **Initial Release** - The first stable version of Plugitify

**Core Features:**
* AI-powered chat interface for plugin development
* Support for multiple AI providers (OpenAI, Anthropic, Google, Deepseek)
* Complete file management system (create, read, edit, delete)
* Plugin structure extraction and analysis
* Task and progress tracking system
* WordPress debug integration
* Plugin ZIP export functionality
* Comprehensive chat history management
* Database migration system
* Modern Vue.js-based user interface
* Role-based security and permissions
* AJAX API for all operations

**Technical Implementation:**
* Modular PHP class architecture
* Namespace-based code organization
* WordPress coding standards compliance
* Secure input handling and validation
* Nonce verification for all requests
* User-specific data isolation
* Migration logging system

== Upgrade Notice ==

= 1.0.0 =
This is the initial release of Plugitify. No upgrade is needed - simply install and activate the plugin to get started. Make sure you have:

* WordPress 5.0 or higher
* PHP 7.4 or higher
* A valid API key for your preferred AI provider
* Administrator access to your WordPress installation

After activation, the plugin will automatically run database migrations to set up required tables for chat history, messages, tasks, and metadata storage.

== Developer Information ==

**Plugin Structure:**

```
plugitify/
├── assets/
│   ├── css/          # Stylesheets
│   ├── js/           # JavaScript files (Vue.js, Agent Framework)
│   └── img/          # Images and logos
├── include/
│   ├── class/        # PHP classes
│   │   ├── Plugitify_DB.php
│   │   ├── Plugitify-admin-menu.php
│   │   ├── Plugitify-chat-logs.php
│   │   ├── Plugitify-panel.php
│   │   └── Plugitify-tools-api.php
│   └── main.php      # Main plugin initialization
├── migrations/       # Database migration files
├── template/         # PHP templates
│   └── panel/        # Admin panel templates
└── plugitify.php     # Main plugin file
```

**Hooks and Filters:**

The plugin uses standard WordPress hooks and filters. Developers can extend functionality using:

* `wp_ajax_*` actions for custom AJAX endpoints
* WordPress admin menu hooks
* Template filters for custom UI modifications

**Database Tables:**

* `wp_plugitify_chat_history` - Stores chat sessions
* `wp_plugitify_messages` - Stores individual messages
* `wp_plugitify_tasks` - Stores task information
* `wp_plugitify_steps` - Stores task step details
* `wp_plugitify_chat_history_meta` - Chat metadata
* `wp_plugitify_messages_meta` - Message metadata

**API Endpoints:**

All AJAX endpoints are prefixed with `plugitify_` and require:
* Valid nonce verification
* User authentication
* `manage_options` capability

**Security Considerations:**

* All user inputs are sanitized
* File paths are validated
* Nonce verification on all AJAX requests
* Role-based access control
* User data isolation

== Support ==

For support, feature requests, or bug reports, please visit:

* **Plugin URI**: https://wpagentify.com
* **Author URI**: https://amirsafaridev.github.io/

== Credits ==

Plugitify is developed with the following technologies and libraries:

* **WordPress** - The world's most popular content management system
* **Vue.js** - Progressive JavaScript framework for building user interfaces
* **Agent Framework** - Custom JavaScript framework for AI agent management
* **PHP** - Server-side scripting language
* **MySQL/MariaDB** - Database management system

Special thanks to the WordPress community and all AI providers for their excellent APIs and services.

== License ==

This plugin is licensed under the GPLv2 or later.

```
Copyright (C) 2024 Amir Safari

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

== Additional Notes ==

**Best Practices:**

* Always test generated plugins in a development environment first
* Review generated code before deploying to production
* Keep your AI API keys secure and rotate them regularly
* Use descriptive chat titles to organize your development sessions
* Take advantage of task tracking to manage complex plugin development

**Tips for Better Results:**

* Be specific about your requirements when chatting with the AI
* Break down complex plugins into smaller, manageable features
* Use the plugin structure extraction tool to learn from existing plugins
* Leverage the debug tools when troubleshooting issues
* Export your plugins regularly for backup purposes

**Performance Considerations:**

* Large plugins may take longer to generate
* API rate limits may apply based on your AI provider
* Chat history is stored locally in your database
* Consider clearing old chat sessions to optimize database performance

**Compatibility:**

* Compatible with most WordPress themes
* Works alongside other plugins
* Follows WordPress coding standards
* Respects WordPress file permissions

**Future Roadmap:**

Future versions may include:
* Additional AI model support
* Code quality analysis
* Automated testing integration
* Version control integration
* Team collaboration features
* Plugin marketplace integration
