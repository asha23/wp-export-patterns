<?php

namespace WPExportPatterns\Sync;

class PatternSyncService
{
    protected static string $patternDir = '/resources/patterns';

    /**
     * Get absolute path to the patterns folder
     */
    public static function get_pattern_path(): string
    {
        return get_theme_file_path(self::$patternDir);
    }

    /**
     * Get all block patterns saved on disk
     */
    public static function load_from_disk(): array
    {
        $path = self::get_pattern_path();
        if (!is_dir($path)) {
            return [];
        }

        $patterns = [];
        foreach (glob($path . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (isset($data['post_name'], $data['post_content'])) {
                $patterns[$data['post_name']] = $data;
            }
        }

        return $patterns;
    }

    /**
     * Export a pattern array to disk
     */
    public static function export_to_disk(array $pattern): bool
    {
        if (!isset($pattern['post_name'], $pattern['post_title'], $pattern['post_content'])) {
            return false;
        }

        $folder = self::get_pattern_path();
        if (!is_dir($folder)) {
            wp_mkdir_p($folder);
        }

        $pattern['modified'] = current_time('c');

        $filename = $folder . '/' . sanitize_file_name($pattern['post_name']) . '.json';
        return (bool) file_put_contents($filename, json_encode($pattern, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Compare patterns on disk to patterns in DB
     */
    public static function detect_unsynced(): array
    {
        $disk = self::load_from_disk();
        $unsynced = [];

        foreach ($disk as $slug => $pattern) {
            $existing = get_page_by_path($slug, OBJECT, 'wp_block');
            if (!$existing) {
                $unsynced[$slug] = [
                    'title' => $pattern['post_title'],
                    'status' => 'missing',
                ];
                continue;
            }

            // Check content hash
            $disk_hash = md5(trim($pattern['post_content']));
            $db_hash = md5(trim($existing->post_content));

            if ($disk_hash !== $db_hash) {
                $unsynced[$slug] = [
                    'title' => $pattern['post_title'],
                    'status' => 'outdated',
                ];
            }
        }

        return $unsynced;
    }

    /**
     * Sync a pattern from disk into the DB
     */
    public static function import_pattern(string $slug): bool
    {
        $path = self::get_pattern_path() . '/' . sanitize_file_name($slug) . '.json';
        if (!file_exists($path)) {
            return false;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data) || !isset($data['post_name'], $data['post_content'])) {
            return false;
        }

        $existing = get_page_by_path($slug, OBJECT, 'wp_block');
        if ($existing) {
            $updated = wp_update_post([
                'ID' => $existing->ID,
                'post_title' => $data['post_title'],
                'post_content' => $data['post_content'],
            ]);
            return !is_wp_error($updated);
        } else {
            $inserted = wp_insert_post([
                'post_type' => 'wp_block',
                'post_name' => $data['post_name'],
                'post_title' => $data['post_title'],
                'post_content' => $data['post_content'],
                'post_status' => 'publish',
            ]);
            return !is_wp_error($inserted);
        }
    }
}
