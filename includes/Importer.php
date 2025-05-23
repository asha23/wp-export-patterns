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

        // Accept single-object pattern JSON too
        if (isset($patterns['post_title'], $patterns['post_name'], $patterns['post_content'])) {
            $patterns = [$patterns];
        }

        $overwrite = isset($_POST['overwrite_existing']) && $_POST['overwrite_existing'] === '1';
        $writeToDisk = isset($_POST['write_to_disk']) && $_POST['write_to_disk'] === '1';

        $imported = 0;
        $skipped = 0;
        $overwritten = 0;
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
                if ($overwrite) {
                    $updated = wp_update_post([
                        'ID'           => $existing->ID,
                        'post_title'   => sanitize_text_field($pattern['post_title']),
                        'post_content' => wp_kses_post($pattern['post_content']),
                    ]);
                    if ($updated && !is_wp_error($updated)) {
                        $overwritten++;
                    } else {
                        $failed++;
                    }
                } else {
                    $skipped++;
                }
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

        // Optionally export all patterns to disk after import
        if ($writeToDisk) {
            foreach ($patterns as $pattern) {
                \WPExportPatterns\Sync\PatternSyncService::export_to_disk([
                    'post_title'   => $pattern['post_title'],
                    'post_name'    => $pattern['post_name'],
                    'post_content' => $pattern['post_content'],
                ]);
            }
        }

        update_option('_wp_export_notice', "import_result_{$imported}_{$skipped}_{$overwritten}_{$failed}");
    }
}
