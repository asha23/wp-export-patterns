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
        if (!isset($pattern['post_name'], $pattern['post_content'])) {
            return new \WP_Error('pattern_invalid', 'Missing required pattern fields.');
        }

        $pattern['post_title'] ??= $pattern['post_name'];

        $folder = self::get_pattern_path();

        if (!is_dir($folder) && !wp_mkdir_p($folder)) {
            return new \WP_Error('mkdir_failed', 'Failed to create pattern folder at: ' . $folder);
        }

        if (!is_writable($folder)) {
            return new \WP_Error('folder_not_writable', 'The patterns folder is not writable: ' . $folder);
        }

        $filename = $folder . '/' . sanitize_file_name($pattern['post_name']) . '.json';

        // Load existing file if it exists
        if (file_exists($filename)) {
            $existing = json_decode(file_get_contents($filename), true);

            // Compare only the relevant fields (ignore previous 'modified')
            $existing_clean = [
                'post_title'   => $existing['post_title'] ?? '',
                'post_name'    => $existing['post_name'] ?? '',
                'post_content' => $existing['post_content'] ?? '',
            ];

            $incoming_clean = [
                'post_title'   => $pattern['post_title'],
                'post_name'    => $pattern['post_name'],
                'post_content' => $pattern['post_content'],
            ];

            if ($existing_clean === $incoming_clean) {
                return true; // Nothing changed — skip write
            }
        }

        // Only set modified if we’re actually writing the file
        $pattern['modified'] = current_time('c');

        $json = json_encode($pattern, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = file_put_contents($filename, $json);

        if ($result === false) {
            return new \WP_Error('write_failed', 'Failed to write pattern file: ' . $filename);
        }

        return true;
    }



    public static function detect_unsynced(): array
    {
        $results = [];

        $disk = self::load_from_disk();
        $patternFolder = self::get_pattern_path();
        $diskSlugs = array_keys($disk);

        $dbPatterns = get_posts([
            'post_type'      => 'wp_block',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'trash'],
        ]);

        $dbSlugs = [];

        foreach ($dbPatterns as $post) {
            $slug = $post->post_name;
            $dbSlugs[] = $slug;
            $title = $post->post_title;
            $db_content = trim($post->post_content);

            if (!isset($disk[$slug])) {
                $results[$slug] = [
                    'title'   => $title,
                    'status'  => 'missing_from_disk',
                    'trashed' => $post->post_status === 'trash',
                    'notes'   => 'Exists in DB but no matching file on disk.',
                ];
                continue;
            }

            $disk_content = trim($disk[$slug]['post_content'] ?? '');

            if (md5($db_content) !== md5($disk_content)) {
                $results[$slug] = [
                    'title'   => $title,
                    'status'  => 'outdated',
                    'trashed' => $post->post_status === 'trash',
                    'notes'   => 'Content differs between DB and disk.',
                ];
            } else {
                $results[$slug] = [
                    'title'   => $title,
                    'status'  => 'in_sync',
                    'trashed' => $post->post_status === 'trash',
                    'notes'   => '',
                ];
            }
        }

        // Disk patterns not in DB — mark as missing_from_db
        foreach ($diskSlugs as $slug) {
            if (!in_array($slug, $dbSlugs, true)) {
                $title = $disk[$slug]['post_title'] ?? $slug;

                $results[$slug] = [
                    'title'   => $title,
                    'status'  => 'missing_from_db',
                    'trashed' => false,
                    'notes'   => 'Exists on disk but not in DB.',
                ];
            }
        }

        return $results;
    }



    public static function render_sync_status_table(): void
    {
        $results = self::detect_unsynced();

        echo '<div class="wrap"><h2>Pattern Sync Status</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Slug</th><th>Title</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead><tbody>';

        foreach ($results as $slug => $info) {
            $status = esc_html($info['status']);
            $title  = esc_html($info['title']);
            $notes  = esc_html($info['notes']);
            $safe_slug = esc_html($slug);

            echo '<tr>';
            echo "<td>{$safe_slug}</td>";
            echo "<td>{$title}</td>";
            echo "<td>{$status}</td>";
            echo "<td>{$notes}</td>";
            echo '<td>';

            // Show Sync button for relevant statuses
            if (in_array($status, ['outdated', 'missing_from_disk', 'missing_from_db'], true)) {
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field('sync_pattern_' . $slug, 'sync_nonce');
                echo '<input type="hidden" name="sync_slug" value="' . esc_attr($slug) . '">';
                echo '<button type="submit" name="sync_pattern" class="button button-primary">Sync</button>';
                echo '</form> ';
            }

            // Show Delete button only for orphaned patterns
            if ($status === 'orphaned') {
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field('delete_pattern_' . $slug, 'delete_nonce');
                echo '<input type="hidden" name="delete_slug" value="' . esc_attr($slug) . '">';
                echo '<input type="hidden" name="confirm_delete" value="1">';
                echo '<button type="submit" name="delete_pattern" class="button button-secondary">Delete</button>';
                echo '</form>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
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
        if ($post) {
            $deleted = wp_delete_post($post->ID, true); // ← force delete (skip trash)
            if (!$deleted) {
                $errors[] = 'Failed to delete DB pattern.';
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
