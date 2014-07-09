<?php
require_once('php/common.php');
require_once("app/helpers/headers.php");
require("config/bootstrap.php");

$routes = array(
	'/wikidata-annotation-tool' => 'HomeController',
);

Toro::serve($routes);