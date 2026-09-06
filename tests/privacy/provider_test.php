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

namespace customfield_picture\privacy;

use core_customfield_generator;
use core_privacy\local\request\writer;

/**
 * Tests for the privacy provider
 *
 * @package    customfield_picture
 * @covers     \customfield_picture\privacy\provider
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Test that the stored picture is exported alongside the data row
     *
     * @return void
     */
    public function test_export_customfield_data(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');

        $category = $generator->create_category();
        $field = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'picture']);
        $data = $generator->add_instance_data($field, (int) $course->id, 1);

        // Populate file area, exactly as tests/data_controller_test.php does.
        $filerecord = [
            'contextid' => $data->get('contextid'),
            'component' => 'customfield_picture',
            'filearea'  => 'file',
            'itemid'    => $data->get('id'),
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ];
        $file = get_file_storage()->create_file_from_pathname($filerecord, "{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png");

        $subcontext = ['Custom fields data', (string) $data->get('id')];

        writer::reset();
        provider::export_customfield_data($data, (object) ['id' => $data->get('id')], $subcontext);

        $writer = writer::with_context($data->get_context());
        $foundfiles = $writer->get_files($subcontext);

        $this->assertCount(1, $foundfiles);
        $this->assertEquals($file, reset($foundfiles));
    }

    /**
     * Test that before_delete_data() removes only the targeted row's file
     *
     * @return void
     */
    public function test_before_delete_data(): void {
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

        $fs = get_file_storage();
        $fs->create_file_from_pathname([
            'contextid' => $dataa->get('contextid'),
            'component' => 'customfield_picture',
            'filearea'  => 'file',
            'itemid'    => $dataa->get('id'),
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ], "{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png");

        // Control file: must survive the call below, proving the deletion actually ran and was scoped.
        $fs->create_file_from_pathname([
            'contextid' => $datab->get('contextid'),
            'component' => 'customfield_picture',
            'filearea'  => 'file',
            'itemid'    => $datab->get('id'),
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ], "{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png");

        provider::before_delete_data('= :dataid', ['dataid' => $dataa->get('id')], [$dataa->get_context()->id]);

        $remaininga = $fs->get_area_files($dataa->get('contextid'), 'customfield_picture', 'file', $dataa->get('id'), '', false);
        $this->assertEmpty($remaininga);

        // Control assertion: row B's file was never targeted and must still be there.
        $remainingb = $fs->get_area_files($datab->get('contextid'), 'customfield_picture', 'file', $datab->get('id'), '', false);
        $this->assertCount(1, $remainingb);
    }
}
