<?php
require_once "../config.php";

use \Tsugi\Util\U;
use \Tsugi\UI\Table;
use \Tsugi\Core\LTIX;
use \Tsugi\Core\User;
use \Tsugi\Core\Link;
use \Tsugi\Grades\GradeUtil;

// Sanity checks
$LAUNCH = LTIX::requireData();
$p = $CFG->dbprefix;

$user_info = false;
$links = array();
$user_sql = false;
$class_sql = false;
$summary_sql = false;

$grade_url = U::reconstruct_query('grades.php', array("detail" => ""));
$index_url = U::reconstruct_query('index.php', array("force" => ""));

if ( U::get($_GET, 'detail') == 'yes') {
    unset($_GET['search_text']);
    unset($_GET['order_by']);
    unset($_GET['desc']);
}

$user_id = $USER->id;
if ( $USER->instructor && isset($_GET['user_id']) ) {
    $user_id = $_GET['user_id'] + 0;
    $user_info = User::loadUserInfoBypass($user_id);
}

// http://stackoverflow.com/questions/5602907/calculate-difference-between-two-datetimes
$query_parms = array(":UID" => $user_id, ":CID" => $CONTEXT->id);
$searchfields = array("L.title", "R.grade", "R.note", "R.updated_at", "retrieved_at");
$orderfields = array("L.title", "R.note", "R.updated_at", "retrieved_at");
$user_sql =
    "SELECT R.result_id AS result_id, L.title as title, R.grade AS grade, R.note AS note,
        R.updated_at as updated_at, server_grade, retrieved_at, sourcedid, result_url, service_key as service,
        TIMESTAMPDIFF(SECOND,retrieved_at,NOW()) as diff_in_seconds, NOW() AS time_now
    FROM {$p}lti_result AS R
    JOIN {$p}lti_link as L ON R.link_id = L.link_id
    LEFT JOIN {$p}lti_service AS S ON R.service_id = S.service_id
    WHERE R.user_id = :UID AND L.context_id = :CID AND R.grade IS NOT NULL";

$menu = false;
if ( $USER->instructor ) {
    $menu = new \Tsugi\UI\MenuSet();
    $menu->addLeft(__('View Student Detail'), $grade_url);
    $force_url = U::add_url_parm($index_url, "force", "yes");
    $menu->addRight(__('Force Reload'), $force_url);
}

$force = U::get($_GET, "force");

// View
$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

echo("<p>Class: ".$CONTEXT->title."</p>\n");

if ( $user_info !== false ) {
    echo("<p>Results for ".$user_info['displayname']."</p>\n");
}
// Table::pagedAuto($user_sql, $query_parms, $searchfields);

// Temporarily make this small since each entry is costly
$DEFAULT_PAGE_LENGTH = 15;
$newsql = Table::pagedQuery($user_sql, $query_parms, $searchfields);
$rows = $PDOX->allRowsDie($newsql, $query_parms);

$retrieval_debug = '';

