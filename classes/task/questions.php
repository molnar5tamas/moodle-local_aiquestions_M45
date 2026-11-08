<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Adhoc task for questions generation.
 *
 * @package     local_aiquestions
 * @category    admin
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquestions\task;

defined('MOODLE_INTERNAL') || die();

/**
 * The question generator adhoc task.
 *
 * @package     local_aiquestions
 * @category    admin
 */
class questions extends \core\task\adhoc_task {
    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute() {
        global $DB, $CFG;
        require_once(__DIR__ . '/../../locallib.php');

        // Read numoftries from settings (at least 1 try).
        $numoftries = max(1, (int) get_config('local_aiquestions', 'numoftries'));

        // Get the data from the task.
        $data = $this->get_custom_data();
        $courseid = $data->courseid;
        $category = $data->category;
        $userid = $data->userid;
        $uniqid = $data->uniqid;
        $numofquestions = $data->numofquestions;
        $addidentifier = $data->addidentifier;
        $customprefix = $data->customprefix ?? '';

        // Create the DB entry.
        $dbrecord = new \stdClass();
        $dbrecord->course = $courseid;
        $dbrecord->numoftries = $numoftries;
        $dbrecord->userid = $userid;
        $dbrecord->timecreated = time();
        $dbrecord->timemodified = time();
        $dbrecord->tries = 0;
        $dbrecord->uniqid = $uniqid;
        $dbrecord->gift = '';
        $dbrecord->success = '';
        $inserted = $DB->insert_record('local_aiquestions', $dbrecord);

        $created = false;     // Array of created questions on success.
        $tries = 0;           // Number of tries made so far.
        $lasterror = '';      // Last error message (stored in DB when everything fails).

        echo "[local_aiquestions] Creating questions...\n";

        while (!$created && $tries < $numoftries) {
            $tries++;
            echo "[local_aiquestions] Try $tries of $numoftries...\n";

            // First update DB on tries, so the UI can show the progress.
            $DB->update_record('local_aiquestions', (object) [
                'id' => $inserted,
                'tries' => $tries,
                'timemodified' => time(),
            ]);

            // One failing try (even a PHP exception) must never kill the whole task.
            try {
                $questions = \local_aiquestions_get_questions($data);

                // Error returned by the AI API.
                if (isset($questions->error)) {
                    $lasterror = (string) $questions->error;
                    echo "[local_aiquestions] API error: $lasterror\n";

                    // A 400 error (e.g. text too long) will not get better by retrying.
                    $errorcode = $questions->errorcode ?? '';
                    if ($errorcode === 'badrequest' || preg_match('/^400\b/', $lasterror)) {
                        echo "[local_aiquestions] Non-retryable error, stopping.\n";
                        break;
                    }
                    continue;
                }

                if (!property_exists($questions, 'text') || trim((string) $questions->text) === '') {
                    $lasterror = 'No question text returned.';
                    echo "[local_aiquestions] Error: $lasterror\n";
                    continue;
                }

                // Check gift format.
                if (!\local_aiquestions_check_gift($questions->text)) {
                    $lasterror = 'The AI output did not contain valid GIFT questions.';
                    echo "[local_aiquestions] Error: $lasterror\n";
                    continue;
                }

                // Create the questions, returns an array of objects of the created questions (or false).
                $result = \local_aiquestions_create_questions(
                    $courseid,
                    $category,
                    $questions->text,
                    $numofquestions,
                    $userid,
                    $addidentifier,
                    $customprefix
                );

                if (empty($result)) {
                    $lasterror = 'The GIFT was valid, but no question could be saved.';
                    echo "[local_aiquestions] Error: $lasterror\n";
                    continue;
                }

                $success = [];
                foreach ($result as $question) {
                    $success[] = [
                        'id' => $question->id,
                        'questiontext' => $question->questiontext,
                    ];
                }
                $created = $result;

                echo "[local_aiquestions] Successfully created " . count($success) . " questions!\n";

                // Insert success creation info to DB.
                $DB->update_record('local_aiquestions', (object) [
                    'id' => $inserted,
                    'gift' => $questions->text,
                    'tries' => $tries,
                    'success' => json_encode($success),
                    'timemodified' => time(),
                ]);
            } catch (\Throwable $e) {
                $lasterror = get_class($e) . ': ' . $e->getMessage();
                echo "[local_aiquestions] Exception on try $tries: $lasterror\n";
                // Do not retry on exceptions thrown by our own code paths forever: loop is bounded by numoftries.
            }
        }

        // If questions were not created, mark the request as failed.
        if (!$created) {
            $DB->update_record('local_aiquestions', (object) [
                'id' => $inserted,
                'tries' => $tries,
                'timemodified' => time(),
                'success' => 0,
                // The 'gift' column is not used on failure, so it carries the reason of the failure.
                'gift' => $lasterror,
            ]);
            echo "[local_aiquestions] Generation failed after $tries tries. Last error: $lasterror\n";
        }
    }
}