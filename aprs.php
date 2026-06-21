<?php
   include "settings.php";
   include "cache.php";

   // Sanitize the callsign before interpolating it into the URL.
   $indicativo = preg_replace('/[^A-Za-z0-9\-]/', '', $_GET['indicativo'] ?? '');

   header('Content-Type: application/json');
   if ($indicativo === '') { echo '{}'; exit; }

   $url = 'https://api.aprs.fi/api/get?name=' . rawurlencode($indicativo)
        . '&what=loc&apikey=' . $AprsFiKey . '&format=json';

   // aprs.fi requires a User-Agent identifying the app name, version and home page.
   $ctx = stream_context_create(['http' => [
       'header'  => "User-Agent: hab-tracker/1.0 (+https://hab.teconecta.es)\r\n",
       'timeout' => 10,
   ]]);

   // Shared cache: one upstream fetch per callsign per TTL, across all clients.
   $json = cached_fetch('aprs_' . $indicativo, api_cache_ttl(),
       function () use ($url, $ctx) { return @file_get_contents($url, false, $ctx); });

   cache_send_headers();   // X-Cache: HIT|MISS|STALE for the debug overlay
   echo ($json !== null && trim($json) !== '') ? $json : '{}';
