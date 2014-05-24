<?php

	require("config/bootstrap.php");

	$routes = array(
		'/' => 'HomeController'
	);

	Toro::serve($routes);
