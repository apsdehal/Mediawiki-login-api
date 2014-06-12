<?php

class User
{	
	private $info;
	private $tool;

	function __construct( $params, $toolName ) {
		$this->info = json_encode($params);
		$this->tool = $toolName;
		$this->setInfo();
	}

	function setInfo() {
			$t = time()+60*60*24*30 ; // expires in one month
			setcookie( 'mxdf', $this->info, $t, '/'+$this->tool+'/');
	}

	function getInfo(){
		if( isset( $_COOKIE['mxdf'] ) && $_COOKIE['mxdf'] ){
			// var_dump($);
			return $_COOKIE['mxdf'];
		} else {
			return false;
		}
 	}
}