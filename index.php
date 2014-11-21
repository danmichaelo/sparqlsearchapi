<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require('vendor/autoload.php');

$config = yaml_parse_file('config.yaml');

$api = new SparqlSearchApi;
$api->endpoint = $config['endpoint'];
$api->graphs = $config['graphs'];
$api->prefLang = $config['prefLang'];
$api->shortLimit = $config['shortLimit'];
$api->queryTemplates = $config['queries'];

$router = new Router($_SERVER['REQUEST_URI'], $_GET);
$router->route($api);
