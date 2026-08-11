<?php

class CF7_IP_Restrict_Public
{
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

    // CF7 silently discards an error attached to a tag with no name, so pick
    // the first tag that actually has one.
    private function block($result, $tags, $message)
    {
        foreach ($tags as $tag) {
            if (!empty($tag->name)) {
                $result->invalidate($tag, $message);
                break;
            }
        }
        return $result;
    }

    public function check_ip_before_submission($result, $tags)
    {
        $user_ip = $this->client_ip();

        // Check against permanently blocked IPs
        if ($user_ip) {
            $blocked_ips = CF7_IP_Restrict::to_list(get_option('cf7_ip_restrict_blocked_ips'));
            if (in_array($user_ip, $blocked_ips, true)) {
                return $this->block($result, $tags, "Submission is Blocked");
            }
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return $result;
        }

        // Whole-word keyword match, so "ass" does not flag "Cassandra". A word
        // boundary is only added where the keyword edge is a word character —
        // "\b" next to punctuation never matches, which would silently break
        // keywords like "c++", "$$$" or ".ru".
        $posted_data = $submission->get_posted_data();
        foreach (CF7_IP_Restrict::to_list(get_option('cf7_ip_restrict_blocked_keywords')) as $keyword) {
            $open = preg_match('/^\w/', $keyword) ? '\b' : '';
            $close = preg_match('/\w$/', $keyword) ? '\b' : '';
            $pattern = '/' . $open . preg_quote($keyword, '/') . $close . '/iu';
            foreach ($posted_data as $form_value) {
                if (is_string($form_value) && preg_match($pattern, $form_value)) {
                    return $this->block($result, $tags, "Your submission contains inapropriate words");
                }
            }
        }

        return $result;
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
