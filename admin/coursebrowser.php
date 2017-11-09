<?php
//IMathAS: Course browser page
//(c) 2017 David Lippman

/*** Utility functions ***/
function getCourseBrowserJSON() {
  global $DBH;
  $stm = $DBH->query("SELECT id,jsondata FROM imas_courses WHERE (istemplate&16)=16");
  $courseinfo = array();
  while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
    $jsondata = json_decode($row['jsondata'], true);
    if (!isset($jsondata['browser'])) {
      continue;
    }
    $jsondata['browser']['name'] = Sanitize::encodeStringForDisplay($jsondata['browser']['name']);
    $jsondata['browser']['id'] = $row['id'];
    //TODO:  Use ownerid somehow to identify course creator?
    $courseinfo[] = $jsondata['browser'];
  }
  //TODO: Sort courseinfo;
  return json_encode($courseinfo);
}

/*** Start output ***/
$placeinhead .= '<script type="text/javascript">';
$placeinhead .= 'var courseBrowserData = '.getCourseBrowserJSON().';';
$placeinhead .= '</script>';
$placeinhead .= '<script type="text/javascript" src="../javascript/coursebrowser.js" />';
$pagetitle = _('Course Browser');
require("../header.php");

if (!isset($GET['embedded'])) {
  $curBreadcrumb = $breadcrumbbase . ' &gt; '. _('Course Browser');
  echo '<div class=breadcrumb>'.$curBreadcrumb.'</div>';
	echo '<div id="headercoursebrowser" class="pagetitle"><h2>'.$pagetitle.'</h2></div>';
}

echo '<div id="coursebrowserholder">'._('Loading...').'</div>';
require("../footer.php");
