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


/**
 * Get questions from the API using Moodle's AI subsystem.
 *
 * @param object data data to create questions from
 * @return object questions of generated questions
 */
function local_aiquestions_get_questions($data) {

    global $CFG, $USER;

    // Build primer.
    $primer = $data->primer;
    $primer .= " Write $data->numofquestions questions.";

    // Remove new lines and carriage returns.
    $story = str_replace("\n", " ", $data->story);
    $story = str_replace("\r", " ", $story);
    $instructions = str_replace("\n", " ", $data->instructions);
    $instructions = str_replace("\r", " ", $instructions);
    $example = str_replace("\n", " ", $data->example);
    $example = str_replace("\r", " ", $example);

    // Build the complete prompt for Moodle's AI subsystem.
    $prompttext = $primer . "\n\n";
    $prompttext .= "Instructions: " . $instructions . "\n\n";
    $prompttext .= "Example: " . $example . "\n\n";

// MT_MT_MT_MT_MT_MT_MT_MT_MT_MT # # # # # # # # # # # #
    // $prompttext .= "Now, create " . $data->numofquestions . " questions for me based on this topic: " . $story;

    $prompttext .= "Create " . $data->numofquestions . " questions in the GIFT format based on this provided content: <content>\n"
    . $story . "\n</content>\n\nREMINDER: Write your output in the SAME language as the content above.";

    // A stricter formatting guide for the model: this will result in fewer incorrect GIFT entries.
    $prompttext .= "\n\nOUTPUT FORMAT RULES: Output ONLY the GIFT questions, no introduction, no explanation, no markdown and no code fences. "
        . "Do not include question titles and never use the ::characters. Start every question with the question text. "
        . "Separate questions with exactly ONE blank line. Every question must have exactly ONE correct answer (=) and exactly THREE wrong answers (~). "
        . "Do not use the characters ~ = # { } : inside the question text or answers unless they are escaped with a backslash.";

// MT_MT_MT_MT_MT_MT_MT_MT_MT_MT # # # # # # # # # # # #
// file_put_contents(__DIR__ . '/prompttext.txt', print_r($prompttext, true));
// MT_MT_MT_MT_MT_MT_MT_MT_MT_MT # # # # # # # # # # # #

    // Get the context - use system context if no specific context is available.
    $contextid = context_system::instance()->id;

    // Use Moodle's AI subsystem to generate questions.
    $aianswer = new \local_aiquestions\sendtoai();
    $result = $aianswer->execute($contextid, $prompttext);

    $questions = new stdClass(); // The questions object.
    if ($result['success']) {
        $questions->text = $result['generatedcontent'];
        $questions->prompt = $story;
    } else {
        // Handle error case.
        $questions = new stdClass();
        $questions->error = $result['error'] ?? 'Unknown error occurred';
        $questions->errorcode = $result['errorcode'] ?? 0;
        $questions->prompt = $story;
    }
    return $questions;
}

// MT_MT_MT_MT_MT_MT_MT_MT_MT_MT # # # # # # # # # # # #
//  helper functions for robust GIFT processing

/**
 * Normalize the raw AI output: line endings, code fences, BOM, whitespace-only lines.
 * @param string $gift raw output
 * @return string normalized text
 */
 
function local_aiquestions_normalize_gift($gift) {
    $gift = (string) $gift;
    $gift = preg_replace('/^\xEF\xBB\xBF/', '', $gift);            // UTF-8 BOM.
    $gift = str_replace(["\r\n", "\r"], "\n", $gift);              // Egységes sortörés.
    $gift = preg_replace('/^[ \t]*```[a-zA-Z]*[ \t]*$/m', '', $gift); // Markdown kerítés (```gift).
    $gift = preg_replace('/^[ \t]*\/\/.*$/m', '', $gift);          // GIFT megjegyzés sorok.
    $gift = preg_replace('/^[ \t]+$/m', '', $gift);                // Csak szóközt tartalmazó sorok.
    $gift = preg_replace("/\n{3,}/", "\n\n", $gift);               // Többszörös üres sor -> egy.

// file_put_contents(__DIR__ . '/gift1.txt', print_r($gift, true));

    return trim($gift);
}

