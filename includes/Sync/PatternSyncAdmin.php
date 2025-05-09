<?php

namespace WPExportPatterns\Sync;

class PatternSyncAdmin
{
    public static function register(): void
    {
        add_submenu_page(
            'wp-export-patterns',         // Parent slug
            'Sync Block Patterns',        // Page title
            'Sync Patterns',              // Menu label (sidebar)
            'manage_options',             // Capability
            'wp-pattern-sync',            // Menu slug
            [self::class, 'render']       // Callback
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.');
        }

        // Handle sync
        if (
            isset($_POST['sync_slug'], $_POST['sync_nonce']) &&
            wp_verify_nonce($_POST['sync_nonce'], 'sync_pattern_' . $_POST['sync_slug'])
        ) {
            $slug = sanitize_title($_POST['sync_slug']);
            $success = PatternSyncService::import_pattern($slug);

            if ($success) {
                add_settings_error('pattern_sync', 'sync_success', "Pattern synced: $slug", 'updated');
            } else {
                add_settings_error('pattern_sync', 'sync_error', "Failed to sync: $slug", 'error');
            }

            // Refresh detection result
            $unsynced = PatternSyncService::detect_unsynced();
        } else {
            $unsynced = PatternSyncService::detect_unsynced();
        }

        echo '<div class="wrap">';
        echo '<h1>Pattern Sync</h1>';

        settings_errors('pattern_sync');

        if (empty($unsynced)) {
			echo '<div class="notice notice-success is-dismissible"><p>All patterns are in sync.</p></div>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Title</th><th>Slug</th><th>Status</th><th>Action</th></tr></thead><tbody>';

            foreach ($unsynced as $slug => $info) {
                echo '<tr>';
                echo '<td>' . esc_html($info['title']) . '</td>';
                echo '<td><code>' . esc_html($slug) . '</code></td>';
                echo '<td>' . esc_html($info['status']) . '</td>';
                echo '<td>';
                echo '<form method="post">';
                echo '<input type="hidden" name="sync_slug" value="' . esc_attr($slug) . '">';
                echo '<input type="hidden" name="sync_nonce" value="' . esc_attr(wp_create_nonce('sync_pattern_' . $slug)) . '">';
                echo '<input type="submit" class="button button-primary" value="Sync">';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
