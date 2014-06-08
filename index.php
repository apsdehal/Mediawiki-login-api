<?php

	require("config/bootstrap.php");

	$routes = array(
		'/' => 'HomeController',
		'/hello' => 'HomeController',
		'/wikidata-annotation-tool' => 'HomeController'
	);

	Toro::serve($routes);
