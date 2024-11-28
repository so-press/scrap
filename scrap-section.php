<?php

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$verbose = in_array('--verbose', $argv);
$refresh = in_array('--refresh', $argv);

$section = null;
$page = 0;

// Parse arguments for --start parameter
foreach ($argv as $arg) {
    if (strstr($arg, '--section=')) {
        $section = explode('=',$arg)[1];
    }
    if (strstr($arg, '--page=')) {
        $page = explode('=',$arg)[1];
    }
}


if (!$section) die('section manquante');

$base = 'https://www.sofoot.com/' . $section;


while(true) {
    if($page) {
        $url =$base.'/page/'.$page;
    } else {
        $url =$base;
    }
    echo $url."\n";
    callUrl($url);
    $page++;
}

function callUrl($url)
{
    $startTime = microtime(true); // Début du chronométrage

    // Contexte pour récupérer les en-têtes
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'follow_location' => true,
            'timeout' => 10
        ]
    ]);

    // Effectuer la requête
    $content = @file_get_contents($url, false, $context);
    $contentSize = $content ? strlen($content) : 0;

    // Convertir la taille en format lisible
    $humanReadableSize = formatSize($contentSize);

    // Extraire les en-têtes
    $headers = $http_response_header ?? [];
    $cfCacheStatus = 'Not Available';
    foreach ($headers as $header) {
        if (stripos($header, 'cf-cache-status:') !== false) {
            $cfCacheStatus = trim(explode(':', $header, 2)[1]);
            break;
        }
    }

    $endTime = microtime(true); // Fin du chronométrage
    $duration = $endTime - $startTime;

    echo "\n\t\t";
    echo $cfCacheStatus;
    echo ' / ';
    echo round($duration, 2) . 's';
    echo ' / Taille : ' . $humanReadableSize;
    echo "\n";
    if(pageSansArticles($content)) die('Cette page ne contient pas d\'articles'."\n");
}

/**
 * Convertit une taille en octets en un format lisible par l'humain.
 *
 * @param int $bytes Taille en octets
 * @return string Taille formatée (Ko, Mo, etc.)
 */
function formatSize($bytes)
{
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' Go';
    } elseif ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' Mo';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' Ko';
    } else {
        return $bytes . ' octets';
    }
}


function pageSansArticles($string)
{
    // Expression régulière pour correspondre au motif
    $pattern = '/<div\s+class="liste-articles">\s*<\/div>/';

    return preg_match($pattern, $string) === 1;
}