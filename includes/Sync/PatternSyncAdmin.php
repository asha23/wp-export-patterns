<?php

namespace WPExportPatterns\Sync;

class PatternSyncAdmin
{
    public static function register(): void
    {
        add_submenu_page(
            'wp-export-patterns',
            'Sync Status',
            'Sync Status',
            'manage_options',
            'wp-pattern-sync',
            [self::class, 'render']
        );

        add_submenu_page(
            'wp-export-patterns',
            'Pattern Manager',
            'Pattern Manager',
            'manage_options',
            'pattern-manager',
            function () {
                wp_redirect(admin_url('site-editor.php?p=/pattern'));
                exit;
            }
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.');
        }

        // Sync
        if (
            isset($_POST['sync_slug'], $_POST['sync_nonce']) &&
            wp_verify_nonce($_POST['sync_nonce'], 'sync_pattern_' . $_POST['sync_slug'])
        ) {
            $slug = sanitize_title($_POST['sync_slug']);
            $source = PatternSyncService::load_from_disk();

            $result = isset($source[$slug])
                ? PatternSyncService::import_pattern($slug)
                : PatternSyncService::export_pattern($slug);

            if ($result === true) {
                add_settings_error('pattern_sync', 'sync_success', "Pattern synced: $slug", 'updated');
            } elseif (is_wp_error($result)) {
                add_settings_error('pattern_sync', 'sync_error', "Sync failed: $slug – " . $result->get_error_message(), 'error');
            } else {
                add_settings_error('pattern_sync', 'sync_error', "Sync failed: $slug – unknown error", 'error');
            }
        }

        // Trash
        if (
            isset($_POST['trash_slug'], $_POST['trash_nonce'], $_POST['confirm_trash']) &&
            wp_verify_nonce($_POST['trash_nonce'], 'trash_pattern_' . $_POST['trash_slug'])
        ) {
            $slug = sanitize_title($_POST['trash_slug']);
            $result = PatternSyncService::trash_pattern($slug);

            if ($result === true) {
                add_settings_error('pattern_sync', 'trashed', "Pattern trashed: $slug", 'updated');
            } elseif (is_wp_error($result)) {
                add_settings_error('pattern_sync', 'trash_failed', "Trash failed: $slug – " . $result->get_error_message(), 'error');
            }
        }

        // Restore
        if (
            isset($_POST['restore_slug'], $_POST['restore_nonce']) &&
            wp_verify_nonce($_POST['restore_nonce'], 'restore_pattern_' . $_POST['restore_slug'])
        ) {
            $slug = sanitize_title($_POST['restore_slug']);
            $result = PatternSyncService::restore_pattern($slug);

            if ($result === true) {
                add_settings_error('pattern_sync', 'restored', "Pattern restored: $slug", 'updated');
            } elseif (is_wp_error($result)) {
                add_settings_error('pattern_sync', 'restore_failed', "Restore failed: $slug – " . $result->get_error_message(), 'error');
            }
        }

        // Bulk trash
        if (
            isset($_POST['bulk_trash'], $_POST['bulk_trash_nonce']) &&
            wp_verify_nonce($_POST['bulk_trash_nonce'], 'bulk_trash_all')
        ) {
            $unsynced = PatternSyncService::detect_unsynced();
            $slugs = array_keys($unsynced);
            $results = PatternSyncService::bulk_trash($slugs);

            foreach ($results as $slug => $result) {
                if ($result === 'trashed') {
                    add_settings_error('pattern_sync', "trashed_$slug", "Trashed: $slug", 'updated');
                } else {
                    add_settings_error('pattern_sync', "trash_failed_$slug", "Failed to trash $slug – $result", 'error');
                }
            }
        }

        $unsynced = PatternSyncService::detect_unsynced();

        echo '<div class="wrap">';
        echo '<h1>Pattern Sync</h1>';
        settings_errors('pattern_sync');

        if (!empty($unsynced)) {
            echo '<form method="post" style="margin-bottom:1rem;">';
            echo '<input type="hidden" name="bulk_trash_nonce" value="' . esc_attr(wp_create_nonce('bulk_trash_all')) . '">';
            echo '<input type="submit" name="bulk_trash" class="button button-secondary" value="Trash All Out-of-Sync">';
            echo '</form>';
        }

        if (empty($unsynced)) {
            echo '<div class="notice notice-success is-dismissible"><p>Sweet! All patterns are in sync.</p></div>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Title</th><th>Slug</th><th>Status</th><th>Actions</th></tr></thead><tbody>';

            foreach ($unsynced as $slug => $info) {
                $status = esc_html($info['status']);
                $trashed = !empty($info['trashed']);

                echo '<tr>';
                echo '<td>' . esc_html($info['title']) . '</td>';
                echo '<td><code>' . esc_html($slug) . '</code></td>';
                echo "<td>$status</td>";
                echo '<td>';

                // Sync button
                echo '<form method="post" style="display:inline-block; margin-right:1rem;">';
                echo '<input type="hidden" name="sync_slug" value="' . esc_attr($slug) . '">';
                echo '<input type="hidden" name="sync_nonce" value="' . esc_attr(wp_create_nonce('sync_pattern_' . $slug)) . '">';
                echo '<input type="submit" class="button button-primary" value="Sync">';
                echo '</form>';

                if ($trashed) {
                    // Restore
                    echo '<form method="post" style="display:inline-block;">';
                    echo '<input type="hidden" name="restore_slug" value="' . esc_attr($slug) . '">';
                    echo '<input type="hidden" name="restore_nonce" value="' . esc_attr(wp_create_nonce('restore_pattern_' . $slug)) . '">';
                    echo '<input type="submit" class="button button-secondary" value="Undo Delete">';
                    echo '</form>';
                } else {
                    // Trash
                    echo '<form method="post" style="display:inline-block;">';
                    echo '<input type="hidden" name="trash_slug" value="' . esc_attr($slug) . '">';
                    echo '<input type="hidden" name="trash_nonce" value="' . esc_attr(wp_create_nonce('trash_pattern_' . $slug)) . '">';
                    echo '<label style="font-size: 12px; margin-right: 4px;"><input type="checkbox" name="confirm_trash" value="1"> Are you sure?</label>';
                    echo '<input type="submit" class="button button-secondary" value="Trash">';
                    echo '</form>';
                }

                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
