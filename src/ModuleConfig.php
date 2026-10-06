<?php
namespace Relaxit\DolimedNotif;

class ModuleConfig
{
    public static function load($entity)
    {
        $file = DOL_DATA_ROOT.'/dolimednotif/config.php';
        if (!is_file($file)) {
            return array('enabled' => false);
        }
        $all = require $file;
        $config = is_array($all) && isset($all[$entity]) ? $all[$entity] : array();
        if (empty($config['enabled'])) {
            return array('enabled' => false);
        }
        foreach (array('url', 'api_key', 'tenant_code', 'installation_id', 'encryption_key', 'template', 'timezone', 'clinic_name') as $field) {
            if (empty($config[$field]) || !is_string($config[$field])) {
                throw new \RuntimeException('configuration_incomplete');
            }
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/i', $config['encryption_key']) || empty($config['variables']) || !is_array($config['variables'])) {
            throw new \RuntimeException('configuration_invalide');
        }
        $config['lifecycle_enabled'] = isset($config['lifecycle_enabled']) && $config['lifecycle_enabled'] === true;
        if ($config['lifecycle_enabled']) {
            foreach (array('updated_template', 'cancelled_template', 'reminder_template') as $field) {
                if (empty($config[$field]) || !is_string($config[$field]) || !preg_match('/\A[a-z][a-z0-9_]{0,99}\z/', $config[$field])) {
                    throw new \RuntimeException('lifecycle_configuration_invalid');
                }
            }
            if (count(array_unique(array($config['template'], $config['updated_template'], $config['cancelled_template'], $config['reminder_template']))) !== 4) {
                throw new \RuntimeException('lifecycle_templates_must_differ');
            }
        }
        new \DateTimeZone($config['timezone']);
        new RelaxitClient($config['url'], $config['api_key']);
        $config['binding'] = hash('sha256', json_encode(array(rtrim($config['url'], '/'), $config['tenant_code'], 'dolibarr', $config['installation_id'], (int) $entity)));
        return $config;
    }
}
