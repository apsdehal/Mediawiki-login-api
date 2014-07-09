<?PHP

/*
error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
ini_set('display_errors', 'On');

ini_set('memory_limit','500M');
set_time_limit ( 60 * 10 ) ; // Seconds
*/

define('CLI', PHP_SAPI === 'cli');
ini_set('user_agent','Magnus labs tools'); # Fake user agent
header("Connection: close");
$tools_webproxy = 'tools-webproxy' ;
$tusc_url = "http://$tools_webproxy/tusc/tusc.php" ; // http://tools-webserver-01/ // tools.wmflabs.org
$use_db_cache = false ;
$common_db_cache = array() ;
$wdq_internal_url = 'http://wikidata-wdq-mm.eqiad.wmflabs/api' ; //'http://wdq.wmflabs.org/api' ;

function myurlencode ( $t ) {
	$t = str_replace ( " " , "_" , $t ) ;
	$t = urlencode ( $t ) ;
	return $t ;
}

function getDBpassword () {
	global $mysql_user , $mysql_password , $tool_user_name ;
	if ( isset ( $tool_user_name ) ) $user = $tool_user_name ;
	else $user = str_replace ( 'tools.' , '' , get_current_user() ) ;
	$passwordfile = '/data/project/' . $user . '/replica.my.cnf' ;
	if ( $user == 'magnus' ) $passwordfile = '/home/' . $user . '/replica.my.cnf' ; // Command-line usage
	$t = file_get_contents ( $passwordfile ) ;
	$lines = explode ( "\n" , $t ) ;
	foreach ( $lines AS $l ) {
		$l = explode ( '=' , trim ( str_replace ( "'" , '' , $l  ) ) , 2 ) ;
		if ( $l[0] == 'user' ) $mysql_user = $l[1] ;
		if ( $l[0] == 'password' ) $mysql_password = $l[1] ;
	}
}

function getDBname ( $language , $project ) {
	$ret = $language ;
	if ( $language == 'commons' ) $ret = 'commonswiki_p' ;
	else if ( $language == 'wikidata' || $project == 'wikidata' ) $ret = 'wikidatawiki_p' ;
	else if ( $project == 'wikipedia' ) $ret .= 'wiki_p' ;
	else if ( $project == 'wikisource' ) $ret .= 'wikisource_p' ;
	else if ( $project == 'wiktionary' ) $ret .= 'wiktionary_p' ;
	else if ( $project == 'wikibooks' ) $ret .= 'wikibooks_p' ;
	else if ( $project == 'wikinews' ) $ret .= 'wikinews_p' ;
	else if ( $project == 'wikiversity' ) $ret .= 'wikiversity_p' ;
	else if ( $project == 'wikivoyage' ) $ret .= 'wikivoyage_p' ;
	else if ( $project == 'wikiquote' ) $ret .= 'wikiquote_p' ;
	else die ( "Cannot construct database name for $language.$project - aborting." ) ;
	return $ret ;
}

function openToolDB ( $dbname = '' , $server = '' , $force_user = '' ) {
	global $o , $mysql_user , $mysql_password ;
	getDBpassword() ;
	if ( $dbname == '' ) $dbname = '_main' ;
	else $dbname = "__$dbname" ;
	if ( $force_user == '' ) $dbname = $mysql_user.$dbname;
	else $dbname = $force_user.$dbname;
	if ( $server == '' ) $server = "tools-db" ;
	$db = new mysqli($server, $mysql_user, $mysql_password, $dbname);
	if($db->connect_errno > 0) {
		$o['msg'] = 'Unable to connect to database [' . $db->connect_error . ']';
		$o['status'] = 'ERROR' ;
		return false ;
	}
	return $db ;
}

function openDBwiki ( $wiki ) {
	preg_match ( '/^(.+)(wik.+)$/' , $wiki , $m ) ;
	if ( $m == null ) {
		print "Cannot parse $wiki\n" ;
		return ;
	}
	if ( $m[2] == 'wiki' ) $m[2] = 'wikipedia' ;
	return openDB ( $m[1] , $m[2] ) ;
}

