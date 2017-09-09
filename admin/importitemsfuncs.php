<?php
//IMathAS:  Course item import processing funcs
//JSON edition
//(c) 2017 David Lippman

require_once("../includes/htmLawed.php");

//get item info for confirmation step
function getsubinfo($items,$parent,$pre) {
	global $ids,$types,$names,$data,$parents;
	foreach($items as $k=>$anitem) {
		if (is_array($anitem)) {
			$ids[] = $parent.'-'.($k+1);
			$types[] = $pre."Block";
			$names[] = $anitem['name'];
			$parents[] = $parent;
			getsubinfo($anitem['items'],$parent.'-'.($k+1),$pre.'--');
		} else {
			$ids[] = $anitem;
			$parents[] = $parent;
			$types[] = $pre.$data['items'][$anitem]['type'];
			if (isset($data['items'][$anitem]['data']['name'])) {
				$names[] = $data['items'][$anitem]['data']['name'];
			} else {
				$names[] = $data['items'][$anitem]['data']['title'];
			}
		}
	}
}

//make a list of items to import
function extractItemsToImport($items,&$addtoarr, $checked) {
	foreach ($items as $k=>$anitem) {
		if (is_array($anitem)) {
			extractItemsToImport($anitem['items'], $addtoarr);
		} else {
			if ($checked===true || array_search($anitem,$checked)!==FALSE) {
				$addtoarr[] = $anitem;
			}
		}
	}
}
//get a list of questions from an assessment
function getAssessQids($arr) {
	$qs = array();
	foreach ($arr as $v) {
		if (is_array($v)) {
			for ($i=(strpos($subs[0],'|')!==false)?1:0;$i<count($v);$i++) {
				$qs[] = $v[$i];
			}
		} else {
			$qs[] = $v;
		}
	}
	return $qs;
}

function copysub($items,$parent,$checked,&$addtoarr,&$blockcnt,$itemmap) {
	global $db_fields;
	foreach ($items as $k=>$anitem) {
		if (is_array($anitem)) {
			if ($checked===true || array_search($parent.'-'.($k+1),$checked)!==FALSE) { //copy block
				$newblock = array();
				$newblock['id'] = $blockcnt;
				$blockcnt++;
				foreach ($db_fields['block'] as $field) {
					$newblock[$field] = $item[$field];
				}
				$newblock['items'] = array();
				copysub($anitem['items'],$parent.'-'.($k+1),$newblock['items']);
				$addtoarr[] = $newblock;
			} else {
				copysub($anitem['items'],$parent.'-'.($k+1),$addtoarr);
			}
		} else {
			if ($checked===true || array_search($anitem,$checked)!==FALSE) {
				$addtoarr[] = $itemmap[$anitem];
			}
		}
	}
}
function getMappedOwnerid($sourceinstall, $listedowner, $backupowner) {
	if (isset($GLOBALS['mapusers']) && isset($GLOBALS['mapusers'][$sourceinstall][$listedowner])) {
		return $GLOBALS['mapusers'][$sourceinstall][$listedowner];
	} else {
		return $backupowner;
	}
}

