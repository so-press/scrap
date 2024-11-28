<?php

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$verbose = in_array('--verbose', $argv);
$refresh = in_array('--refresh', $argv);
$recompute = in_array('--recompute', $argv);
$start = null;

// Parse arguments for --start parameter
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--start=')) {
        $start = substr($arg, 8);
    }
}

if($start == 'last') {
    $start = $redis->get('last_visited_url');
}
if($start || $refresh) {
    $redis->del('visited_urls');
}
$totalUrlsKey = 'total_urls'; // Redis key for total URLs
$totalUrls = 0; // Initialize total URLs count
$processedUrls = 0; // Number of processed URLs
$totalTimeSpent = 0; // Total time spent on URL calls
$skipUntilStart = !empty($start); // Flag to indicate whether to skip URLs


// Appel du script principal
$sitemapUrl = 'https://www.sofoot.com/sitemap.xml';
processSitemap($sitemapUrl);


function processSitemap($sitemapUrl)
{
    global $totalUrls, $totalUrlsKey, $redis, $recompute;

    $sitemapName = basename($sitemapUrl);
    echo "Téléchargement du sitemap principal: $sitemapName\n";
    $sitemapContent = file_get_contents($sitemapUrl);

    if ($sitemapContent === false) {
        echo "Impossible de télécharger le sitemap principal.\n";
        return;
    }

    $sitemapXml = simplexml_load_string($sitemapContent);
    if (!$sitemapXml) {
        echo "Erreur de parsing XML du sitemap principal.\n";
        return;
    }

    $total = count($sitemapXml->sitemap);
    $sitemaps = [];
    foreach ($sitemapXml->sitemap as $sitemap) {
        $sitemaps[] = $sitemap;
    }

    if (!$recompute && $redis->exists($totalUrlsKey)) {
        $totalUrls = (int)$redis->get($totalUrlsKey);
    } else {

        foreach ($sitemaps as $sitemap) {
            echo ".";
            $subSitemapUrl = (string) $sitemap->loc;
            $subSitemapContent = file_get_contents($subSitemapUrl);
            if ($subSitemapContent === false) continue;
            $subSitemapXml = simplexml_load_string($subSitemapContent);
            if (!$subSitemapXml) continue;

            $totalUrls += count($subSitemapXml->url); // Count URLs in each sub-sitemap
        }
        echo "\n";
        $redis->set($totalUrlsKey, $totalUrls);
    }

    $totalUrls -= $redis->scard('visited_urls');
    $idx = 0;
    foreach ($sitemaps as $sitemap) {
        $idx++;
        $subSitemapUrl = (string) $sitemap->loc;
        $subSitemapName = basename($subSitemapUrl);
        echo ($total - $idx) . '/' . $total . " " . $subSitemapName . "\n";

        processSubSitemap($subSitemapUrl, $idx, $total);
    }
}

/**
 * Télécharge et analyse un sous-sitemap.
 * @param string $subSitemapUrl URL du sous-sitemap.
 * @param Redis $redis Instance Redis pour la gestion des URLs.
 */
function processSubSitemap($subSitemapUrl, $sIdx, $sTotal)
{
    global $totalUrls, $processedUrls, $start, $skipUntilStart, $redis;

    $subSitemapContent = file_get_contents($subSitemapUrl);

    if ($subSitemapContent === false) {
        echo "Impossible de télécharger le sous-sitemap: $subSitemapUrl\n";
        return;
    }

    $subSitemapXml = simplexml_load_string($subSitemapContent);
    if (!$subSitemapXml) {
        echo "Erreur de parsing XML du sous-sitemap.\n";
        return;
    }

    $total = count($subSitemapXml->url);
    $idx = 0;
    foreach ($subSitemapXml->url as $urlEntry) {
        $idx++;
        $url = (string) $urlEntry->loc;

        if (strstr($url, '.xml')) {
            continue;
        }

        if ($redis->sIsMember('visited_urls', $url)) {
            continue;
        }

        // Store URL in Redis
        $redis->sAdd('visited_urls', $url);
        $redis->set('last_visited_url', $url);

        // Skip URLs until the start value is reached
        if ($skipUntilStart) {
            if ($url === $start) {
                $skipUntilStart = false;
            } else {
                continue;
            }
        }


        $subSitemapName = basename($subSitemapUrl);
        echo "\t";
        echo $sIdx . '/' . $sTotal;
        echo ' - ';
        echo $processedUrls . '/' . $totalUrls;
        echo ' - ';
        echo $subSitemapName . "\n\t\t" . str_replace('_https://www.sofoot.com/', '/', $url);

        callUrl($url);
        $processedUrls++;
        echo "\n";
    }
}
/**
 * Effectue une requête GET sur une URL, mesure le temps d'exécution,
 * affiche la taille de la réponse et la valeur de l'en-tête 'cf-cache-status'.
 * @param string $url URL à analyser.
 */