function openDB ( $language , $project ) {
	global $mysql_user , $mysql_password , $o , $common_db_cache , $use_db_cache ;
	
	$db_key = "$language.$project" ;
	if ( isset ( $common_db_cache[$db_key] ) ) return $common_db_cache[$db_key] ;
	
	getDBpassword() ;
	$dbname = getDBname ( $language , $project ) ;

	$p = $project ;
	if ( $p == "wikipedia" ) $p = "wiki" ;
	
	$l = str_replace ( 'classic' , 'classical' , $language ) ;
	if ( $l == 'commons' ) $p = 'wiki' ;
	else if ( $l == 'wikidata' or $project == 'wikidata' ) $p = 'wiki' ;
	$server = "$l$p.labsdb" ;

	$db = new mysqli($server, $mysql_user, $mysql_password, $dbname);
	if($db->connect_errno > 0) {
		$o['msg'] = 'Unable to connect to database [' . $db->connect_error . ']';
		$o['status'] = 'ERROR' ;
		return false ;
	}
	if ( $use_db_cache ) $common_db_cache[$db_key] = $db ;
	return $db ;
}

function get_db_safe ( $s , $fixup = false ) {
	global $db ;
	if ( $fixup ) $s = str_replace ( ' ' , '_' , trim ( ucfirst ( $s ) ) ) ;
	return $db->real_escape_string ( str_replace ( ' ' , '_' , $s ) ) ;
}

function make_db_safe ( &$s , $fixup = false ) {
	$s = get_db_safe ( $s , $fixup ) ;
}

function findSubcats ( $db , $root , &$subcats , $depth = -1 ) {
	global $testing ;
	$check = array() ;
	$c = array() ;
	foreach ( $root AS $r ) {
		if ( isset ( $subcats[$r] ) ) continue ;
		$subcats[$r] = get_db_safe ( $r ) ;
		$c[] = str_replace ( ' ' , '_' , $db->escape_string ( $r ) ) ;
	}
	if ( count ( $c ) == 0 ) return ;
	if ( $depth == 0 ) return ;
	$sql = "select distinct page_title from page,categorylinks where page_id=cl_from and cl_to IN ('" . implode ( "','" , $c ) . "') and cl_type='subcat'" ;
	if(!$result = $db->query($sql)) die('There was an error running the query [' . $db->error . ']');
#	if ( $testing ) print "<pre>$depth : $sql</pre>" ;
	while($row = $result->fetch_assoc()){
		if ( isset ( $subcats[$row['page_title']] ) ) continue ;
		$check[] = $row['page_title'] ;
	}
#	if ( $testing ) print_r ( $check ) ;
	if ( count ( $check ) == 0 ) return ;
	findSubcats ( $db , $check , $subcats , $depth - 1 ) ;
}

function getPagesInCategory ( $db , $category , $depth = 0 , $namespace = 0 , $no_redirects = false ) {
	global $testing ;
	$ret = array() ;
	$cats = array() ;
	findSubcats ( $db , array($category) , $cats , $depth ) ;
	if ( $namespace == 14 ) return $cats ; // Faster, and includes root category

	$namespace *= 1 ;
	$sql = "SELECT DISTINCT page_title FROM page,categorylinks WHERE cl_from=page_id AND page_namespace=$namespace AND cl_to IN ('" . implode("','",$cats) . "')" ;
	if ( $no_redirects ) $sql .= " AND page_is_redirect=0" ;

	if(!$result = $db->query($sql)) die('There was an error running the query [' . $db->error . ']');
	while($o = $result->fetch_object()){
		$ret[$o->page_title] = $o->page_title ;
	}
	return $ret ;
}


//________________________________________________________________________________________
// Misc


function get_common_header ( $script , $title , $p = array() ) {
	if ( !headers_sent() ) {
		header('Content-type: text/html');
		header("Cache-Control: no-cache, must-revalidate");
	}
	$s = file_get_contents ( '/data/project/magnustools/public_html/resources/html/dummy_header.html' ) ;
	if ( isset ( $p['style'] ) ) $s = str_replace ( '</style>' , $p['style'].'</style>' , $s ) ;
	if ( isset ( $p['script'] ) ) $s = str_replace ( '</script>' , $p['script'].'</script>' , $s ) ;
	
	$misc = '' ;
	if ( isset ( $p['link'] ) ) $misc .= $p['link'] ;
	$s = str_replace ( '<!--header_misc-->' , $misc , $s ) ;
	
	$s = str_replace ( '$$TITLE$$' , $title , $s ) ;
	return $s ;
}

