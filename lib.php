<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Plugin callbacks
 *
 * @package    customfield_picture
 * @copyright  2022 Paul Holden <paulh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_customfield\{data, data_controller, field_controller};

/**
 * Resolve the file a plugin file request names, or refuse it
 *
 * The only area is 'file', whose item id is the id of the customfield_data row. Every refusal is
 * null: a request naming a data row that does not exist, a field of another type, a context other
 * than the row's own, or a field the current user may not view (the handler's visibility rule:
 * "nobody" refuses everyone, "teachers" needs moodle/course:update on the course, "everyone" needs
 * nothing at all). Kept apart from the sending so that it can be tested: send_stored_file() drains
 * every output buffer and writes the bytes to the real output.
 *
 * @param context $context The context the URL names.
 * @param string $filearea The file area.
 * @param array $args The remaining path segments: the data id, then the file name.
 * @return stored_file|null The file to send, or null to refuse.
 */
function customfield_picture_get_file(context $context, string $filearea, array $args): ?stored_file {
    global $DB;

    if ($filearea !== 'file') {
        return null;
    }

    $itemid = (int) array_shift($args);
    $datarecord = $DB->get_record(data::TABLE, ['id' => $itemid]);
    if (!$datarecord) {
        return null;
    }

    $field = field_controller::create($datarecord->fieldid);
    $data = data_controller::create(0, $datarecord, $field);

    if (
        $field->get('type') !== 'picture' ||
        (int) $data->get_context()->id !== (int) $context->id ||
        !$field->get_handler()->can_view($field, $data->get('instanceid'))
    ) {
        return null;
    }

    $filename = (string) array_pop($args);
    $file = get_file_storage()->get_file($context->id, 'customfield_picture', $filearea, $itemid, '/', $filename);
    if (!$file || $file->is_directory()) {
        return null;
    }
    return $file;
}

/**
 * Serve plugin files from storage
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if the file not found, otherwise serve the file
 */
function customfield_picture_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    $file = customfield_picture_get_file($context, $filearea, $args);
    if ($file === null) {
        return false;
    }

    // Success, serve the file.
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}
