<?php
// The module suite supplies the isolated SQL database and fake transport.
$config['enabled'] = true;
$config['installation_id'] = 'test-install';
$config['template'] = 'appointment_confirmation';
$config['lifecycle_enabled'] = true;
$config['updated_template'] = 'appointment_changed';
$config['cancelled_template'] = 'appointment_cancelled';
$config['reminder_template'] = 'appointment_reminder';
saveConfig($config);
$patient->array_options['options_dolimednotif_whatsapp'] = 1;
$patient->phone_mobile = '+221770000001';
$postCode = 202; $remoteStatus = 'read'; $posts = array();
function lifecycle($action) { global $trigger, $event, $conf; return $trigger->runTrigger($action, $event, new stdClass(), new stdClass(), $conf); }
function rows() { global $pdo; return $pdo->query('SELECT * FROM test_dolimednotif_outbox ORDER BY rowid')->fetchAll(PDO::FETCH_OBJ); }
function freshEvent($seconds = 259200) {
    global $event;
    $event = (object) array('id' => 42, 'entity' => 1, 'socid' => 20, 'type_code' => 'AC_RDV', 'datep' => time() + $seconds, 'datef' => time() + $seconds + 3600, 'fulldayevent' => 0);
    $GLOBALS['events'] = array(42 => $event);
}
resetQueue(); freshEvent();
$pdo->beginTransaction(); capture(); $pdo->rollBack();
check(count(rows()) === 0, 'Lifecycle and reminder roll back together');
check(capture() === 1 && count(rows()) === 2, 'Create confirmation and one reminder');
$initial = rows();
check($initial[1]->event_kind === 'reminder' && (int) $initial[1]->next_attempt === $event->datep - 86400, 'Reminder is due 24 hours before appointment');
check($initial[1]->request_key !== $initial[0]->request_key, 'Reminder has its own stable key');
check(capture() === 1 && count(rows()) === 2, 'Duplicate creation creates no extra reminder');
$event->oldcopy = clone $event; $event->note = 'Private medical note';
check(lifecycle('ACTION_MODIFY') === 0 && count(rows()) === 2, 'Notes do not trigger an update');
$worker->run();
check(count($posts) === 1 && rows()[0]->remote_status === 'read', 'Confirmation sent, future reminder not sent');
$oldDate = $event->datep;
$event->oldcopy = clone $event; $event->datep += 86400; $event->datef += 86400;
check(lifecycle('ACTION_MODIFY') === 1 && count(rows()) === 4, 'Reschedule creates update and replacement reminder');
check(rows()[1]->state === 'cancelled', 'Old reminder invalidated');
check(lifecycle('ACTION_MODIFY') === 0 && count(rows()) === 4, 'Duplicate update ignored');
$worker->run();
check(count($posts) === 2 && json_decode($posts[1][1], true)['template'] === 'appointment_changed', 'Update sent with dedicated template');
$keyB = rows()[2]->request_key;
$event->oldcopy = clone $event; $event->datep = $oldDate; $event->datef -= 86400;
lifecycle('ACTION_MODIFY'); $worker->run();
check(count($posts) === 3 && rows()[4]->request_key !== $keyB && (int) rows()[4]->revision === 3, 'Returning to an earlier date uses a new revision');
unset($GLOBALS['events'][42]);
check(lifecycle('ACTION_DELETE') === 1, 'Deletion captures cancellation snapshot');
$worker->run();
check(count($posts) === 4 && json_decode($posts[3][1], true)['template'] === 'appointment_cancelled', 'Cancellation sent after agenda deletion');
check(latestRow()->remote_status === 'read' && latestRow()->payload === '', 'Cancellation is reconciled and payload cleared');
check(lifecycle('ACTION_DELETE') === 0, 'Duplicate deletion does not send again');

