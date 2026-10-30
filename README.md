# Advanced Divi Forms

Administration, opt-in submission capture and HTML email templates for Divi 5
contact forms, with **GitHub Releases → native WordPress auto-updates**.
The integration is verified against the locally supplied **Divi 5.16.0** source.
Without a selected template, the native recipient email remains unchanged.

## Forms administration

Administrators with `manage_options` have a **Forms** menu:

- **All Forms**: registered forms, source pages, submission counts and last receipt.
  Clicking a form opens its paginated answers; clicking an answer shows all field
  values. Marking an answer read and permanently deleting it use nonce-protected
  POST requests. Deletion also requires explicit confirmation.
- **Mail Templates**: WordPress's native list, search, create, edit and trash UI.
  Each template has a stable post ID, internal title, subject and HTML content.
  Only published templates are available to the rendering API; drafts and trashed
  templates are not. Publish does not expose templates on the public site.

The HTML editor supports safe document wrappers, tables and inline CSS. Scripts,
style blocks, event handlers and unsafe markup are filtered on save. The saved
preview uses an isolated, script-free iframe with external requests blocked.
Placeholders such as `{{fields.name}}` refer to stable field IDs, not display
labels, and are case-insensitive. They work in the subject and HTML text content,
not tag attributes. Follow Divi's field ID convention: letters, numbers,
underscores and hyphens, without spaces or special characters.
Missing fields become empty strings; supplied values are treated as plain text
and escaped for HTML. PHP, shortcodes and arbitrary template expressions are not
executed. Template changes apply when the same ID is rendered again.

Use **`{{form.summary}}`** on its own line in the HTML body to insert a two-column
**Field / Value** table containing every validated submitted field, in submission
order. When Divi supplies a field label, it is used; otherwise the stable field ID
is shown. Empty submitted values remain visible, multiline values retain line
breaks, and multiselects are comma-separated. Fields excluded by conditional logic
and internal POST parameters (such as captcha/nonces) are not included.

```html
<h2>New enquiry from {{fields.name}}</h2>
{{form.summary}}
<p>End of enquiry.</p>
```

This works for both notification and sender confirmation templates, independently
of submission storage. Only place the summary where a table is valid HTML, not
inside a paragraph, heading or attribute. It is body-only: in the subject it becomes
empty text. Labels and values are escaped, and placeholders inside submitted data
are never evaluated. `{{fields.all}}` still refers to an ordinary field with ID
`all`; it is not an alias for the summary.

### Safe previews with synthetic values

In a draft or published Mail Template, enter invented values in **Synthetic field
values (JSON)**, using the actual Divi field IDs as keys. Example:

```json
{"name":"Ada Example","email":"ada@example.org","choices":["A","B"]}
```

The helper lists `{{fields.ID}}` tokens from these keys and `{{form.summary}}`;
it never imports real answers. Choose HTML body or Subject and click a token to
insert it at the cursor. The summary always goes into the HTML body. Use
**Render test-data preview** to render the current unsaved subject/HTML without
publishing, saving a submission or calling any email transport. Preview values
are not saved with the template. Do not use real personal data in test values.

Diagnostics distinguish absent test-data IDs, unsupported expressions, tokens
inside attributes, and body-only summaries in subjects. Add/fix test keys to
match your Divi IDs. Values are plain text, escaped and never recursively expanded.
Attribute/tag placeholders are removed before HTML repair, including duplicate or
unfinished quoted tags. Preview scripts, PHP and shortcodes never execute.
The iframe retains an empty sandbox and an offline CSP; images, links, forms and
other external resources are disabled. This is a content preview, not proof of
mail-client CSS support or actual SMTP delivery.

Preview accepts a JSON object with at most 50 fields and 50,000 bytes, scalar
values or flat multiselect arrays, and no case-insensitive ID collisions. Subject
and HTML limits are 1,000 and 200,000 bytes; repeated-placeholder expansion is
bounded before rendering (approximately 1 MiB). Errors leave stored content alone.

### Configure a Divi 5 contact form

1. Create and publish templates under **Forms → Mail Templates**.
2. Reload the Divi Visual Builder after publishing templates. Open the native
   **Contact Form → Content → Email** group:
   - **Notification Template** replaces the subject/body of Divi's existing email.
     Recipient addresses, Reply-To and SMTP plugins remain in Divi/WordPress's
     existing delivery path. Default keeps the original message.
   - **Sender Confirmation Template** is disabled by default. When selected, one
     confirmation is sent only if the original recipient email succeeds.
   - **Sender Email Field ID** defaults to `email`. The corresponding submitted
     field must be an Email-type field and contain a valid single email address.
   - **Save Submissions** is **off by default**. Enable it only after establishing
     your site's consent, access and retention policy. When enabled, all validated
     fields surviving Divi's conditional logic are saved as plain text.
