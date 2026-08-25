# Files d’attente et opérations planifiées

## Référence temporelle

Toutes les fenêtres applicatives utilisent `config('app.timezone')`. Les opérateurs doivent configurer le même fuseau sur chaque instance et afficher `php artisan schedule:list` pendant la recette. Un changement de fuseau est un changement opérationnel contrôlé.

## Rappels et notifications d’activités

Le scheduler exécute `sgar:activities:queue-reminders` toutes les cinq minutes avec `withoutOverlapping`. La sélection couvre les activités publiées dont le début est strictement futur et situé dans les prochaines 24 heures. Cette fenêtre rattrape une exécution tardive sans envoyer après le début.

Seuls les comptes actifs encore inscrits au moment du worker reçoivent un mail. Une modification de `starts_at`, `ends_at` ou `location` après publication incrémente la version et prépare un avis ; une modification descriptive n’en prépare aucun. Une annulation publiée invalide tous les anciens jobs. Les versions et la contrainte unique activité/utilisateur/type/version rendent les répétitions et anciens jobs inoffensifs.

`sent_at` signifie uniquement que le transport mail a réussi. Il ne prouve ni livraison ni lecture. Les jobs sont chiffrés, exécutés après commit, limités à trois essais et utilisent les délais 60, 300 et 900 secondes.

## Worker

Exécuter le worker sous le gestionnaire de processus approuvé :

```text
php artisan queue:work --queue=default --sleep=3 --tries=3 --backoff=60,300,900 --max-time=3600
```

Après chaque déploiement :

```text
php artisan queue:restart
```

Un modèle Supervisor ou systemd peut lancer cette commande, la relancer après sortie et collecter sa sortie assainie. Le nom d’utilisateur, les chemins, les variables, les limites et la destination des logs doivent être fournis par l’infrastructure ; aucun exemple versionné ne doit être copié tel quel en production.

Inspecter et reprendre un échec précis sans afficher son payload :

```text
php artisan queue:failed
php artisan queue:retry <failed-job-uuid>
```

## Scheduler

Le système d’exploitation doit appeler chaque minute, sous le même contexte de release que l’application :

```text
php artisan schedule:run
```

Un modèle cron, timer systemd ou équivalent doit définir le répertoire de travail et l’utilisateur à partir de l’infrastructure approuvée. Le dépôt n’impose ni chemin ni compte système.

`withoutOverlapping` nécessite un cache de verrouillage fonctionnel. En environnement multi-instance, ce cache doit être partagé. `onOneServer` n’est volontairement pas activé tant qu’un cache central partagé, durable et testé n’est pas prouvé ; il pourra alors être ajouté lors d’une décision de déploiement.

## Sauvegarde v2 quotidienne

`sgar:backup:create` est planifiée quotidiennement à `SGAR_BACKUP_SCHEDULE_TIME` (`02:00` par défaut) avec `withoutOverlapping`. L’heure n’est pas secrète. La commande conserve le chiffrement, le disque privé, la rétention et les audits du format v2. Aucun stockage hors site n’est ajouté ici.

Vérifier régulièrement les nouvelles archives avec `sgar:backup:verify` et tester leur restauration dans une base isolée selon `docs/backups.md`. Ne jamais lancer une restauration depuis le scheduler.

## Supervision et critères d’alerte

Déclencher une alerte sur : ancienneté ou profondeur anormale de la file, nouvelle entrée `failed_jobs`, absence de heartbeat du worker, absence d’exécution du scheduler sur plusieurs intervalles, échec `backup.failed`, absence d’archive quotidienne ou verrou de chevauchement anormalement ancien. Les seuils et destinations d’alerte relèvent du déploiement.

`/up` demeure une liveness limitée. Il ne prouve pas la disponibilité de la queue, du scheduler, du mail, du cache partagé, de la base ou du disque de sauvegarde et ne doit pas être présenté comme une readiness complète.