resetQueue(); freshEvent(); $posts = array(); capture(); unset($GLOBALS['events'][42]); lifecycle('ACTION_DELETE'); $worker->run();
check(count($posts) === 0, 'Deletion before any attempt sends neither confirmation nor cancellation');
check(count(array_filter(rows(), function ($row) { return $row->state === 'pending'; })) === 0, 'Deletion invalidates pending reminder');

resetQueue(); freshEvent(7200); capture();
check(count(rows()) === 1, 'No immediate reminder for appointments booked within 24 hours');

resetQueue(); freshEvent(86400 - 10); $posts = array();
$loaded = \Relaxit\DolimedNotif\ModuleConfig::load(1);
\Relaxit\DolimedNotif\AppointmentLifecycle::capture($db, 'ACTION_CREATE', $event, 1, $loaded, time() - 86400);
$worker->run();
check(count($posts) === 2 && json_decode($posts[1][1], true)['template'] === 'appointment_reminder', 'Due reminder sent after confirmation with fresh consent');
$worker->run(); check(count($posts) === 2, 'Repeated cron does not duplicate reminder');

resetQueue(); freshEvent(86400 - 7200); $posts = array();
\Relaxit\DolimedNotif\AppointmentLifecycle::capture($db, 'ACTION_CREATE', $event, 1, $loaded, time() - 86400);
$worker->run();
check(count($posts) === 1 && latestRow()->last_error === 'notification_expired', 'Reminder delayed over one hour is not sent late');

resetQueue(); freshEvent(); $posts = array(); capture(); $worker->run();
$event->oldcopy = clone $event; $event->datep += 7200; $event->datef += 7200; lifecycle('ACTION_MODIFY');
$patient->array_options['options_dolimednotif_whatsapp'] = 0; $worker->run();
check(count($posts) === 1 && rows()[2]->state === 'cancelled', 'Consent revocation blocks update');
$patient->array_options['options_dolimednotif_whatsapp'] = 1;

resetQueue(); freshEvent(); $posts = array(); capture(); $worker->run(); unset($GLOBALS['events'][42]); lifecycle('ACTION_DELETE');
$patient->phone_mobile = '+221770000002'; $worker->run();
check(count($posts) === 1 && latestRow()->state === 'cancelled', 'Changed mobile blocks cancellation to old number');
$patient->phone_mobile = '+221770000001';

resetQueue(); freshEvent(); $posts = array(); capture(); $postCode = 0; $worker->run();
$event->oldcopy = clone $event; $event->datep += 7200; $event->datef += 7200; lifecycle('ACTION_MODIFY');
dueNow(); $postCode = 202; $worker->run();
check(count($posts) === 1 && rows()[2]->state === 'review', 'Uncertain predecessor requires review rather than out-of-order update');

resetQueue(); freshEvent(); $posts = array(); capture(); $remoteStatus = 'queued'; $worker->run();
$event->oldcopy = clone $event; $event->datep += 7200; $event->datef += 7200; lifecycle('ACTION_MODIFY'); $worker->run();
check(count($posts) === 1 && rows()[2]->last_error === 'previous_notification_wait', 'Update waits for predecessor delivery');
$remoteStatus = 'read'; dueNow(); $worker->run();
check(count($posts) === 2, 'Update resumes after predecessor read');

resetQueue(); freshEvent(); capture(); $stale = rows()[0];
check($outbox->claim($stale, time(), 'race-worker'), 'Worker claims before concurrent change');
$event->oldcopy = clone $event; $event->datep += 7200; $event->datef += 7200; lifecycle('ACTION_MODIFY');
check(!$outbox->markAttempt($stale, 'race-worker'), 'Concurrent update fences unsent worker before HTTP');

resetQueue(); freshEvent(); $posts = array(); capture(); $event->entity = 2;
check(lifecycle('ACTION_DELETE') === 0 && rows()[0]->state === 'pending', 'Cross-entity event cannot invalidate another entity');
$event->entity = 1;

resetQueue(); freshEvent(); capture(); $pdo->beginTransaction();
$event->oldcopy = clone $event; $event->datep += 7200; $event->datef += 7200; lifecycle('ACTION_MODIFY'); $pdo->rollBack();
check(count(rows()) === 2 && rows()[1]->state === 'pending', 'Reschedule rollback restores reminder and removes update');

