<?php

/**
 * Plugin Name: CF7 IP Restrict
 * Description: Warns Contact Form 7 visitors before a repeat submission, and restricts IP addresses and keywords.
 * Version: 2.2.0
 * Author: Ashu Tiwary
 */

if (!defined('ABSPATH')) exit;


// Activation check function
function cf7_ip_restrict_activation_check()
{
    // Include the plugin.php library for the is_plugin_active function
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');

    // Check if Contact Form 7 is active
    if (!is_plugin_active('contact-form-7/wp-contact-form-7.php')) {
        // Throw an error that will halt the activation process
        wp_die('Sorry, but CF7 IP Restrict requires the Contact Form 7 plugin to be installed and active.<br><br>Return to the <a href="' . admin_url('plugins.php') . '">plugins page</a>.');
    }
}

// Register the activation hook
register_activation_hook(__FILE__, 'cf7_ip_restrict_activation_check');

require_once plugin_dir_path(__FILE__) . 'includes/class-cf7-ip-restrict.php';

function run_cf7_ip_restrict()
{
    $plugin = new CF7_IP_Restrict();
    $plugin->run();
}

run_cf7_ip_restrict();


// Add settings link to the plugin actions links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'cf7_ip_restrict_add_action_links');

function cf7_ip_restrict_add_action_links($links)
{
    $settings_link = '<a href="' . admin_url('admin.php?page=cf7-ip-restrict-settings') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