function get_common_footer() {
	return "</div></div></body></html>" ;
}


function get_request ( $key , $default = "" ) {
	global $prefilled_requests ;
	if ( isset ( $prefilled_requests[$key] ) ) return $prefilled_requests[$key] ;
	if ( isset ( $_REQUEST[$key] ) ) return str_replace ( "\'" , "'" , $_REQUEST[$key] ) ;
	return $default ;
}


function do_post_request($url, $data, $optional_headers = null)
{
 $params = array('http' => array(
			  'method' => 'POST',
			  'content' => http_build_query ( $data ) 
		   ));
 if ($optional_headers !== null) {
	$params['http']['header'] = $optional_headers;
 }
 $ctx = stream_context_create($params);
 $fp = @fopen($url, 'rb', false, $ctx);
 if (!$fp) {
	throw new Exception("Problem with $url"); // , $php_errormsg
 }
 $response = @stream_get_contents($fp);
 if ($response === false) {
	throw new Exception("Problem reading data from $url, $php_errormsg");
 }
 return $response;
}


function verify_tusc () {
	global $tusc_user , $tusc_password , $tusc_url ;
	if ( $tusc_user == '' ) return false ;
	if ( $tusc_password == '' ) return false ;
	$ret = '' ;
	
	$ret = do_post_request ( $tusc_url , 
		array (
			'check' => '1' ,
			'botmode' => '1' ,
			'user' => $tusc_user ,
			'language' => 'commons' ,
			'project' => 'wikimedia' ,
			'password' => $tusc_password ) ) ;

	if ( strpos ( $ret , '1' ) !== false ) return true ;
	return false ;
}

function verify_tusc_detail () {
	global $tusc_user , $tusc_password , $tusc_url ;
	if ( $tusc_user == '' ) return false ;
	if ( $tusc_password == '' ) return false ;

	$ret = do_post_request ( $tusc_url ,
 		 array (
				'action' => 'check_password' ,
				'user' => $tusc_user ,
				'language' => 'commons' ,
				'project' => 'wikimedia' ,
				'password' => $tusc_password ) ) ;

	$ret = json_decode ( $ret ) ;
	return $ret ;
}

function myflush () {
#	for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
#	ob_implicit_flush(1);
#while ( @ob_end_flush() );
	@ob_flush();
     flush();
}

function pre ( $d ) {
	print "<pre>" ;
	print_r ( $d ) ;
	print "</pre>" ;
}

function pluralPl( $count, $forms ) {
	if ( !count($forms) ) { return ''; }
#	$forms = $this->preConvertPlural( $forms, 3 );
	$count = abs( $count );
	if ( $count == 1 )
		return $forms[0];     // singular
	switch ( $count % 10 ) {
		case 2:
		case 3:
		case 4:
			if ( $count / 10 % 10 != 1 )
				return $forms[1]; // plural
		default:
			return $forms[2];   // plural genitive
	}
}

function bytes ( $nr ) {
	global $language ;
	if ( $language == 'pl' ) {
		$f = array ( 'bajt','bajty','bajtów' ) ;
		return "$nr " . pluralPl ( $nr , $f ) ;
	} else {
		return "$nr bytes" ;
	}
}



//________________________________________________________________________________________
// LEGACY STUFF (should be replaced)


/**
 * Returns the raw text of a wikipedia page, trimmed and with html comments removed
 * Returns empty string if something went wrong
 */
