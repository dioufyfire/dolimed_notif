<?php
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once __DIR__.'/../../class/bootstrap.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class InterfaceDolimedNotifAppointment extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = 'DolimedNotifAppointment';
        $this->family = 'dolimednotif';
        $this->description = 'Confirmation des rendez-vous via RelaxIT Notify';
        $this->version = '0.2.0';
        $this->picto = 'email';
    }
    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        if (empty($conf->dolimednotif->enabled) || $action !== 'ACTION_CREATE' || empty($object->type_code)
            || $object->type_code !== 'AC_RDV' || empty($object->socid)) {
            return 0;
        }
        try {
            $config = \Relaxit\DolimedNotif\ModuleConfig::load((int) $conf->entity);
            if (empty($config['enabled'])) {
                return 0;
            }
            $patient = new Societe($this->db);
            if ($patient->fetch($object->socid) <= 0 || $patient->fetch_optionals() < 0) {
                throw new RuntimeException('patient_unavailable');
            }
            $now = time();
            $prepared = \Relaxit\DolimedNotif\AgendaAdapter::prepare($object, $patient, $conf->entity, $config, $now);
            if ($prepared === null) {
                return 0;
            }
            $outbox = new \Relaxit\DolimedNotif\Outbox($this->db, $conf->entity);
            $outbox->enqueue($object->id, $patient->id, $prepared, $config, $now);
            return 1;
        } catch (Exception $exception) {
            // No upstream error, patient data or secret in the Dolibarr log.
            $this->error = 'Dolimed Notif : impossible de préparer la confirmation. Vérifier la configuration et la base.';
            $this->errors = array($this->error);
            dol_syslog($this->error, LOG_ERR);
            return -1;
        }
    }
}
