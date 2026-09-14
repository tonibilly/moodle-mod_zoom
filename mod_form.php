<?php
// This file is part of the Zoom plugin for Moodle - http://moodle.org/
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
 * The main zoom configuration form
 *
 * @package    mod_zoom
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/zoom/lib.php');
require_once($CFG->dirroot . '/mod/zoom/locallib.php');

/**
 * Module instance settings form
 */
class mod_zoom_mod_form extends moodleform_mod {
    /**
     * Helper property for showing the scheduling privilege options.
     *
     * @var bool
     */
    private $showschedulingprivilege;

    /**
     * Defines forms elements
     */
    public function definition() {
        global $PAGE, $USER, $OUTPUT;

        $completionpagetypes = array(
            'course-defaultcompletion' => 'Edit completion default settings',
            'course-editbulkcompletion' => 'Edit completion settings in bulk',
            'course-editdefaultcompletion' => 'Edit completion default settings',
        );
        if (isset($completionpagetypes[$PAGE->pagetype])) {
            return;
        }

        $config = get_config('zoom');
        if (method_exists($PAGE->requires, 'js_call_amd')) {
            $PAGE->requires->js_call_amd("mod_zoom/form", 'init');
        }

        $isnew = empty($this->_cm);

        $zoomuserid = zoom_get_user_id(false);

        if ($isnew && $zoomuserid === false) {
            $errstring = 'zoomerr_usernotfound';
            $nexturl = $PAGE->url;
            zoom_fatal_error($errstring, 'mod_zoom', $nexturl, $config->zoomurl);
        }

        $scheduleusers = array();

        $canschedule = false;
        if ($zoomuserid !== false) {
            $canschedule = zoom_webservice()->get_schedule_for_users($zoomuserid);
        }

        if (!empty($canschedule)) {
            $canschedule[$zoomuserid] = new stdClass();
            $canschedule[$zoomuserid]->email = $USER->email;

            if (!$isnew && $zoomuserid !== $this->current->host_id) {
                $currenthostschedulers = zoom_webservice()->get_schedule_for_users($this->current->host_id);
                if (!empty($currenthostschedulers)) {
                    $currenthostschedulers[$this->current->host_id] = true;
                }

                $canschedule = array_intersect_key($canschedule, $currenthostschedulers);
            }

            $moodleusers = get_enrolled_users($this->context, 'mod/zoom:addinstance', 0, 'u.*', 'lastname');

            foreach ($canschedule as $zoomuserinfo) {
                $zoomemail = strtolower($zoomuserinfo->email);
                if (isset($scheduleusers[$zoomemail])) {
                    continue;
                }

                if ($zoomemail === strtolower($USER->email)) {
                    $scheduleusers[$zoomemail] = get_string('scheduleforself', 'zoom');
                    continue;
                }

                foreach ($moodleusers as $muser) {
                    if ($zoomemail === strtolower($muser->email)) {
                        $scheduleusers[$zoomemail] = fullname($muser);
                        break;
                    }
                }
            }
        }

        if (!$isnew) {
            try {
                zoom_webservice()->get_meeting_webinar_info($this->current->meeting_id, $this->current->webinar);
            } catch (webservice_exception $error) {
                if (zoom_is_meeting_gone_error($error)) {
                    $errstring = 'zoomerr_meetingnotfound';
                    $param = zoom_meetingnotfound_param($this->_cm->id);
                    $nexturl = "/mod/zoom/view.php?id=" . $this->_cm->id;
                    zoom_fatal_error($errstring, 'mod_zoom', $nexturl, $param, "meeting/get : $error");
                } else {
                    throw $error;
                }
            }
        }

        $allowschedule = false;
        if (!$isnew) {
            if (!empty($scheduleusers)) {
                try {
                    $founduser = zoom_get_user($this->current->host_id);
                    if ($founduser && array_key_exists($founduser->email, $scheduleusers)) {
                        $allowschedule = true;
                    }
                } catch (moodle_exception $error) {
                    $allowschedule = false;
                }
            }
        } else {
            $allowschedule = true;
        }

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('title', 'zoom'), array('size' => '64'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 200), 'maxlength', 200, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'schedule', get_string('schedule', 'mod_zoom'));
        $mform->setExpanded('schedule');

