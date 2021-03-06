<?php
/**
 *   https://github.com/Bigjoos/
 *   Licence Info: GPL
 *   Copyright (C) 2010 U-232 v.3
 *   A bittorrent tracker source based on TBDev.net/tbsource/bytemonsoon.
 *   Project Leaders: Mindless,putyn.
 *
 */
require_once (dirname(__FILE__).DIRECTORY_SEPARATOR.'include'.DIRECTORY_SEPARATOR.'bittorrent.php');
require_once (INCL_DIR.'user_functions.php');
require_once (INCL_DIR.'bbcode_functions.php');
dbconn();
loggedinorreturn();
$HTMLOUT = '';
$lang = array_merge(load_language('global') , load_language('index'));
$dt = TIME_NOW;
$res = sql_query("SELECT u.id, u.curr_ann_id, u.curr_ann_last_check, u.last_access, ann_main.subject AS curr_ann_subject, ann_main.body AS curr_ann_body "." FROM users AS u "." LEFT JOIN announcement_main AS ann_main "." ON ann_main.main_id = u.curr_ann_id "." WHERE u.id = ".sqlesc($CURUSER['id'])." AND u.enabled='yes' AND u.status = 'confirmed'") or sqlerr(__FILE__, __LINE__);
$row = mysqli_fetch_assoc($res);
//If curr_ann_id > 0 but curr_ann_body IS NULL, then force a refresh
if (($row['curr_ann_id'] > 0) AND ($row['curr_ann_body'] == NULL)) {
    $row['curr_ann_id'] == 0;
    $row['curr_ann_last_check'] == 0;
}
// If elapsed > 3 minutes, force a announcement refresh.
if (($row['curr_ann_last_check'] != 0) AND (($row['curr_ann_last_check']) < ($dt - 600))
/** 10 mins **/) $row['curr_ann_last_check'] == 0;
if (($row['curr_ann_id'] == 0) AND ($row['curr_ann_last_check'] == 0)) { // Force an immediate check...
    $query = sprintf('SELECT m.*,p.process_id FROM announcement_main AS m '.'LEFT JOIN announcement_process AS p ON m.main_id = p.main_id '.'AND p.user_id = %s '.'WHERE p.process_id IS NULL '.'OR p.status = 0 '.'ORDER BY m.main_id ASC '.'LIMIT 1', sqlesc($row['id']));
    $result = sql_query($query);
    if (mysqli_num_rows($result)) { // Main Result set exists
        $ann_row = mysqli_fetch_assoc($result);
        $query = $ann_row['sql_query'];
        // Ensure it only selects...
        if (!preg_match('/\\ASELECT.+?FROM.+?WHERE.+?\\z/', $query)) die();
        // The following line modifies the query to only return the current user
        // row if the existing query matches any attributes.
        $query.= ' AND u.id = '.sqlesc($row['id']).' LIMIT 1';
        $result = sql_query($query);
        if (mysqli_num_rows($result)) { // Announcement valid for member
            $row['curr_ann_id'] = (int)$ann_row['main_id'];
            // Create two row elements to hold announcement subject and body.
            $row['curr_ann_subject'] = $ann_row['subject'];
            $row['curr_ann_body'] = $ann_row['body'];
            // Create additional set for main UPDATE query.
            $add_set = 'curr_ann_id = '.sqlesc($ann_row['main_id']);
            $mc1->begin_transaction('user'.$CURUSER['id']);
            $mc1->update_row(false, array(
                'curr_ann_id' => $ann_row['main_id']
            ));
            $mc1->commit_transaction($INSTALLER09['expires']['user_cache']);
            $mc1->begin_transaction('MyUser_'.$CURUSER['id']);
            $mc1->update_row(false, array(
                'curr_ann_id' => $ann_row['main_id']
            ));
            $mc1->commit_transaction($INSTALLER09['expires']['curuser']);
            $status = 2;
        } else {
            // Announcement not valid for member...
            $add_set = 'curr_ann_last_check = '.sqlesc($dt);
            $mc1->begin_transaction('user'.$CURUSER['id']);
            $mc1->update_row(false, array(
                'curr_ann_last_check' => $dt
            ));
            $mc1->commit_transaction($INSTALLER09['expires']['user_cache']);
            $mc1->begin_transaction('MyUser_'.$CURUSER['id']);
            $mc1->update_row(false, array(
                'curr_ann_last_check' => $dt
            ));
            $mc1->commit_transaction($INSTALLER09['expires']['curuser']);
            $status = 1;
        }
        // Create or set status of process
        if ($ann_row['process_id'] === NULL) {
            // Insert Process result set status = 1 (Ignore)
            $query = sprintf('INSERT INTO announcement_process (main_id, '.'user_id, status) VALUES (%s, %s, %s)', sqlesc($ann_row['main_id']) , sqlesc($row['id']) , sqlesc($status));
        } else {
            // Update Process result set status = 2 (Read)
            $query = sprintf('UPDATE announcement_process SET status = %s '.'WHERE process_id = %s', sqlesc($status) , sqlesc($ann_row['process_id']));
        }
        sql_query($query);
    } else {
        // No Main Result Set. Set last update to now...
        $add_set = 'curr_ann_last_check = '.sqlesc($dt);
        $mc1->begin_transaction('user'.$CURUSER['id']);
        $mc1->update_row(false, array(
            'curr_ann_last_check' => $dt
        ));
        $mc1->commit_transaction($INSTALLER09['expires']['user_cache']);
        $mc1->begin_transaction('MyUser_'.$CURUSER['id']);
        $mc1->update_row(false, array(
            'curr_ann_last_check' => $dt
        ));
        $mc1->commit_transaction($INSTALLER09['expires']['curuser']);
    }
    unset($result);
    unset($ann_row);
}
$add_set = (isset($add_set)) ? $add_set : 'curr_ann_last_check = 0';
sql_query("UPDATE users SET $add_set WHERE id={$row['id']}") or sqlerr(__FILE__, __LINE__);
// Announcement Code...
$ann_subject = trim($row['curr_ann_subject']);
$ann_body = trim($row['curr_ann_body']);
if ((!empty($ann_subject)) AND (!empty($ann_body))) {
    $HTMLOUT.= "<div class='headline'>{$lang['index_announce']}</div>
    <div class='headbody'>
    <table width='100%' border='1' cellspacing='0' cellpadding='5'>
    <tr><td bgcolor='transparent'><b><font color='red'>Announcement&nbsp;: 
    ".htmlsafechars($ann_subject)."</font></b></td></tr>
    <tr><td style='padding: 10px; background:#333333'>
    ".format_comment($ann_body)."
    <br /><hr /><br />
    Click <a href='{$INSTALLER09['baseurl']}/clear_announcement.php'>
    <i><b>here</b></i></a> to clear this announcement.</td></tr></table></div>\n";
}
if ((empty($ann_subject)) AND (empty($ann_body))) {
    $HTMLOUT.= "<div class='headline'>{$lang['index_announce']}</div>
    <div class='headbody'>
    <table width='100%' border='1' cellspacing='0' cellpadding='5'>
    <tr><td bgcolor='transparent'><b><font color='red'>Announcement&nbsp;: 
    Nothing here</font></b></td></tr>
    <tr><td style='padding: 10px; background:#333333'>
    Currently no new announcements
    <br /><hr /><br />
    </td></tr></table></div>\n";
}
echo stdhead("Announcements").$HTMLOUT.stdfoot();
?>
