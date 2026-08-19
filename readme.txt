=== Lead Forms ===
Contributors: v4vikram, codevani
Donate link: https://codevani.com
Tags: contact form, lead form, enquiry, form builder, leads
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Dynamic contact and enquiry forms with a field builder, stored leads and multi-recipient e-mail notifications.

== Description ==

Build a form by adding fields in the admin, drop it on a page with a shortcode
or block, and every submission is both saved to the database and e-mailed to
as many addresses as you like.

**Features**

* Field builder — text, e-mail, phone, URL, number, date, message, dropdown,
  radio, checkboxes and a consent checkbox. Reorder, mark required, and set
  half or full width.
* Per-field validation — min/max characters, numeric and date ranges, how many
  checkboxes may be ticked, a format rule (letters only, digits only, Indian
  mobile, or your own pattern) and a custom error message.
* Send each lead to several recipients, plus Cc and Bcc.
* Merge tags in the subject line: `{site_name}`, `{form_title}`, `{date}`,
  `{time}`, and one for every field key, e.g. `{name}`.
* Optional auto-reply to the person who submitted.
* Leads screen with search, per-status views, bulk actions and CSV export.
* Anti-spam without a CAPTCHA: honeypot, a signed minimum-time check, and a
  per-visitor hourly rate limit.
* Works without JavaScript. The form posts normally and re-renders with inline
  errors; the script only upgrades it to a no-reload submit.

== Installation ==

1. Copy the `lead-forms` folder into `wp-content/plugins/`.
2. Activate **Lead Forms** in Plugins.
3. Go to **Lead Forms → Add Form**, add your fields, and set the recipient
   addresses under *E-mail notifications*.
4. Copy the shortcode from the sidebar onto any page, or insert the
   **Lead Form** block.

A starter form called "Book Your Visit" is created on first activation.

== Frequently Asked Questions ==

= How do I send leads to two or three addresses? =

In the form editor, under *E-mail notifications → Send to*, enter the
addresses separated by commas or on separate lines. Anything that is not a
valid address is dropped when you save.

= The notification e-mail never arrives. =

`wp_mail()` uses PHP's `mail()` by default, which most hosts either block or
have flagged as spam. Install an SMTP plugin and send through a real mailbox
or a transactional provider. Every lead is also stored under **Lead Forms →
Leads**, so nothing is lost while you sort that out.

= Should I put the visitor's address in the "From" field? =

No. Mail sent as somebody else's domain fails SPF and DKIM and lands in spam.
Leave *From* blank, or use an address on your own domain — the plugin already
sets the visitor's address as `Reply-To`, so hitting Reply reaches them.

= What validation can I set per field? =

Open a field in the builder and use the **Validation** panel. What appears
depends on the field type:

* Text, message, e-mail, phone, URL — min and max characters.
* Number and date — lowest and highest allowed value.
* Checkboxes — min and max number of choices.
* Text and phone — a **Format** rule: letters only, letters and numbers,
  digits only, Indian mobile (10 digits starting 6-9), or a custom pattern.
* Any field — a custom error message replacing the built-in wording.

Phone format rules match the digits only, so `98765 43210` and `(98765) 43210`
both pass a 10-digit rule. Every rule is mirrored as an HTML attribute so the
browser catches the mistake first, and re-checked on the server, which is what
actually enforces it.

= My custom pattern disappeared when I saved. =

It did not compile. Patterns are written as a regular expression without
delimiters, for example `^[A-Z]{2}[0-9]{4}$`. Anything PCRE rejects is dropped
on save rather than left as a rule no visitor could ever satisfy.

= Where does the field key come from? =

It is generated from the label as you type and is what merge tags and stored
leads use. You can edit it, but changing it on a live form means leads that
were already stored keep the old key.

= Is the visitor's IP address stored? =

Only as a salted hash, which is used for rate limiting. The raw address is
never written to the database.

= How do I keep my data when deleting the plugin? =

Deleting the plugin removes its forms, leads and options. To keep them, add
`define( 'LEAD_FORMS_KEEP_DATA', true );` to `wp-config.php` first.

== Hooks ==

Filters:

* `lead_forms_capability` — capability required for the admin screens.
* `lead_forms_field_types` — register additional field types.
* `lead_forms_validation_errors` — add custom validation rules.
* `lead_forms_spam_check` — plug in Akismet, a CAPTCHA, or your own check.
* `lead_forms_notification_email` — alter the notification before it is sent.
* `lead_forms_ip_server_keys` — trust a proxy header for the client IP.
* `lead_forms_load_form` — alter a form after it is loaded.

Actions:

* `lead_forms_submission_created` — after a valid submission is stored.
* `lead_forms_spam_rejected` — when a submission is rejected as spam.
* `lead_forms_booted` — after all services are wired up.

== Changelog ==

= 1.0.0 =
* Initial release.
