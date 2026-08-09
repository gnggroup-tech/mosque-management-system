# mosque-management-system
Application web centralisée de gestion administrative et financière des mosquées.

## Intégration continue

Le workflow GitHub Actions s'exécute pour chaque Pull Request vers `main`, chaque push sur `main` et à la demande avec `workflow_dispatch`. Il installe les dépendances verrouillées PHP et Node.js, compile les assets Vite, contrôle le manifeste frontend, la syntaxe PHP, la configuration Laravel, les traductions JSON, Laravel Pint et la suite PHPUnit complète. Il refuse également les fichiers sensibles suivis accidentellement.

Les vérifications peuvent être reproduites localement depuis `backend` :

```powershell
composer install --no-interaction --prefer-dist --no-progress
composer validate --strict --no-check-publish
npm.cmd ci --ignore-scripts
npm.cmd run build
vendor\bin\pint.bat --test
php artisan test
```

Un échec du build frontend doit être examiné avant PHPUnit : les vues Blade ont besoin de `public/build/manifest.json`, généré par Vite mais non suivi par Git. Un échec Laravel doit ensuite être reproduit avec la commande et le test indiqués dans les logs du job `Laravel validation`.
