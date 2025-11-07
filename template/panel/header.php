<?php
/**
 * Note: This template file is loaded directly via template_include filter,
 * not through WordPress's normal template hierarchy. Therefore, wp_enqueue_style()
 * cannot be used here. The stylesheet URL is properly escaped using esc_url().
 */
?>
<html>
    <head>
        <title>Plugitify</title>
        <link rel="stylesheet" href="<?php echo esc_url(PLUGITIFY_URL.'assets/css/panel.css'); ?>">
    </head>
    <body>