//do the data import
//$data:  parsed JSON array
//$cid:	course ID to import into
//$checked:  The array of checked items to import, or boolean TRUE to import all
//$options:  Array of options:
//   	ownerid: set to provide import ownerid. Otherwise executing user is owner
//	importcourseopt: import course settings (overwrites existing)
//	importgbsetup: import gb scheme and cats (overwrites existing)
//	update: for questions; 1 for update if newer, 2 for force update, 0 for no update
//	userights: userights for imported q's.  -1 to use rights in export file
//	importlib: library for imported q's
//	importstickyposts: import sticky posts
//	importoffline: import offline grade items
//	importcalitems: import calendar items
function importdata($data, $cid, $checked, $options) {
	global $userid, $db_fields, $CFG, $DBH, $myrights;
	
	$now = time();
	
	if (!empty($options['ownerid'])) {
		$importowner = $options['ownerid'];
	} else {
		$importowner = $userid;
	}
	if (!isset($options['userights'])) {
		$options['userights'] = -1;	
	}
	if (!isset($options['importlib'])) {
		$options['importlib'] = 0;
	}
	
	$stm = $DBH->prepare("SELECT itemorder,blockcnt FROM imas_courses WHERE id=?");
	$stm->execute(array($cid));
	list($itemorder,$blockcnt) = $stm->fetch(PDO::FETCH_NUM);
	$courseitems = unserialize($itemorder);
	
	//set course options
	if (!empty($options['importcourseopt'])) {
		$db_fields['course'] = explode(',', $db_fields['course']);
		$sets = array();
		$exarr = array();
		if (!isset($CFG['CPS'])) { $CFG['CPS'] = array();}
		foreach ($db_fields['course'] as $field) {
			//check if in export, and if CFG allows setting
			if (isset($data['course'][$field]) && (!isset($CFG['CPS'][$field]) || $CFG['CPS'][$field][1]!=0)) {
				$sets[] = $field.'=?';
				$exarr[] = $data['course'][$field];
			}
		}
		if (count($sets)>0) {
			$exarr[] = $cid;
			$stm = $DBH->prepare("UPDATE imas_courses SET ".implode(',',$sets)." WHERE id=?");
			$stm->execute($exarr);
		}
	}
	
	$gbmap = array(0=>0);
	//import gbscheme and gbcats if importgbsetup is set
	//  we'll overwrite gbscheme, and delete any existing gbcats
	if (!empty($options['importgbsetup'])) {
		//clear any existing gbcats
		$stm = $DBH->prepare("DELETE FROM imas_gbcats WHERE courseid=?");
		$stm->execute(array($cid));
		
		if (count($data['gbcats'])>0) {
			//unset any fields we don't have
			$db_fields['gbcats'] = explode(',', $db_fields['gbcats']);
			//only keep values in db_fields that are also keys of first gb_cat
			$db_fields['gbcats'] = array_values(array_intersect($db_fields['gbcats'], array_flip($data['gbcats'][1])));
			$exarr = array();
			foreach ($data['gbcats'] as $i=>$row) {
				$exarr[] = $cid;
				foreach ($db_fields['gbcats'] as $field) {
					$exarr[] = $row[$field];
				}
			}
			$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,count($db_fields['gbcats'])+1);
			$stm = $DBH->prepare("INSERT INTO imas_gbcats (courseid,".implode(',',$db_fields['gbcats']).") VALUES $ph");
			$stm->execute($exarr);
			$firstgbcat = $DBH->lastInsertId();
			//first gbcat has an index of 1 to distinguish it from default, so need to offset incrementer
			foreach ($data['gbcats'] as $i=>$row) {
				$gbmap[$i] = $firstgbcat+($i-1);
			}
		}
		
		//replace gbscheme
		$db_fields['gbscheme'] = explode(',', $db_fields['gbscheme']);
		foreach ($db_fields['gbscheme'] as $field) {
			if (isset($data['gbscheme'][$field])) {
				$sets[] = $field.'=?';
				$exarr[] = $data['gbscheme'][$field];
			}
		}
		if (count($sets)>0) {
			$exarr[] = $cid;
			$stm = $DBH->prepare("UPDATE imas_gbscheme SET ".implode(',',$sets)." WHERE courseid=?");
			$stm->execute($exarr);
		}
	}
	
	//figure out which items to import
	extractItemsToImport($data['course']['itemorder'], $itemstoimport, $checked);
	
	$typemap = array();
	
	//figure out what questionsets we're importing by looping through items
	$qstoimport = array();
	foreach ($itemstoimport as $item) {
		if ($data['items'][$item]['type']=='Assessment') {
			$qids = getAssessQids($data['items'][$item]['data']['itemorder']);
			foreach ($qids as $qid) {
				$qsid = $data['questions'][$qid]['questionsetid'];
				$qstoimport[] = $qsid;
				if (isset($data['questionset'][$qsid]['dependencies'])) {
					$qstoimport = array_merge($qstoimport, $data['questions'][$qsid]['dependencies']);
				}
			}
		} else if ($data['items'][$item]['type']=='Drill') {
			$qstoimport = array_merge($qstoimport, $data['items'][$item]['data']['itemids']);
		}
	}
	$qstoimport = array_unique($qstoimport);
	$qsuidmap = array();
	foreach ($qstoimport as $qsid) {
		$qsuidmap[$data['questionset'][$qsid]['uniqueid']] = $qsid;
	}
	
	//prep DB fields
	$db_fields['questionset'] = explode(',', $db_fields['questionset']);
	//only keep values in db_fields that are also keys of first questionset
	if (count($data['questionset'])>0) {
		$db_fields['questionset'] = array_values(array_intersect($db_fields['questionset'], array_flip($data['questionset'][0])));
	}
	$questionset_sets = implode('=?,', $db_fields['questionset']).'=?';
	$update_qset_stm = $DBH->prepare("UPDATE imas_questionset SET $questionset_sets WHERE id=?");
	
	//now pull existing questions to setup qsmap. Update as appropriate
	$ph = Sanitize::generateQueryPlaceholders($qsuids);
	$stm = $DBH->prepare("SELECT id,uniqueid,lastmoddate,deleted,ownerid,userights FROM imas_questionset WHERE uniqueid IN ($ph)");
	$stm->execute(array_keys($qsuids));
	$qsmap = array();
	$toresolve = array();
	$qimgs = array();
	$qmodcnt = 0;
	while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
		//set up map of export id => local id
		$exportqid = $qsuidmap[$row['uniqueid']];
		$qsmap[$exportqid] = $row['id'];
		$exportlastmod = $data['questionset'][$exportqid]['lastmoddate'];
		if ($row['deleted']==1 || ($options['update']==2 && $myrights==100) || 
			($options['update']==1 && $exportlastmod>$row['lastmoddate'] && ($row['ownerid']==$importowner || $row['userights']>3 || $myrights==100))) {
			
			//update question
			$exarr = array();
			if ($row['deleted']==0) {
				//don't change owner unless undeleting
				$data['questionset'][$exportqid]['ownerid'] = $row['ownerid'];
			} else {
				$data['questionset'][$exportqid]['ownerid'] = getMappedOwnerid($data['sourceinstall'],$data['questionset'][$exportqid]['ownerid'],$importowner);
			}
			foreach ($db_fields['questionset'] as $field) {
				$exarr[] = $data['questionset'][$exportqid][$field];
			}
			$exarr[] = $row['id'];
			$update_qset_stm->execute($exarr);
			$qmodcnt++;
			if (isset($data['questionset'][$exportqid]['dependencies'])) {
				$toresolve[] = $exportqid;
			}
			if ($data['questionset'][$exportqid]['hasimg']==1 && count($data['questionset'][$exportqid]['qimgs'])>0) {
				$qimgs[$exportqid] = $data['questionset'][$exportqid]['qimgs'];
			}
		}
	}
	
	//figure out which questions we need to add and add them
	$qstoadd = array_diff($qstoimport, array_keys($qsmap));
	$exarr = array();
	$tomap = array();
	foreach ($qstoadd as $exportqid) {
		$tomap[] = $exportqid;
		$data['questionset'][$exportqid]['ownerid'] = getMappedOwnerid($data['sourceinstall'],$data['questionset'][$exportqid]['ownerid'],$importowner);
		$data['questionset'][$exportqid]['adddate'] = $now;
		$data['questionset'][$exportqid]['lastmoddate'] = $now;
		if (isset($data['questionset'][$exportqid]['dependencies'])) {
			$toresolve[] = $exportqid;
		}
		if ($options['userights']>-1) {
			$data['questionset'][$exportqid]['userights'] = $options['userights'];
		}
		foreach ($db_fields['questionset'] as $field) {
			$exarr[] = $data['questionset'][$exportqid][$field];
		}
		if ($data['questionset'][$exportqid]['hasimg']==1 && count($data['questionset'][$exportqid]['qimgs'])>0) {
			$qimgs[$exportqid] = $data['questionset'][$exportqid]['qimgs'];
		}
		if (count($exarr)>2000) { //do a batch add
			$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,count($db_fields['questionset']));
			$stm = $DBH->prepare("INSERT INTO imas_questionset (".implode(',',$db_fields['questionset']).") VALUES $ph");
			$stm->execute($exarr);
			$firstqsid = $DBH->lastInsertId();
			foreach ($tomap as $k=>$tomapeqid) {
				$qsmap[$tomapeqid] = $firstqsid+$k;
			}
			$tomap = array();
			$exarr = array();
		}
	}
	if (count($exarr)>0) { //final batch add
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,count($db_fields['questionset']));
		$stm = $DBH->prepare("INSERT INTO imas_questionset (".implode(',',$db_fields['questionset']).") VALUES $ph");
		$stm->execute($exarr);
		$firstqsid = $DBH->lastInsertId();
		foreach ($tomap as $k=>$tomapeqid) {
			$qsmap[$tomapeqid] = $firstqsid+$k;
		}
	}
	
	//add question images
	$todelqimg = array();
	$exarr = array();
	foreach ($qimgs as $eqsid->$qimgarr) {
		$todelqimg[] = $qsmap[$eqsid];
		foreach ($qimgarr as $v) {
			//rehost image.  prepend with question ID to prevent conflicts
			$newfn = rehostfile($v['filename'], 'qimages', $qsmap[$eqsid].'-');
			if ($newfn!==false) {
				$exarr[] = $qsmap[$eqsid];
				$exarr[] = $v['var'];
				$exarr[] = $newfn;
				$exarr[] = $v['alttext'];
			}
		}
	}
	if (count($exarr)>0) {
		//we'll be lazy and delete any existing qimages for these questions
		$ph = Sanitize::generateQueryPlaceholders($todelqimg);
		$stm = $DBH->prepare("DELETE FROM imas_qimages WHERE qsetid IN ($ph)");
		$stm->execute($todelqimg);
		//insert new qimage records
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,4);
		$stm = $DBH->prepare("INSERT INTO imas_qimages (qsetid,var,filename,alttext) VALUES $ph");
		$stm->execute($exarr);
	}
	
	//add library items for inserted questions
	$exarr = array();
	foreach ($qstoadd as $exportqid) {
		$exarr[] = $options['importlib'];
		$exarr[] = $qsmap[$exportqid];
		//already remapped ownerid in $data for question; use it for lib item too
		$exarr[] = $data['questionset'][$exportqid]['ownerid'];
		$exarr[] = $now;
	}
	if (count($exarr)>0) {
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,4);
		$stm = $DBH->prepare("INSERT INTO imas_library_items (libid,qsetid,ownerid,lastmoddate) VALUES $ph");
		$stm->execute($exarr);
	}
	
	//resolve include___from dependencies by updating
	$upd_qset_include = $DBH->prepare("UPDATE imas_questionset SET control=?,qtext=? WHERE id=?");
	foreach ($toresolve as $exportqid) {
		$data['questionset'][$exportqid]['control'] = preg_replace_callback('/includecodefrom\(EID(\d+)\)/', 
			function($matches) use ($qsmap) {
				  return "includecodefrom(".$qsmap[$matches[1]].")";
			}, $data['questionset'][$exportqid]['control']);
		$data['questionset'][$exportqid]['qtext'] = preg_replace_callback('/includeqtextfrom\(EID(\d+)\)/', 
			function($matches) use ($qsmap) {
				  return "includeqtextfrom(".$qsmap[$matches[1]].")";
			}, $data['questionset'][$exportqid]['qtext']);
		$upd_qset_include->execute(array($data['questionset'][$exportqid]['control'], $data['questionset'][$exportqid]['qtext'], $qsmap[$exportqid]));
	}
	
	//group items to export by type
	$toimportbytype = array();
	foreach ($itemstoimport as $itemtoimport) {
		if (!isset($toimportbytype[$data['items'][$itemtoimport]['type']])) {
			$toimportbytype[$data['items'][$itemtoimport]['type']] = array();
		}
		$toimportbytype[$data['items'][$itemtoimport]['type']][] = $itemtoimport;
	}
	
	//insert the inlinetext items
	if (isset($toimportbytype['InlineText'])) {
		$typemap['InlineText'] = array();
		$exarr = array();
		$toresolve = array();
		$db_fields['inlinetext'] = array_values(array_intersect($db_fields['inlinetext'], array_flip($data['items'][$toimportbytype['InlineText'][0]]['data'])));
		foreach ($toimportbytype['InlineText'] as $toimport) {
			$thisinline = $data['items'][$toimport]['data'];
			if (is_array($thisinline['fileorder'])) {
				$toresolve[] = $toimport;
				$thisinline['fileorder'] = '';
			}
			//sanitize html fields
			foreach ($db_fields['html']['inlinetext'] as $field) {
				$thisinline[$field] = myhtmlawed($thisinline[$field]);
			}
			$exarr[] = $cid;
			foreach ($db_fields['inlinetext'] as $field) {
				$exarr[] = $thisinline[$field];
			}
		}
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,count($db_fields['inlinetext'])+1);
		$stm = $DBH->prepare("INSERT INTO imas_inlinetext (courseid,".implode(',',$db_fields['inlinetext']).") VALUES $ph");
		$stm->execute($exarr);
		$firstinsid = $DBH->lastInsertId();
		foreach ($toimportbytype['InlineText'] as $toimport) {
			$typemap['InlineText'][$toimport] = $firstinsid+$k;
		}
		
		//resolve any fileorders
		$exarr = array();
		foreach ($toresolve as $tohandle) {
			foreach($data['items'][$tohandle]['data']['fileorder'] as $filearr) {
				//rehost file
				$newfn = rehostfile($filearr[1], 'cfiles/'.$cid);
				if ($newfn!==false) {
					$exarr[] = $filearr[0]; //description
					$exarr[] = $cid.'/'.$newfn; //filename 
					$exarr[] = $typemap['InlineText'][$tohandle];
				}
			}
		}
		if (count($exarr)>0) {
			$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,3);
			$stm = $DBH->prepare("INSERT INTO imas_inlinetext (description,filename,itemid) VALUES $ph");
			$stm->execute($exarr);
			$firstinsid = $DBH->lastInsertId();
			$fcnt = 0;
			$inline_file_upd_stm = $DBH->prepare("UPDATE imas_inlinetext SET fileorder=? WHERE id=?");
			foreach ($toresolve as $tohandle) {
				$thisfileorder = array();
				for ($i=0;$i<count($data['items'][$tohandle]['data']['fileorder']);$i++) {
					$thisfileorder[] = $firstinsid+$fcnt;
					$fcnt++;
				}
				$inline_file_upd_stm->execute(array(implode(',', $thisfileorder), $typemap['InlineText'][$tohandle]));
			}
		}
	}
	
	//insert the linkedtext items
	if (isset($toimportbytype['LinkedText'])) {
		$typemap['LinkedText'] = array();
		$exarr = array();
		$db_fields['linkedtext'] = array_values(array_intersect($db_fields['linkedtext'], array_flip($data['items'][$toimportbytype['LinkedText'][0]]['data'])));
		foreach ($toimportbytype['LinkedText'] as $toimport) {
			if ($data['items'][$toimport]['rehostfile']==true && substr($data['items'][$toimport]['data']['text'],0,4)=='http') {
				//rehost file and change weblink to file:
				$fileurl = substr($data['items'][$toimport]['data']['text'],5);
				$newfn = rehostfile($fileurl, 'cfiles/'.$cid);
				if ($newfn!==false) {
					$data['items'][$toimport]['data']['text'] = 'file:'.$cid.'/'.$newfn;	
				}	
			} else if (substr($data['items'][$toimport]['data']['text'],0,8)=='exttool:') {
				//remap gbcategory
				$parts = explode('~~',substr($data['items'][$toimport]['data']['text'],8));
				if (isset($parts[3])) { //has gbcategory
					if (isset($gbmap[$parts[3]])) {
						$parts[3] = $gbmap[$parts[3]];
					} else {
						$parts[3] = 0;
					}
					$data['items'][$toimport]['data']['text'] = 'exttool:'.implode('~~',$parts);
				}
			}
			//sanitize html fields
			foreach ($db_fields['html']['linkedtext'] as $field) {
				$data['items'][$toimport]['data'][$field] = myhtmlawed($data['items'][$toimport]['data'][$field]);
			}
			$exarr[] = $cid;
			foreach ($db_fields['linkedtext'] as $field) {
				$exarr[] = $data['items'][$toimport]['data'][$field];
			}
		}
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,count($db_fields['linkedtext'])+1);
		$stm = $DBH->prepare("INSERT INTO imas_linkedtext (courseid,".implode(',',$db_fields['linkedtext']).") VALUES $ph");
		$stm->execute($exarr);
		$firstinsid = $DBH->lastInsertId();
		foreach ($toimportbytype['LinkedText'] as $toimport) {
			$typemap['LinkedText'][$toimport] = $firstinsid+$k;
		}
	}
	
	//insert the Forum items
	if (isset($toimportbytype['Forum'])) {
		$typemap['Forum'] = array();
		$exarr = array();
		$db_fields['forum'] = array_values(array_intersect($db_fields['forum'], array_flip($data['items'][$toimportbytype['Forum'][0]]['data'])));
		foreach ($toimportbytype['Forum'] as $toimport) {
			if (isset($gbmap[$data['items'][$toimport]['data']['gbcategory']])) {
				$data['items'][$toimport]['data']['gbcategory'] = $gbmap[$data['items'][$toimport]['data']['gbcategory']];
			} else {
				$data['items'][$toimport]['data']['gbcategory'] = 0;
			}
			//sanitize html fields
			foreach ($db_fields['html']['forum'] as $field) {
				$data['items'][$toimport]['data'][$field] = myhtmlawed($data['items'][$toimport]['data'][$field]);
			}
			$exarr[] = $cid;
			foreach ($db_fields['forum'] as $field) {
				$exarr[] = $data['items'][$toimport]['data'][$field];
			}
		}
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,count($db_fields['forum'])+1);
		$stm = $DBH->prepare("INSERT INTO imas_forums (courseid,".implode(',',$db_fields['forum']).") VALUES $ph");
		$stm->execute($exarr);
		$firstfid = $DBH->lastInsertId();
		foreach ($toimportbytype['Forum'] as $k=>$toimport) {
			$typemap['Forum'][$toimport] = $firstfid+$k;
		}
	}
	
	//insert the wiki items
	if (isset($toimportbytype['Wiki'])) {
		$typemap['Wiki'] = array();
		$exarr = array();
		$db_fields['Wiki'] = array_values(array_intersect($db_fields['wiki'], array_flip($data['items'][$toimportbytype['Wiki'][0]]['data'])));
		foreach ($toimportbytype['Wiki'] as $toimport) {
			$exarr[] = $cid;
			//sanitize html fields
			foreach ($db_fields['html']['wiki'] as $field) {
				$data['items'][$toimport]['data'][$field] = myhtmlawed($data['items'][$toimport]['data'][$field]);
			}
			foreach ($db_fields['Wiki'] as $field) {
				$exarr[] = $data['items'][$toimport]['data'][$field];
			}
		}
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,count($db_fields['wiki'])+1);
		$stm = $DBH->prepare("INSERT INTO imas_wikis (courseid,".implode(',',$db_fields['wiki']).") VALUES $ph");
		$stm->execute($exarr);
		$firstinsid = $DBH->lastInsertId();
		foreach ($toimportbytype['Wiki'] as $toimport) {
			$typemap['Wiki'][$toimport] = $firstinsid+$k;
		}
	}

	
	//insert the Drill items
	if (isset($toimportbytype['Drill'])) {
		$typemap['Drill'] = array();
		$exarr = array();
		$db_fields['drill'] = array_values(array_intersect($db_fields['drill'], array_flip($data['items'][$toimportbytype['Drill'][0]]['data'])));
		foreach ($toimportbytype['Drill'] as $toimport) {
			//map itemids then implode
			$newitems = array();
			foreach ($data['items'][$toimport]['data']['itemids'] as $eqsid) {
				$newitems[] = $qsmap[$eqsid];
			}
			$data['items'][$toimport]['data']['itemids'] = implode(',', $newitems);
			//sanitize html fields
			foreach ($db_fields['html']['drill'] as $field) {
				$data['items'][$toimport]['data'][$field] = myhtmlawed($data['items'][$toimport]['data'][$field]);
			}
			$exarr[] = $cid;
			foreach ($db_fields['drill'] as $field) {
				$exarr[] = $data['items'][$toimport]['data'][$field];
			}
		}
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,count($db_fields['drill'])+1);
		$stm = $DBH->prepare("INSERT INTO imas_drillassess (courseid,".implode(',',$db_fields['drill']).") VALUES $ph");
		$stm->execute($exarr);
		$firstinsid = $DBH->lastInsertId();
		foreach ($toimportbytype['Drill'] as $toimport) {
			$typemap['Drill'][$toimport] = $firstinsid+$k;
		}

	}
	
	//insert the Assessment items
	if (isset($toimportbytype['Assessment'])) {
		$typemap['Assessment'] = array();
		$exarr = array();
		$db_fields['assessment'] = array_values(array_intersect($db_fields['assessment'], array_flip($data['items'][$toimportbytype['Assessment'][0]]['data'])));
		$contentlen = 0; 
		$tomap = array();
		foreach ($toimportbytype['Assessment'] as $toimport) {
			$tomap[] = $toimport;
			$thisitemdata = $data['items'][$toimport]['data'];
			//map gbcategory
			if (isset($gbmap[$thisitemdata['gbcategory']])) {
				$thisitemdata['gbcategory'] = $gbmap[$thisitemdata['gbcategory']];
			} else {
				$thisitemdata['gbcategory'] = 0;
			}
			//map posttoforum
			if ($thisitemdata['posttoforum']>0) {
				if (isset($typemap['Forum'][$thisitemdata['posttoforum']])) {
					$thisitemdata['posttoforum'] = $typemap['Forum'][$thisitemdata['posttoforum']];
				} else {
					$thisitemdata['posttoforum'] = 0;
				}
			}
			//sanitize html fields
			foreach ($db_fields['html']['assessment'] as $field) {
				$data['items'][$toimport]['data'][$field] = myhtmlawed($data['items'][$toimport]['data'][$field]);
			}
			//sanitize intro field, which may be json
			$introjson = json_decode($data['items'][$toimport]['data']['intro'], true);
			if ($introjson===false) {
				//regular intro
				$data['items'][$toimport]['data']['intro'] = myhtmlawed($data['items'][$toimport]['data']['intro']);
			} else {
				$introjson[0] = myhtmlawed($introjson[0]);
				for ($i=1;$i<count($introjson);$i++) {
					$introjson[$i]['text'] = myhtmlawed($introjson[$i]['text']);
				}
				$data['items'][$toimport]['data']['intro'] = json_encode($introjson);
			}
			//Sanitize endmsg
			if (is_array($data['items'][$toimport]['data']['endmsg'])) {
				$endmsgdata = $data['items'][$toimport]['data']['endmsg'];
				$endmsgdata['commonmsg'] = myhtmLawed($endmsgdata['commonmsg']);
				$endmsgdata['def'] = myhtmLawed($endmsgdata['def']);
				foreach (array_keys($endmsgdata['msgs']) as $k) {
					$endmsgdata['msgs'][$k] = myhtmLawed($endmsgdata['msgs'][$k]);
				}
				$data['items'][$toimport]['data']['endmsg'] = serialize($endmsgdata);
			} else {
				$data['items'][$toimport]['data']['endmsg'] = '';
			}
			//we'll resolve these later
			$thisitemdata['reqscoreaid'] = 0;
			$thisitemdata['itemorder'] = '';
			$contentlen += strlen($thisitemdata['intro']);
			$exarr[] = $cid;
			foreach ($db_fields['assessment'] as $field) {
				$exarr[] = $thisitemdata[$field];
			}
			if ($contentlen>5E5) { //do a batch add if more than 500,000 chars in intro
				$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,count($db_fields['assessment'])+1);
				$stm = $DBH->prepare("INSERT INTO imas_assessments (courseid,".implode(',',$db_fields['assessment']).") VALUES $ph");
				$stm->execute($exarr);
				$firstaid = $DBH->lastInsertId();
				foreach ($tomap as $k=>$tomapid) {
					$typemap['Assessment'][$tomapid] = $firstaid+$k;
				}
				$tomap = array();
				$exarr = array();
			}
		}
		if (count($exarr)>0) { //do final batch
			$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,count($db_fields['assessment'])+1);
			$stm = $DBH->prepare("INSERT INTO imas_assessments (courseid,".implode(',',$db_fields['assessment']).") VALUES $ph");
			$stm->execute($exarr);
			$firstaid = $DBH->lastInsertId();
			foreach ($tomap as $k=>$tomapid) {
				$typemap['Assessment'][$tomapid] = $firstaid+$k;
			}
		}
		
		//now, insert questions
		$db_fields['questions'] = explode(',', $db_fields['questions']);
		//only keep values in db_fields that are also keys of first question
		if (count($data['questions'])>0) {
			$db_fields['questions'] = array_values(array_intersect($db_fields['questions'], array_flip($data['questions'][0])));
		}
		$qmap = array();
		foreach ($toimportbytype['Assessment'] as $toimport) {
			$tomap = array();
			$qids = getAssessQids($data['items'][$toimport]['data']['itemorder']);
			$exarr = array();
			foreach ($qids as $qid) {
				$tomap[] = $qid;
				//remap questionsetid
				$data['questions'][$qid]['questionsetid'] = $qsmap[$data['questions'][$qid]['questionsetid']];
				//add in assessmentid
				$exarr[] = $typemap['Assessment'][$toimport];
				foreach ($db_fields['questions'] as $field) {
					$exarr[] = $data['questions'][$qid][$field];
				}
			}
			if (count($exarr)>0) {
				$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,count($db_fields['questions'])+1);
				$stm = $DBH->prepare("INSERT INTO imas_questions (assessmentid,".implode(',',$db_fields['questions']).") VALUES $ph");
				$stm->execute($exarr);
				$firstqid = $DBH->lastInsertId();
				foreach ($tomap as $k=>$tomapid) {
					$qmap[$tomapid] = $firstqid+$k;
				}
			}
		}
		
		//resolve itemorder and reqscoreaid
		$a_upd_stm = $DBH->prepare("UPDATE imas_assessments SET reqscoreaid=?,itemorder=? WHERE id=?");
		foreach ($toimportbytype['Assessment'] as $toimport) {
			//remap reqscoreaid
			if ($data['items'][$toimport]['data']['reqscoreaid']>0) {
				$rsaid = $typemap['Assessment'][$data['items'][$toimport]['data']['reqscoreaid']];
			} else {
				$rsaid = 0;
			}
			//remap itemorder and collapse
			$aitems = $data['items'][$toimport]['data']['itemorder'];
			foreach ($aitems as $i=>$q) {
				if (is_array($q)) {
					foreach ($q as $k=>$subq) {
						if ($k==0 && strpos($subq,'|')!==false) {continue;}
						$q[$k] = $qmap[$q[$k]];
					}
					$aitems[$i] = implode('~',$q);
				} else {
					$aitems[$i] = $qmap[$q];
				}
			}
			$aitemorder = implode(',', $aitems);
			$a_upd_stm->execute(array($rsaid, $aitemorder, $typemap['Assessment'][$toimport]));
		}
	}
	
	//add imas_items
	$exarr = array();
	foreach ($itemstoimport as $item) {
		$type = $data['items'][$item]['type'];
		$exarr[] = $cid;
		$exarr[] = $type;
		if ($type=='Calendar') {
			$exarr[] = 0;
		} else {
			$exarr[] = $typemap[$type][$item];
		}
	}
	$itemmap = array();
	if (count($exarr)>0) {
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr,3);
		$stm = $DBH->prepare("INSERT INTO imas_items (courseid,itemtype,typeid) VALUES $ph");
		$stm->execute($exarr);
		$firstinsid = $DBH->lastInsertId();
		foreach ($itemstoimport as $k=>$tomapid) {
			$itemmap[$tomapid] = $firstinsid+$k;
		}
	}
	//add checked items from $data itemorder into courseitems and update blockcnt
	copysub($data['course']['itemorder'], '0', $checked, $courseitems, $blockcnt, $itemmap);
	//record new itemorder
	$stm = $DBH->prepare("UPDATE imas_courses SET itemorder=?,blockcnt=? WHERE id=?");
	$stm->execute(array(serialize($courseitems), $blockcnt, $cid));
	
	//import sticky posts, if present
	if (!empty($options['importstickyposts']) && isset($data['stickyposts'])) {
		$db_fields['forum_posts'] = explode(',', $db_fields['forum_posts']);
		//only keep values in db_fields that are also keys of first question
		$db_fields['forum_posts'] = array_values(array_intersect($db_fields['forum_posts'], array_flip($data['stickyposts'][0])));
		$exarr = array();
		foreach ($data['stickyposts'] as $toimport) {
			//remap forumid
			$toimport['forumid'] = $typemap['Forum'][$toimport['forumid']];
			//sanitize html fields
			foreach ($db_fields['html']['forum_posts'] as $field) {
				$toimport[$field] = myhtmlawed($toimport[$field]);
			}
			//rehost files
			if (is_array($toimport['files'])) {
				$newfiles = array();
				for ($i=0;$i<count($toimport['files'])/2;$i++) {
					$newfn = rehostfile($toimport['files'][2*$i+1], 'ffiles/'.$typemap['Forum'][$toimport['forumid']]);
					if ($newfn!==false) {
						$newfiles[2*$i] = $toimport['files'][2*$i];
						$newfiles[2*$i+1] = $newfn; 
					}
				}
				$toimport['files'] = implode('@@', $newfiles);
			} else {
				$toimport['files'] = '';
			}
			//add in owner and postdate, then rest of fields
			$exarr[] = $importowner;
			$exarr[] = $now;
			foreach ($db_fields['forum_posts'] as $field) {
				$exarr[] = $toimport[$field];
			}
		}
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr, count($db_fields['forum_posts'])+2);
		$stm = $DBH->prepare("INSERT INTO imas_forum_posts (userid,postdate,".implode(',',$db_fields['forum_posts']).") VALUES $ph");
		$stm->execute($exarr);
		$firstinsid = $DBH->lastInsertId();
		
		//now insert corresponding imas_forum_threads entries
		$exarr = array();
		foreach ($data['stickyposts'] as $k=>$toimport) {
			array_push($exarr, $firstinsid+$k, $typemap['Forum'][$toimport['forumid']], $now, $importowner);
		}
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr, 4);
		$stm = $DBH->prepare("INSERT INTO imas_forum_threads (id,forumid,lastposttime,lastpostuser) VALUES $ph");
		$stm->execute($exarr);
	}
	
	//import offline, if present
	if (!empty($options['importoffline']) && isset($data['offline'])) {
		$db_fields['offline'] = explode(',', $db_fields['offline']);
		//only keep values in db_fields that are also keys of first question
		$db_fields['offline'] = array_values(array_intersect($db_fields['offline'], array_flip($data['offline'][0])));
		$exarr = array();
		foreach ($data['offline'] as $toimport) {
			//remap gbcat
			if (isset($gbmap[$toimport['gbcategory']])) {
				$toimport['gbcategory'] = $gbmap[$toimport['gbcategory']];
			} else {
				$toimport['gbcategory'] = 0;
			}
			$exarr[] = $cid;
			foreach ($db_fields['offline'] as $field) {
				$exarr[] = $toimport[$field];
			}
		}
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr, count($db_fields['offline'])+1);
		$stm = $DBH->prepare("INSERT INTO imas_gbitems (courseid,".implode(',',$db_fields['offline']).") VALUES $ph");
		$stm->execute($exarr);
	}
	
	//import calendar items, if present
	if (!empty($options['importcalitems']) && isset($data['calitems'])) {
		$db_fields['calitems'] = explode(',', $db_fields['calitems']);
		//only keep values in db_fields that are also keys of first question
		$db_fields['calitems'] = array_values(array_intersect($db_fields['calitems'], array_flip($data['calitems'][0])));
		$exarr = array();
		foreach ($data['calitems'] as $toimport) {
			$exarr[] = $cid;
			foreach ($db_fields['calitems'] as $field) {
				$exarr[] = $toimport[$field];
			}
		}
		$ph = Sanitize::generateQueryPlaceholdersGrouped($exarr, count($db_fields['calitems'])+1);
		$stm = $DBH->prepare("INSERT INTO imas_calitems (courseid,".implode(',',$db_fields['calitems']).") VALUES $ph");
		$stm->execute($exarr);
	}
	
	return array(
		'Questions Added'=>count($qstoadd),
		'Questions Updated'=>$qmodcnt,
		'InlineText Imported'=>count($typemap['InlineText']),
		'Linked Imported'=>count($typemap['LinkedText']),
		'Forums Imported'=>count($typemap['Forum']),
		'Assessments Imported'=>count($typemap['Assessment']),
		'Drills Imported'=>count($typemap['Drill']),
		'Wikis Imported'=>count($typemap['Wiki'])
		);
		
		
}

