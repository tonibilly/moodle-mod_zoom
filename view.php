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
 * Prints a particular instance of zoom
 *
 * @package    mod_zoom
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/moodlelib.php');

require_login();
// Additional access checks in zoom_get_instance_setup().
list($course, $cm, $zoom) = zoom_get_instance_setup();

$config = get_config('zoom');

$context = context_module::instance($cm->id);
$iszoommanager = has_capability('mod/zoom:addinstance', $context);

// Record module viewed event in Moodle 2.4.
add_to_log($course->id, 'zoom', 'view', 'view.php?id=' . $cm->id, $zoom->id, $cm->id);

// Print the page header.
$PAGE->set_url('/mod/zoom/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($zoom->name, true, array('context' => $context)));
$PAGE->set_heading(format_string($course->fullname, true, array('context' => $context)));

// Get Zoom user ID of current Moodle user.
$zoomuserid = zoom_get_user_id(false);

// Check if this user is the (real) host.
$userisrealhost = ($zoomuserid === $zoom->host_id);

// Get the alternative hosts of the meeting.
$alternativehosts = zoom_get_alternative_host_array_from_string($zoom->alternative_hosts);

// Check if this user is the host or an alternative host.
$userapiidentifier = zoom_get_api_identifier($USER);
if (filter_var($userapiidentifier, FILTER_VALIDATE_EMAIL) !== false) {
    $userapiidentifier = strtolower($userapiidentifier);
}
$userishost = ($userisrealhost || in_array($userapiidentifier, $alternativehosts, true));

// Get host user from Zoom.
$showrecreate = false;
if ($zoom->exists_on_zoom == ZOOM_MEETING_EXPIRED) {
    $showrecreate = true;
} else {
    try {
        zoom_webservice()->get_meeting_webinar_info($zoom->meeting_id, $zoom->webinar);
    } catch (webservice_exception $error) {
        $showrecreate = zoom_is_meeting_gone_error($error);

        if ($showrecreate) {
            // Mark meeting as expired.
            $updatedata = new stdClass();
            $updatedata->id = $zoom->id;
            $updatedata->exists_on_zoom = ZOOM_MEETING_EXPIRED;
            $DB->update_record('zoom', $updatedata);

            $zoom->exists_on_zoom = ZOOM_MEETING_EXPIRED;
        }
    } catch (moodle_exception $error) {
        // Ignore other exceptions.
        debugging($error->getMessage());
    }
}

$isrecurringnotime = ($zoom->recurring && $zoom->recurrence_type == ZOOM_RECURRINGTYPE_NOTIME);

$stryes = get_string('yes');
$strno = get_string('no');
$strjoin = get_string('join_meeting', 'mod_zoom');
$strregister = get_string('register', 'mod_zoom');
$strtime = get_string('meeting_time', 'mod_zoom');
$strduration = get_string('duration', 'mod_zoom');
$strpassprotect = get_string('passwordprotected', 'mod_zoom');
$strpassword = get_string('password', 'mod_zoom');
$strjoinlink = get_string('joinlink', 'mod_zoom');
$strencryption = get_string('option_encryption_type', 'mod_zoom');
$strencryptionenhanced = get_string('option_encryption_type_enhancedencryption', 'mod_zoom');
$strencryptionendtoend = get_string('option_encryption_type_endtoendencryption', 'mod_zoom');
$strjoinbeforehost = get_string('joinbeforehost', 'mod_zoom');
$strstartvideohost = get_string('starthostjoins', 'mod_zoom');
$strstartvideopart = get_string('startpartjoins', 'mod_zoom');
$straudioopt = get_string('option_audio', 'mod_zoom');
$strstatus = get_string('status', 'mod_zoom');
$strall = get_string('allmeetings', 'mod_zoom');
$strwwaitingroom = get_string('waitingroom', 'mod_zoom');
$strmuteuponentry = get_string('option_mute_upon_entry', 'mod_zoom');
$strauthenticatedusers = get_string('option_authenticated_users', 'mod_zoom');
$strhost = get_string('host', 'mod_zoom');
$strmeetinginvite = get_string('meeting_invite', 'mod_zoom');
$strmeetinginviteshow = get_string('meeting_invite_show', 'mod_zoom');

// Output starts here.
echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($zoom->name, true, array('context' => $context)), 2);

