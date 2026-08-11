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
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_apply_to_logged_in', array('sanitize_callback' => array($this, 'sanitize_toggle')));

        add_settings_section('cf7_ip_restrict_main', 'Main Settings', null, 'cf7-ip-restrict-settings');
        add_settings_field('cf7_ip_restrict_field_logged_in', 'Logged-in Users', array($this, 'apply_to_logged_in_field_callback'), 'cf7-ip-restrict-settings', 'cf7_ip_restrict_main');
        add_settings_field('cf7_ip_restrict_field_ips', 'Blocked IP Addresses', array($this, 'blocked_ips_field_callback'), 'cf7-ip-restrict-settings', 'cf7_ip_restrict_main');
        add_settings_field('cf7_ip_restrict_field_keywords', 'Blocked Keywords', array($this, 'blocked_keywords_field_callback'), 'cf7-ip-restrict-settings', 'cf7_ip_restrict_main');
    }

    // Renders the on/off switch for applying the rules to logged-in users
    public function apply_to_logged_in_field_callback()
    {
        $enabled = get_option('cf7_ip_restrict_apply_to_logged_in');
        echo '<label class="cf7-ip-restrict-switch">';
        echo '<input type="checkbox" name="cf7_ip_restrict_apply_to_logged_in" value="1" ' . checked($enabled, '1', false) . '>';
        echo '<span class="cf7-ip-restrict-slider"></span>';
        echo '<span class="cf7-ip-restrict-switch-text">Apply blocking and the repeat-submission prompt to logged-in users</span>';
        echo '</label>';
        echo '<p class="description">Off by default, so administrators can keep testing forms without being blocked.</p>';
    }

    // Displays the main settings page content
    public function display_settings_page()
    {
?>
        <style>
            .cf7-ip-restrict-switch {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                cursor: pointer;
            }
            .cf7-ip-restrict-switch input {
                position: absolute;
                opacity: 0;
            }
            .cf7-ip-restrict-slider {
                position: relative;
                flex: 0 0 auto;
                width: 44px;
                height: 24px;
                background: #c3c4c7;
                border-radius: 12px;
                transition: background 0.2s;
            }
            .cf7-ip-restrict-slider::before {
                content: "";
                position: absolute;
                top: 3px;
                left: 3px;
                width: 18px;
                height: 18px;
                background: #fff;
                border-radius: 50%;
                transition: transform 0.2s;
            }
            .cf7-ip-restrict-switch input:checked + .cf7-ip-restrict-slider {
                background: #2271b1;
            }
            .cf7-ip-restrict-switch input:checked + .cf7-ip-restrict-slider::before {
                transform: translateX(20px);
            }
            .cf7-ip-restrict-switch input:focus-visible + .cf7-ip-restrict-slider {
                box-shadow: 0 0 0 2px #2271b1;
            }
        </style>
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

    // An unchecked box is absent from the POST, so WordPress passes null here.
    public function sanitize_toggle($input)
    {
        return $input ? '1' : '';
    }

    // Normalises the keyword list so a stray comma cannot produce an empty keyword.
    public function sanitize_keywords($input)
    {
        return implode(', ', array_map('sanitize_text_field', CF7_IP_Restrict::to_list($input)));
    }
}
