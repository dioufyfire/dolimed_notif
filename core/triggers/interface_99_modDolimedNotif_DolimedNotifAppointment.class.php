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
        $this->description = 'Notifications des rendez-vous via RelaxIT Notify';
        $this->version = '0.3.1';
        $this->picto = 'email';
    }
    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        if (empty($conf->dolimednotif->enabled) || !in_array($action, array('ACTION_CREATE', 'ACTION_MODIFY', 'ACTION_DELETE'), true)) {
            return 0;
        }
        if ($action === 'ACTION_CREATE' && (empty($object->type_code) || $object->type_code !== 'AC_RDV' || empty($object->socid))) {
            return 0;
        }
        try {
            $config = \Relaxit\DolimedNotif\ModuleConfig::load((int) $conf->entity);
            if (empty($config['enabled'])) {
                return 0;
            }
            return \Relaxit\DolimedNotif\AppointmentLifecycle::capture($this->db, $action, $object, (int) $conf->entity, $config, time());
        } catch (Exception $exception) {
            // No upstream error, patient data or secret in the Dolibarr log.
            $this->error = 'Dolimed Notif : impossible de préparer la notification. Vérifier la configuration et la base.';
            $this->errors = array($this->error);
            dol_syslog($this->error, LOG_ERR);
            return -1;
        }
    }
}
