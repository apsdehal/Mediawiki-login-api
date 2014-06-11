<?php

class User
{	
	private var $info;

	function __construct( $params ) {
		$this->info = $params;
	}

	function setInfo() {
			$t = time()+60*60*24*30 ; // expires in one month
			setcookie( 'mxdf', $this->info, $t, '/'+$this->tool+'/');
	}

	function getInfo(){
		if( isset( $_COOKIE['mxdf'] ) && $_COOKIE['mxdf'] ){
			return json_encode($_COOKIE['mxdf'];
		} else {
			return false;
		}
 	}
}