function callUrl($url)
{
    global $processedUrls, $totalUrls, $totalTimeSpent, $verbose;

    if(!$verbose) {
        return callUrlWithoutWaiting($url);
    }
    $startTime = microtime(true); // Start timing

    // Context to retrieve headers
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'follow_location' => true,
            'timeout' => 10
        ]
    ]);

    // Perform the request
    $content = @file_get_contents($url, false, $context);

    $endTime = microtime(true); // End timing
    $duration = $endTime - $startTime;
    $totalTimeSpent += $duration;

    // Calculate content size
    $contentSize = $content ? strlen($content) : 0;

    // Extract headers
    $headers = $http_response_header ?? [];
    $cfCacheStatus = 'Not Available';
    foreach ($headers as $header) {
        if (stripos($header, 'cf-cache-status:') !== false) {
            $cfCacheStatus = trim(explode(':', $header, 2)[1]);
            break;
        }
    }

    $averageTime = $processedUrls ? $totalTimeSpent / $processedUrls : 0;
    $remainingUrls = $totalUrls - $processedUrls;
    $estimatedTimeRemaining = $averageTime * $remainingUrls;

    echo "\n\t\t";
    echo $cfCacheStatus;
    echo ' / ';
    echo round($duration, 2) . 's';
    echo ' / ';
    echo humanReadableSize($contentSize);
    echo "\n\t\t".'ETA ' . date('Y-m-d H:i:s', time() + $estimatedTimeRemaining) . ' - ' . formatDuration($estimatedTimeRemaining);
}

/**
 * Formats a duration in seconds into hours, minutes, and seconds.
 * @param float $seconds Duration in seconds.
 * @return string Formatted duration as "Xh Ym Zs".
 */
function formatDuration($seconds)
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;

    return sprintf("%dh %dm %ds", $hours, $minutes, $seconds);
}

/**
 * Convert bytes into a human-readable format (KB, MB, GB, etc.).
 * @param int $size Size in bytes.
 * @return string Human-readable size.
 */
function humanReadableSize($size)
{
    if ($size == 0) {
        return '0 Bytes';
    }

    $unit = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log($size, 1024));
    return round($size / pow(1024, $i), 2) . ' ' . $unit[$i];
}

/**
 * Envoie une requête GET à une URL sans attendre la réponse.
 *
 * @param string $url L'URL à appeler.
 */
function callUrlWithoutWaiting($url)
{
    $urlParts = parse_url($url);

    if (!isset($urlParts['host'])) {
        throw new InvalidArgumentException("Invalid URL: $url");
    }

    $host = $urlParts['host'];
    $port = isset($urlParts['port']) ? $urlParts['port'] : ($urlParts['scheme'] === 'https' ? 443 : 80);
    $path = isset($urlParts['path']) ? $urlParts['path'] : '/';
    $path .= isset($urlParts['query']) ? '?' . $urlParts['query'] : '';

    $scheme = ($urlParts['scheme'] === 'https') ? 'ssl://' : '';
    $socket = fsockopen($scheme . $host, $port, $errno, $errstr, 30);

    if (!$socket) {
        throw new RuntimeException("Failed to open socket to $host: $errstr ($errno)");
    }

    $request = "GET $path HTTP/1.1\r\n";
    $request .= "Host: $host\r\n";
    $request .= "Connection: Close\r\n\r\n";

    fwrite($socket, $request);
    fclose($socket);
}