function get_wikipedia_article ( $lang , $title , $allow_redirect = true , $project = "wikipedia" , $remove_comments = true) {
	global $is_on_toolserver ;
	if ( $is_on_toolserver ) {
		$u = myurlencode ( $title ) ;
		$url = "http://tools.wikimedia.de/~daniel/WikiSense/WikiProxy.php?wiki={$lang}.{$project}.org&title={$u}&rev=0&go=Fetch" ;
#		$text = get_article_from_database ( $lang , $title ) ;
	} else {
		$url = get_wikipedia_url ( $lang , $title , "raw" , $project ) ;
	}
	$max_attempts = 2 ;
	$cnt = 0 ;
	do {
		$text = @file_get_contents ( $url ) ;
		$cnt++ ;
		if ( $cnt > 1 ) $url = get_wikipedia_url ( $lang , $title , "raw" , $project ) ; # On toolserver, try alternate URL
	} while ( $text === false && $cnt < $max_attempts ) ;
	if ( $text === false ) {
		# Wikipedia did not return anything
		$text = "" ;
	}
	if ( substr ( $text , 0 , 10 ) == '<!DOCTYPE ' ) {
		# Wikipedia did not return raw text
		$text = "" ;
	}

	if ( $remove_comments ) $text = trim ( strip_html_comments ( $text ) ) ;
	else $text = trim ( $text ) ;
	
	# REDIRECT?
	if ( $allow_redirect && strtoupper ( substr ( $text , 0 , 9 ) ) == "#REDIRECT" ) {
		$text = substr ( $text , 9 ) ;
		$text = array_shift ( explode ( "\n" , $text , 2 ) ) ;
		$text = str_replace ( "[[" , "" , $text ) ;
		$text = str_replace ( "]]" , "" , $text ) ;
		$text = ucfirst ( trim ( $text ) ) ;
#		print "Redirected to {$text}<br/>" ;
		return get_wikipedia_article ( $lang , $text , false ) ;
	}
	return $text ;
}

/**
 * Returns the URL for a language/title combination
 * May be called with additional parameter $action
 */
function get_wikipedia_url ( $lang , $title , $action = "" , $project = "wikipedia" ) {
	$lang = trim ( strtolower ( $lang ) ) ;
	$url = "http://" ;
	if ( $lang != 'xxx' ) $url .= "{$lang}." ;
	if ( $lang == "commons" ) $url .= "wikimedia" ;
	else $url .= $project ;
	$url .= ".org/w/index.php?" ;
	if ( $action != "" ) $url .= "action={$action}&" ;
	$url .= "title=" . myurlencode ( $title ) ;
	return $url ;
}

function strip_html_comments ( &$text ) {
	return preg_replace( '?<!--.*-->?msU', '', $text);
}

function get_image_url ( $lang , $image , $project = "wikipedia" ) {
	if ( $lang == 'commons' ) $project = 'wikipedia' ;
	$image = utf8_encode ( $image ) ;
	$image2 = ucfirst ( str_replace ( " " , "_" , $image ) ) ;
	$m = md5( $image2 ) ;
	$m1 = substr ( $m , 0 , 1 ) ;
	$m2 = substr ( $m , 0 , 2 ) ;
	
	$url = "http://upload.wikimedia.org/{$project}/{$lang}/{$m1}/{$m2}/" . myurlencode ( $image ) ;
	return $url ;
}

function get_thumbnail_url ( $lang , $image , $width , $project = "wikipedia" ) {
	$image = $image ; #utf8_encode (  $image ) ;
	$image2 = ucfirst ( str_replace ( " " , "_" , $image ) ) ;
	$m = md5( $image2 ) ;
	$m1 = substr ( $m , 0 , 1 ) ;
	$m2 = substr ( $m , 0 , 2 ) ;
	$project='wikipedia' ;

	$url = "//upload.wikimedia.org/{$project}/{$lang}/thumb/{$m1}/{$m2}/" . myurlencode ( $image ) ;
	$url .= '/' . $width . 'px-' . myurlencode ( $image ) ;
	if ( strtolower ( substr ( $image , -4 , 4 ) ) == '.svg' ) $url .= '.png' ;
	return $url ;
}

