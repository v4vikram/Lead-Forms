# WordPress.org listing assets

These files are **not** part of the plugin. They are synced into the SVN
`assets/` directory by the `wordpress-org-assets` job in
[`../.github/workflows/release.yml`](../.github/workflows/release.yml), and
they are what people see on the plugin's directory page before installing.

`.distignore` and `.gitattributes` both exclude this folder, so nothing here
ends up in the installable ZIP.

## What to add

| File | Size | Shown |
|---|---|---|
| `icon-128x128.png` | 128 × 128 | Search results, plugin cards |
| `icon-256x256.png` | 256 × 256 | Same, on high-DPI screens |
| `banner-772x250.png` | 772 × 250 | Header of the plugin page |
| `banner-1544x500.png` | 1544 × 500 | Same, on high-DPI screens |
| `screenshot-1.png` | any, keep consistent | Screenshots tab |
| `screenshot-2.png` | … | … |

`.jpg` works too. An `icon.svg` may be supplied instead of the two PNGs, but
the 128px PNG should still be present as a fallback.

## Screenshots

Each `screenshot-N.png` is captioned by the Nth line under `== Screenshots ==`
in `readme.txt`, so the numbering has to line up. That section does not exist
yet — add it alongside the first screenshot, for example:

```
== Screenshots ==

1. The form on the front end.
2. Building fields, with per-field validation.
3. The Leads inbox.
```

Good candidates for this plugin: the rendered form, the field builder with a
Validation panel open, the e-mail notification settings, and the Leads list.

## Design notes

Keep the left third of the banner clear of important detail — the plugin title
is overlaid there on some viewports. Avoid putting the WordPress logo or the
word "WordPress" in the icon; the directory guidelines disallow it.
