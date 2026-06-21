<?php
   include "settings.php";

   // Sanitize the callsign before interpolating it into the URL.
   $indicativo = preg_replace('/[^A-Za-z0-9\-]/', '', $_GET['indicativo'] ?? '');

   $url = 'https://api.aprs.fi/api/get?name=' . rawurlencode($indicativo)
        . '&what=loc&apikey=' . $AprsFiKey . '&format=json';

   // aprs.fi requires a User-Agent identifying the app name, version and home page.
   $ctx = stream_context_create(['http' => [
       'header'  => "User-Agent: hab-tracker/1.0 (+https://hab.teconecta.es)\r\n",
       'timeout' => 10,
   ]]);
   $json = @file_get_contents($url, false, $ctx);

   header('Content-Type: application/json');
   echo ($json !== false) ? $json : '{}';
