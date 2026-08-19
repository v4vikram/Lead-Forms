# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.0.x   | Yes       |

## Reporting a vulnerability

Please **do not** open a public issue for security problems.

Report them privately through
[GitHub's private vulnerability reporting](https://github.com/v4vikram/Lead-Forms/security/advisories/new),
or by e-mail to the address listed on [codevani.com](https://codevani.com).

Please include:

- the plugin version and the WordPress and PHP versions,
- steps to reproduce, and
- what an attacker could achieve.

You can expect an acknowledgement within a few days. Once a fix is ready it
will ship in a patch release, and you will be credited in the changelog unless
you would rather not be.

## Scope

This plugin accepts input from unauthenticated visitors and stores it, so the
areas most worth scrutiny are:

- the submission path (`src/Submission/`), including nonce handling, the spam
  guard, and validation of choice fields against their definitions,
- database access in `src/Leads/LeadRepository.php`,
- output escaping in `src/Frontend/FormRenderer.php` and the admin screens,
- the CSV export in `src/Admin/LeadsPage.php`.

Note that an administrator can already run arbitrary code on a WordPress site,
so issues that require administrator privileges are generally not treated as
vulnerabilities.
