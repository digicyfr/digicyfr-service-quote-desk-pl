# Service Quote Desk PL 0.1.0 QA

Date: 5 November 2026. This record covers the exact, allowlisted `digicyfr-service-quote-desk-pl-0.1.0.zip` with SHA-256 `e83c726343e1750b0893632d4f2fbe8b0e3d06f07c041830bae3e1e098296ef0`. Its 10 packaged files were compared byte for byte with source by `scripts/release.py`; the sidecar passed `sha256sum -c` locally. Development tests and scripts are excluded from the public ZIP.

## Automated verification

- PHP 8.3 syntax and JavaScript syntax passed. WordPress Coding Standards reported zero errors and 54 review warnings. The warnings comprise direct access to this plugin's private custom table without caching personal data, read-only admin query parameters, one `php://output` close, the required but unused privacy eraser page argument, and five assignment alignment notices. These warnings were reviewed; no insecure direct SQL interpolation is used in runtime code.
- The exact ZIP installed, activated, deactivated and reactivated on disposable WordPress 7.1/PHP 8.3.33. The custom table, public form, distinct IDs for multiple forms, Polish translation catalog, nonce, honeypot, required contact, invalid email, same-host source URL, request persistence, status and assignment update, follow-up date, retention, email-based personal data export and erase, phone-only manual deletion all passed.
- Headless Chromium submitted the public form at 390 px, rejected a missing contact, showed the success state without horizontal overflow, and let an administrator open the saved request and update its status and note.
- axe-core 4.10.3 found zero violations in the plugin-owned public form and request detail screen. Each scoped scan had one incomplete check requiring human review. Keyboard focus on the service field was verified. These checks do not establish WCAG conformance or replace a screen-reader pass.
- The disposable test site had no sendmail service. The request remained saved and showed a failed notification status, as designed. Delivery through a configured real mail provider remains a deployment check.

## Deployment checks and limits

Set the recipient, service choices, privacy notice and retention period for the business before publishing. Send a real request and confirm mail delivery independently of WordPress mail acceptance. The plugin does not send follow-up reminders or automatically calculate a price. WordPress cron timing depends on traffic or host scheduling. Requests with only a phone number require administrator deletion because the core privacy tools identify data by email.

A representative Polish service business staging flow, human screen-reader review, multisite behavior, very large request volumes, and other PHP/WordPress combinations remain untested. No claim of legal compliance or universal host compatibility is made.