// Scan to see if there are any un-retrieved server grades
$newrows = array();
foreach ( $rows as $row ) {
    $newrow = $row;
    unset($newrow['result_id']);
    unset($newrow['diff_in_seconds']);
    unset($newrow['time_now']);
    // unset($newrow['server_grade']);
    unset($newrow['sourcedid']);
    unset($newrow['service']);
    unset($newrow['result_url']);
    $newrow['note'] = '';
    if ( $row['grade'] <= 0.0 ) {
        $newrows[] = $newrow;
        continue;
    }

    $diff = $row['diff_in_seconds'];

    // $newrow['note'] = $row['retrieved_at'].' diff='.$diff.' '.
        // $row['server_grade'].' '.$row['sourcedid'].' '.$row['service_key'];

    $RETRIEVE_INTERVAL = 3600; // One Hour
    $newnote['note'] = " ".$diff;

    $remote_grade = U::get($row,'result_url') || (U::get($row,'sourcedid') && U::get($row,'service'));

    $retrieval_debug .= "\n";
    if ( U::get($row,'result_url') ) $retrieval_debug .= "result_url=".U::get($row,'result_url')."\n";
    if ( U::get($row,'service') ) $retrieval_debug .= "service=".U::get($row,'service')."\n";
    if ( U::get($row,'sourcedid') ) $retrieval_debug .= "sourcedid=".U::get($row,'sourcedid')."\n";

    if ( $remote_grade && ( $force || !isset($row['retrieved_at']) || $row['retrieved_at'] < $row['updated_at'] ||
        ! U::get($row, 'server_grade') || U::get($row, 'grade') != U::get($row, 'server_grade') ||
        $diff > $RETRIEVE_INTERVAL ) ) {

        $server_grade = LTIX::gradeGet($row);
        $retrieval_debug .= "Retrieved server grade: ".$server_grade."\n";
        if ( is_string($server_grade) && strlen(trim($server_grade)) == 0 ) $server_grade = 0.0;
        if ( is_string($server_grade)) {
            echo('<pre class="alert alert-danger">'."\n");
            $msg = "result_id=".$row['result_id']."\n".
                "grade=".$row['grade']." updated=".$row['updated_at']."\n".
                "server_grade=".$row['server_grade']." retrieved=".$row['retrieved_at']."\n".
                "error=".$server_grade;

            echo("Problem Retrieving Grade: ".session_safe_id()." ".$msg);
            error_log("Problem Retrieving Grade: ".session_id()."\n".$msg."\n".
              "service=".U::get($row,'service_key')." sourcedid=".U::get($row,'sourcedid'));

            echo("\nProblem Retrieving Grade - Please take a screen shot of this page.\n");
            echo("</pre>\n");
            $newrow['note'] = "Problem Retrieving Server Grade: ".$server_grade;
            $newrows[] = $newrow;
            continue;
        } else {
            $newrow['note'] .= ' Server grade retrieved: '.$server_grade;
        }
        $row['server_grade'] = $server_grade;
        $newrow['server_grade'] = $server_grade;
        $newrow['retrieved_at'] = $row['time_now'];
        $row['retrieved_at'] = $row['time_now'];
    }

    // Now check to see if we need to update the server_grade
    if ( $remote_grade && $row['server_grade'] < $row['grade'] ) {
        error_log("Patching server grade: ".session_id()." result_id=".$row['result_id']."\n".
                "grade=".$row['grade']." updated=".$row['updated_at']."\n".
                "server_grade=".$row['server_grade']." retrieved=".$row['retrieved_at']);

        $debug_log = array();
        $retrieval_debug .= "Sending Tsugi grade: ".$row['grade']."\n";
        $status = LTIX::gradeSend($row['grade'], $row, $debug_log);
        $retrieval_debug .= "Send status: ".$status."\n";

        if ( $status === true ) {
            $server_grade = LTIX::gradeGet($row);
            $retrieval_debug .= "Re-reteived server grade: ".$server_grade."\n";
            if ( is_string($server_grade) && strlen(trim($server_grade)) == 0 ) $server_grade = 0.0;
            $new_row['server_grade'] = $server_grade;
            $row['server_grade'] = $server_grade;
            if ( $server_grade != $row['grade'] ){
                $newrow['note'] .= " Grade re-send mismatch.";
            } else {
                $newrow['note'] .= " Grade re-sent and checked.";
            }
        } else {
            echo('<pre class="alert alert-danger">'."\n");

            $msg = "result_id=".$row['result_id']."\n".
                "grade=".$row['grade']." updated=".$row['updated_at']."\n".
                "server_grade=".$row['server_grade']." retrieved=".$row['retrieved_at']."\n".
                "error=".$server_grade;

            echo("Problem Updating Grade: ".session_safe_id()." ".$msg);
            error_log("Problem Updating Grade: ".session_id()."\n".$msg."\n".
              "service=".U::get(row,'service')." sourcedid=".U::get($row,'sourcedid'));


            echo("\nProblem Retrieving Grade - Please take a screen shot of this page.\n");
            echo("</pre>\n");
            $newrow['note'] .= " Problem Updating Server Grade";
        }
    }

    $newrows[] = $newrow;
}

// Make the grades percentages
$showrows = array();
foreach ( $newrows as $row ) {
    $g = $row['grade'] * 100.0;
    $row['grade'] = sprintf("%1.1f",$g);
    $g = $row['server_grade'] * 100.0;
    $row['server_grade'] = sprintf("%1.1f",$g);
    $showrows[] = $row;
}

$searchfields = array();
Table::pagedTable($showrows, $searchfields, false);

$identity = __("Logged in as: ").$USER->key;
if ( strlen($USER->email) > 0 ) {
    $identity .= ' ' . htmlentities($USER->email);
}
if ( strlen($USER->displayname) > 0 ) {
    $identity .= ' ' . htmlentities($USER->displayname);
}
echo("<p>".$identity."</p>");

if ( strlen(trim($retrieval_debug)) > 0 && $LAUNCH->user->instructor ) {
    echo("<pre>\n");
    echo(htmlentities($retrieval_debug));
    echo("</pre>\n");
}

$OUTPUT->footer();
