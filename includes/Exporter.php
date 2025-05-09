<?php

namespace WPExportPatterns;

class Exporter
{
    public static function render_admin_page(): void
    {
        $blocks = get_posts([
            'post_type'      => 'wp_block',
            'posts_per_page' => -1,
        ]);

        echo '<div class="wrap">';
        echo '<h1>Import/Export Patterns</h1>';

        // Export form
        echo '<h2>Export Patterns</h2>';
        echo '<form method="post">';
        echo '<input type="hidden" name="wp_export_patterns_nonce" value="' . esc_attr(wp_create_nonce('wp_export_patterns')) . '">';

        if ($blocks) {
            echo '<ul>';
            foreach ($blocks as $block) {
                $title = esc_html($block->post_title ?: $block->post_name);
                echo '<li>';
                echo '<label>';
                echo '<input type="checkbox" name="export_ids[]" value="' . esc_attr($block->ID) . '"> ';
                echo $title;
                echo '</label>';
                echo '</li>';
            }
            echo '</ul>';
            echo '<input type="submit" name="export_patterns" class="button button-primary" value="Export Selected Patterns">';
        } else {
            echo '<p>No patterns found.</p>';
        }

        echo '</form>';

        // Import form
        echo '<hr><h2>Import Patterns</h2>';
        echo '<form method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="wp_import_patterns_nonce" value="' . esc_attr(wp_create_nonce('wp_import_patterns')) . '">';
        echo '<input type="file" name="import_file" required> ';
        echo '<input type="submit" name="import_patterns" class="button" value="Import">';
        echo '</form>';

        // Undo last import
        if ($session = get_option('_wp_export_last_session')) {
            echo '<hr><h2>Undo Last Import</h2>';
            echo '<form method="post">';
            echo '<input type="hidden" name="undo_import_nonce" value="' . esc_attr(wp_create_nonce('undo_import')) . '">';
            echo '<input type="submit" name="undo_import" class="button button-secondary" value="Undo Last Import">';
            echo '</form>';
        }

        // Session log
        echo '<hr><h2>Import Session Log</h2>';

        $log = get_option('_wp_export_sessions', []);

        if (empty($log)) {
            echo '<p>No import sessions found.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>Session ID</th>';
            echo '<th>Timestamp</th>';
            echo '<th>Imported</th>';
            echo '<th>Skipped</th>';
            echo '<th>Overwritten</th>';
            echo '<th>Failed</th>';
            echo '<th>Patterns</th>';
            echo '<th>Undo</th>';
            echo '</tr></thead><tbody>';

            foreach ($log as $session_id => $data) {
                $time = date('Y-m-d H:i:s', $data['timestamp']);
                $imported = (int) ($data['imported'] ?? 0);
                $skipped = (int) ($data['skipped'] ?? 0);
                $overwritten = (int) ($data['overwritten'] ?? 0);
                $failed = (int) ($data['failed'] ?? 0);
                $imported_titles = $data['imported_titles'] ?? [];
                $overwritten_titles = $data['overwritten_titles'] ?? [];

                echo '<tr>';
                echo '<td style="font-family:monospace;">' . esc_html($session_id) . '</td>';
                echo '<td>' . esc_html($time) . '</td>';
                echo '<td>' . esc_html($imported) . '</td>';
                echo '<td>' . esc_html($skipped) . '</td>';
                echo '<td>' . esc_html($overwritten) . '</td>';
                echo '<td>' . esc_html($failed) . '</td>';

                // Titles (inline!)
                echo '<td style="max-width:300px;">';
                if ($imported_titles) {
                    echo '<strong>Imported:</strong><ul style="margin:0; padding-left:1rem;">';
                    foreach ($imported_titles as $title) {
                        echo '<li>' . esc_html($title) . '</li>';
                    }
                    echo '</ul>';
                }
                if ($overwritten_titles) {
                    echo '<strong>Overwritten:</strong><ul style="margin:0; padding-left:1rem;">';
                    foreach ($overwritten_titles as $title) {
                        echo '<li>' . esc_html($title) . '</li>';
                    }
                    echo '</ul>';
                }
                echo '</td>';

                // Undo form
                echo '<td>';
                echo '<form method="post" style="margin:0;">';
                echo '<input type="hidden" name="undo_session_id" value="' . esc_attr($session_id) . '">';
                echo '<input type="hidden" name="undo_session_nonce" value="' . esc_attr(wp_create_nonce('undo_session_' . $session_id)) . '">';
                echo '<input type="submit" class="button" value="Undo">';
                echo '</form>';
                echo '</td>';

                echo '</tr>';
            }

            echo '</tbody></table>';
        }

    public static function maybe_handle_export(): void
    {
        if (isset($_POST['undo_import']) && check_admin_referer('undo_import', 'undo_import_nonce')) {
            $session = get_option('_wp_export_last_session');
            if ($session) {
                $blocks = get_posts([
                    'post_type'      => 'wp_block',
                    'meta_key'       => '_import_session',
                    'meta_value'     => $session,
                    'posts_per_page' => -1,
                ]);

                $deleted = 0;
                foreach ($blocks as $block) {
                    if (wp_delete_post($block->ID, true)) {
                        $deleted++;
                    }
                }

                delete_option('_wp_export_last_session');

                update_option('_wp_export_notice', $deleted > 0
                    ? "undo_success_$deleted"
                    : "undo_none");
            } else {
                update_option('_wp_export_notice', 'undo_none');
            }
        }

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
                ];
            }, $blocks);

            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="block-patterns-export.json"');
            echo json_encode($export_data, JSON_PRETTY_PRINT);
            exit;
        }

        if (isset($_POST['undo_session_id']) && isset($_POST['undo_session_nonce'])) {
            $session_id = sanitize_text_field($_POST['undo_session_id']);
            if (!wp_verify_nonce($_POST['undo_session_nonce'], 'undo_session_' . $session_id)) {
                update_option('_wp_export_notice', 'invalid_nonce');
                return;
            }

            $blocks = get_posts([
                'post_type'      => 'wp_block',
                'meta_key'       => '_import_session',
                'meta_value'     => $session_id,
                'posts_per_page' => -1,
            ]);

            $deleted = 0;
            foreach ($blocks as $block) {
                if (wp_delete_post($block->ID, true)) {
                    $deleted++;
                }
            }

            $log = get_option('_wp_export_sessions', []);
            unset($log[$session_id]);
            update_option('_wp_export_sessions', $log);

            update_option('_wp_export_notice', $deleted > 0
                ? "undo_success_$deleted"
                : "undo_none");

            return;
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

        $class = 'notice-success';
        $message = '';

        switch (true) {
            case $notice === 'invalid_nonce':
                $class = 'notice-error';
                $message = 'Security check failed. Please try again.';
                break;

            case $notice === 'no_selection':
                $class = 'notice-warning';
                $message = 'Please select at least one pattern to export.';
                break;

            case $notice === 'import_invalid_request':
                $class = 'notice-error';
                $message = 'Import failed: invalid request.';
                break;

            case $notice === 'import_invalid_json':
                $class = 'notice-error';
                $message = 'Import failed: invalid or corrupt JSON file.';
                break;

            case $notice === 'import_none':
                $class = 'notice-warning';
                $message = 'No new patterns were imported. All may already exist.';
                break;

            case str_starts_with($notice, 'import_result_'):
                $parts = explode('_', $notice);
                $imported    = (int) ($parts[2] ?? 0);
                $skipped     = (int) ($parts[3] ?? 0);
                $overwritten = (int) ($parts[4] ?? 0);
                $failed      = (int) ($parts[5] ?? 0);

                $summary = [];

                if ($imported > 0) {
                    $summary[] = "$imported imported";
                }
                if ($skipped > 0) {
                    $summary[] = "$skipped skipped";
                }
                if ($overwritten > 0) {
                    $summary[] = "$overwritten overwritten";
                }
                if ($failed > 0) {
                    $summary[] = "$failed failed";
                    $class = 'notice-error';
                }

                $message = 'Import complete: ' . implode(', ', $summary) . '.';
                break;

            case str_starts_with($notice, 'undo_success_'):
                $count = (int) str_replace('undo_success_', '', $notice);
                $message = "$count pattern" . ($count === 1 ? ' was' : 's were') . " successfully removed.";
                break;

            case $notice === 'undo_none':
                $message = 'Nothing to undo.';
                $class = 'notice-warning';
                break;
        }

        if ($message) {
            printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($message));
        }
    }
}
