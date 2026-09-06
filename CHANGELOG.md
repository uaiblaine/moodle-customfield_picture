# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Added

- Fleet adoption of the fork: the moodle-an-hochschulen CI workflow (one job per
  supported branch, push filter and concurrency block), release workflow,
  `.gitattributes` export-ignore list, `phpcs.xml`, `CLAUDE.md`, a README in the
  fleet's shape with credits to the upstream author, and the `pt_br` language
  pack in lockstep with `en`.
- `$plugin->supported = [405, 502]`; `$plugin->requires` raised to Moodle 4.4
  (2024042200), where the custom field backup callbacks the plugin implements
  arrived (MDL-79151) — on 4.1–4.3 the callbacks were never invoked and the
  picture silently missed every backup.
- `data_controller::get_file()`, the one truthful way to know whether a picture
  exists (the data row's value is 1 once the form is saved, file or not).
- Tests for the file route's access gates, the image validation, the rendered
  value, deletion cleanup, the privacy provider and a backup/restore round trip,
  including the core behaviour that a field visible to nobody restores without
  its file.

### Changed

- `export_value()` renders a Mustache template (`customfield_picture/picture`)
  instead of `html_writer::img()`, and passes the field name in the plain
  spelling: the upstream escaped it twice, so a name holding `&` rendered as
  `&amp;` in the alt text.
- `customfield_picture_pluginfile()` compares contexts by id (as core's
  textarea type does) instead of object identity, serves only the `file` area,
  and answers a nonexistent data id with a plain 404 instead of an exception.

### Fixed

- **Any file type could be stored through the course web services.** The file
  manager's accepted types are enforced by the upload endpoint from a parameter
  the client sends, `file_save_draft_area_files()` checks no types, and
  `core_course_create_courses` / `update_courses` hand a raw draft id to
  `instance_form_save()` with no form in the loop — so a user holding
  `moodle/course:update` could attach an HTML file that the file route then
  served inline, same-origin, to whoever the field's visibility admitted. Every
  file of the draft area must now pass `stored_file::is_valid_image()`, in the
  form validation (an error on the element) and in `instance_form_save()` (an
  exception), whoever the caller is.
