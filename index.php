<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require('vendor/autoload.php');
use EasyRdf\RdfNamespace;
use EasyRdf\Sparql\Client as SparqlClient;

RdfNamespace::set('category', 'http://dbpedia.org/resource/Category:');
RdfNamespace::set('dbpedia', 'http://dbpedia.org/resource/');
RdfNamespace::set('dbo', 'http://dbpedia.org/ontology/');
RdfNamespace::set('dbp', 'http://dbpedia.org/property/');
RdfNamespace::set('skos', 'http://www.w3.org/2004/02/skos/core#');


function returnJson($out) {
	header('Access-Control-Allow-Origin: *');
	header('Content-type: application/json; charset=utf-8');
	if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
	    echo json_encode($out, JSON_PRETTY_PRINT);
	} else {
	    echo json_encode($out);
	}
	exit;
}

function fail($msg) {
    // TODO: Add some headers
    print $msg;
    exit;
}

function route($request) {

    $request = explode('?', $request)[0];
    $base = '/realfagstermer/api/';
    $rest = explode('/', substr($request, strlen($base)));
    $endpoint = $rest[0];
    $args = array_slice($rest, 1);

    $valid_endpoints = array('search', 'show');
    if (!in_array($endpoint, $valid_endpoints)) {
        $urls = array_map(function($k) use ($base) {
            return "<a href=\"$base/$k\">$k</a>";
        }, $valid_endpoints);
        fail('Invalid endpoint. Valid endpoints are: ' . implode(', ', $urls) );
    }

    switch ($endpoint) {

        case 'search':
            returnJson(search());
            break;

        case 'show':
            returnJson(show());
            break;

    }

}

route($_SERVER['REQUEST_URI']);

// Route: /search?query=
function search() {

    $query = isset($_GET['query']) ? $_GET['query'] : '';
    if (empty($query)) {
        returnJson(array('error' => 'missing:query', 'errorDetails' => 'Query string parameter "query" must be specified.'));
    }
    preg_match('/^"(.+)"$/', $query, $matches);
    $exact = false;
    if (count($matches) == 2) {
        $query = $matches[1];
        $exact = true;
    } else {
        $query = strtoupper(substr($query, 0, 1)) . substr($query, 1);
    }

    $sparql = new SparqlClient('http://data.ub.uio.no/sparql');

    if ($exact) {
        
$sparqlTpl = <<<'EOD'
SELECT DISTINCT ?concept ?prefLabel WHERE {
  GRAPH <{{graph}}>
  {
    ?concept skos:prefLabel "{{query}}"@nb,
                ?prefLabel .
  }
  FILTER(LANG(?prefLabel) =  "nb")
}
EOD;
    
} else {
    if (strlen($query) < 4){ // grense for bif:contains
        $sparqlTpl = <<<'EOD'
SELECT DISTINCT ?concept ?prefLabel ?label WHERE
{
  GRAPH <{{graph}}>
  {
    ?concept skos:prefLabel ?prefLabel
    {
      SELECT DISTINCT ?concept ?label WHERE
      {   
         ?concept (skos:prefLabel | skos:altLabel) "{{query}}",
                  ?label .
      }
    }
  }
  FILTER(lang(?prefLabel) = "nb")
}
EOD;
    } else {

    // Note that bif:contains is a Virtuoso SPARQL extension
    // http://docs.openlinksw.com/virtuoso/sparqlextensions.html 
$sparqlTpl = <<<'EOT'
SELECT DISTINCT ?concept ?prefLabel ?label WHERE
{
  GRAPH <{{graph}}>
  {
      ?concept skos:prefLabel ?prefLabel
      {
        SELECT DISTINCT ?concept ?label WHERE
        {
           {
             ?concept skos:prefLabel ?label .
             ?label bif:contains "'{{query}}*'" .
           } UNION {
             ?concept skos:altLabel ?label .
             ?label bif:contains "'{{query}}*'" .
           }
        }
        LIMIT 100
    }
  }
  FILTER(lang(?prefLabel) = "nb")
}
EOT;
}
//  FILTER (STRSTARTS(STR(?prefLabel), "{{query}}") || STRSTARTS(STR(?altLabel), "{{query}}"))
// '  FILTER (REGEX(STR(?prefLabel), "{{query}}", "i") || REGEX(STR(?altLabel), "{{query}}", "i"))'.
}

    $graph = 'http://data.ub.uio.no/rt';

    $sparqlQuery = str_replace(
        array('{{query}}', '{{graph}}'), array($query, $graph),
        $sparqlTpl
    );

    $t0 = microtime(true);
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
    $out['results'] = uniq($out['results']);
    $t1 = microtime(true);

    $out['querytime'] = round(($t1 - $t0) * 1000);
    $out['query'] = $sparqlQuery;

    return $out;
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



// Route: /show?uri=
function show() {

    $uri = isset($_GET['uri']) ? $_GEy['uri'] : '';
    if (empty($uri)) {
        returnJson(array('error' => 'missing:uri', 'errorDetails' => 'Query string parameter "uri" must be specified.'));
    }

    $sparql = new SparqlClient('http://data.ub.uio.no/sparql');

    $sparqlQuery = str_replace(
        '{{uri}}', $uri,
        'SELECT * WHERE {' .
        '  { <{{uri}}> ?p ?o }' .
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

