<?PHP

class MW_OAuth {

	var $use_cookies = true ;
	var $tool ;
	var $debugging = true ;
	var $language , $project ;
	var $ini_file , $params ;
	var $mwOAuthUrl = 'https://www.mediawiki.org/w/index.php?title=Special:OAuth';
	var $mwOAuthIW = 'mw'; // Set this to the interwiki prefix for the OAuth central wiki.
	var $config = array();
	
	function MW_OAuth ( $t , $l , $p ) {
		global $config;
		$this->tool = $t ;
		$this->language = $l ;
		$this->project = $p ;
		// $this->ini_file = "/data/project/$t/oauth.ini" ;
		
		if ( $l == 'wikidata' ) $this->apiUrl = 'http://www.wikidata.org/w/api.php' ;
		else if ( $l == 'commons' ) $this->apiUrl = 'https://commons.wikimedia.org/w/api.php' ;
		else $this->apiUrl = "https://$l.$p.org/w/api.php" ;

		$this->config = $config;
		$this->loadConfig() ;
		$this->setupSession() ;
		$this->loadToken() ;

		if ( isset( $_GET['oauth_verifier'] ) && $_GET['oauth_verifier'] ) {
			$this->fetchAccessToken();
		}

	}
	
	function setupSession() {
		// Setup the session cookie
		session_name( $this->tool );
		$params = session_get_cookie_params();
		session_set_cookie_params(
			$params['lifetime'],
			dirname( $_SERVER['SCRIPT_NAME'] )
		);
	}
	
	function loadConfig () {
		$this->gUserAgent = $this->config['agent'];
		$this->gConsumerKey = $this->config['consumer_token'];
		$this->gConsumerSecret = $this->config['secret_token'];
	}
	
	// Load the user token (request or access) from the session
	function loadToken() {
		$this->gTokenKey = '';
		$this->gTokenSecret = '';
		session_start();
		if ( isset( $_SESSION['tokenKey'] ) ) {
			$this->gTokenKey = $_SESSION['tokenKey'];
			$this->gTokenSecret = $_SESSION['tokenSecret'];
		} else if ( $this->use_cookies and isset( $_COOKIE['tokenKey'] ) ) {
			$this->gTokenKey = $_COOKIE['tokenKey'];
			$this->gTokenSecret = $_COOKIE['tokenSecret'];
		}
		session_write_close();
	}


	/**
	 * Handle a callback to fetch the access token
	 * @return void
	 */
	function fetchAccessToken() {
		$url = $this->mwOAuthUrl . '/token';
		$url .= strpos( $url, '?' ) ? '&' : '?';
		$url .= http_build_query( array(
			'format' => 'json',
			'oauth_verifier' => $_GET['oauth_verifier'],

			// OAuth information
			'oauth_consumer_key' => $this->gConsumerKey,
			'oauth_token' => $this->gTokenKey,
			'oauth_version' => '1.0',
			'oauth_nonce' => md5( microtime() . mt_rand() ),
			'oauth_timestamp' => time(),

			// We're using secret key signatures here.
			'oauth_signature_method' => 'HMAC-SHA1',
		) );
		$this->signature = $this->sign_request( 'GET', $url );
		$url .= "&oauth_signature=" . urlencode( $this->signature );
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_USERAGENT, $this->gUserAgent );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		$data = curl_exec( $ch );
		if ( !$data ) {
//			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
			exit(0);
		}
		curl_close( $ch );
		$token = json_decode( $data );
		if ( is_object( $token ) && isset( $token->error ) ) {
//			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Error retrieving token: ' . htmlspecialchars( $token->error );
			exit(0);
		}
		if ( !is_object( $token ) || !isset( $token->key ) || !isset( $token->secret ) ) {
//			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Invalid response from token request';
			exit(0);
		}

