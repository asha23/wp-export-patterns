<?php

namespace WPExportPatterns;

use WPExportPatterns\Sync\PatternSyncService;

class Exporter
{
    public static function render_admin_page(): void
    {
        $blocks = get_posts([
            'post_type'      => 'wp_block',
            'posts_per_page' => -1,
        ]);

        // ⛏️ Setup sync folder if missing
        $syncFolder = PatternSyncService::get_pattern_path();
        if (!is_dir($syncFolder)) {
            wp_mkdir_p($syncFolder);
        }

        $existingFiles = glob($syncFolder . '/*.json');
        if (empty($existingFiles) && !empty($blocks)) {
            foreach ($blocks as $block) {
                PatternSyncService::export_to_disk([
                    'post_title'   => $block->post_title,
                    'post_name'    => $block->post_name,
                    'post_content' => $block->post_content,
                ]);
            }
        }

        echo '<div class="wrap">';
        echo '<h1>Import/Export Patterns</h1>';

        // 🧠 Show sync status
        $unsynced = PatternSyncService::detect_unsynced();

        if (empty($unsynced)) {
            echo '<div class="notice notice-success inline"><p>All patterns are in sync.</p></div>';
        } else {
            $count = count($unsynced);
            echo '<div class="notice notice-warning inline"><p>';
            echo sprintf('%d pattern%s out of sync. <a href="%s" class="button">Sync Now</a>',
                $count,
                $count === 1 ? '' : 's',
                esc_url(admin_url('admin.php?page=wp-pattern-sync'))
            );
            echo '</p></div>';
        }

        // Export form
        echo '<h2>Export Patterns</h2>';
        echo '<form method="post">';
        echo '<input type="hidden" name="wp_export_patterns_nonce" value="' . esc_attr(wp_create_nonce('wp_export_patterns')) . '">';

        if ($blocks) {
            echo '<ul>';
            foreach ($blocks as $block) {
                $title = esc_html($block->post_title ?: $block->post_name);
                echo '<li>';
                echo '<label>';
                echo '<input type="checkbox" name="export_ids[]" value="' . esc_attr($block->ID) . '"> ';
                echo $title;
                echo '</label>';
                echo '</li>';
            }
            echo '</ul>';
            echo '<input type="submit" name="export_patterns" class="button button-primary" value="Export Selected Patterns">';
        } else {
            echo '<p>No patterns found.</p>';
        }

        echo '</form>';

        // Import form
        echo '<hr><h2>Import Patterns</h2>';
        echo '<form method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="wp_import_patterns_nonce" value="' . esc_attr(wp_create_nonce('wp_import_patterns')) . '">';
        echo '<input type="file" name="import_file" required> ';
        echo '<input type="submit" name="import_patterns" class="button" value="Import">';
        echo '</form>';

        if ($session = get_option('_wp_export_last_session')) {
            echo '<hr><h2>Undo Last Import</h2>';
            echo '<form method="post">';
            echo '<input type="hidden" name="undo_import_nonce" value="' . esc_attr(wp_create_nonce('undo_import')) . '">';
            echo '<input type="submit" name="undo_import" class="button button-secondary" value="Undo Last Import">';
            echo '</form>';
        }

        // Session log will go here (not repeated in this file — assumed already included)

        echo '</div>';
    }

    public static function maybe_handle_export(): void
    {
        // (Unchanged from earlier: export handlers, undo handlers, session deletion)
        // You already have this from previous full exporter dumps
    }

    public static function show_notices(): void
    {
        // (Unchanged from earlier: admin_notice rendering)
        // You already have this from previous full exporter dumps
    }
}
