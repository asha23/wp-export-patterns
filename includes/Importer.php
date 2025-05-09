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
            wp_safe_redirect(admin_url('admin.php?page=wp-export-patterns'));
            exit;
        }

        $json = file_get_contents($_FILES['import_file']['tmp_name']);
        $patterns = json_decode($json, true);

        if (!is_array($patterns)) {
            update_option('_wp_export_notice', 'import_invalid_json');
            wp_safe_redirect(admin_url('admin.php?page=wp-export-patterns'));
            exit;
        }

        $session_id = wp_generate_uuid4();
        $data = [
            'session_id' => $session_id,
            'patterns'   => $patterns,
            'overwrite'  => false,
            'timestamp'  => time(),
        ];

        update_option("_wp_preview_session_$session_id", $data, false);
        update_option('_wp_export_preview_session', $session_id); // for redirect

        wp_safe_redirect(admin_url('admin.php?page=wp-pattern-preview'));
        exit;
    }

    public static function handle_confirmed_import(): void
    {
        if (
            !isset($_POST['session_id'], $_POST['confirm_import']) ||
            !check_admin_referer('wp_confirm_import', 'confirm_import_nonce')
        ) {
            update_option('_wp_export_notice', 'import_invalid_request');
            wp_safe_redirect(admin_url('admin.php?page=wp-export-patterns'));
            exit;
        }

        $session_id = sanitize_text_field($_POST['session_id']);
        $session_data = get_option("_wp_preview_session_$session_id");

        if (!$session_data || !is_array($session_data['patterns'])) {
            update_option('_wp_export_notice', 'import_invalid_json');
            wp_safe_redirect(admin_url('admin.php?page=wp-export-patterns'));
            exit;
        }

        $patterns = $session_data['patterns'];
        $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';

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

            if ($existing && !$overwrite) {
                $skipped++;
                continue;
            }

            if ($existing && $overwrite) {
                $updated = wp_update_post([
                    'ID'           => $existing->ID,
                    'post_title'   => sanitize_text_field($pattern['post_title']),
                    'post_content' => wp_kses_post($pattern['post_content']),
                ]);

                if ($updated && !is_wp_error($updated)) {
                    $overwritten++;
                    update_post_meta($existing->ID, '_import_session', $session_id);
                    continue;
                } else {
                    $failed++;
                    continue;
                }
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
                add_post_meta($inserted, '_import_session', $session_id);
            } else {
                $failed++;
            }
        }

        delete_option("_wp_preview_session_$session_id");

        $summary_key = "import_result_{$imported}_{$skipped}_{$overwritten}_{$failed}";
        update_option('_wp_export_notice', $summary_key);
        update_option('_wp_export_last_session', $session_id);

        // track sessions in history log
        $log = get_option('_wp_export_sessions', []);
        $log[$session_id] = [
            'timestamp' => time(),
            'imported' => $imported,
            'skipped' => $skipped,
            'overwritten' => $overwritten,
            'failed' => $failed,
        ];
        update_option('_wp_export_sessions', $log);

        wp_safe_redirect(admin_url('admin.php?page=wp-export-patterns'));
        exit;
    }
}
