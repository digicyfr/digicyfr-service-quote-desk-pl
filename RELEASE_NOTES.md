# Digicyfr Service Quote Desk PL 0.1.0

First self-hosted GPL-2.0-or-later release. Install the attached ZIP through WordPress. The `release/0.1.0` branch and `v0.1.0` tag record its source. Verify the ZIP against the attached SHA-256 sidecar before installation.

## Included

A public service quote form as a block or shortcode; a private administrator inbox with status, assignee, follow-up date and notes; CSV export; configurable service choices, notification recipient and retention; and WordPress personal data export/erase for requests with email addresses. English and Polish strings are bundled. No paid service or other Digicyfr plugin is required.

## Operating limits

The form does not calculate a price, book an appointment or process a payment. WordPress mail acceptance does not prove delivery, so review the inbox and test the site's mail service. Follow-up dates are visible in the inbox but send no automatic reminder. WordPress cron may run later than its scheduled time. Phone-only requests require manual deletion in the inbox. Deactivation and uninstall preserve stored requests.

The unique `Update URI` prevents a same-slug WordPress.org plugin from replacing this self-hosted plugin; it does not provide automatic updates. WordPress.org distribution needs separate review and channel-specific changes. See [QA record](docs/QA-0.1.0.md).
