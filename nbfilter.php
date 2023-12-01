<?
/*
populate database
	possibilities:
		nutrition [individual nutrients, pyramid/myplate placement]
		video games/movies [title, studio/developer/publisher?, genre, review rating, age rating (and details)]
			use TGDB: https://github.com/TheGamesDB/TheGamesDB
				OMG! Why the hell isn't this normalized?!? It's like they attempted to, but then continued using giant tables...
		contacts [name, phone number, address, gender, birth date, race]
conditions
	what fields will be available
	what operators will be implemented and which fields will be able to use them
field definition format:
	server {
		$field:{
			$op:$table_field|$gen_sql_func($cond)
			,...
		}
		,...
	}
	client {
		$field:{
			display:$name
			,ops:{
				$op:[$display,$create_op($elem,$value,$data),$read_op($elem)]
				,...
			}
			,data:[$value,...]|{$id:$value,...} #This is for autocompletes and select elements. Really it could be any format, since it will only be used by $create_op functions
		}
		,...
	}
filter format
	root=group
	group: {type:"group",op:#,data:[group|condition,...]}
	group.op: 0 for AND, 1 for OR
	condition: {type:"cond",field:$field,op:$op,value:$value}
*/
ini_set('html_errors',0);

function esc_js($dirty,$flags=0){
	return json_encode($dirty,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|$flags);
}

$db=new PDO('mysql:host=database;dbname=games;charset=utf8','root','',[
	PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
	,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_NUM
	,PDO::MYSQL_ATTR_INIT_COMMAND=>"SET SESSION time_zone='+00:00';"
	/*Pull all data into PHP's memory when a query finishes because mysql[1] can't handle multiple queries on one connection.
	[1]: it might actually be the API implementation, but mysqli does it by default, more research needed.*/
	,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>true
]);

if(0){ #To help determine what needs to be normalized
	$fields=['id' ,'GameTitle' ,'GameID' ,'Players' ,'ReleaseDate' ,'Developer' ,'Publisher' ,'Runtime' ,'Genre' ,'Actors' ,'Overview' ,'bannerrequest' ,'created' ,'lastupdated' ,'Airs_DayOfWeek' ,'Airs_Time' ,'Rating' ,'flagged' ,'forceupdate' ,'hits' ,'updateID' ,'requestcomment' ,'locked' ,'mirrorupdate' ,'lockedby' ,'autoimport' ,'disabled' ,'IMDB_ID' ,'zap2it_id' ,'Platform' ,'coop' ,'os' ,'processor' ,'ram' ,'hdd' ,'video' ,'sound' ,'Youtube' ,'Alternates' ,'author' ,'updatedby'];
	$dcs=[]; #Distinct Counts
	foreach($fields as $field){
		$temp=$db->query(
			"SELECT {$field} field,COUNT(*) cnt
			FROM games
			GROUP BY field
			HAVING cnt>2
			ORDER BY cnt DESC
			LIMIT 20;"
		)->fetchAll(PDO::FETCH_FUNC,function($field,$cnt){
		return "{$cnt} || {$field}";
		});
		if(count($temp)<3){continue;}
		$dcs[$field]=$temp;
	}
	echo array2html($dcs);
	exit;
	/*
	Genre needs to be redone using a many:many table. A genre table already
	exists so we just need to create a game_genre table, explode games.genre
	by pipes, create a genres row if needed for each genre, and finally insert
	the ids into games_genres.
	CREATE TABLE game_genre (game_id INT(10) UNSIGNED NOT NULL, genre_id INT(10) UNSIGNED NOT NULL, PRIMARY KEY(game_id,genre_id)) ENGINE=MYISAM;

	Platform is the only referenced table
	Rating should be normalized out

	Developer and Publisher should be normalized into their own tables... or
	maybe one table that specifies whether a string is a developer and/or
	publisher... screw it, two tables, easier.
	*/
}

