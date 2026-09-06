moodle-customfield_picture
==========================

[![Moodle Plugin CI](https://github.com/uaiblaine/moodle-customfield_picture/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/uaiblaine/moodle-customfield_picture/actions/workflows/ci.yml?query=branch%3Amaster)

A custom field type that stores one uploaded image per instance.

The Picture field type adds an image upload to any custom field area of Moodle:
courses, cohorts, groups and groupings, or any plugin that uses the custom field
API. An admin creates a field of type "Picture" (Site administration > Courses >
Course custom fields, or the equivalent page of the area), sets its maximum
upload size, and the field appears as a file manager accepting one web image
(PNG, JPEG, GIF, WebP or SVG). Wherever core displays custom fields — course
cards, the course listing, web service exports — the value renders as the image.

This is the fleet's fork of Paul Holden's plugin. It holds the institutional
badges of the enrolment hotsite delivered by `theme_boost_union_fundaseg`: four
course fields of this type, provisioned by the theme, read by the hotsite page
and served to visitors of a public course through the theme's own file route.
Beyond the fleet scaffolding, the fork hardens what the upstream trusted to the
form layer: every file saved through the field — from the course form or from
the course web services, which never build a form — must be a web image that GD
or the SVG parser accepts, and the file route validates the area it serves and
the context it is asked for.


Requirements
------------

- Moodle 4.4 or later (tested on 4.5, 5.1 and 5.2). The plugin implements the
  custom field backup callbacks that arrived in Moodle 4.4 (MDL-79151), so the
  stored picture travels with course backups, restores and copies.


Installation
------------

Install the plugin like any other plugin to folder `/customfield/field/picture`.

See http://docs.moodle.org/en/Installing_plugins for details on installing
Moodle plugins.

Nothing to configure after installation: the type shows up in the "Add a new
custom field" menu of every custom field area.


Usage
-----

**Creating a field.** In the custom field area (for courses: Site
administration > Courses > Course custom fields) add a field of type Picture.
Its only specific setting is the maximum upload size; "Site upload limit" (the
default) means the site's own limit, not unlimited. The standard visibility
setting decides who sees the image where core renders custom fields — and, for
courses, who may fetch the image file at all (see Troubleshooting).

**Filling it in.** The field renders as a file manager accepting one file of the
web image group. A file that is not an image — whatever its extension — is
refused with an error, on the form and through the web services alike. An SVG
must declare its size (`viewBox`, or `width` and `height`).

**Reading it from code.** `customfield_picture\data_controller::get_file()`
returns the stored file, or null when none was uploaded; `export_value()`
returns the rendered `<img>` or null. The data row's value is always 1 once the
form has been saved and says nothing about a picture existing.


Capabilities
------------

The plugin declares no capabilities. Access follows the custom field area's own
rules: whoever may edit the instance's custom fields may upload, and the field's
visibility decides who may view.


Privacy
-------

The plugin stores the uploaded image in the file area of the instance's
context, linked to the custom field data row. It holds no personal data of its
own; the image is exported and deleted together with the custom field data
through core's custom field privacy provider.


Troubleshooting
---------------

- **The image is not restored after a backup, copy or import.** Core annotates
  the files of a custom field for backup only when the field is visible to the
  user running the backup. A field set to "Nobody" therefore restores as an
  empty field, unless a visible Picture field exists anywhere in the same area
  (the file annotation is shared by every Picture field). Use "Teachers"
  (kept whenever the backup runs as someone who can update the course, the site
  administrator included) or "Everyone".
- **The image URL answers 404, even for an administrator.** The file route
  applies the field's visibility rule exactly: "Nobody" refuses everyone. A
  plugin that needs to show such an image serves the file through its own route
  (the enrolment hotsite of `theme_boost_union_fundaseg` does).
- **"The file ... is not an image."** The uploaded file is not a web image GD
  (or, for SVG, the XML parser) accepts: a renamed document, a corrupt file, or
  an SVG without `viewBox`/`width`/`height`.
- **An SVG opened by its URL downloads instead of showing.** Core forces the
  download of SVG files served from user uploads for security; inside an
  `<img>` the same URL renders normally.


Credits
-------

This plugin is a fork of Paul Holden's `customfield_picture`
(https://github.com/paulholden/moodle-customfield_picture, released on
https://moodle.org/plugins/customfield_picture), copyright 2022 Paul Holden
<paulh@moodle.com>. The field type, its controllers, privacy provider and backup
callbacks are his work; the fork adds the fleet's CI and documentation, the
image validation of saved files, the hardened file route, a template for the
rendered value, Brazilian Portuguese strings and tests.


License
-------

This plugin is licensed under the [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html).

Copyright: 2022 Paul Holden (upstream), 2026 Anderson Blaine (fork)
