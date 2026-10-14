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
 * Plugin version details
 *
 * @package    customfield_picture
 * @copyright  2022 Paul Holden <paulh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$plugin->component = 'customfield_picture';
$plugin->release   = '5.3';
$plugin->version   = 2026101400;
// Moodle 4.4 onwards: the custom field backup callbacks this plugin implements arrived there (MDL-79151).
$plugin->requires  = 2024042200;
$plugin->maturity  = MATURITY_STABLE;
// The branches this fork is tested on: the fleet's 4.5 LTS, 5.1 and 5.2 stacks.
$plugin->supported = [405, 503];
