# Dolimed Notif — rendez-vous RelaxIT Notify (0.3.0)

Module externe pour l’agenda Dolibarr : les patients sont des tiers, leur numéro WhatsApp est `Societe::phone_mobile`. Le module ajoute un champ supplémentaire **Accord WhatsApp — rendez-vous via RelaxIT**, décoché par défaut. Il ne modifie pas le cœur Dolibarr.

## Périmètre et compatibilité

La première version installable cible **Dolibarr 22.0.1**, à partir des fichiers `comm/action` et `societe` fournis. La classe d’agenda correspond à la version officielle. Une base commune est conservée ; les adaptateurs pour Dolibarr 11.0.3 et l’installation annoncée 9.0.5 restent à valider avant d’abaisser la version minimale du module. Les tags officiels de la série 9.0 s’arrêtent à 9.0.4.

Le client et le traitement sont testés sur PHP 5.6, 7.4, 8.2 et 8.4 avec SQLite, et sur MariaDB 10.11. Les objets Dolibarr sont simulés dans ces tests ; ils ne remplacent pas la recette dans une véritable installation. Le schéma livré cible MySQL/MariaDB avec tables InnoDB ; PostgreSQL n’est pas validé pour ce module (RelaxIT Notify garde sa propre base PostgreSQL).

