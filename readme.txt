=== Digicyfr Service Quote Desk PL ===
Contributors: digicyfr
Tags: quote, service, inquiry, polish, lead
Requires at least: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Collect service quote requests and manage follow-up privately in WordPress. Polish and English text. No external account.

== Description ==

Add the Service Quote Request block or [digicyfr_service_quote] shortcode. Visitors provide a service, city, job description, name and email or phone. Administrators manage a private request inbox with statuses, assignees, follow-up dates, notes and CSV export. The plugin stores requests on the site; it does not process payments or reserve appointments.

Configure notification email, service choices, retention period and a link to your privacy notice in Quote Desk > Settings. Requests older than the configured period are deleted by WordPress cron, including open requests. The default is 180 days. Email accepted by WordPress does not guarantee inbox delivery; the request remains in the private dashboard.

The plugin integrates with WordPress personal data export and erase for requests with an email address. Administrators can remove individual phone-only requests in the request detail. Review your own privacy notice and retention needs before publishing the form.

No telemetry, external account, remote script, or Digicyfr server is used. Deactivation and uninstall retain requests and settings to prevent accidental data loss. Remove data from WordPress before uninstall when needed.

== Installation ==

1. Upload the versioned ZIP in Plugins > Add New > Upload Plugin.
2. Activate and open Quote Desk > Settings.
3. Add the block or shortcode to a page. Test an inquiry and the notification email.

== Frequently Asked Questions ==

= Does it replace a booking system? =

No. Preferred callback time is a request, not a reserved slot.

= Does it send a quote automatically? =

No. Staff contact the customer and update the status manually.

== Changelog ==

= 0.1.0 =
* Initial request form, private workflow, CSV, retention and privacy hooks.
