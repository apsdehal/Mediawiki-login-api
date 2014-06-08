<?php
 //Load the autoload class provided by the composer
 require("vendor/autoload.php");

 //Get the config and json decode it to get an array with the required config
 $config  = json_decode(file_get_contents("config/config.json"), true);

 	//Check for the development environment if yes set error display on
	if ($config["environment"] === "development") {
	    error_reporting("-1");
	    ini_set("display_errors", "On");
	 }
 
