<?php

// JAMF School API mock for tests/e2e/run.sh (fictional devices only). State lives in
// /mock-state, written by the test script:
//   devices.json  device list returned by GET /api/devices
//   fail          "<status> <count>": answer the next <count> device requests with <status>
//   requests.log  one line per request (appended)
$state = '/mock-state';
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Basic ');
file_put_contents("$state/requests.log", sprintf("%s %s auth=%s\n", $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $auth ? 'yes' : 'no'), FILE_APPEND);

header('Content-Type: application/json');
if (!$auth) {
    http_response_code(401);
    echo '{"message":"unauthorized"}';
    return;
}
if ($path !== '/api/devices') {
    http_response_code(404);
    echo '{"message":"not found"}';
    return;
}

$fail = is_file("$state/fail") ? trim((string) file_get_contents("$state/fail")) : '';
if (preg_match('/^(\d{3}) (\d+)$/', $fail, $m) === 1 && (int) $m[2] > 0) {
    file_put_contents("$state/fail", $m[1] . ' ' . ((int) $m[2] - 1));
    http_response_code((int) $m[1]);
    if ((int) $m[1] === 429) {
        header('Retry-After: 1');
    }
    echo '{"message":"simulated failure"}';
    return;
}

$devices = json_decode((string) file_get_contents("$state/devices.json"), true) ?: [];
echo json_encode(['code' => 200, 'count' => count($devices), 'devices' => $devices]);
