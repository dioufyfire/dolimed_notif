# Dolimed Notif — connecteur RelaxIT Notify

Une base de code commune pour les installations Dolibarr/Dolimed, avec comme premier événement la confirmation de création d’un rendez-vous. Le transport HTTP ne dépend pas de Dolibarr. Les adaptations aux objets et transactions du logiciel métier seront isolées.

## État actuel

Ce dépôt contient le client API et la préparation d’une confirmation. **Ce n’est pas encore un module installable dans Dolibarr.** Aucun trigger, tâche planifiée ni envoi automatique n’est activé. Le code du module Dolimed créant les rendez-vous est nécessaire pour raccorder correctement le premier événement.

| Cible | Vérification effectuée |
|---|---|
| Dolibarr 22.0.1 | Source officielle : `ActionComm::create` appelle `ACTION_CREATE` avant le commit. |
| Dolibarr 11.0.3 | Même point d’extension et même contrainte de transaction. |
| Installation annoncée 9.0.5 | Tag officiel absent ; la série publiée s’arrête à 9.0.4. Version exacte et adaptations Dolimed à confirmer. |
| Client PHP commun | Syntaxe PHP 5.6 ; tests automatisés sur PHP 5.6, 7.4, 8.2 et 8.4. Cela ne certifie pas encore l’intégration Dolibarr. |

Une branche durable par version multiplierait les correctifs et les divergences. Préférer `main`, des versions publiées par tags, et de petits adaptateurs si les objets Dolimed diffèrent. La compatibilité syntaxique avec un ancien PHP ne constitue pas une recommandation d’exploitation de ce runtime.

Sources comparées : [Dolibarr 11.0.3](https://github.com/Dolibarr/dolibarr/blob/11.0.3/htdocs/comm/action/class/actioncomm.class.php), [Dolibarr 22.0.1](https://github.com/Dolibarr/dolibarr/blob/22.0.1/htdocs/comm/action/class/actioncomm.class.php), [Dolibarr 9.0.4](https://github.com/Dolibarr/dolibarr/blob/9.0.4/htdocs/comm/action/class/actioncomm.class.php).

## Contrat du connecteur

`src/AppointmentConfirmation.php` prépare le corps et une clé d’idempotence déterministe à partir de l’installation, de l’entité Dolibarr et du rendez-vous. L’identifiant d’installation doit être stable et différent entre sites ; une copie de production destinée à un test doit avoir une identité distincte et les envois désactivés. Les noms de variables et le modèle doivent correspondre au modèle Meta approuvé/configuré dans RelaxIT. L’accord WhatsApp doit être fourni explicitement ; aucun numéro national n’est transformé automatiquement.

`src/RelaxitClient.php` fournit :

- `identity()` : vérifier le tenant et l’application de la clé via `/api/v1/me`.
- `submit($payload, $idempotencyKey)` : créer ou retrouver une demande via `/api/v1/notifications`.
- `status($id)` : récupérer son statut, notamment `delivered` et `read`.

La clé à utiliser est celle de **RelaxIT Notify**, application `dolibarr`, jamais le token Meta. L’URL est `https://notify.relaxit.pro`. Configurer la clé côté serveur, hors du dépôt. Le client impose HTTPS avec vérification TLS, ne suit pas les redirections, limite les réponses à 1 Mio et n’effectue aucune relance implicite. Aucun test n’appelle Meta ou le serveur de production.

Chaque résultat contient `ok`, `http_status`, `retryable`, `retry_after`, `error` et `data`. `ok` signifie que la requête API a réussi, pas que le message est livré : consulter `data.data.status`. Les erreurs distantes sont remplacées par un code générique et leur corps n’est pas exposé. Une erreur HTTP 409 exige une investigation du contenu sauvegardé, pas une nouvelle clé automatique. Une erreur 401/403 nécessite la correction des accès.

## Raccordement restant

1. Identifier l’objet rendez-vous Dolimed, sa table, son événement de création, le téléphone choisi et le champ d’accord WhatsApp. Ne pas traiter tous les `ACTION_CREATE` comme des rendez-vous médicaux.
2. Enregistrer la demande dans une table locale de sortie, dans la transaction du rendez-vous. Ne pas appeler HTTP dans le trigger, qui peut précéder un rollback.
3. Ajouter le worker planifié : verrou, reprise après incident, plafonnement du débit, attente `Retry-After`, stockage de l’identifiant RelaxIT et consultation des statuts. Une demande incertaine doit toujours conserver **le même corps sauvegardé et la même clé** ; ne pas recalculer les variables depuis un rendez-vous modifié lors d’une relance.
4. Ajouter la configuration et le journal du module avec isolation par entité. Ne pas copier de diagnostic, traitement, notes ou dossier médical vers le service de notifications.
5. Valider le module sur des installations de test correspondant aux trois versions avant activation. Aucun envoi à des patients pendant ces essais ; garder le destinataire de pilote RelaxIT.

Le modèle Meta `jaspers_market_order_confirmation_v1` actuel sert uniquement au test du transport. Il ne constitue pas un modèle de confirmation médicale prêt à utiliser pour des rendez-vous réels.

## Tests

```bash
php tests/run.php
```

Aucune dépendance Composer nécessaire. cURL et un magasin de certificats CA sont nécessaires au transport réel ; les tests injectent un transport simulé.
