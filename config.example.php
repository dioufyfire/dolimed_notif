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
        'template' => 'jaspers_market_order_confirmation_v1',
        'variables' => array(
            'customer_name' => 'patient_name',
            'order_reference' => 'appointment_reference',
            'order_date' => 'appointment_date'
        )
    )
);
