# BCA Media Cleaner : conventions pour les sessions de développement

## Règles non négociables

- En cas de doute, un fichier est **utilisé**. Un faux négatif coûte quelques Ko, un faux positif casse un site client.
- Aucun tag ni release sans la validation d'Eric. Travail par phases, arrêt après chaque phase.
- Phase 1 = lecture seule stricte : aucune écriture dans `uploads`, aucune modification de contenus ou de metas. Le test d'empreinte de `tests/run.php` doit rester vert.
- La désinstallation ne doit jamais perdre un fichier (dès la phase 2 : restaurer la quarantaine d'abord).
- Ne jamais compter sur `.htaccess` (les serveurs BCA sont sous nginx).
- Aucune donnée sortante, sauf la vérification de mise à jour (User-Agent neutre `BCA-Media-Cleaner/x`, jamais d'email ni d'URL de site).

## Conventions

- PHP 8.1 à 8.4, WordPress 6.4+. Multisite : inactif avec notice.
- Préfixe `bcamc_` (fonctions, options, tables, hooks, handles), classes `BCAMC_*`.
- Text domain `bca-media-cleaner`, chaînes sources en anglais, traduction `languages/bca-media-cleaner-fr_FR.po/.mo` à régénérer après tout ajout de chaîne :
  `wp i18n make-pot . languages/bca-media-cleaner.pot --exclude=tests` puis mise à jour du `.po` et `wp i18n make-mo languages`.
- Textes : pas de tiret cadratin ni demi-cadratin, pas le mot « petit ».
- Capacité `manage_options` via `BCAMC_Settings::capability()` (filtre `bcamc_capability`). Nonces sur chaque action, SQL toujours préparé.
- Couleurs BCA : #1D3058, dégradé #4CAF50 vers #FF7043, #E8EDF5, #D7DEE8.

## Architecture

| Fichier | Rôle |
|---|---|
| `class-db.php` | Tables `bcamc_refs` (index des références : ID, chemin exact, radical, fichiers possédés) et `bcamc_items` (résultats) |
| `class-extractor.php` | Extraction des IDs et chemins d'un texte, normalisation, radical |
| `class-sources.php` | Sources lues par lots avec curseur. Filtre `bcamc_reference_sources` |
| `class-inventory.php` | Fichiers de chaque média, classement A et B, parcours des dossiers pour C |
| `class-scanner.php` | Machine à états reprenable (option `bcamc_scan`) : index, inventaire, fichiers. Budget temps et garde mémoire à 70 % |
| `class-report.php` | Synthèse et listes |
| `class-admin.php` / `assets/admin.js` | Page Médias > Nettoyage des médias, passages AJAX de 6 s |
| `class-cli.php` | `wp bca-media-cleaner scan|report|list` |
| `class-updater.php` | Mises à jour via GitHub Releases (copie adaptée de BCA Media Folders) |

Correspondance : B par ID ou radical ; A et C par chemin exact uniquement. Le radical retire extension, `-LxH`, `-scaled`, `-rotated`, `-e` + 13 chiffres, mais garde `-1`, `-2`.

## Tests

WordPress de test local en SQLite, puis :

```bash
BCAMC_TESTS=1 wp eval-file wp-content/plugins/bca-media-cleaner/tests/run.php
BCAMC_TESTS=1 BCAMC_PERF_MEDIA=20000 wp eval-file wp-content/plugins/bca-media-cleaner/tests/perf.php
```

Tout nouveau type de référence s'accompagne d'un scénario dans `run.php` et d'une ligne dans `TESTS.md`.
