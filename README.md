# CF7 IP Restrict

A WordPress plugin that throttles and blocks [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) submissions by IP address and by keyword, with a modal shown to the visitor instead of an inline error.

- **Version:** 2.1.0
- **Author:** Ashu Tiwary
- **Requires:** WordPress, Contact Form 7 (active)

## What it does

| Rule | Behaviour |
| --- | --- |
| **Rate limit** | After a successful submission the visitor's IP is blocked for 5 minutes. They get a "Submit Again" option in the modal. |
| **Permanent block** | IPs listed in settings are blocked outright, with no way to retry. |
| **Keyword block** | Any submission whose field values contain a blocked keyword (case-insensitive substring match) is rejected. |

Logged-in users are exempt from all three — none of the front-end hooks are registered for them.

## Install

1. Copy the `cf7-iprestrict` folder into `wp-content/plugins/`.
2. Make sure Contact Form 7 is installed and **active** — activation is blocked otherwise.
3. Activate **CF7 IP Restrict** from the Plugins screen.

## Settings

**CF7 IP Restrict** in the admin sidebar, or the *Settings* link on the Plugins row.

- **Blocked IP Addresses** — comma-separated (`192.168.1.1, 10.0.0.2`). Invalid entries are silently dropped on save.
- **Blocked Keywords** — comma-separated (`spam, unauthorized`).

## How it works

| File | Role |
| --- | --- |
| `cf7-iprestrict.php` | Plugin header, CF7 dependency check, bootstrap |
| `includes/class-cf7-ip-restrict.php` | Hook registration |
| `admin/class-cf7-ip-restrict-admin.php` | Settings page (Settings API) |
| `public/class-cf7-ip-restrict-public.php` | Validation, transients, AJAX unblock, modal markup |
| `public/public-script.js` | Listens for `wpcf7invalid`, shows the modal |
| `public/public-style.css` | Modal styling |

The 5-minute block is a transient keyed `block_ip_<ip>`. Validation runs on the `wpcf7_validate` filter; the block is set on `wpcf7_before_send_mail`.

## Known limitations

- **The `unblock_ip` AJAX endpoint has no nonce check.** Anything that can POST to `admin-ajax.php` can clear its own 5-minute block, so the rate limit stops honest repeat submitters, not bots.
- **Blocking is by `REMOTE_ADDR` only.** Behind Cloudflare or any reverse proxy this is the proxy's IP, not the visitor's — every visitor shares one block.
- **PHP and JS match on exact English error strings.** Change the wording in one and the modal stops appearing; the plugin is not translatable as written.
- **IP and keyword lists must be comma-separated.** Newline-separated input is discarded on save.
- **Keywords match as substrings**, so `spam` also blocks `spammer` and `unspammed`.

## License

GPL-2.0-or-later, as required for WordPress plugins.