if(0){ #Fill game_genre table and display a list so we can manually verify that it is right
	$get_genre=$db->prepare("SELECT id FROM genres WHERE genre=?;");
	$ins_genre=$db->prepare("INSERT genres (genre) VALUES (?);");
	#A "failed insert" is probably faster than checking if the row already exists. INSERT IGNORE also works, but causes warnings if there is a conflict.
	$ins_gage=$db->prepare("INSERT game_genre (game_id,genre_id) VALUES (?,?) ON DUPLICATE KEY UPDATE game_id=game_id;");

	$result_games=$db->query("SELECT id,genre FROM games WHERE genre!='';");
	while($row_game=$result_games->fetch()){
		foreach(array_filter(explode('|',$row_game[1])) as $genre){
			$get_genre->execute([$genre]);
			if(!($genre_id=$get_genre->fetchColumn())){
				$ins_genre->execute([$genre]);
				$genre_id=$db->lastInsertId();
				echo "Created {$genre_id}:{$genre}";
			}
			$ins_gage->execute([$row_game[0],$genre_id]);
		}
	}
	echo array2html($db->query(
		"SELECT
			g.id
			,g.GameTitle
			,g.genre field
			,GROUP_CONCAT(CONCAT(ge.id,ge.genre)) joined
		FROM games g
			INNER JOIN game_genre gg ON (g.id=gg.game_id)
			INNER JOIN genres ge ON (gg.genre_id=ge.id)
		GROUP BY g.id;"
	)->fetchAll(PDO::FETCH_ASSOC));
	exit;
}

global $fields;
$fields=[
	'game'        =>['contains'=>'g.GameTitle']
	,'coop'       =>['eq_str'=>'g.coop']
	,'max_players'=>['eq_int'=>'g.Players']
	,'rating'     =>['eq_str'=>'g.Rating']
	,'genre'      =>['int_in_list'=>'ge.genre_ids']
	,'platform'   =>['eq_int'=>'g.Platform']
	,'publisher'  =>['contains'=>'g.Publisher']
	,'developer'  =>['contains'=>'g.Developer']
];

function tg2s(array $group){ #translate group to SQL
	if(!isset($group['type'],$group['op'],$group['data'])){throw new Exception("Group is missing keys: ".json_encode($group));}
	if($group['type']!=='group'){throw new Exception("Given type is wrong: ".$group['type']);}
	if(!($temp=gettype($group['data']))){throw new Exception("Expected \$group['data'] to be an array, got ".$temp);}
	$conditions=array_filter(array_map('tc2s',$group['data']),'is_string');
	if(!$conditions){return null;}
	if(count($conditions)===1){return reset($conditions);} #array_filter doesn't reorder the array, reset will return the first element.
	return "(".implode(
		($group['op']===1 ? ' OR ' : ' AND ')
		,$conditions
	).")";
}

function tc2s($cond){ #translate condition to SQL
	global $fields,$db;
	if(!isset($cond['type'])){throw new Exception("Condition is missing type");}
	if($cond['type']==='group'){return tg2s($cond);}

	if($cond['type']!=='cond'){throw new Exception("Expected type to be 'cond', got ".json_encode($cond['type']));}
	if(!isset($cond['field'],$cond['op'],$cond['value'])){throw new Exception("Condition is missing keys: ".json_encode($cond));}
	if(!$cond['field']||!$cond['op']){return null;} #Incomplete condition, should we complain?
	if(!isset($fields[$cond['field']][$cond['op']])){throw new Exception("No such field/op combination exists: {$cond['field']}/{$cond['op']}");}

	#This is for when things get a bit more complex or the operator code is only useful for one field/op combination
	$field=$fields[$cond['field']][$cond['op']];
	if(!is_string($field)&&is_callable($field)){return $field($cond);}

	switch($cond['op']){
		case 'contains': return "{$field} LIKE ".$db->quote('%'.$cond['value'].'%');
		case 'eq_str': return "{$field}=".$db->quote($cond['value']);
		case 'eq_int': return "{$field}=".(int)$cond['value'];
		case 'int_in_list': return "FIND_IN_SET(".(int)$cond['value'].",{$field})";
	}
	#All cases above should return
	throw new Exception("Operation not implemented");
}

