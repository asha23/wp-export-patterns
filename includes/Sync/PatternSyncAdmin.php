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

        // Dummy Pattern Manager link to Site Editor
        add_submenu_page(
            'wp-export-patterns',
            'Pattern Manager',
            'Pattern Manager',
            'manage_options',
            'pattern-manager-dummy',
            '__return_null'
        );

        global $submenu;
        if (isset($submenu['wp-export-patterns'])) {
            foreach ($submenu['wp-export-patterns'] as &$item) {
                if ($item[2] === 'pattern-manager-dummy') {
                    $item[2] = 'site-editor.php?p=/pattern';
                    break;
                }
            }
        }
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.');
        }

        // Sync
        if (in_array($status, ['outdated', 'missing_from_disk', 'missing_from_db'], true)) {
            echo '<form method="post" style="display:inline-block; margin-right:1rem;">';
            echo '<input type="hidden" name="sync_slug" value="' . esc_attr($slug) . '">';
            echo '<input type="hidden" name="sync_nonce" value="' . esc_attr(wp_create_nonce('sync_pattern_' . $slug)) . '">';

            $buttonLabel = match ($status) {
                'missing_from_db' => 'Import to DB',
                'missing_from_disk' => 'Export to Disk',
                'outdated' => 'Sync',
                default => 'Sync',
            };

            echo '<input type="submit" class="button button-primary" value="' . esc_attr($buttonLabel) . '">';
            echo '</form>';
        }

        // TRASH
        if (
            isset($_POST['trash_slug'], $_POST['trash_nonce'], $_POST['confirm_trash']) &&
            wp_verify_nonce($_POST['trash_nonce'], 'trash_pattern_' . $_POST['trash_slug'])
        ) {
            $slug = sanitize_title($_POST['trash_slug']);
            $result = PatternSyncService::trash_pattern($slug);

            if ($result === true) {
                add_settings_error('pattern_sync', 'trashed', "Pattern trashed: $slug", 'updated');
            } elseif (is_wp_error($result)) {
                add_settings_error('pattern_sync', 'trash_failed', "Trash failed: $slug - " . $result->get_error_message(), 'error');
            }
        }

        // RESTORE
        if (
            isset($_POST['restore_slug'], $_POST['restore_nonce']) &&
            wp_verify_nonce($_POST['restore_nonce'], 'restore_pattern_' . $_POST['restore_slug'])
        ) {
            $slug = sanitize_title($_POST['restore_slug']);
            $result = PatternSyncService::restore_pattern($slug);

            if ($result === true) {
                add_settings_error('pattern_sync', 'restored', "Pattern restored: $slug", 'updated');
            } elseif (is_wp_error($result)) {
                add_settings_error('pattern_sync', 'restore_failed', "Restore failed: $slug - " . $result->get_error_message(), 'error');
            }
        }

        $patterns = PatternSyncService::detect_unsynced();

        echo '<div class="wrap">';
        echo '<h1>Pattern Sync</h1>';
        settings_errors('pattern_sync');

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Title</th><th>Slug</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead><tbody>';

        foreach ($patterns as $slug => $info) {
            $status = esc_html($info['status']);
            $title = esc_html($info['title']);
            $notes = esc_html($info['notes'] ?? '');
            $trashed = !empty($info['trashed']);

            echo '<tr>';
            echo "<td>{$title}</td>";
            echo "<td><code>{$slug}</code></td>";
            echo "<td>{$status}</td>";
            echo "<td>{$notes}</td>";
            echo '<td>';

            // Sync
            if (in_array($status, ['outdated', 'missing_from_disk'], true)) {
                echo '<form method="post" style="display:inline-block; margin-right:1rem;">';
                echo '<input type="hidden" name="sync_slug" value="' . esc_attr($slug) . '">';
                echo '<input type="hidden" name="sync_nonce" value="' . esc_attr(wp_create_nonce('sync_pattern_' . $slug)) . '">';
                echo '<input type="submit" class="button button-primary" value="Sync">';
                echo '</form>';
            }

            // Trash
            if ($status === 'orphaned') {
                echo '<form method="post" style="display:inline-block;">';
                echo '<input type="hidden" name="trash_slug" value="' . esc_attr($slug) . '">';
                echo '<input type="hidden" name="trash_nonce" value="' . esc_attr(wp_create_nonce('trash_pattern_' . $slug)) . '">';
                echo '<label style="font-size: 12px; margin-right: 4px;"><input type="checkbox" name="confirm_trash" value="1"> Are you sure?</label>';
                echo '<input type="submit" class="button button-secondary" value="Trash">';
                echo '</form>';
            }

            // Restore
            if ($trashed) {
                echo '<form method="post" style="display:inline-block;">';
                echo '<input type="hidden" name="restore_slug" value="' . esc_attr($slug) . '">';
                echo '<input type="hidden" name="restore_nonce" value="' . esc_attr(wp_create_nonce('restore_pattern_' . $slug)) . '">';
                echo '<input type="submit" class="button button-secondary" value="Undo Delete">';
                echo '</form>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}
