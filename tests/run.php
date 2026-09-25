<?php
require __DIR__.'/../src/RelaxitClient.php';
require __DIR__.'/../src/AppointmentConfirmation.php';

use Relaxit\DolimedNotif\RelaxitClient;
use Relaxit\DolimedNotif\AppointmentConfirmation;

$count = 0;
function check($condition, $label)
{
    global $count;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $count++;
}
function rejects($callable, $label)
{
    try {
        $callable();
    } catch (InvalidArgumentException $exception) {
        check(true, $label);
        return;
    }
    check(false, $label);
}
function appointment($installation = 'site-a', $entity = 1, $id = 42, $consented = true, $recipient = '+221770000001', $variables = null)
{
    return AppointmentConfirmation::prepare($installation, $entity, $id, $recipient, $consented,
        'appointment_confirmation', $variables === null ? array('name' => 'Exemple', 'date' => '2026-10-01') : $variables);
}

$a = appointment();
check($a === appointment(), 'Replay retains identical key and body');
check($a['idempotency_key'] === appointment('site-a', '1', '42')['idempotency_key'], 'Database scalar types stable');
foreach (array(appointment('site-b'), appointment('site-a', 2), appointment('site-a', 1, 43)) as $other) {
    check($a['idempotency_key'] !== $other['idempotency_key'], 'No cross-site/entity/appointment collision');
}
$changed = appointment('site-a', 1, 42, true, '+221770000001', array('name' => 'Changed'));
check($a['idempotency_key'] === $changed['idempotency_key'], 'Changed content cannot silently create a second confirmation');
check(!isset($a['payload']['tenant_id']), 'Tenant determined by API key');
check(count($a['payload']) === 5, 'No source object or medical record exported');
rejects(function () { appointment('site-a', 1, 42, false); }, 'Missing consent');
rejects(function () { appointment('site-a', 1, 42, 'true'); }, 'Consent must be explicit boolean');
rejects(function () { appointment('site-a', 1, 42, true, '77 000 00 01'); }, 'No guessed phone country');
rejects(function () { appointment('site-a', 1, 42, true, '+221770000001', array('name' => array('private'))); }, 'No nested variables');
rejects(function () { appointment('site-a', 1, 42, true, '+221770000001', array('name' => "\xff")); }, 'Invalid UTF8');
rejects(function () { appointment('site-a', 1, 42, true, '+221770000001', array('name' => str_repeat('é', 1001))); }, 'Variable length');
check(appointment('site-a', 1, 42, true, '+221770000001', array('name' => str_repeat('é', 1000))) !== null, 'Unicode length counted as characters');
foreach (array('http://notify.example', 'https://key@notify.example', 'https://notify.example?token=x', 'https://notify.example#x') as $url) {
    rejects(function () use ($url) { new RelaxitClient($url, 'test-key'); }, 'Unsafe base URL');
}
rejects(function () { new RelaxitClient('https://notify.example', "secret\r\nInjected: x"); }, 'Header injection');

$calls = array();
$id = '01K50000000000000000000000';
$response = array('code' => 202, 'body' => json_encode(array('data' => array('id' => $id, 'status' => 'queued'), 'replayed' => false)));
$client = new RelaxitClient('https://notify.example/', 'test-key', function ($method, $url, $headers, $body) use (&$calls, &$response) {
    $calls[] = array($method, $url, $headers, $body);
    return $response;
});
$r = $client->submit($a['payload'], $a['idempotency_key']);
check($r['ok'] && !$r['retryable'], 'Accepted POST');
check($calls[0][0] === 'POST' && $calls[0][1] === 'https://notify.example/api/v1/notifications', 'POST endpoint');
check(in_array('Authorization: Bearer test-key', $calls[0][2], true), 'API authentication');
check(in_array('Idempotency-Key: '.$a['idempotency_key'], $calls[0][2], true), 'Idempotency header');
check(json_decode($calls[0][3], true) === $a['payload'], 'Exact payload');
foreach (array(0, 408, 429, 500, 503) as $code) {
    $response = array('code' => $code, 'body' => 'private diagnostic', 'retry_after' => 60);
    $r = $client->submit($a['payload'], $a['idempotency_key']);
    check(!$r['ok'] && $r['retryable'] && $r['data'] === null && $r['retry_after'] === 60, 'Transient '.$code);
}
check(count($calls) === 6, 'No implicit HTTP retries');
foreach ($calls as $call) {
    check($call[3] === $calls[0][3] && $call[2] === $calls[0][2], 'Retries preserve body/key');
}
foreach (array(301, 302, 400, 401, 403, 404, 409, 422) as $code) {
    $response = array('code' => $code, 'body' => '{"error":"secret"}');
    $r = $client->submit($a['payload'], $a['idempotency_key']);
    check(!$r['ok'] && !$r['retryable'] && $r['data'] === null, 'Permanent error '.$code);
}
foreach (array('<html>login</html>', '{}', '{"data":{"id":"bad","status":"read"}}') as $body) {
    $response = array('code' => 200, 'body' => $body);
    check($client->submit($a['payload'], $a['idempotency_key'])['retryable'], 'Malformed success is uncertain');
}
$response = array('code' => 200, 'body' => json_encode(array('data' => array('id' => $id, 'status' => 'read'))));
check($client->status($id)['data']['data']['status'] === 'read', 'Read status');
check(!$client->status('01K50000000000000000000001')['ok'], 'Reject mismatched response ID');
rejects(function () use ($client) { $client->status('../me'); }, 'No arbitrary endpoint');
rejects(function () use ($client, $a) { $client->submit($a['payload'], "x\nHeader: x"); }, 'No idempotency header injection');
$response = array('code' => 200, 'body' => '{"tenant":{"code":"GLOBALE_SANTE"},"application":"dolibarr"}');
check($client->identity()['data']['tenant']['code'] === 'GLOBALE_SANTE', 'Identity endpoint');
echo $count." assertions OK (PHP ".PHP_VERSION.")\n";
