<?php

namespace WPExportPatterns;

use WPExportPatterns\Sync\PatternSyncService;

class Exporter
{
    public static function render_admin_page(): void
    {
        $blocks = get_posts([
            'post_type'      => 'wp_block',
            'posts_per_page' => -1,
        ]);

        $syncFolder = PatternSyncService::get_pattern_path();
        if (!is_dir($syncFolder)) {
            wp_mkdir_p($syncFolder);
        }

        echo '<div class="wrap">';
        echo '<h1>Pattern Manager</h1>';

        // 🧠 Show sync status
        $unsynced = PatternSyncService::detect_unsynced();

        if (empty($unsynced)) {
            echo '<div class="notice notice-success inline"><p>All patterns are in sync.</p></div>';
        } else {
            echo '<div class="notice notice-warning inline">';
            echo '<p><strong>' . count($unsynced) . ' pattern(s) out of sync:</strong></p>';
            echo '<ul style="margin-left:1em;">';
            foreach ($unsynced as $slug => $info) {
                $status = $info['status'] === 'missing_from_disk' ? 'Missing from disk' : 'Outdated';
                echo '<li>' . esc_html($info['title']) . ' <em>(' . $status . ')</em></li>';
            }
            echo '</ul>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wp-pattern-sync')) . '" class="button">Open Sync Tool</a></p>';
            echo '</div>';
        }

        // Table of all patterns
        echo '<hr><h2>All Patterns</h2>';

        if ($blocks) {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Title</th><th>Slug</th><th>Actions</th></tr></thead><tbody>';

            foreach ($blocks as $block) {
                $title = esc_html($block->post_title ?: $block->post_name);
                $slug  = esc_html($block->post_name);

                echo '<tr>';
                echo '<td>' . $title . '</td>';
                echo '<td><code>' . $slug . '</code></td>';
                echo '<td>';
                echo '<form method="post" style="display:inline-block;">';
                echo '<input type="hidden" name="delete_slug" value="' . esc_attr($slug) . '">';
                echo '<input type="hidden" name="delete_nonce" value="' . esc_attr(wp_create_nonce('delete_pattern_' . $slug)) . '">';
                echo '<label style="font-size: 12px;"><input type="checkbox" name="confirm_delete" value="1"> Are you sure?</label> ';
                echo '<input type="submit" name="delete_pattern" class="button button-secondary" value="Delete">';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>No patterns found.</p>';
        }

        echo '</div>';
    }

    public static function maybe_handle_export(): void
    {
        if (
            isset($_POST['delete_pattern'], $_POST['delete_slug'], $_POST['delete_nonce'], $_POST['confirm_delete']) &&
            wp_verify_nonce($_POST['delete_nonce'], 'delete_pattern_' . $_POST['delete_slug'])
        ) {
            $slug = sanitize_title($_POST['delete_slug']);

            // Delete from DB
            $post = get_page_by_path($slug, OBJECT, 'wp_block');
            if ($post) {
                wp_delete_post($post->ID, true);
            }

            // Delete from disk
            $file = PatternSyncService::get_pattern_path() . '/' . sanitize_file_name($slug) . '.json';
            if (file_exists($file)) {
                unlink($file);
            }

            update_option('_wp_export_notice', "pattern_deleted_$slug");
        }
    }

    public static function show_notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = get_option('_wp_export_notice');
        if (!$notice) {
            return;
        }

        delete_option('_wp_export_notice');

        if (str_starts_with($notice, 'pattern_deleted_')) {
            $slug = str_replace('pattern_deleted_', '', $notice);
            echo '<div class="notice notice-success is-dismissible"><p>Pattern deleted: ' . esc_html($slug) . '</p></div>';
        }
    }
}
