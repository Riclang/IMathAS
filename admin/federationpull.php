<?php
//IMathAS:  Federated libraries update pull
//(c) 2017 David Lippman

require("../init.php");
require("../includes/filehandler.php");

if ($myrights<100) {
	echo "Not authorized";
	exit;
}

$peer = intval($_GET['peer']);
$mypeername = isset($CFG['federatedname'])?$CFG['federatedname']:$installname;

function print_header() {
	global $peer;
	require("../header.php");
	echo '<h1>Pulling from Federation Peer</h1>';
	echo '<form method="post" action="federationpull.php?peer='.Sanitize::onlyInt($peer).'">';
}



//look up the peer to call
$stm = $DBH->prepare('SELECT peername,peerdescription,secret,url FROM imas_federation_peers WHERE id=:id');
$stm->execute(array(':id'=>$peer));
if (!$stm) {
	echo 'Invalid peer ID';
	exit;
}
$peerinfo = $stm->fetch(PDO::FETCH_ASSOC);

//set up our stream context for later data pulls
$streamopts = array(
	'http'=>array(
		'method'=>'GET',
		'header'->'Authorization: '.$peerinfo['secret']."\r\n"
	)
);

//see if we have a pull to continue
$stm = $DBH->prepare('SELECT id,pulltime,step,fileurl,record FROM imas_federation_pulls WHERE step<10 AND peerid=:id ORDER BY pulltime DESC LIMIT 1');
$stm->execute(array(':id'=>$peer));
if (!$stm) {
	$continuing = false;
} else {
	$continuing = true;
	$pullstatus = $stm->fetch(PDO::FETCH_ASSOC);
	$record = json_decode($pullstatus['record'], true);
	$since = $record['since'];
}

$now = time();

