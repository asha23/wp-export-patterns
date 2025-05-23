<?php
/**
 * Plugin Name: BrightLocal - Sync Gutenberg Block Patterns
 * Description: Sync Gutenberg block patterns across multiple WordPress sites.
 * Version: 1.0.0
 * Author: Ash Whiting for BrightLocal
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/Exporter.php';
require_once __DIR__ . '/includes/Importer.php';
require_once __DIR__ . '/includes/Preview.php';
require_once __DIR__ . '/includes/Cleanup.php';
require_once __DIR__ . '/includes/Sync/PatternSyncService.php';
require_once __DIR__ . '/includes/Sync/PatternSyncAdmin.php';

// Redirect-safe routing
add_action('admin_init', function () {
    if (!is_admin()) {
        return;
    }

    \WPExportPatterns\Cleanup::clean_stale_previews();
    \WPExportPatterns\Cleanup::limit_session_log();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
        \WPExportPatterns\Importer::handle_upload();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
        \WPExportPatterns\Importer::handle_confirmed_import();
        exit;
    }

    \WPExportPatterns\Exporter::maybe_handle_export();
});

add_action('admin_notices', ['WPExportPatterns\\Exporter', 'show_notices']);

add_action('admin_menu', function () {
    add_menu_page(
        'Pattern Options',
        'Pattern Options',
        'manage_options',
        'wp-export-patterns',
        function () {
            // \WPExportPatterns\Exporter::maybe_handle_export();   // this must run on form submission
            \WPExportPatterns\Exporter::render_admin_page();     // then render the page
        },
        plugins_url('assets/icon.png', __FILE__)
    );

    add_submenu_page(
        null,
        'Import Preview',
        'Preview',
        'manage_options',
        'wp-pattern-preview',
        'WPExportPatterns\\Preview::render_preview_screen'
    );

    \WPExportPatterns\Sync\PatternSyncAdmin::register(); 
});