        $starttimeoptions = array(
            'step' => 5,
            'defaulttime' => time() + 3600,
        );
        $mform->addElement('date_time_selector', 'start_time', get_string('start_time', 'zoom'), $starttimeoptions);

        $mform->addElement('duration', 'duration', get_string('duration', 'zoom'), array('optional' => false));
        $mform->setDefault('duration', array('number' => 1, 'timeunit' => 3600));

        $mform->addElement(
            'advcheckbox',
            'recurring',
            get_string('recurringmeeting', 'zoom'),
            get_string('recurringmeetingthisis', 'zoom')
        );
        $mform->setDefault('recurring', $config->defaultrecurring);
        $mform->addHelpButton('recurring', 'recurringmeeting', 'zoom');

        $recurrencetype = array(
            ZOOM_RECURRINGTYPE_DAILY => get_string('recurrence_option_daily', 'zoom'),
            ZOOM_RECURRINGTYPE_WEEKLY => get_string('recurrence_option_weekly', 'zoom'),
            ZOOM_RECURRINGTYPE_MONTHLY => get_string('recurrence_option_monthly', 'zoom'),
            ZOOM_RECURRINGTYPE_NOTIME => get_string('recurrence_option_no_time', 'zoom'),
        );
        $mform->addElement('select', 'recurrence_type', get_string('recurrencetype', 'zoom'), $recurrencetype);
        if ($config->defaultrecurring == 1) {
            $mform->setDefault('recurrence_type', ZOOM_RECURRINGTYPE_NOTIME);
        }
        $mform->disabledIf('recurrence_type', 'recurring', 'notchecked');

        $options = array();
        for ($i = 1; $i <= 90; $i++) {
            $options[$i] = $i;
        }

        $group = array();
        $group[] = $mform->createElement('select', 'repeat_interval', '', $options);
        $htmlspantextstart = '<span class="repeat_interval" id="interval_';
        $htmlspantextend = '</span>';
        $group[] = $mform->createElement('html', $htmlspantextstart . 'daily">' . get_string('day', 'zoom') . $htmlspantextend);
        $group[] = $mform->createElement('html', $htmlspantextstart . 'weekly">' . get_string('week', 'zoom') . $htmlspantextend);
        $group[] = $mform->createElement('html', $htmlspantextstart . 'monthly">' . get_string('month', 'zoom') . $htmlspantextend);
        $mform->addGroup($group, 'repeat_group', get_string('repeatinterval', 'zoom'), null, false);

        $weekdayoptions = zoom_get_weekday_options();
        $group = array();
        foreach ($weekdayoptions as $key => $weekday) {
            $weekdayid = 'weekly_days_' . $key;
            $group[] = $mform->createElement('advcheckbox', $weekdayid, '', $weekday, null, array(0, $key));
        }

        $mform->addGroup($group, 'weekly_days_group', get_string('occurson', 'zoom'), ' ', false);
        if (!empty($this->current->weekly_days)) {
            $weekdaynumbers = explode(',', $this->current->weekly_days);
            foreach ($weekdaynumbers as $daynumber) {
                $weekdayid = 'weekly_days_' . $daynumber;
                $mform->setDefault($weekdayid, $daynumber);
            }
        }

        $monthoptions = array();
        for ($i = 1; $i <= 31; $i++) {
            $monthoptions[$i] = $i;
        }

        $monthlyweekoptions = zoom_get_monthweek_options();

