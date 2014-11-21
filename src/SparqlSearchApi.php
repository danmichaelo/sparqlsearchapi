<?php

use EasyRdf\RdfNamespace;
use EasyRdf\Sparql\Client as SparqlClient;

RdfNamespace::set('category', 'http://dbpedia.org/resource/Category:');
RdfNamespace::set('dbpedia', 'http://dbpedia.org/resource/');
RdfNamespace::set('dbo', 'http://dbpedia.org/ontology/');
RdfNamespace::set('dbp', 'http://dbpedia.org/property/');
RdfNamespace::set('skos', 'http://www.w3.org/2004/02/skos/core#');

class SparqlSearchApi {

	public $endpoint = null;
	public $graphs = null;
	public $prefLang = null;
	public $shortLimit = 4;  // Limit for when the 'short' query is to be used;
	public $queryTemplates = null;

	public function __construct($config = null)
	{
		$this->endpoint = $config['endpoint'];
		$this->graphs = $config['graphs'];
		$this->prefLang = $config['prefLang'];
		$this->shortLimit = $config['shortLimit'];
		$this->queryTemplates = $config['queries'];
	}

	function uniq($array)
	{
		$out = array();
		$ex = array();
		foreach ($array as $o) {
			if (!in_array($o['uri'], $ex)) {
				$out[] = $o;
				$ex[] = $o['uri'];
			}
		}
		return $out;
	}

	function validate() {
		return !(is_null($this->endpoint) || is_null($this->graphs) || is_null($this->prefLang) || is_null($this->queryTemplates));
	}

	// Route: /search?query=
	function search($graph, $args) {

		if (!$this->validate()) {
			return array(
				'error' => 'Search API not fully configured.'
			);			
		}

		$query = isset($args['query']) ? $args['query'] : '';

		if (empty($query)) {
			return array(
				'error' => 'Query string parameter "query" must be specified.'
			);
		}

		if (!array_key_exists($graph, $this->graphs)) {
			return array(
				'error' => 'Unknown graph "' . $graph . '".'
			);
		}

		preg_match('/^"(.+)"$/', $query, $matches);
		$exact = false;
		if (count($matches) == 2) {
			$query = $matches[1];
			$exact = true;
		} else {
			$query = strtoupper(substr($query, 0, 1)) . substr($query, 1);
		}

		if ($exact) {
			$sparqlTpl = $this->queryTemplates['exact'];
		} else {
			if (strlen($query) < $this->shortLimit){ 
				$sparqlTpl = $this->queryTemplates['short'];
			} else {
				$sparqlTpl = $this->queryTemplates['standard'];
			}
		}

		$sparqlQuery = str_replace(
			array('{{query}}', '{{graph}}', '{{prefLang}}'), array($query, $this->graphs[$graph], $this->prefLang),
			$sparqlTpl
		);

		$t0 = microtime(true);
		$sparql = new SparqlClient($this->endpoint);
		$result = $sparql->query($sparqlQuery);

		$out = array('results' => array());
		foreach ($result as $row) {
			$r = array(
				'uri' => strval($row->concept),
				'label' => strval($row->prefLabel),
				'match' => strval($row->prefLabel),
			);
			if (isset($row->label)) {
				$r['match'] = strval($row->label);
			}
			$out['results'][] = $r;
		}
		$out['results'] = $this->uniq($out['results']);
		$t1 = microtime(true);

		$out['querytime'] = round(($t1 - $t0) * 1000);
		$out['query'] = $sparqlQuery;

		return $out;
	}

	// Route: /show?uri=
	function show($graph, $args) {

		if (!$this->validate()) {
			return array(
				'error' => 'Search API not fully configured.'
			);			
		}

		$uri = isset($args['uri']) ? $args['uri'] : '';

		if (empty($uri)) {
			return array(
				'error' => 'Query string parameter "uri" must be specified.'
			);
		}

		$sparql = new SparqlClient($this->endpoint);

		$sparqlQuery = str_replace(
			array('{{graph}}', '{{uri}}'), array($this->graphs[$graph], $uri),
			'SELECT * WHERE {' .
			'  GRAPH <{{graph}}> { <{{uri}}> ?p ?o }' .
			'}'
		);

		$t0 = microtime(true);
		$result = $sparql->query($sparqlQuery);
		$skosBase = 'http://www.w3.org/2004/02/skos/core#';
		$madsBase= 'http://www.loc.gov/mads/rdf/v1#';

		$out = array(
			'mappings' => array(),
			'related' => array(),
			'labels' => array(),
			'facet' => '',
		);
		foreach ($result as $row) {
			
			if (strpos($row->o, $madsBase) !== false) {

				$o = substr($row->o, strlen($madsBase));
				$out['facet'] = $o;

			} elseif (strpos($row->p, $skosBase) !== false) {

				$p = substr($row->p, strlen($skosBase));
				$o = strval($row->o);

				if (strpos($p, 'Match') !== false) {
					$m = array(
						'uri' => $o,
						'role' => $p,
					);
					$out['mappings'][] = $m;
				} elseif (strpos($p, 'Label') !== false) {
					$m = array(
						'value' => $o,
						'role' => $p,
						'lang' => $row->o->getLang(),
					);
					$out['labels'][] = $m;
				} elseif (strpos($p, 'type') !== false) {
					$out['facets'][] = $o;
				} elseif (isset($out[$p]) && is_array($out[$p])) {
					$out[$p][] = $o;
				} else {
					$out[$p] = $o;
				}
			}

		}
		$t1 = microtime(true);

		$out['querytime'] = round(($t1 - $t0) * 1000);

		return $out;
	}
	
}