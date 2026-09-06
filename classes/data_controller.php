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

namespace customfield_picture;

use backup_nested_element;
use context_user;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Data controller class
 *
 * @package    customfield_picture
 * @copyright  2022 Paul Holden <paulh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_controller extends \core_customfield\data_controller {
    /** @var string The one file area of this field type; the item id is the customfield_data id. */
    public const FILEAREA = 'file';

    /**
     * Return the name of the field where the information is stored
     *
     * @return string
     */
    public function datafield(): string {
        return 'intvalue';
    }

    /**
     * Return options suitable for the file manager element
     *
     * A maximum size of 0 (the "site upload limit" choice of the field settings, and the value of a
     * field created without one) is not "unlimited": the upload endpoint clamps it to the site and
     * course limits (repository/repository_ajax.php), so the effective cap is the site's.
     *
     * @return array
     */
    private function get_filemanager_options(): array {
        return [
            'maxbytes' => (int) $this->get_field()->get_configdata_property('maximumbytes'),
            'maxfiles' => 1,
            'subdirs' => 0,
            'accepted_types' => 'web_image',
        ];
    }

    /**
     * Add form elements for editing the custom field instance
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function instance_form_definition(MoodleQuickForm $mform): void {
        $mform->addElement(
            'filemanager',
            $this->get_form_element_name(),
            $this->get_field()->get_formatted_name(),
            null,
            $this->get_filemanager_options(),
        );
    }

    /**
     * Prepare file draft area prior to loading form
     *
     * @param stdClass $data
     * @return void
     */
    public function instance_form_before_set_data(stdClass $data): void {
        $fieldname = $this->get_form_element_name();

        $draftid = file_get_submitted_draft_itemid($fieldname);
        file_prepare_draft_area(
            $draftid,
            $this->get_context()->id,
            'customfield_picture',
            self::FILEAREA,
            $this->get('id'),
            $this->get_filemanager_options(),
        );

        $data->{$fieldname} = $draftid;
    }

    /**
     * Refuse a draft area holding anything that is not a web image
     *
     * The file manager's accepted types are enforced by the upload endpoint from a parameter the
     * client sends, and file_save_draft_area_files() never checks types at all, so this is the
     * check that actually holds: every file of the draft area must be an image GD (or, for SVG, the
     * XML parser) accepts, with a MIME type in the web_image group.
     *
     * @param array $data
     * @param array $files
     * @return array array of errors
     */
    public function instance_form_validation(array $data, array $files): array {
        $fieldname = $this->get_form_element_name();
        $errors = parent::instance_form_validation($data, $files);
        if (isset($data[$fieldname])) {
            $rejected = $this->rejected_draft_files((int) $data[$fieldname]);
            if ($rejected) {
                $errors[$fieldname] = get_string('error:notanimage', 'customfield_picture', implode(', ', $rejected));
            }
        }
        return $errors;
    }

    /**
     * Move submitted file to storage
     *
     * Validation is repeated here because not every caller goes through the form: the course web
     * services set custom field values from raw request data and call this method directly.
     *
     * @param stdClass $data
     * @return void
     * @throws \moodle_exception When the draft area holds a file that is not a web image.
     */
    public function instance_form_save(stdClass $data): void {
        $fieldname = $this->get_form_element_name();
        $draftitemid = (int) ($data->{$fieldname} ?? 0);

        $rejected = $this->rejected_draft_files($draftitemid);
        if ($rejected) {
            throw new \moodle_exception('error:notanimage', 'customfield_picture', '', implode(', ', $rejected));
        }

        // Trigger save.
        parent::instance_form_save((object) [$fieldname => 1]);

        file_save_draft_area_files(
            $draftitemid,
            $this->get_context()->id,
            'customfield_picture',
            self::FILEAREA,
            $this->get('id'),
            $this->get_filemanager_options(),
        );
    }

    /**
     * The names of the files of a draft area that are not valid web images
     *
     * @param int $draftitemid The draft area of the current user, 0 for none.
     * @return string[] File names, empty when every file is an image.
     */
    private function rejected_draft_files(int $draftitemid): array {
        global $USER;

        // A draft area belongs to a logged-in user; without one there is nothing to inspect (or to copy).
        if (!$draftitemid || empty($USER->id)) {
            return [];
        }
        $usercontext = context_user::instance($USER->id);
        $files = get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);

        $rejected = [];
        foreach ($files as $file) {
            if (!$file->is_valid_image()) {
                $rejected[] = $file->get_filename();
            }
        }
        return $rejected;
    }

    /**
     * Returns the default value in non human-readable format
     *
     * @return int
     */
    public function get_default_value(): int {
        return 0;
    }

    /**
     * Implement the backup callback in order to include embedded files.
     *
     * @param \backup_nested_element $customfieldelement
     * @return void
     */
    public function backup_define_structure(backup_nested_element $customfieldelement): void {
        $annotations = $customfieldelement->get_file_annotations();

        if (!isset($annotations['customfield_picture'][self::FILEAREA])) {
            $customfieldelement->annotate_files('customfield_picture', self::FILEAREA, 'id');
        }
    }

    /**
     * Implement the restore callback in order to restore embedded files.
     *
     * @param \restore_structure_step $step
     * @param int $newid
     * @param int $oldid
     * @return void
     */
    public function restore_define_structure(\restore_structure_step $step, int $newid, int $oldid): void {
        if (!$step->get_mappingid('customfield_picture_data', $oldid)) {
            $step->set_mapping('customfield_picture_data', $oldid, $newid, true);
            $step->add_related_files('customfield_picture', self::FILEAREA, 'customfield_picture_data');
        }
    }

    /**
     * The stored picture, if any
     *
     * The data row does not say whether a picture exists: instance_form_save() writes intvalue 1
     * whenever the form is saved, with or without a file. Only the file area does.
     *
     * @return \stored_file|null The picture, or null when none was uploaded.
     */
    public function get_file(): ?\stored_file {
        if (!$this->get('id')) {
            return null;
        }
        $files = get_file_storage()->get_area_files(
            $this->get_context()->id,
            'customfield_picture',
            self::FILEAREA,
            $this->get('id'),
            'itemid, filepath, filename',
            false,
        );
        return $files ? reset($files) : null;
    }

    /**
     * Returns value in a human-readable format
     *
     * @return string|null
     */
    public function export_value(): ?string {
        global $OUTPUT;

        $file = $this->get_file();
        if ($file === null) {
            return null;
        }

        $fileurl = moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
        );

        return $OUTPUT->render_from_template('customfield_picture/picture', [
            'url' => $fileurl->out(false),
            'alt' => $this->get_field()->get_formatted_name(false),
        ]);
    }

    /**
     * Delete individual field data
     *
     * @return bool
     */
    public function delete(): bool {
        get_file_storage()->delete_area_files($this->get_context()->id, 'customfield_picture', self::FILEAREA, $this->get('id'));

        return parent::delete();
    }
}
