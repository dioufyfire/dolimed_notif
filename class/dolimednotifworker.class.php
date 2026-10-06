<?php
require_once __DIR__.'/bootstrap.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

class DolimedNotifWorker
{
    public $output = '';
    public $error = '';
    private $db;
    private $clientFactory;
    public function __construct($db, $clientFactory = null)
    {
        $this->db = $db;
        $this->clientFactory = $clientFactory;
    }
    public function run()
    {
        global $conf;
        $this->output = '';
        $this->error = '';
        if (empty($conf->dolimednotif->enabled)) {
            return 0;
        }
        try {
            $entity = (int) $conf->entity;
            $config = \Relaxit\DolimedNotif\ModuleConfig::load($entity);
            if (empty($config['enabled'])) {
                $this->output = 'Envois désactivés.';
                return 0;
            }
            $client = $this->clientFactory ? call_user_func($this->clientFactory, $config)
                : new \Relaxit\DolimedNotif\RelaxitClient($config['url'], $config['api_key']);
            $identity = $client->identity();
            if (!$identity['ok'] || $identity['data']['tenant']['code'] !== $config['tenant_code']
                || $identity['data']['application'] !== 'dolibarr') {
                $this->error = 'Identité API incorrecte ou indisponible. Aucun envoi effectué.';
                return -1;
            }
            $outbox = new \Relaxit\DolimedNotif\Outbox($this->db, $entity);
            $cipher = new \Relaxit\DolimedNotif\PayloadCipher($config['encryption_key']);
            $count = 0;
            foreach ($outbox->due(time()) as $row) {
                $token = bin2hex(openssl_random_pseudo_bytes(32));
                if (!$outbox->claim($row, time(), $token)) {
                    continue;
                }
                if ($row->destination_hash !== $config['binding']) {
                    $outbox->finish($row, $token, array('state' => 'review', 'last_error' => 'destination_changed'));
                    continue;
                }
                if ((!$row->remote_id && (int) $row->attempts >= 5) || ($row->remote_id && time() - (int) ($row->accepted_at ?: $row->created_at) > 7 * 86400)) {
                    $outbox->finish($row, $token, array('state' => 'review', 'last_error' => 'retry_window_exhausted'));
                    continue;
                }
                if (!$row->remote_id) {
                    if ($row->event_kind !== 'created' && empty($config['lifecycle_enabled'])) {
                        $outbox->finish($row, $token, array('state' => 'review', 'last_error' => 'lifecycle_disabled'));
                        continue;
                    }
                    if ((int) $row->expires_at > 0 && time() >= (int) $row->expires_at) {
                        $outbox->finish($row, $token, array('state' => (int) $row->attempts > 0 ? 'review' : 'cancelled', 'last_error' => 'notification_expired', 'payload' => ''));
                        continue;
                    }
                    $dependency = $outbox->dependency($row);
                    if ($dependency !== 'ready') {
                        $outbox->finish($row, $token, array('state' => $dependency === 'review' ? 'review' : 'pending',
                            'last_error' => 'previous_notification_'.$dependency, 'next_attempt' => time() + 60));
                        continue;
                    }
                    $prepared = $cipher->decrypt($row->payload);
                    if ($row->event_kind === 'reminder' && time() < (int) $prepared['source']['datep'] - 86400) {
                        $outbox->finish($row, $token, array('next_attempt' => (int) $prepared['source']['datep'] - 86400));
                        continue;
                    }
                    $event = new ActionComm($this->db);
                    $patient = new Societe($this->db);
                    $found = $event->fetch($row->fk_action);
                    if ($found < 0 || $patient->fetch($row->fk_soc) < 0 || $patient->fetch_optionals() < 0) {
                        throw new RuntimeException('source_unavailable');
                    }
                    $eligible = $row->event_kind === 'cancelled'
                        ? ($found == 0 && \Relaxit\DolimedNotif\AgendaAdapter::cancellationEligible($patient, $entity, $prepared['source'], time()))
                        : ($found > 0 && \Relaxit\DolimedNotif\AgendaAdapter::stillEligible($event, $patient, $entity, $prepared['source'], time()));
                    if ($row->event_kind === 'reminder' && isset($event->percentage) && (int) $event->percentage >= 100) {
                        $eligible = false;
                    }
                    if (!$eligible) {
                        $outbox->finish($row, $token, array('state' => (int) $row->attempts > 0 ? 'review' : 'cancelled', 'last_error' => 'source_changed', 'payload' => ''));
                        continue;
                    }
                    // Fenced reservation: a concurrent agenda change can cancel an unsent row.
                    if (!$outbox->markAttempt($row, $token)) { continue; }
                    $row->attempts = (int) $row->attempts + 1;
                    $result = $client->submit($prepared['payload'], $prepared['idempotency_key']);
                } else {
                    $result = $client->status($row->remote_id);
                }
                $count++;
                if (!$result['ok']) {
                    $retry = $result['retryable'] && ($row->remote_id || (int) $row->attempts < 5);
                    $delay = max(60, min(3600, 60 * pow(2, min(6, (int) $row->attempts))), (int) $result['retry_after']);
                    $outbox->finish($row, $token, array('state' => $retry ? ($row->remote_id ? 'tracking' : 'pending') : 'review',
                        'last_error' => $result['error'], 'next_attempt' => time() + (int) $delay));
                    // Stop this batch for rate limiting, access failures, or unavailable service.
                    if ($result['http_status'] === 429 || $result['http_status'] === 401 || $result['http_status'] === 403 || $result['http_status'] === 0 || $result['http_status'] >= 500) {
                        break;
                    }
                    continue;
                }
                $data = $result['data']['data'];
                $done = in_array($data['status'], array('read', 'failed', 'blocked'), true);
                $acceptedAt = (int) ($row->accepted_at ?: ($row->remote_id ? $row->created_at : time()));
                $expired = time() - $acceptedAt > 7 * 86400;
                $outbox->finish($row, $token, array('remote_id' => $data['id'], 'remote_status' => $data['status'],
                    'state' => $done ? 'done' : ($expired ? 'review' : 'tracking'), 'last_error' => $expired && !$done ? 'tracking_expired' : null,
                    'next_attempt' => time() + 300, 'payload' => '', 'accepted_at' => $acceptedAt));
            }
            $this->output = $count.' demande(s) traitée(s).';
            return 0;
        } catch (Exception $exception) {
            $this->error = 'Traitement interrompu. Vérifier la configuration, la clé de chiffrement et la base.';
            return -1;
        }
    }
}
