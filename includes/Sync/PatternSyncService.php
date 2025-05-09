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
            $slug = basename($file, '.json');

            if (str_ends_with($slug, '.deleted')) {
                continue;
            }

            $data = json_decode(file_get_contents($file), true);
            if (isset($data['post_name'], $data['post_content'])) {
                $patterns[$data['post_name']] = $data;
            }
        }

        return $patterns;
    }

    public static function export_to_disk(array $pattern): \WP_Error|bool
    {
        if (!isset($pattern['post_name'], $pattern['post_title'], $pattern['post_content'])) {
            return new \WP_Error('pattern_invalid', 'Missing required pattern fields.');
        }

        $folder = self::get_pattern_path();

        if (!is_dir($folder)) {
            if (!wp_mkdir_p($folder)) {
                return new \WP_Error('mkdir_failed', 'Failed to create pattern folder at: ' . $folder);
            }
        }

        if (!is_writable($folder)) {
            return new \WP_Error('folder_not_writable', 'The patterns folder is not writable: ' . $folder);
        }

        $pattern['modified'] = current_time('c');
        $filename = $folder . '/' . sanitize_file_name($pattern['post_name']) . '.json';

        $result = file_put_contents($filename, json_encode($pattern, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($result === false) {
            return new \WP_Error('write_failed', 'Failed to write pattern file: ' . $filename);
        }

        return true;
    }

    public static function detect_unsynced(): array
    {
        $unsynced = [];

        $disk = self::load_from_disk();

        $dbPatterns = get_posts([
            'post_type'      => 'wp_block',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'trash'],
        ]);

        foreach ($dbPatterns as $post) {
            $slug = $post->post_name;
            $title = $post->post_title;
            $db_content = trim($post->post_content);

            if (!isset($disk[$slug])) {
                $unsynced[$slug] = [
                    'title' => $title,
                    'status' => 'missing_from_disk',
                    'trashed' => $post->post_status === 'trash',
                ];
                continue;
            }

            $disk_content = trim($disk[$slug]['post_content'] ?? '');
            if (md5($db_content) !== md5($disk_content)) {
                $unsynced[$slug] = [
                    'title' => $title,
                    'status' => 'outdated',
                    'trashed' => $post->post_status === 'trash',
                ];
            }

            // Auto-delete orphaned JSON files if the DB pattern no longer exists
            $existingSlugs = wp_list_pluck($dbPatterns, 'post_name');

            $patternFolder = self::get_pattern_path();
            foreach (glob($patternFolder . '/*.json') as $file) {
                $filename = basename($file, '.json');
                if (!in_array($filename, $existingSlugs, true)) {
                    @unlink($file);
                }
            }

        }

        return $unsynced;
    }

    public static function import_pattern(string $slug): \WP_Error|bool
    {
        $path = self::get_pattern_path() . '/' . sanitize_file_name($slug) . '.json';
        if (!file_exists($path)) {
            return new \WP_Error('not_found', 'Pattern file not found.');
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data) || !isset($data['post_name'], $data['post_content'])) {
            return new \WP_Error('invalid_json', 'Pattern JSON is invalid.');
        }

        $existing = get_page_by_path($slug, OBJECT, 'wp_block');
        if ($existing) {
            $updated = wp_update_post([
                'ID'           => $existing->ID,
                'post_title'   => $data['post_title'],
                'post_content' => $data['post_content'],
                'post_status'  => 'publish',
            ]);
            return is_wp_error($updated) ? $updated : true;
        }

        $inserted = wp_insert_post([
            'post_type'    => 'wp_block',
            'post_name'    => $data['post_name'],
            'post_title'   => $data['post_title'],
            'post_content' => $data['post_content'],
            'post_status'  => 'publish',
        ]);

        return is_wp_error($inserted) ? $inserted : true;
    }

    public static function export_pattern(string $slug): \WP_Error|bool
    {
        $post = get_page_by_path($slug, OBJECT, 'wp_block');
        if (!$post) {
            return new \WP_Error('not_found', 'Pattern not found in DB.');
        }

        return self::export_to_disk([
            'post_title'   => $post->post_title,
            'post_name'    => $post->post_name,
            'post_content' => $post->post_content,
        ]);
    }

    public static function trash_pattern(string $slug): \WP_Error|bool
    {
        $errors = [];

        $post = get_page_by_path($slug, OBJECT, 'wp_block');
        if ($post && $post->post_status !== 'trash') {
            $trashed = wp_trash_post($post->ID);
            if (!$trashed) {
                $errors[] = 'Failed to move DB pattern to trash.';
            }
        }

        $file = self::get_pattern_path() . '/' . sanitize_file_name($slug) . '.json';
        $trashed_file = self::get_pattern_path() . '/' . sanitize_file_name($slug) . '.deleted.json';

        if (file_exists($file)) {
            if (!rename($file, $trashed_file)) {
                $errors[] = 'Failed to move pattern file to trash.';
            }
        }

        return empty($errors) ? true : new \WP_Error('trash_failed', implode(' ', $errors));
    }

    public static function restore_pattern(string $slug): \WP_Error|bool
    {
        $post = get_page_by_path($slug, OBJECT, 'wp_block');
        if ($post && $post->post_status === 'trash') {
            $restored = wp_untrash_post($post->ID);
            if (!$restored) {
                return new \WP_Error('untrash_failed', 'Failed to restore pattern from trash.');
            }
        }

        $file = self::get_pattern_path() . '/' . sanitize_file_name($slug) . '.deleted.json';
        $original = self::get_pattern_path() . '/' . sanitize_file_name($slug) . '.json';

        if (file_exists($file)) {
            if (!rename($file, $original)) {
                return new \WP_Error('file_restore_failed', 'Failed to restore pattern file from trash.');
            }
        }

        return true;
    }

    public static function bulk_trash(array $slugs): array
    {
        $results = [];
        foreach ($slugs as $slug) {
            $result = self::trash_pattern($slug);
            $results[$slug] = $result === true ? 'trashed' : ($result instanceof \WP_Error ? $result->get_error_message() : 'failed');
        }
        return $results;
    }
}
