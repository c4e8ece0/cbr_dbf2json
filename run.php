<?php

// --------------------------------------------------------------------------
// Подготовка списка банков с реквизитами из экспорта базы данных
// кредитных организаций cbr.ru
// --------------------------------------------------------------------------

define('DIR_ROOT', dirname(__FILE__).'/');
define('DIR_CACHE', DIR_ROOT.'cache/');

define('URL_PREFIX', 'http://cbr.ru');
define('URL_START', URL_PREFIX.'/mcirabis/?PrtId=bic');
define('EMAIL_LOG', 'cbrdbf@adsem.ru');
define('CACHE_PERIOD', 60*45*1); // 60*45*1 = 45 minutes
define('CACHE_PREFIX', round(time()/CACHE_PERIOD));

// Предпроверка возможностей
if(!function_exists('dbase_open')) {
	return logerr("Know nothing about dbf-databases");
}

// Получение страницы с именами файлов
$page = fetch(URL_START);
if(!$page) {
	return logerr("Can't fetch start url");
}

if(!preg_match_all('#<a href="(/mcirabis/BIK/bik_db_\d+.zip)">#isu', $page, $m, PREG_SET_ORDER)) {
	return logerr("Can't find urls for zip-file (total=".count($m).")");
}
$con = array();
foreach($m as $k=>$v) {
	$con[] = $v[1];
}
$con = array_unique($con);
if(count($con) != 1) {
	print "<p>There are too many files (>1), getting first after arsort()</p>";
	arsort($con);
}

// Получение файла базы данных
$url = URL_PREFIX.$con[0];
$zip = fetch($url);
if(!$zip) {
	return logerr("Can't download ". $url);	
}
$zippath = DIR_CACHE.cacheid($url);
$dbfpath = $zippath.'.dbf';
$dbffile = file_get_contents('zip://'.$zippath.'#bnkseek.dbf');
if(!$dbffile) {
	return logerr("Can't extract bnkseek.dbf from archive");
}
if(!file_put_contents($dbfpath, $dbffile)) {
	return logerr("Can't write new dbf-file");
}

// Конверсия данных в нужный формат
$db = dbase_open($dbfpath, 0);
if (!$db) {
	return logerr('Can\'t open dbf at ' . $path);
}
$data = array();
$last = dbase_numrecords($db);
for($i = 0; $i< $last; $i++) {
	$data[$i] = dbase_get_record_with_names($db,$i+1);
}
dbase_close($db);

ob_start();
print implode("\t", array_map('trim', array_keys($data[1]))) . "\n";
$json = array();
foreach($data as $arr) {
	print implode("\t", array_map('trim', $arr)) . "\n";
	$json[] = array(
		'addr' => iconv('CP866', 'UTF-8', (trim($arr['NNP'])?trim($arr['NNP']).', ':'') . trim($arr['ADR'])),
		'name' => trim(iconv('CP866', 'UTF-8', $arr['NAMEP'])),
		'small' => trim(iconv('CP866', 'UTF-8', $arr['NAMEN'])),
		'bik' => trim($arr['NEWNUM']),
		'ks' => trim($arr['KSNP']),
		// 'inn' => $arr['ks'],
	);
}
$t = iconv('CP866', 'UTF-8', ob_get_clean());
file_put_contents(DIR_CACHE.'_last.tsv', $t, LOCK_EX);
file_put_contents(DIR_CACHE.'_last.json', json_encode($json), LOCK_EX);


// Зачистка
$arr = scandir(DIR_CACHE);
$par = array();
foreach($arr as $k=>$v) {
	if($v[0]=='.' || $v[0] =='_') {
		continue;
	}
	list($pref, $name) = explode('.', $v);
	$par[$pref][] = $v;
}
if(count($par) > 1) {
	krsort($par);
	array_shift($par);
	foreach($par as $k=>$v) {
		foreach($v as $a=>$b) {
			unlink(DIR_CACHE.$b);
		}
	}
}


// --------------------------------------------------------------------------
// --------------------------------------------------------------------------
// --------------------------------------------------------------------------

function fetch($url) {
	$path = DIR_CACHE.cacheid($url);

	if(!file_exists($path)) {
		$t = @file_get_contents($url);
		if($t && file_put_contents($path, $t, LOCK_EX)) {
			return $t;
		} else {
			return '';
		}
	}
	return file_get_contents($path);
}

function cacheid($base) {
	return sprintf("%09d.%s", CACHE_PREFIX, md5($base));
}

function logerr() {
	$str = strftime("%Y-%m-%d %H:%M:%S").': '.implode('|', func_get_args());
	$str = preg_replace('/\s+/isu', ' ', $str);
	file_put_contents(DIR_CACHE.'_log.txt', trim($str)."\n", LOCK_EX|FILE_APPEND);
	header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
	mail(EMAIL_LOG, func_get_arg(0), 'subj');
	return 1;
}

?>