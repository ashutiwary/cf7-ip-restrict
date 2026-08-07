<?php

class CF7_IP_Restrict_Admin
{
    // Constructor to add the necessary WordPress hooks
    public function __construct()
    {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    // Adds a menu item to the WordPress admin menu
    public function add_admin_menu()
    {
        add_menu_page(
            'CF7 IP Restrict Settings',
            'CF7 IP Restrict',
            'manage_options',
            'cf7-ip-restrict-settings',
            array($this, 'display_settings_page'),
            'dashicons-hidden',
            30
        );
        
    }

    // Registers settings, sections, and fields with the WordPress Settings API
    public function register_settings()
    {
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_blocked_ips', array('sanitize_callback' => array($this, 'sanitize_ips')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_blocked_keywords', array('sanitize_callback' => array($this, 'sanitize_keywords')));

        add_settings_section('cf7_ip_restrict_main', 'Main Settings', null, 'cf7-ip-restrict-settings');
        add_settings_field('cf7_ip_restrict_field_ips', 'Blocked IP Addresses', array($this, 'blocked_ips_field_callback'), 'cf7-ip-restrict-settings', 'cf7_ip_restrict_main');
        add_settings_field('cf7_ip_restrict_field_keywords', 'Blocked Keywords', array($this, 'blocked_keywords_field_callback'), 'cf7-ip-restrict-settings', 'cf7_ip_restrict_main');  
	
	}

    // Displays the main settings page content
    public function display_settings_page()
    {
?>
        <div class="wrap">
            <h2>CF7 IP Restrict Settings</h2>
            <?php settings_errors(); ?>
            <form action="options.php" method="post">
                <?php
                settings_fields('cf7_ip_restrict_settings');
                do_settings_sections('cf7-ip-restrict-settings');
                submit_button();
                ?>
            </form>
        </div>
    <?php
    }

    // Renders the settings field for blocked IP addresses
    public function blocked_ips_field_callback()
    {
        $ips = get_option('cf7_ip_restrict_blocked_ips');
        echo '<textarea name="cf7_ip_restrict_blocked_ips" class="large-text" rows="5">' . esc_textarea($ips) . '</textarea>';
        echo '<p class="description">Enter IP addresses to block, one per line or separated by commas (e.g., 192.168.1.1, 10.0.0.2).</p>';
    }

    // Renders the settings field for blocked keywords
    public function blocked_keywords_field_callback()
    {
        $keywords = get_option('cf7_ip_restrict_blocked_keywords');
        echo '<textarea name="cf7_ip_restrict_blocked_keywords" class="large-text" rows="5">' . esc_textarea($keywords) . '</textarea>';
        echo '<p class="description">Enter keywords to block, one per line or separated by commas (e.g., spam, unauthorized). Matched as whole words, case-insensitive.</p>';
    }

    // Keeps only valid IPs and tells the admin which entries were dropped.
    public function sanitize_ips($input)
    {
        $valid = array();
        $invalid = array();

        foreach (CF7_IP_Restrict::to_list($input) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $valid[] = $ip;
            } else {
                $invalid[] = $ip;
            }
        }

        if ($invalid) {
            add_settings_error(
                'cf7_ip_restrict_blocked_ips',
                'cf7_ip_restrict_invalid_ips',
                'Ignored invalid IP addresses: ' . esc_html(implode(', ', $invalid))
            );
        }

        return implode(', ', $valid);
    }

    // Normalises the keyword list so a stray comma cannot produce an empty keyword.
    public function sanitize_keywords($input)
    {
        return implode(', ', array_map('sanitize_text_field', CF7_IP_Restrict::to_list($input)));
    }
}
