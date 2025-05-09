<?php

namespace WPExportPatterns;

class Exporter
{
    public static function render_admin_page(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
            Importer::handle_import();
        }

        echo '<div class="wrap">';
        echo '<h1>WP Export Patterns</h1>';

        echo '<h2>Export</h2>';
        echo '<form method="post">';
        echo '<input type="submit" name="export_patterns" class="button button-primary" value="Export Patterns">';
        echo '</form>';

        if (isset($_POST['export_patterns'])) {
            self::export();
        }

        echo '<hr><h2>Import</h2>';
        echo '<form method="post" enctype="multipart/form-data">';
        echo '<input type="file" name="import_file" required> ';
        echo '<input type="submit" class="button" value="Import">';
        echo '</form>';
        echo '</div>';
    }

    public static function export(): void
    {
        $blocks = get_posts([
            'post_type'      => 'wp_block',
            'posts_per_page' => -1,
        ]);

        $export_data = array_map(function ($block) {
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
}
