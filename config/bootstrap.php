<?php
 require("vendor/autoload.php");
 
 global $config = json_decode(file_get_contents("config/config.json", true);
 
 if ($config["environment"] === "development") {
    error_reporting("-1");
    ini_set("display_errors", "On");
 }

