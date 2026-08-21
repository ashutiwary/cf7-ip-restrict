<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Set only by the deactivation modal. Absent means keep everything.
if (!get_site_option('cf7_ip_restrict_delete_data')) {
    return;
}

function cf7_ip_restrict_delete_site_data()
{
    global $wpdb;

    $options = array(
        'cf7_ip_restrict_blocked_ips',
        'cf7_ip_restrict_blocked_keywords',
        'cf7_ip_restrict_apply_to_logged_in',
        'cf7_ip_restrict_repeat_enabled',
        'cf7_ip_restrict_repeat_duration',
        'cf7_ip_restrict_repeat_unit',
    );

    foreach ($options as $option) {
        delete_option($option);
    }

    $like = $wpdb->esc_like('cf7_ip_restrict_repeat_') . '%';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_' . $like,
        '_transient_timeout_' . $like
    ));

    wp_unschedule_hook('cf7_ip_restrict_cleanup_repeat');
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $site_id) {
        switch_to_blog($site_id);
        cf7_ip_restrict_delete_site_data();
        restore_current_blog();
    }
} else {
    cf7_ip_restrict_delete_site_data();
}

delete_site_option('cf7_ip_restrict_delete_data');
