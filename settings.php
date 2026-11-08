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

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_aiquestions_settings', new lang_string('pluginname', 'local_aiquestions'));

    // Provider selection.
    $settings->add(new admin_setting_configselect(
        'local_aiquestions/provider',
        get_string('provider', 'local_aiquestions'),
        get_string('provider_desc', 'local_aiquestions'),
        'moodle_ai',
        [
            'moodle_ai' => 'Moodle AI Subsystem',
            'ollama' => 'Ollama',
        ]
    ));

    // Add info about using Moodle's AI settings.
    $settings->add(new admin_setting_heading(
        'local_aiquestions/aiinfo_heading',
        get_string('aisettingsheader', 'local_aiquestions'),
        get_string('aisettingsdesc', 'local_aiquestions')
    ));

// MT MT MT MT MT MT 
    $settings->add(new admin_setting_heading(
        'local_aiquestions/ollama_settings',
        get_string('ollama_settings', 'local_aiquestions'),
       ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aiquestions/ollama_apiurl',
        get_string('ollama_apiurl', 'local_aiquestions'),
        get_string('ollama_apiurl_desc', 'local_aiquestions'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_aiquestions/ollama_apikey',
        get_string('ollama_apikey', 'local_aiquestions'),
        get_string('ollama_apikey_desc', 'local_aiquestions'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aiquestions/ollama_model',
        get_string('ollama_model', 'local_aiquestions'),
        get_string('ollama_model_desc', 'local_aiquestions'),
        'llama3',
        PARAM_TEXT
    ));

    // Number of tries.
    $settings->add( new admin_setting_configtext(
        'local_aiquestions/numoftries',
        get_string('numoftriesset', 'local_aiquestions'),
        get_string('numoftriesdesc', 'local_aiquestions'),
        10, PARAM_INT, 10
    ));

    // Presets.
    $settings->add( new admin_setting_heading(
        'local_aiquestions/presets',
        get_string('presets', 'local_aiquestions'),
        get_string('presetsdesc', 'local_aiquestions') .
        get_string('shareyourprompts', 'local_aiquestions'),
    ));

    for ($i = 1; $i <= 10; $i++) {

        // Preset header.
        $settings->add( new admin_setting_heading(
            'local_aiquestions/preset' . $i,
            get_string('preset', 'local_aiquestions') . " $i",
            null
        ));

        // Preset name.
        $settings->add( new admin_setting_configtext(
            'local_aiquestions/presetname' . $i,
            get_string('presetname', 'local_aiquestions'),
            get_string('presetnamedesc', 'local_aiquestions'),
            get_string('presetnamedefault' . $i, 'local_aiquestions'),
        ));

        // Preset primer.
        $settings->add( new admin_setting_configtextarea(
            'local_aiquestions/presettprimer' . $i,
            get_string('presetprimer', 'local_aiquestions'),
            get_string('primer_help', 'local_aiquestions'),
            get_string('presetprimerdefault' . $i, 'local_aiquestions'),
            PARAM_TEXT, 4000
        ));

        // Preset instructions.
        $settings->add( new admin_setting_configtextarea(
            'local_aiquestions/presetinstructions' . $i,
            get_string('presetinstructions', 'local_aiquestions'),
            get_string('instructions_help', 'local_aiquestions'),
            get_string('presetinstructionsdefault' . $i, 'local_aiquestions'),
            PARAM_TEXT, 4000
        ));

        // Preset example.
        $settings->add( new admin_setting_configtextarea(
            'local_aiquestions/presetexample' . $i,
            get_string('presetexample', 'local_aiquestions'),
            get_string('example_help', 'local_aiquestions'),
            get_string('presetexampledefault' . $i, 'local_aiquestions'),
            PARAM_TEXT, 4000
        ));

    }

    $ADMIN->add('localplugins', $settings);
}