<?php // $Id$
/*
 * This file is part of Totara LMS
 *
 * Copyright (C) 2010, 2011 Totara Learning Solutions LTD
 * 
 * This program is free software; you can redistribute it and/or modify  
 * it under the terms of the GNU General Public License as published by  
 * the Free Software Foundation; either version 2 of the License, or     
 * (at your option) any later version.                                   
 *                                                                       
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Simon Coggins <simonc@catalyst.net.nz>
 * @package totara
 * @subpackage reportbuilder 
 */

/*
 * Page for viewing, creating and deleting activity groups
 */

    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
    require_once($CFG->libdir . '/adminlib.php');
    require_once($CFG->libdir . '/ddllib.php');
    require_once($CFG->dirroot . '/totara/reportbuilder/lib.php');
    require_once($CFG->dirroot . '/totara/reportbuilder/groupslib.php');
    require_once($CFG->dirroot . '/totara/reportbuilder/groups_forms.php');

    define('REPORT_BUILDER_GROUPS_CONFIRM_DELETE', 1);
    define('REPORT_BUILDER_GROUPS_FAILED_DELETE', 2);
    define('REPORT_BUILDER_GROUPS_FAILED_CREATE_GROUP', 3);
    define('REPORT_BUILDER_GROUPS_NO_PREPROCESSOR', 4);
    define('REPORT_BUILDER_GROUPS_FAILED_INIT_TABLES', 5);
    define('REPORT_BUILDER_GROUPS_REPORTS_EXIST', 6);

    global $USER;

    $id = optional_param('id',null,PARAM_INT); // id for delete group
    $d = optional_param('d',false, PARAM_BOOL); // delete group?
    $confirm = optional_param('confirm', false, PARAM_BOOL); // confirm delete

    $returnurl = $CFG->wwwroot . '/totara/reportbuilder/groups.php';

    admin_externalpage_setup('activitygroups');

    if($d && $confirm) {
        // delete an existing group
        if(!confirm_sesskey()) {
            totara_set_notification(get_string('error:bad_sesskey','local_reportbuilder'), $returnurl);
        }
        if(delete_group($id)) {
            totara_set_notification(get_string('groupdeleted','local_reportbuilder'), $returnurl, array('class' => 'notifysuccess'));
        } else {
            totara_set_notification(get_string('error:groupnotdeleted','local_reportbuilder'), $returnurl);
        }
    } else if($d) {
        $reports = get_records_select('report_builder',
            "source " . sql_ilike() . " '%grp_$id'");
        if($reports) {
            // can't delete group when reports are using it
            totara_set_notification(get_string('error:grouphasreports','local_reportbuilder'), $returnurl);
            die;
        } else {
            // prompt to delete
            admin_externalpage_print_header();
            print_heading(get_string('activitygroups','local_reportbuilder'));
            notice_yesno(get_string('groupconfirmdelete','local_reportbuilder'),
                "groups.php?id={$id}&amp;d=1&amp;confirm=1&amp;" .
                "sesskey={$USER->sesskey}", $returnurl);

            print_footer();
        }
        die;
    }

    // form definition
    $mform = new report_builder_new_group_form();

    // form results check
    if ($mform->is_cancelled()) {
        redirect($returnurl);
    }
    if ($fromform = $mform->get_data()) {

        if(empty($fromform->submitbutton)) {
            totara_set_notification(get_string('error:unknownbuttonclicked','local_reportbuilder'), $returnurl);
        }

        $errorcode = 'error:groupnotcreated';
        if($newid = create_group($fromform, $errorcode)) {
            redirect($CFG->wwwroot.'/totara/reportbuilder/groupsettings.php?id=' .
                $newid);
            die;
        } else {
            totara_set_notification(get_string($errorcode,'local_reportbuilder'), $returnurl);
            die;
        }

    }

    admin_externalpage_print_header();

    print_heading(get_string('activitygroups','local_reportbuilder'));

    print '<p>' . get_string('activitygroupdesc','local_reportbuilder') . '</p>';

    $tableheader = array(get_string('name','local_reportbuilder'),
                         get_string('tag'),
                         get_string('baseitem','local_reportbuilder'),
                         get_string('activities','local_reportbuilder'),
                         get_string('reports','local_reportbuilder'),
                         get_string('options','local_reportbuilder'));

    $feedbackmoduleid = get_field('modules', 'id', 'name', 'feedback');
    if($feedbackmoduleid) {

        $substr3rdparam = '';
        if ($CFG->dbfamily == 'mssql') {
            $substr3rdparam = ', len(source)';
        }
        $sql = "
            SELECT reports.groupid,g.*, assign.numitems, reports.numreports,
            f.name AS feedbackname, f.id AS feedbackid,
            c.fullname AS coursename, c.id AS courseid,
            cm.id AS cmid, tag.name as tagname
            FROM {$CFG->prefix}report_builder_group g
            LEFT JOIN {$CFG->prefix}feedback f
            ON f.id = " . sql_cast_char2int('g.baseitem') . "
            LEFT JOIN {$CFG->prefix}course c ON f.course = c.id
            LEFT JOIN {$CFG->prefix}course_modules cm ON cm.course = c.id
            AND cm.instance = f.id AND cm.module = $feedbackmoduleid
            LEFT JOIN (
                SELECT groupid,COUNT(id) as numitems
                FROM {$CFG->prefix}report_builder_group_assign
                GROUP BY groupid
            ) assign ON assign.groupid = g.id
            LEFT JOIN (
                SELECT id,name
                FROM {$CFG->prefix}tag
                WHERE tagtype = 'official'
            ) tag ON g.assigntype = 'tag' AND g.assignvalue = tag.id
            LEFT JOIN (
                SELECT " . sql_substr() . "(source, " .
                sql_position("'grp_'", "source"). " + 4{$substr3rdparam}) as groupid,
                count(id) as numreports
                FROM {$CFG->prefix}report_builder
                WHERE source " . sql_ilike() . " '%_grp_%'
                GROUP BY groupid
            ) reports ON " . sql_cast_char2int('reports.groupid') . " = g.id";
        $groups = get_records_sql($sql);
    } else {
        $groups = false;
    }

    if($groups) {
        $data = array();
        foreach($groups as $group) {
            $row = array();
            $strsettings = get_string('settings','local_reportbuilder');
            $strdelete = get_string('delete','local_reportbuilder');
            $strcron = get_string('refreshdataforthisgroup','local_reportbuilder');
            $settings = '<a href="' . $CFG->wwwroot .
                '/totara/reportbuilder/groupsettings.php?id=' . $group->id .
                '" title="' . $strsettings . '">' .
                '<img src="' . $CFG->pixpath . '/t/edit.gif" alt="' .
                $strsettings . '"></a>';
            $delete = '<a href="' . $CFG->wwwroot .
                '/totara/reportbuilder/groups.php?d=1&amp;id=' . $group->id .
                '" title="' . $strdelete . '">' .
                '<img src="' . $CFG->pixpath . '/t/delete.gif" alt="' .
                $strdelete . '"></a>';
            $cron = link_to_popup_window(
                $CFG->wwwroot . '/totara/reportbuilder/runcron.php?group=' .
                $group->id . '&amp;sesskey=' . $USER->sesskey,
                null,
                '<img src="' . $CFG->pixpath . '/t/reload.gif" alt="' .
                $strcron . '">',
                500, 750, $strcron, null, true);

            $row[] = '<a href="' . $CFG->wwwroot .
                '/totara/reportbuilder/groupsettings.php?id=' . $group->id .
                '">' . $group->name . '</a>';
            //$row[] = $group->preproc;
            $row[] = $group->tagname;

            $row[] = '<a href="' . $CFG->wwwroot . '/mod/feedback/view.php?id=' .
                $group->cmid . '">' . $group->feedbackname . '</a>';
            $row[] = ($group->numitems === null) ? 0 : $group->numitems;
            $row[] = ($group->numreports === null) ? 0 : $group->numreports;
            $row[] = "$settings &nbsp; $delete &nbsp; $cron";
            $data[] = $row;
        }

        $table = new object();
        $table->summary = '';
        $table->head = $tableheader;
        $table->data = $data;
        print_table($table);
    } else {
        print '<p>' . get_string('nogroups','local_reportbuilder') . '</p>';
    }
    $mform->display();
    admin_externalpage_print_footer();



// page specific functions

/**
 * Deletes a group
 *
 * @param integer $id ID of the group to delete
 *
 * @return boolean True if successful
 */
function delete_group($id) {
    if (!$id) {
        return false;
    }

    $preproc = get_field('report_builder_group', 'preproc', 'id', $id);
    if(!$preproc) {
        return false;
    }
    $pp = reportbuilder::get_preproc_object($preproc, $id);
    if(!$pp) {
        return false;
    }
    // try to drop group's tables
    if(!$pp->drop_group_tables()) {
        return false;
    }

    // now get rid of any records about this group
    begin_sql();

    // delete the group
    if(!delete_records('report_builder_group', 'id', $id)) {
        rollback_sql();
        return false;
    }

    // delete the group assignments
    if(!delete_records('report_builder_group_assign', 'groupid', $id)) {
        rollback_sql();
        return false;
    }

    // delete any tracking records
    if(!delete_records('report_builder_preproc_track', 'groupid', $id)) {
        rollback_sql();
        return false;
    }

    commit_sql();
    return true;
}

/**
 * Creates a group
 *
 * @param object $fromform Formslib data object to base group on
 * @param integer &$errorcode Error code to return on failure (passed by ref)
 *
 * @return mixed ID of new group if successful, or false
 */
function create_group($fromform, &$errorcode) {
    // create new record here
    $todb = new object();
    $todb->name = addslashes($fromform->name);
    $todb->baseitem = addslashes($fromform->baseitem);
    $todb->preproc = addslashes($fromform->preproc);
    $todb->assigntype = addslashes($fromform->assigntype);
    $todb->assignvalue = addslashes($fromform->assignvalue);

    begin_sql();

    // first create the group
    $newid = insert_record('report_builder_group', $todb);

    if (!$newid) {
        $errorcode = 'error:groupnotcreated';
        return false;
    }

    // group's preprocessor must exist
    $pp = reportbuilder::get_preproc_object($fromform->preproc, $newid);
    if(!$pp) {
        rollback_sql();
        $errorcode = 'error:groupnotcreatedpreproc';
        return false;
    }

    // initialize any tables required by the group's preprocessor
    if(!$pp->is_initialized()) {
        $status = $pp->initialize_group($fromform->baseitem);
        if(!$status) {
            $errorcode = 'error:groupnotcreatedinitfail';
            rollback_sql();
            return false;
        }
    }

    // find any activities that use this tag and add them to the group
    // TODO should make use of transaction too but update_tag_grouping()
    // also uses transactions
    update_tag_grouping($newid);

    commit_sql();
    return $newid;

}