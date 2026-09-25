<?php
require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modDolimedNotif extends DolibarrModules
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->numero = 507321; // Private module ID; check local collisions before installation.
        $this->rights_class = 'dolimednotif';
        $this->family = 'interface';
        $this->name = 'DolimedNotif';
        $this->description = 'Confirmations de rendez-vous via RelaxIT Notify';
        $this->version = '0.2.0';
        $this->const_name = 'MAIN_MODULE_DOLIMEDNOTIF';
        $this->picto = 'email';
        $this->module_parts = array('triggers' => 1);
        $this->dirs = array('/dolimednotif');
        $this->config_page_url = array('setup.php@dolimednotif');
        $this->langfiles = array('dolimednotif@dolimednotif');
        $this->depends = array('modSociete', 'modAgenda', 'modCron');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->phpmin = array(5, 6);
        // The shared client supports older PHP; this first installable adapter targets the supplied Dolibarr 22 source.
        $this->need_dolibarr_version = array(22, 0);
        $this->const = array();
        $this->rights = array();
        $this->menu = array();
        $this->cronjobs = array(array('label' => 'Dolimed Notif : confirmations et statuts', 'jobtype' => 'method',
            'class' => '/dolimednotif/class/dolimednotifworker.class.php', 'objectname' => 'DolimedNotifWorker', 'method' => 'run',
            'parameters' => '', 'comment' => 'File locale RelaxIT Notify', 'frequency' => 1, 'unitfrequency' => 60,
            'priority' => 50, 'status' => 0, 'test' => '$conf->dolimednotif->enabled', 'datestart' => time()));
    }
    public function init($options = '')
    {
        global $conf;
        if ($this->_load_tables('/dolimednotif/sql/') < 0) {
            return -1;
        }
        require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
        $extra = new ExtraFields($this->db);
        if ($extra->fetch_name_optionals_label('societe') < 0) {
            return -1;
        }
        if (!isset($extra->attributes['societe']['type']['dolimednotif_whatsapp'])) {
            $result = $extra->addExtraField('dolimednotif_whatsapp', 'Accord WhatsApp — rendez-vous via RelaxIT', 'boolean', 100, '', 'societe',
                0, 0, '0', '', 1, '', '1', 'Le patient accepte les confirmations de rendez-vous du cabinet via RelaxIT Notify.', '', $conf->entity);
            if ($result < 0) {
                return -1;
            }
        } elseif ($extra->attributes['societe']['type']['dolimednotif_whatsapp'] !== 'boolean') {
            $this->error = 'Le champ dolimednotif_whatsapp existe avec un type incompatible.';
            return -1;
        }
        return $this->_init(array(), $options);
    }
    public function remove($options = '')
    {
        // Preserve consent, queued requests, keys and idempotency records on deactivation.
        return $this->_remove(array(), $options);
    }
}
