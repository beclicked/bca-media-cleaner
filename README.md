# BCA Media Cleaner

Extension WordPress gratuite de Be Clicked Agency : repérer les médias et fichiers qu'un site n'utilise plus, et mesurer l'espace disque récupérable.

## Règle d'or

Rien n'est supprimé sans passer par : **analyse en lecture seule, quarantaine réversible, délai, confirmation explicite**. En cas de doute, un fichier est considéré comme **utilisé**. Garder un fichier inutile coûte quelques Ko, supprimer un fichier utilisé casse un site.

## État d'avancement

| Phase | Contenu | État |
|---|---|---|
| 1 | Index des références et rapport, **lecture seule** | 0.1.0 livrée, 0.2.0 après le premier test réel (toutes les tables, champs ACF et JetEngine, export CSV) |
| 2 | Quarantaine et restauration | À venir |
| 3 | Suppression définitive après N jours | À venir |

La version actuelle **ne déplace et ne supprime aucun fichier**.

## Les trois catégories

| | Catégorie | Ce qui est compté | Ce qui n'est jamais compté |
|---|---|---|---|
| A | Tailles d'images inutilisées | Tailles plus enregistrées par le thème ou les extensions, dont le fichier (et ses variantes WebP/AVIF) n'est cité nulle part | Une taille encore enregistrée (WordPress s'en sert pour le `srcset`), un fichier partagé avec une taille enregistrée ou l'original |
| B | Médias inutilisés | Médias sans aucune référence par ID ni par radical de nom, ajoutés il y a plus de 30 jours. Images et vidéos par défaut | Documents (PDF, audio, bureautique), sauf si le réglage est activé |
| C | Fichiers orphelins | Fichiers de `uploads/AAAA/MM/` qui n'appartiennent à aucun média et ne sont cités nulle part, plus anciens que la limite d'âge | Tout ce qui est hors des dossiers datés (elementor, fluentform, woocommerce_uploads, cache...) : mesuré, jamais analysé |

## Ce qui est lu

- Contenus de **tous les types et statuts** (brouillons, programmés, privés, corbeille, modèles Elementor, blocs réutilisables, templates FSE, menus de navigation, CSS additionnel), révisions et brouillons automatiques exclus.
- Metas de contenus (Elementor, image mise en avant, galeries WooCommerce, SEOPress, ACF et extensions inconnues : toute valeur contenant un chemin d'uploads).
- Options (logo, icône, theme mods, widgets, kit Elementor, SEOPress), autoloadées ou non, transients exclus.
- Metas de termes, metas utilisateurs, commentaires.
- **Toutes les tables des extensions** (JetEngine, FluentPlayer, Fluent Support, SureDash et toute extension future) :
  - tables connues (FluentCRM, Fluent Forms, FluentBooking, Presto Player, quiz LearnDash) lues comme un contenu ;
  - autres tables en mode strict : chemins de fichiers partout, IDs seulement dans les colonnes nommées comme un média (un `"id":12` quelconque y désigne souvent un ticket ou une commande) ;
  - journaux, statistiques et files d'attente ignorés (Independent Analytics, Security Ninja, Action Scheduler...), liste filtrable avec `bcamc_skip_tables`.
- **Champs média déclarés par ACF et JetEngine** (image, fichier, galerie, média), y compris répéteurs, pages d'options et contenus personnalisés JetEngine : un champ nommé `visuel` ou `plan_acces` est reconnu comme un média. Liste filtrable avec `bcamc_media_field_keys`.
- Valeurs sérialisées ou JSON : les entiers rangés sous une clé de média (`background_image`, `logoId`...) comptent comme des IDs.
- Fichiers CSS hors dossiers datés (dont `uploads/elementor/css`) et snippets FluentSnippets.
- Sources supplémentaires via le filtre `bcamc_reference_sources`.

La correspondance se fait sur le **chemin relatif au dossier d'uploads**, quel que soit le domaine (CDN, http/https, URL relative, caractères encodés, JSON échappé).

## Ce que l'analyse ne voit pas

Emails déjà envoyés, publications sur les réseaux sociaux, sites tiers qui pointent vers un fichier, produits SureCart (stockés hors WordPress), adresses écrites en dur dans le thème, stockage externe. C'est la raison d'être de la quarantaine en phase 2.

## Utilisation

**Interface** : Médias > Nettoyage des médias > Lancer l'analyse. L'analyse avance par passages de 6 secondes ; on peut fermer l'onglet et reprendre plus tard. Chaque liste se télécharge en CSV (bouton « Télécharger cette liste »), ou tout d'un coup depuis le rapport. Le séparateur suit la langue du site (point-virgule en français, pour Excel).

**WP-CLI** (recommandé sur les gros sites) :

```bash
wp bca-media-cleaner scan                      # analyse complète, puis rapport
wp bca-media-cleaner report                    # dernier rapport
wp bca-media-cleaner report --format=json
wp bca-media-cleaner list --category=B         # 50 premiers médias inutilisés
wp bca-media-cleaner list --format=csv --limit=0 > inutilises.csv
```

Options de `scan` : `--batch-size=<n>`, `--min-age=<jours>`, `--include-documents`.

## Réglages

| Réglage | Défaut | Rôle |
|---|---|---|
| Limite d'âge | 30 jours | Ignore les médias récents, pas encore insérés |
| Documents | Désactivé | Ajoute PDF, audio, bureautique à la catégorie B |
| Taille des lots | 200 | À baisser sur un serveur modeste |

## Compatibilité et droits

PHP 8.1 à 8.4, WordPress 6.4 et plus. Multisite non pris en charge (l'extension reste inactive et l'indique). Capacité requise : `manage_options` (filtrable via `bcamc_capability`). Aucune donnée ne sort du site, sauf la vérification de mise à jour auprès de GitHub (User-Agent neutre).

## Désinstallation

Supprimer l'extension efface ses tables (`bcamc_refs`, `bcamc_items`) et ses options. En phase 1, aucun fichier n'est concerné. À partir de la phase 2, la désinstallation restaurera d'abord tout fichier en quarantaine.

## Mises à jour automatiques

Basées sur GitHub Releases et le mécanisme natif « Update URI » de WordPress, comme BCA Media Folders. Les sites vérifient toutes les heures, et immédiatement sur Tableau de bord > Mises à jour.

## Publier une version

1. Passer la version dans `bca-media-cleaner.php` (en-tête `Version:` et constante `BCAMC_VERSION`).
2. Commit et push.
3. Sur GitHub : **Actions > Release > Run workflow**.

Le workflow vérifie la cohérence des versions, refuse de republier une version existante, construit `bca-media-cleaner.zip`, crée le tag et publie la release.

## Tests

Sur un WordPress de test uniquement (les scripts effacent des contenus) :

```bash
BCAMC_TESTS=1 wp eval-file wp-content/plugins/bca-media-cleaner/tests/run.php    # 79 contrôles
BCAMC_TESTS=1 BCAMC_PERF_MEDIA=20000 wp eval-file wp-content/plugins/bca-media-cleaner/tests/perf.php
```

Scénarios manuels : voir `TESTS.md`.
