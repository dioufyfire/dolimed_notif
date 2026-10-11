<?php
$loaded = 0;
foreach (array(__DIR__.'/../../../main.inc.php', __DIR__.'/../../main.inc.php') as $candidate) {
    if (is_file($candidate)) {
        $loaded = require $candidate;
        break;
    }
}
if (!$loaded) {
    die('Dolibarr bootstrap unavailable');
}
if (!$user->admin || empty($conf->dolimednotif->enabled)) {
    accessforbidden();
}
require_once __DIR__.'/../class/bootstrap.php';
llxHeader('', 'Dolimed Notif');
print '<h1>Dolimed Notif</h1>';
try {
    $config = \Relaxit\DolimedNotif\ModuleConfig::load((int) $conf->entity);
    print '<p>Envois : <strong>'.(!empty($config['enabled']) ? 'activés' : 'désactivés').'</strong>.</p>';
} catch (Exception $exception) {
    print '<p>Configuration incomplète ou invalide.</p>';
}
print '<p>Configuration serveur : documents/dolimednotif/config.php. La tâche planifiée doit être activée séparément. '
    .'Seuls les rendez-vous futurs AC_RDV avec mobile international et accord WhatsApp sont retenus.</p>';
print '<p>Les modifications de date, suppressions et rappels à 24 h nécessitent lifecycle_enabled=true. Les rappels dépassés de plus d’une heure ne sont pas envoyés.</p>';
print '<p>Le journal ne se rafraîchit pas automatiquement. Les statuts sont consultés toutes les cinq minutes. Aucun bouton ne renvoie un message.</p>';
try {
    $summary = (new \Relaxit\DolimedNotif\Outbox($db, (int) $conf->entity))->summary(time());
    print '<h2>File locale — toutes dates</h2><table class="noborder centpercent"><tr class="liste_titre"><td>À vérifier</td><td>Échecs ou blocages RelaxIT</td><td>Rappels en attente</td><td>Rappels dépassés ou expirés avant transmission</td></tr><tr>';
    foreach ($summary as $value) { print '<td><strong>'.(int) $value.'</strong></td>'; }
    print '</tr></table><p>Ces compteurs couvrent uniquement cette entité. Les rappels annulés à la suite d’un changement ne sont pas des rappels expirés. Les états historiques restent comptés ; ce ne sont pas des alertes acquittées. Recharger la page pour actualiser.</p>';
} catch (Exception $exception) {
    print '<p>Récapitulatif indisponible. Vérifier la migration du module.</p>';
}
$sql = 'SELECT fk_action, event_kind, revision, state, remote_id, remote_status, last_error, attempts, next_attempt FROM '.MAIN_DB_PREFIX.'dolimednotif_outbox WHERE entity='.(int) $conf->entity.' ORDER BY rowid DESC LIMIT 50';
$result = $db->query($sql);
if ($result) {
    print '<table class="noborder centpercent"><tr class="liste_titre"><td>Rendez-vous</td><td>Événement</td><td>Version</td><td>Traitement</td><td>Référence RelaxIT</td><td>Statut</td><td>Diagnostic</td><td>Tentatives</td><td>Prochaine exécution (heure serveur)</td></tr>';
    $labels = array('pending' => 'En attente', 'tracking' => 'Suivi', 'done' => 'Terminé', 'review' => 'À vérifier', 'cancelled' => 'Annulé avant envoi',
        'queued' => 'En file', 'submitted' => 'Acceptée par Meta', 'sent' => 'Envoyée', 'delivered' => 'Livrée', 'read' => 'Lue', 'failed' => 'Échec', 'blocked' => 'Bloquée');
    $kinds = array('created' => 'Confirmation', 'updated' => 'Modification', 'cancelled' => 'Annulation', 'reminder' => 'Rappel 24 h');
    while ($row = $db->fetch_object($result)) {
        print '<tr>';
        foreach (array($row->fk_action, isset($kinds[$row->event_kind]) ? $kinds[$row->event_kind] : $row->event_kind, $row->revision, isset($labels[$row->state]) ? $labels[$row->state] : $row->state, $row->remote_id,
            isset($labels[$row->remote_status]) ? $labels[$row->remote_status] : $row->remote_status, $row->last_error, $row->attempts, in_array($row->state, array('pending', 'tracking'), true) ? date('d/m/Y H:i', $row->next_attempt) : '') as $value) {
            print '<td>'.dol_escape_htmltag((string) $value).'</td>';
        }
        print '</tr>';
    }
    print '</table>';
} else {
    print '<p>Journal indisponible. Vérifier l’activation du module et sa migration.</p>';
}
llxFooter();
$db->close();
