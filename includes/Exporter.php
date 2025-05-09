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

        // EXPORT FORM
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

        // IMPORT FORM
        echo '<hr><h2>Import Patterns</h2>';
        echo '<form method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="wp_import_patterns_nonce" value="' . esc_attr(wp_create_nonce('wp_import_patterns')) . '">';
        echo '<input type="file" name="import_file" required> ';
        echo '<input type="submit" name="import_patterns" class="button" value="Import">';
        echo '</form>';
        echo '</div>';
    }

    public static function maybe_handle_export(): void
    {
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

            if (!headers_sent()) {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="block-patterns-export.json"');
                echo json_encode($export_data, JSON_PRETTY_PRINT);
                exit;
            }
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
                $imported = (int) $parts[2];
                $skipped  = (int) $parts[3];
                $failed   = (int) $parts[4];

                $summary = [];

                if ($imported > 0) {
                    $summary[] = "$imported imported";
                }
                if ($skipped > 0) {
                    $summary[] = "$skipped skipped (already exist)";
                }
                if ($failed > 0) {
                    $summary[] = "$failed failed";
                    $class = 'notice-error';
                }

                $message = 'Import complete: ' . implode(', ', $summary) . '.';
                break;
        }

        if ($message) {
            printf('<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
        }
    }
}
