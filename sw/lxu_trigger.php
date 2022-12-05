<?php
/*************************************************************
 * trigger for LTrax V1.20-SQL
 *
 * 04.12.2022 - (C)JoEmbedded.com
 *
 * This is database version for a trigger that accepts 
 * all incomming data and insertes it into a SQL database.
 * Tested with mySQL and MariaDB
 *
 * Last used Err: 106
 ***************************************************************/

error_reporting(E_ALL);

ignore_user_abort(true);
set_time_limit(120); // 2 Min runtime

include("conf/api_key.inc.php");
include("conf/config.inc.php");	// DB Access Param
include("lxu_loglib.php");

// ----------------Functions----------------
function db_init()
{
	global $pdo;
	if (isset($pdo)) return;
	try { // Nothing will work without the DB
		$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASSWORD);
	} catch (PDOException $e) {
		exit_error("ERROR: '" . $e->getMessage() . "'");
	}
}

// Filename-sort-callback
function flcmp($a, $b)
{			// Compare Filenames, containing the dates
	if ($a[0] == '.') return 1;	// Points at to the end
	if ($b[0] == '.') return -1;
	$ea = explode('_', $a);
	$eb = explode('_', $b);
	$res = intval($ea[0]) - intval($eb[0]);
	if ($res) return $res;
	$res = intval(@$ea[1]) - intval(@$eb[1]);
	if ($res) return $res;
	$res = intval(@$ea[2]) - intval(@$eb[2]);
	return $res;
}

function send_alarm_mail($mail, $cont, $subj, $from)
{
	global $dbg;
	$host = $_SERVER['SERVER_NAME'];
	$mail_text = "Notification via '$host':\n";
	$mail_text .= "$subj\n$cont\n(This Email was sent automatically. If received unintentionally, please ignore it. Contact Service: " . SERVICEMAIL . ")";
	$header = "From: $from <" . AUTOMAIL . ">\r\n" .
		// 'Reply-To: webmaster@example.com' . "\r\n" .
		'X-Mailer: PHP/' . phpversion();

	if ($dbg) {
		echo "(DEBUG)<pre>MAIL: =$mail=\nSUBJ: =$subj=\nHEADER: =$header=\nTEXT:\n=$mail_text=\n</pre>";
		$res = true;	// Patch
	} else {
		$res = @mail($mail, $subj, $mail_text, $header);
	}
	return $res; // OK: true
}

// ----------------MAIN----------------
$dbg = 0;	// Debug-Level if >0, see docu

header('Content-Type: text/plain');

$api_key = @$_GET['k'];				// max. 41 Chars KEY
$mac = strtoupper(@$_GET['s']); 		// exactly 16 UC Chars. api_key and mac identify device
$reason = intval(@$_GET['r']);				// Opt. Reason (ALARMS) (as in device_info.dat also)
// reason&256: SEND Contact 512|1024:Timeout (reason&16: oder $fcnt>0: Data neu)
$now = time();						// one timestamp for complete run
$mttr_t0 = microtime(true);           // Benchmark trigger
$xlog = "(Trigger:$reason)";		// Assume only Trigger/Service

if (!isset($mac) || strlen($mac) != 16) {
	if (strlen($mac) > 24) exit();		// URL Attacked?
	exit_error("MAC Len");
}

if (@file_exists(S_DATA . "/$mac/cmd/dbg.cmd")) $dbg = 1; // Allow Individual Debug

// Check Key before loading data
//echo "API-KEY: '$api_key'\n"; // TEST
if (!$dbg && (!isset($api_key) || strcmp($api_key, S_API_KEY))) {
	exit_error("API Key");
}

// --- Now check files ---
$dpath = S_DATA . "/$mac/in_new";		// Device Path (must exist)

$flist = @scandir($dpath, SCANDIR_SORT_NONE);
if (!$flist) {
	exit_error("MAC Unknown");
}
usort($flist, "flcmp");	// Now Compared by Filenames
$fcnt = count($flist) - 2;   // Without . and ..
if ($fcnt > 0) $xlog = "(Import)"; // Now: real import
// foreach($flist as $fl) echo "$fl\n"; exit();
$cpath = S_DATA . "/$mac/cmd";		// Path (UPPERCASE recommended, must exist)

