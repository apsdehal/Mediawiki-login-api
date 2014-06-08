<?php

require("config/bootstrap.php");

$routes = array(
	'/wikidata-annotation-tool' => 'HomeController',
	'/wikidata-annotation-tool/test' => 'TestController'
);

Toro::serve($routes);