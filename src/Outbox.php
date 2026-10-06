<?php
namespace Relaxit\DolimedNotif;

class Outbox
{
    private $db;
    private $table;
    private $entity;
    public function __construct($db, $entity)
    {
        $this->db = $db;
        $this->table = MAIN_DB_PREFIX.'dolimednotif_outbox';
        $this->entity = (int) $entity;
    }
    private function query($sql)
    {
        $result = $this->db->query($sql);
        if (!$result) {
            throw new \RuntimeException('outbox_database_error');
        }
        return $result;
    }
    private function quote($value)
    {
        return $value === null ? 'NULL' : "'".$this->db->escape((string) $value)."'";
    }
    public function latest($eventId)
    {
        return $this->db->fetch_object($this->query('SELECT * FROM '.$this->table.' WHERE entity='.$this->entity.' AND fk_action='.(int) $eventId.
            " AND event_kind<>'reminder' ORDER BY revision DESC,rowid DESC LIMIT 1"));
    }
    public function invalidateUnsent($eventId)
    {
        $this->query('UPDATE '.$this->table." SET state='cancelled',last_error='source_changed',payload='',lease_until=0,lease_token=NULL WHERE entity=".$this->entity.
            ' AND fk_action='.(int) $eventId." AND state='pending' AND attempts=0 AND remote_id IS NULL");
    }
    public function enqueue($eventId, $patientId, array $prepared, array $config, $now, $kind = 'created', $revision = 1, $due = null, $expires = 0, $sourceHash = null)
    {
        // The caller owns the appointment transaction: do not begin or commit here.
        $exists = $this->query('SELECT rowid FROM '.$this->table.' WHERE entity='.$this->entity.' AND fk_action='.(int) $eventId.
            ' AND event_kind='.$this->quote($kind).' AND revision='.(int) $revision);
        if ($this->db->fetch_object($exists)) {
            return;
        }
        if ($kind !== 'created' || $revision !== 1) {
            $key = 'dolimed-'.hash('sha256', $prepared['idempotency_key'].':'.$kind.':'.(int) $revision);
            $prepared['idempotency_key'] = $key;
            $prepared['payload']['external_reference'] = $key;
        }
        $cipher = new PayloadCipher($config['encryption_key']);
        $this->query('INSERT INTO '.$this->table.' (entity,fk_action,fk_soc,request_key,destination_hash,payload,created_at,next_attempt,event_kind,revision,source_hash,expires_at) VALUES ('
            .$this->entity.','.(int) $eventId.','.(int) $patientId.','.$this->quote($prepared['idempotency_key']).','.$this->quote($config['binding']).','
            .$this->quote($cipher->encrypt($prepared)).','.(int) $now.','.(int) ($due === null ? $now : $due).','.$this->quote($kind).','.(int) $revision.','.$this->quote($sourceHash).','.(int) $expires.')');
    }
    public function dependency($row)
    {
        if ($row->event_kind === 'created') {
            return 'ready';
        }
        $result = $this->query('SELECT * FROM '.$this->table.' WHERE entity='.$this->entity.' AND fk_action='.(int) $row->fk_action.
            ' AND rowid<'.(int) $row->rowid.' ORDER BY rowid');
        while ($previous = $this->db->fetch_object($result)) {
            if ($previous->state === 'cancelled' && (int) $previous->attempts === 0) {
                continue;
            }
            if ($previous->state === 'review' && !$previous->remote_id) {
                return 'review';
            }
            if (in_array($previous->remote_status, array('read', 'delivered', 'failed', 'blocked'), true)) {
                continue;
            }
            if ($previous->state === 'review') {
                return 'review';
            }
            return 'wait';
        }
        return 'ready';
    }
    public function markAttempt($row, $token)
    {
        $result = $this->query('UPDATE '.$this->table.' SET attempts=attempts+1 WHERE rowid='.(int) $row->rowid.
            ' AND entity='.$this->entity.' AND lease_token='.$this->quote($token)." AND state='pending' AND remote_id IS NULL AND attempts=".(int) $row->attempts);
        return $this->db->affected_rows($result) === 1;
    }
    public function hasAttempts($eventId)
    {
        return (bool) $this->db->fetch_object($this->query('SELECT rowid FROM '.$this->table.' WHERE entity='.$this->entity.
            ' AND fk_action='.(int) $eventId.' AND (attempts>0 OR remote_id IS NOT NULL) LIMIT 1'));
    }
    public function recordSkipped($eventId, $patientId, $kind, $revision, $hash, array $config, $now)
    {
        $key = 'dolimed-'.hash('sha256', $config['binding'].':'.$eventId.':'.$kind.':'.$revision);
        $this->query('INSERT INTO '.$this->table.' (entity,fk_action,fk_soc,event_kind,revision,source_hash,request_key,destination_hash,payload,state,last_error,created_at,next_attempt) VALUES ('
            .$this->entity.','.(int) $eventId.','.(int) $patientId.','.$this->quote($kind).','.(int) $revision.','.$this->quote($hash).','.$this->quote($key).','.$this->quote($config['binding']).",'','cancelled','source_changed',".(int) $now.','.(int) $now.')');
    }
    public function due($now)
    {
        $result = $this->query('SELECT * FROM '.$this->table.' WHERE entity='.$this->entity." AND state IN ('pending','tracking') AND next_attempt<=".(int) $now.' AND lease_until<='.(int) $now.' ORDER BY next_attempt,rowid LIMIT 10');
        $rows = array();
        while ($row = $this->db->fetch_object($result)) {
            $rows[] = $row;
        }
        return $rows;
    }
    public function claim($row, $now, $token)
    {
        $result = $this->query('UPDATE '.$this->table.' SET lease_until='.(int) ($now + 300).',lease_token='.$this->quote($token)
            .' WHERE rowid='.(int) $row->rowid.' AND entity='.$this->entity.' AND lease_until<='.(int) $now
            .' AND next_attempt<='.(int) $now.' AND state='.$this->quote($row->state)
            .' AND remote_id '.($row->remote_id === null ? 'IS NULL' : '='.$this->quote($row->remote_id)));
        return $this->db->affected_rows($result) === 1;
    }
    public function finish($row, $token, array $values)
    {
        $allowed = array('state', 'remote_id', 'remote_status', 'attempts', 'last_error', 'next_attempt', 'payload', 'accepted_at');
        $set = array('lease_until=0', 'lease_token=NULL');
        foreach ($values as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw new \RuntimeException('outbox_field_invalid');
            }
            $set[] = $name.'='.$this->quote($value);
        }
        $this->query('UPDATE '.$this->table.' SET '.implode(',', $set).' WHERE rowid='.(int) $row->rowid.' AND entity='.$this->entity.' AND lease_token='.$this->quote($token));
    }
}
