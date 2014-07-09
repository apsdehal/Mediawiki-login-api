<?php

 class HomeController{

 	/**
 	 * @var $mwConfig A variable for holding an instance of MWOAuthClientCOnfig
 	 * @var $cmrToken Token received generated by passing secret and consume_token to
 	 * 					to OAuthClient class
 	 * @var $client Instance of MWOAuthClass that is generated after passing both above vars into it
 	 * 				Used to interact with Wikimedia's OAuth API 	
 	 */	
 	private $mwClient;
 	private $tool;

  	public function __construct(){
 		$this->tool = 'wikidata-annotation-tool';
		global $botmode;
 		$this->botmode = $botmode;
 		$this->miser_mode = false;

 	}
	
 	/**
 	 * Function to handle the get request made to the server where this app reside
 	 * Since the App is built in a RESTful manner thats why this function is here
 	 * Initiaites the client and makes the redirect to and fro the wikimedia OAuth clientS
 	 * Also return the user info, <= todo after testing
 	 */	
 	public function get(){
 		$this->mwClient = new MW_OAuth(	'Wikidata-Annotation', 'wikidata', 'www' );

 		$this->checkRedirect();

		$this->out = array ( 'error' => 'OK' , 'data' => array() );
 		
		switch ( isset( $_GET['action'] ) ? $_GET['action'] : '' ) {
			case 'authorize':
				$this->mwClient->doAuthorizationRedirect();
				exit ( 0 ) ;
				return;
			
			case 'remove_claim' :
				$this->removeClaim() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;

			case 'set_claims':
				$this->setClaims() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;

			case 'merge_items':
				$this->mergeItems() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;

			case 'set_label':
				$this->setLabel() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;

			case 'set_string':
				$this->setString() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;

			case 'get_rights':
				$this->getRights() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;

			case 'logout':
				$this->logout() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;
			
			case 'set_date':
				$this->setDateClaim() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;

			case 'create_item_from_page':
				$this->createItemFromPage() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;

			case 'delete':
				$this->deletePage() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;

			case 'add_row': // Adds a text row to a non-item page
				$this->addRow() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;
				
			case 'append' :
				$this->appendText() ;
				if ( $this->botmode ) bot_out() ;
				else print get_common_footer() ;
				exit ( 0 ) ;
				return ;
		}
 	}

 	public function analyzeGetRequests(){
 		if(isset($_GET['action'])){
 			unset($_GET['action']);
 		}
 		return $_GET;
 	}

 	public function setRedirect(){
 		session_start();
 		$_SESSION['redirect_to'] = $_SERVER['HTTP_REFERER'];
 		session_write_close();
 	}

 	public function checkRedirect(){
 		session_start();
 		if(isset($_SESSION['redirect_to']) && $_SESSION['redirect_to']){
 			$redirect = $_SESSION['redirect_to'];
  			unset($_SESSION['redirect_to']);
  			var_dump($_SESSION);
 			header('Location:' . $redirect);
 		}
 	}

 	public function ensureAuth () {
		$ch = null;

		// First fetch the username
		$res = $this->mwClient->doApiQuery( array(
			'format' => 'json',
			'action' => 'query',
			'meta' => 'userinfo',
		), $ch );

		if ( isset( $res->error->code ) && $res->error->code === 'mwoauth-invalid-authorization' ) {
			// We're not authorized!
			$msg = 'You haven\'t authorized this application yet! Go <a target="_blank" href="' . htmlspecialchars( $_SERVER['SCRIPT_NAME'] ) . '?action=authorize">here</a> to do that, then reload this page.' ;
			if ( $this->botmode ) $this->out['error'] = $msg ;
			else echo $msg . '<hr>';
			return false ;
		}

		if ( !isset( $res->query->userinfo ) ) {
			$msg = 'Bad API response[1]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>' ;
			if ( $this->botmode ) {
				$this->out['error'] = $msg ;
				return false ;
			} else {
				header( "HTTP/1.1 500 Internal Server Error" );
				echo $msg;
				exit(0);
			}
		}
		if ( isset( $res->query->userinfo->anon ) ) {
			$msg = 'Not logged in. (How did that happen?)' ;
			if ( $this->botmode ) {
				$this->out['error'] = $msg ;
				return false ;
			} else {
				header( "HTTP/1.1 500 Internal Server Error" );
				echo $msg;
				exit(0);
			}
		}
		
		return true ;
	}

	public function setLabel () {		
		// https://tools.wmflabs.org/widar/index.php?action=set_label&q=Q1980313&lang=en&label=New+Bach+monument+in+Leipzig&botmode=1

		if ( !$this->ensureAuth() ) return ;
		show_header() ;

		$q = get_request ( 'q' , '' ) ;
		$lang = get_request ( 'lang' , '' ) ;
		$label = get_request ( 'label' , '' ) ;
		
		if ( $q == '' or $lang == '' or $label == '' ) {
			$msg = "Needs q, lang, label" ;
			if ( $this->botmode ) $this->out['error'] = $msg ;
			else print "<pre>$msg</pre>" ;
			return ;
		}

		if ( !$this->mwClient->setLabel ( $q , $label , $lang ) ) {
			$msg = "Problem setting label" ;
			if ( $this->botmode ) $this->out['error'] = $msg ;
			else print "<pre>$msg</pre>" ;
		}
	}

	public function createItemFromPage() {
		if ( !$this->ensureAuth() ) return ;
		show_header() ;

		$site = get_request ( 'site' , '' ) ;
		$page = get_request ( 'page' , '' ) ;
		
		if ( $site == '' or $page == '' ) {
			$msg = "Needs site and page" ;
			if ( $$this->botmode ) $$this->out['error'] = $msg ;
			else print "<pre>$msg</pre>" ;
			return ;
		}
		
		if ( !$$this->mwClient->createItemFromPage ( $site , $page ) ) {
			$msg = "Problem creating item" ;
			if ( $this->botmode ) $this->out['error'] = $msg ;
			else print "<pre>$msg</pre>" ;
		} else {
			$q = $this->mwClient->last_res->entity->id ;
			if ( $this->botmode ) $this->out['q'] = $q ;
			else print "<p>$site page '$page' now has Wikidata item ID <a href='//www.wikidata.org/wiki/$q'>$q</a>.</p>" ;
		}
	}
 	
 	public function removeClaim () {
		
		if ( !$this->ensureAuth() ) return ;
		show_header() ;

		$id = trim ( get_request ( "id" , '' ) ) ;
		$baserev = get_request ( 'baserev' , '' ) ;
		
		if ( $id == '' ) {
			$msg = "Parameters incomplete." ;
			if ( $this->botmode ) $this->out['error'] = $msg ;
			else print "<pre>$msg</pre>" ;
			return ;
		}
		
		if ( !$this->botmode ) {
			print "<div>Processing claim removal...</div>" ;
			print "<ol>" ;
			myflush();
		}

		if ( !$this->botmode ) {
			print "<li>Removing $id ... " ;
			myflush() ;
		}
		
		if ( $this->miser_mode ) {
			if ( !$this->botmode ) {
				print " [delaying edit 5 seconds - temporary measure to not overload Wikidata-Wikipedia sync] " ;
				myflush() ;
			}
			sleep ( 5 ) ;
		}

		if ( isset ( $_REQUEST['test'] ) ) {
			print "$id<br/>$baserev" ;
	//		print "<pre>" ; print_r ( $claim ) ; print "</pre>" ;
		}

		if ( $this->mwClient->removeClaim ( $id , $baserev ) ) {
			if ( !$botmode ) print "done.\n" ;
		} else {
			$msg = "failed!" ;
			if ( $this->botmode ) $this->out['error'] = $msg ;
			else print "$msg\n" ;
		}
		if ( !$this->botmode )  {
			print "</li>" ;
			myflush() ;
		}

		if ( !$this->botmode ) print "</ol>" ;
	}
	public function mergeItems () {
		
		if ( !$this->ensureAuth() ) return ;
		show_header() ;

		$q_from = trim ( get_request ( "from" , '' ) ) ;
		$q_to = trim ( get_request ( "to" , '' ) ) ;
		
		if ( $q_from == '' or $q_to == '' ) {
			$msg = "Parameters incomplete." ;
			if ( $this->botmode ) $this->out['error'] = $msg ;
			else print "<pre>$msg</pre>" ;
			return ;
		}
		
		if ( !$this->botmode ) {
			print "<div>Processing merging...</div>" ;
			print "<ol>" ;
			myflush();
		}
		
		if ( $this->miser_mode ) {
			if ( !$this->botmode ) {
				print " [delaying edit 5 seconds - temporary measure to not overload Wikidata-Wikipedia sync] " ;
				myflush() ;
			}
			sleep ( 5 ) ;
		}

		if ( isset ( $_REQUEST['test'] ) ) {
			print "$q_from<br/>$q_to" ;
	//		print "<pre>" ; print_r ( $claim ) ; print "</pre>" ;
		}

		if ( $this->mwClient->mergeItems($q_from,$q_to) ) {
			if ( !$this->botmode ) print "done.\n" ;
		} else {
			$msg = "failed!" ;
			if ( $this->botmode ) $this->out['error'] = $msg ;
			else print "$msg\n" ;
		}
		if ( !$this->botmode )  {
			print "</li>" ;
			myflush() ;
		}

		if ( !$this->botmode ) print "</ol>" ;
	}

	public function setClaims() {
		
		if ( !$this->ensureAuth() ) return ;
		show_header() ;

		$ids = explode ( "," , get_request ( "ids" , '' ) ) ;
		$prop = get_request ( 'prop' , '' ) ;
		$target = get_request ( 'target' , '' ) ;
		$qualifier_claim = get_request ( 'claim' , '' ) ;
		
		if ( count($ids) == 0 or $prop == '' or $target == '' ) {
			$msg = "Parameters incomplete." ;
			if ( $this->botmode ) $this->out['error'] = $msg ;
			else print "<pre>$msg</pre>" ;
			return ;
		}
		
		if ( !$this->botmode ) {
			print "<div>Batch-processing " . count($ids) . " items...</div>" ;
			print "<ol>" ;
			myflush();
		}

		foreach ( $ids AS $id ) {
			$id = trim ( $id ) ;
			if ( $id == '' && $qualifier_claim == '' ) continue ;
			if ( !$this->botmode ) {
				print "<li><a href='//www.wikidata.org/wiki/$id'>$id</a> : $prop => $target ... " ;
				myflush() ;
			}
			
			if ( $this->miser_mode ) {
				if ( !$this->botmode ) {
					print " [delaying edit 5 seconds - temporary measure to not overload Wikidata-Wikipedia sync] " ;
					myflush() ;
				}
				sleep ( 5 ) ;
			}

			$claim = array (
				"prop" => $prop ,
	//			"q" => $id ,
				"target" => $target ,
				"type" => "item"
			) ;
			
			if ( $qualifier_claim == '' ) $claim['q'] = $id ;
			else $claim['claim'] = $qualifier_claim ;
		
			if ( $this->mwClient->setClaim ( $claim ) ) {
				if ( !$this->botmode ) print "done.\n" ;
				else $this->out['res'] = $oa->last_res ;
			} else {
				$msg = "failed!" ;
				if ( $this->botmode ) $this->out['error'] = $msg ;
				else print "$msg\n" ;
			}
			if ( !$this->botmode )  {
				print "</li>" ;
				myflush() ;
			}
			
		}
		if ( !$this->botmode ) print "</ol>" ;

	}

	public function setString() {
		
		if ( !$this->ensureAuth() ) return ;
		show_header() ;

		$id = trim ( get_request ( "id" , '' ) ) ;
		$prop = get_request ( 'prop' , '' ) ;
		$text = get_request ( 'text' , '' ) ;
		$qualifier_claim = get_request ( 'claim' , '' ) ;
		
		if ( ( $id == '' and $qualifier_claim == '' ) or $prop == '' or $text == '' ) {
			$msg = "Parameters incomplete." ;
			if ( $this->botmode ) $this->out['error'] = $msg ;
			else print "<pre>$msg</pre>" ;
			return ;
		}
		
		if ( !$this->botmode ) {
			print "<div>Processing items $id...</div>" ;
			print "<ol>" ;
			myflush();
		}

		if ( !$this->botmode ) {
			print "<li><a href='//www.wikidata.org/wiki/$id'>$id</a> : $prop => $text ... " ;
			myflush() ;
		}

		$claim = array (
			"prop" => $prop ,
	//		"q" => $id ,
			"text" => $text ,
			"type" => "string"
		) ;

		if ( $qualifier_claim == '' ) $claim['q'] = $id ;
		else $claim['claim'] = $qualifier_claim ;

		if ( $this->mwClient->setClaim ( $claim ) ) {
			if ( !$this->botmode ) print "done.\n" ;
			else $this->out['res'] = $this->mwClient->last_res ;
		} else {
			$msg = "failed!" ;
			if ( $this->botmode ) $this->out['error'] = $msg ;
			else print "$msg\n" ;
		}
		if ( !$this->botmode )  {
			print "</li>" ;
			myflush() ;
		}

		if ( !$this->botmode ) print "</ol>" ;

	}

	public function setDateClaim() {
		
		if ( !$this->ensureAuth() ) return ;
		show_header() ;

		$id = trim ( get_request ( "id" , '' ) ) ;
		$prop = get_request ( 'prop' , '' ) ;
		$date = get_request ( 'date' , '' ) ;
		$prec = get_request ( 'prec' , '' ) ;
		
		if ( $id == '' or $prop == '' or $date == '' or $prec == '' ) {
			$msg = "Parameters incomplete." ;
			else print "<pre>$msg</pre>" ;
			return ;
		}
		
		if ( !$this->botmode ) {
			print "<div>Processing items $id...</div>" ;
			print "<ol>" ;
			myflush();
		}

		if ( !$this->botmode ) {
			print "<li><a href='//www.wikidata.org/wiki/$id'>$id</a> : $prop => $text ... " ;
			myflush() ;
		}

		$claim = array (
			"prop" => $prop ,
			"q" => $id ,
			"date" => $date ,
			"prec" => $prec ,
			"type" => "date"
		) ;
		
	//	print_r ( $claim ) ;

		if ( $this->mwClient->setClaim ( $claim ) ) {
			if ( !$this->botmode ) print "done.\n" ;
		} else {
			$msg = "failed!" ;
			else print "$msg\n" ;
		}
		if ( !$this->botmode )  {
			print "</li>" ;
			myflush() ;
		}

		if ( !$this->botmode ) print "</ol>" ;

	}
	
	public function addRow () { // ASSUMING BOTMODE
		
		if ( !$this->ensureAuth() ) return ;
		show_header() ;

		$page = trim ( get_request ( "page" , '' ) ) ;
		$row = trim ( get_request ( "row" , '' ) ) ;
		$text = file_get_contents ( 'http://www.wikidata.org/w/index.php?action=raw&title='.urlencode($page) ) ;
		$text = trim ( $text ) . "\n" . $row ;
		
		if ( ! $this->mwClient->setPageText ( $page , $text ) ) {
		}
		
	}
 }