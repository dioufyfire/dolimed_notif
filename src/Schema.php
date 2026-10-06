<?php
namespace Relaxit\DolimedNotif;

class Schema
{
    public static function upgrade($db)
    {
        $table = MAIN_DB_PREFIX.'dolimednotif_outbox';
        if ($db->query('SELECT event_kind,revision,source_hash,expires_at,accepted_at FROM '.$table.' WHERE 1=0')) {
            return true;
        }
        // Activation only; never execute DDL inside an appointment transaction.
        return (bool) $db->query("ALTER TABLE ".$table.
            " ADD event_kind varchar(16) NOT NULL DEFAULT 'created',".
            " ADD revision integer NOT NULL DEFAULT 1, ADD source_hash varchar(64) NULL,".
            " ADD expires_at integer NOT NULL DEFAULT 0, ADD accepted_at integer NOT NULL DEFAULT 0,".
            " DROP INDEX uk_dolimednotif_event,".
            " ADD UNIQUE KEY uk_dolimednotif_event (entity,fk_action,event_kind,revision)");
    }
}
