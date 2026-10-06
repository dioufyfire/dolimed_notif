<?php
namespace Relaxit\DolimedNotif;

class AppointmentLifecycle
{
    public static function fingerprint($event, array $config)
    {
        return hash_hmac('sha256', json_encode(array((int) $event->socid, (int) $event->datep,
            (int) $event->datef, (string) $event->type_code, (int) $event->fulldayevent)), $config['encryption_key']);
    }
    public static function capture($db, $action, $event, $entity, array $config, $now)
    {
        $outbox = new Outbox($db, $entity);
        $latest = $outbox->latest($event->id);
        if ($action !== 'ACTION_CREATE' && empty($config['lifecycle_enabled'])) {
            return 0;
        }
        if (!empty($event->entity) && (int) $event->entity !== (int) $entity) {
            return 0;
        }
        if ($latest && (int) $latest->fk_soc !== (int) $event->socid) {
            $outbox->invalidateUnsent($event->id);
            return 0;
        }
        $hash = self::fingerprint($event, $config);
        if ($action === 'ACTION_CREATE') {
            if ($latest) { return 1; }
            $kind = 'created';
            $revision = 1;
        } else {
            // No historical scan: only appointments already captured by this connector.
            if (!$latest || $latest->destination_hash !== $config['binding']) { return 0; }
            if ($action === 'ACTION_MODIFY') {
                if ($latest->source_hash === $hash || (!empty($event->oldcopy) && self::fingerprint($event->oldcopy, $config) === $hash)) {
                    return 0;
                }
                if (!$latest->source_hash && empty($event->oldcopy)) {
                    $outbox->invalidateUnsent($event->id);
                    return 0;
                }
                $kind = 'updated';
            } else {
                if ($latest->event_kind === 'cancelled') { return 0; }
                $kind = 'cancelled';
            }
            $revision = (int) $latest->revision + 1;
            $outbox->invalidateUnsent($event->id);
        }
        $patient = new \Societe($db);
        if (empty($event->socid) || $patient->fetch($event->socid) <= 0 || $patient->fetch_optionals() < 0) {
            if ($action === 'ACTION_CREATE' && empty($event->socid)) { return 0; }
            throw new \RuntimeException('patient_unavailable');
        }
        $eventConfig = $config;
        if ($kind !== 'created') { $eventConfig['template'] = $config[$kind.'_template']; }
        $prepared = AgendaAdapter::prepare($event, $patient, $entity, $eventConfig, $now);
        if ($prepared === null || ($kind === 'cancelled' && !$outbox->hasAttempts($event->id))) {
            if ($latest) { $outbox->recordSkipped($event->id, $event->socid, $kind, $revision, $hash, $config, $now); }
            return 0;
        }
        $outbox->enqueue($event->id, $patient->id, $prepared, $config, $now, $kind, $revision, null, (int) $event->datep, $hash);
        if (!empty($config['lifecycle_enabled']) && $kind !== 'cancelled' && (int) $event->datep - 86400 > $now) {
            $eventConfig['template'] = $config['reminder_template'];
            $reminder = AgendaAdapter::prepare($event, $patient, $entity, $eventConfig, $now);
            $due = (int) $event->datep - 86400;
            $outbox->enqueue($event->id, $patient->id, $reminder, $config, $now, 'reminder', $revision, $due, $due + 3600, $hash);
        }
        return 1;
    }
}