		// Save the access token
		session_start();
		$_SESSION['tokenKey'] = $this->gTokenKey = $token->key;
		$_SESSION['tokenSecret'] = $this->gTokenSecret = $token->secret;
		$_SESSION['authDone'] = true;
		if ( $this->use_cookies ) {
			$t = time()+60*60*24*30 ; // expires in one month
			setcookie ( 'tokenKey' , $_SESSION['tokenKey'] , $t , '/'+$this->tool+'/' ) ;
			setcookie ( 'tokenSecret' , $_SESSION['tokenSecret'] , $t , '/'+$this->tool+'/' ) ;
		}
		session_write_close();
	}


	/**
	 * Utility function to sign a request
	 *
	 * Note this doesn't properly handle the case where a parameter is set both in 
	 * the query string in $url and in $params, or non-scalar values in $params.
	 *
	 * @param string $method Generally "GET" or "POST"
	 * @param string $url URL string
	 * @param array $params Extra parameters for the Authorization header or post 
	 * 	data (if application/x-www-form-urlencoded).
	 *Ã‚ @return string Signature
	 */
	function sign_request( $method, $url, $params = array() ) {
//		global $gConsumerSecret, $gTokenSecret;

		$parts = parse_url( $url );

		// We need to normalize the endpoint URL
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
		$host = isset( $parts['host'] ) ? $parts['host'] : '';
		$port = isset( $parts['port'] ) ? $parts['port'] : ( $scheme == 'https' ? '443' : '80' );
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( ( $scheme == 'https' && $port != '443' ) ||
			( $scheme == 'http' && $port != '80' ) 
		) {
			// Only include the port if it's not the default
			$host = "$host:$port";
		}

		// Also the parameters
		$pairs = array();
		parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
		$query += $params;
		unset( $query['oauth_signature'] );
		if ( $query ) {
			$query = array_combine(
				// rawurlencode follows RFC 3986 since PHP 5.3
				array_map( 'rawurlencode', array_keys( $query ) ),
				array_map( 'rawurlencode', array_values( $query ) )
			);
			ksort( $query, SORT_STRING );
			foreach ( $query as $k => $v ) {
				$pairs[] = "$k=$v";
			}
		}

		$toSign = rawurlencode( strtoupper( $method ) ) . '&' .
			rawurlencode( "$scheme://$host$path" ) . '&' .
			rawurlencode( join( '&', $pairs ) );
		$key = rawurlencode( $this->gConsumerSecret ) . '&' . rawurlencode( $this->gTokenSecret );
		return base64_encode( hash_hmac( 'sha1', $toSign, $key, true ) );
	}

	/**
	 * Request authorization
	 * @return void
	 */
	function doAuthorizationRedirect() {
		// First, we need to fetch a request token.
		// The request is signed with an empty token secret and no token key.
		$this->gTokenSecret = '';
		$url = $this->mwOAuthUrl . '/initiate';
		$url .= strpos( $url, '?' ) ? '&' : '?';
		$url .= http_build_query( array(
			'format' => 'json',
		
			// OAuth information
			'oauth_callback' => 'oob', // Must be "oob" for MWOAuth
			'oauth_consumer_key' => $this->gConsumerKey,
			'oauth_version' => '1.0',
			'oauth_nonce' => md5( microtime() . mt_rand() ),
			'oauth_timestamp' => time(),

			// We're using secret key signatures here.
			'oauth_signature_method' => 'HMAC-SHA1',
		) );
		$signature = $this->sign_request( 'GET', $url );
		$url .= "&oauth_signature=" . urlencode( $signature );
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_USERAGENT, $this->gUserAgent );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		$data = curl_exec( $ch );
		if ( !$data ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
			exit(0);
		}
		curl_close( $ch );
		$token = json_decode( $data );
		if ( is_object( $token ) && isset( $token->error ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Error retrieving token: ' . htmlspecialchars( $token->error );
			exit(0);
		}
		if ( !is_object( $token ) || !isset( $token->key ) || !isset( $token->secret ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Invalid response from token request';
			exit(0);
		}

		// Now we have the request token, we need to save it for later.
		session_start();
		$_SESSION['tokenKey'] = $token->key;
		$_SESSION['tokenSecret'] = $token->secret;
		if ( $this->use_cookies ) {
			$t = time()+60*60*24*30 ; // expires in one month
			setcookie ( 'tokenKey' , $_SESSION['tokenKey'] , $t , '/'+$this->tool+'/' ) ;
			setcookie ( 'tokenSecret' , $_SESSION['tokenSecret'] , $t , '/'+$this->tool+'/' ) ;
		}
		session_write_close();

		// Then we send the user off to authorize
		$url = $this->mwOAuthUrl . '/authorize';
		$url .= strpos( $url, '?' ) ? '&' : '?';
		$url .= http_build_query( array(
			'oauth_token' => $token->key,
			'oauth_consumer_key' => $this->gConsumerKey,
		) );
		header( "Location: $url" );
		echo 'Please see <a href="' . htmlspecialchars( $url ) . '">' . htmlspecialchars( $url ) . '</a>';
	}



	/**
	 * Send an API query with OAuth authorization
	 *
	 * @param array $post Post data
	 * @param object $ch Curl handle
	 * @return array API results
	 */
	function doApiQuery( $post, &$ch = null , $mode = '' ) {
		$headerArr = array(
			// OAuth information
			'oauth_consumer_key' => $this->gConsumerKey,
			'oauth_token' => $this->gTokenKey,
			'oauth_version' => '1.0',
			'oauth_nonce' => md5( microtime() . mt_rand() ),
			'oauth_timestamp' => time(),

			// We're using secret key signatures here.
			'oauth_signature_method' => 'HMAC-SHA1',
		);
		
		$to_sign = '' ;
		if ( $mode == 'upload' ) {
			$to_sign = $headerArr ;
		} else {
			$to_sign = $post + $headerArr ;
		}
		$signature = $this->sign_request( 'POST', $this->apiUrl, $to_sign );
		$headerArr['oauth_signature'] = $signature;

		$header = array();
		foreach ( $headerArr as $k => $v ) {
			$header[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
		}
		$header = 'Authorization: OAuth ' . join( ', ', $header );


		if ( !$ch ) {
			$ch = curl_init();
			
		}
		
		$url = $this->apiUrl ;
//		if ( $mode == 'userinfo' ) $url = $this->mwOAuthUrl ;
		
		$post_fields = '' ;
		if ( $mode == 'upload' ) {
			$post_fields = $post ;
		} else {
			$post_fields = http_build_query( $post ) ;
		}
		
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_fields );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( $header ) );
		//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_USERAGENT, $this->gUserAgent );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

		$data = curl_exec( $ch );

		if ( isset ( $_REQUEST['test'] ) ) {
			print "<hr/><h3>API query</h3>" ;
			print "Header:<pre>" ; print_r ( $header ) ; print "</pre>" ;
			print "Payload:<pre>" ; print_r ( $post ) ; print "</pre>" ;
			print "Result:<pre>" ; print_r ( $data ) ; print "</pre>" ;
			print "<hr/>" ;
		}


		if ( !$data ) {
		return ;
//			if ( $mode != 'userinfo' ) header( "HTTP/1.1 500 Internal Server Error" );
			$info = curl_getinfo($ch);
			print "<pre>" ; print_r ( $info ) ; print "</pre>" ;
			echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
			exit(0);
		}
		$ret = json_decode( $data );
		if ( $ret == null ) {
		return ;
//			if ( $mode != 'userinfo' ) header( "HTTP/1.1 500 Internal Server Error" );
			print "<h1>API trouble!</h1>" ;
//			print "<pre>" ; print_r ($header ) ; print "</pre>" ;
			print "<pre>" ; print_r ($post ) ; print "</pre>" ;
			print "<pre>" ; print_r ($data ) ; print "</pre>" ;
			print "<pre>" ; print var_export ( $ch , 1 ) ; print "</pre>" ;
			exit(0);
		}
		return $ret ;
	}




	// Wikidata-specific methods
	