// Show notification if the meeting does not exist on Zoom.
if ($showrecreate) {
    if ($iszoommanager) {
        $message = get_string('zoomerr_meetingnotfound', 'mod_zoom', zoom_meetingnotfound_param($cm->id));
        echo $OUTPUT->notification($message, 'notifyproblem');
    } else {
        $message = get_string('zoomerr_meetingnotfound_info', 'mod_zoom');
        echo $OUTPUT->notification($message, 'notifyproblem');
    }
}

// Show intro.
if ($zoom->intro) {
    echo $OUTPUT->box(format_module_intro('zoom', $zoom, $cm->id), 'generalbox mod_introbox', 'intro');
}

// Supplementary feature: Meeting capacity warning.
if (!$showrecreate && !empty($config->showcapacitywarning)) {
    if ($iszoommanager) {
        $meetingcapacity = zoom_get_meeting_capacity($zoom->host_id, $zoom->webinar);
        $eligiblemeetingparticipants = zoom_get_eligible_meeting_participants($context);

        if ($eligiblemeetingparticipants > $meetingcapacity) {
            $participantspageurl = new moodle_url('/user/index.php', array('id' => $course->id));
            $meetingcapacityplaceholders = array(
                'meetingcapacity' => $meetingcapacity,
                'eligiblemeetingparticipants' => $eligiblemeetingparticipants,
                'zoomprofileurl' => $config->zoomurl . '/profile',
                'courseparticipantsurl' => $participantspageurl->out(),
                'hostname' => zoom_get_user_display_name($zoom->host_id),
            );
            $meetingcapacitywarning = get_string('meetingcapacitywarningheading', 'mod_zoom');
            $meetingcapacitywarning .= html_writer::empty_tag('br');
            if ($userisrealhost) {
                $meetingcapacitywarning .= get_string(
                    'meetingcapacitywarningbodyrealhost',
                    'mod_zoom',
                    $meetingcapacityplaceholders
                );
            } else {
                $meetingcapacitywarning .= get_string(
                    'meetingcapacitywarningbodyalthost',
                    'mod_zoom',
                    $meetingcapacityplaceholders
                );
            }

            $meetingcapacitywarning .= html_writer::empty_tag('br');
            if ($userisrealhost) {
                $meetingcapacitywarning .= get_string('meetingcapacitywarningcontactrealhost', 'mod_zoom');
            } else {
                $meetingcapacitywarning .= get_string('meetingcapacitywarningcontactalthost', 'mod_zoom');
            }

            echo html_writer::tag('div', $meetingcapacitywarning, array('class' => 'alert alert-warning'));
        }
    }
}

// Get meeting state from Zoom.
list($inprogress, $available, $finished) = zoom_get_state($zoom);

// Show join meeting button or unavailability note.
if (!$showrecreate) {
    if ($userishost) {
        $userisregistered = true;
    } else if ($zoom->registration != ZOOM_REGISTRATION_OFF) {
        $userisregistered = zoom_is_user_registered_for_meeting($USER->email, $zoom->meeting_id, $zoom->webinar);
        if (!$userisregistered) {
            $available = true;
        }
    }

    if ($available) {
        $btntext = $strjoin;
        if ($zoom->registration != ZOOM_REGISTRATION_OFF && !$userisregistered) {
            $btntext = $strregister;
        }

        $buttonhtml = html_writer::tag('button', $btntext, array('type' => 'submit', 'class' => 'btn btn-primary'));

        $aurl = new moodle_url('/mod/zoom/loadmeeting.php', array('id' => $cm->id));
        $buttonhtml .= html_writer::input_hidden_params($aurl);
        $link = html_writer::tag('form', $buttonhtml, array('action' => $aurl->out_omit_querystring(), 'target' => '_blank'));
    } else {
        $unavailabilitynote = zoom_get_unavailability_note($zoom, $finished);
        $link = html_writer::tag('div', $unavailabilitynote, array('class' => 'alert alert-info'));
    }

    echo $OUTPUT->box_start('generalbox text-center');
    echo $link;
    echo $OUTPUT->box_end();
}

