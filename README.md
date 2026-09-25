# Dolimed Notif — confirmations de rendez-vous RelaxIT Notify

Module externe pour l’agenda Dolibarr : les patients sont des tiers, leur numéro WhatsApp est `Societe::phone_mobile`. Le module ajoute un champ supplémentaire **Accord WhatsApp — rendez-vous via RelaxIT**, décoché par défaut. Il ne modifie pas le cœur Dolibarr.

## Périmètre et compatibilité

La première version installable cible **Dolibarr 22.0.1**, à partir des fichiers `comm/action` et `societe` fournis. La classe d’agenda correspond à la version officielle. Une base commune est conservée ; les adaptateurs pour Dolibarr 11.0.3 et l’installation annoncée 9.0.5 restent à valider avant d’abaisser la version minimale du module. Les tags officiels de la série 9.0 s’arrêtent à 9.0.4.

Le client et le traitement sont testés sur PHP 5.6, 7.4, 8.2 et 8.4 avec SQLite, et sur MariaDB 10.11. Les objets Dolibarr sont simulés dans ces tests ; ils ne remplacent pas la recette dans une véritable installation. Le schéma livré cible MySQL/MariaDB avec tables InnoDB ; PostgreSQL n’est pas validé pour ce module (RelaxIT Notify garde sa propre base PostgreSQL).

Sources de référence : [création d’événement Dolibarr 22.0.1](https://github.com/Dolibarr/dolibarr/blob/22.0.1/htdocs/comm/action/class/actioncomm.class.php), [Dolibarr 11.0.3](https://github.com/Dolibarr/dolibarr/blob/11.0.3/htdocs/comm/action/class/actioncomm.class.php), [Dolibarr 9.0.4](https://github.com/Dolibarr/dolibarr/blob/9.0.4/htdocs/comm/action/class/actioncomm.class.php).

## Comportement

- Uniquement `ACTION_CREATE`, type `AC_RDV`, date future, heure précise (pas de journée entière), tiers lié par `socid` dans la même entité et accord WhatsApp coché. Les contacts, le téléphone fixe, les événements automatiques et l’historique ne sont pas utilisés.
- Mobile international explicite : `+221…` ou `00221…`. Espaces, points, tirets et parenthèses sont retirés. Aucun pays n’est deviné pour un numéro local.
- Le trigger enregistre la demande dans la transaction du rendez-vous ; aucun appel réseau avant le commit. Un rollback supprime aussi la demande. Une erreur de stockage/configuration lors de la capture empêche la création du rendez-vous avec un message générique, afin de ne pas perdre silencieusement une confirmation attendue.
- Corps et clé d’idempotence stables par installation/entité/rendez-vous. Le payload patient est chiffré (AES-256-CBC + HMAC), puis effacé localement dès que RelaxIT fournit une référence. Les références techniques sont conservées pour empêcher les doublons.
- La tâche planifiée vérifie `/api/v1/me` et refuse tout autre tenant ou application que ceux configurés. Elle traite au maximum dix demandes par passage, avec un verrou de cinq minutes, et consulte les statuts toutes les cinq minutes.
- Avant chaque tentative d’envoi, nouvelle vérification de l’accord, du mobile, du tiers, des dates et du type. Une modification provoque une annulation avant envoi, ou un état « À vérifier » si une tentative était déjà incertaine. Une confirmation déjà acceptée par RelaxIT ne peut pas être retirée par ce module.
- Les erreurs réseau/408/429/5xx peuvent être retentées avec le même corps et la même clé, cinq tentatives au maximum. Une réponse perdue ne crée pas une nouvelle demande. Les erreurs permanentes nécessitent une vérification. Pas de bouton de renvoi ni de nouvelle clé automatique.
- Le suivi s’arrête sur `read`, `failed`, `blocked`, ou après sept jours (« À vérifier »). Un statut `delivered` reste suivi pour recevoir `read`. Le statut d’envoi incertain n’entraîne jamais de nouvel envoi si la référence RelaxIT est connue.

La création d’événements récurrents peut créer plusieurs rendez-vous, donc plusieurs confirmations éligibles. Le clone est traité comme une nouvelle création s’il déclenche `ACTION_CREATE`. Aucun rappel, modification ou annulation de message n’est encore implémenté. Ne pas utiliser ce premier jalon comme un moteur complet de gestion des changements de rendez-vous.

## Installation pilote sur Dolibarr 22

1. Sauvegarder l’installation et la base. Utiliser une instance de recette et un tiers fictif au mobile autorisé par le pilote RelaxIT.
2. Extraire le répertoire `dolimednotif` de l’archive dans `htdocs/custom/`. Le chemin final doit être `htdocs/custom/dolimednotif/core/modules/modDolimedNotif.class.php`. Ne pas remplacer `comm` ni `societe`.
3. Vérifier que le numéro de module privé `507321` n’est pas déjà utilisé par un autre module installé. Activer **Tiers**, **Agenda**, **Travaux planifiés**, puis **Dolimed Notif** dans Configuration → Modules/Applications. Le module crée la table et la case sur le tiers ; il conserve ces données lors d’une désactivation.
4. Copier `config.example.php` vers **`DOL_DATA_ROOT/dolimednotif/config.php`**, dans le répertoire documents hors de la racine web. `DOL_DATA_ROOT` est le chemin des documents défini par l’installation Dolibarr ; ce n’est pas le dossier du module. Restreindre la lecture aux comptes PHP et cron. Ne pas versionner ce fichier.
5. Modifier l’entrée correspondant à l’entité Dolibarr (souvent `1`). Renseigner la clé API **RelaxIT** créée pour l’application `dolibarr`, le tenant attendu et un identifiant d’installation stable et unique. Une copie de recette doit avoir son propre identifiant et rester désactivée au départ.
6. Générer la clé de chiffrement avec `openssl rand -hex 32` et la placer dans `encryption_key`. La sauvegarder avec la configuration ; ne pas la remplacer tant que des demandes sont en attente. PHP doit disposer de cURL, OpenSSL et de certificats CA valides.
7. Conserver le modèle de démonstration et son mapping pour le pilote actuel. Il ne convient pas à des rendez-vous réels. Les variables autorisées sont `patient_name`, `appointment_reference`, `appointment_date`, `appointment_time`, `clinic_name`. Les clés du tableau `variables` doivent correspondre aux noms attendus par RelaxIT ; son ordre Meta est défini côté RelaxIT. Aucun titre, note ou diagnostic n’est exporté.
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
python3 scripts/package.py /tmp/module_dolimednotif-0.2.0.zip
```

Les tests n’appellent ni Meta ni la production. Le packaging utilise une liste explicite de fichiers du module et exclut tests, Git et secrets locaux. Pour les tests MariaDB, utiliser une base jetable avec `DOLIMED_TEST_DSN`, `DOLIMED_TEST_USER`, `DOLIMED_TEST_PASSWORD` ; le test supprime uniquement sa table `test_dolimednotif_outbox` dans cette base.
