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
use core_customfield_generator;
use core_customfield\data;
use core_customfield\field;
use core_customfield\field_config_form;

/**
 * Tests for the field controller
 *
 * @package    customfield_picture
 * @covers     \customfield_picture\field_controller
 * @copyright  2022 Paul Holden <paulh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class field_controller_test extends advanced_testcase {
    /**
     * Test that using base field controller returns our picture type
     */
    public function test_create(): void {
        $this->resetAfterTest();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);

        $this->assertInstanceOf(field_controller::class, \core_customfield\field_controller::create((int) $field->get('id')));
        $this->assertInstanceOf(field_controller::class, \core_customfield\field_controller::create(0, $field->to_record()));
    }

    /**
     * Test submitting field definition form
     */
    public function test_form_definition(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'configdata' => [
                'maximumbytes' => 1024,
            ],
        ]);

        $submitdata = (array) $field->to_record();
        $submitdata['configdata'] = $field->get('configdata');

        $formdata = field_config_form::mock_ajax_submit($submitdata);
        $form = new field_config_form(null, null, 'post', '', null, true, $formdata, true);

        $form->set_data_for_dynamic_submission();
        $this->assertTrue($form->is_validated());
        $form->process_dynamic_submission();
    }

    /**
     * Test that deleting one field removes only its own pictures and data rows
     *
     * A second field in the same category is the control: its data row and file must survive
     * untouched, which is what proves the deletion was scoped to the targeted field rather than
     * passing by doing nothing.
     *
     * @return void
     */
    public function test_delete_removes_files(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $fielda = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);
        $fieldb = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);

        $dataa = $generator->add_instance_data($fielda, (int) $course->id, 1);
        $datab = $generator->add_instance_data($fieldb, (int) $course->id, 1);

        $contextida = (int) $dataa->get('contextid');
        $dataaid = (int) $dataa->get('id');
        $contextidb = (int) $datab->get('contextid');
        $databid = (int) $datab->get('id');
        $fieldaid = (int) $fielda->get('id');
        $fieldbid = (int) $fieldb->get('id');

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

        $fielda->delete();

        $filesa = get_file_storage()->get_area_files($contextida, 'customfield_picture', 'file', $dataaid, '', false);
        $this->assertCount(0, $filesa);
        $this->assertFalse(data::record_exists($dataaid));
        $this->assertFalse(field::record_exists($fieldaid));

        // Control: field B, its data row and its file were not touched by field A's deletion.
        $filesb = get_file_storage()->get_area_files($contextidb, 'customfield_picture', 'file', $databid, '', false);
        $this->assertCount(1, $filesb);
        $this->assertTrue(data::record_exists($databid));
        $this->assertTrue(field::record_exists($fieldbid));
    }
}