if ($zoom->show_schedule) {
    echo $OUTPUT->box_start('', 'zoom_section-schedule');
    echo $OUTPUT->heading(get_string('schedule', 'mod_zoom'), 3);

    $table = new html_table();
    $table->attributes['class'] = 'generaltable mod_view';
    $table->align = array('center', 'left');
    $table->size = array('35%', '65%');

    $rowmeetingtime = new html_table_row();
    $rowmeetingtime->id = 'zoom_schedule-meetingtime';
    $meetingtimeheader = new html_table_cell();
    $meetingtimeheader->header = true;
    $meetingtimetext = new html_table_cell();

    if ($isrecurringnotime) {
        $meetingtimeheader->text = get_string('recurringmeeting', 'mod_zoom');
        $meetingtimetext->text = get_string('recurringmeetingexplanation', 'mod_zoom');
    } else if ($zoom->recurring && $zoom->recurrence_type != ZOOM_RECURRINGTYPE_NOTIME) {
        $meetingrecurringheader = new html_table_cell();
        $meetingrecurringheader->header = true;
        $meetingrecurringheader->text = get_string('recurringmeeting', 'mod_zoom');
        $meetingrecurringtext = new html_table_cell();
        $meetingrecurringtext->text = get_string('recurringmeetingthisis', 'mod_zoom');
        $rowmeetingrecurring = new html_table_row();
        $rowmeetingrecurring->id = 'zoom_schedule-meetingrecurring';
        $rowmeetingrecurring->cells = array($meetingrecurringheader, $meetingrecurringtext);
        $table->data[] = $rowmeetingrecurring;
        $nextoccurrence = zoom_get_next_occurrence($zoom);
        $meetingtimeheader->text = get_string('nextoccurrence', 'mod_zoom');
        if ($nextoccurrence > 0) {
            $meetingtimetext->text = userdate($nextoccurrence);
        } else {
            $meetingtimetext->text = get_string('nooccurrenceleft', 'mod_zoom');
        }
    } else {
        $meetingtimeheader->text = $strtime;
        $meetingtimetext->text = userdate($zoom->start_time);
    }

    $rowmeetingtime->cells = array($meetingtimeheader, $meetingtimetext);
    $table->data[] = $rowmeetingtime;

    if (!$isrecurringnotime) {
        $rowduration = new html_table_row();
        $rowduration->id = 'zoom_schedule-duration';
        $durationheader = new html_table_cell($strduration);
        $durationheader->header = true;
        $rowduration->cells = array($durationheader, format_time($zoom->duration));
        $table->data[] = $rowduration;
    }

    if (!empty($config->viewrecordings)) {
        $recordingaddurl = new moodle_url('/mod/zoom/recordings.php', array('id' => $cm->id));
        $recordingaddbutton = html_writer::div(get_string('recordingview', 'mod_zoom'), 'btn btn-primary');
        $recordingaddbuttonhtml = html_writer::link($recordingaddurl, $recordingaddbutton, array('target' => '_blank'));
        $recordinghtml = html_writer::div($recordingaddbuttonhtml);

        $rowrecordings = new html_table_row();
        $rowrecordings->id = 'zoom_schedule-recordings';
        $recordingheader = new html_table_cell(get_string('recordings', 'mod_zoom'));
        $recordingheader->header = true;
        $rowrecordings->cells = array($recordingheader, $recordinghtml);
        $table->data[] = $rowrecordings;
    }

    if ($config->showdownloadical != ZOOM_DOWNLOADICAL_DISABLE && !$showrecreate && !$isrecurringnotime) {
        $icallink = new moodle_url('/mod/zoom/exportical.php', array('id' => $cm->id));
        $calendaricon = $OUTPUT->pix_icon('i/calendar', get_string('calendariconalt', 'mod_zoom'));
        $calendarbutton = html_writer::div($calendaricon . ' ' . get_string('downloadical', 'mod_zoom'), 'btn btn-primary');
        $buttonhtml = html_writer::link((string) $icallink, $calendarbutton, array('target' => '_blank'));
        $rowaddtocalendar = new html_table_row();
        $rowaddtocalendar->id = 'zoom_schedule-addtocalendar';
        $addtocalendarheader = new html_table_cell(get_string('addtocalendar', 'mod_zoom'));
        $addtocalendarheader->header = true;
        $rowaddtocalendar->cells = array($addtocalendarheader, $buttonhtml);
        $table->data[] = $rowaddtocalendar;
    }

    if ($zoom->exists_on_zoom == ZOOM_MEETING_EXPIRED) {
        $status = get_string('meeting_nonexistent_on_zoom', 'mod_zoom');
    } else if (!$isrecurringnotime) {
        if ($finished) {
            $status = get_string('meeting_finished', 'mod_zoom');
        } else if ($inprogress) {
            $status = get_string('meeting_started', 'mod_zoom');
        } else {
            $status = get_string('meeting_not_started', 'mod_zoom');
        }
        $rowstatus = new html_table_row();
        $rowstatus->id = 'zoom_schedule-status';
        $statusheader = new html_table_cell($strstatus);
        $statusheader->header = true;
        $rowstatus->cells = array($statusheader, $status);
        $table->data[] = $rowstatus;
    }

    $hostdisplayname = zoom_get_user_display_name($zoom->host_id);
    if (isset($hostdisplayname)) {
        $rowhost = new html_table_row();
        $rowhost->id = 'zoom_schedule-host';
        $hostheader = new html_table_cell($strhost);
        $hostheader->header = true;
        $rowhost->cells = array($hostheader, s($hostdisplayname));
        $table->data[] = $rowhost;
    }

    if ($iszoommanager) {
        if ($config->showalternativehosts != ZOOM_ALTERNATIVEHOSTS_DISABLE && !empty($zoom->alternative_hosts)) {
            $rowshowalternativehosts = new html_table_row();
            $rowshowalternativehosts->id = 'zoom_schedule-showalternativehosts';
            $alternativehostsheader = new html_table_cell(get_string('alternative_hosts', 'mod_zoom'));
            $alternativehostsheader->header = true;

            if ($config->showalternativehosts == ZOOM_ALTERNATIVEHOSTS_PICKER) {
                $alternativehostusers = zoom_get_users_from_alternativehosts($alternativehosts);
                $alternativehostnonusers = zoom_get_nonusers_from_alternativehosts($alternativehosts);

                $alternativehostusersstring = implode(', ', array_map('fullname', $alternativehostusers));

                foreach ($alternativehostnonusers as &$ah) {
                    $ah .= ' (' . get_string('externaluser', 'mod_zoom') . ')';
                }

                $alternativehostnonusersstring = s(implode(', ', $alternativehostnonusers));

                if ($alternativehostusersstring != '' && $alternativehostnonusersstring != '') {
                    $alternativehoststring = $alternativehostusersstring . ', ' . $alternativehostnonusersstring;
                } else if ($alternativehostusersstring != '') {
                    $alternativehoststring = $alternativehostusersstring;
                } else {
                    $alternativehoststring = $alternativehostnonusersstring;
                }

                $rowshowalternativehosts->cells = array($alternativehostsheader, $alternativehoststring);
            } else {
                $rowshowalternativehosts->cells = array($alternativehostsheader, s($zoom->alternative_hosts));
            }

            $table->data[] = $rowshowalternativehosts;
        }
    }

    if ($iszoommanager) {
        $sessionsurl = new moodle_url('/mod/zoom/report.php', array('id' => $cm->id));
        $sessionslink = html_writer::link($sessionsurl, get_string('sessionsreport', 'mod_zoom'));
        $rowsessions = new html_table_row();
        $rowsessions->id = 'zoom_schedule-sessions';
        $sessionsheader = new html_table_cell(get_string('sessions', 'mod_zoom'));
        $sessionsheader->header = true;
        $rowsessions->cells = array($sessionsheader, $sessionslink);
        $table->data[] = $rowsessions;
    }

    echo html_writer::table($table);
    echo $OUTPUT->box_end();
}

