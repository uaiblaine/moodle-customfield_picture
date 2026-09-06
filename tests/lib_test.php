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
use context_course;
use core_customfield_generator;

/**
 * Tests for the pluginfile callback
 *
 * @package    customfield_picture
 * @covers     ::customfield_picture_get_file
 * @covers     ::customfield_picture_pluginfile
 * @copyright  2022 Paul Holden <paulh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends advanced_testcase {
    /**
     * Load the plugin's procedural library once for the whole class
     *
     * customfield_picture_pluginfile() lives in lib.php, a plain procedural file the class
     * autoloader never touches, so it has to be required explicitly before any test calls it.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/customfield/field/picture/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * Store the fixture image at the coordinates the callback expects
     *
     * @param int $contextid
     * @param int $itemid The customfield_data row id.
     * @param string $filename
     * @return void
     */
    private function store_picture(int $contextid, int $itemid, string $filename = 'logo.png'): void {
        global $CFG;

        get_file_storage()->create_file_from_pathname([
            'contextid' => $contextid,
            'component' => 'customfield_picture',
            'filearea'  => 'file',
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => $filename,
        ], "{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png");
    }

    /**
     * Resolve a request the way the callback does, without sending anything
     *
     * customfield_picture_pluginfile() hands the resolution to customfield_picture_get_file()
     * and only adds send_stored_file(), which drains every output buffer and writes the bytes
     * to the real output - readfile_accel() in lib/filelib.php - so the success path cannot be
     * asserted in-process. The resolver returns the stored file to serve, or null to refuse.
     *
     * @param \context $context
     * @param string $filearea
     * @param array $args
     * @return \stored_file|null
     */
    private function resolve(\context $context, string $filearea, array $args): ?\stored_file {
        return customfield_picture_get_file($context, $filearea, $args);
    }

    /**
     * A field visible to everyone serves its picture to a user with no relationship to the course
     *
     * @return void
     */
    public function test_visibility_everyone_serves_to_unenrolled_user(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int) context_course::instance((int) $course->id)->id;

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category();
        $field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'configdata' => ['visibility' => 2],
        ]);
        $data = $generator->add_instance_data($field, (int) $course->id, 1);
        $dataid = (int) $data->get('id');
        $this->store_picture($contextid, $dataid);

        // A user with no enrolment and no role anywhere near this course.
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        $result = $this->resolve(context_course::instance((int) $course->id), 'file', [$dataid, 'logo.png']);
        $this->assertInstanceOf(\stored_file::class, $result);

        $file = get_file_storage()->get_file($contextid, 'customfield_picture', 'file', $dataid, '/', 'logo.png');
        $this->assertNotFalse($file);
        $this->assertSame(file_get_contents("{$CFG->dirroot}/lib/tests/fixtures/gd-logo.png"), $file->get_content());
    }

    /**
     * A field visible only to teachers gates on moodle/course:update, not on enrolment alone
     *
     * The student is the refusal under test; the editing teacher is the control that proves
     * the visibility check actually ran, since the same row and context that refuse the
     * student here go on to serve the teacher.
     *
     * @return void
     */
    public function test_visibility_teachers_restricts_by_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int) context_course::instance((int) $course->id)->id;

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category();
        $field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'configdata' => ['visibility' => 1],
        ]);
        $data = $generator->add_instance_data($field, (int) $course->id, 1);
        $dataid = (int) $data->get('id');
        $this->store_picture($contextid, $dataid);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $student->id, (int) $course->id, 'student');
        $this->setUser($student);

        $studentresult = $this->resolve(context_course::instance((int) $course->id), 'file', [$dataid, 'logo.png']);
        $this->assertNull($studentresult);

        // Control: the editing teacher must be served through the exact same row and context.
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $course->id, 'editingteacher');
        $this->setUser($teacher);

        $teacherresult = $this->resolve(context_course::instance((int) $course->id), 'file', [$dataid, 'logo.png']);
        $this->assertInstanceOf(\stored_file::class, $teacherresult);
    }

    /**
     * A field hidden from everyone refuses even the admin
     *
     * A second field in the same category, visible to everyone, is the control: the admin
     * must be served through it, which is what proves the first refusal came from the field's
     * own visibility setting and not from some blanket fault that refuses every request.
     *
     * @return void
     */
    public function test_visibility_nobody_refuses_everyone(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int) context_course::instance((int) $course->id)->id;

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category();

        $hidden = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'configdata' => ['visibility' => 0],
        ]);
        $hiddendata = $generator->add_instance_data($hidden, (int) $course->id, 1);
        $hiddendataid = (int) $hiddendata->get('id');
        $this->store_picture($contextid, $hiddendataid, 'hidden.png');

        $visible = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'configdata' => ['visibility' => 2],
        ]);
        $visibledata = $generator->add_instance_data($visible, (int) $course->id, 1);
        $visibledataid = (int) $visibledata->get('id');
        $this->store_picture($contextid, $visibledataid, 'visible.png');

        $context = context_course::instance((int) $course->id);

        $hiddenresult = $this->resolve($context, 'file', [$hiddendataid, 'hidden.png']);
        $this->assertNull($hiddenresult);

        // Control: the same admin, the same course, a field that allows viewing.
        $visibleresult = $this->resolve($context, 'file', [$visibledataid, 'visible.png']);
        $this->assertInstanceOf(\stored_file::class, $visibleresult);
    }

    /**
     * A data row is only served through its own course's context
     *
     * Requesting course A's row via course B's context is the refusal under test; requesting
     * the same row via its own context is the control, proving the mismatch itself is what
     * refused rather than something else about the row.
     *
     * @return void
     */
    public function test_wrong_context_refuses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $contextaid = (int) context_course::instance((int) $coursea->id)->id;

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category();
        $field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'configdata' => ['visibility' => 2],
        ]);
        $data = $generator->add_instance_data($field, (int) $coursea->id, 1);
        $dataid = (int) $data->get('id');
        $this->store_picture($contextaid, $dataid);

        $wrongresult = $this->resolve(context_course::instance((int) $courseb->id), 'file', [$dataid, 'logo.png']);
        $this->assertNull($wrongresult);

        // Control: the row's own context serves it.
        $rightresult = $this->resolve(context_course::instance((int) $coursea->id), 'file', [$dataid, 'logo.png']);
        $this->assertInstanceOf(\stored_file::class, $rightresult);
    }

    /**
     * A data row belonging to a field of another type is refused before any file lookup
     *
     * A picture-type field in the same category is the control, proving the type check is
     * what is being exercised rather than some coincidence of the fixture data.
     *
     * @return void
     */
    public function test_wrong_field_type_refuses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int) context_course::instance((int) $course->id)->id;

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category();

        $textfield = $generator->create_field(['categoryid' => $category->get('id'), 'type' => 'text']);
        $textdata = $generator->add_instance_data($textfield, (int) $course->id, 'not a picture');
        $textdataid = (int) $textdata->get('id');

        $picturefield = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'configdata' => ['visibility' => 2],
        ]);
        $picturedata = $generator->add_instance_data($picturefield, (int) $course->id, 1);
        $picturedataid = (int) $picturedata->get('id');
        $this->store_picture($contextid, $picturedataid);

        $context = context_course::instance((int) $course->id);

        $wrongtyperesult = $this->resolve($context, 'file', [$textdataid, 'logo.png']);
        $this->assertNull($wrongtyperesult);

        // Control: the picture field in the same category still serves.
        $righttyperesult = $this->resolve($context, 'file', [$picturedataid, 'logo.png']);
        $this->assertInstanceOf(\stored_file::class, $righttyperesult);
    }

    /**
     * A data id with no matching row is refused, not thrown
     *
     * A real row afterwards, at the same course and context, is the control, proving the
     * lookup mechanism itself works and the first call failed only because the id was absent.
     *
     * @return void
     */
    public function test_nonexistent_data_id_refuses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int) context_course::instance((int) $course->id)->id;

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category();
        $field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'configdata' => ['visibility' => 2],
        ]);
        $data = $generator->add_instance_data($field, (int) $course->id, 1);
        $dataid = (int) $data->get('id');
        $this->store_picture($contextid, $dataid);

        $context = context_course::instance((int) $course->id);
        $missingid = $dataid + 999999;

        // Reaching this line with no uncaught exception already proves the missing id did not throw.
        $missingresult = $this->resolve($context, 'file', [$missingid, 'logo.png']);
        $this->assertNull($missingresult);

        // Control: the real id, same course and context, is served.
        $realresult = $this->resolve($context, 'file', [$dataid, 'logo.png']);
        $this->assertInstanceOf(\stored_file::class, $realresult);
    }

    /**
     * Only the 'file' area is served; every other area name is refused
     *
     * The 'file' area on the same row is the control, proving the row itself was never the
     * problem.
     *
     * @return void
     */
    public function test_wrong_filearea_refuses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int) context_course::instance((int) $course->id)->id;

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category();
        $field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'configdata' => ['visibility' => 2],
        ]);
        $data = $generator->add_instance_data($field, (int) $course->id, 1);
        $dataid = (int) $data->get('id');
        $this->store_picture($contextid, $dataid);

        $context = context_course::instance((int) $course->id);

        $wrongarearesult = $this->resolve($context, 'draft', [$dataid, 'logo.png']);
        $this->assertNull($wrongarearesult);

        // Control: the 'file' area, same row, serves.
        $rightarearesult = $this->resolve($context, 'file', [$dataid, 'logo.png']);
        $this->assertInstanceOf(\stored_file::class, $rightarearesult);
    }

    /**
     * A row with no stored picture is refused, even though every other check would pass
     *
     * Storing the picture afterwards and repeating the same call is the control, proving the
     * missing file was the only reason for the first refusal.
     *
     * @return void
     */
    public function test_missing_stored_file_refuses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int) context_course::instance((int) $course->id)->id;

        /** @var core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category();
        $field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'picture',
            'configdata' => ['visibility' => 2],
        ]);
        $data = $generator->add_instance_data($field, (int) $course->id, 1);
        $dataid = (int) $data->get('id');

        $context = context_course::instance((int) $course->id);

        $nofileresult = $this->resolve($context, 'file', [$dataid, 'logo.png']);
        $this->assertNull($nofileresult);

        // Control: the same row, now with its picture stored.
        $this->store_picture($contextid, $dataid);
        $withfileresult = $this->resolve($context, 'file', [$dataid, 'logo.png']);
        $this->assertInstanceOf(\stored_file::class, $withfileresult);
    }

    /**
     * The callback itself answers a refusal with false and writes nothing
     *
     * @return void
     */
    public function test_pluginfile_refusal_returns_false_silently(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance((int) $course->id);

        $this->expectOutputString('');
        $this->assertFalse(customfield_picture_pluginfile($course, null, $context, 'file', [999999, 'logo.png'], false));
    }
}
