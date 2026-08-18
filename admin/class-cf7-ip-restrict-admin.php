<?php

class CF7_IP_Restrict_Admin
{
    // Constructor to add the necessary WordPress hooks
    public function __construct()
    {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    // Adds the settings page under Contact Form 7's own menu ("wpcf7" is CF7's
    // top-level slug). The page slug is unchanged, so the Settings link on the
    // Plugins row still resolves.
    public function add_admin_menu()
    {
        add_submenu_page(
            'wpcf7',
            'CF7 IP Restrict Settings',
            'CF7 IP Restrict',
            'manage_options',
            'cf7-ip-restrict-settings',
            array($this, 'display_settings_page')
        );
    }

    // Registers settings, sections, and fields with the WordPress Settings API
    public function register_settings()
    {
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_blocked_ips', array('sanitize_callback' => array($this, 'sanitize_ips')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_blocked_keywords', array('sanitize_callback' => array($this, 'sanitize_keywords')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_apply_to_logged_in', array('sanitize_callback' => array($this, 'sanitize_toggle')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_repeat_enabled', array('sanitize_callback' => array($this, 'sanitize_toggle')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_repeat_duration', array('sanitize_callback' => array($this, 'sanitize_duration')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_repeat_unit', array('sanitize_callback' => array($this, 'sanitize_unit')));

        add_settings_section('cf7_ip_restrict_main', 'Main Settings', null, 'cf7-ip-restrict-settings');
        add_settings_field('cf7_ip_restrict_field_repeat', 'Repeat Submissions', array($this, 'repeat_field_callback'), 'cf7-ip-restrict-settings', 'cf7_ip_restrict_main');
        add_settings_field('cf7_ip_restrict_field_logged_in', 'Logged-in Users', array($this, 'apply_to_logged_in_field_callback'), 'cf7-ip-restrict-settings', 'cf7_ip_restrict_main');
        add_settings_field('cf7_ip_restrict_field_ips', 'Blocked IP Addresses', array($this, 'blocked_ips_field_callback'), 'cf7-ip-restrict-settings', 'cf7_ip_restrict_main');
        add_settings_field('cf7_ip_restrict_field_keywords', 'Blocked Keywords', array($this, 'blocked_keywords_field_callback'), 'cf7-ip-restrict-settings', 'cf7_ip_restrict_main');
    }

    // Renders a checkbox styled as an on/off switch
    private function switch_field($option, $label, $default = '')
    {
        echo '<label class="cf7-ip-restrict-switch">';
        echo '<input type="checkbox" name="' . esc_attr($option) . '" value="1" ' . checked(get_option($option, $default), '1', false) . '>';
        echo '<span class="cf7-ip-restrict-slider"></span>';
        echo '<span class="cf7-ip-restrict-switch-text">' . esc_html($label) . '</span>';
        echo '</label>';
    }

    // Switch on the left, how-long controls on the right of the same row.
    // The controls are rendered hidden when the switch is off so there is no
    // flash of them before the inline script runs.
    public function repeat_field_callback()
    {
        $hidden = get_option('cf7_ip_restrict_repeat_enabled', '1') ? '' : ' hidden';
        $unit = get_option('cf7_ip_restrict_repeat_unit', 'minutes');
?>
        <div class="cf7-ip-restrict-row">
            <?php $this->switch_field('cf7_ip_restrict_repeat_enabled', 'Ask visitors to confirm before they submit a form again', '1'); ?>
            <span class="cf7-ip-restrict-window cf7-ip-restrict-when-on"<?php echo $hidden; ?>>
                <input type="number" name="cf7_ip_restrict_repeat_duration" value="<?php echo esc_attr(absint(get_option('cf7_ip_restrict_repeat_duration', 0))); ?>" min="0" step="1" aria-label="How long the prompt lasts">
                <select name="cf7_ip_restrict_repeat_unit" aria-label="Unit">
                    <?php foreach (array('seconds' => 'Seconds', 'minutes' => 'Minutes') as $value => $text) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($unit, $value); ?>><?php echo esc_html($text); ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
        </div>
        <p class="description">
            On by default. Off, visitors can submit as often as they like with no prompt.
            <span class="cf7-ip-restrict-when-on"<?php echo $hidden; ?>>Use <strong>0</strong> to keep the prompt until the browser is closed. Capped at 30 days.</span>
        </p>
<?php
    }

    // Renders the on/off switch for applying the rules to logged-in users
    public function apply_to_logged_in_field_callback()
    {
        $this->switch_field('cf7_ip_restrict_apply_to_logged_in', 'Apply blocking and the repeat-submission prompt to logged-in users');
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

            /* Switch left, how-long controls right, on one line */
            .cf7-ip-restrict-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 24px;
                max-width: 720px;
            }

            /* Amount and unit joined into a single pill */
            .cf7-ip-restrict-window {
                display: inline-flex;
                align-items: stretch;
                flex: 0 0 auto;
            }
            .cf7-ip-restrict-window[hidden],
            .description .cf7-ip-restrict-when-on[hidden] {
                display: none;
            }
            .cf7-ip-restrict-window input,
            .cf7-ip-restrict-window select {
                height: 36px;
                margin: 0;
                border: 1px solid #8c8f94;
                background: #fff;
                color: #2c3338;
                font-size: 14px;
                line-height: 1;
                box-shadow: none;
                transition: border-color 0.15s, box-shadow 0.15s;
            }
            .cf7-ip-restrict-window input {
                width: 76px;
                padding: 0 10px;
                text-align: center;
                border-radius: 6px 0 0 6px;
                border-right: 0;
            }
            .cf7-ip-restrict-window select {
                min-width: 108px;
                padding: 0 30px 0 12px;
                border-radius: 0 6px 6px 0;
                background-color: #f6f7f7;
            }
            .cf7-ip-restrict-window input:hover,
            .cf7-ip-restrict-window select:hover {
                border-color: #646970;
            }
            .cf7-ip-restrict-window input:focus,
            .cf7-ip-restrict-window select:focus {
                position: relative;
                z-index: 1;
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1;
                outline: 2px solid transparent;
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
        <script>
            (function () {
                var toggle = document.querySelector('input[name="cf7_ip_restrict_repeat_enabled"]');
                if (!toggle) {
                    return;
                }
                toggle.addEventListener('change', function () {
                    document.querySelectorAll('.cf7-ip-restrict-when-on').forEach(function (el) {
                        el.hidden = !toggle.checked;
                    });
                });
            })();
        </script>
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
        echo '<p class="description">Enter keywords to block, one per line or separated by commas. Case-insensitive, and matched anywhere they appear &mdash; including inside a longer word or an email address, so <code>hello</code> also blocks <code>hello123@gmail.com</code> and <code>nr.abchello@abc.com</code>.</p>';
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

    public function sanitize_unit($input)
    {
        return $input === 'seconds' ? 'seconds' : 'minutes';
    }

    // Stores the clamped value so the field shows the window actually in force,
    // rather than an entry the front end would silently cut down to 30 days.
    public function sanitize_duration($input)
    {
        $amount = absint($input);

        // The unit is submitted alongside, so read the new one rather than the
        // stored one, which has not been updated yet at this point.
        $unit = isset($_POST['cf7_ip_restrict_repeat_unit'])
            ? $this->sanitize_unit(wp_unslash($_POST['cf7_ip_restrict_repeat_unit']))
            : get_option('cf7_ip_restrict_repeat_unit', 'minutes');

        $max = $unit === 'seconds' ? 30 * DAY_IN_SECONDS : (30 * DAY_IN_SECONDS) / MINUTE_IN_SECONDS;

        if ($amount > $max) {
            add_settings_error(
                'cf7_ip_restrict_repeat_duration',
                'cf7_ip_restrict_duration_capped',
                sprintf('Repeat window reduced to the 30 day maximum (%d %s).', $max, $unit)
            );
            $amount = (int) $max;
        }

        return $amount;
    }

    // Normalises the keyword list so a stray comma cannot produce an empty keyword.
    public function sanitize_keywords($input)
    {
        return implode(', ', array_map('sanitize_text_field', CF7_IP_Restrict::to_list($input)));
    }
}
