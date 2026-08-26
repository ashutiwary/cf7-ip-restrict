<?php

class CF7_IP_Restrict_Public
{
    const CONFIRM_FIELD = 'cf7-ip-restrict-confirm';
    const CAPTCHA_FIELD = 'cf7-ip-restrict-captcha';
    const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    const CLEANUP_HOOK = 'cf7_ip_restrict_cleanup_repeat';
    const BUSINESS_EMAIL_MESSAGE = 'Please enter your business email address.';

    // Set when this plugin rejects a submission, read back when the response
    // is built so CF7's own field-level output can be dropped.
    private $block_reason = '';

    // Email field that was filled in with a personal domain, if any.
    private $personal_email_field = '';

    // The admin's own wording, falling back to the constant when left blank.
    public static function business_email_message()
    {
        $message = trim((string) get_option('cf7_ip_restrict_domain_message', ''));

        return $message !== '' ? $message : self::BUSINESS_EMAIL_MESSAGE;
    }

    public function enqueue_scripts()
    {
        // Enqueue front-end scripts and styles.
        wp_enqueue_style('cf7-ip-restrict-public-style', plugin_dir_url(__FILE__) . 'public-style.css', array(), CF7_IP_RESTRICT_VERSION, 'all');
        wp_enqueue_script('cf7-ip-restrict-public-script', plugin_dir_url(__FILE__) . 'public-script.js', array(), CF7_IP_RESTRICT_VERSION, true);
        wp_localize_script('cf7-ip-restrict-public-script', 'cf7IpRestrict', array(
            'repeatEnabled' => get_option('cf7_ip_restrict_repeat_enabled', '1') ? 1 : 0,
            'repeatMaxAge'  => $this->repeat_max_age(),
            // Only the public half of the pair ever reaches the browser.
            'captchaKey'    => $this->captcha_active() ? get_option('cf7_ip_restrict_captcha_site_key') : '',
        ));

        if ($this->captcha_active()) {
            wp_enqueue_script('cf7-ip-restrict-recaptcha', 'https://www.google.com/recaptcha/api.js?render=explicit', array(), null, true);
        }
    }

    // A captcha only exists once it is switched on and both keys are saved.
    private function captcha_active()
    {
        return get_option('cf7_ip_restrict_captcha_enabled')
            && get_option('cf7_ip_restrict_repeat_enabled', '1')
            && get_option('cf7_ip_restrict_captcha_site_key')
            && get_option('cf7_ip_restrict_captcha_secret_key');
    }

    // Fails closed: an unreachable Google, a missing token or a rejected one all
    // mean the resubmission does not go through.
    private function captcha_passed()
    {
        $token = isset($_POST[self::CAPTCHA_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::CAPTCHA_FIELD])) : '';

        if ($token === '') {
            return false;
        }

