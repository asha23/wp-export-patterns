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
        echo '<h1>WP Export Patterns</h1>';

        echo '<h2>Export</h2>';
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

        echo '<hr><h2>Import</h2>';
        echo '<form method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="wp_import_patterns_nonce" value="' . esc_attr(wp_create_nonce('wp_import_patterns')) . '">';
        echo '<input type="file" name="import_file" required> ';
        echo '<input type="submit" name="import_patterns" class="button" value="Import">';
        echo '</form>';
        echo '</div>';
    }

    public static function maybe_handle_export(): void
    {
        if (
            isset($_POST['export_patterns'], $_POST['export_ids']) &&
            check_admin_referer('wp_export_patterns', 'wp_export_patterns_nonce')
        ) {
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
            header('Content-Disposition: attachment; filename=\"block-patterns-export.json\"');
            echo json_encode($export_data, JSON_PRETTY_PRINT);
            exit;
        }
    }
}
