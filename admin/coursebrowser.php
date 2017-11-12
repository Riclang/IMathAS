<?php
//IMathAS: Course browser page
//(c) 2017 David Lippman
require("../init.php");
require("../includes/coursebrowserdefs.php");

/*** Utility functions ***/
function getCourseBrowserJSON() {
  global $DBH, $levels, $modes, $contenttypes, $CFG;
  $stm = $DBH->query("SELECT ic.id,ic.jsondata,iu.FirstName,iu.LastName FROM imas_courses AS ic JOIN imas_users AS iu ON ic.ownerid=iu.id WHERE (ic.istemplate&16)=16");
  $courseinfo = array();
  while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
    $jsondata = json_decode($row['jsondata'], true);
    if (!isset($jsondata['browser'])) {
      continue;
    }

    $jsondata['browser']['name'] = Sanitize::encodeStringForDisplay($jsondata['browser']['name']);
    $jsondata['browser']['id'] = $row['id'];
    $jsondata['browser']['owner'] = $row['LastName'].', '.$row['FirstName'];

    //map stored values to display values
    if ($jsondata['browser']['level']=='other') {
      $jsondata['browser']['level'] = $jsondata['browser']['levelother'];
    } else {
      $jsondata['browser']['level'] = $levels[$jsondata['browser']['level']];
    }
    $jsondata['browser']['mode'] = $modes[$jsondata['browser']['mode']];
    if (isset($CFG['browser']['books'])) {
      if ($jsondata['browser']['book']=='other') {
        $jsondata['browser']['book'] = $jsondata['browser']['bookother'];
      } else {
        $jsondata['browser']['book'] = $CFG['browser']['books'][$jsondata['browser']['book']];
      }
    }
    if (!isset($jsondata['browser']['contenttypes'])) {
      $jsondata['browser']['contenttypes'] = array();
    }
    foreach ($jsondata['browser']['contenttypes'] as $k=>$v) {
      $jsondata['browser']['contenttypes'][$k] = $contenttypes[$v];
    }
    //TODO: Remap stored values to full names

    //TODO:  Use ownerid somehow to identify course creator?
    $courseinfo[] = $jsondata['browser'];
  }
  //TODO: Sort courseinfo;
  return json_encode($courseinfo);
}

/*** Start output ***/
$placeinhead = '<script type="text/javascript">';
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
