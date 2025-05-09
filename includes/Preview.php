<?php

namespace WPExportPatterns;

class Preview
{
    public static function render_preview_screen(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'wp-export-patterns'));
        }

        $session_id = get_option('_wp_export_preview_session');
        if (!$session_id) {
            echo '<div class="wrap"><h1>No import session found.</h1></div>';
            return;
        }

        $session_data = get_option("_wp_preview_session_$session_id");

        if (!$session_data || !isset($session_data['patterns'])) {
            echo '<div class="wrap"><h1>Preview session expired or missing.</h1></div>';
            return;
        }

        $patterns = $session_data['patterns'];

        echo '<div class="wrap">';
        echo '<h1>Import Preview</h1>';
        echo '<form method="post">';
        echo '<input type="hidden" name="session_id" value="' . esc_attr($session_id) . '">';
        echo '<input type="hidden" name="confirm_import" value="1">';
        echo '<input type="hidden" name="confirm_import_nonce" value="' . esc_attr(wp_create_nonce('wp_confirm_import')) . '">';

        echo '<p>The following patterns will be imported. Existing patterns with the same slug are marked.</p>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Title</th><th>Slug</th><th>Status</th>';
        echo '</tr></thead><tbody>';

        foreach ($patterns as $pattern) {
            $title = esc_html($pattern['post_title']);
            $slug  = esc_html($pattern['post_name']);
            $exists = get_page_by_path($pattern['post_name'], OBJECT, 'wp_block');
            $status = $exists ? '<span style="color:orange;">Exists</span>' : '<span style="color:green;">New</span>';

            echo '<tr>';
            echo "<td>$title</td>";
            echo "<td>$slug</td>";
            echo "<td>$status</td>";
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p><label><input type="checkbox" name="overwrite" value="1"> Overwrite existing patterns with matching slugs</label></p>';
        echo '<p><button type="submit" class="button button-primary">Confirm Import</button></p>';
        echo '</form>';
        echo '</div>';
    }
}
