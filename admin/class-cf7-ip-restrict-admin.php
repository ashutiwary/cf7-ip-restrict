<?php

class CF7_IP_Restrict_Admin
{
    // Screen id of the settings page, so its stylesheet loads nowhere else.
    private $hook_suffix = '';

    // Constructor to add the necessary WordPress hooks
    public function __construct()
    {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_footer', array($this, 'deactivate_consent_modal'));
        add_action('wp_ajax_cf7_ip_restrict_purge_consent', array($this, 'save_purge_consent'));
    }

    // Asked on deactivation, applied later by uninstall.php. Nothing is removed
    // here. Bulk deactivate and WP-CLI skip it and leave the stored answer as is.
    public function deactivate_consent_modal()
    {
        $screen = get_current_screen();

        if (!$screen || $screen->base !== 'plugins' || !current_user_can('deactivate_plugins')) {
            return;
        }

        $basename = plugin_basename(dirname(__DIR__) . '/cf7-iprestrict.php');
?>
        <dialog id="cf7-ip-restrict-purge" style="max-width:480px;padding:24px;border:1px solid #c3c4c7;border-radius:4px;">
            <h2 style="margin-top:0;">Deactivate CF7 IP Restrict</h2>
            <p>Deactivating stops all IP blocking, keyword blocking, and repeat-submission prompts. <strong>Nothing is removed from your database right now, whichever option you pick.</strong></p>
            <p>
                <label>
                    <input type="checkbox" id="cf7-ip-restrict-purge-box" <?php checked(get_site_option('cf7_ip_restrict_delete_data'), '1'); ?>>
                    <strong>Delete my data when I delete this plugin.</strong> Blocked IPs, blocked keywords, settings, and repeat-submission records go permanently &mdash; but only if and when you click Delete on the Plugins screen.
                </label>
            </p>
            <p class="description">Left unchecked, everything stays in the database even after the plugin is deleted, so a reinstall picks up where you left off.</p>
            <p style="text-align:right;margin-bottom:0;">
                <button type="button" class="button" data-cf7-ip-restrict="cancel">Cancel</button>
                <button type="button" class="button button-primary" data-cf7-ip-restrict="go">Deactivate</button>
            </p>
        </dialog>
        <script>
            (function () {
                var dialog = document.getElementById('cf7-ip-restrict-purge');
                var box = document.getElementById('cf7-ip-restrict-purge-box');
                // Matched on the href, not the link id core builds from the name.
                var selector = 'tr[data-plugin="<?php echo esc_js($basename); ?>"] a[href*="action=deactivate"]';
                var href = '';

                document.addEventListener('click', function (e) {
                    var link = e.target.closest && e.target.closest(selector);
                    if (!link) {
                        return;
                    }
                    e.preventDefault();
                    href = link.href;
                    dialog.showModal();
                });

                dialog.querySelector('[data-cf7-ip-restrict="cancel"]').addEventListener('click', function () {
                    dialog.close();
                });

                dialog.querySelector('[data-cf7-ip-restrict="go"]').addEventListener('click', function () {
                    this.disabled = true;
                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: new URLSearchParams({
                            action: 'cf7_ip_restrict_purge_consent',
                            nonce: '<?php echo esc_js(wp_create_nonce('cf7_ip_restrict_purge')); ?>',
                            purge: box.checked ? '1' : ''
                        })
                    }).then(function () {
                        window.location = href;
                    });
                });
            })();
        </script>
    <?php
    }

    // Network-wide, since deleting the plugin removes the files for every site.
    // get_site_option falls back to the options table on single sites.
    public function save_purge_consent()
    {
        check_ajax_referer('cf7_ip_restrict_purge', 'nonce');

        if (!current_user_can('deactivate_plugins')) {
            wp_send_json_error(null, 403);
        }

        if (empty($_POST['purge'])) {
            delete_site_option('cf7_ip_restrict_delete_data');
        } else {
            update_site_option('cf7_ip_restrict_delete_data', '1');
        }

        wp_send_json_success();
    }

    // Adds the settings page under Contact Form 7's own menu ("wpcf7" is CF7's
    // top-level slug). The page slug is unchanged, so the Settings link on the
    // Plugins row still resolves.
    public function add_admin_menu()
    {
        $this->hook_suffix = add_submenu_page(
            'wpcf7',
            'CF7 IP Restrict Settings',
            'CF7 IP Restrict',
            'manage_options',
            'cf7-ip-restrict-settings',
            array($this, 'display_settings_page')
        );
    }

    public function enqueue_styles($hook)
    {
        if ($hook === $this->hook_suffix) {
            wp_enqueue_style('cf7-ip-restrict-admin', plugin_dir_url(__FILE__) . 'admin-style.css', array('dashicons'), CF7_IP_RESTRICT_VERSION);
        }
    }

    // Registers the options with the Settings API, which is what gives the page
    // its nonce, capability check and per-option sanitising. The fields are laid
    // out by hand in display_settings_page() rather than by do_settings_sections.
    public function register_settings()
    {
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_blocked_ips', array('sanitize_callback' => array($this, 'sanitize_ips')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_blocked_keywords', array('sanitize_callback' => array($this, 'sanitize_keywords')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_personal_domains', array('sanitize_callback' => array($this, 'sanitize_domains')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_domain_forms', array('sanitize_callback' => array($this, 'sanitize_forms')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_domain_enabled', array('sanitize_callback' => array($this, 'sanitize_toggle')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_apply_to_logged_in', array('sanitize_callback' => array($this, 'sanitize_toggle')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_repeat_enabled', array('sanitize_callback' => array($this, 'sanitize_toggle')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_repeat_duration', array('sanitize_callback' => array($this, 'sanitize_duration')));
        register_setting('cf7_ip_restrict_settings', 'cf7_ip_restrict_repeat_unit', array('sanitize_callback' => array($this, 'sanitize_unit')));
    }

    // Renders a checkbox styled as an on/off switch. The text sits outside the
    // label so only the switch itself is clickable; aria-label keeps the name.
    private function switch_field($option, $label, $default = '', $controls = '')
    {
        echo '<span class="cf7-ip-restrict-switch">';
        echo '<label class="cf7-ip-restrict-toggle">';
        echo '<input type="checkbox" name="' . esc_attr($option) . '" value="1" aria-label="' . esc_attr($label) . '"';
        echo $controls ? ' data-cf7-toggle="' . esc_attr($controls) . '"' : '';
        echo ' ' . checked(get_option($option, $default), '1', false) . '>';
        echo '<span class="cf7-ip-restrict-slider"></span>';
        echo '</label>';
        echo '<span class="cf7-ip-restrict-switch-text">' . esc_html($label) . '</span>';
        echo '</span>';
    }

    // The how-long controls are rendered hidden when the switch is off so there
    // is no flash of them before the inline script runs.
    public function repeat_field_callback()
    {
        $hidden = get_option('cf7_ip_restrict_repeat_enabled', '1') ? '' : ' hidden';
        $unit = get_option('cf7_ip_restrict_repeat_unit', 'minutes');
?>
        <?php $this->switch_field('cf7_ip_restrict_repeat_enabled', 'Ask visitors to confirm before they submit a form again', '1', '.cf7-ip-restrict-when-repeat'); ?>
        <span class="cf7-ip-restrict-window cf7-ip-restrict-when-repeat"<?php echo $hidden; ?>>
            <input type="number" name="cf7_ip_restrict_repeat_duration" value="<?php echo esc_attr(absint(get_option('cf7_ip_restrict_repeat_duration', 0))); ?>" min="0" step="1" aria-label="How long the prompt lasts">
            <select name="cf7_ip_restrict_repeat_unit" aria-label="Unit">
                <?php foreach (array('seconds' => 'Seconds', 'minutes' => 'Minutes') as $value => $text) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($unit, $value); ?>><?php echo esc_html($text); ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <p class="description">
            Detects repeats by cookie and IP, so a different browser or device is still caught.
            <span class="cf7-ip-restrict-when-repeat"<?php echo $hidden; ?>><strong>0</strong> = until the browser closes. Capped at 30 days.</span>
        </p>
<?php
    }

    // Renders the on/off switch for applying the rules to logged-in users
    public function apply_to_logged_in_field_callback()
    {
        $this->switch_field('cf7_ip_restrict_apply_to_logged_in', 'Apply blocking and the repeat-submission prompt to logged-in users');
        echo '<p class="description">Off by default, so administrators can keep testing forms without being blocked.</p>';
    }

    // Every tab renders inside the one form: options.php writes null over any
    // registered option missing from the POST, so hiding is CSS, never markup.
    public function display_settings_page()
    {
        $domains_on = get_option('cf7_ip_restrict_domain_enabled', '1') ? '' : ' hidden';
        $tabs = array(
            'general' => array('General', 'dashicons-admin-generic'),
            'captcha' => array('Captcha', 'dashicons-shield'),
            'domains' => array('Domain Block', 'dashicons-email-alt'),
        );
?>
        <div class="cf7-ip-restrict-app">
            <form action="options.php" method="post">
                <?php settings_fields('cf7_ip_restrict_settings'); ?>
                <div class="cf7-ip-restrict-layout">
                    <aside class="cf7-ip-restrict-sidebar">
                        <div class="cf7-ip-restrict-brand">
                            <strong>CF7 IP Restrict</strong>
                            <span>v<?php echo esc_html(CF7_IP_RESTRICT_VERSION); ?></span>
                        </div>
                        <nav class="cf7-ip-restrict-nav">
                            <?php foreach ($tabs as $slug => $tab) : ?>
                                <button type="button" class="cf7-ip-restrict-tab" data-tab="<?php echo esc_attr($slug); ?>" data-title="<?php echo esc_attr($tab[0] . ' Settings'); ?>">
                                    <span class="dashicons <?php echo esc_attr($tab[1]); ?>" aria-hidden="true"></span>
                                    <?php echo esc_html($tab[0]); ?>
                                </button>
                            <?php endforeach; ?>
                        </nav>
                    </aside>
                    <div class="cf7-ip-restrict-main">
                        <header class="cf7-ip-restrict-header">
                            <h1 class="cf7-ip-restrict-title"><?php echo esc_html($tabs['general'][0] . ' Settings'); ?></h1>
                            <button type="submit" class="cf7-ip-restrict-save">Save Changes</button>
                        </header>
                        <div class="cf7-ip-restrict-body">
                            <?php settings_errors(); ?>
                            <?php if (is_user_logged_in() && !get_option('cf7_ip_restrict_apply_to_logged_in')) : ?>
                                <div class="notice notice-warning inline">
                                    <p><strong>None of these rules apply to you right now.</strong> You are logged in, and <em>Logged-in Users</em> is off, so your own submissions are never blocked &mdash; not even by the IP list. Test in a private window, or turn that toggle on.</p>
                                </div>
                            <?php endif; ?>

                            <section class="cf7-ip-restrict-panel" data-panel="general">
                                <div class="cf7-ip-restrict-grid">
                                    <?php
                                    $this->card('Repeat Submissions', 'repeat_field_callback');
                                    $this->card('Logged-in Users', 'apply_to_logged_in_field_callback');
                                    $this->card('Blocked IP Addresses', 'blocked_ips_field_callback');
                                    $this->card('Blocked Keywords', 'blocked_keywords_field_callback');
                                    ?>
                                </div>
                            </section>

                            <section class="cf7-ip-restrict-panel" data-panel="captcha" hidden>
                                <div class="cf7-ip-restrict-grid">
                                    <div class="cf7-ip-restrict-card">
                                        <h2>Captcha</h2>
                                        <p class="description">Not built yet.</p>
                                    </div>
                                </div>
                            </section>

                            <section class="cf7-ip-restrict-panel" data-panel="domains" hidden>
                                <div class="cf7-ip-restrict-panel-head">
                                    <?php $this->switch_field('cf7_ip_restrict_domain_enabled', 'Ask for a business email address', '1', '.cf7-ip-restrict-when-domains'); ?>
                                </div>
                                <p class="cf7-ip-restrict-intro cf7-ip-restrict-when-domains"<?php echo $domains_on; ?>>
                                    Submissions from a personal domain are <strong>still delivered to you</strong> &mdash; the visitor just sees &ldquo;<?php echo esc_html(CF7_IP_Restrict_Public::BUSINESS_EMAIL_MESSAGE); ?>&rdquo; under the email field, with their answers kept, instead of the thank-you message. Nothing is rejected.
                                </p>
                                <div class="cf7-ip-restrict-grid cf7-ip-restrict-when-domains"<?php echo $domains_on; ?>>
                                    <?php
                                    $this->card('Apply To Forms', 'domain_forms_field_callback');
                                    $this->card('Personal Email Domains', 'personal_domains_field_callback');
                                    ?>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <script>
            (function () {
                var app = document.querySelector('.cf7-ip-restrict-app');
                var tabs = app.querySelectorAll('.cf7-ip-restrict-tab');
                var panels = app.querySelectorAll('.cf7-ip-restrict-panel');
                var title = app.querySelector('.cf7-ip-restrict-title');

                function show(slug) {
                    tabs.forEach(function (tab) {
                        var active = tab.dataset.tab === slug;
                        tab.classList.toggle('is-active', active);
                        if (active) {
                            title.textContent = tab.dataset.title;
                        }
                    });
                    panels.forEach(function (panel) {
                        panel.hidden = panel.dataset.panel !== slug;
                    });
                }

                tabs.forEach(function (tab) {
                    tab.addEventListener('click', function () {
                        location.hash = tab.dataset.tab;
                        show(tab.dataset.tab);
                    });
                });

                // Browsers keep the fragment across the redirect options.php does
                // after saving, so you land back on the tab you saved from.
                var wanted = location.hash.slice(1).replace(/[^a-z]/g, '');
                show(app.querySelector('.cf7-ip-restrict-panel[data-panel="' + wanted + '"]') ? wanted : 'general');

                // Each switch shows or hides whatever its own selector matches.
                app.querySelectorAll('[data-cf7-toggle]').forEach(function (toggle) {
                    toggle.addEventListener('change', function () {
                        app.querySelectorAll(toggle.dataset.cf7Toggle).forEach(function (el) {
                            el.hidden = !toggle.checked;
                        });
                    });
                });
            })();
        </script>
    <?php
    }

    // Wraps one field callback in a titled card.
    private function card($title, $callback)
    {
        echo '<div class="cf7-ip-restrict-card"><h2>' . esc_html($title) . '</h2>';
        call_user_func(array($this, $callback));
        echo '</div>';
    }

    // Renders the settings field for blocked IP addresses
    public function blocked_ips_field_callback()
    {
        $ips = get_option('cf7_ip_restrict_blocked_ips');
        echo '<textarea name="cf7_ip_restrict_blocked_ips" rows="5" placeholder="Enter IP addresses...">' . esc_textarea($ips) . '</textarea>';
        echo '<p class="description">Enter IP addresses to block, one per line or separated by commas (e.g., 192.168.1.1, 10.0.0.2). Anyone submitting a form from a listed address is refused.</p>';
    }

    // Renders the settings field for blocked keywords
    public function blocked_keywords_field_callback()
    {
        $keywords = get_option('cf7_ip_restrict_blocked_keywords');
        echo '<textarea name="cf7_ip_restrict_blocked_keywords" rows="5" placeholder="Enter keywords...">' . esc_textarea($keywords) . '</textarea>';
        echo '<p class="description">Enter keywords to block, one per line or separated by commas. Case-insensitive, and matched anywhere they appear including inside a longer word or an email address, so <code>hello</code> also blocks <code>hello123@gmail.com</code> and <code>nr.abchello@abc.com</code>.</p>';
    }

    // Lists the published CF7 forms the business-email notice can apply to.
    public function domain_forms_field_callback()
    {
        $selected = array_map('absint', (array) get_option('cf7_ip_restrict_domain_forms', array()));
        $forms = class_exists('WPCF7_ContactForm')
            ? WPCF7_ContactForm::find(array('post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC'))
            : array();

        if (!$forms) {
            echo '<p class="description">No published Contact Form 7 forms found.</p>';
            return;
        }

        echo '<ul class="cf7-ip-restrict-forms">';
        foreach ($forms as $form) {
            echo '<li><label>';
            echo '<input type="checkbox" name="cf7_ip_restrict_domain_forms[]" value="' . absint($form->id()) . '"' . checked(in_array((int) $form->id(), $selected, true), true, false) . '>';
            echo '<span>' . esc_html($form->title()) . '</span>';
            echo '</label></li>';
        }
        echo '</ul>';
        echo '<p class="description">Tick the forms that should ask for a business email address. Leave every box unchecked to apply it to <strong>all</strong> forms.</p>';
    }

    // Renders the settings field for personal email domains
    public function personal_domains_field_callback()
    {
        $domains = get_option('cf7_ip_restrict_personal_domains');
        echo '<textarea name="cf7_ip_restrict_personal_domains" rows="5" placeholder="gmail.com, yahoo.com, hotmail.com, outlook.com">' . esc_textarea($domains) . '</textarea>';
        echo '<p class="description">One per line or comma-separated. Matched exactly and case-insensitively, so <code>gmail.com</code> does not cover <code>mail.gmail.com</code>.</p>';
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

    // Stores canonical domains and tells the admin which entries were dropped.
    public function sanitize_domains($input)
    {
        $valid = array();
        $invalid = array();

        foreach (CF7_IP_Restrict::to_list($input) as $entry) {
            $domain = CF7_IP_Restrict::normalize_domain(sanitize_text_field($entry));

            if ($domain !== '') {
                $valid[] = $domain;
            } else {
                $invalid[] = $entry;
            }
        }

        if ($invalid) {
            add_settings_error(
                'cf7_ip_restrict_personal_domains',
                'cf7_ip_restrict_invalid_domains',
                'Ignored entries that are not email domains: ' . esc_html(implode(', ', $invalid))
            );
        }

        return implode(', ', array_unique($valid));
    }

    // Keeps only ids that are really CF7 forms. Empty means every form.
    public function sanitize_forms($input)
    {
        $valid = array();

        foreach ((array) $input as $id) {
            $id = absint($id);

            if ($id && wpcf7_contact_form($id)) {
                $valid[] = $id;
            }
        }

        return array_values(array_unique($valid));
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