/*
Claims are used like this:
	$claim = array (
		"prop" => 'P31' ,
		"q" => 'Q4115189' ,
		"target" => 'Q12345' ,
		"type" => "item"
	) ;
*/
	
	function doesClaimExist ( $claim ) {
		$q = 'Q' . str_replace('Q','',$claim['q'].'') ;
		$p = 'P' . str_replace('P','',$claim['prop'].'') ;
		$url = 'https://www.wikidata.org/w/api.php?action=wbgetentities&format=json&props=claims&ids=' . $q ;
		$j = json_decode ( file_get_contents ( $url ) ) ;
	//	print "<pre>" ; print_r ( $j ) ; print "</pre>" ;

		if ( !isset ( $j->entities ) ) return false ;
		if ( !isset ( $j->entities->$q ) ) return false ;
		if ( !isset ( $j->entities->$q->claims ) ) return false ;
		if ( !isset ( $j->entities->$q->claims->$p ) ) return false ;

		$nid = 'numeric-id' ;
		$does_exist = false ;
		$cp = $j->entities->$q->claims->$p ; // Claims for this property
		foreach ( $cp AS $k => $v ) {
	//		print "<pre>" ; print_r ( $v ) ; print "</pre>" ;
			if ( $claim['type'] == 'item' ) {
				if ( !isset($v->mainsnak) ) continue ;
				if ( !isset($v->mainsnak->datavalue) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value) ) continue ;
				if ( $v->mainsnak->datavalue->value->$nid != str_replace('Q','',$claim['target'].'') ) continue ;
				$does_exist = true ;
			}
		}
	
		return $does_exist ;
	}


	function getConsumerRights () {

		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query',
			'meta' => 'userinfo',
			'uiprop' => 'blockinfo|groups|rights'
		), $ch );
		
		return $res ;
	}

	
	function setLabel ( $q , $text , $language ) {

		// Fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'tokens',
			'type' => 'edit',
		), $ch );
		if ( !isset( $res->tokens->edittoken ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Bad API response [setLabel]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->tokens->edittoken;

		// Now do that!
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'wbsetlabel',
			'id' => $q,
			'language' => $language ,
			'value' => $text ,
			'token' => $token,
		), $ch );
		
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}

		return true ;
	}
	
	
	function setPageText ( $page , $text ) {

		// Fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'tokens',
			'type' => 'edit',
		), $ch );
		if ( !isset( $res->tokens->edittoken ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Bad API response[setPageText]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->tokens->edittoken;
		
		// Now do that!
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'edit',
			'title' => $page,
			'text' => $text ,
			'minor' => '' ,
			'token' => $token,
		), $ch );
		
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}

		return true ;
	}
	
	function createItemFromPage ( $site , $page ) {
		$page = str_replace ( ' ' , '_' , $page ) ;
	
		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'tokens',
			'type' => 'edit',
		), $ch );
		if ( !isset( $res->tokens->edittoken ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Bad API response[setClaim]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->tokens->edittoken;


		$data = array ( 
			'sitelinks' => array ( array ( "site" => $site ,"title" => $page ) )
		) ;
		$m = array () ;
		if ( preg_match ( '/^(.+)wiki$/' , $site , $m ) ) {
			$nice_title = preg_replace ( '/\s+\(.+$/' , '' , str_replace ( '_' , ' ' , $page ) ) ;
			$data['labels'] = array ( array ( 'language' => $m[1] , 'value' => $nice_title ) ) ;
		}
//		print "<pre>" ; print_r ( json_encode ( $data ) ) ; print " </pre>" ; return true ;

		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'wbeditentity',
			'new' => 'item' ,
			'data' => json_encode ( $data ) ,
			'token' => $token,
		), $ch );
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}

		$this->last_res = $res ;
		if ( isset ( $res->error ) ) return false ;

		return true ;
	}


	function setClaim ( $claim ) {
		if ( $this->doesClaimExist($claim) ) return true ;

		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'tokens',
			'type' => 'edit',
		), $ch );
		if ( !isset( $res->tokens->edittoken ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Bad API response[setClaim]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->tokens->edittoken;
	
	
	
		// Now do that!
		$value = "" ;
		if ( $claim['type'] == 'item' ) {
			$value = '{"entity-type":"item","numeric-id":'.str_replace('Q','',$claim['target'].'').'}' ;
		} else if ( $claim['type'] == 'string' ) {
			$value = json_encode($claim['text']) ;
//			$value = '{"type":"string","value":'.json_encode($claim['text']).'}' ;
		}
	

		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'wbcreateclaim',
			'entity' => $claim['q'],
			'snaktype' => 'value' ,
			'property' => 'P' . str_replace('P','',$claim['prop'].'') ,
			'value' => $value ,
			'token' => $token,
		), $ch );
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $claim ) ; print "</pre>" ;
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}
		
