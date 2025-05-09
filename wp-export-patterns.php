<?php
/**
 * Plugin Name: BrightLocal - Export Gutenberg Block Patterns
 * Description: Export and import Gutenberg block patterns across multiple WordPress sites.
 * Version: 1.0.0
 * Author: Ash Whiting for BrightLocal
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/Exporter.php';
require_once __DIR__ . '/includes/Importer.php';
require_once __DIR__ . '/includes/Preview.php';

add_action('admin_init', ['WPExportPatterns\\Exporter', 'maybe_handle_export']);
add_action('admin_notices', ['WPExportPatterns\\Exporter', 'show_notices']);

add_action('admin_menu', function () {
    add_menu_page(
        'Import/Export Patterns',
        'Import/Export Patterns',
        'manage_options',
        'wp-export-patterns',
        'WPExportPatterns\\Exporter::render_admin_page'
    );

    add_submenu_page(
        null,
        'Import Preview',
        'Preview',
        'manage_options',
        'wp-pattern-preview',
        'WPExportPatterns\\Preview::render_preview_screen'
    );
});