        $group = array();
        $group[] = $mform->createElement(
            'radio',
            'monthly_repeat_option',
            '',
            get_string('day', 'calendar'),
            ZOOM_MONTHLY_REPEAT_OPTION_DAY
        );
        $group[] = $mform->createElement('select', 'monthly_day', '', $monthoptions);
        $group[] = $mform->createElement('static', 'month_day_text', '', get_string('month_day_text', 'zoom'));
        $group[] = $mform->createElement('radio', 'monthly_repeat_option', '', '', ZOOM_MONTHLY_REPEAT_OPTION_WEEK);
        $group[] = $mform->createElement('select', 'monthly_week', '', $monthlyweekoptions);
        $group[] = $mform->createElement('select', 'monthly_week_day', '', $weekdayoptions);
        $group[] = $mform->createElement('static', 'month_week_day_text', '', get_string('month_day_text', 'zoom'));
        $mform->addGroup($group, 'monthly_day_group', get_string('occurson', 'zoom'), null, false);
        $mform->setDefault('monthly_repeat_option', ZOOM_MONTHLY_REPEAT_OPTION_DAY);

        $maxoptions = array();
        for ($i = 1; $i <= 50; $i++) {
            $maxoptions[$i] = $i;
        }

        $group = array();
        $group[] = $mform->createElement(
            'radio',
            'end_date_option',
            '',
            get_string('end_date_option_by', 'zoom'),
            ZOOM_END_DATE_OPTION_BY
        );
        $group[] = $mform->createElement('date_selector', 'end_date_time', '');
        $group[] = $mform->createElement(
            'radio',
            'end_date_option',
            '',
            get_string('end_date_option_after', 'zoom'),
            ZOOM_END_DATE_OPTION_AFTER
        );
        $group[] = $mform->createElement('select', 'end_times', '', $maxoptions);
        $group[] = $mform->createElement('static', 'end_times_text', '', get_string('end_date_option_occurrences', 'zoom'));
        $mform->addGroup($group, 'radioenddate', get_string('enddate', 'zoom'), null, false);
        $mform->setDefault('end_date_option', ZOOM_END_DATE_OPTION_BY);
        $mform->setDefault('end_date_time', strtotime('+1 week'));

        if ($config->showwebinars != ZOOM_WEBINAR_DISABLE) {
            if ($isnew) {
                $userfeatures = zoom_get_user_settings($zoomuserid)->feature;
                $haswebinarlicense = !empty($userfeatures->webinar) || !empty($userfeatures->zoom_events);

                if (
                    $config->showwebinars == ZOOM_WEBINAR_ALWAYSSHOW ||
                    ($config->showwebinars == ZOOM_WEBINAR_SHOWONLYIFLICENSE && $haswebinarlicense)
                ) {
                    $webinarattr = null;
                    if (!$haswebinarlicense) {
                        $webinarattr = array('disabled' => true);
                    }

                    $mform->addElement(
                        'advcheckbox',
                        'webinar',
                        get_string('webinar', 'zoom'),
                        get_string('webinarthisis', 'zoom'),
                        $webinarattr
                    );
                    $mform->setDefault('webinar', $config->webinardefault);
                    $mform->addHelpButton('webinar', 'webinar', 'zoom');
                }
            } else if (!empty($this->current->webinar)) {
                $mform->addElement(
                    'static',
                    'webinaralreadyset',
                    get_string('webinar', 'zoom'),
                    get_string('webinar_already_true', 'zoom')
                );
            } else {
                $mform->addElement(
                    'static',
                    'webinaralreadyset',
                    get_string('webinar', 'zoom'),
                    get_string('webinar_already_false', 'zoom')
                );
            }
        }

        $defaulttrackingfields = zoom_clean_tracking_fields();
        foreach ($defaulttrackingfields as $key => $defaulttrackingfield) {
            $configname = 'tf_' . $key . '_field';
            if (!empty($config->$configname)) {
                $mform->addElement('text', $key, $defaulttrackingfield);
                $mform->setType($key, PARAM_TEXT);
                $rvprop = 'tf_' . $key . '_recommended_values';
                if (!empty($config->$rvprop)) {
                    $mform->addElement(
                        'static',
                        $key . '_recommended_values',
                        null,
                        get_string('trackingfields_recommendedvalues', 'mod_zoom') . $config->$rvprop
                    );
                }

                $requiredproperty = 'tf_' . $key . '_required';
                if (!empty($config->$requiredproperty)) {
                    $mform->addRule($key, null, 'required', null, 'client');
                }
            }
        }

