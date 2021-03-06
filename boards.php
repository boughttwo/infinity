<?php

include "inc/functions.php";
include "inc/countries.php";

$admin = isset($mod["type"]) && $mod["type"]<=30;

if (php_sapi_name() == 'fpm-fcgi' && !$admin) {
	error('Cannot be run directly.');
}
$boards = listBoards();
$all_tags = array();
$total_posts_hour = 0;
$total_posts = 0;
$write_maxes = false;

function to_tag($str) {
	$str = trim($str);
	$str = strtolower($str);
	$str = str_replace(['_', ' '], '-', $str);
	return $str;
}

if (!file_exists('maxes.txt') || filemtime('maxes.txt') < (time() - (60*60))) {
	$fp = fopen('maxes.txt', 'w+');
	$write_maxes = true;
}

foreach ($boards as $i => $board) {
	$query = prepare(sprintf("
SELECT IFNULL(MAX(id),0) max,
(SELECT COUNT(*) FROM ``posts_%s`` WHERE FROM_UNIXTIME(time) > DATE_SUB(NOW(), INTERVAL 1 HOUR)) pph,
(SELECT COUNT(DISTINCT ip) FROM ``posts_%s`` WHERE FROM_UNIXTIME(time) > DATE_SUB(NOW(), INTERVAL 3 DAY)) uniq_ip
 FROM ``posts_%s``
", $board['uri'], $board['uri'], $board['uri'], $board['uri'], $board['uri']));
	$query->execute() or error(db_error($query));
	$r = $query->fetch(PDO::FETCH_ASSOC);

	$tquery = prepare("SELECT `tag` FROM ``board_tags`` WHERE `uri` = :uri");
	$tquery->execute([":uri" => $board['uri']]) or error(db_error($tquery));
	$r2 = $tquery->fetchAll(PDO::FETCH_ASSOC);

	$tags = array();
	if ($r2) {
		foreach ($r2 as $ii => $t) {
			$tag=to_tag($t['tag']);
			$tags[] = $tag;
			if (!isset($all_tags[$tag])) {
				$all_tags[$tag] = (int)$r['uniq_ip'];
			} else {
				$all_tags[$tag] += $r['uniq_ip'];
			}
		}
	}

	$pph = $r['pph'];

	$total_posts_hour += $pph;
	$total_posts += $r['max'];

	$boards[$i]['pph'] = $pph;
	$boards[$i]['ppd'] = $pph*24;
	$boards[$i]['max'] = $r['max'];
	$boards[$i]['uniq_ip'] = $r['uniq_ip'];
	$boards[$i]['tags'] = $tags;

	if ($write_maxes) fwrite($fp, $board['uri'] . ':' . $boards[$i]['max'] . "\n");
}
if ($write_maxes) fclose($fp);

usort($boards, 
function ($a, $b) { 
	$x = $b['uniq_ip'] - $a['uniq_ip']; 
	if ($x) { return $x; 
	//} else { return strcmp($a['uri'], $b['uri']); }
	} else { return $b['max'] - $a['max']; }
});

$hidden_boards_total = 0;
$rows = array();
foreach ($boards as $i => &$board) {
	$board_config = @file_get_contents($board['uri'].'/config.php');
	$boardCONFIG = array();
	if ($board_config && $board['uri'] !== 'int') {
		$board_config = str_replace('$config', '$boardCONFIG', $board_config);
		$board_config = str_replace('<?php', '', $board_config);
		@eval($board_config);
	}
	$showboard = $board['indexed'];
	$locale = isset($boardCONFIG['locale'])?$boardCONFIG['locale']:'en';

	$board['title'] = utf8tohtml($board['title']);
	$locale_arr = explode('_', $locale);
	$locale_short = isset($locale_arr[1]) ? strtolower($locale_arr[1]) : strtolower($locale_arr[0]);
	$locale_short = str_replace('.utf-8', '', $locale_short);
	$country = get_country($locale_short);
	if ($board['uri'] === 'int') {$locale_short = 'eo'; $locale = 'eo'; $country = 'Esperanto';}

	$board['img'] = "<img class=\"flag flag-$locale_short\" src=\"/static/blank.gif\" style=\"width:16px;height:11px;\" alt=\"$country\" title=\"$country\">";

	if ($showboard || $admin) {
		if (!$showboard) {
			$lock = ' <i class="fa fa-lock" title="No index"></i>';
		} else {
			$lock = '';
		}
		$board['ago'] = human_time_diff(strtotime($board['time']));
	} else {
		unset($boards[$i]);
		$hidden_boards_total += 1;
	}
}

$n_boards = sizeof($boards);
$t_boards = $hidden_boards_total + $n_boards;

$boards = array_values($boards);
arsort($all_tags);

$config['additional_javascript'] = array('js/jquery.min.js', 'js/jquery.tablesorter.min.js');
$body = Element("8chan/boards-tags.html", array("config" => $config, "n_boards" => $n_boards, "t_boards" => $t_boards, "hidden_boards_total" => $hidden_boards_total, "total_posts" => $total_posts, "total_posts_hour" => $total_posts_hour, "boards" => $boards, "last_update" => date('r'), "uptime_p" => shell_exec('uptime -p'), 'tags' => $all_tags, 'top2k' => false));

$html = Element("page.html", array("config" => $config, "body" => $body, "title" => "Boards on &infin;chan"));
$boards_top2k = $boards;
array_splice($boards_top2k, 2000);
$boards_top2k = array_values($boards_top2k);
$body = Element("8chan/boards-tags.html", array("config" => $config, "n_boards" => $n_boards, "t_boards" => $t_boards, "hidden_boards_total" => $hidden_boards_total, "total_posts" => $total_posts, "total_posts_hour" => $total_posts_hour, "boards" => $boards_top2k, "last_update" => date('r'), "uptime_p" => shell_exec('uptime -p'), 'tags' => $all_tags, 'top2k' => true));
$html_top2k = Element("page.html", array("config" => $config, "body" => $body, "title" => "Boards on &infin;chan"));

if ($admin) {
	echo $html;
} else {
	foreach ($boards as $i => &$b) { unset($b['img']); }
	file_write("boards.json", json_encode($boards));
	file_write("tags.json", json_encode($all_tags));
	foreach ($boards as $i => $b) {
		if (in_array($b['uri'], $config['no_top_bar_boards'])) {
			unset($boards[$i]);
		}
		unset($boards[$i]['img']);
	}

	array_splice($boards, 48);

	$boards = array_values($boards);

	file_write("boards-top20.json", json_encode($boards));
	file_write("boards.html", $html_top2k);
	file_write("boards_full.html", $html);
	echo 'Done';
}

