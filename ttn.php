<?php
require_once 'settings.php';

$dev_id = $_GET['dev_id'] ?? '';
$dev_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $dev_id);
if (!$dev_id) { http_response_code(400); echo '{}'; exit; }

header('Content-Type: application/json');

// 1) Try the TTN Storage Integration for the application that owns this device
//    (full decoded payload, needs the secret key — kept server-side).
$app = ttn_app_for($dev_id);
$result = null;
if ($app) {
    $result = ($app['schema'] === 'servet')
        ? ttn_storage_servet($app, $dev_id)
        : ttn_storage_cayenne($app, $dev_id);
}

// 2) Fall back to the public TTN Mapper API (position + signal only).
if ($result === null) {
    $result = ttn_mapper($dev_id);
}

echo ($result !== null) ? json_encode($result) : '{}';
exit;


// Find which configured application owns this device.
function ttn_app_for($dev_id) {
    global $TtnApps;
    if (!empty($TtnApps) && is_array($TtnApps)) {
        foreach ($TtnApps as $app) {
            if (empty($app['key']) || $app['key'] === 'CHANGE ME!') continue;
            if (!empty($app['devices']) && in_array($dev_id, $app['devices'], true)) {
                return $app;
            }
        }
    }
    // Backward-compat: single-app config without $TtnApps.
    global $TtnApiKey, $TtnAppId, $TtnRegion;
    if (!empty($TtnApiKey) && $TtnApiKey !== 'CHANGE ME!' && !empty($TtnAppId)) {
        return ['app_id' => $TtnAppId, 'key' => $TtnApiKey,
                'region' => ($TtnRegion ?: 'eu1'), 'schema' => 'cayenne', 'devices' => []];
    }
    return null;
}