        $response = wp_remote_post(self::VERIFY_URL, array(
            'timeout' => 10,
            'body'    => array(
                'secret'   => get_option('cf7_ip_restrict_captcha_secret_key'),
                'response' => $token,
                'remoteip' => CF7_IP_Restrict::client_ip(),
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return !empty($body['success']);
    }

    // Cookie lifetime in seconds. 0 means it lasts until the browser is closed.
    private function repeat_max_age()
    {
        $amount = absint(get_option('cf7_ip_restrict_repeat_duration', 0));
        $seconds = get_option('cf7_ip_restrict_repeat_unit', 'minutes') === 'seconds' ? $amount : $amount * MINUTE_IN_SECONDS;

        return min($seconds, 30 * DAY_IN_SECONDS);
    }

    // 0 means "session cookie" client-side; server-side falls back to a day.
    private function repeat_window_seconds()
    {
        $seconds = $this->repeat_max_age();
        return $seconds > 0 ? $seconds : DAY_IN_SECONDS;
    }

    private function repeat_transient_key($user_ip)
    {
        $packed = $this->ip_key($user_ip);
        return $packed === '' ? '' : 'cf7_ip_restrict_repeat_' . bin2hex($packed);
    }

    public function remember_submission($contact_form)
    {
        // A submission that got the business-email notice is not remembered, so
        // correcting the address and sending again is not treated as a repeat.
        if ($this->personal_email_field !== '' || !get_option('cf7_ip_restrict_repeat_enabled', '1')) {
            return;
        }
        $key = $this->repeat_transient_key(CF7_IP_Restrict::client_ip());
        if ($key === '') {
            return;
        }

        $seconds = $this->repeat_window_seconds();
        set_transient($key, true, $seconds);

        wp_clear_scheduled_hook(self::CLEANUP_HOOK, array($key));
        wp_schedule_single_event(time() + $seconds, self::CLEANUP_HOOK, array($key));
    }

    // One address can be written several ways, so compare canonical binary
    // forms instead of strings: "::1" and "0:0:0:0:0:0:0:1" are the same host,
    // and "::ffff:1.2.3.4" is the same host as "1.2.3.4". Returns '' for
    // anything that is not a parseable address.
    private function ip_key($ip)
    {
        $packed = @inet_pton((string) $ip);
        if ($packed === false) {
            return '';
        }

        // Unwrap IPv4-mapped IPv6 so both spellings collapse to one key.
        if (strlen($packed) === 16 && strncmp($packed, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
            $packed = substr($packed, 12);
        }

        return $packed;
    }

    // Rejects the submission. The field-level message is what stops the mail;
    // filter_feedback_response() then strips it from the response so the
    // visitor only sees the modal, not red text under an unrelated field.
    // CF7 silently discards an error attached to a tag with no name, so pick
    // the first tag that actually has one.
    private function block($result, $tags, $reason, $message)
    {
        $this->block_reason = $reason;

        foreach ($tags as $tag) {
            if (!empty($tag->name)) {
                $result->invalidate($tag, $message);
                break;
            }
        }
        return $result;
    }

    // A personal domain is a nudge, never a rejection: $result is returned
    // untouched, so CF7 carries on and the mail is sent as normal.
    public function check_business_email($result, $tags)
    {
        $domains = CF7_IP_Restrict::to_list(get_option('cf7_ip_restrict_personal_domains'));
        $submission = WPCF7_Submission::get_instance();

        if (!$domains || !$submission || !get_option('cf7_ip_restrict_domain_enabled', '1')) {
            return $result;
        }

        // Opt in per form: nothing happens until a form is ticked in settings.
        $forms = array_map('absint', (array) get_option('cf7_ip_restrict_domain_forms', array()));
        $contact_form = $submission->get_contact_form();

        if (!$forms || !$contact_form || !in_array((int) $contact_form->id(), $forms, true)) {
            return $result;
        }

        $posted = $submission->get_posted_data();

        foreach ($tags as $tag) {
            if ($tag->basetype !== 'email' || empty($tag->name) || !isset($posted[$tag->name])) {
                continue;
            }

            // to_list also splits the comma-separated value an email field
            // posts when it carries the "multiple:" option.
            foreach ((array) $posted[$tag->name] as $value) {
                foreach (CF7_IP_Restrict::to_list($value) as $address) {
                    if (CF7_IP_Restrict::is_personal_email($address, $domains)) {
                        $this->personal_email_field = $tag->name;
                        return $result;
                    }
                }
            }
        }

        return $result;
    }

    // Keeps the rejection but removes CF7's field-level errors and banner text,
    // and hands the reason to the front end so the modal knows what to say.
    public function filter_feedback_response($response, $result)
    {
        // Mail is already sent here. Reporting a failed validation is what keeps
        // the typed values and stops the thank-you, reset and any redirect.
        if ($this->personal_email_field !== '' && isset($response['status']) && $response['status'] === 'mail_sent') {
            $contact_form = isset($response['contact_form_id']) ? wpcf7_contact_form($response['contact_form_id']) : null;
            $message = $contact_form ? $contact_form->message('validation_error') : '';

            $response['status'] = 'validation_failed';
            $response['message'] = $message !== '' ? $message : self::business_email_message();
            $response['invalid_fields'][] = array(
                'field'   => str_replace('.', '_', $this->personal_email_field),
                'message' => self::business_email_message(),
            );
        }

        if (!$this->block_reason) {
            return $response;
        }

        $response['cf7_ip_restrict'] = $this->block_reason;
        $response['invalid_fields'] = array();
        $response['message'] = '';

        return $response;
    }

    public function check_ip_before_submission($result, $tags)
    {
        $user_ip = CF7_IP_Restrict::client_ip();

        // Refuse the submission if the visitor's address is on the blocklist.
        $visitor = $this->ip_key($user_ip);
        if ($visitor !== '') {
            foreach (CF7_IP_Restrict::to_list(get_option('cf7_ip_restrict_blocked_ips')) as $blocked) {
                if ($this->ip_key($blocked) === $visitor) {
                    return $this->block($result, $tags, 'ip', "Submission is Blocked");
                }
            }
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return $result;
        }

        // Every field is searched, not just the message: name, email, subject,
        // dropdowns, checkboxes, anything the visitor filled in.
        $haystack = implode("\n", $this->posted_strings($submission->get_posted_data()));

        // Substring match: "hello" blocks hello123@gmail.com, abchello@abc.com
        // and abc@hello.com alike. stripos covers the ASCII case and cannot be
        // defeated by malformed UTF-8; the regex adds multibyte case folding.
        // to_list() guarantees no keyword is empty, which would match anything.
        foreach (CF7_IP_Restrict::to_list(get_option('cf7_ip_restrict_blocked_keywords')) as $keyword) {
            if (stripos($haystack, $keyword) !== false
                || preg_match('/' . preg_quote($keyword, '/') . '/iu', $haystack)) {
                return $this->block($result, $tags, 'keyword', "Your submission contains inapropriate words");
            }
        }

        if (get_option('cf7_ip_restrict_repeat_enabled', '1')) {
            $key = $this->repeat_transient_key($user_ip);

            if ($key !== '' && get_transient($key)) {
                if (empty($_POST[self::CONFIRM_FIELD])) {
                    return $this->block($result, $tags, 'repeat', "You already submitted this form recently.");
                }

                // Confirmed, but the captcha is what makes that confirmation
                // worth anything: the field on its own is trivial to forge.
                if ($this->captcha_active() && !$this->captcha_passed()) {
                    return $this->block($result, $tags, 'captcha', "Captcha check failed. Please try again.");
                }
            }
        }

        return $result;
    }

    // Every submitted string, flattened out of the nested arrays that
    // checkboxes and multi-selects post. Joined on a newline by the caller,
    // which is safe because to_list() never lets a keyword contain one.
    private function posted_strings($posted_data)
    {
        $strings = array();

        array_walk_recursive($posted_data, function ($value) use (&$strings) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        });

        return $strings;
    }

    public function add_custom_error_modal_html()
    {
        // Outputs the HTML for a modal in the footer.
?>
        <div id="cfcustomErrorModal" class="cf-modal-custom">
            <div class="cf-modal-content-custom">
                <span class="cf-close-custom close-arrow">&times;</span>
                <h5 class="cf-modal-title">Warning</h5>
                <div class="cf-modal-body">
                    You Already Submitted Form. Do you want to Submit Again?
                </div>
                <?php if ($this->captcha_active()) : ?>
                    <div class="cf-modal-captcha" id="cf-captcha-box"></div>
                <?php endif; ?>
                <div class="cf-modal-footer">
                    <button type="button" class="btn cf-btn-secondary cf-unblock">Submit Again</button>
                    <button type="button" class="btn cf-btn-primary cf-close-custom">Close</button>
                </div>
            </div>
        </div>
<?php
    }
}
