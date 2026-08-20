<?php

class CF7_IP_Restrict
{
    // Splits a comma or newline separated option into trimmed, non-empty values.
    public static function to_list($str)
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $str)), 'strlen'));
    }

    // The visitor's IP as this plugin sees it. Proxy headers are only trusted
    // when the site opts in with define('CF7_IP_RESTRICT_TRUST_PROXY', true) in
    // wp-config.php, otherwise any visitor could send a fake header and skip
    // the blocklist. Shared so the settings page can show the same value the
    // blocklist is compared against.
    public static function client_ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        if (defined('CF7_IP_RESTRICT_TRUST_PROXY') && CF7_IP_RESTRICT_TRUST_PROXY) {
            foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR') as $header) {
                if (!empty($_SERVER[$header])) {
                    $parts = explode(',', $_SERVER[$header]);
                    $ip = trim($parts[0]);
                    break;
                }
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public function run()
    {
        // Hook define_public_hooks to the init action so it executes when is_user_logged_in() is available
        add_action('init', array($this, 'define_public_hooks'));
        $this->load_dependencies();
        $this->define_admin_hooks();
        add_action(CF7_IP_Restrict_Public::CLEANUP_HOOK, 'delete_transient');
    }

    private function load_dependencies()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-cf7-ip-restrict-admin.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'public/class-cf7-ip-restrict-public.php';
    }

    private function define_admin_hooks()
    {
        new CF7_IP_Restrict_Admin();
    }

    public function define_public_hooks()
    {
        // Logged-in users are skipped unless the admin turned the toggle on.
        if (is_user_logged_in() && !get_option('cf7_ip_restrict_apply_to_logged_in')) {
            return;
        }

        $plugin_public = new CF7_IP_Restrict_Public();
        add_action('wp_footer', array($plugin_public, 'add_custom_error_modal_html'));
        add_action('wp_enqueue_scripts', array($plugin_public, 'enqueue_scripts'));
        add_filter('wpcf7_validate', array($plugin_public, 'check_ip_before_submission'), 20, 2);
        add_filter('wpcf7_feedback_response', array($plugin_public, 'filter_feedback_response'), 10, 2);
        add_action('wpcf7_before_send_mail', array($plugin_public, 'remember_submission'));
    }
}