if ($zoom->show_security) {
    echo $OUTPUT->box_start('', 'zoom_section-security');
    echo $OUTPUT->heading(get_string('security', 'mod_zoom'), 3);

    $table = new html_table();
    $table->attributes['class'] = 'generaltable mod_view';
    $table->align = array('center', 'left');
    $table->size = array('35%', '65%');

    $haspassword = (isset($zoom->password) && $zoom->password !== '');
    $strhaspass = ($haspassword) ? $stryes : $strno;
    $canviewjoinurl = has_capability('mod/zoom:viewjoinurl', $context);

    $rowhaspass = new html_table_row();
    $rowhaspass->id = 'zoom_security-haspass';
    $haspassheader = new html_table_cell($strpassprotect);
    $haspassheader->header = true;
    $rowhaspass->cells = array($haspassheader, $strhaspass);
    $table->data[] = $rowhaspass;

    if ($haspassword && ($canviewjoinurl || get_config('zoom', 'displaypassword'))) {
        $rowpassword = new html_table_row();
        $rowpassword->id = 'zoom_security-password';
        $passwordheader = new html_table_cell($strpassword);
        $passwordheader->header = true;
        $rowpassword->cells = array($passwordheader, s($zoom->password));
        $table->data[] = $rowpassword;
    }

    if ($canviewjoinurl) {
        $rowjoinurl = new html_table_row();
        $rowjoinurl->id = 'zoom_security-joinurl';
        $joinurlheader = new html_table_cell($strjoinlink);
        $joinurlheader->header = true;
        $rowjoinurl->cells = array($joinurlheader, html_writer::link($zoom->join_url, s($zoom->join_url), array('target' => '_blank')));
        $table->data[] = $rowjoinurl;
    }

    if (!$zoom->webinar) {
        if ($config->showencryptiontype != ZOOM_ENCRYPTION_DISABLE) {
            $strenc = ($zoom->option_encryption_type === ZOOM_ENCRYPTION_TYPE_E2EE)
                ? $strencryptionendtoend
                : $strencryptionenhanced;
            $rowencryption = new html_table_row();
            $rowencryption->id = 'zoom_security-encryption';
            $encryptionheader = new html_table_cell($strencryption);
            $encryptionheader->header = true;
            $rowencryption->cells = array($encryptionheader, $strenc);
            $table->data[] = $rowencryption;
        }
    }

    if (!$zoom->webinar) {
        $strwr = ($zoom->option_waiting_room) ? $stryes : $strno;
        $rowwaitingroom = new html_table_row();
        $rowwaitingroom->id = 'zoom_security-waitingroom';
        $waitingroomheader = new html_table_cell($strwwaitingroom);
        $waitingroomheader->header = true;
        $rowwaitingroom->cells = array($waitingroomheader, $strwr);
        $table->data[] = $rowwaitingroom;
    }

    if (!$zoom->webinar) {
        $strjbh = ($zoom->option_jbh) ? $stryes : $strno;
        $rowjoinbeforehost = new html_table_row();
        $rowjoinbeforehost->id = 'zoom_security-joinbeforehost';
        $joinbeforehostheader = new html_table_cell($strjoinbeforehost);
        $joinbeforehostheader->header = true;
        $rowjoinbeforehost->cells = array($joinbeforehostheader, $strjbh);
        $table->data[] = $rowjoinbeforehost;
    }

    $rowauthenticatedusers = new html_table_row();
    $rowauthenticatedusers->id = 'zoom_security-authenticatedusers';
    $authenticatedusersheader = new html_table_cell($strauthenticatedusers);
    $authenticatedusersheader->header = true;
    $rowauthenticatedusers->cells = array($authenticatedusersheader, $zoom->option_authenticated_users ? $stryes : $strno);
    $table->data[] = $rowauthenticatedusers;

    echo html_writer::table($table);
    echo $OUTPUT->box_end();
}

