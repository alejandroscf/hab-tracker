<?php
require_once 'settings.php';

$dev_id = $_GET['dev_id'] ?? '';
$dev_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $dev_id);
if (!$dev_id) { http_response_code(400); echo '[]'; exit; }

$end   = gmdate('Y-m-d\TH:i:s\Z');
$start = gmdate('Y-m-d\TH:i:s\Z', strtotime('-30 days'));

$url = "https://api.ttnmapper.org/device/data?dev_id={$dev_id}&start_time={$start}&end_time={$end}&limit=100";

$ctx = stream_context_create(['http' => [
    'header'  => "User-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36\r\nAccept: application/json\r\nReferer: https://ttnmapper.org/\r\n",
    'timeout' => 10,
]]);

$data = @file_get_contents($url, false, $ctx);

header('Content-Type: application/json');
echo ($data !== false) ? $data : '[]';
