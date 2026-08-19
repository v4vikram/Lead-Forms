# Lead Forms

[![CI](https://github.com/v4vikram/Lead-Forms/actions/workflows/ci.yml/badge.svg)](https://github.com/v4vikram/Lead-Forms/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net)

Dynamic contact and enquiry forms for WordPress. Build a form by adding
fields in the admin, embed it with a shortcode or block, and every submission
is stored as a lead **and** e-mailed to as many recipients as you like.

Built without a bundler — no `npm run build` step, no compiled assets in the
repository. Clone it into `wp-content/plugins/` and activate.

---

## Features

- **Field builder** — text, e-mail, phone, URL, number, date, message,
  dropdown, radio, checkboxes and a consent checkbox. Reorder rows, mark
  fields required, and lay them out half or full width.
- **Per-field validation** — min/max characters, numeric and date ranges,
  how many checkboxes may be ticked, a format rule (letters only, digits only,
  Indian mobile, or your own regex) and a custom error message.
- **Multiple recipients** — send each lead to several addresses, plus Cc and
  Bcc. `Reply-To` is set to the visitor so hitting Reply reaches them.
- **Merge tags** — `{site_name}`, `{form_title}`, `{date}`, `{time}` and one
  per field key, usable in the subject line and auto-reply.
- **Lead inbox** — search, per-status views, bulk actions and CSV export.
- **Anti-spam without a CAPTCHA** — honeypot, an HMAC-signed minimum-time
  check, and a per-visitor hourly rate limit.
- **Works without JavaScript** — the form is a real `<form>` posting to
  `admin-post.php`; the script only upgrades it to a no-reload submit.

## Requirements

| | |
|---|---|
| WordPress | 6.4 or newer |
| PHP | 7.4 or newer |

## Installation

```bash
cd wp-content/plugins
git clone https://github.com/v4vikram/Lead-Forms.git lead-forms
```

Then activate **Lead Forms** in the Plugins screen. A starter form called
"Book Your Visit" is created on first activation.

## Usage

Copy the shortcode from the form editor sidebar:

```
[lead_form id="12"]
```

Or insert the **Lead Form** block and pick a form from the dropdown.

## Architecture

```
lead-forms.php              Bootstrap: headers, autoloader, activation hooks
src/
  Plugin.php                Service container — wires everything together
  Installer.php             dbDelta schema, migrations, seed data
  Forms/                    Form, Field, FormSettings, FieldRegistry, repository
  Frontend/                 Renderer, shortcode, block, asset loading
  Submission/               Validator, SpamGuard, REST + no-JS handlers
  Leads/                    Lead model and $wpdb repository
  Mail/                     Notifier and merge tags
  Admin/                    Field builder, settings boxes, leads list table
assets/                     Unbundled CSS and JS
blocks/lead-form/           block.json + editor script
```

Both entry points — the REST endpoint and the no-JavaScript POST — funnel
through a single `SubmissionHandler`, so validation and security checks cannot
drift apart between them.

### Security notes

- Every query goes through `$wpdb->prepare()`; `ORDER BY` is whitelisted.
- Output is escaped at the point of printing; choice values are validated
  against the stored field definition, so a tampered request cannot inject
  options that were never offered.
- Nonces guard both entry points, with a `/token` endpoint handing out a fresh
  one so full-page caching cannot serve an expired nonce.
- Visitor IPs are stored only as a salted hash, used for rate limiting.
- CSV exports neutralise spreadsheet formula injection.

## Hooks

**Filters**

| Hook | Purpose |
|---|---|
| `lead_forms_capability` | Capability required for the admin screens |
| `lead_forms_field_types` | Register additional field types |
| `lead_forms_field_patterns` | Add format presets to the builder |
| `lead_forms_validation_errors` | Add custom validation rules |
| `lead_forms_spam_check` | Plug in Akismet, a CAPTCHA, or your own check |
| `lead_forms_notification_email` | Alter the notification before it is sent |
| `lead_forms_ip_server_keys` | Trust a proxy header for the client IP |
| `lead_forms_load_form` | Alter a form after it is loaded |

**Actions**

| Hook | Purpose |
|---|---|
| `lead_forms_submission_created` | After a valid submission is stored |
| `lead_forms_spam_rejected` | When a submission is rejected as spam |
| `lead_forms_booted` | After all services are wired up |

### Example

```php
// Require a company e-mail address on form 12.
add_filter( 'lead_forms_validation_errors', function ( $errors, $values, $form ) {
	if ( 12 !== $form->id() ) {
		return $errors;
	}

	$email = $values['email']['value'] ?? '';

	if ( $email && ! str_ends_with( $email, '@acme.com' ) ) {
		$errors['email'] = 'Please use your Acme address.';
	}

	return $errors;
}, 10, 3 );
```

## Development

```bash
composer install     # PHPCS + WordPress Coding Standards
composer run lint    # check
composer run lint:fix # auto-fix what can be fixed
composer run lint:php # plain PHP syntax check
```

CI runs the syntax check on PHP 7.4–8.3, PHPCS against WPCS, and a JavaScript
syntax check on every push and pull request.

## Contributing

Bug reports and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[GPL-2.0-or-later](LICENSE) — the same license as WordPress itself.

Copyright © 2026 [Vikram](https://github.com/v4vikram) · [Codevani](https://codevani.com)