        $mform->addElement(
            'advcheckbox',
            'show_schedule',
            get_string('showschedule', 'zoom'),
            get_string('showscheduleonview', 'zoom')
        );
        $mform->setDefault('show_schedule', $config->defaultshowschedule);
        $mform->addHelpButton('show_schedule', 'showschedule', 'zoom');

        $registrationoptions = array(
            ZOOM_REGISTRATION_OFF => get_string('no'),
            ZOOM_REGISTRATION_AUTOMATIC => get_string('registration_text', 'mod_zoom'),
        );
        $mform->addElement('select', 'registration', get_string('registration', 'mod_zoom'), $registrationoptions);
        $mform->setDefault('registration', $config->defaultregistration);
        $mform->addHelpButton('registration', 'registration', 'mod_zoom');

        $mform->addElement('hidden', 'rooms', '');
        $mform->setType('rooms', PARAM_RAW);

        $mform->addElement('hidden', 'roomsparticipants', '');
        $mform->setType('roomsparticipants', PARAM_RAW);

        $mform->addElement('hidden', 'roomsgroups', '');
        $mform->setType('roomsgroups', PARAM_RAW);

        $mform->addElement('header', 'security', get_string('security', 'mod_zoom'));
        $mform->setExpanded('security');

        if (isset($this->current->password)) {
            $this->current->meetingcode = $this->current->password;
            unset($this->current->password);
        }

        $mform->addElement(
            'advcheckbox',
            'requirepasscode',
            get_string('password', 'zoom'),
            get_string('requirepasscode', 'zoom')
        );
        if (isset($this->current->meetingcode) && strval($this->current->meetingcode) === "") {
            $mform->setDefault('requirepasscode', 0);
        } else {
            $mform->setDefault('requirepasscode', 1);
        }

        $mform->addHelpButton('requirepasscode', 'requirepasscode', 'zoom');

        $hostuseridforsec = isset($this->current->host_id) ? $this->current->host_id : $zoomuserid;
        $securitysettings = zoom_get_meeting_security_settings($hostuseridforsec);
        $mform->addElement('text', 'meetingcode', get_string('setpasscode', 'zoom'), array('maxlength' => '10'));
        $mform->setType('meetingcode', PARAM_TEXT);
        $regex = '/^[a-zA-Z0-9@_*-]{1,10}$/';
        $mform->addRule('meetingcode', get_string('err_invalid_password', 'mod_zoom'), 'regex', $regex, 'client');
        $mform->setDefault('meetingcode', zoom_create_default_passcode($securitysettings->meeting_password_requirement));

        $passwordrequirementsgroup = array();
        $passwordrequirementsgroup[] =& $mform->createElement(
            'static',
            'passwordrequirements',
            '',
            zoom_create_passcode_description($securitysettings->meeting_password_requirement)
        );
        $mform->addGroup($passwordrequirementsgroup, 'passwordrequirementsgroup', '', '', false);

        if ($config->showencryptiontype != ZOOM_ENCRYPTION_DISABLE) {
            $e2eispossible = $securitysettings->end_to_end_encrypted_meetings;

            if ($config->showencryptiontype == ZOOM_ENCRYPTION_SHOWONLYIFPOSSIBLE && !$e2eispossible) {
                $mform->addElement('hidden', 'option_encryption_type', ZOOM_ENCRYPTION_TYPE_ENHANCED);
            } else if (
                $config->showencryptiontype == ZOOM_ENCRYPTION_ALWAYSSHOW ||
                ($config->showencryptiontype == ZOOM_ENCRYPTION_SHOWONLYIFPOSSIBLE && $e2eispossible)
            ) {
                $encryptionattr = null;
                $defaultencryptiontype = $config->defaultencryptiontypeoption;
                if (!$e2eispossible) {
                    $encryptionattr = array('disabled' => true);
                    $defaultencryptiontype = ZOOM_ENCRYPTION_TYPE_ENHANCED;
                }

                $mform->addGroup(array(
                    $mform->createElement(
                        'radio',
                        'option_encryption_type',
                        '',
                        get_string('option_encryption_type_enhancedencryption', 'zoom'),
                        ZOOM_ENCRYPTION_TYPE_ENHANCED,
                        $encryptionattr
                    ),
                    $mform->createElement(
                        'radio',
                        'option_encryption_type',
                        '',
                        get_string('option_encryption_type_endtoendencryption', 'zoom'),
                        ZOOM_ENCRYPTION_TYPE_E2EE,
                        $encryptionattr
                    ),
                ), 'option_encryption_type_group', get_string('option_encryption_type', 'zoom'), null, false);
                $mform->setDefault('option_encryption_type', $defaultencryptiontype);
                $mform->addHelpButton('option_encryption_type_group', 'option_encryption_type', 'zoom');
            }

            $mform->setType('option_encryption_type', PARAM_ALPHANUMEXT);
        }

