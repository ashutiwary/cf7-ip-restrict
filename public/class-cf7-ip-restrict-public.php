<?php

class CF7_IP_Restrict_Public
{
    const CONFIRM_FIELD = 'cf7-ip-restrict-confirm';
    const CLEANUP_HOOK = 'cf7_ip_restrict_cleanup_repeat';

    // Set when this plugin rejects a submission, read back when the response
    // is built so CF7's own field-level output can be dropped.
    private $block_reason = '';

    public function enqueue_scripts()
    {
        // Enqueue front-end scripts and styles.
        wp_enqueue_style('cf7-ip-restrict-public-style', plugin_dir_url(__FILE__) . 'public-style.css', array(), '2.2.0', 'all');
        wp_enqueue_script('cf7-ip-restrict-public-script', plugin_dir_url(__FILE__) . 'public-script.js', array(), '2.2.0', true);
        wp_localize_script('cf7-ip-restrict-public-script', 'cf7IpRestrict', array(
            'repeatEnabled' => get_option('cf7_ip_restrict_repeat_enabled', '1') ? 1 : 0,
            'repeatMaxAge'  => $this->repeat_max_age(),
        ));
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
        if (!get_option('cf7_ip_restrict_repeat_enabled', '1')) {
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

    // Keeps the rejection but removes CF7's field-level errors and banner text,
    // and hands the reason to the front end so the modal knows what to say.
    public function filter_feedback_response($response, $result)
    {
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

        if (get_option('cf7_ip_restrict_repeat_enabled', '1') && empty($_POST[self::CONFIRM_FIELD])) {
            $key = $this->repeat_transient_key($user_ip);
            if ($key !== '' && get_transient($key)) {
                return $this->block($result, $tags, 'repeat', "You already submitted this form recently.");
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
                <div class="cf-modal-footer">
                    <button type="button" class="btn cf-btn-secondary cf-unblock">Submit Again</button>
                    <button type="button" class="btn cf-btn-primary cf-close-custom">Close</button>
                </div>
            </div>
        </div>
<?php
    }
}