/*
		if ( $claim['type'] == 'string' ) {
			echo 'API edit result: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			echo '<hr>';
		}
*/		
		return true ;
	}

	
	function doUploadFromURL ( $url , $new_file_name , $desc , $comment ) {
	
		if ( $new_file_name == '' ) {
			$a = explode ( '/' , $url ) ;
			$new_file_name = array_pop ( $a ) ;
		}
		$new_file_name = ucfirst ( str_replace ( ' ' , '_' , $new_file_name ) ) ;
		
		// Download file
		$basedir = '/data/project/magnustools/tmp' ;
		$tmpfile = tempnam ( $basedir , 'doUploadFromURL' ) ;
		copy($url, $tmpfile) ;


		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'tokens',
			'type' => 'edit',
		), $ch );
		if ( !isset( $res->tokens->edittoken ) ) {
			$this->error = 'Bad API response[uploadFromURL]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>' ;
			unlink ( $tmpfile ) ;
			return false ;
		}
		$token = $res->tokens->edittoken;

		$params = array(
			'format' => 'json',
			'action' => 'upload' ,
			'filename' => $new_file_name ,
			'comment' => $comment ,
			'text' => $desc ,
			'token' => $token ,
			'file' => '@' . $tmpfile
		) ;
		
		$res = $this->doApiQuery( $params , $ch , 'upload' );

		unlink ( $tmpfile ) ;
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $params ) ; print "</pre>" ;
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}


		$this->last_res = $res ;
		if ( $res->upload->result != 'Success' ) {
			$this->error = $res->upload->result ;
			return false ;
		}

		return true ;
	}



	
	function isAuthOK () {

		$ch = null;

		// First fetch the username
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query',
			'meta' => 'userinfo',
		), $ch , 'userinfo' );

		if ( isset( $res->error->code ) && $res->error->code === 'mwoauth-0invalid-authorization' ) {
			// We're not authorized!
			$this->error = 'You haven\'t authorized this application yet! Go <a target="_blank" href="' . htmlspecialchars( $_SERVER['SCRIPT_NAME'] ) . '?action=authorize">here</a> to do that, then reload this page.' ;
			return false ;
		}

		if ( !isset( $res->query->userinfo ) ) {
/*			if ( isset($_REQUEST['test']) ) {
				$info = curl_getinfo($ch);
				print "<pre>" ;
				print_r ( $info ) ;
				print "</pre>" ;
			}*/
			$this->error = 'Not authorized (bad API response[isAuthOK]: ' . htmlspecialchars( json_encode( $res) ) . ')' ;
			return false ;
		}
		if ( isset( $res->query->userinfo->anon ) ) {
			$this->error = 'Not logged in. (How did that happen?)' ;
			return false ;
		}
		

		return true ;
	}



}


