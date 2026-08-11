<?php

class CF7_IP_Restrict
{
    // Splits a comma or newline separated option into trimmed, non-empty values.
    public static function to_list($str)
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $str)), 'strlen'));
    }

    public function run()
    {
        // Hook define_public_hooks to the init action so it executes when is_user_logged_in() is available
        add_action('init', array($this, 'define_public_hooks'));
        $this->load_dependencies();
        $this->define_admin_hooks();
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
        // This now safely checks if the user is logged in when 'init' action is processed
        if (is_user_logged_in()) {
            return;
        }

        $plugin_public = new CF7_IP_Restrict_Public();
        add_action('wp_footer', array($plugin_public, 'add_custom_error_modal_html'));
        add_action('wp_enqueue_scripts', array($plugin_public, 'enqueue_scripts'));
        add_filter('wpcf7_validate', array($plugin_public, 'check_ip_before_submission'), 20, 2);
    }
}
