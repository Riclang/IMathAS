<?php

//the loadItemShowData function loads item data based on a course itemarray 

function getitemstolookup($items,$inpublic,$viewall,&$tolookup,$onlyopen,$ispublic) {
	 global $studentinfo,$openblocks,$firstload;
	 $now = time();
	 foreach ($items as $item) {
		 if (is_array($item)) { //only add content from open blocks
			 $turnonpublic = false;
			 if ($ispublic && !$inpublic) {
			 	 if (isset($item['public']) && $item['public']==1) {
			 	 	 $turnonpublic = true;
			 	 } else {
			 	 	 continue;
			 	 }
			 }
			 if (!$viewall && isset($item['grouplimit']) && count($item['grouplimit'])>0) {
				 if (!in_array('s-'.$studentinfo['section'],$item['grouplimit'])) {
					 continue;
				 }
			 }
			 if (($item['avail']==2 || ($item['avail']==1 && $item['startdate']<$now && $item['enddate']>$now)) ||
				($viewall || ($item['SH'][0]=='S' && $item['avail']>0))) {
					if ($onlyopen) {
						if (in_array($item['id'],$openblocks)) { $isopen=true;} else {$isopen=false;}
						if ($firstload && (strlen($item['SH'])==1 || $item['SH'][1]=='O')) {$isopen=true;}
					}
					if ((!$onlyopen || $isopen) && $item['SH'][1]!='T' && $item['SH'][1]!='F') {
						getitemstolookup($item['items'],$inpublic||$turnonpublic,$viewall,$tolookup,$onlyopen,$ispublic);
					}
			 }
		} else {
			$tolookup[] = $item;
		}
	}
}
	
function loadItemShowData($items,$onlyopen,$viewall,$inpublic=false,$ispublic=false) {
	global $DBH;
	$itemshowdata = array();
	
	$itemstolookup = array();
	getitemstolookup($items,$inpublic,$viewall,$itemstolookup,$onlyopen,$ispublic);
	$typelookups = array();
	if (count($itemstolookup)>0) {
		$itemlist = implode(',', array_map('intval', $itemstolookup));
		$stm = $DBH->query("SELECT itemtype,typeid,id FROM imas_items WHERE id IN ($itemlist)");
		
		while ($line = $stm->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($typelookups[$line['itemtype']])) {$typelookups[$line['itemtype']] = array();}
			if ($line['itemtype']=='Calendar') {
				$itemshowdata[$line['id']] = $line;
			} else {
				$typelookups[$line['itemtype']][$line['typeid']] = $line['id'];  //store so we can map typeid back to item id below
			}
		}
	}
	if (isset($typelookups['Assessment']) && !$ispublic) {
		$typelist = implode(',', array_keys($typelookups['Assessment']));
		$stm = $DBH->query("SELECT id,name,summary,startdate,enddate,reviewdate,deffeedback,reqscore,reqscoreaid,avail,allowlate,timelimit FROM imas_assessments WHERE id IN ($typelist)");
		while ($line = $stm->fetch(PDO::FETCH_ASSOC)) {
			$line['itemtype'] = 'Assessment';
			$itemshowdata[$typelookups['Assessment'][$line['id']]] = $line;
		}
	}
	if (isset($typelookups['InlineText'])) {
		$typelist = implode(',', array_keys($typelookups['InlineText']));
		$stm = $DBH->query("SELECT id,title,text,startdate,enddate,fileorder,avail,isplaylist FROM imas_inlinetext WHERE id IN ($typelist)");
		while ($line = $stm->fetch(PDO::FETCH_ASSOC)) {
			$line['itemtype'] = 'InlineText';
			$itemshowdata[$typelookups['InlineText'][$line['id']]] = $line;
		}
	}
	if (isset($typelookups['Drill']) && !$ispublic) {
		$typelist = implode(',', array_keys($typelookups['Drill']));
		$stm = $DBH->query("SELECT id,name,summary,startdate,enddate,avail FROM imas_drillassess WHERE id IN ($typelist)");
		while ($line = $stm->fetch(PDO::FETCH_ASSOC)) {
			$line['itemtype'] = 'Drill';
			$itemshowdata[$typelookups['Drill'][$line['id']]] = $line;
		}
	}
	if (isset($typelookups['LinkedText'])) {
		$typelist = implode(',', array_keys($typelookups['LinkedText']));
		$stm = $DBH->query("SELECT id,title,summary,text,startdate,enddate,avail,target FROM imas_linkedtext WHERE id IN ($typelist)");
		while ($line = $stm->fetch(PDO::FETCH_ASSOC)) {
			$line['itemtype'] = 'LinkedText';
			$itemshowdata[$typelookups['LinkedText'][$line['id']]] = $line;
		}
	}
	if (isset($typelookups['Forum']) && !$ispublic) {
		$typelist = implode(',', array_keys($typelookups['Forum']));
		$stm = $DBH->query("SELECT id,name,description,startdate,enddate,groupsetid,avail,postby,replyby,allowlate FROM imas_forums WHERE id IN ($typelist)");
		while ($line = $stm->fetch(PDO::FETCH_ASSOC)) {
			$line['itemtype'] = 'Forum';
			$itemshowdata[$typelookups['Forum'][$line['id']]] = $line;
		}
	}
	if (isset($typelookups['Wiki'])) {
		$typelist = implode(',', array_keys($typelookups['Wiki']));
		$stm = $DBH->query("SELECT id,name,description,startdate,enddate,editbydate,avail,settings,groupsetid FROM imas_wikis WHERE id IN ($typelist)");
		while ($line = $stm->fetch(PDO::FETCH_ASSOC)) {
			$line['itemtype'] = 'Wiki';
			$itemshowdata[$typelookups['Wiki'][$line['id']]] = $line;
		}
	}
	
	return $itemshowdata;
} 

?>