if (!$continuing) {  //start a fresh pull
	//look up our last successful pull to them
	$stm = $DBH->prepare('SELECT pulltime FROM imas_federation_pulls WHERE peerid=:id ORDER BY pulltime DESC LIMIT 1');
	$stm->execute(array(':id'=>$peer));
	if (!$stm) {
		$since = 0;
	} else {
		$since = $stm->fetchColumn(0);
	}

	$record = array('since'=>$since);

	//pull from remote
	$data = file_get_contents($peerinfo['url'].'/admin/federationapi.php?peer='.$mypeername'&since='.$since.'&stage=0', false, $streamopts);

	//store for our use
	storecontenttofile($data, 'fedpulls/'.$peer.'_'.$now.'_0.json', 'public');

	$parsed = json_decode($data, true);
	if ($parsed===NULL) {
		echo 'Invalid data received';
		exit;
	} else if ($parsed['stage']!=0) {
		echo 'Wrong data stage sent';
		exit;
	}
	//note that we've pulled it
	$query = 'INSERT INTO imas_federation_pulls (peerid,pulltime,step,fileurl,record) VALUES ';
	$query .= "(:peerid, :pulltime, 0, :fileurl, :record)";
	$stm = $DBH->prepare($query);
	$stm->execute(array(':peerid'=>$peer, ':pulltime'=>$now, ':fileurl'=>'fedpulls/'.$peer.'_'.$now.'_0.json', ':record'=>json_encode($record)));
	$done = false;
	$autocontinue = true;
} else if ($pullstatus['step']==0 && !isset($_POST['record'])) {
	//have pulled library info
	//do interactive confirm.

	print_header();
	echo '<h2>Updating Libraries</h2>';

	$data = json_decode(file_get_contents(getfopenloc($pullstatus['fileurl'])), true);

	$libs = array();
	$libnames = array(0=>'Root');
	foreach ($data['data'] as $i=>$lib) {
		if (ctype_digit($lib['uid'])) {
			$libs[] = $lib['uid'];
			$libnames[$lib['uid']] = $lib['n'];
		} else {
			//remove any invalid uniqueids
			unset($data['data'][$i];
		}
	}
	if (count($libs)==0) {
		echo '<p>No libraries to update</p>';
	} else {
		$liblist = implode(',', $libs);  //sanitized above

		//pull local info on these libraries
		$query = 'SELECT A.id,A.uniqueid,A.federationlevel,A.name,A.deleted,A.lastmoddate,A.parent,B.uniqueid as parentuid,B.name AS parentname ';
		$query .= 'FROM imas_libraries AS A LEFT JOIN imas_libraries AS B ON A.parent=B.id ';
		$query .= "WHERE A.uniqueid IN ($liblist)";
		$stm = $DBH->query($query);
		$libdata = array();
		while ($row = $stm->fetch(PDO::ASSOC)) {
			if ($row['parent']==0) { $row['parentuid'] = 0;}
			$libdata[$row['uniqueid']] = $row;
			$libnames[$row['parentuid']] = $row['parentname'];
		}
		$toadd = array();
		$tochg = array();
		$neednames = array();
		//for each sent library, figure out what's changed.
		foreach ($data['data'] as $lib) {
			if (!isset($libdata[$lib['uid']])) {
				if ($lib['d']==0) {
					$parent = isset($libnames[$lib['uid']])?$lib['uid']:0;
					$toadd[] = array($lib['uid'], $lib['name'], $lib['fl'], $parent);
				}
			} else {
				$curlib = $libdata[$lib['uid']];
				$chgs = array();
				if ($lib['fl']!=$curlib['federationlevel']) {
					$chgs['fedlevel'] = array($lib['fl'],$curlib['federationlevel']);
				}
				if ($lib['n']!=$curlib['name']) {
					$chgs['name'] = $lib['n'];
				}
				if ($lib['p']!=$curlib['parentuid']) {
					if (!isset($libnames[$lib['p']])) {
						$neednames[] = $lib['p'];
					}
					$chgs['parent'] = array($lib['p'], $curlib['parentuid']);
				}
				if ($lib['d']!=$curlib['deleted']) {
					$chgs['del'] = array($lib['n'],$curlib['deleted']);
				}
				if ($curlib['lastmoddate']>$since) {
					$chgs['localmod'] = $curlib['lastmoddate'];
				}
				if (count($chgs)>0) {
					$tochg[] = array($lib['uid'], $curlib['name'], $chgs);
				}
			}
		}
		//TODO:  Look up names from $neednames list
		foreach ($neednames as $k=>$v) {
			if (!ctype_digit($v)) {
				unset($neednames[$k]);
			}
		}
		$neednamelist = implode(',', $neednames);
		$stm = $DBH->query("SELECT uniqueid,name FROM imas_libraries WHERE uniqueid IN ($neednamelist)");
		while ($row = $stm->fetch(PDO::ASSOC)) {
			$libnames[$row['uniqueid']] = $row['name'];
		}

		echo '<h3>Libraries to Add</h3>';
		if (count($toadd)==0) {
			echo '<p>No libraries to add</p>';
		} else {
			echo '<table class="gb">';
			echo '<thead><tr>';
			echo '<th>Add?</th>';
			echo '<th>Name</th>';
			echo '<th>Level</th>';
			echo '<th>Parent</th>';
			echo '</tr></thead><tbody>';

			foreach ($toadd as $a) {
				echo '<tr><td><input type="checkbox" name="toadd'.$a[0].'" value="1" checked/></td>';
				echo '<td>'.$a[1].'</td>';
				echo '<td><select name="fedlevel'.$a[0].'">';
				echo ' <option value=1 '.($a[2]==1?'selected':'').'>Federated</option>';
				echo ' <option value=2 '.($a[2]==2?'selected':'').'>Top Level Federated</option>';
				echo '</select></td>';
				echo '<td>'.$a[3].'</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h3>Libraries to Change</h3>';
		if (count($tochg)==0) {
			echo '<p>No libraries to change</p>';
		} else {
			echo '<table class="gb">';
			echo '<thead><tr>';
			echo '<th>Current Name</th>';
			echo '<th>Changes</th>';
			echo '</tr></thead><tbody>';

			foreach ($tochg as $a) {
				echo '<tr><td>'.$a[1].'</td>';
				echo '<td>';
				foreach ($a[2] as $type=>$chginfo) {
					if ($type=='localmod') {
						echo '<p>Note: Library modified locally since last pull</p>';
					} else if ($type=='federationlevel') {
						echo '<p>Fed Level<br/>Current: ';
						if ($chginfo[1]==2) { echo 'Top Level Federated';}
						else if ($chginfo[1]==1) { echo 'Federated';}
						else { echo 'Not Federated';}
						echo '<br/>New: ';
						echo '<td><select name="fedlevel'.$a[0].'">';
						echo ' <option value=0>Not Federated</option>';
						echo ' <option value=1 '.($chginfo[0]==1?'selected':'').'>Federated</option>';
						echo ' <option value=2 '.($chginfo[0]==2?'selected':'').'>Top Level Federated</option>';
						echo '</select></p>';
					} else if ($type=='name') {
						echo '<p>Name<br/>Current: '.$a[1];
						echo '<br/><checkbox name="chgname'.$a[0].'" value=1 checked/> New: '.$chginfo[0];
						echo '</p>';
					} else if ($type=='parent') {
						echo '<p>Parent<br/>Current: '.$libnames[$chginfo[1]];
						if (isset($libnames[$chginfo[0]])) {
							echo '<br/><checkbox name="chgparent'.$a[0].'" value=1 checked/> New: '.$libnames[$chginfo[0]];
						} else {// if new parent isn't in system or in pull
							echo '<br/><checkbox name="chgparent'.$a[0].' disabled"/> New: Unknown';
						}
						echo '</p>';
					} else if ($type=='deleted') {
						echo '<p>Deleted<br/>Current: '.($chginfo[1]==1?'Yes':'No');
						echo '<br/><checkbox name="chgdel'.$a[0].'" value=1 checked/> New: '.($chginfo[0]==1?'Yes':'No');
						echo '</p>';
					}
				}
				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}
	}
	echo '<input type="submit" name="record" value="Record"/>';

	$done = false;
	$autocontinue = false;

} else if ($pullstatus['step']==0 && isset($_POST['record'])) {
	//have postback from library confirmation

	$record['step0'] = $_POST;

	$data = json_decode(file_get_contents(getfopenloc($pullstatus['url'])), true);

	$libs = array();
	$parentref = array();
	foreach ($data['data'] as $i=>$lib) {
		if (ctype_digit($lib['uid'])) {
			$libs[] = $lib['uid'];
			//build a backref for parents to children
			if (isset($_POST['toadd'.$lib['uid']])) {
				if (!isset($parentref[$lib['p']])) {
					$parentref[$lib['p']] = array($lib['uid']);
				} else {
					$parentref[$lib['p']][] = $lib['uid'];
				}
			}
		} else {
			//remove any invalid uniqueids
			unset($data['data'][$i];
		}
	}

	if (count($libs)==0) {
		echo '<p>No libraries to update</p>';
	} else {
		$liblist = implode(',', $libs);  //sanitized above

		//pull local info on these libraries
		$query = 'SELECT A.id,A.uniqueid,A.parent,B.uniqueid as parentuid ';
		$query .= 'FROM imas_libraries AS A LEFT JOIN imas_libraries AS B ON A.parent=B.id ';
		$query .= "WHERE A.uniqueid IN ($liblist)";
		$stm = $DBH->query($query);
		$localids = array();
		while ($row = $stm->fetch(PDO::ASSOC)) {
			if ($row['parent']==0) { $row['parentuid'] = 0;}
			//backref for parents to children
			if (!isset($parentref[$row['parentuid']])) {
				$parentref[$row['parentuid']] = array($row['uid']);
			} else {
				$parentref[$lib['parentuid']][] = $row['uid'];
			}
			$localid[$row['uniqueid']] = $row['id'];
			$localid[$row['parentuid']] = $row['parent'];
		}
		$childremcnt = 0;

		function unsetchildren($parentlib) {
			global $parentref,$childremcnt;
			foreach ($parentref[$parentlib] as $childlib) {
				if (isset($_POST['toadd'.$childlib])) {
					unset($_POST['toadd'.$childlib]);
					$childremcnt++;
				}
				if (isset($parentref[$childlib])) {
					unsetchildren($childlib);
				}
			}
		}

		//don't add any libraries if we're not adding the parent lib
		foreach ($data['data'] as $lib) {
			if (!isset($localid[$lib['uid']])) {
				//new library
				if (!isset($_POST['toadd'.$lib['uid']])) {
					//we're not ading this library, so unset any children adds
					unsetchildren($lib['uid']);
				}
			}
		}

		//don't change any parents if we're not adding the new parent
		foreach ($data['data'] as $lib) {
			if (isset($localid[$lib['uid']])) {
				//changed library
				if (isset($_POST['chgparent'.$lib['uid']])) {
					//we're changing the parent - let's make sure
					//the new parent is either local or we're
					//actually adding it
					//if not, don't change the parent
					if (!isset($localid[$lib['p']]) && !isset($_POST['toadd'.$lib['uid']])) {
						unset($_POST['chgparent'.$lib['uid']]);
					}
				}
			}
		}

		//now we can actually do the adds and changes
		$parentstoupdate = array();
		foreach ($data['data'] as $lib) {
			if (isset($_POST['toadd'.$lib['uid']])) {
				//add the library
				$thisparent = 0;
				if ($lib['p']>0) {
					if (isset($localid[$lib['p']])) {
						$thisparent = $localid[$lib['p']];
					} else {
						$parentstoupdate[$lib['uid']] = $lib['p'];
					}
				}
				$query = 'INSERT INTO imas_libraries (uniqueid, adddate, lastmoddate, name, ownerid, federationlevel, parent, groupid) ';
				$query .= 'VALUES (:uniqueid, :adddate, :lastmoddate, :name, :ownerid, :federationlevel, :parent, :groupid)';
				$stm = $DBH->prepare($query);
				$stm->execute(array(':uniqueid'=>$lib['uid'], ':adddate'=>$now, ':lastmoddate'=>$lib['lm'],
					':name'=>$lib['n'], ':ownerid'=>$userid, ':federationlevel'=>$_POST['fedlevel'.$lib['uid']],
					':parent'=>$thisparent, ':groupid'=>$groupid));
				//record new ID
				$localid[$lib['uid']] = $DBH->lastInsertId();
			}
		}
		//update parents if needed
		$stm = $DBH->prepare("UPDATE imas_libraries SET parent=:parent WHERE id=:id");
		foreach ($parentstoupdate as $libuid=>$libparentuid) {
			$stm->execute(array(':parent'=>$localid[$libparentuid]), ':id'=>$localid[$libuid]);
		}

		//update step number and redirect to start step 1
		$stm = $DBH->prepare("UPDATE imas_federation_pulls SET step=1 WHERE id=:id");
		$stm->execute(array(':id'=>$pullstatus['id']));

		$done = false;
		$autocontinue = true;

} else if ($pullstatus['step']==1 && !isset($_POST['record'])) {
	//pull step 1 from remote

	if (isset($record['stage1offset'])) {
		$offset = $record['stage1offset'];
	} else {
		$offset = 0;
	}
	$data = file_get_contents($peerinfo['url'].'/admin/federationapi.php?peer='.$mypeername'&since='.$since.'&stage=1&offset='.$offset, false, $streamopts);

	//store for our use
	storecontenttofile($data, 'fedpulls/'.$peer.'_'.$now.'_1.json', 'public');

	$parsed = json_decode($data, true);
	if ($parsed===NULL) {
		echo 'Invalid data received';
		exit;
	} else if ($parsed['stage']!=0) {
		echo 'Wrong data stage sent';
		exit;
	}
	//update pull record
	$query = 'UPDATE imas_federation_pulls SET fileurl=:fileurl,record=:record,step=2 WHERE id=:id';
	$stm = $DBH->prepare($query);
	$stm->execute(array(':fileurl'=>'fedpulls/'.$peer.'_'.$now.'_1.json',
											':record'=>json_encode($record), ':id'=>$pullstatus['id']));

	$autocontinue = true;
	$done = false;
} else if ($pullstatus['step']==2 && !isset($_POST['record'])) {
	//have pulled a batch of questions.
	//do interactive confirmation

	$data = json_decode(file_get_contents(getfopenloc($pullstatus['fileurl'])), true);
	print_header();

	echo '<h2>Updating Questions Batch</h2>';

	$quids = array();
	$qdescrip = array();
	$quidref = array();
	foreach ($data['data'] AS $i=>$q) {
		if (ctype_digit($q['uniqueid'])) {
			$quids[] = $q['uniqueid'];
			$qdescript[$q['uniqueid']] = $q['ds'];
			$quidref[$q['uniqueid']] = $i;
		} else {
			//remove any invalid uniqueids
			unset($data['data'][$i];
		}
	}
	if (count($quids)==0) {
		echo '<p>No questions to update</p>';
	} else {
		//pull library names and local ids
		$stm = $DBH->query("SELECT uniqueid,id,name FROM imas_libraries WHERE federationlevel>0");
		$libdata = array();
		while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
			$libdata[$row['uniqueid']] = array('id'=>$row['id'], 'name'=>$row['name']);
		}

		//pull existing library items for imported questions
		$placeholders = Sanitize::generateQueryPlaceholders($quids);
		$query = "SELECT il.uniqueid,ili.id,ili.qsetid,ili.deleted,ili.junkflag FROM imas_libraries AS il ";
		$query .= "JOIN imas_library_items AS ili ON il.id=ili.libid ";
		$query .= "JOIN imas_questionset AS iq ON ili.qsetid=iq.id ";
		$query .= "WHERE iq.uniqueid IN ($placeholders)";
		$stm = $DBH->prepare($query);
		$qlibs= array();
		while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($qlibs[$row['qsetid']])) {
				$qlibs[$row['qsetid']] = array();
			}
			$qlibs[$row['qsetid']][$row['uniqueid']] = array('deleted'=>$row['deleted'], 'junkflag'=>$row['junkflag'],'iliid'=>$row['id']);
		}

		//pull existing question info
		//we'll interactive ask about these as needed, then worry about
		//questions that weren't already on the system
		$stm = $DBH->prepare("SELECT * FROM imas_questionset WHERE uniqueid IN ($placeholders)");
		$stm->execute($quids);
		while ($local = $stm->fetch(PDO::FETCH_ASSOC)) {
			$remote = $data['data'][$quidref[$local['uniqueid']]];
			//if remote lastmod==adddate, and local lastmod is newer, skip the question
			// since it wasn't modified remotely
			if ($remote['adddate']==$remote['lastmoddate'] && $local['lastmoddate']>$remote['lastmoddate']) {
				continue; //just skip it
			}

			//check libraries
			$livesinalib = false;  $libhtml = '';
			$remotelibs = array();
			foreach ($remote['libs'] as $rlib) {
				$remotelibs[] = $rlib['ulibid'];
				if (isset($qlibs[$local['id']][$rlib['ulibid']])) {
					$llib = $qlibs[$local['id']][$rlib['ulibid']];
					//question is already in lib - look for changes.
					if ($llib['deleted']==1 && $rlib['deleted']==0) {
						$libhtml .= '<li>Library assignment: '.Sanitize::encodeStringForDisplay($libdata[$rlib['ulibid']]['name']).'. ';
						$libhtml .= 'Not deleted remotely, deleted locally. ';
						$libhtml .= '<input type="checkbox" name="undeleteli[]" value="'.$llib['iliid'].'" checked> Un-delete locally and update</li>';
					} else if ($llib['deleted']==0 && $rlib['deleted']==1) {
						$libhtml .= '<li>Library assignment: '.Sanitize::encodeStringForDisplay($libdata[$rlib['ulibid']]['name']).'. ';
						$libhtml .= 'Deleted remotely, not deleted locally. ';
						$libhtml .= '<input type="checkbox" name="deleteli[]" value="'.$llib['iliid'].'"> Delete locally </li>';
					} else if ($llib['junkflag']==1 && $rlib['junkflag']==0) {
						$libhtml .= '<li>Library assignment: '.Sanitize::encodeStringForDisplay($libdata[$rlib['ulibid']]['name']).'. ';
						$libhtml .= 'Marked OK remotely, Marked as wrong lib locally. ';
						$libhtml .= '<input type="checkbox" name="unjunkli[]" value="'.$llib['iliid'].'" checked> Un-mark as wrong lib</li>';
					} else if ($llib['junkflag']==0 && $rlib['junkflag']==1) {
						$libhtml .= '<li>Library assignment: '.Sanitize::encodeStringForDisplay($libdata[$rlib['ulibid']]['name']).'. ';
						$libhtml .= 'Marked as wrong lib remotely, marked OK locally. ';
						$libhtml .= '<input type="checkbox" name="junkli[]" value="'.$llib['iliid'].'" checked> Mark as wrong lib </li>';
					}
					$livesinalib = true;
				} else if (isset($libdata[$rlib['ulibid']]) && $rlib['deleted']==0 && $rlib['junkflag']==0) {
					//new library assignment to an existing lib, and it isn't deleted or junk
					$libhtml .= '<li>';
					$libhtml = 'New library assignment: '.Sanitize::encodeStringForDisplay($libdata[$rlib['ulibid']]['name']).'.';
					//value is localqsetid:locallibid
					$libhtml .= '<input type="checkbox" name="addli[]" value="'.$row['id'].':'.$libdata[$rlib['ulibid']]['id'].'" checked> Add it</li>';
					$livesinalib = true;
				}
			}
			if (!$livesinalib) {
				//we must not have created local copies of any of the libraries the question
				//is in. No point asking about questions where we didn't bring in the library
				continue;
			}

			echo '<h4><b>Question '.$local['id'].'</b>. ';
			if ($local['lastmoddate']<$since) {
				//it's been updated remotely but not locally
				echo '<span style="color: #ff6600;">Changed Remotely - no local conflict</span>';
			} else {
				//it's been updated both remotely and locally - potential conflict
				echo '<span style="color: #ff0000;">Changed Remotely and Locally - potential conflict</span>';
			}
			echo '</h4>';
			if ($remote['deleted']==1 && $local['deleted']==0) {
				echo '<p>Deleted remotely, not deleted locally.  ';
				echo '<input type="checkbox" name="deleteq[]" value="'.$local['id'].'"> Delete locally </p>';
			} else if ($remote['deleted']==0 && $local['deleted']==1) {
				echo '<p>Not deleted remotely, deleted locally.  ';
				echo '<input type="checkbox" name="undeleteq[]" value="'.$local['id'].'" checked> Un-delete locally and update</p>';
				echo '<p>Library assignments, if undeleted:<ul>'.$libhtml.'</ul></p>';
			} else {
				//show changes to most fields
				$fields = array('author','description', 'qtype', 'control',	'qcontrol', 'qtext', 'answer','extref', 'broken',
					'solution', 'solutionopts', 'license','ancestorauthors', 'otherattribution');
				foreach ($fields as $field) {
					if ($remote[$field]!=$local[$field]) {
						echo '<p>'.ucwords($field). ' changed. ';
						echo '<input type="checkbox" name="update'.$field.'[]" value="'.$local['id'].'" checked> Update it</p>';
						echo '<table class="gridded"><tr><td>Local</td><td>Remote</td></tr>';
						echo '<tr><td>'.str_replace("\n",'<br/>',Sanitize::encodeStringForDisplay($local['description'])).'</td>';
						echo '<td>'.str_replace("\n",'<br/>',Sanitize::encodeStringForDisplay($remote['description'])).'</td></tr></table>';
					}
				}
				//TODO:  Figure a way to handle replaceby
				//plan: Update qimages if control is updated
				echo '<p>Library assignments:<ul>'.$libhtml.'</ul></p>';
			}
		}
	}
	echo '<input type="submit" name="record" value="Record"/>';

	$done = false;
	$autocontinue = false;
}

if ($autocontinue) {
	header('Location: ' . $GLOBALS['basesiteurl'] . "/admin/federationpull.php?peer=".Sanitize::encodeUrlParam($peer));
	exit;
} else {
	echo '</form>';
	require("../footer.php");
}
?>
