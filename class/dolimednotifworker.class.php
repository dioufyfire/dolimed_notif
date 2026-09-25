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
                if ((!$row->remote_id && (int) $row->attempts >= 5) || ($row->remote_id && time() - (int) $row->created_at > 7 * 86400)) {
                    $outbox->finish($row, $token, array('state' => 'review', 'last_error' => 'retry_window_exhausted'));
                    continue;
                }
                if (!$row->remote_id) {
                    $prepared = $cipher->decrypt($row->payload);
                    $event = new ActionComm($this->db);
                    $patient = new Societe($this->db);
                    if ($event->fetch($row->fk_action) <= 0 || $patient->fetch($row->fk_soc) <= 0 || $patient->fetch_optionals() < 0
                        || !\Relaxit\DolimedNotif\AgendaAdapter::stillEligible($event, $patient, $entity, $prepared['source'], time())) {
                        $outbox->finish($row, $token, array('state' => (int) $row->attempts > 0 ? 'review' : 'cancelled', 'last_error' => 'source_changed', 'payload' => ''));
                        continue;
                    }
                    // Persist the attempt BEFORE HTTP: a crash may hide an accepted response.
                    $row->attempts = (int) $row->attempts + 1;
                    // Keep lease while marking the attempt, rather than releasing it before HTTP.
                    $sql = 'UPDATE '.MAIN_DB_PREFIX.'dolimednotif_outbox SET attempts='.(int) $row->attempts
                        .' WHERE rowid='.(int) $row->rowid.' AND entity='.$entity." AND lease_token='".$this->db->escape($token)."'";
                    if (!$this->db->query($sql)) {
                        throw new RuntimeException('database_unavailable');
                    }
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
                $expired = time() - (int) $row->created_at > 7 * 86400;
                $outbox->finish($row, $token, array('remote_id' => $data['id'], 'remote_status' => $data['status'],
                    'state' => $done ? 'done' : ($expired ? 'review' : 'tracking'), 'last_error' => $expired && !$done ? 'tracking_expired' : null,
                    'next_attempt' => time() + 300, 'payload' => ''));
            }
            $this->output = $count.' demande(s) traitée(s).';
            return 0;
        } catch (Exception $exception) {
            $this->error = 'Traitement interrompu. Vérifier la configuration, la clé de chiffrement et la base.';
            return -1;
        }
    }
}
