# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-08-19

### Added

- Field builder with eleven field types: text, e-mail, phone, URL, number,
  date, message, dropdown, radio, checkboxes and a consent checkbox.
- Per-field validation: min/max characters, numeric and date ranges, checkbox
  selection counts, format presets (letters, letters and numbers, digits,
  Indian mobile) or a custom regular expression, plus a custom error message.
  Rules are mirrored as HTML constraint attributes and re-checked server-side.
- Multi-recipient e-mail notifications with Cc, Bcc, a configurable subject
  supporting merge tags, and an optional auto-reply to the submitter.
- Lead storage in a dedicated table, with an admin inbox offering search,
  per-status views, bulk actions, a detail view and CSV export.
- `[lead_form]` shortcode and a server-rendered **Lead Form** block.
- Anti-spam: honeypot field, HMAC-signed minimum-submit-time check, and a
  per-visitor hourly rate limit.
- REST submission endpoint with a companion `/token` route that issues a fresh
  nonce, so full-page caching cannot serve an expired one.
- Full no-JavaScript fallback posting to `admin-post.php`, sharing one
  submission handler with the REST route.
- Uninstall routine that removes all plugin data, with a
  `LEAD_FORMS_KEEP_DATA` constant to opt out.

### Security

- All database access goes through `$wpdb->prepare()`, with `ORDER BY` values
  whitelisted rather than interpolated.
- Submitted choices are validated against the stored field definition, so a
  tampered request cannot inject options that were never offered.
- Visitor IP addresses are stored only as a salted hash.
- CSV exports neutralise spreadsheet formula injection.
- Custom validation patterns are compile-checked on save and always run with
  fixed delimiters and modifiers.

[Unreleased]: https://github.com/v4vikram/Lead-Forms/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/v4vikram/Lead-Forms/releases/tag/v1.0.0
