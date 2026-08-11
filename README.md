# CF7 IP Restrict

A WordPress plugin that warns [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) visitors before a repeat submission, and blocks submissions by IP address and by keyword. All three surface in a modal instead of CF7's inline error text.

- **Version:** 2.2.0
- **Author:** Ashu Tiwary
- **Requires:** WordPress, Contact Form 7 (active)

## What it does

| Rule | Behaviour | Where it runs |
| --- | --- | --- |
| **Repeat submission** | Once a visitor has submitted any form, the next submit attempt on **any page** opens a modal: *"You Already Submitted Form. Do you want to Submit Again?"* — **Submit Again** sends it, **Close** cancels and leaves the typed input alone. Can be switched off, and the window is configurable. | Browser only |
| **Permanent block** | IPs listed in settings are rejected outright. The modal appears with the *Submit Again* button hidden. | Server |
| **Keyword block** | Any submission whose field values contain a blocked keyword (whole word, case-insensitive) is rejected, no retry offered. | Server |

Logged-in users are exempt from all three by default — none of the front-end hooks are registered for them, so test in a private window. Flip the **Logged-in Users** toggle in settings to apply the rules to them as well.

## Install

1. Copy the `cf7-iprestrict` folder into `wp-content/plugins/`.
2. Make sure Contact Form 7 is installed and **active** — activation is blocked otherwise.
3. Activate **CF7 IP Restrict** from the Plugins screen.

## Settings

**CF7 IP Restrict** in the admin sidebar, or the *Settings* link on the Plugins row.

- **Repeat Submissions** — on by default. Off, visitors can submit as often as they like with no prompt; IP and keyword blocking are unaffected. On the right of the same row sits the window: a number plus **Seconds** or **Minutes**, for how long after a submission the prompt keeps appearing. **0** keeps it until the browser is closed, and values are capped at 30 days. The window controls are hidden while the toggle is off.
- **Logged-in Users** — off by default. On, every rule below also applies to logged-in users, including administrators. Leave it off while you are testing forms from your own account.
- **Blocked IP Addresses** — one per line or comma-separated. Entries that are not valid IPs are dropped on save and named in an admin notice.
- **Blocked Keywords** — one per line or comma-separated. Matched as whole words, so `spam` does not flag `spammer`. Keywords that begin or end with punctuation match on that side without a word boundary, so `.ru`, `$$$`, `bit.ly` and `c++` all work as written.

### Behind a proxy or CDN

`REMOTE_ADDR` is the proxy's IP behind Cloudflare, a load balancer, or any reverse proxy, which would make every visitor share one identity. To read the real client IP, opt in explicitly in `wp-config.php`:

```php
define('CF7_IP_RESTRICT_TRUST_PROXY', true);
```

`CF-Connecting-IP` then `X-Forwarded-For` are used, first hop only. This is **off by default on purpose** — trusting those headers unconditionally would let any visitor spoof one and walk past the blocklist.

### Repeat-submission window

The prompt is driven by a `cf7_already_submitted` cookie whose lifetime comes from the **Repeat Window** setting. With the default of `0` it is a session cookie, so the prompt resets when the browser closes. The amount and unit are passed to the front end via `wp_localize_script` as `cf7IpRestrict`.

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

**Repeat submission** never reaches the server. A capture-phase `submit` listener on `document` runs before CF7's own handler, so when the cookie is present the submission is stopped with `preventDefault()` before anything is posted — no request, no validation error, nothing to suppress. *Submit Again* clears the cookie and calls `wpcf7.submit(form)` on the same form. The cookie is set on CF7's `wpcf7mailsent` event, which re-arms the prompt for next time.

Because the state lives in a cookie rather than a server transient, page caching can't serve a stale answer, and there's no round trip before the modal opens. The document-level listener also covers forms injected later by AJAX.

**IP and keyword blocking** still run server-side on the `wpcf7_validate` filter and use `$result->invalidate()`. The error is attached to the first form tag that actually has a name, because CF7 silently discards errors attached to an unnamed tag such as `[submit]`.

## Known limitations

- **There is no server-side throttle.** The repeat-submission prompt is a browser-side courtesy; anything ignoring cookies and JavaScript submits freely. This is a deliberate consequence of the feature — the prompt has to be dismissible, so it cannot also be enforcement. Use a CAPTCHA if spam is the actual problem.
- **PHP and JS match on exact English strings** for the two server-side blocks. Change the wording in one place and the modal stops appearing; the plugin is not translatable as written. The misspelling in `"Your submission contains inapropriate words"` is load-bearing for the same reason.
- **IPv6 addresses must be written exactly as they arrive.** `::1` in the blocklist will not match a request arriving as `0:0:0:0:0:0:0:1`.
- **Keywords cannot contain commas or newlines** — those are the list separators.
- **Only string field values are scanned** for keywords. Array values from checkboxes and multi-selects are skipped.
- **The prompt is per browser, not per person.** A new browser, a private window, or cleared cookies starts fresh.
- **The Logged-in Users toggle has no role exceptions.** Turning it on applies the rules to every logged-in user including administrators, so a blocked IP blocks your own account too.

## Changelog

### 2.2.0

**Added**

- **Repeat Submissions** toggle to turn the repeat-submission prompt on or off from the admin.
- **Repeat Window** controls — an amount plus a Seconds/Minutes unit for how long the prompt lasts, replacing the hardcoded value in the JavaScript. `0` keeps it until the browser closes; values are capped at 30 days. They sit on the right of the toggle's own row and hide when the toggle is off.
- **Logged-in Users** toggle on the settings page. Previously logged-in users were always exempt with no way to change it; the exemption is now opt-out.

**Changed**

- The repeat-submission prompt no longer works by rejecting the form. It is now a pre-submit dialog driven by a cookie, so CF7's red inline error no longer appears next to the modal. The modal's markup, wording, and styling are unchanged.
- The prompt is site-wide: a submission on any page arms it for every form on every page.
- Keyword matching is whole-word instead of substring, so `ass` no longer flags `Cassandra` and `sex` no longer flags `Sussex`. Word boundaries are applied only where the keyword edge is a word character, so punctuated keywords such as `.ru`, `$$$` and `c++` still match.
- IP and keyword lists accept newline-separated input as well as commas.
- Invalid IP entries are reported in an admin notice instead of disappearing silently.

**Fixed**

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
