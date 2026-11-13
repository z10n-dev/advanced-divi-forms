# Changelog

All notable changes to Advanced Divi Forms. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the plugin uses
`MAJOR.MINOR.PATCH` release tags (`vX.Y.Z`) that must match the plugin header and
`ADF_PLUGIN_VERSION`.

## [Unreleased]

## [0.13.0]

### Added

- Bundled translations: `languages/advanced-divi-forms.pot`, a complete German
  catalogue (`-de_DE.po` plus the compiled `.mo`) and a `load_plugin_textdomain`
  call on `init`. The build ZIP now ships the `languages` directory, so the admin
  interface is available in German as well as English.
- `tests/i18n.php` fails when a template string has no German translation, when
  the catalogues disagree on the number of entries, or when a placeholder set
  changes during translation; it also loads the textdomain at runtime in de_DE.
- Multisite coverage: `tests/multisite.php`, `scripts/test-wordpress-multisite.sh`
  and a CI job prove per-site capabilities, per-site settings and caches, isolated
  answers, a network-wide uninstall pass and the opt-in content removal.
- Real SMTP delivery: `tests/smtp-sink.py` implements a local sink,
  `tests/mail-smtp.php` renders the Divi notification through WordPress's actual
  PHPMailer transport, and `scripts/test-mail-smtp.sh` runs both. The suite
  asserts subject, routing, Reply-To, the HTML body and the summary table on the
  wire.
- Accessibility audit: `tests/admin-accessibility.js` and
  `scripts/test-admin-accessibility.sh` run axe-core (WCAG A/AA plus best
  practice) against the Forms overview, answer list, Trash view, answer detail,
  Mail Templates, template editor, Privacy & Retention and Updates screens.
- CI jobs `multisite`, `mail-delivery` and `accessibility`; a release is published
  only after these and the compatibility matrix pass.
- `.github/workflows/divi-live.yml`, a manual, secret-gated run of
  `tests/divi-live.php` against a licensed Divi theme supplied as
  `ADF_DIVI_ZIP_BASE64`. It stays outside the release pipeline because the
  repository cannot ship or download Divi.

### Changed

- Compatibility matrix includes PHP 8.5 on the latest WordPress; the plugin is
  documented and tested for PHP 8.2–8.5.
- Extracted the selection counter, the per-row checkbox label and the view totals
  into variables so their strings carry real `translators:` comments instead of a
  PHPCS suppression. Rendered markup is unchanged.
- The template editor's CodeMirror tag colour is darkened (5.95:1 instead of
  4.08:1) so the syntax highlighting meets WCAG AA on its matching-tag
  background.

### Not verified

- A licensed Divi editing session still needs the gated workflow or a staging
  site; the vendor-free contract tests remain the only automated Divi evidence.
- The SMTP suite uses a local sink. A provider with TLS and authentication must
  still be tested once on staging.

## [0.12.0]

### Added

- Divi compatibility guard: the plugin now names the detected Divi theme and
  version, warns administrators on every admin screen when Divi 5.16 or newer is
  not active, and shows the same state on **Forms → Updates** instead of silently
  storing nothing.
- `uninstall.php` with a tested removal module: deleting the plugin removes its
  settings, updater state and caches, the daily retention event and the three
  dedicated capabilities from every role. Stored answers, form records and mail
  templates stay in place unless `ADF_DELETE_ALL_DATA_ON_UNINSTALL` is defined.
- CI compatibility job that replays the same tested release ZIP on WordPress 6.2,
  6.7 and the latest release (PHP 8.2) and on PHP 8.3/8.4 for the newer releases.
  A release is published only after validate and compatibility pass.
- `tests/divi-support.php`, `tests/submission-volume.php` and `tests/uninstall.php`
  plus README sections for requirements, support and uninstalling.

### Changed

- Volume suite verifies filter counts, the local-midnight date boundary, the
  exported row count and that a filtered list page does not fan out into per-row
  queries.

## [0.11.0]

### Added

- Opt-in retention policy (default off) with a bounded daily cleanup and a
  **Forms → Privacy & Retention** screen.
- WordPress privacy exporter and eraser with exact verified-address matching;
  ambiguous legacy rows are reported, never guessed.
- Dedicated submission capabilities (`adf_view_submissions`,
  `adf_manage_submissions`, `adf_export_submissions`) granted only to
  administrators.

## [0.10.0]

### Added

- Historical field-label snapshots (`_adf_field_labels`) for lists, detail views
  and Excel exports, rendered as `Label [field_id]` with an ID fallback.

## [0.9.0]

### Added

- Synthetic template preview and placeholder helpers with a sandboxed iframe, no
  mail delivery and no stored test data.

## [0.8.0]

### Added

- Recoverable submission trash with separate restore and confirmed permanent
  deletion, including export handling.

## [0.7.0]

### Added

- Search, read/unread and date filters with stable sorting and pagination, plus
  scoped Excel exports for selections and filtered results.

## [0.6.0]

### Added

- Reliable manual update check that bypasses the plugin cache, persisted
  diagnostics for the last attempt and clearer network/rate-limit errors.

## [0.5.1]

### Fixed

- Red destructive buttons, consistent spacing and aligned delete confirmations.

[Unreleased]: https://github.com/z10n-dev/advanced-divi-forms/compare/v0.12.0...main
[0.12.0]: https://github.com/z10n-dev/advanced-divi-forms/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/z10n-dev/advanced-divi-forms/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/z10n-dev/advanced-divi-forms/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/z10n-dev/advanced-divi-forms/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/z10n-dev/advanced-divi-forms/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/z10n-dev/advanced-divi-forms/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/z10n-dev/advanced-divi-forms/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/z10n-dev/advanced-divi-forms/compare/v0.5.0...v0.5.1
