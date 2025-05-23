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
        echo '<h1>Pattern Sync</h1>';

        // Show sync status
        $patterns = PatternSyncService::detect_unsynced();

        // Filter only the truly out-of-sync ones
        $outOfSync = array_filter($patterns, fn($p) =>
            in_array($p['status'], ['missing_from_disk', 'outdated', 'missing_from_db'], true)
        );

        error_log('[OUT OF SYNC] ' . print_r($outOfSync, true));
        if (empty($outOfSync)) {
            echo '<div class="notice notice-success is-dismissible"><p>Sweet! All patterns are in sync.</p></div>';
        } else {
            echo '<div class="notice notice-warning inline">';
            echo '<p><strong>' . count($outOfSync) . ' pattern' . (count($outOfSync) === 1 ? '' : 's') . ' out of sync:</strong></p>';
            echo '<ul style="margin-left:1em;">';
            foreach ($outOfSync as $slug => $info) {
                $label = match ($info['status']) {
                    'missing_from_disk' => 'Missing from disk',
                    'outdated' => 'Outdated',
                    'missing_from_db' => 'Missing from DB',
                    default => ucfirst($info['status']),
                };
                echo '<li>' . esc_html($info['title']) . ' <em>(' . $label . ')</em></li>';
            }
            echo '</ul>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wp-pattern-sync')) . '" class="button">Open Sync Tool</a></p>';
            echo '</div>';
        }

        echo '<hr><h2>Export Selected Patterns</h2>';
        echo '<form method="post">';
        echo '<input type="hidden" name="wp_export_patterns_nonce" value="' . esc_attr(wp_create_nonce('wp_export_patterns')) . '">';

        if ($blocks) {
            echo '<table class="widefat striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th style="width: 50px;">Export</th>';
            echo '<th>Pattern</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($blocks as $block) {
                $title = esc_html($block->post_title ?: $block->post_name);
                $id = esc_attr($block->ID);

                echo '<tr>';
                echo '<td style="text-align: center;"><input type="checkbox" name="export_ids[]" value="' . $id . '"></td>';
                echo '<td>' . $title . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';

            echo '<p style="margin-top: 1rem;">';
            echo '<input type="submit" name="export_patterns" class="button button-primary" value="Export Selected Patterns">';
            echo '</p>';
        } else {
            echo '<p>No patterns found.</p>';
        }

        echo '</form>';


        echo '<hr><h2>Import Patterns</h2>';
        echo '<form method="post" enctype="multipart/form-data" style="max-width: 600px;">';
        echo '<input type="hidden" name="wp_import_patterns_nonce" value="' . esc_attr(wp_create_nonce('wp_import_patterns')) . '">';

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="import_file">Pattern JSON File</label></th>';
        echo '<td><input type="file" name="import_file" id="import_file" required></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Overwrite Existing</th>';
        echo '<td><label><input type="checkbox" name="overwrite_existing" value="1" checked> Overwrite patterns with matching slugs</label></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Write to Disk</th>';
        echo '<td><label><input type="checkbox" name="write_to_disk" value="1" checked> Also add to disk after import</label></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"></th>';
        echo '<td><input type="submit" name="import_patterns" class="button button-primary" value="Import"></td>';
        echo '</tr>';
        echo '</table>';

        echo '</form>';


        // Undo last import
        // if ($session = get_option('_wp_export_last_session')) {
        //     echo '<hr><h2>Undo Last Import</h2>';
        //     echo '<form method="post">';
        //     echo '<input type="hidden" name="undo_import_nonce" value="' . esc_attr(wp_create_nonce('undo_import')) . '">';
        //     echo '<input type="submit" name="undo_import" class="button button-secondary" value="Undo Last Import">';
        //     echo '</form>';
        // }

        // Deletion table
        echo '<hr><h2>Delete Patterns</h2>';
        if ($blocks) {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Title</th><th>Slug</th><th>Actions</th><th></th></tr></thead><tbody>';

            foreach ($blocks as $block) {
                $slug = esc_attr($block->post_name);
                $title = esc_html($block->post_title ?: $slug);
            
                echo '<tr>';
                echo '<td>' . $title . '</td>';
                echo '<td><code>' . $slug . '</code></td>';
            
                // Are you sure checkbox cell
                echo '<td>';
                echo '<form method="post">';
                echo '<label><input type="checkbox" name="confirm_delete" value="1"> Are you sure?</label>';
                echo '</td>';
            
                // Delete button cell
                echo '<td>';
                
                echo '<input type="hidden" name="delete_slug" value="' . $slug . '">';
                echo '<input type="hidden" name="delete_nonce" value="' . esc_attr(wp_create_nonce('delete_pattern_' . $slug)) . '">';
                echo '<input type="submit" name="delete_pattern" class="button button-secondary" value="Delete">';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            

            echo '</tbody></table>';
        } else {
            echo '<p>No patterns available to delete.</p>';
        }

        echo '</div>';
    }


    public static function maybe_handle_export(): void
    {
        // Handle individual delete
        if (
            isset($_POST['delete_pattern'], $_POST['delete_slug'], $_POST['delete_nonce'], $_POST['confirm_delete']) &&
            wp_verify_nonce($_POST['delete_nonce'], 'delete_pattern_' . $_POST['delete_slug'])
        ) {
            $slug = sanitize_title($_POST['delete_slug']);

            $post = get_page_by_path($slug, OBJECT, 'wp_block');
            $deleted_from_db = false;

            if ($post) {
                $deleted_from_db = wp_delete_post($post->ID, true) !== false;
            }

            // Only delete file if DB deletion succeeded
            if ($deleted_from_db) {
                $file = PatternSyncService::get_pattern_path() . '/' . sanitize_file_name($slug) . '.json';
                if (file_exists($file)) {
                    unlink($file);
                }
            }

            update_option('_wp_export_notice', "pattern_deleted_$slug");
        }

        // Export selected patterns
        if (isset($_POST['export_patterns'])) {
            if (!check_admin_referer('wp_export_patterns', 'wp_export_patterns_nonce')) {
                update_option('_wp_export_notice', 'invalid_nonce');
                return;
            }

            if (empty($_POST['export_ids']) || !is_array($_POST['export_ids'])) {
                update_option('_wp_export_notice', 'no_selection');
                return;
            }

            $ids = array_map('intval', $_POST['export_ids']);

            $blocks = get_posts([
                'post_type' => 'wp_block',
                'post__in' => $ids,
                'posts_per_page' => -1,
            ]);

            $export_data = array_map(static function ($block) {
                return [
                    'post_title'   => $block->post_title,
                    'post_name'    => $block->post_name,
                    'post_content' => $block->post_content,
                    'modified'     => get_post_modified_time('c', true, $block),
                ];
            }, $blocks);

            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="block-patterns-export.json"');
            echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
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

        if (str_starts_with($notice, 'import_result_')) {
            $parts = explode('_', $notice);
            $imported     = (int) ($parts[1] ?? 0);
            $skipped      = (int) ($parts[2] ?? 0);
            $overwritten  = (int) ($parts[3] ?? 0);
            $db_failed    = (int) ($parts[4] ?? 0);
            $disk_failed  = (int) ($parts[5] ?? 0);
            $disk_skipped = (int) ($parts[6] ?? 0);
        
            $total_activity = $imported + $skipped + $overwritten + $db_failed + $disk_failed + $disk_skipped;
        
            if ($total_activity === 0) {
                echo '<div class="notice notice-info is-dismissible"><p>No patterns imported.</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo sprintf(
                    '%d imported, %d skipped, %d overwritten, %d DB Entry Already Exists, %d disk write errors, %d disk skipped (no change).',
                    $imported,
                    $skipped,
                    $overwritten,
                    $db_failed,
                    $disk_failed,
                    $disk_skipped
                );
                echo '</p></div>';
            }
        }
        
        
    }
}