if ($zoom->show_media) {
    echo $OUTPUT->box_start('', 'zoom_section-media');
    echo $OUTPUT->heading(get_string('media', 'mod_zoom'), 3);

    $table = new html_table();
    $table->attributes['class'] = 'generaltable mod_view';
    $table->align = array('center', 'left');
    $table->size = array('35%', '65%');

    if (!$zoom->webinar) {
        $strvideohost = ($zoom->option_host_video) ? $stryes : $strno;
        $rowshowhostvideo = new html_table_row();
        $rowshowhostvideo->id = 'zoom_media-showhostvideo';
        $showhostvideoheader = new html_table_cell($strstartvideohost);
        $showhostvideoheader->header = true;
        $rowshowhostvideo->cells = array($showhostvideoheader, $strvideohost);
        $table->data[] = $rowshowhostvideo;
    }

    if (!$zoom->webinar) {
        $strparticipantsvideo = ($zoom->option_participants_video) ? $stryes : $strno;
        $rowstartvideopart = new html_table_row();
        $rowstartvideopart->id = 'zoom_media-startvideopart';
        $startvideopartheader = new html_table_cell($strstartvideopart);
        $startvideopartheader->header = true;
        $rowstartvideopart->cells = array($startvideopartheader, $strparticipantsvideo);
        $table->data[] = $rowstartvideopart;
    }

    $rowaudioopt = new html_table_row();
    $rowaudioopt->id = 'zoom_media-audioopt';
    $audiooptheader = new html_table_cell($straudioopt);
    $audiooptheader->header = true;
    $rowaudioopt->cells = array($audiooptheader, get_string('audio_' . $zoom->option_audio, 'mod_zoom'));
    $table->data[] = $rowaudioopt;

    $rowmuteuponentry = new html_table_row();
    $rowmuteuponentry->id = 'zoom_media-muteuponentry';
    $muteuponentryheader = new html_table_cell($strmuteuponentry);
    $muteuponentryheader->header = true;
    $rowmuteuponentry->cells = array($muteuponentryheader, ($zoom->option_mute_upon_entry) ? $stryes : $strno);
    $table->data[] = $rowmuteuponentry;

    if (
        !$showrecreate
        && ($zoom->option_audio === ZOOM_AUDIO_BOTH || $zoom->option_audio === ZOOM_AUDIO_TELEPHONY)
        && has_capability('mod/zoom:viewdialin', $context)
    ) {
        $meetinginvite = zoom_webservice()->get_meeting_invitation($zoom)->get_display_string($cm->id);
        if (!empty($meetinginvite)) {
            $meetinginvitetext = nl2br(s($meetinginvite));
            $showbutton = html_writer::tag(
                'button',
                $strmeetinginviteshow,
                array('id' => 'show-more-button', 'class' => 'btn btn-link pt-0 pl-0', 'onclick' => 'var el=document.getElementById("show-more-body");el.style.display=el.style.display==="none"?"block":"none";return false;')
            );
            $meetinginvitebody = html_writer::div(
                $meetinginvitetext,
                '',
                array('id' => 'show-more-body', 'style' => 'display: none;')
            );
            $rowmeetinginvite = new html_table_row();
            $rowmeetinginvite->id = 'zoom_media-meetinginvite';
            $meetinginviteheader = new html_table_cell($strmeetinginvite);
            $meetinginviteheader->header = true;
            $rowmeetinginvite->cells = array($meetinginviteheader, html_writer::div($showbutton . $meetinginvitebody, ''));
            $table->data[] = $rowmeetinginvite;
        }
    }

    echo html_writer::table($table);
    echo $OUTPUT->box_end();
}

if ($config->showallmeetings != ZOOM_ALLMEETINGS_DISABLE) {
    $urlall = new moodle_url('/mod/zoom/index.php', array('id' => $course->id));
    $linkall = html_writer::link($urlall, $strall);
    echo $OUTPUT->box_start('generalbox mt-4 pt-4 border-top text-center');
    echo $linkall;
    echo $OUTPUT->box_end();
}

echo $OUTPUT->footer();
