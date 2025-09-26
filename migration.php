<?php
if (!defined('ABSPATH')) exit;

register_activation_hook(__FILE__, 'tmw147d_migrate_terms_to_cpt');

function tmw147d_migrate_terms_to_cpt() {
    if (get_option('tmw_mf_migrated')) return;

    global $wpdb;

    // Get all terms from taxonomy=models
    $terms = $wpdb->get_results("SELECT t.term_id, t.name, t.slug FROM {$wpdb->terms} t
        INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
        WHERE tt.taxonomy = 'models'");

    $migrated = 0;

    if ($terms) {
        foreach ($terms as $term) {
            // Find model CPT by slug first, fallback by name
            $post = get_page_by_path($term->slug, OBJECT, 'model');
            if (!$post) {
                $post = get_page_by_title($term->name, OBJECT, 'model');
            }
            if ($post) {
                $post_id = $post->ID;

                // Get all term meta
                $metas = $wpdb->get_results($wpdb->prepare(
                    "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d",
                    $term->term_id
                ));

                foreach ($metas as $meta) {
                    if ($meta->meta_key && $meta->meta_value !== null) {
                        update_post_meta($post_id, $meta->meta_key, maybe_unserialize($meta->meta_value));
                    }
                }
                $migrated++;
            }
        }
    }

    update_option('tmw_mf_migrated', true);
    update_option('tmw_mf_migrated_count', $migrated);
}

add_action('admin_notices', function() {
    if (get_option('tmw_mf_migrated') && ($count = get_option('tmw_mf_migrated_count'))) {
        echo '<div class="notice notice-success"><p>✅ Flipbox migration complete: ' . intval($count) . ' models migrated to CPT meta.</p></div>';
        delete_option('tmw_mf_migrated_count');
    }
});