Sources de référence : [création d’événement Dolibarr 22.0.1](https://github.com/Dolibarr/dolibarr/blob/22.0.1/htdocs/comm/action/class/actioncomm.class.php), [Dolibarr 11.0.3](https://github.com/Dolibarr/dolibarr/blob/11.0.3/htdocs/comm/action/class/actioncomm.class.php), [Dolibarr 9.0.4](https://github.com/Dolibarr/dolibarr/blob/9.0.4/htdocs/comm/action/class/actioncomm.class.php).

## Mise à jour depuis 0.2.0 : modifications, suppressions et rappel à 24 h

Cette version conserve les confirmations et ajoute les événements `ACTION_MODIFY` et `ACTION_DELETE`, plus un rappel local 24 heures avant le rendez-vous. Les nouvelles fonctions restent désactivées tant que `lifecycle_enabled` n'est pas strictement `true`.

### 1. Préparer RelaxIT en premier

Déployer la branche RelaxIT **`codex/appointment-lifecycle-templates`**, qui inclut l'image et le plafond local. Dans `/docker/relaxit-notify/app/.env`, conserver les valeurs validées et ajouter :

```dotenv
META_WHATSAPP_ADDITIONAL_TEMPLATES=globale_sante_modification_rdv,globale_sante_annulation_rdv,globale_sante_rappel_rdv
```

Les trois modèles doivent être approuvés dans le même WABA, en français, avec en-tête image et les mêmes trois variables que la confirmation. Conserver `META_WHATSAPP_TEMPLATE=globale_sante_confirmation_rdv`, `META_WHATSAPP_BODY_VARIABLES=patient_name,appointment_date,appointment_time`, la langue `fr` et l'URL de l'image. Refaire le cache, vérifier `relaxit:whatsapp-status`, puis redémarrer app/worker/scheduler/web suivant le guide de déploiement RelaxIT. Ne pas déplacer à nouveau la date d'activation : les demandes déjà capturées qui attendent leur rappel doivent rester admissibles.

### 2. Mettre à jour le module Dolimed

1. Sauvegarder la base, le dossier du module et `documents/dolimednotif/config.php`. Prévoir une courte fenêtre sans modification de l'agenda.
2. Désactiver la tâche **Dolimed Notif : confirmations et statuts**, puis désactiver le module. Ne pas supprimer la table ni la configuration.
3. Remplacer le contenu du dossier `htdocs/custom/dolimednotif` par l'archive **module_dolimednotif-0.3.0.zip**. Ne pas toucher aux dossiers `comm`/`societe` du cœur.
4. Réactiver le module. Son activation met à jour la table existante : type de message, version de rendez-vous, échéance et début de suivi. Les lignes, payloads chiffrés, références RelaxIT et clés d'idempotence 0.2 sont conservés. Cette migration MySQL/MariaDB a été testée sur MariaDB 10.11 et est réexécutable. En cas d'erreur de migration, laisser la tâche désactivée et conserver la sauvegarde ; ne pas vider la table pour contourner l'erreur.
5. Dans le fichier serveur existant `/var/www/dolimed/documents/dolimednotif/config.php`, ajouter à l'entrée de l'entité, sans remplacer ses clés ni son identifiant :

```php
'lifecycle_enabled' => true,
'updated_template' => 'globale_sante_modification_rdv',
'cancelled_template' => 'globale_sante_annulation_rdv',
'reminder_template' => 'globale_sante_rappel_rdv',
```

Conserver le modèle de confirmation et le mapping des trois variables. Les noms des quatre modèles doivent être distincts. Le fichier `config.example.php` est une illustration, jamais un fichier à copier par-dessus les secrets existants.

6. Réactiver la tâche planifiée existante et vérifier qu'elle avance chaque minute. Il n'y a pas de nouveau cron système à créer. Le journal affiche maintenant **Événement**, **Version** et **Prochaine exécution (heure serveur)**.

Le module reste ciblé sur Dolibarr 22.0.1. La compatibilité PHP commune ne signifie pas que les adaptateurs Dolibarr 11/9 sont validés.

### Règles appliquées

- **Modification** : un changement des dates/heures d'un rendez-vous suivi crée une nouvelle version. Une simple modification de note ne produit aucun message. Les messages locaux jamais tentés et l'ancien rappel sont annulés ; une nouvelle modification et un nouveau rappel sont préparés si le rendez-vous reste éligible. Revenir à une ancienne date produit une nouvelle clé, sans réutiliser un ancien message. Un passage vers un autre type/journée entière/date passée invalide les demandes locales sans envoyer un texte trompeur. Le changement de tiers est exclu de l'envoi automatique et exige un contrôle manuel.
- **Annulation** : la suppression de l'événement prépare une notification à partir de ses anciennes dates, dans la même transaction. Si aucune demande n'a encore été tentée, les messages sont simplement annulés localement, sans annoncer une annulation inutile. Une suppression annulée par rollback n'envoie rien. La disparition de l'événement est revérifiée avant envoi, ainsi que l'accord et le mobile actuel du tiers.
- **Rappel** : il est capturé à la création ou au changement de date et conservé chiffré dans Dolimed jusqu'à **date de début moins 24 heures**, puis transmis au passage suivant du cron. Un rendez-vous créé ou déplacé à moins de 24 heures ne reçoit pas de rappel immédiat. Le rappel expire une heure après son échéance locale : pas de rattrapage tardif. Un rendez-vous terminé à 100 %, supprimé, modifié ou sans consentement ne reçoit pas ce rappel.
- **Ordre et incertitude** : les nouveaux messages attendent que les précédents soient livrés/lus ou en échec définitif. Un envoi antérieur incertain sans référence impose une vérification manuelle (`previous_notification_review`). Les erreurs temporaires conservent le même corps et la même clé. Les lignes déjà acceptées par RelaxIT restent suivies même si l'agenda change. Une demande déjà partie ne peut pas être retirée ; la protection contre les données périmées s'applique avant transmission, pas à un message déjà pris en charge par RelaxIT/Meta.
- **Historique** : aucun balayage ni rappel rétroactif des rendez-vous anciens. Seuls les rendez-vous déjà capturés sont suivis lors d'une modification/suppression. Une ancienne ligne 0.2 peut servir de confirmation de référence ; pour sa première modification, il faut les anciennes valeurs fournies par `oldcopy` si aucune empreinte n'existe encore. Le formulaire agenda fourni expose ces valeurs. Sans elles, les demandes locales non tentées sont annulées et aucun nouveau message n'est deviné.
- **Consentement et volume** : la case d'accord et le mobile sont revérifiés avant chaque transmission. Confirmer avec le patient que son accord couvre confirmation, modification, annulation et rappel. Le quota local RelaxIT englobe tous ces envois : un rendez-vous avec confirmation et rappel consomme deux tentatives. Les jours/heures sont calculés sur l'instant du rendez-vous, puis formatés en `Africa/Dakar` pour le texte.

### Recette avant usage courant

1. Créer un rendez-vous fictif à plus de 48 heures avec accord WhatsApp : une confirmation et un rappel doivent apparaître ; seule la confirmation part immédiatement.
2. Après réception/livraison de la confirmation, changer l'heure. Vérifier un message de modification, l'annulation de l'ancien rappel et la nouvelle échéance. Une modification de note seule ne doit rien ajouter.
3. Après livraison de la modification, supprimer le rendez-vous. Vérifier l'annulation WhatsApp et l'absence de rappel ultérieur. Vérifier les statuts dans les deux applications.
4. Pour observer le rappel rapidement sans modifier la base, créer un autre rendez-vous fictif **dans 24 heures et 5 minutes** : la confirmation part maintenant, le rappel au passage du cron environ cinq minutes plus tard. Tester séparément un rendez-vous dans deux heures : aucun rappel 24 h ne doit être créé.
5. Retirer l'accord d'un tiers fictif avant l'échéance : le rappel ne doit pas partir. Vérifier aussi suppression avant la toute première tentative : aucune confirmation ni annulation envoyée.

Pour suspendre uniquement ces nouvelles fonctions, mettre `lifecycle_enabled` à `false`. Les demandes nouvelles non transmises passent à « À vérifier » et ne repartent pas toutes seules à la réactivation ; les références connues restent suivies. Le code 0.3 et son schéma doivent rester ensemble : ne pas réinstaller 0.2 sur la table migrée. Un retour complet nécessite la sauvegarde cohérente du module et de la base, avec traitement des messages envoyés depuis la sauvegarde pour éviter les doublons.

## Comportement des confirmations

- À la création : `ACTION_CREATE`, type `AC_RDV`, date future, heure précise (pas de journée entière), tiers lié par `socid` dans la même entité et accord WhatsApp coché. Les contacts, le téléphone fixe, les événements automatiques et l’historique ne sont pas utilisés.
- Mobile international explicite : `+221…` ou `00221…`. Espaces, points, tirets et parenthèses sont retirés. Aucun pays n’est deviné pour un numéro local.
- Le trigger enregistre la demande dans la transaction du rendez-vous ; aucun appel réseau avant le commit. Un rollback supprime aussi la demande. Une erreur de stockage/configuration lors de la capture empêche la création du rendez-vous avec un message générique, afin de ne pas perdre silencieusement une confirmation attendue.
- Corps et clé d’idempotence stables par installation/entité/rendez-vous/type de message/version. Le payload patient est chiffré (AES-256-CBC + HMAC), puis effacé localement dès que RelaxIT fournit une référence. Les références techniques sont conservées pour empêcher les doublons.
- La tâche planifiée vérifie `/api/v1/me` et refuse tout autre tenant ou application que ceux configurés. Elle traite au maximum dix demandes par passage, avec un verrou de cinq minutes, et consulte les statuts toutes les cinq minutes.
- Avant chaque tentative d’envoi, nouvelle vérification de l’accord, du mobile, du tiers, des dates et du type. Une modification provoque une annulation avant envoi, ou un état « À vérifier » si une tentative était déjà incertaine. Une confirmation déjà acceptée par RelaxIT ne peut pas être retirée par ce module.
- Les erreurs réseau/408/429/5xx peuvent être retentées avec le même corps et la même clé, cinq tentatives au maximum. Une réponse perdue ne crée pas une nouvelle demande. Les erreurs permanentes nécessitent une vérification. Pas de bouton de renvoi ni de nouvelle clé automatique.
- Le suivi s’arrête sur `read`, `failed`, `blocked`, ou après sept jours (« À vérifier »). Un statut `delivered` reste suivi pour recevoir `read`. Le statut d’envoi incertain n’entraîne jamais de nouvel envoi si la référence RelaxIT est connue.

La création d’événements récurrents peut créer plusieurs rendez-vous, donc plusieurs confirmations éligibles. Le clone est traité comme une nouvelle création s’il déclenche `ACTION_CREATE`. Les modifications, suppressions et rappels sont optionnels, selon les règles de la version 0.3 ci-dessus.

## Installation pilote sur Dolibarr 22

1. Sauvegarder l’installation et la base. Utiliser une instance de recette et un tiers fictif au mobile autorisé par le pilote RelaxIT.
2. Extraire le répertoire `dolimednotif` de l’archive dans `htdocs/custom/`. Le chemin final doit être `htdocs/custom/dolimednotif/core/modules/modDolimedNotif.class.php`. Ne pas remplacer `comm` ni `societe`.
3. Vérifier que le numéro de module privé `507321` n’est pas déjà utilisé par un autre module installé. Activer **Tiers**, **Agenda**, **Travaux planifiés**, puis **Dolimed Notif** dans Configuration → Modules/Applications. Le module crée la table et la case sur le tiers ; il conserve ces données lors d’une désactivation.
4. Copier `config.example.php` vers **`DOL_DATA_ROOT/dolimednotif/config.php`**, dans le répertoire documents hors de la racine web. `DOL_DATA_ROOT` est le chemin des documents défini par l’installation Dolibarr ; ce n’est pas le dossier du module. Restreindre la lecture aux comptes PHP et cron. Ne pas versionner ce fichier.
5. Modifier l’entrée correspondant à l’entité Dolibarr (souvent `1`). Renseigner la clé API **RelaxIT** créée pour l’application `dolibarr`, le tenant attendu et un identifiant d’installation stable et unique. Une copie de recette doit avoir son propre identifiant et rester désactivée au départ.
6. Générer la clé de chiffrement avec `openssl rand -hex 32` et la placer dans `encryption_key`. La sauvegarder avec la configuration ; ne pas la remplacer tant que des demandes sont en attente. PHP doit disposer de cURL, OpenSSL et de certificats CA valides.
7. Utiliser le modèle de confirmation approuvé et son mapping de variables. Les variables autorisées sont `patient_name`, `appointment_reference`, `appointment_date`, `appointment_time`, `clinic_name`. Les clés du tableau `variables` doivent correspondre aux noms attendus par RelaxIT ; son ordre Meta est défini côté RelaxIT. Aucun titre, note ou diagnostic n’est exporté.
8. Passer `enabled` à `true` uniquement pour l’essai. Dans Travaux planifiés, activer **Dolimed Notif : confirmations et statuts** et vérifier que le cron système Dolibarr fonctionne déjà. La tâche est créée désactivée. Elle utilise l’entité courante configurée par Dolibarr ; prévoir une exécution correcte par entité si MultiCompany est utilisé. Les tiers partagés d’une autre entité sont exclus de ce premier pilote.

La roue de configuration du module affiche un journal administrateur limité à l’entité courante, sans nom ni numéro de patient ni secret. Recharger la page pour voir les changements. L’absence du fichier de configuration ou `enabled=false` désactive capture et traitement.

## Recette

1. Sur un tiers fictif, saisir le mobile international du destinataire de test et cocher l’accord WhatsApp. Créer un rendez-vous `AC_RDV` futur associé à ce tiers.
2. Ouvrir la configuration Dolimed Notif : la demande doit apparaître « En attente ». Exécuter la tâche planifiée ; la référence RelaxIT doit apparaître. Ouvrir le message sur le téléphone et vérifier ensuite `Lue` après le prochain contrôle de statut (jusqu’à cinq minutes).
3. Créer un second rendez-vous avec un tiers sans accord : aucune demande ne doit être créée. Tester aussi un mobile vide/local, un autre type d’événement et une modification de date avant l’exécution du worker.
4. Vérifier les droits : seule une session administrateur accède au journal du module. La case d’accord utilise les droits habituels de modification des tiers ; ce jalon ne fournit pas un historique dédié des preuves de consentement.

Une réponse `http_401` du connecteur concerne la clé RelaxIT. Un échec Meta est consultable dans la notification RelaxIT. `destination_changed`, `source_changed`, `retry_window_exhausted` et les états « À vérifier » exigent une vérification manuelle ; ne pas vider la table pour relancer les messages.

La table conserve les références et les payloads encore nécessaires aux tentatives ou à leur examen. Définir une politique de conservation avant usage réel ; ne pas purger les lignes d’idempotence sans traiter le risque de doublon. Les sauvegardes peuvent contenir les anciens payloads chiffrés.

## Tests et archive

```bash
php tests/module.php
python3 scripts/package.py /tmp/module_dolimednotif-0.3.0.zip
```

Les tests n’appellent ni Meta ni la production. Le packaging utilise une liste explicite de fichiers du module et exclut tests, Git et secrets locaux. Pour les tests MariaDB, utiliser une base jetable avec `DOLIMED_TEST_DSN`, `DOLIMED_TEST_USER`, `DOLIMED_TEST_PASSWORD` ; le test supprime uniquement sa table `test_dolimednotif_outbox` dans cette base.