function get_edit_timestamp ( $lang , $project , $title ) {
	$t = "http://{$lang}.{$project}.org/w/index.php?title=Special:Export/" . myurlencode ( $title ) ;
	$t = @file_get_contents ( $t ) ;
#	$desc = $t ;
	$t = explode ( '<timestamp>' , $t , 2 ) ;
	$t = explode ( '</timestamp>' , array_pop ( $t ) , 2 ) ;
	$t = array_shift ( $t ) ;
	$t = str_replace ( '-' , '' , $t ) ;
	$t = str_replace ( ':' , '' , $t ) ;
	$t = str_replace ( 'T' , '' , $t ) ;
	$t = str_replace ( 'Z' , '' , $t ) ;
	return $t ;
}

function cGetEditButton ( $text , $title , $lang , $project , $summary , $button_label , $new_window = true , $add = false , $diff = false , $minor = false , $section = -1 , $blank_init = false ) {
	global $toynote ;
	if ( !isset ( $toynote ) ) $toynote = '' ;
	
	$t = get_edit_timestamp ( $lang , $project , $title ) ;
	if ( $blank_init ) $t = '' ;
	$timestamp = $t ;
	
	$text = str_replace ( "'" , htmlentities ( "'" , ENT_QUOTES ) , $text ) ;
	$summary = str_replace ( "'" , htmlentities ( "'" , ENT_QUOTES ) , $summary.$toynote ) ;

	$url = "//{$lang}.{$project}.org/w/index.php?title=" . myurlencode ( $title ) . '&action=edit' ;
	if ( $add ) $url .= '&section=new' ;
	else if ( $section >= 0 ) $url .= "&section=$section" ;
	$ncb = "<form id='upload' method=post enctype='multipart/form-data'" ;
	if ( $new_window ) $ncb .= " target='_blank'" ;
	$ncb .= " action='{$url}' style='display:inline'>" ;
	$ncb .= "<input type='hidden' name='wpTextbox1' value='{$text}'/>" ;
	$ncb .= "<input type='hidden' name='wpSummary' value='{$summary}'/>" ;
	if ( $diff ) $ncb .= "<input type='hidden' name='wpDiff' value='wpDiff' />" ;
	else $ncb .= "<input type='hidden' name='wpPreview' value='wpPreview' />" ;
	
	$starttime = date ( "YmdHis" , time() + (12 * 60 * 60) ) ;
	$ncb .= "<input type='hidden' value='{$starttime}' name='wpStarttime' />" ;
	$ncb .= "<input type='hidden' value='{$t}' name='wpEdittime' />" ;

  if ( $minor ) $ncb .= "<input type='hidden' value='1' name='wpMinoredit' />" ;
	if ( $diff ) $ncb .= "<input type='submit' name='wpDiff' value='$button_label'/>" ;
	else $ncb .= "<input class='btn btn-primary' type='submit' name='wpPreview' value='$button_label'/>" ;
	$ncb .= "</form>" ;
	return $ncb ;
}

function db_get_user_images ( $username , $db ) {
	make_db_safe ( $username ) ;
	$username = str_replace ( '_' , ' ' , $username ) ;

	$ret = array () ;
	$sql = "SELECT  * FROM image WHERE img_user_text=\"{$username}\"" ;
	if(!$result = $db->query($sql)) die('There was an error running the query [' . $db->error . ']');
	while($o = $result->fetch_object()){
		$ret[$o->img_name] = $o ;
	}
	return $ret ;
}

