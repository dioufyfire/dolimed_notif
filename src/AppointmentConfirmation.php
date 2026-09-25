<?php

namespace Relaxit\DolimedNotif;

/** Maps already selected, consented appointments; does not infer medical fields from an agenda event. */
class AppointmentConfirmation
{
    public static function prepare($installationId, $entityId, $appointmentId, $recipient, $consented, $template, array $variables)
    {
        foreach (array($installationId, $entityId, $appointmentId) as $id) {
            if ((!is_string($id) && !is_int($id)) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', (string) $id)) {
                throw new \InvalidArgumentException('Identifiant source invalide.');
            }
        }
        if ($consented !== true) {
            throw new \InvalidArgumentException('Accord WhatsApp requis pour cette confirmation.');
        }
        if (!is_string($recipient) || !preg_match('/\A\+[1-9][0-9]{7,14}\z/', $recipient)) {
            throw new \InvalidArgumentException('Numéro international explicite requis.');
        }
        if (!is_string($template) || !preg_match('/\A[a-z][a-z0-9_]{0,99}\z/', $template)) {
            throw new \InvalidArgumentException('Modèle invalide.');
        }
        if (count($variables) > 30) {
            throw new \InvalidArgumentException('Trop de variables.');
        }
        foreach ($variables as $name => $value) {
            if (!preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', (string) $name)
                || !is_string($value) || trim($value) === '' || !preg_match('//u', $value)
                || preg_match_all('/./us', $value) > 1000) {
                throw new \InvalidArgumentException('Variable invalide.');
            }
        }
        ksort($variables);
        $source = json_encode(array((string) $installationId, (string) $entityId, (string) $appointmentId, 'appointment_created_v1'));
        $reference = 'dolimed-'.hash('sha256', $source);
        return array(
            'idempotency_key' => $reference,
            'payload' => array(
                'recipient' => $recipient,
                'channel' => 'whatsapp',
                'template' => $template,
                'variables' => $variables,
                'external_reference' => $reference
            )
        );
    }
}