if(isset($_GET['submit'])){
	$input=file_get_contents('php://input');
	$group=json_decode($input,1);
	if(!$group){throw new Exception("Bad input: ".$input);}
	header("Content-Type: application/json; charset=UTF-8");

	try{$where=tg2s($group);}
	catch(Exception $e){
		echo json_encode(['error'=>$e->getMessage()]);
		throw $e; #Let the error handler log it.
	}

	header("Where: ".json_encode($where));
	$result=$db->query(
		"SELECT
			g.GameTitle
			,CONCAT(g.Publisher,' (',g.Developer,')')
			,p.name
			,g.Rating
			/*,ge.genres*/
		FROM games g
			LEFT JOIN (
				SELECT gg.game_id,GROUP_CONCAT(ge.id) genre_ids,GROUP_CONCAT(ge.genre) genres
				FROM game_genre gg
					INNER JOIN genres ge ON (gg.genre_id=ge.id)
				GROUP BY gg.game_id
			) ge ON (g.id=ge.game_id)
			LEFT JOIN platforms p ON (g.Platform=p.id)
		WHERE ".($where?:1)."
		ORDER BY p.name, g.GameTitle;"
	);
	$columns=[];
	for($i=0,$count=$result->columnCount(); $i<$count; ++$i){
		$columns[]=$result->getColumnMeta($i)['name'];
	}
	echo json_encode([
		'error'=>0
		,'columns'=>$columns
		,'result'=>$result->fetchAll()
	]);
	exit;
}

header(isset($_SERVER['HTTP_ACCEPT'])&&stripos($_SERVER['HTTP_ACCEPT'],"application/xhtml+xml")!==false
	? "Content-Type: application/xhtml+xml; charset=UTF-8"
	: "Content-Type: text/html; charset=UTF-8"
);
while(ob_get_level()){ob_end_clean();} #Remove output buffers, we're not changing headers after this point.
?>
<!DOCTYPE html>
<html xmlns='http://www.w3.org/1999/xhtml'>
<head>
	<title>Nested Boolean Filter | Videogame Example</title>

	<script>//<![CDATA[
		'use strict';
		window.console.warn=(function(warn){
			return function(){
				warn.apply(window.console,arguments);
				window.alert('Javascript warning.');
			};
		}(window.console.warn));

		window.onerror=function(message, source, lineno, colno, error){
			alert(
				'Javascript Error!\n'
				+message+'\n'
				+source+':['+lineno+','+colno+']'
			);
		};
	//]]></script>

	<style>
		/* http://meyerweb.com/eric/tools/css/reset/
		   v2.0 | 20110126
		   License: none (public domain)
		*/

		html, body, div, span, applet, object, iframe,
		h1, h2, h3, h4, h5, h6, p, blockquote, pre,
		a, abbr, acronym, address, big, cite, code,
		del, dfn, em, img, ins, kbd, q, s, samp,
		small, strike, strong, sub, sup, tt, var,
		b, u, i, center,
		dl, dt, dd, ol, ul, li,
		fieldset, form, label, legend,
		table, caption, tbody, tfoot, thead, tr, th, td,
		article, aside, canvas, details, embed,
		figure, figcaption, footer, header, hgroup,
		menu, nav, output, ruby, section, summary,
		time, mark, audio, video {
			margin: 0;
			padding: 0;
			border: 0;
			font-size: 100%;
			font: inherit;
			vertical-align: baseline;
		}
		/* HTML5 display-role reset for older browsers */
		article, aside, details, figcaption, figure,
		footer, header, hgroup, menu, nav, section {
			display: block;
		}
		body {
			line-height: 1;
		}
		ol, ul {
			list-style: none;
		}
		blockquote, q {
			quotes: none;
		}
		blockquote:before, blockquote:after,
		q:before, q:after {
			content: '';
			content: none;
		}
		table {
			border-collapse: collapse;
			border-spacing: 0;
		}
	</style>

	<style>
		body {margin:1em;}
		h1,h2,h3,h4,h5,h6 {
			font-weight:bold;
			margin-bottom:0.2em;
		}
		h1 {font-size:2em;}
		h2 {font-size:1.78em;}
		h3 {font-size:1.59em;}
		h4 {font-size:1.41em;}
		h5 {font-size:1.26em;}
		h6 {font-size:1.12em;}

		#filter_block .filter_group  ,#filter_block .filter_cond>div   {display:table;}
		#filter_block .filter_group>*,#filter_block .filter_cond>div>* {display:table-cell;}
		#filter_block .filter_group {
			padding:0.25em;
			border:1px solid black;
			background-color:rgba(0,0,0,0.1);
		}
		#filter_block .filter_group .connector {
			vertical-align:middle;
			padding-right:1em;
		}
		#filter_block .filter_cond {
			background-color:rgba(255,255,255,0.5);
			padding:0.25em;
		}
		#filter_block .sortable_placeholder {

		}

		#result_list td {padding:0 0.5em;}
	</style>
