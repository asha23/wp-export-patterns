<?php

namespace WPExportPatterns\Sync;

class PatternSyncService
{
    protected static string $patternDir = '/resources/patterns';

    public static function get_pattern_path(): string
    {
        return get_theme_file_path(self::$patternDir);
    }

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

    public static function detect_unsynced(): array
    {
        $unsynced = [];

        $disk = self::load_from_disk();

        $dbPatterns = get_posts([
            'post_type'      => 'wp_block',
            'posts_per_page' => -1,
        ]);

        foreach ($dbPatterns as $post) {
            $slug = $post->post_name;
            $title = $post->post_title;
            $db_content = trim($post->post_content);

            if (!isset($disk[$slug])) {
                $unsynced[$slug] = [
                    'title' => $title,
                    'status' => 'missing_from_disk',
                ];
                continue;
            }

            $disk_content = trim($disk[$slug]['post_content'] ?? '');
            if (md5($db_content) !== md5($disk_content)) {
                $unsynced[$slug] = [
                    'title' => $title,
                    'status' => 'outdated',
                ];
            }
        }

        return $unsynced;
    }

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
                'ID'           => $existing->ID,
                'post_title'   => $data['post_title'],
                'post_content' => $data['post_content'],
            ]);
            return !is_wp_error($updated);
        } else {
            $inserted = wp_insert_post([
                'post_type'    => 'wp_block',
                'post_name'    => $data['post_name'],
                'post_title'   => $data['post_title'],
                'post_content' => $data['post_content'],
                'post_status'  => 'publish',
            ]);
            return !is_wp_error($inserted);
        }
    }

    public static function export_pattern(string $slug): bool
    {
        $post = get_page_by_path($slug, OBJECT, 'wp_block');

        if (!$post) {
            return false;
        }

        return self::export_to_disk([
            'post_title'   => $post->post_title,
            'post_name'    => $post->post_name,
            'post_content' => $post->post_content,
        ]);
    }
}
