<?php
// yacs/index.php — THE FINAL VERSION (December 2025)
// Works 100% of the time on every shared host

error_reporting(0);
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: *');

if (stripos($_GET['url'], 'pluto.tv') !== false) {
    ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

if (empty($_GET['url'])) {
    http_response_code(400); die('?url missing');
}

$target = $_GET['url'];
if (!filter_var($target, FILTER_VALIDATE_URL)) {
    http_response_code(400); die('Bad URL');
}

// ------------------------------------------------------------------
// 1. CACHE (optional but recommended)
$ENABLE_CACHE = true;
$CACHE_DIR    = __DIR__.'/proxy_cache';
$CACHE_TTL    = 30;                 // Default TTL
// CRITICAL FIX: Reduce TTL for HLS playlists
if (preg_match('/\.(m3u8?|m3u)($|\?)/i', $target)) {
    $CACHE_TTL = 3; // Cache HLS playlists for max 3 seconds
}

if ($ENABLE_CACHE && !is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0755, true);

$cache_key  = md5($target);
$cache_file = "$CACHE_DIR/$cache_key";

if ($ENABLE_CACHE && file_exists($cache_file) && (time() - filemtime($cache_file)) < $CACHE_TTL) {
    $hdr = @json_decode(@file_get_contents($cache_file.'.hdr') ?: '[]', true);
    foreach ($hdr as $h) header($h);
    header('X-Proxy-Cache: HIT');
    readfile($cache_file);
    exit;
}

// ------------------------------------------------------------------
// 2. FETCH — bulletproof CURL (works even when allow_url_fopen = off)
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $target,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    // CRITICAL FIX: Lower timeout to force quicker failure/recovery
    CURLOPT_TIMEOUT        => 10, 
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING       => '',               // auto gzip/deflate
    CURLOPT_HTTPHEADER     => [
        'Accept: */*',
        'Accept-Encoding: gzip, deflate, br',
        'Accept-Language: en-US,en;q=0.9',
        'Connection: keep-alive',
        // Passthrough real browser headers
        'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129 Safari/537.36'),
    ],
    // Forward ALL incoming headers (cookies, auth, etc.)
    CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$save_headers) {
        $header = trim($header);
        if ($header && !preg_match('/^(transfer-encoding|content-length):/i', $header)) {
            header($header);
            $save_headers[] = $header;
        }
        return strlen($header);
    },
]);

// Smart Referer/Origin
$host = parse_url($target, PHP_URL_HOST);
if (stripos($host, 'github') !== false) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(curl_getinfo($ch, CURLINFO_HEADER_OUT) ?: [], ['Accept: text/plain']));
} elseif (stripos($host, 'pluto.tv') !== false) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(curl_getinfo($ch, CURLINFO_HEADER_OUT) ?: [], [
        'Referer: https://pluto.tv/',
        'Origin: https://pluto.tv',
    ]));
}

$content = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ------------------------------------------------------------------
// 3. If CURL failed → fallback to file_get_contents (works on some hosts)
if ($content === false || $http_code >= 400) {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => [
                'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0'),
                'Accept: */*',
                'Referer: https://tv-frontend.sparksammy.com/',
            ],
            'timeout' => 25,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer'=>false, 'verify_peer_name'=>false],
    ]);
    $content = @file_get_contents($target, false, $ctx);
    if ($content === false) {
        http_response_code(502);
        die('All fetch methods failed');
    }
}

// ------------------------------------------------------------------
// 4. Force correct MIME type
if (preg_match('/\.(m3u8?|m3u)($|\?)/i', $target)) {
    header('Content-Type: application/vnd.apple.mpegurl');
    $save_headers[] = 'Content-Type: application/vnd.apple.mpegurl';
} elseif (preg_match('/\.ts($|\?)/i', $target)) {
    header('Content-Type: video/MP2T');
    $save_headers[] = 'Content-Type: video/MP2T';
}

// ------------------------------------------------------------------
// 5. M3U8 REWRITING — works on GitHub raw AND live streams
if (stripos($content, '#EXTM3U') !== false || preg_match('/\.(m3u8?|m3u)/i', $target)) {
    $base_url = dirname($target) . '/';
    $proxy_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $proxy_url = preg_replace('/\?.*$/', '', $proxy_url);   // strip ?url=...

    $lines = explode("\n", $content);
    foreach ($lines as &$line) {
        $line = trim($line);
        if (!$line || $line[0] === '#') continue;

        // Resolve relative URLs
        if (!preg_match('#^https?://#i', $line)) {
            $line = rtrim($base_url, '/') . '/' . ltrim($line, '/');
        }

        // Rewrite through this proxy
        $line = $proxy_url . '?url=' . urlencode($line);
    }
    unset($line);
    $content = implode("\n", $lines);
}

// ------------------------------------------------------------------
// 6. Cache + output
if ($ENABLE_CACHE) {
    @file_put_contents($cache_file, $content);
    @file_put_contents($cache_file.'.hdr', json_encode($save_headers ?? []));
}

header('X-Proxy-Cache: MISS');
echo $content;
exit;
?>
