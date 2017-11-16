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
            get_string('sessiontype', 'attendance'),
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
            get_string('requiresubnet', 'attendance')
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
                'sessiontype' => $data->header1,
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
                'requiresubnet' => $data->header12
            );
        } else {
            return array(
                'course' => 0,
                'sessiontype' => 1,
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
                'requiresubnet' => 12
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
            $session->sessiontype = $this->get_column_data($row, $mapping['sessiontype']);

            $sessiondate = strtotime($this->get_column_data($row, $mapping['sessiondate']));
            $session->sessiondate = $sessiondate;

            $from = explode(':', $this->get_column_data($row, $mapping['from']));
            $session->sestime['starthour'] = $from[0];
            $session->sestime['startminute'] = $from[1];

            $to = explode(':', $this->get_column_data($row, $mapping['to']));
            $session->sestime['endhour'] = $to[0];
            $session->sestime['endminute'] = $to[1];

            $session->sdescription['text'] = $this->get_column_data($row, $mapping['description']);
            $session->sdescription['format'] = FORMAT_HTML;
            $session->sdescription['itemid'] = 0;

            $session->repeaton = $this->get_column_data($row, $mapping['repeaton']);
            $session->repeatevery = $this->get_column_data($row, $mapping['repeatevery']);
            $session->repeatuntil = $this->get_column_data($row, $mapping['repeatuntil']);
            $session->studentscanmark = $this->get_column_data($row, $mapping['studentscanmark']);
            $session->passwordgrp = $this->get_column_data($row, $mapping['passwordgrp']);
            $session->randompassword = $this->get_column_data($row, $mapping['randompassword']);
            $session->requiresubnet = $this->get_column_data($row, $mapping['requiresubnet']);

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
                    // Get activities in course.
                    $activities = $DB->get_recordset('attendance', array(
                        'course' => $course->id
                    ), 'id', 'id');

                    foreach ($activities as $activity) {
                        $cm = get_coursemodule_from_instance('attendance', $activity->id, $course->id);
                        $att = new mod_attendance_structure($activity, $cm, $course);

                        $sessions = attendance_construct_sessions_data_for_add($session, $att);
                        $att->add_sessions($sessions);
                        if (count($sessions) == 1) {
                            $message = get_string('sessiongenerated', 'attendance');
                        } else {
                            $message = get_string('sessionsgenerated', 'attendance', count($sessions));
                        }

                        mod_attendance_notifyqueue::notify_success($message);
                    }
                    $activities->close();
                } else {
                    mod_attendance_notifyqueue::notify_problem(get_string('error:coursehasnoattendance', 'attendance', $session->course));
                }
            } else {
                mod_attendance_notifyqueue::notify_problem(get_string('error:coursenotfound', 'attendance', $session->course));
            }
        }
    }
}

