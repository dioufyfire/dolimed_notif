<?php
namespace Relaxit\DolimedNotif;

class AgendaAdapter
{
    public static function mobile($phone)
    {
        $phone = preg_replace('/[\s().-]+/', '', (string) $phone);
        if (substr($phone, 0, 2) === '00') {
            $phone = '+'.substr($phone, 2);
        }
        return preg_match('/\A\+[1-9][0-9]{7,14}\z/', $phone) ? $phone : null;
    }
    public static function prepare($event, $patient, $entity, array $config, $now)
    {
        if ($event->type_code !== 'AC_RDV' || (int) $event->socid !== (int) $patient->id
            || (int) $patient->entity !== (int) $entity || (!empty($event->entity) && (int) $event->entity !== (int) $entity)) {
            return null;
        }
        $consent = isset($patient->array_options['options_dolimednotif_whatsapp']) ? $patient->array_options['options_dolimednotif_whatsapp'] : 0;
        $mobile = self::mobile(isset($patient->phone_mobile) ? $patient->phone_mobile : '');
        if (!in_array($consent, array(1, '1', true), true) || !$mobile || (int) $event->datep <= $now || !empty($event->fulldayevent)) {
            return null;
        }
        $date = new \DateTime('@'.(int) $event->datep);
        $date->setTimezone(new \DateTimeZone($config['timezone']));
        $available = array('patient_name' => $patient->name, 'appointment_reference' => 'RDV-'.(int) $event->id,
            'appointment_date' => $date->format('d/m/Y'), 'appointment_time' => $date->format('H:i'), 'clinic_name' => $config['clinic_name']);
        $variables = array();
        foreach ($config['variables'] as $name => $source) {
            if (!is_string($source) || !array_key_exists($source, $available)) {
                throw new \RuntimeException('variable_mapping_invalid');
            }
            $variables[$name] = (string) $available[$source];
        }
        $prepared = AppointmentConfirmation::prepare($config['installation_id'], $entity, $event->id, $mobile, true, $config['template'], $variables);
        $prepared['source'] = array('socid' => (int) $patient->id, 'datep' => (int) $event->datep,
            'datef' => (int) $event->datef, 'mobile' => $mobile);
        return $prepared;
    }
    public static function stillEligible($event, $patient, $entity, array $source, $now)
    {
        $consent = isset($patient->array_options['options_dolimednotif_whatsapp']) ? $patient->array_options['options_dolimednotif_whatsapp'] : 0;
        return $event->type_code === 'AC_RDV' && (int) $event->entity === (int) $entity
            && (int) $patient->entity === (int) $entity && (int) $event->socid === (int) $source['socid']
            && (int) $patient->id === (int) $source['socid'] && (int) $event->datep === (int) $source['datep']
            && (int) $event->datef === (int) $source['datef'] && (int) $event->datep > $now
            && empty($event->fulldayevent) && in_array($consent, array(1, '1', true), true)
            && self::mobile($patient->phone_mobile) === $source['mobile'];
    }
}
