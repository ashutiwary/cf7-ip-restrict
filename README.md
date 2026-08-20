# CF7 IP Restrict

A WordPress plugin that warns [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) visitors before a repeat submission, and blocks submissions by IP address and by keyword. All three surface in a modal instead of CF7's inline error text.

- **Version:** 2.2.0
- **Author:** Ashu Tiwary
- **Requires:** WordPress, Contact Form 7 (active)

## What it does

| Rule | Behaviour | Where it runs |
| --- | --- | --- |
| **Repeat submission** | Once a visitor has submitted any form, the next submit attempt on **any page** opens a modal: *"You Already Submitted Form. Do you want to Submit Again?"* — **Submit Again** sends it, **Close** cancels and leaves the typed input alone. Detected by browser cookie and, as a fallback, by matching the visitor's IP, so a different browser or device on the same connection is still caught. Can be switched off, and the window is configurable. | Browser + server |
| **IP block** | IPs listed in settings are rejected outright. The modal appears with the *Submit Again* button hidden, and **no red error text is added to any field**. | Server |
| **Keyword block** | A blocked keyword in **any** field — name, email, subject, message, dropdowns, checkboxes — rejects the submission (whole word, case-insensitive), no retry offered. Also modal-only, with no field error. | Server |

Logged-in users are exempt from all three by default — none of the front-end hooks are registered for them, so test in a private window. Flip the **Logged-in Users** toggle in settings to apply the rules to them as well.

## Install

1. Copy the `cf7-iprestrict` folder into `wp-content/plugins/`.
2. Make sure Contact Form 7 is installed and **active** — activation is blocked otherwise.
3. Activate **CF7 IP Restrict** from the Plugins screen.

## Settings

**Contact → IP Restrict** in the admin sidebar, or the *Settings* link on the Plugins row.

- **Repeat Submissions** — on by default. Off, visitors can submit as often as they like with no prompt; IP and keyword blocking are unaffected. On the right of the same row sits the window: a number plus **Seconds** or **Minutes**, for how long after a submission the prompt keeps appearing. **0** keeps it until the browser is closed, and values are capped at 30 days. The window controls are hidden while the toggle is off.
- **Logged-in Users** — off by default. On, every rule below also applies to logged-in users, including administrators. Leave it off while you are testing forms from your own account.
- **Blocked IP Addresses** — one per line or comma-separated. Entries that are not valid IPs are dropped on save and named in an admin notice.
- **Blocked Keywords** — one per line or comma-separated. Case-insensitive, matched **anywhere** the keyword appears, including inside a longer word or an email address. `hello` blocks `hello123@gmail.com`, `nr.abchello@abc.com` and `abc@hello.com`. Punctuation works as written, so `.ru`, `$$$`, `bit.ly` and `c++` are all valid keywords.

### Behind a proxy or CDN

`REMOTE_ADDR` is the proxy's IP behind Cloudflare, a load balancer, or any reverse proxy, which would make every visitor share one identity. To read the real client IP, opt in explicitly in `wp-config.php`:

```php
define('CF7_IP_RESTRICT_TRUST_PROXY', true);
```

`CF-Connecting-IP` then `X-Forwarded-For` are used, first hop only. This is **off by default on purpose** — trusting those headers unconditionally would let any visitor spoof one and walk past the blocklist.

### Repeat-submission window

The prompt is driven by a `cf7_already_submitted` cookie, and separately by a server-side record of the visitor's IP, both timed from the **Repeat Window** setting. With the default of `0` the cookie is session-only; the server can't express "until the browser closes", so it falls back to a day. The amount and unit are passed to the front end via `wp_localize_script` as `cf7IpRestrict`.

The IP record deletes itself exactly when its window ends (`wp_schedule_single_event`), not just via WordPress's daily transient sweep — so it never lingers past its own expiry.

Because these are site settings rather than per-visitor state, a page cache holding them briefly is harmless — unlike the visitor's own "already submitted" flag, which is why that lives in a cookie instead.

## How it works

| File | Role |
| --- | --- |
| `cf7-iprestrict.php` | Plugin header, CF7 dependency check, bootstrap |
| `includes/class-cf7-ip-restrict.php` | Hook registration, shared `to_list()` option parser |
| `admin/class-cf7-ip-restrict-admin.php` | Settings page (Settings API), input sanitising |
| `public/class-cf7-ip-restrict-public.php` | IP/keyword validation, modal markup |
| `public/public-script.js` | Repeat-submission prompt, modal behaviour |
| `public/public-style.css` | Modal styling |
| `index.php` | Empty-index guard against directory listing |

