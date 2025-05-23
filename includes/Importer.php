<?php

namespace WPExportPatterns;

class Importer
{
    public static function handle_upload(): void
    {
        if (
            !isset($_FILES['import_file']) ||
            !check_admin_referer('wp_import_patterns', 'wp_import_patterns_nonce')
        ) {
            update_option('_wp_export_notice', 'import_invalid_request');
            return;
        }

        $json = file_get_contents($_FILES['import_file']['tmp_name']);
        $patterns = json_decode($json, true);

        if (!is_array($patterns)) {
            update_option('_wp_export_notice', 'import_invalid_json');
            return;
        }

        $imported = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($patterns as $pattern) {
            if (
                !isset($pattern['post_title'], $pattern['post_name'], $pattern['post_content'])
            ) {
                $failed++;
                continue;
            }

            $existing = get_page_by_path($pattern['post_name'], OBJECT, 'wp_block');

            if ($existing) {
                $skipped++;
                continue;
            }

            $inserted = wp_insert_post([
                'post_type'    => 'wp_block',
                'post_title'   => sanitize_text_field($pattern['post_title']),
                'post_name'    => sanitize_title($pattern['post_name']),
                'post_content' => wp_kses_post($pattern['post_content']),
                'post_status'  => 'publish',
            ]);

            if ($inserted && !is_wp_error($inserted)) {
                $imported++;
            } else {
                $failed++;
            }
        }

        update_option('_wp_export_notice', "import_result_{$imported}_{$skipped}_0_{$failed}");
    }
}
