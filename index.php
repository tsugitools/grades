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

$link_id = 0;
if ( isset($_GET['link_id']) ) {
    $link_id = $_GET['link_id']+0;
}

$link_info = false;
if ( $USER->instructor && $link_id > 0 ) {
    $link_info = Link::loadLinkInfo($link_id);
}

if ( $USER->instructor && isset($_GET['viewall']) && !isset($_GET['user_id']) ) {
    $query_parms = array(":CID" => $CONTEXT->id);
    $orderfields = array("R.user_id", "displayname", "email", "user_key", "grade_count");
    $searchfields = array("R.user_id", "displayname", "email", "user_key");
    $summary_sql =
        "SELECT R.user_id AS user_id, displayname, email, COUNT(grade) AS grade_count, user_key
        FROM {$p}lti_result AS R JOIN {$p}lti_link as L
            ON R.link_id = L.link_id
        JOIN {$p}lti_user as U
            ON R.user_id = U.user_id
        WHERE L.context_id = :CID AND R.grade IS NOT NULL
        GROUP BY R.user_id";

} else if ( $USER->instructor && isset($_GET['link_id'] ) ) {
    $query_parms = array(":LID" => $link_id, ":CID" => $CONTEXT->id);
    $searchfields = array("R.user_id", "displayname", "grade", "R.updated_at", "server_grade", "retrieved_at");
    $class_sql =
        "SELECT R.user_id AS user_id, displayname, grade,
            R.updated_at as updated_at, server_grade, retrieved_at
        FROM {$p}lti_result AS R JOIN {$p}lti_link as L
            ON R.link_id = L.link_id
        JOIN {$p}lti_user as U
            ON R.user_id = U.user_id
        WHERE R.link_id = :LID AND L.context_id = :CID AND R.grade IS NOT NULL";

} else { // Gets grades for the current or specified
    $user_id = $USER->id;
    if ( $USER->instructor && isset($_GET['user_id']) ) {
        $user_id = $_GET['user_id'] + 0;
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
    $user_info = User::loadUserInfoBypass($user_id);
}

if ( $USER->instructor ) {
    $lstmt = $PDOX->queryDie(
        "SELECT DISTINCT L.title as title, L.link_id AS link_id
        FROM {$p}lti_link AS L JOIN {$p}lti_result as R
            ON L.link_id = R.link_id AND R.grade IS NOT NULL
        WHERE L.context_id = :CID",
        array(":CID" => $CONTEXT->id)
    );
    $links = $lstmt->fetchAll();
}
// View
$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav();
$OUTPUT->flashMessages();

if ( $USER->instructor ) {
?>
  <a href="index.php?viewall=yes" class="btn btn-default">Class Summary</a>
  <a href="index.php" class="btn btn-default">My Grades</a>
<?php
if ( $links !== false && count($links) > 0 ) {
?>
  <div class="btn-group">
    <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown">
      Activities
      <span class="caret"></span>
    </button>
    <ul class="dropdown-menu">
<?php
    foreach($links as $link) {
        echo('<li><a href="#" onclick="window.location=\'');
        echo(addSession('index.php?link_id='.$link['link_id']).'\';">');
        echo(htmlent_utf8($link['title']));
        echo("</a></li>\n");
    }
?>
    </ul>
  </div>
<?php } ?>
<p></p>
<?php
}

echo("<p>Class: ".$CONTEXT->title."</p>\n");

if ( $user_sql !== false ) {
    if ( $user_info !== false ) {
        echo("<p>Results for ".$user_info['displayname']."</p>\n");
    }
    // Table::pagedAuto($user_sql, $query_parms, $searchfields);

    // Temporarily make this small since each entry is costly
    $DEFAULT_PAGE_LENGTH = 10;
    $newsql = Table::pagedQuery($user_sql, $query_parms, $searchfields);
    // echo("<pre>\n$newsql\n</pre>\n");
    $rows = $PDOX->allRowsDie($newsql, $query_parms);

    // echo("<pre>\n");var_dump($rows);echo("</pre>\n");
    // Scan to see if there are any un-retrieved server grades
    $newrows = array();
    foreach ( $rows as $row ) {
        $newrow = $row;
        unset($newrow['result_id']);
        unset($newrow['diff_in_seconds']);
        unset($newrow['time_now']);
        unset($newrow['server_grade']);
        unset($newrow['sourcedid']);
        unset($newrow['service']);
        $newrow['note'] = '';
        if ( $row['grade'] <= 0.0 ) {
            $newrows[] = $newrow;
            continue;
        }

        $diff = $row['diff_in_seconds'];

        // $newrow['note'] = $row['retrieved_at'].' diff='.$diff.' '.
            // $row['server_grade'].' '.$row['sourcedid'].' '.$row['service'];

        $RETRIEVE_INTERVAL = 14400; // Four Hours
        $newnote['note'] = " ".$diff;

        $remote_grade = U::get($row,'result_url') || (U::get($row,'sourcedid') && U::get($row,'service_url'));

        if ( $remote_grade && ( !isset($row['retrieved_at']) || $row['retrieved_at'] < $row['updated_at'] ||
            $diff > $RETRIEVE_INTERVAL ) ) {

            $server_grade = LTIX::gradeGet($row);
            if ( is_string($server_grade)) {
                echo('<pre class="alert alert-danger">'."\n");
                $msg = "result_id=".$row['result_id']."\n".
                    "grade=".$row['grade']." updated=".$row['updated_at']."\n".
                    "server_grade=".$row['server_grade']." retrieved=".$row['retrieved_at']."\n".
                    "error=".$server_grade;

                echo("Problem Retrieving Grade: ".session_safe_id()." ".$msg);
                error_log("Problem Retrieving Grade: ".session_id()."\n".$msg."\n".
                  "service=".U::get(row,'service')." sourcedid=".U::get($row,'sourcedid'));

                echo("\nProblem Retrieving Grade - Please take a screen shot of this page.\n");
                echo("</pre>\n");
                $newrow['note'] = "Problem Retrieving Server Grade: ".$server_grade;
                $newrows[] = $newrow;
                continue;
            } else if ( $server_grade > 0.0 ) {
                $newrow['note'] .= ' Server grade retrieved.';
            } else {
                $newrow['note'] .= ' Server grade checked.';
            }
            $row['server_grade'] = $server_grade;
            $newrow['retrieved_at'] = $row['time_now'];
            $row['retrieved_at'] = $row['time_now'];
        }

        // Now check to see if we need to update the server_grade
        if ( $remote_grade && $row['server_grade'] < $row['grade'] ) {
            error_log("Patching server grade: ".session_id()." result_id=".$row['result_id']."\n".
                    "grade=".$row['grade']." updated=".$row['updated_at']."\n".
                    "server_grade=".$row['server_grade']." retrieved=".$row['retrieved_at']);

            $debug_log = array();
            $status = LTIX::gradeSend($row['grade'], $row, $debug_log);

            if ( $status === true ) {
                $newrow['note'] .= " Server grade updated.";
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
        $showrows[] = $row;
    }

    Table::pagedTable($showrows, $searchfields, $orderfields);
}

if ( $summary_sql !== false ) {
    Table::pagedAuto($summary_sql, $query_parms, $searchfields, $orderfields);
}

if ( $class_sql !== false ) {
    if ( $link_info !== false ) {
        echo("<p>Results for ".$link_info['title']);
        echo(' (<a href="maint.php?link_id='.$link_id.'" target="_new">Maintenance
            tasks</a>)'."</p>\n");
    }
    Table::pagedAuto($class_sql, $query_parms, $searchfields);
}


$OUTPUT->footer();
