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

declare(strict_types=1);

namespace customfield_picture;

use advanced_testcase;
use context_user;
use core_customfield_generator;
use core_customfield_test_instance_form;
use core_customfield\data;

/**
 * Tests for the data controller
 *
 * @package    customfield_picture
 * @covers     \customfield_picture\data_controller
 * @copyright  2022 Paul Holden <paulh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class data_controller_test extends advanced_testcase {
    /**
     * Remove every course custom field the test site already carries
     *
     * The instance form is built from all the categories of the area, and the mock submission
     * only knows the picture field; another type's controller then reads a value the submission
     * never carried (a theme mounted on the dev stacks provisions ten such fields). Deleting the
     * categories first makes the form tests independent of what else the site has.
     *
     * @return void
     */
    private function purge_course_customfields(): void {
        foreach (\core_customfield\api::get_categories_with_fields('core_course', 'course', 0) as $category) {
            \core_customfield\api::delete_category($category);
        }
    }

    /**
     * Test that using base field controller returns our picture type
     */
    public function test_create(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);
        $data = $generator->add_instance_data($field, (int) $course->id, 1);

        $this->assertInstanceOf(data_controller::class, \core_customfield\data_controller::create($data->get('id')));
        $this->assertInstanceOf(data_controller::class, \core_customfield\data_controller::create(0, $data->to_record()));
        $this->assertInstanceOf(data_controller::class, \core_customfield\data_controller::create(0, null, $field));
    }

    /**
     * Test submitting field instance form
     */
    public function test_form_save(): void {
        global $CFG, $USER;

        require_once("{$CFG->dirroot}/customfield/tests/fixtures/test_instance_form.php");

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->purge_course_customfields();

        $course = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);

        // Populate user draft area.
        $draftid = file_get_unused_draft_itemid();
        $filerecord = [
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ];
        get_file_storage()->create_file_from_pathname($filerecord, "{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png");

        $formdata = array_merge((array) $course, ['customfield_' . $field->get('shortname')  => $draftid]);
        core_customfield_test_instance_form::mock_submit($formdata);

        $form = new core_customfield_test_instance_form('POST', ['handler' => $category->get_handler(), 'instance' => $course]);
        $this->assertTrue($form->is_validated());

        $formsubmission = $form->get_data();
        $category->get_handler()->instance_form_save($formsubmission);

        // Validate file was stored.
        $datainstance = data::get_record(['fieldid' => $field->get('id'), 'instanceid' => $formsubmission->id]);
        $files = get_file_storage()->get_area_files(
            $datainstance->get('contextid'),
            'customfield_picture',
            'file',
            $datainstance->get('id'),
            '',
            false,
        );

        $this->assertCount(1, $files);
        $file = reset($files);

        $this->assertEquals('/', $file->get_filepath());
        $this->assertEquals('logo.png', $file->get_filename());
    }

    /**
     * Test exporting instance renders the picture template
     *
     * @return void
     */
    public function test_export_value(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);
        $data = $generator->add_instance_data($field, (int) $course->id, 1);

        // Populate file area.
        $filerecord = [
            'contextid' => $data->get('contextid'),
            'component' => 'customfield_picture',
            'filearea'  => 'file',
            'itemid'    => $data->get('id'),
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ];
        get_file_storage()->create_file_from_pathname($filerecord, "{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png");

        $dataid = (int) $data->get('id');
        $result = \core_customfield\data_controller::create($dataid)->export_value();

        // The src attribute must point at this instance's own pluginfile URL, order of attributes not assumed.
        $this->assertMatchesRegularExpression(
            '#src="[^"]*/customfield_picture/file/' . $dataid . '/logo\.png"#',
            $result,
        );
        $this->assertMatchesRegularExpression(
            '/alt="' . preg_quote($field->get_formatted_name(), '/') . '"/',
            $result,
        );
    }

    /**
     * Test that an instance without a stored picture exports nothing
     *
     * @return void
     */
    public function test_export_value_no_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);
        $data = $generator->add_instance_data($field, (int) $course->id, 1);

        $datacontroller = \core_customfield\data_controller::create((int) $data->get('id'));

        $this->assertNull($datacontroller->get_file());
        $this->assertNull($datacontroller->export_value());
    }

    /**
     * Test that the field name reaches the rendered alt attribute escaped exactly once
     *
     * @return void
     */
    public function test_export_value_alt_is_escaped_once(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'name' => 'Badge & shield',
        ]);
        $data = $generator->add_instance_data($field, (int) $course->id, 1);

        $filerecord = [
            'contextid' => $data->get('contextid'),
            'component' => 'customfield_picture',
            'filearea'  => 'file',
            'itemid'    => $data->get('id'),
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ];
        get_file_storage()->create_file_from_pathname($filerecord, "{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png");

        $result = \core_customfield\data_controller::create((int) $data->get('id'))->export_value();

        $this->assertStringContainsString('alt="Badge &amp; shield"', $result);
        $this->assertStringNotContainsString('alt="Badge &amp;amp; shield"', $result);
    }

    /**
     * Test submitting the instance form with a draft area holding no file
     *
     * @return void
     */
    public function test_form_save_empty_draft(): void {
        global $CFG;

        require_once("{$CFG->dirroot}/customfield/tests/fixtures/test_instance_form.php");

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->purge_course_customfields();

        $course = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);

        // An unused draft area holds no file at all.
        $draftid = file_get_unused_draft_itemid();

        $formdata = array_merge((array) $course, ['customfield_' . $field->get('shortname') => $draftid]);
        core_customfield_test_instance_form::mock_submit($formdata);

        $form = new core_customfield_test_instance_form('POST', ['handler' => $category->get_handler(), 'instance' => $course]);
        $this->assertTrue($form->is_validated());

        $formsubmission = $form->get_data();
        $category->get_handler()->instance_form_save($formsubmission);

        $datainstance = data::get_record(['fieldid' => $field->get('id'), 'instanceid' => $formsubmission->id]);
        $files = get_file_storage()->get_area_files(
            $datainstance->get('contextid'),
            'customfield_picture',
            'file',
            $datainstance->get('id'),
            '',
            false,
        );

        $this->assertCount(0, $files);
        $this->assertNull(\core_customfield\data_controller::create((int) $datainstance->get('id'))->export_value());
    }

    /**
     * Test that the instance form refuses a draft holding a file that is not really an image
     *
     * @return void
     */
    public function test_form_validation_rejects_non_image(): void {
        global $CFG, $USER;

        require_once("{$CFG->dirroot}/customfield/tests/fixtures/test_instance_form.php");

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->purge_course_customfields();

        $course = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);

        // Populate user draft area with a file that is not really an image.
        $draftid = file_get_unused_draft_itemid();
        $filerecord = [
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => 'notes.png',
        ];
        get_file_storage()->create_file_from_string($filerecord, 'this is not an image');

        $formdata = array_merge((array) $course, ['customfield_' . $field->get('shortname') => $draftid]);
        core_customfield_test_instance_form::mock_submit($formdata);

        $form = new core_customfield_test_instance_form('POST', ['handler' => $category->get_handler(), 'instance' => $course]);

        // The fixture form exposes no public accessor for individual element errors: is_validated() false,
        // together with get_data() returning null, is the observable proof that validation rejected the file.
        $this->assertFalse($form->is_validated());
        $this->assertNull($form->get_data());
    }

    /**
     * Test that calling instance_form_save() directly still refuses a non-image draft
     *
     * Not every caller goes through the form: the course web services set custom field values from raw
     * request data and call instance_form_save() directly, so the guard has to hold there too.
     *
     * @return void
     */
    public function test_form_save_throws_on_non_image(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);
        $data = $generator->add_instance_data($field, (int) $course->id, 1);

        $draftid = file_get_unused_draft_itemid();
        $filerecord = [
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => 'notes.png',
        ];
        get_file_storage()->create_file_from_string($filerecord, 'this is not an image');

        $datacontroller = \core_customfield\data_controller::create((int) $data->get('id'));
        $contextid = $datacontroller->get_context()->id;
        $dataid = (int) $data->get('id');
        $elementname = $datacontroller->get_form_element_name();

        $this->expectException(\moodle_exception::class);
        try {
            $datacontroller->instance_form_save((object) [$elementname => $draftid]);
        } finally {
            // Assert this inside the finally block: the exception still has to reach PHPUnit for
            // expectException() to pass, so nothing after the throwing call runs unless it is here.
            $files = get_file_storage()->get_area_files($contextid, 'customfield_picture', 'file', $dataid, '', false);
            $this->assertCount(0, $files);
        }
    }

    /**
     * Test that the instance form accepts an SVG image with a viewBox
     *
     * @return void
     */
    public function test_form_save_accepts_svg(): void {
        global $CFG, $USER;

        require_once("{$CFG->dirroot}/customfield/tests/fixtures/test_instance_form.php");

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->purge_course_customfields();

        $course = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);

        $draftid = file_get_unused_draft_itemid();
        $filerecord = [
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => 'logo.svg',
        ];
        get_file_storage()->create_file_from_pathname(
            $filerecord,
            "{$CFG->dirroot}/lib/filestorage/tests/fixtures/testimage_viewbox.svg",
        );

        $formdata = array_merge((array) $course, ['customfield_' . $field->get('shortname') => $draftid]);
        core_customfield_test_instance_form::mock_submit($formdata);

        $form = new core_customfield_test_instance_form('POST', ['handler' => $category->get_handler(), 'instance' => $course]);
        $this->assertTrue($form->is_validated());

        $formsubmission = $form->get_data();
        $category->get_handler()->instance_form_save($formsubmission);

        $datainstance = data::get_record(['fieldid' => $field->get('id'), 'instanceid' => $formsubmission->id]);
        $files = get_file_storage()->get_area_files(
            $datainstance->get('contextid'),
            'customfield_picture',
            'file',
            $datainstance->get('id'),
            '',
            false,
        );

        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertEquals('logo.svg', $file->get_filename());

        $result = \core_customfield\data_controller::create((int) $datainstance->get('id'))->export_value();
        $this->assertNotNull($result);
    }

    /**
     * Test that deleting one instance removes only its own picture and row
     *
     * A second instance on a different course is the control: it must survive untouched, which is what
     * proves the deletion was scoped to the targeted instance rather than passing by doing nothing.
     *
     * @return void
     */
    public function test_delete_removes_files(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);

        $dataa = $generator->add_instance_data($field, (int) $coursea->id, 1);
        $datab = $generator->add_instance_data($field, (int) $courseb->id, 1);

        $contextida = (int) $dataa->get('contextid');
        $dataaid = (int) $dataa->get('id');
        $contextidb = (int) $datab->get('contextid');
        $databid = (int) $datab->get('id');

        get_file_storage()->create_file_from_pathname([
            'contextid' => $contextida,
            'component' => 'customfield_picture',
            'filearea'  => 'file',
            'itemid'    => $dataaid,
            'filepath'  => '/',
            'filename'  => 'logo-a.png',
        ], "{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png");

        get_file_storage()->create_file_from_pathname([
            'contextid' => $contextidb,
            'component' => 'customfield_picture',
            'filearea'  => 'file',
            'itemid'    => $databid,
            'filepath'  => '/',
            'filename'  => 'logo-b.png',
        ], "{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png");

        $dataa->delete();

        $filesa = get_file_storage()->get_area_files($contextida, 'customfield_picture', 'file', $dataaid, '', false);
        $this->assertCount(0, $filesa);
        $this->assertFalse(data::record_exists($dataaid));

        // Control: instance B was not touched by instance A's deletion.
        $filesb = get_file_storage()->get_area_files($contextidb, 'customfield_picture', 'file', $databid, '', false);
        $this->assertCount(1, $filesb);
        $this->assertTrue(data::record_exists($databid));
    }
}