/**
 * Split the normalized GIFT text into separate question blocks.
 * @param string $gift raw or normalized GIFT text
 * @return array of strings, one per question candidate
 */

function local_aiquestions_split_gift($gift) {
    $gift = local_aiquestions_normalize_gift($gift);
    if ($gift === '') {
        return [];
    }
    if (preg_match('/^::/m', $gift)) {
        $parts = preg_split('/^(?=::)/m', $gift, -1, PREG_SPLIT_NO_EMPTY);
    } else {
        $parts = preg_split('/\n{2,}/', $gift, -1, PREG_SPLIT_NO_EMPTY);
    }
    $blocks = [];
    foreach ($parts as $part) {
        // Üres sorok eltávolítása a blokkon belül, sorok trimmelése.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $part)), 'strlen'));
        if ($lines) {
            $blocks[] = implode("\n", $lines);
        }
    }

// file_put_contents(__DIR__ . '/gift2.txt', print_r($gift, true));

    return $blocks;
}

/**
 * Mask backslash-escaped characters with 'x' (same byte length), so that structural characters ({ } ~ = #) can be found without false hits.
 * @param string $text text
 * @return string masked text of identical length
 */
 
function local_aiquestions_mask_escaped($text) {
    $masked = preg_replace_callback('/\\\\./su', function ($m) {
        return str_repeat('x', strlen($m[0]));
    }, $text);
    return $masked === null ? $text : $masked;
}

/**
 * Joint question text extraction. Both the validation and the save functions use this, so whatever passes validation will have the same saved text.
 * @param string $block one question
 * @return string question text (empty string if not found)
 */
 
function local_aiquestions_extract_question_text($block) {
    $flat = trim(preg_replace('/\s*\n\s*/', ' ', $block));
    $safe = local_aiquestions_mask_escaped($flat);

    $offset = 0;
    if (preg_match('/^::(.+?)::/su', $safe, $m)) {
        $offset = strlen($m[0]);
    }
    $open = strpos($safe, '{', $offset);
    $close = strrpos($safe, '}');
    if ($open === false || $close === false || $close < $open) {
        return '';
    }

    // Text before { and after }.
    $text = trim(substr($flat, $offset, $open - $offset) . ' ' . substr($flat, $close + 1));
    // Trim format tags: [html], [markdown], [plain], [moodle].
    $text = preg_replace('/^\s*\[(html|markdown|plain|moodle)\]/i', '', $text);
    // Unescape GIFT escapes (\: \= \~ \{ \} \#); if UTF-8 is invalid, the original text is retained.
    $unescaped = preg_replace('/\\\\(.)/su', '$1', $text);
    return trim($unescaped ?? $text);
}

/**
 * @param string $text question text
 * @return bool true if there is real text
 */
function local_aiquestions_has_question_text($text) {
    $plain = trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8'));
    return mb_strlen($plain) >= 10 && preg_match('/\p{L}/u', $plain) === 1;
}

/**
 * Validate ONE GIFT multichoice question (1 right, 3 wrong answers).
 * @param string $block one question
 * @param int $expectedwrong expected number of wrong answers
 * @return bool true if valid
 */
function local_aiquestions_is_valid_gift_question($block, $expectedwrong = 3) {
    $flat = trim(preg_replace('/\s*\n\s*/', ' ', $block));
    $safe = local_aiquestions_mask_escaped($flat);

    // Cím (opcionális): ::cím::
    $offset = 0;
    if (preg_match('/^::(.+?)::/su', $safe, $m)) {
        $offset = strlen($m[0]);
    }

    $open = strpos($safe, '{', $offset);
    $close = strrpos($safe, '}');
    if ($open === false || $close === false || $close < $open) {
        return false; // Nincs válasz blokk.
    }

    // OLD CODE: It only counted the number of characters, differently from the save function.
    // $questiontext = trim(substr($flat, $offset, $open - $offset) . ' ' . substr($flat, $close + 1));
    // if (mb_strlen($questiontext) < 10) {
    //     return false;
    // }

    // Check question text
    if (!local_aiquestions_has_question_text(local_aiquestions_extract_question_text($block))) {
        return false;
    }

    // Answers
    $answers = substr($safe, $open + 1, $close - $open - 1);
    $answers = preg_replace('/#[^~=]*/u', '', $answers);
    if ($answers === null) {
        return false;
    }

    $wrong = substr_count($answers, '~');
    $right = substr_count($answers, '=');
    if ($right != 1 || $wrong != $expectedwrong) {
        return false;
    }

    // Answer is not empty
    $items = preg_split('/[~=]/u', $answers);
    array_shift($items);
    foreach ($items as $item) {
        $item = preg_replace('/^\s*%-?\d+(\.\d+)?%/u', '', $item);
        if (trim($item) === '') {
            return false;
        }
    }
    return true;
}