        $mform->addElement(
            'advcheckbox',
            'option_waiting_room',
            get_string('option_waiting_room', 'zoom'),
            get_string('waitingroomenable', 'zoom')
        );
        $mform->addHelpButton('option_waiting_room', 'option_waiting_room', 'zoom');
        $mform->setDefault('option_waiting_room', $config->defaultwaitingroomoption);

        $mform->addElement(
            'advcheckbox',
            'option_jbh',
            get_string('option_jbh', 'zoom'),
            get_string('joinbeforehostenable', 'zoom')
        );
        $mform->setDefault('option_jbh', $config->defaultjoinbeforehost);
        $mform->addHelpButton('option_jbh', 'option_jbh', 'zoom');

        $mform->addElement(
            'advcheckbox',
            'option_authenticated_users',
            get_string('authentication', 'zoom'),
            get_string('option_authenticated_users', 'zoom')
        );
        $mform->setDefault('option_authenticated_users', $config->defaultauthusersoption);
        $mform->addHelpButton('option_authenticated_users', 'option_authenticated_users', 'zoom');

        $mform->addElement(
            'advcheckbox',
            'show_security',
            get_string('showsecurity', 'zoom'),
            get_string('showsecurityonview', 'zoom')
        );
        $mform->setDefault('show_security', $config->defaultshowsecurity);
        $mform->addHelpButton('show_security', 'showsecurity', 'zoom');

        $mform->addElement('header', 'media', get_string('media', 'mod_zoom'));
        $mform->setExpanded('media');

        $mform->addGroup(array(
            $mform->createElement('radio', 'option_host_video', '', get_string('on', 'zoom'), true),
            $mform->createElement('radio', 'option_host_video', '', get_string('off', 'zoom'), false),
        ), 'option_host_video_group', get_string('option_host_video', 'zoom'), null, false);
        $mform->setDefault('option_host_video', $config->defaulthostvideo);
        $mform->addHelpButton('option_host_video_group', 'option_host_video', 'zoom');

        $mform->addGroup(array(
            $mform->createElement('radio', 'option_participants_video', '', get_string('on', 'zoom'), true),
            $mform->createElement('radio', 'option_participants_video', '', get_string('off', 'zoom'), false),
        ), 'option_participants_video_group', get_string('option_participants_video', 'zoom'), null, false);
        $mform->setDefault('option_participants_video', $config->defaultparticipantsvideo);
        $mform->addHelpButton('option_participants_video_group', 'option_participants_video', 'zoom');

        $mform->addGroup(array(
            $mform->createElement('radio', 'option_audio', '', get_string('audio_telephony', 'zoom'), ZOOM_AUDIO_TELEPHONY),
            $mform->createElement('radio', 'option_audio', '', get_string('audio_voip', 'zoom'), ZOOM_AUDIO_VOIP),
            $mform->createElement('radio', 'option_audio', '', get_string('audio_both', 'zoom'), ZOOM_AUDIO_BOTH),
        ), 'option_audio_group', get_string('option_audio', 'zoom'), null, false);
        $mform->addHelpButton('option_audio_group', 'option_audio', 'zoom');
        $mform->setDefault('option_audio', $config->defaultaudiooption);

