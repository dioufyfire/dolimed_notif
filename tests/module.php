<?php
require __DIR__.'/run.php';
date_default_timezone_set('UTC'); // Dolibarr main.inc.php initializes the runtime timezone.
define('DOL_DOCUMENT_ROOT', __DIR__.'/fixtures/dolibarr');
define('DOL_DATA_ROOT', sys_get_temp_dir().'/dolimednotif-tests-'.getmypid());
define('MAIN_DB_PREFIX', 'test_');
mkdir(DOL_DATA_ROOT.'/dolimednotif', 0700, true);
register_shutdown_function(function () {
    @unlink(DOL_DATA_ROOT.'/dolimednotif/config.php');
    @rmdir(DOL_DATA_ROOT.'/dolimednotif');
    @rmdir(DOL_DATA_ROOT);
});
require __DIR__.'/../core/triggers/interface_99_modDolimedNotif_DolimedNotifAppointment.class.php';
require __DIR__.'/../class/dolimednotifworker.class.php';

class TestDatabase
{
    public $pdo;
    public $failInsert = false;
    public function __construct($pdo) { $this->pdo = $pdo; }
    public function query($sql) {
        if ($this->failInsert && strpos($sql, 'INSERT INTO test_dolimednotif') === 0) { return false; }
        try { return $this->pdo->query($sql); } catch (PDOException $error) { return false; }
    }
    public function escape($value) { return substr($this->pdo->quote($value), 1, -1); }
    public function fetch_object($result) { return $result->fetchObject(); }
    public function affected_rows($result) { return $result->rowCount(); }
}
$dsn = getenv('DOLIMED_TEST_DSN') ?: 'sqlite::memory:';
$pdo = new PDO($dsn, getenv('DOLIMED_TEST_USER') ?: null, getenv('DOLIMED_TEST_PASSWORD') ?: null);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$schema = str_replace('llx_', MAIN_DB_PREFIX, file_get_contents(__DIR__.'/../sql/llx_dolimednotif_outbox.sql'));
if (strpos($dsn, 'sqlite:') === 0) {
    $schema = str_replace('integer AUTO_INCREMENT PRIMARY KEY', 'integer PRIMARY KEY AUTOINCREMENT', $schema);
    $schema = str_replace('UNIQUE KEY uk_dolimednotif_event (entity, fk_action),', 'UNIQUE (entity, fk_action)', $schema);
    $schema = preg_replace('/\s*INDEX idx_dolimednotif_due[^\n]+/', '', $schema);
    $schema = str_replace(' ENGINE=innodb', '', $schema);
}
$pdo->exec('DROP TABLE IF EXISTS test_dolimednotif_outbox');
$pdo->exec($schema);
$db = new TestDatabase($pdo);
$conf = (object) array('entity' => 1, 'dolimednotif' => (object) array('enabled' => 1));
$config = array('enabled' => true, 'url' => 'https://notify.example', 'api_key' => 'fake-key', 'tenant_code' => 'GLOBALE_SANTE',
    'installation_id' => 'test-install', 'encryption_key' => str_repeat('a1', 32), 'template' => 'appointment_confirmation',
    'timezone' => 'Africa/Dakar', 'clinic_name' => 'Test', 'variables' => array('name' => 'patient_name', 'date' => 'appointment_date'));
function saveConfig($config) { file_put_contents(DOL_DATA_ROOT.'/dolimednotif/config.php', '<?php return '.var_export(array(1 => $config), true).';'); }
saveConfig($config);
$patient = (object) array('id' => 20, 'entity' => 1, 'name' => 'Exemple', 'phone_mobile' => '00221 77 000 00 01',
    'phone' => '+221770000099', 'array_options' => array('options_dolimednotif_whatsapp' => '1'));
$event = (object) array('id' => 42, 'entity' => 1, 'socid' => 20, 'type_code' => 'AC_RDV', 'datep' => time() + 86400, 'datef' => time() + 90000, 'fulldayevent' => 0);
$GLOBALS['patients'] = array(20 => $patient);
$GLOBALS['events'] = array(42 => $event);
$trigger = new InterfaceDolimedNotifAppointment($db);
function capture() { global $trigger, $event, $conf; return $trigger->runTrigger('ACTION_CREATE', $event, new stdClass(), new stdClass(), $conf); }
function latestRow() { global $pdo; return $pdo->query('SELECT * FROM test_dolimednotif_outbox ORDER BY rowid DESC')->fetchObject(); }
function resetQueue() { global $pdo; $pdo->exec('DELETE FROM test_dolimednotif_outbox'); }
function dueNow() { global $pdo; $pdo->exec('UPDATE test_dolimednotif_outbox SET next_attempt=0'); }

