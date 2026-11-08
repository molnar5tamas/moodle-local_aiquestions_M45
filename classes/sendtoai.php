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
 * Class for sending data to AI providers.
 *
 * @package     local_aiquestions
 * @category    admin
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_aiquestions;

/**
 * Class responsible for sending data to the AI for generation.
 * Supports Moodle AI subsystem and direct Ollama API calls.
 *
 * @package     local_aiquestions
 */
class sendtoai {

    /**
     * Generate text from the configured AI provider.
     *
     * @param int $contextid The context ID.
     * @param string $prompttext The prompt text to send to AI.
     * @return array The generated content and response data.
     */
    public static function execute(
        int $contextid,
        string $prompttext): array {

        $provider = get_config('local_aiquestions', 'provider');

        if ($provider === 'ollama') {
            return self::execute_ollama($prompttext);
        }

        // Default: Moodle AI subsystem.
        return self::execute_moodle_ai($contextid, $prompttext);
    }

    /**
     * Generate text using Moodle's built-in AI subsystem.
     *
     * @param int $contextid The context ID.
     * @param string $prompttext The prompt text.
     * @return array Response array.
     * @since Moodle 4.5
     */
    private static function execute_moodle_ai(int $contextid, string $prompttext): array {
        global $USER;

        // Context validation and permission check.
        $context = \core\context::instance_by_id($contextid);

        // Prepare the action.
        $action = new \core_ai\aiactions\generate_text(
            contextid: $contextid,
            userid: $USER->id,
            prompttext: $prompttext,
        );

        // Send the action to the AI manager.
        $manager = \core\di::get(\core_ai\manager::class);
        $response = $manager->process_action($action);

        return [
            'success'          => $response->get_success(),
            'generatedcontent' => $response->get_response_data()['generatedcontent'] ?? '',
            'finishreason'     => $response->get_response_data()['finishreason'] ?? '',
            'errorcode'        => $response->get_errorcode(),
            'error'            => $response->get_errormessage(),
            'timecreated'      => $response->get_timecreated(),
            'prompttext'       => $prompttext,
        ];
    }

    /**
     * Generate text by calling Ollama directly via its REST API.
     * @param string $prompttext The prompt text.
     * @return array Response array matching the shape returned by execute_moodle_ai().
     */
    private static function execute_ollama(string $prompttext): array {
        $baseurl = rtrim(get_config('local_aiquestions', 'ollama_apiurl'), '/');
        $apikey  = get_config('local_aiquestions', 'ollama_apikey');
        $model   = get_config('local_aiquestions', 'ollama_model') ?: 'llama3';

        if (empty($baseurl)) {
            return self::error_response($prompttext, 'missingurl', 'Ollama API URL is not configured.');
        }

    // OpenAI API standard chat completions route
        $endpoint = $baseurl . '/v1/chat/completions';

    // Formatted for OpenAI API standard payload structure
    // Scope: these settings only apply to direct mode (provider set to Ollama).
    // In the Moodle AI subsystem temperature and max tokens come from the Moodle AI provider's own settings, and the plugin does not control them.
        $payload = json_encode([
            'model'       => $model,
            'stream'      => false,
            'temperature' => 0.7,
            'max_tokens'  => 2000,
            'messages'    => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompttext]
            ],
        ]);

        $headers = ['Content-Type: application/json'];
        if (!empty($apikey)) {
            $headers[] = 'Authorization: Bearer ' . $apikey;
        }

        $curl = new \curl();
        $curl->setHeader($headers);
        $curl->setopt([
            'CURLOPT_CONNECTTIMEOUT' => 60,
            'CURLOPT_TIMEOUT'        => 300,
        ]);

        $raw      = $curl->post($endpoint, $payload);
        $httpcode = $curl->get_info()['http_code'] ?? 0;
        $curlerr  = $curl->error;

        if ($curl->get_errno() || !empty($curlerr)) {
            return self::error_response($prompttext, 'curlerror', 'cURL error: ' . $curlerr);
        }


    // OpenAI API standard
        $data = json_decode($raw, true);

        if ($httpcode !== 200 || empty($data)) {
            $errmsg = self::extract_error_message($data, $httpcode, $raw);
            return self::error_response($prompttext, 'apierror', $errmsg);
        }

        // CHANGED: Parsed using OpenAI structure
        $generatedcontent = $data['choices'][0]['message']['content'] ?? '';
        $finishreason     = $data['choices'][0]['finish_reason'] ?? 'stop';

        if ($generatedcontent === '') {
            return self::error_response(
                $prompttext,
                'emptyresponse',
                'The AI server returned an empty response (finish reason: ' . $finishreason . ').'
            );
        }


        return [
            'success'          => true,
            'generatedcontent' => $generatedcontent,
            'finishreason'     => $finishreason,
            'errorcode'        => null,
            'error'            => null,
            'timecreated'      => time(),
            'prompttext'       => $prompttext,
        ];
    }

    /**
     * Extract a readable error message from an API response.
     *
     * Handles the OpenAI-style {"error": {"message": "..."}} format,
     * a plain string error ({"error": "..."}), and unexpected output.
     *
     * @param mixed $data Decoded JSON response (may be null).
     * @param int $httpcode HTTP status code.
     * @param string $raw Raw response body.
     * @return string
     */
    private static function extract_error_message($data, int $httpcode, string $raw): string {
        if (is_array($data) && isset($data['error'])) {
            $error = $data['error'];

            if (is_string($error)) {
                return $error;
            }
            if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                return $error['message'];
            }
            // Unknown error structure: show it as JSON rather than failing.
            return json_encode($error);
        }

        // No usable error field (invalid JSON, empty body, HTML error page, etc.).
        return 'HTTP ' . $httpcode . ': ' . mb_substr(trim($raw), 0, 500);
    }

    /**
     * Build a uniform error response array.
     *
     * @param string $prompttext Original prompt.
     * @param string $errorcode  Short error code string.
     * @param string $errormsg   Human-readable error message.
     * @return array
     */
    private static function error_response(string $prompttext, string $errorcode, string $errormsg): array {
        return [
            'success'          => false,
            'generatedcontent' => '',
            'finishreason'     => '',
            'errorcode'        => $errorcode,
            'error'            => $errormsg,
            'timecreated'      => time(),
            'prompttext'       => $prompttext,
        ];
    }
}