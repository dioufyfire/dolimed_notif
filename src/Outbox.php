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
    public function enqueue($eventId, $patientId, array $prepared, array $config, $now)
    {
        // The caller owns the appointment transaction: do not begin or commit here.
        $exists = $this->query('SELECT rowid FROM '.$this->table.' WHERE entity='.$this->entity.' AND fk_action='.(int) $eventId);
        if ($this->db->fetch_object($exists)) {
            return;
        }
        $cipher = new PayloadCipher($config['encryption_key']);
        $this->query('INSERT INTO '.$this->table.' (entity,fk_action,fk_soc,request_key,destination_hash,payload,created_at,next_attempt) VALUES ('
            .$this->entity.','.(int) $eventId.','.(int) $patientId.','.$this->quote($prepared['idempotency_key']).','.$this->quote($config['binding']).','
            .$this->quote($cipher->encrypt($prepared)).','.(int) $now.','.(int) $now.')');
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
        $allowed = array('state', 'remote_id', 'remote_status', 'attempts', 'last_error', 'next_attempt', 'payload');
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
