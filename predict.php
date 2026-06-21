<?php
// Proxy + shared cache for the SondeHub/Tawhiri flight prediction. The browser
// used to call api.v2.sondehub.org directly, so every client recomputed the same
// prediction for the same balloon. Routing all clients through here lets them
// share one upstream call per TTL: the position they feed in is already shared
// (aprs.php/ttn.php cache), and we quantize launch_datetime to the TTL bucket so
// near-simultaneous requests collapse onto the same cache key.

require_once 'settings.php';
require_once 'cache.php';

header('Content-Type: application/json');

// Forward only the known tawhiri parameters.
$fields = ['launch_latitude', 'launch_longitude', 'launch_datetime',
           'ascent_rate', 'descent_rate', 'burst_altitude', 'launch_altitude'];
$q = [];
foreach ($fields as $f) {
    if (isset($_GET[$f]) && $_GET[$f] !== '') { $q[$f] = $_GET[$f]; }
}
if (!isset($q['launch_latitude'], $q['launch_longitude'])) { echo '{}'; exit; }

$url = 'https://api.v2.sondehub.org/tawhiri?' . http_build_query($q);

// Cache key: coarse position + rates + launch_datetime bucketed to the TTL, so
// concurrent clients tracking the same balloon share one cached prediction.
$ttl    = api_cache_ttl();
$epoch  = isset($q['launch_datetime']) ? strtotime($q['launch_datetime']) : false;
$bucket = floor(($epoch ?: time()) / $ttl) * $ttl;
$key = 'predict_' . sha1(implode('|', [
    round((float)$q['launch_latitude'], 4),
    round((float)$q['launch_longitude'], 4),
    round((float)($q['launch_altitude'] ?? 0)),
    $q['ascent_rate'] ?? '', $q['descent_rate'] ?? '', $q['burst_altitude'] ?? '',
    $bucket,
]));

$ctx = stream_context_create(['http' => [
    'header'        => "User-Agent: hab-tracker/1.0 (+https://hab.teconecta.es)\r\nAccept: application/json\r\n",
    'timeout'       => 12,
    'ignore_errors' => true,
]]);

$json = cached_fetch($key, $ttl, function () use ($url, $ctx) {
    return @file_get_contents($url, false, $ctx);
});

cache_send_headers();
echo ($json !== null && trim($json) !== '') ? $json : '{}';
