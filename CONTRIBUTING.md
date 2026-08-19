# Contributing

Thanks for taking the time to help improve Lead Forms.

## Getting set up

The plugin has no build step. Clone it straight into a WordPress install:

```bash
cd wp-content/plugins
git clone https://github.com/v4vikram/Lead-Forms.git lead-forms
cd lead-forms
composer install
```

The target directory matters: WordPress loads translations from the plugin
folder, so keep it named `lead-forms` rather than the repository's own casing.

`composer install` only pulls in the linting toolchain — the plugin itself has
no runtime dependencies and ships without a `vendor/` directory.

## Before opening a pull request

```bash
composer run lint:php   # PHP syntax across the tree
composer run lint       # WordPress Coding Standards
```

CI runs the same checks on PHP 7.4 through 8.3, so a clean local run should
mean a green build.

## Coding standards

- Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/).
  `composer run lint:fix` handles most formatting automatically.
- Classes are PSR-4 under `src/`, one class per file, namespaced `LeadForms\`.
- Sanitise on input, escape on output, and prepare every query.
- Every new user-facing string needs the `lead-forms` text domain.
- New public hooks should carry a docblock explaining their parameters.

## Commit messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add a date range rule to the field builder
fix: stop the nonce expiring on cached pages
docs: document the spam check filter
```

## Reporting bugs

Open an issue with the WordPress and PHP versions, the steps to reproduce, and
what you expected instead. If it involves a specific form, the field types it
uses are usually the important part.

For anything security related, please read [SECURITY.md](SECURITY.md) instead
of opening a public issue.