        $mform->addElement(
            'advcheckbox',
            'option_mute_upon_entry',
            get_string('audiodefault', 'mod_zoom'),
            get_string('option_mute_upon_entry', 'mod_zoom')
        );
        $mform->setDefault('option_mute_upon_entry', $config->defaultmuteuponentryoption);
        $mform->addHelpButton('option_mute_upon_entry', 'option_mute_upon_entry', 'mod_zoom');

        $hostuserid = $zoomuserid;
        if (!empty($this->current->host_id)) {
            $hostuserid = $this->current->host_id;
        }

        $allowrecordingchangeoption = $config->allowrecordingchangeoption;
        if ($allowrecordingchangeoption) {
            $defaultsetting = $config->recordingoption;

            $options = array(
                ZOOM_AUTORECORDING_NONE => get_string('autorecording_none', 'mod_zoom'),
            );

            if (!empty($hostuserid)) {
                $recordingsettings = zoom_get_user_settings($hostuserid)->recording;

                if ($config->recordingoption === ZOOM_AUTORECORDING_USERDEFAULT) {
                    $defaultsetting = $recordingsettings->auto_recording;
                }

                if (!empty($recordingsettings->local_recording)) {
                    $options[ZOOM_AUTORECORDING_LOCAL] = get_string('autorecording_local', 'mod_zoom');
                }

                if (!empty($recordingsettings->cloud_recording)) {
                    $options[ZOOM_AUTORECORDING_CLOUD] = get_string('autorecording_cloud', 'mod_zoom');
                }
            }

            $mform->addElement('select', 'option_auto_recording', get_string('option_auto_recording', 'mod_zoom'), $options);
            $mform->setDefault('option_auto_recording', $defaultsetting);
            $mform->addHelpButton('option_auto_recording', 'option_auto_recording', 'mod_zoom');
        }

        $mform->addElement(
            'advcheckbox',
            'show_media',
            get_string('showmedia', 'zoom'),
            get_string('showmediaonview', 'zoom')
        );
        $mform->setDefault('show_media', $config->defaultshowmedia);
        $mform->addHelpButton('show_media', 'showmedia', 'zoom');

        $showschedulingprivilege = ($config->showschedulingprivilege != ZOOM_SCHEDULINGPRIVILEGE_DISABLE) &&
                count($scheduleusers) > 1 && $allowschedule;
        $this->showschedulingprivilege = $showschedulingprivilege;
        $showalternativehosts = ($config->showalternativehosts != ZOOM_ALTERNATIVEHOSTS_DISABLE);
        if ($showschedulingprivilege || $showalternativehosts) {
            $mform->addElement('header', 'host', get_string('host', 'mod_zoom'));
            $mform->setExpanded('host');

            if ($showalternativehosts) {
                $mform->addElement('static', 'hostintro', '', get_string('hostintro', 'zoom'));
                $mform->addElement('text', 'alternative_hosts', get_string('alternative_hosts', 'zoom'), array('size' => '64'));
                $mform->setType('alternative_hosts', PARAM_TEXT);
                $mform->addHelpButton('alternative_hosts', 'alternative_hosts', 'zoom');
            }

            if ($showschedulingprivilege) {
                $mform->addElement('select', 'schedule_for', get_string('schedulefor', 'mod_zoom'), $scheduleusers);
                $mform->setType('schedule_for', PARAM_EMAIL);
                if (!$isnew) {
                    $mform->disabledIf('schedule_for', 'change_schedule_for');
                    $mform->addElement('checkbox', 'change_schedule_for', get_string('changehost', 'zoom'));
                    $mform->setDefault('schedule_for', strtolower(zoom_get_user($this->current->host_id)->email));
                } else {
                    $mform->setDefault('schedule_for', strtolower(zoom_get_api_identifier($USER)));
                }

                $mform->addHelpButton('schedule_for', 'schedulefor', 'zoom');
            }
        }

        if (!empty($config->viewrecordings)) {
            $mform->addElement('header', 'recording', get_string('recording', 'mod_zoom'));
            $mform->addElement(
                'advcheckbox',
                'recordings_visible_default',
                get_string('recordingvisibility', 'mod_zoom'),
                get_string('yes')
            );
            $mform->setDefault('recordings_visible_default', 1);
            $mform->addHelpButton('recordings_visible_default', 'recordingvisibility', 'mod_zoom');
        }

