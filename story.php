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
 * Plugin administration pages are defined here.
 *
 * @package     local_aiquestions
 * @category    admin
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require(__DIR__ . '/../../config.php');
defined('MOODLE_INTERNAL') || die();
// Get course id for creating the questions in it's bank.
$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid == 0) {
    redirect(new moodle_url('/local/aiquestions/index.php'));
}
require_login($courseid);
// Check if the user has the capability to create questions.
$context = context_course::instance($courseid);
require_capability('moodle/question:add', $context);
require_once("$CFG->libdir/formslib.php");
require_once(__DIR__ . '/locallib.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_heading(get_string('pluginname', 'local_aiquestions'));
$PAGE->set_title(get_string('pluginname', 'local_aiquestions'));
$PAGE->set_url('/local/aiquestions/story.php?courseid=' . $courseid);
$PAGE->set_pagelayout('standard');
$PAGE->navbar->add(get_string('pluginname', 'local_aiquestions'), new moodle_url('/local/aiquestions/'));
$PAGE->navbar->add(get_string('story', 'local_aiquestions'),
                    new moodle_url('/local/aiquestions/story.php?courseid=' . $courseid));
$PAGE->requires->js_call_amd('local_aiquestions/state');
echo $OUTPUT->header();
$mform = new local_aiquestions_story_form();
if ($mform->is_cancelled()) {
    redirect($CFG->wwwroot . '/course/view.php?id=' . $courseid);
} else if ($data = $mform->get_data()) {

    // --- File upload handling ---
    // If the user uploaded a file, read its text content and append it to the story.
    $storyText = $data->story ?? '';

    $draftitemid = file_get_submitted_draft_itemid('storyfile');
    if (!empty($draftitemid)) {
        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);

        // Retrieve all files from the draft area (exclude directories).
        $draftfiles = $fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'id',
            false   // Exclude directories.
        );

        foreach ($draftfiles as $draftfile) {
            $filename  = $draftfile->get_filename();
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if ($extension === 'txt') {
                // Plain text: read directly, then normalise to clean UTF-8.
                $filecontent = $draftfile->get_content();

                if (strncmp($filecontent, "\xEF\xBB\xBF", 3) === 0) {
                    $filecontent = substr($filecontent, 3);                  // UTF-8 BOM.
                } else if (strncmp($filecontent, "\xFF\xFE", 2) === 0) {
                    $filecontent = mb_convert_encoding(substr($filecontent, 2), 'UTF-8', 'UTF-16LE');
                } else if (strncmp($filecontent, "\xFE\xFF", 2) === 0) {
                    $filecontent = mb_convert_encoding(substr($filecontent, 2), 'UTF-8', 'UTF-16BE');
                } else if (!mb_check_encoding($filecontent, 'UTF-8')) {
                    $filecontent = \core_text::convert($filecontent, 'Windows-1250', 'UTF-8'); // Magyar ANSI.
                }
                $filecontent = mb_convert_encoding($filecontent, 'UTF-8', 'UTF-8');   // Maradék hibás bájtok.
                $filecontent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $filecontent);

            } else if ($extension === 'pdf') {
                // PDF: extract text via pdftotext if available on the server.
                $tmppath = $draftfile->copy_content_to_temp();
                $filecontent = shell_exec('pdftotext ' . escapeshellarg($tmppath) . ' -');
                @unlink($tmppath);
                if ($filecontent === null) {
                    // pdftotext not available — skip and warn.
                    debugging('pdftotext not available; PDF content could not be extracted.', DEBUG_DEVELOPER);
                    $filecontent = '';
                }

            } else if ($extension === 'docx') {
                // DOCX: extract paragraphs from word/document.xml using a real XML parser.
                $tmppath = $draftfile->copy_content_to_temp('aiquestions', '.docx');
                $zip = new ZipArchive();
                $filecontent = '';
                if ($zip->open($tmppath) === true) {
                    $xml = $zip->getFromName('word/document.xml');
                    $zip->close();
                    if ($xml !== false) {
                        $dom = new DOMDocument();
                        $prev = libxml_use_internal_errors(true);
                        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
                        libxml_clear_errors();
                        libxml_use_internal_errors($prev);

                        if ($loaded) {
                            $xpath = new DOMXPath($dom);
                            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

                            $paragraphs = [];
                            foreach ($xpath->query('//w:p') as $p) {
                                $text = '';
                                // Walk text runs, tabs and line breaks in document order.
                                foreach ($xpath->query('.//w:t | .//w:tab | .//w:br', $p) as $node) {
                                    if ($node->localName === 't') {
                                        $text .= $node->textContent;
                                    } else if ($node->localName === 'tab') {
                                        $text .= "\t";
                                    } else {
                                        $text .= "\n";
                                    }
                                }
                                if (trim($text) !== '') {
                                    $paragraphs[] = $text;
                                }
                            }
                            $filecontent = implode("\n\n", $paragraphs);
                        }
                    }
                }
                @unlink($tmppath);

            } else if ($extension === 'pptx') {
                // PPTX: unzip and extract text from ppt/slides/slide*.xml.
                // Each slide's text runs (<a:t>) are collected in slide order.
                $tmppath = $draftfile->copy_content_to_temp('aiquestions', '.pptx');
                $zip = new ZipArchive();
                $filecontent = '';
                if ($zip->open($tmppath) === true) {
                    // Collect slide filenames and sort them numerically (slide1, slide2 …).
                    $slidefiles = [];
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entry = $zip->getNameIndex($i);
                        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $entry, $m)) {
                            $slidefiles[(int)$m[1]] = $entry;
                        }
                    }
                    ksort($slidefiles);

                    $slidetexts = [];
                    foreach ($slidefiles as $slidenum => $slidepath) {
                        $xml = $zip->getFromName($slidepath);
                        if ($xml === false) {
                            continue;
                        }
                        // Insert newline before each paragraph tag so paragraphs don't merge.
                        $xml = preg_replace('/<a:p[ >]/', "\n<a:p>", $xml);
                        // Insert space between adjacent text runs so words don't merge.
                        $xml = preg_replace('/<\/a:t>\s*<a:t[^>]*>/', ' ', $xml);
                        $slidetext = trim(strip_tags($xml));
                        $slidetext = html_entity_decode($slidetext, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        // Collapse runs of blank lines to a single blank line.
                        $slidetext = preg_replace("/(\r?\n){3,}/", "\n\n", $slidetext);
                        if ($slidetext !== '') {
                            $slidetexts[] = "## Slide {$slidenum}\n" . $slidetext;
                        }
                    }
                    $zip->close();
                    $filecontent = implode("\n\n", $slidetexts);
                }
                @unlink($tmppath);

            } else {
                // Unsupported type — skip.
                $filecontent = '';
            }

            $filecontent = trim($filecontent);
            if ($filecontent !== '') {
                // Append file content to whatever was typed in the textarea.
                $storyText = trim($storyText) !== ''
                    ? $storyText . "\n\n" . $filecontent
                    : $filecontent;
            }
        }
    }
    // --- End file upload handling ---

    // Call the adhoc task.
    $task = new \local_aiquestions\task\questions();
    if ($task) {
        $uniqid = uniqid($USER->id, true);
        $preset = $data->preset;
        $primer = 'primer' . $preset;
        $instructions = 'instructions' . $preset;
        $example = 'example' . $preset;
        $task->set_custom_data(['category' => $data->category,
                                'primer' => $data->$primer,
                                'instructions' => $data->$instructions,
                                'example' => $data->$example,
                                'story' => $storyText,      // <-- merged textarea + file content
                                'numofquestions' => $data->numofquestions,
                                'addidentifier' => $data->addidentifier,
                                'customprefix' => $data->customprefix ?? '',
                                'courseid' => $data->courseid,
                                'userid' => $USER->id,
                                'uniqid' => $uniqid ]);
        \core\task\manager::queue_adhoc_task($task);
        $success = get_string('tasksuccess', 'local_aiquestions');
    } else {
        $error = get_string('taskerror', 'local_aiquestions');
    }
    // Check if the cron is overdue.
    $lastcron = get_config('tool_task', 'lastcronstart');
    $cronoverdue = ($lastcron < time() - 3600 * 24);
    // Prepare the data for the template.
    $datafortemplate = [
        'courseid' => $courseid,
        'wwwroot' => $CFG->wwwroot,
        'uniqid' => $uniqid,
        'userid' => $USER->id,
        'cron' => $cronoverdue,
    ];
    // Load the ready template.
    echo $OUTPUT->render_from_template('local_aiquestions/loading', $datafortemplate);

    echo html_writer::div('', 'd-none', [
        'id' => 'local_aiquestions_courseid',
        'data-courseid' => $courseid,
    ]);

    // GIFT download button (enabled when the generation has finished successfully).
    $downloadurl = new moodle_url('/local/aiquestions/download.php', ['uniqid' => $uniqid]);
    $checkurl    = new moodle_url('/local/aiquestions/download.php', ['uniqid' => $uniqid, 'check' => 1]);
    echo html_writer::div(
        html_writer::link($downloadurl, get_string('downloadgift', 'local_aiquestions'), [
            'id' => 'aiq-download-gift',
            'class' => 'btn btn-secondary disabled',
            'aria-disabled' => 'true',
            'tabindex' => '-1',
        ]),
        'mt-3'
    );
    echo html_writer::script("
(function() {
    var btn = document.getElementById('aiq-download-gift');
    var checkurl = " . json_encode($checkurl->out(false)) . ";
    var timer = setInterval(function() {
        fetch(checkurl, {credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.status === 'ready') {
                    btn.classList.remove('disabled', 'btn-secondary');
                    btn.classList.add('btn-primary');
                    btn.removeAttribute('aria-disabled');
                    btn.removeAttribute('tabindex');
                    clearInterval(timer);
                } else if (d.status === 'failed') {
                    clearInterval(timer);
                }
            })
            .catch(function() {});
    }, 5000);
})();
");

} else {
    $mform->display();
}
echo $OUTPUT->footer();