3. Save the page and test a submission on staging, including captcha failures and
   delivery through your configured SMTP service. Changing a published template
   affects future submissions without changing its ID in the form.

Forms are indexed when saving or rendering their source pages. Use **Forms → All
Forms → Discover existing forms** for old content; discovery recognizes both Divi 5
blocks and legacy `[et_pb_contact_form]` shortcodes without rendering/executing them.
It handles at most 100 matching source posts per batch, with a Continue button when more remain. It
includes registered public post types and Divi library/theme-builder layouts.
There is no need to resubmit an answer just to discover a form. Records are keyed
by source post ID plus persistent module UUID, not a position-dependent CSS index.
Discovery results distinguish source posts checked, form definitions found and
forms indexed. Definitions without a UUID appear under **Detected Forms Requiring
Attention**, with the source page and next step, rather than silently disappearing.
Diagnostics are private to the current administrator, expire after an hour and are
limited to 100 issue entries. Discovery never rewrites page content or assigns IDs.
Old modules without a persisted UUID need attention in Divi. In Divi 5.16.0 the
UUID is assigned when adding or cloning a Contact Form; simply resaving an existing
ID-less module may not assign it. Test a newly added/duplicated module on staging
if needed, preserving the original settings and content. Indexed legacy
shortcodes are marked as such: the template/capture integration still requires
Divi 5's native handler, not the Divi 4 shortcode handler. Migrating a form with its
existing UUID preserves its index and historical answers. Removed source
forms retain their historical answers; discovery does not delete data.

Shared layouts are resolved by an indexed, unambiguous UUID. Run discovery before
testing shared layouts. If the same UUID has multiple possible shared sources,
capture/template replacement is skipped rather than mixing submissions. Divi's
native delivery remains available. Arbitrary nested global components, loops and
future Divi versions still require staging verification with real layouts.

The server hooks verified in Divi 5.16.0 are
`divi_module_library_register_module_attrs` and `et_pb_contact_form_submit`.
The latter runs after Divi's nonce/captcha/field validation. Only successful
Divi 5 submissions with a resolved runtime context are accepted. Mail rewriting
is restricted to the direct `ContactFormHandler::process()` → `wp_mail()` call;
unrelated or nested site emails are not consumed. There is no global HTML content
type filter and no second recipient email. Invalid/deleted/draft templates fall
back to Divi's existing recipient message; invalid confirmations are skipped.

The Visual Builder extends
`divi.moduleLibrary.moduleAttributes.divi.contact-form` using Divi's own vendor
hooks instance. Its shared schema is registered before the module library loads.
Content editors can select published templates; editing template HTML and reading
stored answers remain restricted to `manage_options`.

### Internal APIs

Trusted server-side integrations can also use:

- `adf_register_form( $source_key, $name, $page_id )`: create/update a form using a
  stable source key (for example a page ID plus persistent module ID).
- `adf_store_submission( $form_id, $fields, $labels = array() )`: store a map of
  stable field IDs to plain-text values. The original two-argument API still works;
  optional labels are snapshotted separately. Multiselect values are comma-joined.
- `adf_render_mail_template( $template_id, $fields, $labels = array() )`: return
  `subject` and `html`, or `WP_Error`. Optional labels map field IDs to display names
  for `{{form.summary}}`. This function itself does not send email.

Forms, answers and templates use private custom post types with administrator-only
capabilities, no public queries and no REST exposure. No visitor IP/user-agent is
collected. No records are removed on plugin deactivation. There is no automatic
retention deadline or WordPress privacy exporter/eraser yet: set up the required
policy and workflow before enabling capture. Answers can be permanently deleted
individually in Forms. Network/multisite-wide form management is not provided.

Repository (create this first): https://github.com/z10n-dev/advanced-divi-forms

## Submission management

Open **Forms → All Forms → a form** to manage its stored answers:

- Search a submitted name, email address or distinctive text (up to 200 characters).
  Search is a literal substring over stored field data, including field IDs;
  `%` and `_` are not query wildcards. Combine it with **Unread/Read**, inclusive
  **From date/To date** in the site's timezone and **Newest first/Oldest first**.
  Dates are validated; an invalid calendar day or reversed interval is rejected.
  Sorting uses the submission ID as a stable tie-breaker for equal timestamps.