**Repeat submission** has two independent layers. The fast path: a capture-phase `submit` listener on `document` runs before CF7's own handler, so when the cookie is present the submission is stopped with `preventDefault()` before anything is posted — no request, no validation error, nothing to suppress. The cookie is set on CF7's `wpcf7mailsent` event.

The fallback: `wpcf7_before_send_mail` records the visitor's IP in a transient on every successful send, and `check_ip_before_submission` (the same `wpcf7_validate` filter used for IP/keyword blocking) checks it on the next attempt. This is what catches a different browser, a private window, or a cleared cookie on the same connection — cases the cookie alone can't see. It reports back as `reason: 'repeat'` through the same `wpcf7_feedback_response` filter described below, so it gets the same modal with no red field errors.

Either layer triggers the same modal. *Submit Again* clears the cookie, adds a hidden `cf7-ip-restrict-confirm` field so the resubmission isn't caught by the IP check again, and calls `wpcf7.submit(form)` on the same form. The document-level listener also covers forms injected later by AJAX.

**IP and keyword blocking** run server-side on the `wpcf7_validate` filter and use `$result->invalidate()`, which is what actually stops the mail. The error is attached to the first form tag that has a name, because CF7 silently discards errors attached to an unnamed tag such as `[submit]`.

That invalidation is then hidden from the visitor. A `wpcf7_feedback_response` filter empties `invalid_fields` and `message` and adds a `cf7_ip_restrict` key naming the reason (`ip` or `keyword`), so:

- no red text is added under any field, and no `wpcf7-not-valid` class or `aria-invalid` is set;
- CF7's response bar receives no text, and `.wpcf7-response-output:empty` hides the empty bordered box it would otherwise draw;
- the modal is the only feedback the visitor gets, and it reads the reason key rather than matching English message text.

The filter returns the response untouched unless this plugin was the thing that rejected the submission, so other plugins' validation errors are unaffected.

**Blocking has no time limit.** IP and keyword blocks hold no state and no expiry — every submission is re-checked against the current settings, so a block lasts exactly as long as the entry stays in the list. Removing an IP from the list unblocks it on the very next submission. The **Repeat Window** setting applies only to the repeat-submission prompt and has nothing to do with these two rules.

## Known limitations

- **Repeat submission is a courtesy, not enforcement.** Both the cookie and the IP record are checked before the mail sends, but *Submit Again* always lets the visitor through, and its confirm field is just a hidden form value — nothing stops a script from sending it on every request. This is inherent to a dismissible prompt: it can inconvenience a bot, not stop one. Use a CAPTCHA if spam is the actual problem.
- **The modal wording lives in the JavaScript** and is not translatable as written. The server-side message strings are now internal only — the front end keys off `ip` / `keyword` / `repeat` — so rewording either side is safe.
- **A block hides other validation errors on the same submission.** If a visitor is IP-blocked and also left a required field empty, only the modal shows. They are blocked regardless, so the empty field is moot.
- **A blocked IP still needs to be the address the server sees.** On a local install every request arrives from `127.0.0.1` or `::1`, so a public IP looked up externally will never match. Behind a proxy or CDN, see the opt-in constant above.
- **Keywords cannot contain commas or newlines** — those are the list separators.
- **Keywords match inside longer words.** This is deliberate so that `hello` catches `hello123@gmail.com`, but it also means `ass` flags `Cassandra` and `sex` flags `Sussex`. Choose keywords with that in mind — prefer longer, more specific strings.
- **Uploaded file names are not scanned.** `get_posted_data()` does not include `$_FILES`, so a keyword in an attachment's filename does not block the submission.
- **The IP fallback is per connection, not per person.** A shared IP — an office, a mobile carrier, a household — means one person's submission can prompt everyone behind it. A VPN or a mobile network change gives a genuinely new IP and starts fresh.
- **Behind a proxy or CDN without `CF7_IP_RESTRICT_TRUST_PROXY` set, the IP fallback can't tell visitors apart** — see the proxy section above. The cookie still works normally in that case.
- **The Logged-in Users toggle has no role exceptions.** Turning it on applies the rules to every logged-in user including administrators, so a blocked IP blocks your own account too.