$res = 0;	// No Data

if ($dbg) echo "*$fcnt Files in '$dpath'*\n";

// --- Connect to DB ---
db_init();

$trigger_fb = "";

// --- Save incomming data in database devices ---
if ($pdo->query("SHOW TABLES LIKE 'm$mac'")->rowCount() === 0) { // No Table for this Device 
	$statement = $pdo->prepare("SELECT vals FROM devices WHERE mac='$mac'");
	$statement->execute(); // Fail->Exeption
	$qres = $statement->fetch();
	if ($qres == false) {
		$pdo->exec("INSERT INTO devices ( mac ) VALUES ( '$mac' )");
		$new_id = $pdo->lastInsertId();
		$xlog .= "(AddTable '$mac' (ID:$new_id))";
	} else {
		$xlog .= "(ERROR: No Table 'm$mac', but in 'devices')"; // Cleared
	}
	// Generate new table SQL direct
	$qres = $pdo->query("
			CREATE TABLE IF NOT EXISTS m$mac (
			`id` int unsigned AUTO_INCREMENT,
			`line_ts` timestamp DEFAULT CURRENT_TIMESTAMP,
			`calc_ts` timestamp NULL DEFAULT NULL,
			`dataline` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
			PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");
	if ($qres === false) exit_error("(ERROR 104:" . $pdo->errorInfo()[2] . ")"); // Can not Create Table
} else {	// Table exists, Check Entry in decices
	$statement = $pdo->prepare("SELECT vals FROM devices WHERE mac='$mac'");
	$statement->execute(); // Fail->Exeption
	$qres = $statement->fetch();
	if ($qres == false) {	// Table but no entry in devices?
		$qres = $pdo->exec("INSERT INTO devices ( mac ) VALUES ( '$mac' )");
		$new_id = $pdo->lastInsertId();
		$xlog .= "(ERROR: 'm$mac' exists, but not in 'devices'? (Re-)Added (ID:$new_id))";
	} else {
		$lvalstr = $qres['vals'];
	}
}

$units = ""; // Units for ALL entries
$lvala = array();	// Last Values as array;
if (isset($lvalstr)) { // Inject old vals
	$tmpa = explode(' ', $lvalstr);
	foreach ($tmpa as $tmp) {
		$ds = explode(':', $tmp); // As Key/Val
		$key = $ds[0];
		$val = @$ds[1];
		$lvala[$key] = $val;
	}
	ksort($lvala);
}

// Add files to mac-table
$line_cnt = 0;

$warn_new = 0;	// See Text for explanation
$err_new = 0;
$alarm_new = 0;
$info_wea = array();

$ign_cnt = 0;
$file_cnt = 0;
$sqlps = $pdo->prepare("INSERT INTO m$mac ( calc_ts, dataline  ) VALUES ( FROM_UNIXTIME( ? ), ? )");
// Regard only EDT-Files! 
foreach ($flist as $fname) {
	if (!strcmp($fname, '.') || !strcmp($fname, '..')) continue;
	if (!is_file("$dpath/$fname")) {
		$ign_cnt++;
		//$xlog.="(NOFILE '$fname' ignored)";
		continue;	// ONLY Files
	}
	if (!strpos($fname, '.edt')) { // Ignore other files than EDT
		$ign_cnt++;
		$xlog .= "('$fname' ignored)";

		// echo "ignore '$fname'";		
		if (!$dbg) @unlink("$dpath/$fname");
		continue;	// ONLY Files
	}

	// -- Insert EDT-FILE --
	$file_cnt++;
	$lines = file("$dpath/$fname", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$anz = count($lines);
	if ($anz < 1) {
		$warn_new++;	// Warning: EMPTY FILE (Warning not visible)
		$xlog .= "(WARNING: File '$fname' is empty)";
		$info_wea[] = "WARNING: File '$fname' is empty";
		if (!$dbg) @unlink("$dpath/$fname");	// Strange...
		continue;	// ONLY Files
	}

	$unixt = 0; // Start with unix-Time unknown
	foreach ($lines as $line) { // Find 1.st time 
		if ($line[0] != '!') continue;
		$ht = intval(substr($line, 1));
		if ($ht > 1526030617 && $ht < 0xF0000000) {
			$unixt = $ht;
			break;
		}
	}

	foreach ($lines as $line) {
		if ($line[0] == '!') {
			if ($line[1] == 'U') {		// Units follow
				if ($line[2] == ' ') $units = $line;		// Unit-line. Keep!
				else {
					$warn_new++;				// WARNING: Format-ERROR
					if (strlen($xlog) < 128) $xlog .= "(WARNING: '!U'-Format)";
					if (count($info_wea) < 20) $info_wea[] = "WARNING: '!U'-Format";
				}
				$lvala = array();	// New lvalas
			} else {					// Line contains VALUES
				$lina = array();	// Create an empty Output Array for Mapping Channels to Units

				$tmp = explode(' ', $line);
				if ($tmp[0][1] == '+') {
					$rtime = intval(substr($tmp[0], 2));
					$unixt += $rtime;	// If <100000 strange!
					$tmp[0] = "!$unixt";	// Replace +Time by real
				} else {
					$unixt = intval(substr($tmp[0], 1));
				}
				if ($unixt < 1526030617 || $unixt >= 0xF0000000) { // 2097
					$warn_new++;	// Warning: Strange Times
					if (strlen($xlog) < 128) $xlog .= "(WARNING: Unknown Time)";
					if (count($info_wea) < 20) $info_wea[] = "WARNING: Unknown Time";
				}
				// Check Values
				$anz = count($tmp);
				for ($i = 1; $i < $anz; $i++) {
					$ds = explode(':', $tmp[$i]); // As Key/Val

					$key = $ds[0];
					$val = @$ds[1];
					if (!isset($val)) {
						$err_new++;	// ERROR: No Value for Channel  
						if (count($info_wea) < 20) $info_wea[] = "ERROR(T:$unixt): Channel #$key: No Value";
					}
					if (isset($lina[$key])) {	// Can not set twice per line!
						$err_new++;	// ERROR: Channel '$key' already used ('$iv' ignored) in Line
						if (count($info_wea) < 20) $info_wea[] = "ERROR(T:$unixt): Channel #$key: Channel already used";
					} else {
						$lvala[$key] = $val;	// Save last channel
						$lina[$key] = $val;	// Allocate Channel for this line
						if ($val[0] == '*') {	// Line marked as ALARM
							$val = substr($val, 1);
							if (!is_numeric($val)) {
								$err_new++;	// ERROR: Not Numeric Value
								if (count($info_wea) < 20) $info_wea[] = "ERROR(T:$unixt): Channel #$key: '$val'";
							}
							$alarm_new++; 	// Count ALARMS
							if (count($info_wea) < 20) $info_wea[] = "ALARM(T:$unixt): Channel #$key";
						} else if (!is_numeric($val)) {
							$err_new++; // ERROR: Not Numeric Value
							if (count($info_wea) < 20) $info_wea[] = "ERROR(T:$unixt): Channel #$key: '$val'";
						}
					}
				}
				// Recombine exploded line to string with corect time
				$line = implode(' ', $tmp);
			}
		} else {
			if ($line[0] != '<' || $line[strlen($line) - 1] != '>') {	// No valid Meta Line
				$line = "<LINE ERROR '$line'>";
				$err_new++;
				if (count($info_wea) < 20) $info_wea[] = "ERROR: In line $line_cnt";
			} else {
				if (!strncmp($line, "<COOKIE ", 8)) {
					$cookie = intval(substr($line, 8));
					if ($cookie < 1000000000) {
						$info_wea[] = "ERROR: Cookie($cookie)";
						$err_new++;
					}
				} else if (strpos($line, "<RESET") !== false || strpos($line, "ERROR")) {
					$info_wea[] = "WARNING: '" . trim($line, "<>") . "'";
					$warn_new++;
				}
			}
		}
		if ($unixt == 0) $unixt = NULL;
		$line_time = $unixt;	// Might be 0:Works on MySQL, >0 MariaDB 
		if ($dbg) echo "$line_cnt: '$line'\n";
		$qres = $sqlps->execute(array($line_time, $line));
		if ($qres == false) {	// Write failed
			$err_new++;
			if (count($info_wea) < 20) $info_wea[] = "ERROR: Write1 DB failed";
		}
		$line_cnt++;
	}

	if ($dbg) echo "*File '$fname'\n"; // *** With DBG set: multiple imports in DB ***
	else @unlink("$dpath/$fname");	// Unlinked processed File
}

// Synthesize last values line
$laval = "";
ksort($lvala);
foreach ($lvala as $key => $val) {
	if (strlen($laval)) $laval .= " ";
	$laval .= "$key:$val";
}
$laval = strtr($laval, "'\"<>", "____");

// Remove '!U ' from units if found
if (strlen($units)) $units = strtr(substr($units, 3), "'\"<>", "____");	// Remove strange chars

// Get old Vals
// prepare String for Db Update
$insert_sql = "UPDATE devices SET last_change=NOW(), ";
if ($fcnt > 0) $insert_sql .= "last_seen=NOW(), ";

if (strlen($units)) $insert_sql .= "units='$units',";
if (strlen($laval)) $insert_sql .= "vals='$laval',";
if (isset($cookie)) {
	$insert_sql .= "cookie='$cookie',";
	$par = @file(S_DATA . "/$mac/files/iparam.lxp", FILE_IGNORE_NEW_LINES); // Current name?
	if ($par != false) {
		$dev_name = @$par[5];
		if (strlen($dev_name)) {
			$insert_sql .= "name='$dev_name',";
		}
	}
	$par = @file(S_DATA . "/$mac/files/sys_param.lxp", FILE_IGNORE_NEW_LINES); // PowerSetup
	if ($par != false && count($par) > 18) {
		$hl = @$par[15];	// V
		$insert_sql .= "vbat0 = $hl,";
		$hh = @$par[16];	// V
		$insert_sql .= "vbat100 = $hh,";
		$hc = @$par[18];	// mAh
		$insert_sql .= "cbat = $hc,";
	} else {
		if (!file_exists(S_DATA . "/$mac/cmd/sys_param.lxp.vmeta")) { // No Directory?
			file_put_contents(S_DATA . "/$mac/cmd/getdir.cmd", "123");	// 3 Tries to get Directoy
		} else { // Directory OK, but no sys_param
			file_put_contents(S_DATA . "/$mac/get/sys_param.lxp", "123");	// 3 Tries to get sys_param.lxp
		}
	}
}

// ---Check--- device_info.dat for alarms, ect..
$statement = $pdo->prepare("SELECT *, UNIX_TIMESTAMP(last_gps) as lpos FROM devices WHERE mac = ?");
$qres = $statement->execute(array($mac));
if ($qres == false) {
	if ($dbg) echo ("(ERROR 106:" . $pdo->errorInfo()[2] . ")"); // Can not Update Table?
	$xlog .= "(ERROR: Acces DB 'devices')";
	$err_new++;
	$info_wea[] = "ERROR: Write2 DB failed";
} else {
	$deva = $statement->fetch();

	// Also possible: Alarms in $reason!
	$warn_old = $deva['warnings_cnt'];
	$alarm_old = $deva['alarms_cnt'];
	$err_old = $deva['err_cnt'];

	// Check Battery/Humidity 
	$flags = $deva['flags'];
	if ($flags & 7) {	// Battery Voltage or Capacity
		if (($flags & 7) == 1) $lim = 25;
		else if (($flags & 7) == 2) $lim = 50;
		else $lim = 0;
		$vproc = 100;
		$cbat = 100;
		if (isset($lvala[90])) {	// Voltage
			$ulow = $deva['vbat0'];
			$uhigh = $deva['vbat100'];
			if ($uhigh > $ulow) $vproc = ($lvala[90] - $ulow) / ($uhigh - $ulow) * 100;
		}
		if (isset($lvala[93])) {	// Capacity
			$cbat = $deva['cbat'];
			if ($cbat > 0) $cproc = ($cbat - $lvala[93]) / ($cbat) * 100;
		}
		if ($vproc < $lim || $cbat < $lim) {
			$warn_new++;
			if (strlen($xlog) < 128) $xlog .= "(WARNING: Battery Low Limit)";
			if (count($info_wea) < 20) $info_wea[] = "WARNING: Battery Low Limit";
		}
	}
	if (($flags & 8) && isset($lvala[92]) && $lvala[92] > 80) {	// Feuchte >80%
		$warn_new++;
		if (strlen($xlog) < 128) $xlog .= "(WARNING: Internal Humidity High)";
		if (count($info_wea) < 20) $info_wea[] = "WARNING: Internal Humidity High";
	}


	// Check if Position Update is necessary (only with data)
	$deltap = @array(-1, 604700, 86300, 3500, 60)[$deva['posflags']];
	if ($fcnt > 0 && $deltap > 0 && $deva['lpos'] + $deltap < $now) {
		$devi = array();
		$lines = file(S_DATA . "/$mac/device_info.dat", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines !== false) {
			foreach ($lines as $line) {
				$tmp = explode("\t", $line);
				$devi[$tmp[0]] = $tmp[1];
			}
			$signal = @$devi['signal'];
			if (isset($signal)) {
				$cella = explode(" ", $signal); // Cell-Infos as Array
				$cell = array(); // KV-Array
				foreach ($cella as $kv) {
					$tmp = explode(":", $kv);
					$cell[$tmp[0]] = $tmp[1];
				}
				// Now Call Cellserver
				$sqs = CELLOC_SERVER_URL . '?k=' . G_API_KEY . "&s=$mac&mcc=" . $cell['mcc'] . "&net=" . $cell['net'] . "&lac=" . $cell['lac'] . "&cid=" . $cell['cid'];
				$ch = curl_init($sqs);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				$result = curl_exec($ch);
				if (curl_errno($ch))	$xlog .= "(ERROR: curl:'" . curl_error($ch) . "')";
				curl_close($ch);
				$obj = @json_decode($result);
				if (strcmp($obj->code, "OK")) $xlog .= "(ERROR: CELLOC: " . $obj->code . "," . $cres . $obj->info . ")";
				else {
					$nlat = $obj->lat;
					$nlon = $obj->lon;
					$nrad = $obj->accuracy;
					$insert_sql .= "lat = $nlat, lng = $nlon, rad = $nrad, last_gps=NOW(),";
					$xlog .= "(Automatic Pos. $nlat,$nlon,$nrad)";
					$trigger_fb .= "#C $nlat $nlon $nrad\n"; // If fast enough Feedback Pos. to lxu_v1.php
					$sqlps->execute(array(NULL, "<CELLOC $nlat $nlon $nrad>"));
				}
			}
		}
	}

	$las = $deva['last_seen'];
	if (isset($las)) $ageh = ($now - strtotime($las)) / 3600;
	else $ageh = 0;	// Macht kein Sinn - Never seen..
	$toalarm = $deva['timeout_alarm'];
	if ($toalarm > 0 &&  $toalarm > $ageh) {
		$alam_new++;
		$info_wea[] = "ALARM: Device last seen $age h ago ";
	} else {
		$towarn = $deva['timeout_warn'];
		if ($towarn > 0 && $towarn > $ageh) {
			$warn_new++;
			$info_wea[] = "WARNING: Device last seen $age h ago ";
		}
	}

	// Posibility to reset sth. -> old+new=tot
	$warn_tot = $warn_new + $warn_old;
	$alarm_tot = $alarm_new + $alarm_old;
	$err_tot = $err_new + $err_old;
	if ($warn_new) $xlog .= "($warn_new new Warnings)";
	if ($alarm_new) $xlog .= "($alarm_new new Alarms)";
	if ($err_new) $xlog .= "($err_new new Errors)";
	$cond0 = $deva['cond0'];	// Evaluate Condition 0
	if (!isset($cond0)) $cond0 = "";
	else $cond0 = trim($cond0);
	if (strlen($cond0) || ($reason & 256)) {
		$conds = explode(" ", $cond0);
		// Check Alarm Condition(s)
		$tlmail = $deva['em_date0'];
		if (!isset($tlmail)) $tlmail = "";
		$em_age0 = $now - strtotime($tlmail);	// Age of last Mail null->0
		// echo "Condition:'".$deva['cond0']."'\n"; // Bsp: An+1:M+1000 Et+3 Wn+1 -> AlarmNew>=1 UND Mail>=1000 ODER Etot>=3 ODER Wnew>=1
		$send_err = false;
		$send_or = 0;
		foreach ($conds as $scond) {
			$scond = trim($scond);
			if (!strlen($scond)) continue;
			//echo "-SCond:'$scond': ";
			$send_and = 1;
			$subcond = explode("*", $scond);
			foreach ($subcond as $term) {
				$kv = explode("+", $term);
				if (count($kv) > 2) {
					$xlog .= "(ERROR: Condition Term '$term')";
					$info_wea[] = "ERROR: Condition Term '$term'";
					$send_err = true;
					break;
				} else if (count($kv) > 1) $kval = intval($kv[1]);
				else $kval = 0;
				switch ($kv[0]) {
					case "An":
						if ($alarm_new < $kval) $send_and = 0;
						break;
					case "At":
						if ($alarm_tot < $kval) $send_and = 0;
						break;
					case "Wn":
						if ($warn_new < $kval) $send_and = 0;
						break;
					case "Wt":
						if ($warn_tot < $kval) $send_and = 0;
						break;
					case "En":
						if ($err_new < $kval) $send_and = 0;
						break;
					case "Et":
						if ($err_tot < $kval) $send_and = 0;
						break;
					case "M":
						if ($em_age0 < $kval) $send_and = 0;
						break;
					default:
						$xlog .= "(ERROR: Syntax of Term '$term')";
						$send_err = true;
						$info_wea[] = "ERROR: Syntax of Term '$term'";
						break;
				}
				//echo "((Eval: ".$kv[0].">=$kval)=$send_and)";
			}
			$send_or |= $send_and;
		}
		if ($send_err) $err_tot++;	// Can not Send Mail
		else if ($send_or || ($reason & 256)) { // $reason&256 trggers Mail!
			$mail_dest = $deva['email0'];
			if (!isset($mail_dest)) $mail_dest = "";
			if (strlen(trim($mail_dest))) {
				if ($dbg) echo "Send Mail to '$mail_dest'\n";
				$mailno = $deva['em_cnt0'] + 1;
				$from = "MAC.$mac"; // no : and '
				if (strlen($deva['name'])) $from .= " '" . $deva['name'] . "'";
				$subj = "$from (Mail $mailno)";
				$script = $_SERVER['PHP_SELF'];	// /xxx.php
				$lp = strpos($script, "sw");
				$sroot = substr($script, 0, $lp - 1);
				if (HTTPS_SERVER != null) $sec = "https://" . HTTPS_SERVER;
				else $sec = "http://" . $_SERVER['HTTP_HOST'];
				$url = $sec . $sroot;
				$xcont = $_GET['xc']; // Add. Content
				if (!isset($xcont)) $xcont = "(NoContent)";
				if (strlen($xcont)) {
					$cont = "\n$xcont\n";
					$mail_info = "Mail to '$mail_dest','$xcont'";
				} else {
					$cont = "";
					$mail_info = "Mail to '$mail_dest'";
				}
				$cont .= "\nLink: $url\n\n- Lines (new): $line_cnt\n- Total Transfers: " . $deva['transfer_cnt'] . "\n";

				if ($alarm_tot) $cont .= "- Alarms (new/total): $alarm_new/$alarm_tot\n";
				if ($err_tot) $cont .= "- Errors (new/total): $err_new/$err_tot\n";
				if ($warn_tot) $cont .= "- Warnings (new/total): $warn_new/$warn_tot\n";

				if (send_alarm_mail($mail_dest, $cont, $subj, $from) == true) {
					$statement = $pdo->prepare("UPDATE devices SET em_date0=NOW(), em_cnt0 = em_cnt0+1 WHERE mac = ?");
					$statement->execute(array($mac));
					$xlog .= "($mail_info OK)";
				} else {
					$xlog .= "(ERROR: $mail_info)";
					$info_wea[] = "ERROR: $mail_info";
					$err_tot++;
				}
			} else {
				$xlog .= "(ERROR: Mail: No Contact)";
				$info_wea[] = "ERROR: Mail: No Contact";
				$err_tot++;
			}
		}
	} // Cond0
}
// --Check-- OK

// 2.rd Part: Clear old data
$quota = @file(S_DATA . "/$mac/quota_days.dat");
$quota_days = intval(@$quota[0]);
if ($quota_days < 1) $quota_days = 366;	// 1 Day minimum, if unknown assume 1 year
$pdo->query("DELETE FROM m$mac WHERE DATEDIFF(NOW(), line_ts) > $quota_days;");
$quota_cnt = intval(@$quota[1]);

$stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM m$mac");
$stmt->execute();
$row = $stmt->fetch();
$gesanz = $row['count'];	// Gesamtanzahl
if ($quota_cnt > 0) {	// Evtl. auf Anzahl begrenzen
	$oldest =  $gesanz - $quota_cnt;
	if ($oldest > 0) {
		$stmt = $pdo->prepare("SELECT id FROM m$mac ORDER BY id LIMIT $oldest,1");
		$stmt->execute();
		$row = $stmt->fetch();
		$oldid = $row['id']; // Oldest data
		$pdo->query("DELETE FROM m$mac WHERE id < $oldid");
		$gesanz = $quota_cnt;	// Maximum
	}
}

// 3.nd Part of DB Update String
$insert_sql .= "transfer_cnt = transfer_cnt + $file_cnt,
		lines_cnt = lines_cnt + $line_cnt,
		warnings_cnt =  $warn_tot,
		alarms_cnt = $alarm_tot,
		err_cnt = $err_tot,
		anz_lines = $gesanz 
		WHERE mac ='$mac'";


$qres = $pdo->exec($insert_sql); // return 0 for no match
if ($qres === false) {
	if ($dbg) echo ("(ERROR 105:" . $pdo->errorInfo()[2] . ")"); // Can not Update Table?
	$xlog .= "(ERROR: Update DB 'devices')";
	$err_new++;
	$info_wea[] = "ERROR: Write3 DB failed";
}

if ($dbg) {
	echo "InsertSQL: '$insert_sql'\n";
	echo "Wo:$warn_old Ao:$alarm_old Eo:$err_old\n";
	echo "Wn:$warn_new An:$alarm_new En:$err_new\n";
	echo "Wt:$warn_tot At:$alarm_tot Et:$err_tot\n";
	echo "Lines:$line_cnt\n";
}

if ($ign_cnt) $xlog = "($file_cnt Files, $ign_cnt ignored)" . $xlog;
else $xlog = "($file_cnt Files)" . $xlog;

// Save ERROR WARNING ALARM File
if (count($info_wea)) {
	$logpath = S_DATA . "/$mac/";
	if (@filesize($logpath . "info_wea.txt") > 50000) {	// ErrorWarningAlarm Log
		@unlink($logpath . "_info_wea_old.txt");
		rename($logpath . "info_wea.txt", $logpath . "_info_wea_old.txt");
	}
	$log = @fopen($logpath . "info_wea.txt", 'a');
	$nowdate = gmdate("d.m.y H:i:s", $now) . " UTC ";
	foreach ($info_wea as $line) {
		fputs($log, $nowdate . $line . "\n");
	}
	fclose($log);
}

$mtrun = round((microtime(true) - $mttr_t0) * 1000, 4);
$xlog .= "(Run:$mtrun msec)"; // Script Runtime

echo "*TRIGGER(DBG:$dbg) RES:$res ('$xlog')*\n"; // Always
echo $trigger_fb; // Send Feedback

add_logfile();