- Applying filters resets to page 1. Pagination, Quick Edit, detail/back navigation
  and status actions retain all filters. If an action empties the final page,
  the list falls back to the last remaining matching page.
- **Export filtered to Excel** exports every matching answer across all pages.
  **Export all to Excel** explicitly ignores search/status/date filters and exports
  this form's answers. **Export selected to Excel** exports exactly the selected
  IDs (never falls back to all and is not narrowed by stale status filters).
  An empty filtered result produces headers only. Ambiguous scopes are rejected.

- **Quick Edit** expands directly in the answer row and lets administrators change
  the read/unread status and an internal, plain-text note (up to 5,000 characters).
  Original submitted field values are never edited. Cancel discards unsaved edits.
  The note is private to administrators, but **is included in Excel downloads**.
- Check individual rows or **Select all submissions on this page**, then choose
  **Mark as read**, **Mark as unread**, **Export selected to Excel**, or
  **Move to Trash**. Selection applies to the current page, not hidden pages.
  **Active** and **Trash** are separate views with totals for the current form.
  Trash offers individual/bulk **Restore** and a separate **Delete permanently**
  operation that requires the explicit confirmation checkbox. Original values,
  received date, form identity, read status and notes survive Trash/Restore.
  Restored answers always return to private storage, never drafts/public posts.
- **Export all to Excel** exports every active page of the current form, excluding
  Trash. Explicit filtered/selected exports in the Trash view include only that
  chosen Trash scope. The normal active view never silently includes trashed data. The download is a real `.xlsx` workbook with a frozen header
  and auto-filter: submission ID, received date/time (site timezone), status,
  internal note, then one column per field ID. Field columns are the union across
  the exported answers; missing values are blank. Values including leading zeros,
  multiline text and strings starting with `=` remain literal text, never formulas.

Export access is administrator-only and nonce-protected. Files are created with
private permissions in a system temporary directory outside the site's public
folders and deleted after download (also at request shutdown). No export is saved
in public uploads. A private writable temporary directory is required. ZipArchive
is used when available, otherwise WordPress's bundled PclZip is used; no runtime
Composer packages are required.

Safety limits are **10,000 answers**, **250,000 cells**, **8 MiB of worksheet XML**,
and Excel's column/cell-length limits. Exceeding a limit returns an error instead
of a partially truncated workbook. Export a smaller selection in that case.
Bulk status/deletion actions accept at most 200 selected IDs. All IDs are checked
against the current form before any change. Every write requires POST, an
administrator capability and a valid form-scoped nonce.

Inline editing and individual row selection work without JavaScript. JavaScript
adds select-all, selection counts, Cancel/reset and conditional delete-confirmation
controls. The wide table scrolls within the page on narrow screens.

## Privacy, retention and access

Answer storage is still **off by default**. When enabled, answers are private
custom posts; only the capabilities below can read or change them.

### Opt-in retention (default: OFF)

Open **Forms → Privacy & Retention**. Nothing is deleted automatically until an
administrator ticks the enable box **and** enters a period (1–3650 days). Saving
with a period schedules one daily cleanup that permanently deletes answers older
than that period, in batches of up to 200, including answers already in Trash.
Forms, templates, settings and other post types are never deleted, and switching
retention off removes the scheduled job. Upgrading or deactivating the plugin
deletes nothing. The page shows the current state and the last run with its count.
This is a deletion policy you must be able to justify in your own privacy notice;
the plugin does not decide that for you.

```sh
# Example: inspect the configured state in a disposable site only.
wp option get adf_retention_days
wp option get adf_retention_last_run --format=json
```

### WordPress privacy export and erasure

The plugin registers its own exporter and eraser, available under
**Tools → Export Personal Data** and **Tools → Erase Personal Data**.

Matching is exact and conservative. New receipts store the address that Divi
validated for the email field in `_adf_email`. Legacy receipts without it qualify
only when exactly one email-like field ID (for example `email`, `e-mail`,
`email_address`) carries exactly one valid address. Anything ambiguous — two
different valid addresses, no address, or a synthetic/unmatched value — is **not**
assigned to the request. The export then states that older submissions could not
be matched, and erasure reports them as retained for manual review instead of
guessing. Erasure permanently deletes exactly the matched submissions, including
matched answers in Trash; it never touches another person's record.

