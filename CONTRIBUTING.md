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

## Cutting a release

Releases are driven entirely by tags. Before tagging, the same version has to
appear in four places, or the `verify` job fails the build:

1. `Version:` in the `lead-forms.php` plugin header
2. the `VERSION` constant just below it
3. `Stable tag:` in `readme.txt`
4. a `## [x.y.z]` section in `CHANGELOG.md`

Then:

```bash
git tag -a v1.0.1 -m "Lead Forms 1.0.1"
git push origin v1.0.1
```

That builds the ZIP with `git archive`, checks no development files leaked
into it, and attaches it to a GitHub release. To rehearse without tagging,
run the **Release** workflow manually from the Actions tab and download the
artifact.

### Publishing to WordPress.org

The SVN jobs stay skipped until the plugin is approved. Once it is, add to the
repository settings:

- variable `WPORG_SLUG` — the slug the directory assigned (lowercase)
- secrets `WPORG_SVN_USERNAME` and `WPORG_SVN_PASSWORD` — your WordPress.org
  account, which must have commit access to the plugin

The next tag then pushes to SVN and syncs the listing artwork from
[`.wordpress-org/`](.wordpress-org/README.md). Setting the variable is the
switch; without it nothing is published.

## Reporting bugs

Open an issue with the WordPress and PHP versions, the steps to reproduce, and
what you expected instead. If it involves a specific form, the field types it
uses are usually the important part.

For anything security related, please read [SECURITY.md](SECURITY.md) instead
of opening a public issue.