///////////////


/*
// Take any requested action
switch ( isset( $_GET['action'] ) ? $_GET['action'] : '' ) {
	case 'download':
		header( 'Content-Type: text/plain' );
		readfile( __FILE__ );
		return;

	case 'authorize':
		doAuthorizationRedirect();
		return;

	case 'edit':
		doEdit();
		break;
}
*/

// ******************** CODE ********************






/*
<!DOCTYPE html>
<html lang="en" dir="ltr">
 <head>
  <meta charset="UTF-8" />
  <title>OAuth Hello World!</title>
 </head>
 <body>
<p>This is a very simple "<a href="//en.wikipedia.org/wiki/Hello_world_program">Hello world</a>" program to show how to use OAuth. If you so desire, you may <a href="<?php echo htmlspecialchars( $_SERVER['SCRIPT_NAME'] );?>?action=download">download this file</a>. For a more end-user friendly version, look at <a href="enduser.php">enduser.php</a>.</p>

<h2>Overview</h2>
<p>OAuth is a method for your application to act on behalf of a user on a website, without having to know the user's username and password. First your application is regisetered with the website, then you send the user to a special page on the website where they give your application permission, and then you provide special HTTP headers when accessing the website.</p>

<h2>Creating your consumer</h2>
<p>To be able to use OAuth in your application, you first need to register it as a consumer. To do this, you visit Special:OAuthConsumerRegistration on the OAuth central wiki. For WMF wikis, this is currently <a href="https://www.mediawiki.org/wiki/Special:OAuthConsumerRegistration/propose">mediawiki.org</a>, but will likely change to Meta once OAuth is fully deployed.</p>
<p>On this page, you will fill out information required by your application. Most of the fields are straightforward. Of the rest:</p>
<ul>
 <li>OAuth "callback" URL: After the user authorizes the application, their browser will be sent to this URL. It will be given two parameters, <code>oauth_verifier</code> and <code>oauth_token</code>, which your application will need in order to complete the authorization process.</li>
 <li>Applicable wiki: If your app is only for use in one wiki, specify the wiki id here (this may be retrieved from the API with <code>action=query&amp;meta=siteinfo</code>). If your app is for use on all wikis, specify "*" (without the quotes).</li>
 <li>Applicable grants: Check the checkbox for the grants that provide the rights your application needs. Note that "Basic rights" is almost certainly required, and that even if your application specifies advanced rights such as "Delete pages" your application will still not be able to delete pages on behalf of users who don't already have the delete right.</li>
 <li>Usage restrictions (JSON): This can be used to limit usage of your application, e.g. to certain IP addresses. The default value should be fine.</li>
 <li>Public RSA key: OAuth requires that requests be signed; this can be done by using a shared secret, or by using <a href="https://en.wikipedia.org/wiki/Public-key_cryptography">public-key cryptography</a>. If you want to use the latter, provide a public key here.</li>
</ul>
<p>After submitting your registration request, you will be returned a "consumer token" and a "secret token". In this Hello world program, these go in your ini file as consumerKey and consumerSecret. Note you can later update the Usage restrictions and Public RSA key, and can reset the secret token.</p>
<p>Your application must then be approved by someone with the "mwoauthmanageconsumer" user right.</p>

<h2>Authorizing a user</h2>
<p>When a new user wishes to use your application, they must first authorize it. You do this by making a call to Special:OAuth/initiate to get a request token, then send the user to Special:OAuth/authorize. If the user authorizes your app, the user will be redirected back to your callback URL with the <code>oauth_verifier</code> parameter set; you then call Special:OAuth/token to fetch the access token.</p>

<h2>Deauthorizing a user</h2>
<p>A user may revoke the authorization for the application by visiting Special:OAuthManageMyGrants on the OAuth central wiki.</p>

<h2>Try it out!</h2>
<ul>
 <li><a href="<?php echo htmlspecialchars( $_SERVER['SCRIPT_NAME'] );?>?action=authorize">Authorize this application</a></li>
 <li><a href="<?php echo htmlspecialchars( $_SERVER['SCRIPT_NAME'] );?>?action=edit">Post to your talk page</a></li>
 <li><a href="<?php echo htmlspecialchars( $mytalkUrl );?>">Visit your talk page</a></li>
</ul>

</body>
</html>

*/

?>