### Dedicated submission capabilities

Upgrading grants three new capabilities to the **administrator** role:

- `adf_view_submissions` — open Forms and read stored answers
- `adf_manage_submissions` — mark read/unread, edit the internal note, Trash,
  restore and permanently delete answers
- `adf_export_submissions` — download answers as Excel

Administrators keep their previous access. Editors, authors and subscribers get
**nothing** automatically; granting a capability is a deliberate administrator
action through WordPress's role management (for example a small role-management
plugin). The read-only view hides export, bulk and Quick Edit controls, and direct
handlers still return 403 regardless of the interface.

Kept strictly separate, still requiring `manage_options`:

- Mail-template subject/HTML and the synthetic preview endpoint
- Plugin settings, retention/deletion policy and update checks
- Creating the capabilities above (and therefore the ability to grant them)

A user with all three submission capabilities still cannot edit template HTML or
change retention. This separation is covered by the role-matrix test and a real
Chromium run.

### Historical field labels

Validated Divi receipts now store available human labels separately in
`_adf_field_labels`, keyed by the same stable IDs as immutable `_adf_fields`.
Later form edits never rewrite an older receipt. Missing/invalid labels and old
records without snapshots use the ID; historical labels are never guessed from
current form definitions. List previews and details show `Label [field_id]`.
Two fields with the same label remain separate because their IDs do not change.

Excel keeps the existing value columns and stable-ID headers in their original
positions. When exported records include snapshots, it appends **Field label
[field_id]** columns containing each answer's own historical names (or its ID
fallback), not whichever name happens to be newest. Thus a mixed old/new export
can preserve different names for the same ID, and duplicate labels never merge
value columns. Missing fields have blank value/label cells. Legacy-only exports
retain their original column shape. All extra cells count toward existing limits.

Snapshot write failures are reported as storage errors while retaining already
received original values; no automatic deletion or legacy backfill is performed.
The label PHP suite and `tests/submission-labels-browser.py` exercise immutable
values, duplicates, renaming, legacy fallback, Trash/Restore, Quick Edit and real
HTTP Excel downloads independently read with openpyxl.

### Recoverable Trash and native WordPress cleanup

Normal removal is recoverable, including when WordPress defines
`EMPTY_TRASH_DAYS=0`. Advanced Divi Forms does not silently adopt the site's
normal automatic empty-trash deadline: its trashed answers remain until an
explicit, authorized permanent deletion. WordPress's scheduled cleanup continues
normally for other post types. This protection covers Core's automatic cleanup;
trusted server-side code deliberately calling `wp_delete_post` with `force=true`
can still delete records. Plugin deactivation does not remove data.

## Setup

1. Push this repository to GitHub. Make it **public**: public GitHub release downloads require no credentials.
2. Open `main` PRs to trigger the CI checks. Configure a GitHub ruleset on `main` requiring the `Validate plugin` status check (availability varies by GitHub plan/repo visibility).
3. Build an initial release:
   ```sh
   git tag v0.1.0
   git push origin v0.1.0
   ```
   The tag must point to a commit on `main`. The workflow checks this and creates a GitHub release with `advanced-divi-forms.zip`.
