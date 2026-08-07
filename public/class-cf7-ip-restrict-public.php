<?php

class CF7_IP_Restrict_Public
{
    public function enqueue_scripts()
    {
        // Enqueue front-end scripts and styles.
        wp_enqueue_style('cf7-ip-restrict-public-style', plugin_dir_url(__FILE__) . 'public-style.css', array(), '2.1.0', 'all');
        wp_enqueue_script('cf7-ip-restrict-public-script', plugin_dir_url(__FILE__) . 'public-script.js', array(), '2.1.0', true);
        // localize ajax
        wp_localize_script('cf7-ip-restrict-public-script', 'ajax_object', array(
            'ajax_url' => admin_url('admin-ajax.php')
        ));
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

    // One lock per visitor for the whole site, not per form.
    private function transient_key($ip)
    {
        return 'block_ip_' . $ip;
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

    public function capture_user_ip_on_submission($contact_form)
    {
        $user_ip = $this->client_ip();
        if ($user_ip) {
            set_transient($this->transient_key($user_ip), true, 5 * MINUTE_IN_SECONDS);
        }
    }

    public function check_ip_before_submission($result, $tags)
    {
        $user_ip = $this->client_ip();
        if (!$user_ip) {
            return $result;
        }

        // Check against permanently blocked IPs
        $blocked_ips = CF7_IP_Restrict::to_list(get_option('cf7_ip_restrict_blocked_ips'));
        if (in_array($user_ip, $blocked_ips, true)) {
            return $this->block($result, $tags, "Submission is Blocked");
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return $result;
        }

        // Whole-word keyword match, so "ass" does not flag "Cassandra".
        $posted_data = $submission->get_posted_data();
        foreach (CF7_IP_Restrict::to_list(get_option('cf7_ip_restrict_blocked_keywords')) as $keyword) {
            $pattern = '/\b' . preg_quote($keyword, '/') . '\b/iu';
            foreach ($posted_data as $form_value) {
                if (is_string($form_value) && preg_match($pattern, $form_value)) {
                    return $this->block($result, $tags, "Your submission contains inapropriate words");
                }
            }
        }

        // Check if the visitor already submitted a form anywhere on the site
        if (get_transient($this->transient_key($user_ip))) {
            return $this->block($result, $tags, "Please wait for 5 Minute");
        }

        return $result;
    }

    public function unblock_user_submit_again()
    {
        $user_ip = $this->client_ip();

        if ($user_ip) {
            delete_transient($this->transient_key($user_ip));
        }

        // A block that already expired on its own still leaves the user unblocked.
        wp_send_json_success(array('message' => 'IP unblocked successfully'));
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
