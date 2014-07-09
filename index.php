<?php
global $botmode;
$botmode = isset ( $_REQUEST['botmode'] ) ;

if ( $botmode ) {
	header ( 'application/json' ) ; // text/plain
} else {
	error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
	ini_set('display_errors', 'On');
}
require("config/bootstrap.php");

$routes = array(
	'/wikidata-annotation-tool' => 'HomeController',
);

Link::all($routes);