4. Download the **release asset ZIP** (not GitHub's autogenerated source ZIP), and install via WordPress → Plugins → Add New → Upload Plugin. Activate.
5. Enable automatic updates for Advanced Divi Forms on the Plugins screen.
6. For the next version, update the `Version:` header and `ADF_PLUGIN_VERSION`, merge into main after CI, tag it `v0.2.0` and push the tag.
7. WordPress periodically checks for updates. The updater caches valid GitHub releases for 6 hours and failed checks briefly.
   For a guaranteed fresh check, use **Forms → Updates → Check GitHub now (bypass cache)**.
   This administrator-only, nonce-protected POST clears the plugin cache and refreshes
   WordPress’s native update entry, but never installs an update or changes automatic-update choices.
   WordPress’s general Check again link does not clear this separate plugin cache.
   The Updates page shows installed/latest successful versions, last network attempt,
   last successful check, HTTP status and readable network/rate-limit/release errors.

## Important

- Keep the root directory **advanced-divi-forms** in every release ZIP; the release workflow guarantees that.
- The GitHub repository URL occurs in the plugin header and updater: update both if using a different owner/repo.
- Do not use Hostinger git deployments to the *same plugin directory* as WordPress updates.
- Do not commit credentials. For private repositories the updater needs an authenticated distribution solution.
- Do not include Composer dev-only vendor packages in the production ZIP. If you add runtime packages later, the release packaging must include them.
- Test your actual Divi version, saved layouts and mail transport on staging before deploying the integration.
- The local `/Divi/` reference theme is ignored by Git and never included in the release ZIP.
- Before enabling unattended updates on a live site, test an update on a nonproduction WordPress installation and keep backups.

## CI and release smoke test

Every pull request, release tag, and manual workflow run checks PHP syntax,
WordPress Coding Standards, and plugin versions. CI then builds the release ZIP
and installs it with WP-CLI in a temporary installation of the latest stable
WordPress, using PHP 8.2 and a disposable MySQL 8 database. It checks activation,
a subsequent WordPress bootstrap, and the debug log for fatal PHP errors.
It also runs `tests/admin-integration.php` against the installed ZIP to check
storage, pagination, template sanitization/rendering, permissions and answer actions.
`tests/mail-summary.php` covers summary tables, labels, escaping, line breaks,
multiselects, body-only placement and non-recursive substitution.
`tests/submission-privacy.php` covers retention defaults (off, no deletion), explicit enablement, bounded cleanup, exact/ambiguous/legacy email matching, exporter and eraser output, and capability-gated policy changes. `tests/submission-capabilities.php` covers the admin upgrade grant, no implicit editor access, read-only/export/manage separation, direct-handler rejections and template/settings isolation. `tests/submission-capabilities-browser.py` verifies the same role matrix in real Chromium with 403 responses and the admin privacy page at 1280/768/390px.

`tests/template-preview.php` and `tests/template-preview-controller.php` cover
pure unsaved rendering, synthetic-only inputs, POST/nonce/type/capability boundaries,
resource limits and zero mail/network/storage calls. The optional real Chromium
`tests/template-preview-browser.py` verifies CodeMirror insertion, diagnostics,
opaque-origin sandboxing, no preview resource requests and unchanged stored data
at 1280/768/390px. It uses a disposable draft-template/cookie fixture, never a live site.
The submission action/controller/page/export suites check Quick Edit, bulk status
and deletion, immutable original fields, nonce/capability enforcement, pagination,
real XLSX packaging and the PclZip fallback. Tests use the PHP `zip` extension to
independently inspect both packaging paths. The optional
`tests/submissions-browser.py` accepts a fresh disposable fixture JSON (`form`,
`ids`, ephemeral admin `cookies`) and uses Playwright/openpyxl to exercise actual
inline saves, selections, pagination, mobile layout, XLSX downloads and confirmed
deletion. Run it only against a disposable local WordPress site; it deletes two
selected fixture answers. Never put real login cookies in version control.
Vendor-free Divi contract tests also exercise discovery, anonymous opt-in capture,
mail isolation, native/short-circuited success and failure, and confirmation rules.
`node tests/divi-settings.js` checks the Visual Builder hook registration.
The release job publishes that exact tested ZIP only after all checks pass.

Coding standards are development-only dependencies, locked in `.ci/composer.lock`:

```sh
composer --working-dir=.ci install
.ci/vendor/bin/phpcs --standard=phpcs.xml.dist
```

To run the activation test locally, install PHP with `mysqli`, WP-CLI, the MySQL
client, `zip`, and `unzip`. Provide a **disposable, empty database**, never a live
WordPress database:

```sh
bash scripts/build-plugin.sh
WP_DB_HOST=127.0.0.1:3306 \
WP_DB_NAME=wordpress_test \
WP_DB_USER=wordpress \
WP_DB_PASSWORD=wordpress \
bash scripts/test-wordpress-activation.sh dist/advanced-divi-forms.zip
```

The build requires a clean `dist` output directory. The test removes its temporary
WordPress files but leaves database cleanup to the caller (GitHub Actions removes
the database container automatically). Set `WP_VERSION` to test a specific
WordPress release instead of `latest`.

Optionally set `ADF_DIVI_THEME_DIR` to an **absolute path to your local Divi theme**
when running the smoke test. After the vendor-free tests, it installs that theme
only in the temporary WordPress instance and runs `tests/divi-live.php` against
the actual parser and contact handler. These tests cover nonce/captcha rejection,
HTML replacement, confirmation, anonymous storage and duplicate DOM coordination.
Every outgoing email is intercepted; no test email is delivered. Licensed vendor
source is not needed by public CI and is never bundled/uploaded. Visual Builder
browser interaction, real SMTP delivery and the full version range are not
automatically verified by these tests.
