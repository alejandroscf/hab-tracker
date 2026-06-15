<?php
require_once 'settings.php';

$dev_id = $_GET['dev_id'] ?? '';
$dev_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $dev_id);
if (!$dev_id) { http_response_code(400); echo '{}'; exit; }

header('Content-Type: application/json');

// ---------------------------------------------------------------------------
// 1) Try the TTN Storage Integration (full decoded payload). Needs the secret
//    API key, so this must stay server-side.
// ---------------------------------------------------------------------------
$result = ttn_storage($dev_id);

// ---------------------------------------------------------------------------
// 2) Fall back to the public TTN Mapper API (position + signal only).
// ---------------------------------------------------------------------------
if ($result === null) {
    $result = ttn_mapper($dev_id);
}

echo ($result !== null) ? json_encode($result) : '{}';
exit;


function ttn_storage($dev_id) {
    global $TtnApiKey, $TtnAppId, $TtnRegion;
    if (empty($TtnApiKey) || $TtnApiKey === 'CHANGE ME!' || empty($TtnAppId)) {
        return null;
    }
    $region = $TtnRegion ?: 'eu1';
    $url = "https://{$region}.cloud.thethings.network/api/v3/as/applications/"
         . rawurlencode($TtnAppId) . "/devices/" . rawurlencode($dev_id)
         . "/packages/storage/uplink_message?order=-received_at&limit=1";

    $ctx = stream_context_create(['http' => [
        'header'        => "Authorization: Bearer {$TtnApiKey}\r\nAccept: application/json\r\n",
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || trim($body) === '') {
        return null; // not stored yet / device silent since Storage was enabled
    }

    // Response is newline-delimited JSON: one {"result":{...}} per line.
    $line = strtok($body, "\n");
    while ($line !== false && trim($line) === '') { $line = strtok("\n"); }
    if ($line === false) { return null; }

    $obj = json_decode($line, true);
    $up  = $obj['result']['uplink_message'] ?? null;
    if (!$up) { return null; }

    $decoded = $up['decoded_payload'] ?? [];

    // --- position (GPS) ---
    $lat = $lng = $alt = null;
    if (isset($up['locations']['frm-payload'])) {
        $loc = $up['locations']['frm-payload'];
        $lat = $loc['latitude'] ?? null;
        $lng = $loc['longitude'] ?? null;
        $alt = $loc['altitude'] ?? null;
    }
    if ($lat === null) {
        // any decoded_payload key prefixed gps_
        foreach ($decoded as $k => $v) {
            if (stripos($k, 'gps') === 0 && is_array($v) && isset($v['latitude'])) {
                $lat = $v['latitude'];
                $lng = $v['longitude'] ?? null;
                $alt = $v['altitude'] ?? null;
                break;
            }
        }
    }
    if ($lat === null) {
        foreach (($up['rx_metadata'] ?? []) as $m) {
            if (isset($m['location']['latitude'])) {
                $lat = $m['location']['latitude'];
                $lng = $m['location']['longitude'] ?? null;
                $alt = $m['location']['altitude'] ?? null;
                break;
            }
        }
    }
    if ($lat === null) { return null; }

    // --- temperature (first temperature_* key) ---
    $temperature = null;
    foreach ($decoded as $k => $v) {
        if (stripos($k, 'temperature') === 0) { $temperature = $v; break; }
    }

    // --- digital outputs (all digital_out_* keys) ---
    $digitals = [];
    foreach ($decoded as $k => $v) {
        if (stripos($k, 'digital_out') === 0) {
            $digitals[] = ['name' => $k, 'value' => $v];
        }
    }

    // --- best rssi/snr ---
    $rssi = $snr = null;
    foreach (($up['rx_metadata'] ?? []) as $m) {
        if (isset($m['rssi']) && ($rssi === null || $m['rssi'] > $rssi)) {
            $rssi = $m['rssi'];
            $snr  = $m['snr'] ?? null;
        }
    }

    return [
        'source'      => 'storage',
        'time'        => $obj['result']['received_at'] ?? ($up['received_at'] ?? null),
        'latitude'    => $lat,
        'longitude'   => $lng,
        'altitude'    => $alt,
        'temperature' => $temperature,
        'digitals'    => $digitals,
        'rssi'        => $rssi,
        'snr'         => $snr,
        'payload'     => $decoded,
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

    // newest first
    usort($data, function ($a, $b) {
        return strtotime($b['time'] ?? '0') - strtotime($a['time'] ?? '0');
    });
    $p = $data[0];

    return [
        'source'      => 'ttnmapper',
        'time'        => $p['time'] ?? null,
        'latitude'    => $p['latitude'] ?? null,
        'longitude'   => $p['longitude'] ?? null,
        'altitude'    => $p['altitude'] ?? null,
        'temperature' => null,
        'digitals'    => [],
        'rssi'        => null,
        'snr'         => null,
        'satellites'  => $p['satellites'] ?? null,
        'payload'     => null,
    ];
}
