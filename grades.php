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

if ( ! $LAUNCH->user->instructor ) die('Requires instructor');

$user_info = false;
$links = array();
$class_sql = false;
$summary_sql = false;

$link_id = 0;
if ( isset($_GET['link_id']) ) {
    $link_id = $_GET['link_id']+0;
}

$link_info = false;
if ( $link_id > 0 ) {
    $link_info = Link::loadLinkInfo($link_id);
}

if ( isset($_GET['link_id'] ) ) {
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
} else {
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
}

$lstmt = $PDOX->queryDie(
    "SELECT DISTINCT L.title as title, L.link_id AS link_id
    FROM {$p}lti_link AS L JOIN {$p}lti_result as R
        ON L.link_id = R.link_id AND R.grade IS NOT NULL
    WHERE L.context_id = :CID",
    array(":CID" => $CONTEXT->id)
);
$links = $lstmt->fetchAll();

$menu = new \Tsugi\UI\MenuSet();
$menu->addLeft(__('View My Grades'), 'index.php');
if ( $links !== false && count($links) > 0 ) {
    $submenu = new \Tsugi\UI\Menu();
    foreach($links as $link) {
        $submenu->addLink($link['title'], 'grades.php?link_id='.$link['link_id']);
    }
    $menu->addRight(__('Activity Detail'), $submenu);
}

// View
$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

echo("<p>Class: ".$CONTEXT->title."</p>\n");
if ( $link_info ) echo("<p>Link: ".$link_info["title"]."</p>\n");

if ( $summary_sql !== false ) {
    Table::pagedAuto($summary_sql, $query_parms, $searchfields, $orderfields, "index.php?detail=yes");
}

if ( $class_sql !== false ) {
/*
    if ( $link_info !== false ) {
        echo("<p>Results for ".$link_info['title']);
        echo(' (<a href="maint.php?link_id='.$link_id.'" target="_new">Maintenance
            tasks</a>)'."</p>\n");
    }
 */
    Table::pagedAuto($class_sql, $query_parms, $searchfields, $searchfields, "index.php?detail=yes");
}


$OUTPUT->footer();