resetQueue(); freshEvent(); capture(); $worker->run(); unset($GLOBALS['events'][42]);
$pdo->beginTransaction(); lifecycle('ACTION_DELETE'); $pdo->rollBack();
check(count(rows()) === 2 && rows()[1]->state === 'pending', 'Deletion rollback removes cancellation and restores reminder');

resetQueue(); freshEvent(); capture(); $worker->run();
$event->oldcopy = clone $event; $event->datep = time() - 60; lifecycle('ACTION_MODIFY');
check(latestRow()->state === 'cancelled', 'Past date is recorded without sending');
$event->oldcopy = clone $event; $event->datep = $oldDate; lifecycle('ACTION_MODIFY');
check(latestRow()->event_kind === 'reminder', 'Future date restored after ineligible revision gets fresh reminder');

resetQueue(); freshEvent(); capture();
$config['lifecycle_enabled'] = false; saveConfig($config);
$event->oldcopy = clone $event; $event->datep += 7200;
check(lifecycle('ACTION_MODIFY') === 0 && count(rows()) === 2, 'Feature flag disables new lifecycle capture');
$config['lifecycle_enabled'] = true; $config['updated_template'] = $config['template']; saveConfig($config);
check(lifecycle('ACTION_MODIFY') === -1, 'Identical event templates rejected before use');
$config['updated_template'] = 'appointment_changed'; saveConfig($config);

resetQueue(); freshEvent(86400 - 10); $posts = array();
\Relaxit\DolimedNotif\AppointmentLifecycle::capture($db, 'ACTION_CREATE', $event, 1, $loaded, time() - 86400);
$event->percentage = 100; $worker->run();
check(count($posts) === 1 && latestRow()->state === 'cancelled', 'Completed appointment does not get a reminder');

resetQueue(); freshEvent(); capture(); $worker->run();
$config['installation_id'] = 'different-installation'; saveConfig($config);
$event->oldcopy = clone $event; $event->datep += 7200;
check(lifecycle('ACTION_MODIFY') === 0 && count(rows()) === 2, 'Changed destination never captures updates from old binding');
$config['installation_id'] = 'test-install'; saveConfig($config);

resetQueue(); freshEvent(86400 - 10); $posts = array();
\Relaxit\DolimedNotif\AppointmentLifecycle::capture($db, 'ACTION_CREATE', $event, 1, $loaded, time() - 30 * 86400);
$worker->run();
check(count($posts) === 2 && (int) latestRow()->accepted_at >= time() - 5, 'Long-lead reminder gets a fresh tracking window');

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
    $pdo->exec('DROP TABLE test_dolimednotif_outbox');
    $oldSchema = str_replace('llx_', MAIN_DB_PREFIX, file_get_contents(__DIR__.'/fixtures/outbox-v02.sql'));
    $pdo->exec($oldSchema);
    $pdo->exec("INSERT INTO test_dolimednotif_outbox (entity,fk_action,fk_soc,request_key,destination_hash,payload,state,remote_id,created_at,next_attempt) VALUES (1,42,20,'legacy-stable-key','binding','encrypted-payload','tracking','01K50000000000000000000000',100,200)");
    check(\Relaxit\DolimedNotif\Schema::upgrade($db), 'Version 0.2 schema upgraded on MariaDB');
    $legacy = latestRow();
    check($legacy->request_key === 'legacy-stable-key' && $legacy->payload === 'encrypted-payload' && $legacy->remote_id === '01K50000000000000000000000', 'Migration preserves existing payload, reference and idempotency');
    check($legacy->event_kind === 'created' && (int) $legacy->revision === 1, 'Legacy confirmation receives initial revision');
    check(\Relaxit\DolimedNotif\Schema::upgrade($db) && count(rows()) === 1, 'Schema upgrade is idempotent');
}
