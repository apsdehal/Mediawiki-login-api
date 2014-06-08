<?php

	require("config/bootstrap.php");

	$routes = array(
		'/wikidata-annotation-tool' => 'HomeController'
	);

	Toro::serve($routes);
