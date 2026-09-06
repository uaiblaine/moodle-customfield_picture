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

use core_course\customfield\course_handler;
use core_customfield_generator;

/**
 * Tests for backup and restore of the stored picture
 *
 * @package    customfield_picture
 * @covers     \customfield_picture\data_controller
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_test extends \advanced_testcase {
    /**
     * Test that a course backup and restore keeps the stored picture
     *
     * @return void
     */
    public function test_backup_and_restore_keeps_the_picture(): void {
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
            'configdata' => ['visibility' => 2],
        ]);
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
        $originalcontenthash = $file->get_contenthash();

        $backupid = $this->backup_course($course);
        $newcourseid = $this->restore_course($backupid, $course, '_copy');

        $newdatacontrollers = course_handler::create()->get_instance_data($newcourseid, true);
        $newdata = $newdatacontrollers[(int) $field->get('id')];
        $this->assertNotEmpty($newdata->get('id'));

        $newfile = $newdata->get_file();
        $this->assertNotNull($newfile);
        $this->assertSame($originalcontenthash, $newfile->get_contenthash());
    }

    /**
     * Test that a picture field visible to nobody restores its data row but never its file
     *
     * customfield/classes/handler.php::backup_define_structure() only annotates files of fields the
     * backup user can view (get_instance_data() without returnall skips a NOTVISIBLE field entirely),
     * while the data row itself is still backed up because get_instance_data_for_backup() also
     * accepts fields the backup user can edit. This test documents that split, and the control field
     * proves the round trip itself works when it is not the visibility rule doing the skipping.
     *
     * @return void
     */
    public function test_backup_skips_files_of_fields_visible_to_nobody(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category();
        $fs = get_file_storage();

        /* The file annotation is registered on the backup element by the controller of every
           picture field the backup user can view - data or not - and then covers every picture
           data row of the course. So the hidden field's round trip has to run while no visible
           picture field exists at all; the control (a visible field, same fixture, same round
           trip) is created only afterwards. */
        $fieldhidden = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'shortname' => 'hidden',
            'configdata' => ['visibility' => 0],
        ]);
        $courseb = $this->getDataGenerator()->create_course();
        $datab = $generator->add_instance_data($fieldhidden, (int) $courseb->id, 1);
        $fs->create_file_from_pathname([
            'contextid' => $datab->get('contextid'),
            'component' => 'customfield_picture',
            'filearea'  => 'file',
            'itemid'    => $datab->get('id'),
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ], "{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png");

        // Target: the hidden field's data row is restored, but its file is not.
        $newcourseb = $this->restore_course($this->backup_course($courseb), $courseb, '_copyb');
        $newdatab = course_handler::create()->get_instance_data($newcourseb, true)[(int) $fieldhidden->get('id')];
        $this->assertNotEmpty($newdatab->get('id'));
        $this->assertNull($newdatab->get_file());

        // Control: a visible field's data row and file both make the same round trip.
        $fieldvisible = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'shortname' => 'visible',
            'configdata' => ['visibility' => 2],
        ]);
        $coursea = $this->getDataGenerator()->create_course();
        $dataa = $generator->add_instance_data($fieldvisible, (int) $coursea->id, 1);
        $fs->create_file_from_pathname([
            'contextid' => $dataa->get('contextid'),
            'component' => 'customfield_picture',
            'filearea'  => 'file',
            'itemid'    => $dataa->get('id'),
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ], "{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png");
        $newcoursea = $this->restore_course($this->backup_course($coursea), $coursea, '_copya');
        $newdataa = course_handler::create()->get_instance_data($newcoursea, true)[(int) $fieldvisible->get('id')];
        $this->assertNotEmpty($newdataa->get('id'));
        $this->assertNotNull($newdataa->get_file());
    }

    /**
     * Back a course up to the (unzipped) backup temp directory
     *
     * @param \stdClass $course Course object to back up.
     * @return string Id of the backup.
     */
    protected function backup_course(\stdClass $course): string {
        global $USER, $CFG;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        // Turn off file logging, otherwise it can't delete the file (Windows).
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        // Do backup with default settings. MODE_IMPORT means it will just create the directory and not zip it.
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
        );
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->get_plan()->get_setting('logs')->set_value(true);
        $backupid = $bc->get_backupid();

        $bc->execute_plan();
        $bc->destroy();

        return $backupid;
    }

    /**
     * Restore a course from the backup temp directory
     *
     * @param string $backupid Backup id.
     * @param \stdClass $course Original course object.
     * @param string $suffix Suffix to add after original course shortname and fullname.
     * @return int New course id.
     */
    protected function restore_course(string $backupid, \stdClass $course, string $suffix): int {
        global $USER, $CFG;

        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        // Do restore to new course with default settings.
        $newcourseid = \restore_dbops::create_new_course(
            $course->fullname . $suffix,
            $course->shortname . $suffix,
            $course->category,
        );
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE,
        );
        $rc->get_plan()->get_setting('logs')->set_value(true);
        $rc->get_plan()->get_setting('users')->set_value(true);

        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }
}
