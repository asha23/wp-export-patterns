<?php

namespace WPExportPatterns;

class Importer
{
    public static function handle_import(): void
    {
        $json = file_get_contents($_FILES['import_file']['tmp_name']);
        $patterns = json_decode($json, true);

        foreach ($patterns as $pattern) {
            $exists = get_page_by_path($pattern['post_name'], OBJECT, 'wp_block');
            if ($exists) {
                continue; // Skip existing
            }

            wp_insert_post([
                'post_type'    => 'wp_block',
                'post_title'   => sanitize_text_field($pattern['post_title']),
                'post_name'    => sanitize_title($pattern['post_name']),
                'post_content' => wp_kses_post($pattern['post_content']),
                'post_status'  => 'publish',
            ]);
        }

        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>Patterns imported successfully.</p></div>';
        });
    }
}
