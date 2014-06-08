<?php

require("config/bootstrap.php");

$routes = array(
	'/wikidata-annotation-tool' => 'HomeController',
);

Link::all($routes);