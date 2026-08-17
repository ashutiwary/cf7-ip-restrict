<?php

class CF7_IP_Restrict_Public
{
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

    // Proxy headers are only trusted when the site opts in with
    // define('CF7_IP_RESTRICT_TRUST_PROXY', true) in wp-config.php, otherwise
    // any visitor could send a fake header and skip the blocklist.
    public function client_ip()
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
        $user_ip = $this->client_ip();

        // Check against permanently blocked IPs
        if ($user_ip) {
            $blocked_ips = CF7_IP_Restrict::to_list(get_option('cf7_ip_restrict_blocked_ips'));
            if (in_array($user_ip, $blocked_ips, true)) {
                return $this->block($result, $tags, 'ip', "Submission is Blocked");
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
