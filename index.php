<?php

require_once("app/helpers/headers.php");
require("config/bootstrap.php");

$routes = array(
	'/wikidata-annotation-tool' => 'HomeController',
);

Link::all($routes);