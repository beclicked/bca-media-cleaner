# Recette BCA Media Cleaner

## Tests automatisés

À lancer sur un WordPress de test uniquement (les scripts effacent contenus et fichiers).

```bash
BCAMC_TESTS=1 wp eval-file wp-content/plugins/bca-media-cleaner/tests/run.php
BCAMC_TESTS=1 BCAMC_PERF_MEDIA=20000 wp eval-file wp-content/plugins/bca-media-cleaner/tests/perf.php
```

`run.php` couvre 28 scénarios (60 contrôles) : Elementor (chemin échappé et ID en chaîne), CSS du Customizer, CSS Elementor à chemin relatif, logo, icône du site, Open Graph SEOPress, galerie WooCommerce, contenus programmés, brouillons et en corbeille, tailles non enregistrées (citée ou non), PDF dans une table FluentCRM, variantes WebP, fichiers orphelins, média cité seulement dans une révision, média récent, menu, widget, meta de terme, bloc et shortcode galerie, poster vidéo, doublon `-1`, image éditée `-e`, option non autoloadée, avatar, commentaire, chemin CDN, FluentSnippets. Plus : aucun fichier modifié (empreinte avant/après), documents exclus par défaut, reprise par lots, filtre `bcamc_reference_sources`, droits.

`perf.php` : dernier résultat à 20 000 médias et 107 000 fichiers, 82 s en 13 passages, passage le plus long 6,7 s, mémoire max 133 Mo pour une limite de 256 Mo. Comptes A, B et C exacts.

## Phase 1 : scénarios manuels sur un site réel

Pour chaque scénario, noter le média concerné, lancer l'analyse, et vérifier qu'il **n'apparaît pas** dans la liste (sauf le dernier).

- [ ] Image utilisée uniquement dans une page Elementor
- [ ] Image de fond en CSS : section Elementor, puis CSS additionnel du Customizer
- [ ] Logo du Customizer et icône du site
- [ ] Image Open Graph SEOPress (sur un contenu, puis dans les réglages globaux)
- [ ] Galerie d'un produit WooCommerce
- [ ] Image d'un article programmé
- [ ] Taille d'image plus enregistrée mais citée dans un ancien article : la taille n'est **pas** en catégorie A
- [ ] PDF lié dans une campagne FluentCRM, avec le réglage « Documents » activé
- [ ] Image avec variantes WebP (FlyingPress ou autre) : variantes comptées avec leur image
- [ ] Image réellement orpheline (envoyée il y a plus de 30 jours, jamais insérée) : elle **apparaît** en catégorie B

Contrôles généraux :

- [ ] L'analyse se termine sans erreur, en interface et en WP-CLI, sur le plus gros site
- [ ] Fermer l'onglet en cours d'analyse, revenir : « Reprendre l'analyse » repart du point d'arrêt
- [ ] Le total récupérable et les poids des dossiers sont cohérents avec `du -sh wp-content/uploads`
- [ ] Parcourir la liste B et repérer tout faux positif (média en réalité utilisé) : le signaler avec l'endroit où il sert
- [ ] Aucun fichier modifié : `find wp-content/uploads -newer <fichier témoin>` ne renvoie rien après l'analyse

## Phase 2 : quarantaine (à venir)

- [ ] Quarantaine puis restauration : fichiers et métadonnées revenus à l'identique
- [ ] Conflit à la restauration : un fichier du même nom a été recréé entre-temps
- [ ] Accès direct au dossier de quarantaine depuis un navigateur : refusé (nginx, sans `.htaccess`)
- [ ] Désinstallation avec des éléments en quarantaine : tout est restauré avant suppression des tables
