<?php

namespace WPExportPatterns;

class Cleanup
{
    public static function clean_stale_previews(): void
    {
        global $wpdb;

        $prefix = '_wp_preview_session_';
        $threshold = time() - 1800; // 30 minutes ago

        $options = $wpdb->get_results(
            $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%')
        );

        foreach ($options as $opt) {
            $session = get_option($opt->option_name);
            if (!is_array($session)) continue;

            if (!isset($session['timestamp'])) {
                // Legacy sessions add timestamp now
                $session['timestamp'] = time();
                update_option($opt->option_name, $session, false);
                continue;
            }

            if ($session['timestamp'] < $threshold) {
                delete_option($opt->option_name);
            }
        }
    }

    public static function limit_session_log(): void
    {
        $log = get_option('_wp_export_sessions', []);

        if (count($log) > 20) {
            $log = array_slice($log, -20, true); // keep last 20 entries
            update_option('_wp_export_sessions', $log);
        }
    }
}
