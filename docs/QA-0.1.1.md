# Service Quote Desk PL 0.1.1 QA

Date: 5 November 2026. This record covers the exact, allowlisted `digicyfr-service-quote-desk-pl-0.1.1.zip` with SHA-256 `831d823368715ebaefb4c043f6ce3aa6629b4b8db8bc09b0f9b8856a01eac5ba`. Its 10 packaged files were compared byte for byte with source by `scripts/release.py`; the sidecar passed `sha256sum -c`. Development tests and the repository screenshot are excluded from the public ZIP. The earlier v0.1.0 release remains unchanged.

## Automated verification

- PHP 8.3 and JavaScript syntax passed. WordPress Coding Standards reported zero errors and 53 review warnings. The warnings concern direct access to this plugin's private custom table without caching personal data, read-only admin query parameters, the required but unused privacy eraser page argument, and five assignment alignment notices. Runtime database queries use WordPress placeholders or CRUD methods.
- WordPress.org Plugin Check on the exact ZIP reported `plugin_updater_detected` for the unique `Update URI`, which is disallowed for WordPress.org-hosted plugins but intentional for this self-hosted release. It also reported 48 review warnings for direct custom-table queries, read-only parameters and bundled translation loading. It no longer reports the missing readme header or CSV stream close findings that prompted v0.1.1. WordPress.org submission would need separate review and distribution-specific changes.
- The exact ZIP installed, activated, deactivated and reactivated on disposable WordPress 7.1/PHP 8.3.33. The custom table, public form, distinct IDs for multiple forms, Polish translation catalog, nonce, honeypot, required contact, invalid email, same-host source URL, request persistence, status and assignment update, follow-up date, retention, email-based personal data export and erase, and phone-only manual deletion passed.
- CSV export produced a parseable file and neutralized a formula-like customer name before spreadsheet import.
- Headless Chromium submitted the public form at 390 px, rejected a missing contact, showed the success state without horizontal overflow, and let an administrator open the saved request and update its status and note.
- axe-core 4.10.3 found zero violations in the plugin-owned public form and request detail screen. Each scoped scan had one incomplete check requiring human review. Keyboard focus on the service field was verified. These checks do not establish WCAG conformance or replace a screen-reader pass.
- The disposable test site had no sendmail service. The request remained saved and showed a failed notification status, as designed. Delivery through a configured real mail provider remains a deployment check.

## Deployment checks and limits

Set the recipient, service choices, privacy notice and retention period for the business before publishing. Send a real request and confirm mail delivery independently of WordPress mail acceptance. The plugin does not send follow-up reminders or automatically calculate a price. WordPress cron timing depends on traffic or host scheduling. Requests with only a phone number require administrator deletion because the core privacy tools identify data by email.

A representative Polish service business staging flow, human screen-reader review, multisite behavior, very large request volumes, and other PHP/WordPress combinations remain untested. No claim of legal compliance or universal host compatibility is made.
