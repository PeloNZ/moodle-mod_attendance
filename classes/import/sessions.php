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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.
namespace mod_attendance\import;

use csv_import_reader;
use mod_attendance_notifyqueue;
use mod_attendance_structure;
use stdClass;

/**
 * Import attendance sessions.
 *
 * @package mod_attendance
 * @author Chris Wharton <chriswharton@catalyst.net.nz>
 * @copyright 2017 Catalyst IT
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sessions
{

    /** @var string $error The errors message from reading the xml */
    protected $error = '';

    /** @var array $sessions The sessions info */
    protected $sessions = array();

    protected $mappings = array();

    protected $importid = 0;

    protected $importer = null;

    protected $foundheaders = array();

    /** @var bool $useprogressbar Control whether importing should use progress bars or not. */
    protected $useprogressbar = false;

    /** @var \core\progress\display_if_slow|null $progress The progress bar instance. */
    protected $progress = null;

    /**
     * Store an error message for display later
     *
     * @param string $msg
     */
    public function fail($msg)
    {
        $this->error = $msg;
        return false;
    }

    /**
     * Get the CSV import id
     *
     * @return string The import id.
     */
    public function get_importid()
    {
        return $this->importid;
    }

    /**
     * Get the list of headers required for import.
     *
     * @return array The headers (lang strings)
     */
    public static function list_required_headers()
    {
        return array(
            get_string('course', 'attendance'),
            get_string('groups', 'attendance'),
            get_string('sessiondate', 'attendance'),
            get_string('from', 'attendance'),
            get_string('to', 'attendance'),
            get_string('description', 'attendance'),
            get_string('repeaton', 'attendance'),
            get_string('repeatevery', 'attendance'),
            get_string('repeatuntil', 'attendance'),
            get_string('studentscanmark', 'attendance'),
            get_string('passwordgrp', 'attendance'),
            get_string('randompassword', 'attendance'),
            get_string('subnet', 'attendance')
        );
    }

    /**
     * Get the list of headers found in the import.
     *
     * @return array The found headers (names from import)
     */
    public function list_found_headers()
    {
        return $this->foundheaders;
    }

    /**
     * Read the data from the mapping form.
     *
     * @param
     *            data array The mapping data.
     */
    protected function read_mapping_data($data)
    {
        if ($data) {
            return array(
                'course' => $data->header0,
                'groups' => $data->header1,
                'sessiondate' => $data->header2,
                'from' => $data->header3,
                'to' => $data->header4,
                'description' => $data->header5,
                'repeaton' => $data->header6,
                'repeatevery' => $data->header7,
                'repeatuntil' => $data->header8,
                'studentscanmark' => $data->header9,
                'passwordgrp' => $data->header10,
                'randompassword' => $data->header11,
                'subnet' => $data->header12
            );
        } else {
            return array(
                'course' => 0,
                'groups' => 1,
                'sessiondate' => 2,
                'from' => 3,
                'to' => 4,
                'description' => 5,
                'repeaton' => 6,
                'repeatevery' => 7,
                'repeatuntil' => 8,
                'studentscanmark' => 9,
                'passwordgrp' => 10,
                'randompassword' => 11,
                'subnet' => 12
            );
        }
    }

    /**
     * Get the a column from the imported data.
     *
     * @param
     *            array The imported raw row
     * @param
     *            index The column index we want
     * @return string The column data.
     */
    protected function get_column_data($row, $index)
    {
        if ($index < 0) {
            return '';
        }
        return isset($row[$index]) ? $row[$index] : '';
    }

    /**
     * Constructor - parses the raw text for sanity.
     *
     * @param string $text
     *            The raw csv text.
     * @param string $encoding
     *            The encoding of the csv file.
     * @param
     *            string delimiter The specified delimiter for the file.
     * @param
     *            string importid The id of the csv import.
     * @param
     *            array mappingdata The mapping data from the import form.
     * @param bool $useprogressbar
     *            Whether progress bar should be displayed, to avoid html output on CLI.
     */
    public function __construct($text = null, $encoding = null, $delimiter = null, $importid = 0, $mappingdata = null, $useprogressbar = false)
    {
        global $CFG;

        require_once ($CFG->libdir . '/csvlib.class.php');

        $type = 'sessions';

        if (! $importid) {
            if ($text === null) {
                return;
            }
            $this->importid = csv_import_reader::get_new_iid($type);

            $this->importer = new csv_import_reader($this->importid, $type);

            if (! $this->importer->load_csv_content($text, $encoding, $delimiter)) {
                $this->fail(get_string('invalidimportfile', 'attendance'));
                $this->importer->cleanup();
                return;
            }
        } else {
            $this->importid = $importid;

            $this->importer = new csv_import_reader($this->importid, $type);
        }

        if (! $this->importer->init()) {
            $this->fail(get_string('invalidimportfile', 'attendance'));
            $this->importer->cleanup();
            return;
        }

        $this->foundheaders = $this->importer->get_columns();
        $this->useprogressbar = $useprogressbar;
        $domainid = 1;

        $sessions = array();

        while ($row = $this->importer->next()) {
            // This structure mimics what the UI form returns.
            $mapping = $this->read_mapping_data($mappingdata);

            $session = new stdClass();
            $session->course = $this->get_column_data($row, $mapping['course']);

            // Handle multiple group assignments per session. Expect semicolon separated group names.
            $groups = $this->get_column_data($row, $mapping['groups']);
            if (! empty($groups)) {
                $session->groups = explode(';', $groups);
                $session->sessiontype = \mod_attendance_structure::SESSION_GROUP;
            } else {
                $session->sessiontype = \mod_attendance_structure::SESSION_COMMON;
            }

            // Expect standardised date format, eg YYYY-MM-DD.
            $sessiondate = strtotime($this->get_column_data($row, $mapping['sessiondate']));
            $session->sessiondate = $sessiondate;

            // Expect standardised time format, eg HH:MM.
            $from = explode(':', $this->get_column_data($row, $mapping['from']));
            $session->sestime['starthour'] = $from[0];
            $session->sestime['startminute'] = $from[1];

            $to = explode(':', $this->get_column_data($row, $mapping['to']));
            $session->sestime['endhour'] = $to[0];
            $session->sestime['endminute'] = $to[1];

            // Wrap the plain text description in html tags.
            $session->sdescription['text'] = '<p>' . $this->get_column_data($row, $mapping['description']) . '</p>';
            $session->sdescription['format'] = FORMAT_HTML;
            $session->sdescription['itemid'] = 0;

            $session->repeaton = $this->get_column_data($row, $mapping['repeaton']);
            $session->repeatevery = $this->get_column_data($row, $mapping['repeatevery']);
            $session->repeatuntil = $this->get_column_data($row, $mapping['repeatuntil']);
            $session->studentscanmark = $this->get_column_data($row, $mapping['studentscanmark']);
            $session->passwordgrp = $this->get_column_data($row, $mapping['passwordgrp']);
            $session->randompassword = $this->get_column_data($row, $mapping['randompassword']);

            // Set session subnet restriction. Use the default activity level subnet if there isn't one set for this session.
            $session->subnet = $this->get_column_data($row, $mapping['subnet']);
            if (empty($session->subnet)) {
                $session->usedefaultsubnet = '1';
            } else {
                $session->usedefaultsubnet = '';
            }

            $session->statusset = 0;

            $sessions[] = $session;
        }
        $this->sessions = $sessions;

        $this->importer->close();
        if ($this->sessions == null) {
            $this->fail(get_string('invalidimportfile', 'attendance'));
            return;
        } else {
            // We are calling from browser, display progress bar.
            if ($this->useprogressbar === true) {
                $this->progress = new \core\progress\display_if_slow(get_string('processingfile', 'attendance'));
                $this->progress->start_html();
            } else {
                // Avoid html output on CLI scripts.
                $this->progress = new \core\progress\none();
            }
            $this->progress->start_progress('', count($this->sessions));
            raise_memory_limit(MEMORY_EXTRA);
            $this->progress->end_progress();
        }
    }

    /**
     * Get parse errors.
     *
     * @return array of errors from parsing the xml.
     */
    public function get_error()
    {
        return $this->error;
    }

    /**
     * Create sessions using the CSV data.
     *
     * @return void
     */
    public function import()
    {
        global $DB;

        // Count of sessions added.
        $okcount = 0;

        foreach ($this->sessions as $session) {
            // Check course shortname matches.
            if ($DB->record_exists('course', array(
                'shortname' => $session->course
            ))) {
                // Get course.
                $course = $DB->get_record('course', array(
                    'shortname' => $session->course
                ), '*', MUST_EXIST);

                // Check course has activities.
                if ($DB->record_exists('attendance', array(
                    'course' => $course->id
                ))) {
                    // Translate group names to group IDs. They are unique per course.
                    if ($session->sessiontype === \mod_attendance_structure::SESSION_GROUP) {
                        foreach ($session->groups as $groupname) {
                            $gid = groups_get_group_by_name($course->id, $groupname);
                            if ($gid === false) {
                                \mod_attendance_notifyqueue::notify_problem(get_string('sessionunknowngroup', 'attendance', $groupname));
                            } else {
                                $groupids[] = $gid;
                            }
                        }
                        $session->groups = $groupids;
                    }

                    // Get activities in course.
                    $activities = $DB->get_recordset('attendance', array(
                        'course' => $course->id
                    ), 'id', 'id');

                    foreach ($activities as $activity) {
                        $cm = get_coursemodule_from_instance('attendance', $activity->id, $course->id);
                        $att = new mod_attendance_structure($activity, $cm, $course);

                        $sessions = attendance_construct_sessions_data_for_add($session, $att);
                        foreach ($sessions as $index => $sess) {
                            // Check for duplicate sessions.
                            if ($this->session_exists($sess)) {
                                mod_attendance_notifyqueue::notify_message(get_string('sessionduplicate', 'attendance', (array(
                                    'course' => $session->course,
                                    'activity' => $cm->name
                                ))));
                                unset($sessions[$index]);
                            } else {
                                $okcount ++;
                            }
                        }
                        if (! empty($sessions)) {
                            $att->add_sessions($sessions);
                        }
                    }
                    $activities->close();
                } else {
                    mod_attendance_notifyqueue::notify_problem(get_string('error:coursehasnoattendance', 'attendance', $session->course));
                }
            } else {
                mod_attendance_notifyqueue::notify_problem(get_string('error:coursenotfound', 'attendance', $session->course));
            }
        }
        $message = get_string('sessionsgenerated', 'attendance', $okcount);

        if ($okcount < 1) {
            mod_attendance_notifyqueue::notify_message($message);
        } else {
            mod_attendance_notifyqueue::notify_success($message);
        }

        // Trigger a sessions imported event.
        $event = \mod_attendance\event\sessions_imported::create(array(
            'objectid' => 0,
            'context' => \context_system::instance(),
            'other' => array(
                'count' => $okcount
            )
        ));

        $event->trigger();
    }

    /**
     * Check if an identical session exists.
     *
     * @param stdClass $session
     * @return boolean
     */
    private function session_exists(stdClass $session)
    {
        global $DB;

        $check = clone $session;

        // Remove the properties that aren't useful to check.
        unset($check->description);
        unset($check->descriptionitemid);
        unset($check->timemodified);
        $check = (array) $check;

        if ($DB->record_exists('attendance_sessions', $check)) {
            return true;
        }
        return false;
    }
}

