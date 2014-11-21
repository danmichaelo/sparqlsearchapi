<?php 

class Router
{

	protected $graph;
	protected $endpoint;
	protected $args;
	
	function __construct($url, $args)
	{
		// Strip off querystring
		$url = explode('?', $url)[0];

		$url = trim($url, '/');

		// We don't care if it's /graph/api/search or /api/graph/search
		$parts = array_values(array_filter(explode('/', $url), function($el) {
			return ($el !== 'api');
		}));

		if (count($parts) < 2) {
			return;  // Invalid URL
		}

		$this->graph = preg_replace('/[^a-z]/', '', $parts[0]);
		$this->endpoint = preg_replace('/[^a-z]/', '', $parts[1]);
		$this->args = $args;
	}

	public function route(SparqlSearchApi $api = null)
	{
		if (is_null($api)) {
			$api = new SparqlSearchApi;
		}

		if (!isset($this->graph) || !isset($this->endpoint)) {
			$this->fail('Invalid URL');
		}

		$valid_endpoints = array('search', 'show');
		if (!in_array($this->endpoint, $valid_endpoints)) {
			$eps = array_map(function($k) {
				return "/$k";
			}, $valid_endpoints);
			$this->fail('Invalid endpoint. Valid endpoints are: ' . implode(', ', $eps) );
		}

		$this->returnJson($api->{$this->endpoint}($this->graph, $this->args));
	}

	protected function returnJson($out)
	{
		header('Access-Control-Allow-Origin: *');
		header('Content-type: application/json; charset=utf-8');
		if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
			echo json_encode($out, JSON_PRETTY_PRINT);
		} else {
			echo json_encode($out);
		}
		exit;
	}

	protected function fail($msg) {
		$this->returnJson(array('error' => $msg));
	}

}