</head>
<body>
	<h1>Nested Boolean Filter | Videogame Example</h1>

	<button id='add_group'>Add Group</button>
	&#xa0; &#xa0;
	<button id='add_cond'>Add Condition</button>
	<br/><br/>
	<ul id='filter_block'></ul>
	<button id='submit_filter'>Read group</button>
	<table>
		<tbody id='result_list'></tbody>
	</table>

	<div id='templates' style='display:none;'>
		<li class='filter_group'>
			<span class='connector'>
				<div>
					<span class='handle'>Move</span>
					<span class='delete'>Delete</span>
				</div>
				<div>
					<select>
						<option value='0'>AND</option>
						<option value='1'>OR</option>
					</select>
				</div>
			</span>
			<ul class='data'></ul>
		</li>
		<li class='filter_cond'><div>
			<span>
				<span class='handle'>Move</span>
				<span class='delete'>Delete</span>
			</span>
			<span>
				<select class='field'>
					<option value=''>Select a field</option>
				</select>
			</span>
			<span>
				<select class='operator' disabled='disabled'></select>
			</span>
			<span class='value'></span>
		</div></li>
	</div>

	<?
	flush();
	$data=[
		'coop'=>[]
		,'max_players'=>[]
		,'rating'=>[]
		,'genre'=>[]
		,'platform'=>[]
		,'publisher'=>[]
		,'developer'=>[]
		,'game'=>[]
	];
	$result=$db->query(
		"SELECT
			GameTitle game
			,Players max_players
			,Developer developer
			,Publisher publisher
			,Rating rating
		FROM games;"
	);
	while($row=$result->fetch(PDO::FETCH_ASSOC)){
		foreach($row as $k=>$v){
			if($v&&!in_array($v,$data[$k],true)){$data[$k][]=$v;}
		}
	}
	array_walk($data,function(&$v){sort($v,SORT_NATURAL|SORT_FLAG_CASE);});
	foreach(['genre'=>'genre','platform'=>'name'] as $table=>$field){
		$data[$table]=$db->query(
			"SELECT id,{$field}
			FROM {$table}s
			ORDER BY {$field};"
		)->fetchAll();
	}
	$data['coop']=['Yes','No',['','Maybe']];
	/*Just some nonsense to sort the arrays by their length* /
	uasort($data,function($a,$b){
		return count($a)-count($b);
	});
	/**/
	?>
	<script src='ext/jquery.min.js'></script>
	<script src='ext/jquery-ui.min.js'></script>
	<script src='ext/jquery.mjs.nestedSortable.js'></script>
	<script src='nbfilter.js'></script>
	<?flush();?>
	<script>//<![CDATA[
		nbfilter_init(<?=esc_js($data)?>);
	//]]></script>
</body>
</html>
