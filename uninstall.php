<?php
/**
 * Uninstall file for Razorpay for FluentCart.
 *
 * Executed when the plugin is deleted via the WordPress Admin dashboard (Plugins -> Delete).
 * Leaves zero footprints by completely purging all stored credentials, options,
 * master settings arrays, custom FluentCart database entries, cached plans,
 * and transients across single-site and multisite installs.
 *
 * @package RazorpayFluentCart
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

/**
 * Purge all Razorpay data, credentials, and settings for a single site/blog.
 *
 * @param \wpdb $db WordPress database object.
 */
function rpfc_purge_site_data($db)
{
    // 1. Direct option deletion (all known individual option keys)
    $known_options = [
        'fluent_cart_payment_settings_razorpay',
        'fluent_cart_payment_method_razorpay',
        'fct_payment_settings_razorpay',
        'fluentcart_payment_settings_razorpay',
        'fluent_cart_gateway_settings_razorpay',
        'rpfc_settings',
    ];

    foreach ($known_options as $option) {
        delete_option($option);
        delete_site_option($option);
    }

    // 2. Clean master grouped array options in FluentCart
    // FluentCart often stores all payment methods in master array options.
    $master_arrays = [
        'fluent_cart_payment_settings',
        'fluent_cart_payment_methods',
        'fluent_cart_settings',
        'fct_payment_settings',
        'fct_payment_methods',
        'fct_settings',
        'fluentcart_payment_settings',
        'fluentcart_settings',
    ];

    foreach ($master_arrays as $master_key) {
        $data = get_option($master_key);
        if (is_array($data)) {
            $changed = false;
            if (isset($data['razorpay'])) {
                unset($data['razorpay']);
                $changed = true;
            }
            if (isset($data['payment_methods']['razorpay'])) {
                unset($data['payment_methods']['razorpay']);
                $changed = true;
            }
            if (isset($data['payment_settings']['razorpay'])) {
                unset($data['payment_settings']['razorpay']);
                $changed = true;
            }
            if ($changed) {
                update_option($master_key, $data);
            }
        }
    }

    // 3. Purge all options containing razorpay or rpfc in name
    $db->query(
        "DELETE FROM {$db->options} WHERE option_name LIKE '%razorpay%' OR option_name LIKE 'rpfc_%' OR option_name LIKE '%rpfc%'"
    );

    // 4. Zero footprint: Purge any option whose value contains Razorpay credentials (rzp_live_ or rzp_test_)
    $db->query(
        "DELETE FROM {$db->options} WHERE option_value LIKE '%rzp_live_%' OR option_value LIKE '%rzp_test_%'"
    );

    // 5. Purge transients and transient timeouts
    $db->query(
        "DELETE FROM {$db->options} WHERE option_name LIKE '_transient_rpfc_%' OR option_name LIKE '_transient_timeout_rpfc_%'"
    );

    // 6. Purge from FluentCart custom tables (e.g. fct_meta) if present
    $tables = $db->get_col("SHOW TABLES LIKE '{$db->prefix}fct_%'");
    if (is_array($tables)) {
        foreach ($tables as $table) {
            // Check if table has meta_key column
            $columns = $db->get_col("SHOW COLUMNS FROM `{$table}` LIKE 'meta_key'");
            if (!empty($columns)) {
                $db->query("DELETE FROM `{$table}` WHERE `meta_key` LIKE '%razorpay%' OR `meta_key` LIKE '%rpfc%'");
            }
            // Check if table has key column
            $key_col = $db->get_col("SHOW COLUMNS FROM `{$table}` LIKE 'key'");
            if (!empty($key_col)) {
                $db->query("DELETE FROM `{$table}` WHERE `key` LIKE '%razorpay%' OR `key` LIKE '%rpfc%'");
            }
        }
    }
}

// Execute cleanup on current site FIRST
rpfc_purge_site_data($wpdb);

// Execute cleanup network-wide if running on WordPress Multisite
if (is_multisite()) {
    $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
    if (!empty($blog_ids) && is_array($blog_ids)) {
        foreach ($blog_ids as $b_id) {
            switch_to_blog((int) $b_id);
            rpfc_purge_site_data($wpdb);
            restore_current_blog();
        }
    }

    // Clean network sitemeta
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '%razorpay%' OR meta_key LIKE '%rpfc%' OR meta_value LIKE '%rzp_live_%' OR meta_value LIKE '%rzp_test_%'"
    );
}

// Flush object cache if available
if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
}