/**
 * Return only the valid question blocks of a GIFT text.
 * 
 * We'll skip the invalid questions and keep the valid ones.
 * 
 * @param string $gift GIFT text
 * @return array of valid question blocks
 */
function local_aiquestions_get_valid_gift_blocks($gift) {
    $valid = [];
    foreach (local_aiquestions_split_gift($gift) as $block) {
        if (local_aiquestions_is_valid_gift_question($block)) {
            $valid[] = $block;
        }
    }
    return $valid;
}


/**
 * Create questions from data got from ChatGPT output.
 *
 * @param int $courseid course id
 * @param int $category course category
 * @param string $gift questions in GIFT format
 * @param int $numofquestions number of questions to generate
 * @param int $userid user id
 * @param bool $addidentifier add an GPT prefix to question names.
 * @return array of objects of created questions
 */
function local_aiquestions_create_questions($courseid, $category, $gift, $numofquestions, $userid, $addidentifier, $customprefix = '') {
    global $CFG, $USER, $DB;

    require_once($CFG->libdir . '/questionlib.php');
    require_once($CFG->dirroot . '/question/format.php');
    require_once($CFG->dirroot . '/question/format/gift/format.php');

    $qformat = new \qformat_gift();

    $coursecontext = \context_course::instance($courseid);

    // Get question category TODO: MDL-12345 There is probably a better way to do this.
    if ($category) {
        $categoryids = explode(',', $category);
        $categoryid = $categoryids[0];
        $categorycontextid = $categoryids[1];
        $category = $DB->get_record('question_categories', ['id' => $categoryid, 'contextid' => $categorycontextid]);
    }

    // Use existing questions category for quiz or create the defaults.
    if (!$category) {
        $contexts = new core_question\local\bank\question_edit_contexts($coursecontext);
        if (!$category = $DB->get_record('question_categories', ['contextid' => $coursecontext->id, 'sortorder' => 999])) {
            $category = question_make_default_categories($contexts->all());
        }
    }

// file_put_contents(__DIR__ . '/gift_raw.txt', print_r($gift, true));

    $blocks = local_aiquestions_get_valid_gift_blocks($gift);

    // 2) If none of them are valid, an error occurs (the caller can try again).
    if (empty($blocks)) {
        return false;
    }

    // 3) If there are more valid entries than requested, we will disregard the excess.
    if ($numofquestions > 0 && count($blocks) > $numofquestions) {
        $blocks = array_slice($blocks, 0, $numofquestions);
    }

    $qtype = 'multichoice';
    $createdquestions = []; // Array of objects of created questions.
    foreach ($blocks as $block) {
        try {
            $singlequestion = explode("\n", $block);

            // New (2. fix):
            $questiontext = local_aiquestions_extract_question_text($block);
            if (!local_aiquestions_has_question_text($questiontext)) {
                continue; // We didn't go in with a blank question.
            }

            $q = $qformat->readquestion($singlequestion);

            // If the GIFT reader was unable to interpret it, we'll skip this question (we won't discard all of them).
            if (!$q) {
                continue;
            }
            // We only covered multiple-choice questions (using the `save_question` type with the `multichoice` option).
            if (!empty($q->qtype) && $q->qtype !== 'multichoice') {
                continue;
            }

            $q->category = $category->id;
            $q->createdby = $userid;
            $q->modifiedby = $userid;
            $q->timecreated = time();
            $q->timemodified = time();
            $q->questiontext = ['text' => "<p>" . s($questiontext) . "</p>"];
            $q->questiontextformat = 1;

            // custom prefix
            $customprefix = trim((string)$customprefix);
            if ($customprefix !== '') {
                $q->name = $customprefix . " " . $q->name;
            }
	
            if ($addidentifier == 1) {
                $q->name = "AI-created: " . $q->name; // Adds a "watermark" to the question.
            }
            $created = question_bank::get_qtype($qtype)->save_question($q, $q);
            if ($created) {
                $createdquestions[] = $created;
            }
        } catch (\Throwable $e) {
            // Don't let a mistake on one question derail the rest.
            debugging('AI question creation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            continue;
        }
    }

    // If at least one question has been created, we'll return them.
    if (!empty($createdquestions)) {
        return $createdquestions;
    }
    return false;

}
/**
 * Escape json.
 *
 * @param string $value json to escape
 * @return string result escaped json
 */
function local_aiquestions_escape_json($value) {
    $escapers = ["\\", "/", "\"", "\n", "\r", "\t", "\x08", "\x0c"];
    $replacements = ["\\\\", "\\/", "\\\"", "\\n", "\\r", "\\t", "\\f", "\\b"];
    $result = str_replace($escapers, $replacements, $value);
    return $result;
}

/**
 * Check if the gift format is valid.
 *
 * @param string $gift questions in GIFT format
 * @return bool true if valid, false if not
 */

// OLD CODE:


// === New Code (fix) =======================================================
/**
 * Check if the gift format is valid.
 *
 * Default: true if there is AT LEAST ONE valid question (incorrect ones are
 * skipped by local_aiquestions_create_questions() anyway). If all questions must be correct,
 * call it with $requireall = true.
 *
 * @param string $gift questions in GIFT format
 * @param bool $requireall true: Every question must be valid
 * @return bool true if valid, false if not
 */
function local_aiquestions_check_gift($gift, $requireall = false) {
    $blocks = local_aiquestions_split_gift($gift);
    if (empty($blocks)) {
        return false;
    }

    $validcount = 0;
    foreach ($blocks as $block) {
        if (local_aiquestions_is_valid_gift_question($block)) {
            $validcount++;
        } else if ($requireall) {
            return false;
        }
    }
    return $validcount > 0;
}


/**
 * Extract text from a PDF file using the file ID
 *
 * @param int $fileid File ID from the files table
 * @return string Extracted text or empty string if extraction fails
 */
function extract_pdf_text($fileid) {
    global $DB, $CFG;

    try {
        // Get file information.
        $file = $DB->get_record('files', ['id' => $fileid], '*', MUST_EXIST);

        // Get the file storage.
        $fs = get_file_storage();
        $storedfile = $fs->get_file_by_id($fileid);

        if (!$storedfile) {
            return '';
        }

        // Create a temporary file to work with.
        $tempfile = tempnam($CFG->dataroot . '/temp/', 'moodle_pdf_');
        $storedfile->copy_content_to($tempfile);

        // Include the PDF parser.
        require_once($CFG->dirroot . '/local/aiquestions/smalot_pdfparser/alt_autoload.php');

        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($tempfile);
        $text = $pdf->getText();

        // Clean up the temporary file.
        unlink($tempfile);

        // Clean up the text (remove excessive whitespace, normalize line breaks).
        // $text = preg_replace('/\s+/', ' ', $text);
        // $text = preg_replace('/[^\P{C}\t\n]+/u', '', $text);
        // $text = trim($text);

        // Remove utf8 special characters.
        // $text = preg_replace('/[^\x20-\x7E]/', '', $text);

        // Clean up the text: keep letters, tabs and newlines, remove control characters.
        // 1) Replace invalid UTF-8 bytes so the /u regex cannot fail.
        $text = mb_scrub($text, 'UTF-8');

        // 2) Remove control/format characters, but keep tab and newline.
        $clean = preg_replace('/(?:(?![\t\n])\p{C})+/u', '', $text);

        // 3) Safety net: if the regex still failed, keep the scrubbed text.
        $text = trim($clean ?? $text);

        return $text;

    } catch (Exception $e) {
        // Log the error for debugging.
        debugging('PDF extraction failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return '';
    }
}