        $mform->addElement('hidden', 'meeting_id', -1);
        $mform->setType('meeting_id', PARAM_ALPHANUMEXT);

        $mform->addElement('hidden', 'host_id', $hostuserid);
        $mform->setType('host_id', PARAM_ALPHANUMEXT);

        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', false);

        $this->standard_coursemodule_elements();
        $this->apply_admin_defaults();

        $this->add_action_buttons();
    }

    /**
     * Add standard_grading_coursemodule_elements with grading for field.
     */
    public function standard_grading_coursemodule_elements() {
        parent::standard_grading_coursemodule_elements();
        $mform = $this->_form;
        $options = array(
            'entry' => get_string('gradingentry', 'mod_zoom'),
            'period' => get_string('gradingperiod', 'mod_zoom'),
        );
        $mform->addElement('select', 'grading_method', get_string('gradingmethod', 'mod_zoom'), $options);
        $mform->setDefault('grading_method', get_config('zoom', 'gradingmethod'));
        $mform->addHelpButton('grading_method', 'gradingmethod', 'mod_zoom');
    }

    /**
     * More validation on form data.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $USER;

        $errors = parent::validation($data, $files);
        $config = get_config('zoom');

        if (empty($data['recurring'])) {
            if ($data['start_time'] < time() && $data['meeting_id'] < 0) {
                $errors['start_time'] = get_string('err_start_time_past', 'zoom');
            }

            if ($data['duration'] <= 0) {
                $errors['duration'] = get_string('err_duration_nonpositive', 'zoom');
            } else if ($data['duration'] > 150 * 60 * 60) {
                $errors['duration'] = get_string('err_duration_too_long', 'zoom');
            }
        } else if ($data['recurring'] == 1 && $data['recurrence_type'] != ZOOM_RECURRINGTYPE_NOTIME) {
            if ($data['start_time'] < time() && $data['meeting_id'] < 0) {
                $errors['start_time'] = get_string('err_start_time_past_recurring', 'zoom');
            }

            if ($data['duration'] <= 0) {
                $errors['duration'] = get_string('err_duration_nonpositive', 'zoom');
            } else if ($data['duration'] > 150 * 60 * 60) {
                $errors['duration'] = get_string('err_duration_too_long', 'zoom');
            }
        }

        if (!empty($data['requirepasscode']) && empty($data['meetingcode'])) {
            $errors['meetingcode'] = get_string('err_password_required', 'mod_zoom');
        }

        if (isset($data['schedule_for']) && strtolower($data['schedule_for']) !== strtolower(zoom_get_api_identifier($USER))) {
            $zoomuserid = zoom_get_user_id();
            $scheduleusers = zoom_webservice()->get_schedule_for_users($zoomuserid);
            $scheduleok = false;
            foreach ($scheduleusers as $zuser) {
                if (strtolower($zuser->email) === strtolower($data['schedule_for'])) {
                    $scheduleok = true;
                    break;
                }
            }

            if (!$scheduleok) {
                $errors['schedule_for'] = get_string('invalidscheduleuser', 'mod_zoom');
            }
        }

        if ($config->showalternativehosts != ZOOM_ALTERNATIVEHOSTS_DISABLE) {
            if (!empty($data['alternative_hosts'])) {
                $alternativehosts = zoom_get_alternative_host_array_from_string($data['alternative_hosts']);
                foreach ($alternativehosts as $alternativehost) {
                    if (!(zoom_get_user($alternativehost))) {
                        $errors['alternative_hosts'] = get_string('zoomerr_alternativehostusernotfound', 'zoom', $alternativehost);
                        break;
                    }
                }
            }
        }

        if ($data['registration'] != ZOOM_REGISTRATION_OFF) {
            if (!zoom_webservice()->is_user_permitted_to_require_registration()) {
                $errors['registration'] = get_string('err_registration', 'mod_zoom');
            }
        }

        return $errors;
    }
}
