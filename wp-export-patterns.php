<?php
/**
 * Plugin Name: WP Export Patterns
 * Description: Export and import Gutenberg block patterns across multiple WordPress sites.
 * Version: 1.0.0
 * Author: Ash Whiting for BrightLocal
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/Exporter.php';
require_once __DIR__ . '/includes/Importer.php';

add_action('admin_menu', function () {
    add_menu_page(
        'Export/Import Patterns',
        'WP Export Patterns',
        'manage_options',
        'wp-export-patterns',
        'WPExportPatterns\\Exporter::render_admin_page'
    );
});