// Fetch up to $limit most-recent stored uplinks. Returns result objects
// (newest first), or [] on error/empty.
function ttn_storage_fetch($app, $dev_id, $limit) {
    $region = $app['region'] ?: 'eu1';
    $url = "https://{$region}.cloud.thethings.network/api/v3/as/applications/"
         . rawurlencode($app['app_id']) . "/devices/" . rawurlencode($dev_id)
         . "/packages/storage/uplink_message?order=-received_at&limit={$limit}";
    $ctx = stream_context_create(['http' => [
        'header'        => "Authorization: Bearer {$app['key']}\r\nAccept: application/json\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || trim($body) === '') { return []; }

    $out = [];
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $obj = json_decode($line, true);
        if (isset($obj['result'])) { $out[] = $obj['result']; }
    }
    return $out;
}

// Position from an uplink: prefer GPS in locations.frm-payload, then decoded
// lat/lng or any gps_* object, then a gateway location.
function ttn_position($up) {
    if (isset($up['locations']['frm-payload']['latitude'])) {
        $l = $up['locations']['frm-payload'];
        return [$l['latitude'], $l['longitude'] ?? null, $l['altitude'] ?? null];
    }
    $d = $up['decoded_payload'] ?? [];
    if (isset($d['latitude'])) {
        return [$d['latitude'], $d['longitude'] ?? null, $d['altitude'] ?? null];
    }
    foreach ($d as $k => $v) {
        if (stripos($k, 'gps') === 0 && is_array($v) && isset($v['latitude'])) {
            return [$v['latitude'], $v['longitude'] ?? null, $v['altitude'] ?? null];
        }
    }
    foreach (($up['rx_metadata'] ?? []) as $m) {
        if (isset($m['location']['latitude'])) {
            return [$m['location']['latitude'], $m['location']['longitude'] ?? null, $m['location']['altitude'] ?? null];
        }
    }
    return [null, null, null];
}

// Best (strongest) rssi/snr from rx_metadata.
function ttn_best_signal($up) {
    $rssi = $snr = null;
    foreach (($up['rx_metadata'] ?? []) as $m) {
        if (isset($m['rssi']) && ($rssi === null || $m['rssi'] > $rssi)) {
            $rssi = $m['rssi'];
            $snr  = $m['snr'] ?? null;
        }
    }
    return [$rssi, $snr];
}

function ttn_fnum($v, $dec = 0) { return is_numeric($v) ? round($v, $dec) : $v; }


// ----- Cayenne schema (algspd): single latest message carries everything -----
function ttn_storage_cayenne($app, $dev_id) {
    $recs = ttn_storage_fetch($app, $dev_id, 1);
    if (!count($recs)) { return null; }
    $r  = $recs[0];
    $up = $r['uplink_message'] ?? [];
    list($lat, $lng, $alt) = ttn_position($up);
    if ($lat === null) { return null; }
    $d = $up['decoded_payload'] ?? [];

    $temperature = null;
    foreach ($d as $k => $v) { if (stripos($k, 'temperature') === 0) { $temperature = $v; break; } }
    $digitals = [];
    foreach ($d as $k => $v) { if (stripos($k, 'digital_out') === 0) { $digitals[] = ['name' => $k, 'value' => $v]; } }
    list($rssi, $snr) = ttn_best_signal($up);

    $fields = [];
    if ($alt !== null)         $fields[] = ['label' => 'Alt',  'value' => ttn_fnum($alt) . 'm'];
    if ($temperature !== null) $fields[] = ['label' => 'Temp', 'value' => $temperature . '°C'];
    $i = 1;
    foreach ($digitals as $dg) { $fields[] = ['label' => 'Out' . $i, 'value' => $dg['value']]; $i++; }
    if ($rssi !== null)        $fields[] = ['label' => 'RSSI', 'value' => $rssi];

    return [
        'source' => 'storage', 'schema' => 'cayenne',
        'time' => $r['received_at'] ?? ($up['received_at'] ?? null),
        'latitude' => $lat, 'longitude' => $lng, 'altitude' => $alt,
        'temperature' => $temperature, 'digitals' => $digitals,
        'rssi' => $rssi, 'snr' => $snr,
        'fields' => $fields, 'payload' => $d,
    ];
}


// ----- Servet schema (server-ttn-mapper): position and sensors arrive in
//       separate uplinks (type 1 = GPS, type 2 = sensors); merge the latest of each.
function ttn_storage_servet($app, $dev_id) {
    $recs = ttn_storage_fetch($app, $dev_id, 20);
    if (!count($recs)) { return null; }

    $posUp = null; $posRec = null; $sensUp = null;
    foreach ($recs as $r) {
        $up = $r['uplink_message'] ?? [];
        $d  = $up['decoded_payload'] ?? [];
        if ($posUp === null) {
            list($plat) = ttn_position($up);
            if ($plat !== null) { $posUp = $up; $posRec = $r; }
        }
        if ($sensUp === null && isset($d['type']) && $d['type'] == 2) { $sensUp = $up; }
        if ($posUp !== null && $sensUp !== null) break;
    }
    if ($posUp === null) { return null; }

    list($lat, $lng, $alt) = ttn_position($posUp);
    $pd = $posUp['decoded_payload'] ?? [];
    $sd = $sensUp['decoded_payload'] ?? [];
    list($rssi, $snr) = ttn_best_signal($posUp);

    $fields = [];
    if ($alt !== null)               $fields[] = ['label' => 'Alt',  'value' => ttn_fnum($alt) . 'm'];
    if (isset($pd['sats']))          $fields[] = ['label' => 'Sats', 'value' => $pd['sats']];
    if (isset($sd['batt']))          $fields[] = ['label' => 'Batt', 'value' => $sd['batt'] . 'v'];
    if (isset($sd['temperature_i'])) $fields[] = ['label' => 'Tin',  'value' => ttn_fnum($sd['temperature_i'], 1) . '°C'];
    if (isset($sd['temperature_e'])) $fields[] = ['label' => 'Tout', 'value' => ttn_fnum($sd['temperature_e'], 1) . '°C'];
    if (isset($sd['humidity']))      $fields[] = ['label' => 'Hum',  'value' => $sd['humidity'] . '%'];
    if (isset($sd['pressure']))      $fields[] = ['label' => 'Pres', 'value' => $sd['pressure'] . 'hPa'];
    if (isset($sd['e_cut']))         $fields[] = ['label' => 'Cut',  'value' => $sd['e_cut']];
    if (isset($sd['e_globo']))       $fields[] = ['label' => 'Globo','value' => $sd['e_globo']];
    if ($rssi !== null)              $fields[] = ['label' => 'RSSI', 'value' => $rssi];

    return [
        'source' => 'storage', 'schema' => 'servet',
        'time' => $posRec['received_at'] ?? ($posUp['received_at'] ?? null),
        'latitude' => $lat, 'longitude' => $lng, 'altitude' => $alt,
        'temperature' => $sd['temperature_i'] ?? null, 'digitals' => [],
        'rssi' => $rssi, 'snr' => $snr,
        'fields' => $fields,
        'payload' => ['position' => $pd, 'sensors' => $sd],
    ];
}


function ttn_mapper($dev_id) {
    $end   = gmdate('Y-m-d\TH:i:s\Z');
    $start = gmdate('Y-m-d\TH:i:s\Z', strtotime('-30 days'));
    $url = "https://api.ttnmapper.org/device/data?dev_id={$dev_id}&start_time={$start}&end_time={$end}&limit=100";

    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36\r\nAccept: application/json\r\nReferer: https://ttnmapper.org/\r\n",
        'timeout' => 10,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { return null; }

    $data = json_decode($body, true);
    if (!is_array($data) || !count($data)) { return null; }

    usort($data, function ($a, $b) {
        return strtotime($b['time'] ?? '0') - strtotime($a['time'] ?? '0');
    });
    $p = $data[0];

    $fields = [];
    if (isset($p['altitude']))   $fields[] = ['label' => 'Alt',  'value' => round($p['altitude']) . 'm'];
    if (isset($p['satellites'])) $fields[] = ['label' => 'Sats', 'value' => $p['satellites']];

    return [
        'source' => 'ttnmapper', 'schema' => 'ttnmapper',
        'time' => $p['time'] ?? null,
        'latitude' => $p['latitude'] ?? null,
        'longitude' => $p['longitude'] ?? null,
        'altitude' => $p['altitude'] ?? null,
        'temperature' => null, 'digitals' => [],
        'rssi' => null, 'snr' => null,
        'satellites' => $p['satellites'] ?? null,
        'fields' => $fields, 'payload' => null,
    ];
}