function get_initial_paragraph ( &$text , $language = '' ) {
	global $image_aliases ;
	$t = explode ( "\n" , $text ) ;
	while ( count ( $t ) > 0 ) {
		$s = trim ( array_shift ( $t ) ) ;
		if ( $s == "" ) continue ;
		if ( substr ( $s , 0 , 2 ) == '{{' ) { # Template
			if ( substr ( $s , -2 , 2 ) == '}}' ) continue ; # One-line template
			while ( count ( $t ) > 0 && substr ( $s , -2 , 2 ) != '}}' ) {
				$s = trim ( array_shift ( $t ) ) ;
			}
			continue ;
		}
		if ( substr ( $s , 0 , 2 ) == '--' ) continue ; # <hr>
		if ( substr ( $s , 0 , 1 ) == ':' ) continue ; # Remark
		if ( substr ( $s , 0 , 1 ) == '*' ) continue ; # List
		if ( substr ( $s , 0 , 1 ) == '#' ) continue ; # List
		if ( substr ( $s , 0 , 1 ) == '=' ) continue ; # Heading
		if ( substr ( $s , 0 , 1 ) == '<' ) continue ; # HTML
		if ( substr ( $s , 0 , 1 ) == '!' ) continue ; # Table fragment
		if ( substr ( $s , 0 , 1 ) == '|' ) continue ; # Table fragment
		if ( substr ( $s , 0 , 2 ) == '|-' ) continue ; # Table fragment
		if ( substr ( $s , 0 , 2 ) == '|}' ) continue ; # Table fragment
		if ( substr ( $s , 0 , 2 ) == '{|' ) { # Table
			while ( count ( $t ) > 0 && substr ( $s , 0 , 2 ) != '|}' ) {
				$s = trim ( array_shift ( $t ) ) ;
			}
			continue ;
		}

		if ( substr ( $s , 0 , 2 ) == '}}' ) continue ; # Template end
		
		$sl = strtolower ( $s ) ;
		
		# Check for images
		foreach ( $image_aliases AS $ia )
			{
			if ( false === strpos ( $sl , '[['.$ia ) ) continue ; # Image
			$sl = '' ;
			break ;
			}
		if ( $sl == '' ) continue ;
		
		if ( false !== strpos ( $sl , "|thumb|" ) ) continue ; # Image
		if ( false !== strpos ( $sl , "|frame|" ) ) continue ; # Image
		if ( false !== strpos ( $sl , "|right|" ) ) continue ; # Image
		if ( false !== strpos ( $sl , "|miniatur|" ) ) continue ; # Image
		if ( false !== strpos ( $sl , "|hochkant=" ) ) continue ; # Image

		if ( $language == 'eo' AND false !== strpos ( $sl , ">>" ) ) continue ; # Esperanto navigation line
		
		# Seems to be a real paragraph
		break ;
	}
	if ( count ( $t ) == 0 ) return "" ;
	return $s ;
}


// UPLOAD FILE VIA API

$cookiejar = '' ;
$file_upload_api_result = array() ;

function do_post_request_curl ( $url , $params ) {
	global $cookiejar ;
	$params['format'] = 'php' ;
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_COOKIEJAR, $cookiejar);
	curl_setopt($ch, CURLOPT_COOKIEFILE, $cookiejar);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
	$output = curl_exec($ch);
	$info = curl_getinfo($ch);
	curl_close($ch);
	return unserialize ( $output ) ;
}

function uploadFileViaAPI ( $username , $userpass , $local_file , $new_file_name , $desc , $comment , $testing ) {
	global $cookiejar , $file_upload_api_result ;
	$cookiejar = tempnam("/tmp", "magnus_upload_cookiejar");
	$api = 'http://commons.wikimedia.org/w/api.php' ;
	
	$r1 = do_post_request_curl ( $api , array ( 'action' => 'login' , 'lgname' => $username , 'lgpassword' => $userpass ) ) ;
	$r2 = do_post_request_curl ( $api , array ( 'action' => 'login' , 'lgname' => $username , 'lgpassword' => $userpass , 'lgtoken' => $r1['login']['token'] ) ) ;
	$r3 = do_post_request_curl ( $api , array ( 'action' => 'tokens' , 'type' => 'edit' ) ) ;
	$token = $r3['tokens']['edittoken'] ;
	$file_upload_api_result = do_post_request_curl ( $api , array (
		'action' => 'upload' ,
		'filename' => $new_file_name ,
		'comment' => $comment ,
		'text' => $desc ,
		'token' => $token ,
		'file' => '@' . $local_file
	) ) ;
	if ( isset($testing) AND $testing ) {
		print "<pre>" ; print_r ( $r1 ) ; print "</pre>" ;
		print "<pre>" ; print_r ( $r2 ) ; print "</pre>" ;
		print "<pre>" ; print_r ( $r3 ) ; print "</pre>" ;
		print "<pre>" ; print_r ( $file_upload_api_result ) ; print "</pre>" ;
	}
	unlink ( $cookiejar ) ;
	if ( $file_upload_api_result['upload']['result'] == 'Warning' ) return false ;
	return true ; // TODO 
}



?>