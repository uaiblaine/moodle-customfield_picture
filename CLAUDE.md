# Claude instructions for `customfield_picture`

This file is auto-loaded as context whenever Claude works in this plugin's
directory tree. **Fleet-wide standards live in `~/dev/CLAUDE.md`** (coding
style, CI gates, lang-string rules, the `mdl` environment, git rules) — do not
repeat them here. This file keeps only what is true for this plugin.

Plugin context: a Moodle **customfield** field type ("Picture") that stores one
uploaded web image per custom field instance — the value lives in core's
`customfield_data` row (`intvalue`, always 1 once saved) and the image in the
file area `customfield_picture/file/<dataid>` of the instance's context. It is a
**fork of Paul Holden's `customfield_picture`** (Moodle HQ), adopted by the fleet
on 2026-09-05 to hold up to four institutional badges per course for
`theme_boost_union_fundaseg`'s enrolment hotsite (four course fields
`brasao_1`..`brasao_4`, provisioned by the theme). It owns no tables and no
capabilities, and implements core's `customfield_provider` privacy interface.
Supports Moodle **4.5 through 5.2** (`$plugin->requires = 2024042200` — the
custom field backup callbacks it implements arrived in 4.4 — and
`$plugin->supported = [405, 502]`). CI is the moodle-an-hochschulen reusable
workflow, one job per supported branch in `.github/workflows/ci.yml` — **update
those jobs when `supported` changes**. Development happens on m502; the repo is
mounted into m405, m501 and m502 at `customfield/field/picture` (see
`~/dev/moodle-dev/plugins.conf`). The default branch is `master` (upstream's).

## Agent orchestration budget (fleet rule, repeated here on purpose)

This is section 6 of `~/dev/CLAUDE.md`, mirrored into every repo of the fleet.
It is the one fleet rule these files are allowed to duplicate: a session opened
inside a plugin directory does not always carry the fleet file in context, and
the cost of missing this rule is paid immediately, in tokens, before anyone
notices it was missing.

**Every `Agent` call and every `agent()` inside a Workflow sets `model`
explicitly.** An omitted `model` runs that subagent on the session model — the
most expensive one — and is a defect, not a default:

- `sonnet` — readers, graders, refuters, verifiers, measurers, stale-reference
  sweeps, mechanical renames, test files written against a stated contract.
- `opus` — implementers of non-trivial code, ADR and documentation drafters,
  consolidators, critics, estimators.
- the session model — only for work done inline in the main loop, never for a
  subagent.

Multi-agent workflows stay opt-in and lean whatever mode is on: size the fan-out
to the question (roughly 10 to 25 agents), one refuter per finding and only for
blocking findings, no open-ended "investigate every gap" rounds. Stop and resume
with `resumeFromRunId` rather than relaunching, so completed agents stay cached.
State which model each role got when reporting a launch.

Measured 2026-09-02 on the hub category-context gap analysis: 7 lenses x 2
refuters x 2 measurers plus a critic round, every one of them on the session
model, had to be interrupted for cost — 36 agents with the refuters on Sonnet
produced the same verified result. The rule has been restated three times
(2026-09-01, 2026-09-02, 2026-09-04), the last time over implementers launched
without `model` while the reviewers around them were correctly downgraded.

## Commands

```sh
mdl ci moodle-customfield_picture --matrix      # every leg GitHub would run (405, 501, 502)
mdl ci moodle-customfield_picture               # one leg (MOODLE_501_STABLE by default)
mdl phpunit m502 customfield_picture            # targeted tests, once mounted
mdl purge m502                                  # after PHP changes that affect output
```

## Code layout

```
lib.php                          customfield_picture_pluginfile(): the 'file' area, gated by the handler's can_view()
classes/field_controller.php     field settings form (maximumbytes) and cleanup of every file when a field is deleted
classes/data_controller.php      form element, draft area load/save, image validation, get_file(), export_value(), backup/restore callbacks
classes/privacy/provider.php     customfield_provider: exports and deletes the stored file with the data row
templates/picture.mustache       the <img> export_value() renders (double stash escapes the plain-spelling alt)
tests/                           PHPUnit: controllers, pluginfile gates, privacy, backup/restore round trip
```

## Architecture gotchas

- **Course backups keep the picture only when the field is visible to the
  user running the backup.** `core_customfield\handler::backup_define_structure()`
  (`customfield/classes/handler.php:606-614`) iterates
  `get_instance_data($instanceid)` WITHOUT `returnall`, so the file annotation
  runs only for fields `can_view()` admits — the data row itself is backed up
  through `get_instance_data_for_backup()`, which does use `returnall`. A field
  with visibility "Nobody" therefore restores as a row with no file, silently,
  on every backup, course copy and import — unless ANY visible picture field
  exists in the same custom field area: the annotation is registered on the
  shared `<customfield>` element by the controller of every visible picture
  field, data or not, and then covers every picture data row of the course
  (measured on m502 with a CLI probe: a hidden field alone on its course lost
  its file only once no visible picture field existed on the site; the test
  creates its control field after the hidden round trip). "Teachers" keeps the
  file whenever the backup runs as someone holding `moodle/course:update`
  (admin included, so automated backups are fine). The RESTORE side is filtered
  the same way (`handler::restore_define_structure()` iterates the visible
  fields), so a consumer that wants hidden fields AND backups has to carry the
  files on both sides: annotate `customfield_picture/file` in its backup plugin
  class, record the old data ids, and in `after_restore_course()` map them onto
  the rows core recreated (those are restored for every editable field) before
  `add_related_files()` - theme_boost_union_fundaseg does exactly that.
  `tests/backup_test.php` pins both behaviours.
- **`pluginfile` refuses "Nobody" fields to everyone, admins included** —
  `course_handler::can_view()` returns false outright for that visibility
  (`course/classes/customfield/course_handler.php:81-90`). A consumer that must
  show such a picture (the theme's hotsite) reads the file through
  `data_controller::get_file()` and serves it on its own route with its own
  checks; `export_value()`'s URL is only usable where core's visibility rule is
  the wanted one.
- **The image check in `instance_form_save()` is the real gate, not the file
  manager's accepted types.** The upload endpoint enforces `accepted_types` from
  a parameter the client sends, `file_save_draft_area_files()` never checks types
  (`lib/filelib.php`, grep the function: no `accepted_types`), and the course web
  services (`core_course_create_courses` / `update_courses`,
  `course/externallib.php:1106-1118`) hand a raw draft id straight to
  `instance_form_save()` with no form in the loop. So every file of the draft
  area must pass `stored_file::is_valid_image()` — web_image MIME type AND
  content GD (or, for SVG, the XML parser) accepts — in both
  `instance_form_validation()` (form error) and `instance_form_save()`
  (exception). An SVG without `viewBox`/`width`/`height` fails that check; the
  error string says so.
- **SVG is served with `Content-Disposition: attachment`** by core's
  `send_file()` (`lib/filelib.php:2536-2538`) and that is wanted: an `<img>`
  still renders it (measured in Chromium) while a direct visit downloads instead
  of executing. Never pass `dontforcesvgdownload`.
- **`intvalue` says nothing about a picture existing.** `instance_form_save()`
  writes 1 whenever the form is saved, with or without a file. Use `get_file()`
  (or `export_value()` being null), never `get_value()`.
- **`maximumbytes` 0 (the "Site upload limit" choice, and a field created
  without the setting) is the site limit, not unlimited**: the upload endpoint
  clamps it with `get_user_max_upload_file_size()`
  (`repository/repository_ajax.php:86-91`).
- **Fields are appended to the end of their category on creation**
  (`customfield/classes/api.php` save path), so a provisioner that creates four
  badge fields in one pass gets them in declaration order; a field added in a
  later release lands last until reordered.
- **`export_value()` renders a Mustache template** (fleet rule: no `html_writer`
  in plugin code) and passes the field name in the PLAIN spelling
  (`get_formatted_name(false)`) because the double stash escapes; the form
  element label keeps the default escaped spelling because core's form template
  prints labels with a triple stash.
- **PHPUnit metadata stays in docblocks** (`@covers` at class level) while
  `supported` includes 405: moodle-cs on the 4.05 leg cannot see attributes.
  The two PHPUnit deprecations on the 5.x legs are that, not a defect.

## Testing notes

The core custom field generator does everything: `create_category()`,
`create_field(['type' => 'picture', 'configdata' => ['visibility' => 2]])`,
`add_instance_data($field, $courseid, 1)`; a picture is a `stored_file` created
directly in `customfield_picture/file/<dataid>` (fixture
`lib/tests/fixtures/gd-logo.png`; SVG fixture
`lib/filestorage/tests/fixtures/testimage_viewbox.svg`). The form path is
exercised through core's `customfield/tests/fixtures/test_instance_form.php`
with `mock_submit()`, as upstream's tests do. The file route is tested through
`customfield_picture_get_file()`, the resolver `customfield_picture_pluginfile()`
wraps: the success path cannot be asserted in-process because
`send_stored_file()` reaches `readfile_accel()`, which drains every output buffer
and writes the bytes to the real output (measured on the 5.2 stack).
The backup round trip copies the helpers of
`customfield/field/textarea/tests/plugin_test.php` (import-mode backup stays
unzipped; general-mode restore with `execute_precheck()`).

## When in doubt

Follow the patterns in existing files. The codebase is internally
consistent — if a new file feels like it matches no existing shape,
re-examine the approach.