## Changelog

### 2.2.0

**Added**

- **Repeat Submissions** toggle to turn the repeat-submission prompt on or off from the admin.
- **Repeat Window** controls — an amount plus a Seconds/Minutes unit for how long the prompt lasts, replacing the hardcoded value in the JavaScript. `0` keeps it until the browser closes; values are capped at 30 days. They sit on the right of the toggle's own row and hide when the toggle is off.
- **Logged-in Users** toggle on the settings page. Previously logged-in users were always exempt with no way to change it; the exemption is now opt-out.
- The repeat-submission prompt now also triggers by matching the visitor's IP server-side, catching a different browser or device on the same connection. The cookie stays as the instant, no-request path; the IP check is the fallback for when it isn't present.

**Changed**

- The settings page moved from its own top-level admin menu to a submenu under **Contact**, alongside Contact Form 7's own screens. The page slug is unchanged, so existing links and the Plugins-row *Settings* link still work.
- The repeat-submission prompt no longer works by rejecting the form. It is now a pre-submit dialog driven by a cookie, so CF7's red inline error no longer appears next to the modal. The modal's markup, wording, and styling are unchanged.
- The prompt is site-wide: a submission on any page arms it for every form on every page.
- Keyword matching is a case-insensitive substring search across every field, so a keyword joined to other characters still blocks — `hello` catches `hello123@gmail.com`, `nr.abchello@abc.com` and `abc@hello.com`.
- IP and keyword lists accept newline-separated input as well as commas.
- Invalid IP entries are reported in an admin notice instead of disappearing silently.

**Fixed**

- IP and keyword blocks no longer add red error text to a form field. CF7 anchors a field error to a specific input, so the message appeared under Name or Email regardless of which field actually triggered the block, inviting visitors to edit the wrong field.
- The **Repeat Window** value is now clamped to the 30 day maximum **on save**, with an admin notice. Previously an out-of-range entry such as `999999` was stored and redisplayed verbatim while the front end quietly used 30 days, so the settings screen showed a window that was not in force.
- The front end no longer identifies blocks by matching English message text, so rewording a message can no longer stop the modal appearing.
- Blocklist entries are compared as canonical addresses rather than strings, so the same host written differently still matches — `2001:db8::1` now blocks a request arriving as `2001:0db8:0000:0000:0000:0000:0000:0001`, and `::ffff:203.0.113.9` and `203.0.113.9` are treated as one address.
- Keyword scanning now covers array field values. Checkbox, radio and multi-select answers were skipped by an `is_string()` guard, so a keyword chosen from a dropdown never blocked anything.
- Saving the settings page wiped the IP blocklist when entries were newline-separated. `sanitize_text_field()` collapsed newlines to spaces, so no entry validated as an IP and the option saved empty.
- A trailing or doubled comma in Blocked Keywords produced an empty keyword, and `stripos($value, "")` returns `0` — so **every** submission on the site was rejected as inappropriate.
- Blocks silently did nothing when a form's first tag had no name; CF7 discards errors attached to such a tag, so the submission went through and the mail was sent.
- Every visitor shared one identity behind a proxy. Added opt-in `CF7_IP_RESTRICT_TRUST_PROXY` support.
- *Submit Again* did nothing when the old 5-minute block had already expired on its own — `delete_transient()` returned `false` and the JS only logged to the console.
- On a page with more than one form, *Submit Again* resubmitted the first form in the document rather than the one the visitor was using.
- "Settings saved successfully" was printed on every admin screen carrying `?settings-updated`, including other plugins' settings pages.

**Removed**

- The 5-minute `block_ip_<ip>` transient, the `unblock_ip` AJAX endpoint, and the `wpcf7_before_send_mail` hook. The endpoint had no nonce, so any client could clear its own block with a single POST — it was never real protection.
- A duplicate `admin_menu` registration, a dead `wp_die()` after `wp_send_json_*`, an unused `WPCF7_Submission` guard, the unnecessary jQuery dependency, and the now-unused `wp_localize_script` call.

### 2.1.0

Initial version: 5-minute per-IP rate limit via transient, permanent IP blocklist, substring keyword blocking, modal driven by the `wpcf7invalid` event.

## License

GPL-2.0-or-later, as required for WordPress plugins.
