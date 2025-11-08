

<?php
/**
 * Note: This template file is loaded directly via template_include filter,
 * not through WordPress's normal template hierarchy. Therefore, wp_enqueue_script()
 * cannot be used here. The script URL is properly escaped using esc_url().
 */
?>
    <?php
    /**
     * Note: Vue.js is loaded from local file (assets/js/vue.global.prod.js)
     * This template file is loaded directly via template_include filter,
     * not through WordPress's normal template hierarchy. Therefore, wp_enqueue_script()
     * cannot be used here. The script URL is properly escaped using esc_url().
     */
    ?>
    <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Template loaded directly via template_include, wp_enqueue_script() not available ?>
    <script src="<?php echo esc_url(PLUGITIFY_URL.'assets/js/vue.global.prod.js'); ?>"></script>
    <script>
    // Pass PHP values to JavaScript
    window.plugitifyConfig = {
        ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('plugitify_chat_nonce')); ?>',
        agentUrl: '<?php echo esc_url(PLUGITIFY_URL.'assets/js/agent.js?v='.filemtime(PLUGITIFY_DIR.'assets/js/agent.js')); ?>'
    };
    </script>
    <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Template loaded directly via template_include, wp_enqueue_script() not available ?>
    <script src="<?php echo esc_url(PLUGITIFY_URL.'assets/js/layout.js?v='.filemtime(PLUGITIFY_DIR.'assets/js/layout.js')); ?>"></script>
    <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Template loaded directly via template_include, wp_enqueue_script() not available ?>
    <script src="<?php echo esc_url(PLUGITIFY_URL.'assets/js/panel.js'); ?>"></script>
    </body>
</html>