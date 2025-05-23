<?php

namespace WPExportPatterns;

class Importer
{
    public static function handle_upload(): void
    {
        $imported = 0;
        $skipped = 0;
        $overwritten = 0;
        $db_failed = 0;
        $disk_failed = 0;
        $disk_skipped = 0;

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
                $db_failed++;
                continue;
            }
        
            $post_title   = sanitize_text_field($pattern['post_title']);
            $post_name    = sanitize_title($pattern['post_name']);
            $post_content = wp_kses_post($pattern['post_content']);
        
            $existing = get_posts([
                'post_type'   => 'wp_block',
                'name'        => $post_name,
                'post_status' => 'publish',
                'numberposts' => 1,
            ]);
            $existing = !empty($existing) ? $existing[0] : null;
        
            if ($existing && !$overwrite) {
                $skipped++;
                continue;
            }
        
            if ($existing && $overwrite) {
                $updated = wp_update_post([
                    'ID'           => $existing->ID,
                    'post_title'   => $post_title,
                    'post_content' => $post_content,
                ]);
        
                if ($updated && !is_wp_error($updated)) {
                    $overwritten++;
                } else {
                    $db_failed++;
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
                $db_failed++;
                error_log('[IMPORT FAIL] wp_insert_post failed for: ' . $pattern['post_name']);
                if (is_wp_error($inserted)) {
                    error_log('[IMPORT ERROR] ' . $inserted->get_error_message());
                }
            }
        }
        

        // Optionally export all patterns to disk after import
        if ($writeToDisk) {
            foreach ($patterns as $pattern) {
                $result = \WPExportPatterns\Sync\PatternSyncService::export_to_disk([
                    'post_title'   => $pattern['post_title'],
                    'post_name'    => $pattern['post_name'],
                    'post_content' => $pattern['post_content'],
                ]);
        
                if ($result instanceof \WP_Error || $result === false) {
                    $disk_failed++;
                } elseif ($result === true) {
                    // If true and file already matched, assume skip
                    $slug = $pattern['post_name'];
                    $folder = \WPExportPatterns\Sync\PatternSyncService::get_pattern_path();
                    $file = $folder . '/' . sanitize_file_name($slug) . '.json';
        
                    if (file_exists($file)) {
                        $existing = json_decode(file_get_contents($file), true);
                        $match = $existing['post_content'] === $pattern['post_content'];
                        if ($match) {
                            $disk_skipped++;
                        }
                    }
                }
            }
        }

        update_option('_wp_export_notice', "import_result_{$imported}_{$skipped}_{$overwritten}_{$db_failed}_{$disk_failed}_{$disk_skipped}");

    }
}