$pdo->beginTransaction();
check(capture() === 1, 'Trigger captures within caller transaction');
$pdo->rollBack();
check(latestRow() === false, 'Appointment rollback rolls back notification');
foreach (array(false, 0, '0', 'true') as $consent) {
    $patient->array_options['options_dolimednotif_whatsapp'] = $consent;
    check(capture() === 0 && latestRow() === false, 'No consent, no queue');
}
$patient->array_options['options_dolimednotif_whatsapp'] = 1;
$patient->phone_mobile = '';
check(capture() === 0, 'No fallback to landline');
$patient->phone_mobile = '77 000 00 01';
check(capture() === 0, 'No implicit country code');
$patient->phone_mobile = '+221770000001';
$event->type_code = 'AC_OTH'; check(capture() === 0, 'Ignore non appointment'); $event->type_code = 'AC_RDV';
$patient->entity = 2; check(capture() === 0, 'Cross-entity patient refused'); $patient->entity = 1;
$event->fulldayevent = 1; check(capture() === 0, 'All-day appointment excluded'); $event->fulldayevent = 0;
$db->failInsert = true; check(capture() === -1, 'Storage failure prevents false successful capture'); $db->failInsert = false;
check(capture() === 1, 'Eligible appointment captured');
$first = latestRow();
check(strpos($first->payload, 'Exemple') === false && strpos($first->payload, '221770') === false, 'Patient data encrypted');
check(capture() === 1 && latestRow()->rowid === $first->rowid, 'Duplicate trigger retained once');
$cipher = new \Relaxit\DolimedNotif\PayloadCipher($config['encryption_key']);
$clear = $cipher->decrypt($first->payload);
check($clear['payload']['recipient'] === '+221770000001', 'Third-party mobile selected');
$bad = base64_decode($first->payload); $bad[40] = chr(ord($bad[40]) ^ 1);
try { $cipher->decrypt(base64_encode($bad)); check(false, 'Tampered ciphertext rejected'); } catch (RuntimeException $e) { check(true, 'Tampered ciphertext rejected'); }
$outbox = new \Relaxit\DolimedNotif\Outbox($db, 1);
check($outbox->claim($first, time(), 'worker-a'), 'First worker claims');
check(!$outbox->claim($first, time(), 'worker-b'), 'Second worker excluded');
$outbox->finish($first, 'worker-b', array('state' => 'done'));
check(latestRow()->state === 'pending', 'Wrong worker cannot finish');
$outbox->finish($first, 'worker-a', array('next_attempt' => 0));
check(count((new \Relaxit\DolimedNotif\Outbox($db, 2))->due(time())) === 0, 'Queue isolated by entity');
check($outbox->claim($first, time(), 'worker-a'), 'Reclaim due pending request');
$outbox->finish($first, 'worker-a', array('state' => 'tracking', 'remote_id' => '01K50000000000000000000000', 'next_attempt' => time() + 300));
check(!$outbox->claim($first, time(), 'worker-stale'), 'Stale selection cannot claim an already accepted request');
resetQueue(); capture();

$posts = array(); $polls = 0; $tenant = 'WRONG'; $postCode = 202; $remoteStatus = 'queued';
$factory = function ($config) use (&$posts, &$polls, &$tenant, &$postCode, &$remoteStatus) {
    return new \Relaxit\DolimedNotif\RelaxitClient($config['url'], $config['api_key'], function ($method, $url, $headers, $body) use (&$posts, &$polls, &$tenant, &$postCode, &$remoteStatus) {
        if (substr($url, -3) === '/me') {
            return array('code' => 200, 'body' => json_encode(array('tenant' => array('code' => $tenant), 'application' => 'dolibarr')));
        }
        if ($method === 'POST') { $posts[] = array($headers, $body); } else { $polls++; }
        return array('code' => $method === 'POST' ? $postCode : 200, 'body' => json_encode(array('data' => array('id' => '01K50000000000000000000000', 'status' => $remoteStatus))));
    });
};
$worker = new DolimedNotifWorker($db, $factory);
check($worker->run() === -1 && count($posts) === 0, 'Wrong tenant blocks every send');
$tenant = 'GLOBALE_SANTE';
check($worker->run() === 0 && count($posts) === 1, 'Worker submits');
check(latestRow()->state === 'tracking' && latestRow()->payload === '', 'Accepted request retained without patient payload');
$remoteStatus = 'read'; dueNow();
check($worker->run() === 0 && latestRow()->remote_status === 'read' && latestRow()->state === 'done', 'Read status reconciled');
check(count($posts) === 1 && $polls === 1, 'Polling never resends');

resetQueue(); capture(); $posts = array(); $postCode = 0;
check($worker->run() === 0 && latestRow()->attempts == 1 && latestRow()->state === 'pending', 'Uncertain send saved for retry');
$config['template'] = 'changed_after_capture'; saveConfig($config); dueNow(); $postCode = 202;
check($worker->run() === 0 && count($posts) === 2 && $posts[0] === $posts[1], 'Retry uses exact saved body and idempotency key');
resetQueue(); capture(); $posts = array(); $patient->array_options['options_dolimednotif_whatsapp'] = 0;
check($worker->run() === 0 && latestRow()->state === 'cancelled' && count($posts) === 0, 'Revoked consent cancels unsent request');
$patient->array_options['options_dolimednotif_whatsapp'] = 1;
resetQueue(); capture(); $patient->phone_mobile = '+221770000002';
check($worker->run() === 0 && latestRow()->state === 'cancelled' && count($posts) === 0, 'Changed mobile cancels unsent request');
$patient->phone_mobile = '+221770000001';
resetQueue(); capture(); $postCode = 0; $worker->run(); dueNow(); $event->datep += 60;
check($worker->run() === 0 && latestRow()->state === 'review', 'Source change after uncertain send requires review');
$event->datep -= 60;
resetQueue(); capture(); $config['installation_id'] = 'different-site'; saveConfig($config); $posts = array();
check($worker->run() === 0 && latestRow()->last_error === 'destination_changed' && count($posts) === 0, 'Destination change blocks replay');
$config['enabled'] = false; saveConfig($config);
check(capture() === 0 && $worker->run() === 0 && count($posts) === 0, 'Disabled connector has no side effects');
echo $count." total assertions OK (module + client, ".$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
