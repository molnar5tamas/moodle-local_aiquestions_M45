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
 * Story Form Class is defined here.
 *
 * @package     local_aiquestions
 * @category    admin
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Form to get the story from the user.
 *
 * @package     local_aiquestions
 * @category    admin
 */
class local_aiquestions_story_form extends moodleform {
    /**
     * Defines forms elements
     */
    public function definition() {
        global $courseid;
        $mform = $this->_form;

        // Question category.
        $contexts = [context_course::instance($courseid)];
        $mform->addElement('questioncategory', 'category', get_string('category', 'question'),
            array('contexts'=>$contexts));
        $mform->addHelpButton('category', 'category', 'local_aiquestions');

        // Number of questions.
        $defaultnumofquestions = 4;
        $select = $mform->addElement('select', 'numofquestions', get_string('numofquestions', 'local_aiquestions'),

        array('1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9, '10' => 10, '15' => 15, '20' => 20));

        $select->setSelected($defaultnumofquestions);
        $mform->setType('numofquestions', PARAM_INT);

        // Story.
        $mform->addElement('textarea', 'story', get_string('story', 'local_aiquestions'),
            'wrap="virtual" rows="10" cols="50"'); // This model's maximum context length is 4097 tokens. We limit the story to 4096 tokens.
        $mform->setType('story', PARAM_RAW);
        $mform->addHelpButton('story', 'story', 'local_aiquestions');

        // File upload (optional alternative/supplement to the story textarea).
        $mform->addElement('filepicker', 'storyfile', get_string('storyfile', 'local_aiquestions'), null,
            array(
                'maxbytes'  => 5242880, // 5 MB limit.
                'accepted_types' => array('.txt', '.pdf', '.docx', '.pptx'),
            )
        );

// MT_MT_MT_MT_MT_MT_MT_MT_MT_MT # # # # # # # # # # # #
//                'maxbytes'  => 1048576, // 1 MB limit.

        $mform->addHelpButton('storyfile', 'storyfile', 'local_aiquestions');

        // Add "AI-created" to question name.
        $mform->addElement('checkbox', 'addidentifier', get_string('addidentifier', 'local_aiquestions'));
        $mform->setDefault('addidentifier', 1); // Default of "yes"
        $mform->setType('addidentifier', PARAM_BOOL);

        // Add custom prefix
        $mform->addElement('text', 'customprefix', get_string('customprefix', 'local_aiquestions'), ['size' => '30']);
        $mform->setType('customprefix', PARAM_TEXT);
        $mform->setDefault('customprefix', '');
        $mform->addHelpButton('customprefix', 'customprefix', 'local_aiquestions');
		
        // Preset.
        $presets = array();
        for ($i = 0; $i < 10; $i++) {
            if ($presetname = get_config('local_aiquestions', 'presetname' . $i)) {
                $presets[] = $presetname;
            }
        }
        $mform->addElement('select', 'preset', get_string('preset', 'local_aiquestions'), $presets);

        // Edit preset.
        $mform->addElement('checkbox', 'editpreset', get_string('editpreset', 'local_aiquestions'));
        $mform->addElement('html', get_string('shareyourprompts', 'local_aiquestions'));

        // Create elements for all presets.
        for ($i = 0; $i < 10; $i++) {

            $primer = $i + 1;

            // Primer.
            $mform->addElement('textarea', 'primer' . $i, get_string('primer', 'local_aiquestions'),
                'wrap="virtual" rows="10" cols="50"');
            $mform->setType('primer' . $i, PARAM_RAW);
            $mform->setDefault('primer' . $i, get_config('local_aiquestions', 'presettprimer' . $primer));
            $mform->addHelpButton('primer' . $i, 'primer', 'local_aiquestions');
            $mform->hideif('primer' . $i, 'editpreset');
            $mform->hideif('primer' . $i, 'preset', 'neq', $i);

            // Instructions.
            $mform->addElement('textarea', 'instructions' . $i, get_string('instructions', 'local_aiquestions'),
            'wrap="virtual" rows="10" cols="50"');
            $mform->setType('instructions' . $i, PARAM_RAW);
            $mform->setDefault('instructions' . $i, get_config('local_aiquestions', 'presetinstructions' . $primer));
            $mform->addHelpButton('instructions' . $i, 'instructions', 'local_aiquestions');
            $mform->hideif('instructions' . $i, 'editpreset');
            $mform->hideif('instructions' . $i, 'preset', 'neq', $i);

            // Example.
            $mform->addElement('textarea', 'example' . $i, get_string('example', 'local_aiquestions'),
            'wrap="virtual" rows="10" cols="50"');
            $mform->setType('example' . $i, PARAM_RAW);
            $mform->setDefault('example' . $i, get_config('local_aiquestions', 'presetexample' . $primer));
            $mform->addHelpButton('example' . $i, 'example', 'local_aiquestions');
            $mform->hideif('example' . $i, 'editpreset');
            $mform->hideif('example' . $i, 'preset', 'neq', $i);

        }

        // Courseid.
        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        $buttonarray = array();
        $buttonarray[] =& $mform->createElement('submit', 'submitbutton', get_string('generate', 'local_aiquestions'));
        $buttonarray[] =& $mform->createElement('cancel', 'cancel', get_string('backtocourse', 'local_aiquestions'));
        $mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);
    }

    /**
     * Form validation — ensures the user provided either a story text or an uploaded file,
     * and checks if the character length exceeds the allowed context limit.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $USER, $CFG;
        $errors = parent::validation($data, $files);

        $storytext = trim($data['story'] ?? '');
        $hasstory  = !empty($storytext);
        $hasfile   = !empty($data['storyfile']);

        // 1. Check: Are both empty?
        if (!$hasstory && !$hasfile) {
            $errors['story']     = get_string('errornostory', 'local_aiquestions');
            $errors['storyfile'] = get_string('errornostory', 'local_aiquestions');
            return $errors;
        }

        // Maximum allowed number of characters (adjusted to the LiteLLM limit, minus the length of the prompt).
        // --context-length 65536  -> 160.000 - 200.000 characters

        $maxchars = 160000;

        // 2. Check: If you've typed too much into the text area.
        $currentlength = mb_strlen($storytext, 'UTF-8');
        if ($currentlength > $maxchars) {
            $errors['story'] = "A beküldött szöveg túl hosszú (" . number_format($currentlength, 0, ',', ' ') . " karakter), "
                . "ami meghaladja a megengedett " . number_format($maxchars, 0, ',', ' ') . " karakteres korlátot! "
                . "Küldjön kisebb részt.";
        }

        // 3. Verification: If you've uploaded a file (PDF / TXT / DOCX / PPTX), we'll also check the length of the extracted text.
        if ($hasfile) {
            $fs = get_file_storage();
            $usercontext = \context_user::instance($USER->id);
            $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $data['storyfile'], 'id', false);

            foreach ($draftfiles as $file) {
                $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                $extracted = '';

                if ($ext === 'txt') {
                    $extracted = $file->get_content();
                } else if ($ext === 'pdf') {
                    require_once($CFG->dirroot . '/local/aiquestions/locallib.php');
                    if (function_exists('extract_pdf_text')) {
                        $extracted = extract_pdf_text($file->get_id());
                    }
                } else if ($ext === 'docx' || $ext === 'pptx') {
                    $tempfile = tempnam($CFG->dataroot . '/temp/', 'moodle_office_');
                    $file->copy_content_to($tempfile);
                    $zip = new \ZipArchive();
                    if ($zip->open($tempfile) === true) {
                        if ($ext === 'docx') {
                            $xml = $zip->getFromName('word/document.xml');
                            if ($xml !== false) {
                                $extracted = strip_tags(str_replace('</w:p>', ' ', $xml));
                            }
                        } else if ($ext === 'pptx') {
                            for ($i = 1; $i <= 500; $i++) {
                                $xml = $zip->getFromName('ppt/slides/slide' . $i . '.xml');
                                if ($xml === false) {
                                    break;
                                }
                                $extracted .= ' ' . strip_tags(str_replace('</a:p>', ' ', $xml));
                            }
                        }
                        $zip->close();
                    }
                    @unlink($tempfile);
                }

                $filechars = mb_strlen($extracted, 'UTF-8');
                if (($currentlength + $filechars) > $maxchars) {
                    $errors['storyfile'] = "A fájlból kinyert szöveg túl hosszú (" . number_format($currentlength + $filechars, 0, ',', ' ') . " karakter), "
                        . "ami meghaladja a megengedett " . number_format($maxchars, 0, ',', ' ') . " karakteres korlátot! "
                        . "Küldjön kisebb részt.";
                }
            }
        }

        return $errors;
    }
}