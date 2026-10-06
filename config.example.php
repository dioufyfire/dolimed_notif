<?php
// Copy to DOL_DATA_ROOT/dolimednotif/config.php (outside the public web directory).
// One entry per Dolibarr entity; no real secrets in the repository.
return array(
    1 => array(
        'enabled' => false,
        'url' => 'https://notify.relaxit.pro',
        'api_key' => 'REPLACE_WITH_RELAXIT_KEY',
        'tenant_code' => 'GLOBALE_SANTE',
        'installation_id' => 'globale-sante-production',
        'encryption_key' => 'REPLACE_WITH_OPENSSL_RAND_HEX_32',
        'timezone' => 'Africa/Dakar',
        'clinic_name' => 'Globale Santé',
        'template' => 'globale_sante_confirmation_rdv',
        // Enable only after module upgrade and RelaxIT additional templates deployment.
        'lifecycle_enabled' => false,
        'updated_template' => 'globale_sante_modification_rdv',
        'cancelled_template' => 'globale_sante_annulation_rdv',
        'reminder_template' => 'globale_sante_rappel_rdv',
        'variables' => array(
            'patient_name' => 'patient_name',
            'appointment_date' => 'appointment_date',
            'appointment_time' => 'appointment_time'
        )
    )
);
