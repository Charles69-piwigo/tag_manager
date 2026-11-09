# tag_manager

[Français](#français) · [English](#english)

---

## Français

Plugin [Piwigo](https://piwigo.org) pour **étiqueter les photos et réorganiser l'arbre des tags hiérarchiques de digiKam**, en écrivant directement dans les **métadonnées des fichiers JPEG** (XMP et IPTC), et pas seulement dans la base de données de Piwigo.

### Principe

digiKam range les tags en hiérarchie (`ALBUM|2024 NORVEGE|Fjords`). Piwigo, lui, ne retient que le dernier niveau (`Fjords`). Le chemin complet n'existe que dans les fichiers (`lr:hierarchicalSubject`, `digiKam:TagsList`). **Les fichiers sont la source de vérité** : le plugin les relit pour reconstituer l'arbre, et toute modification est écrite dans le fichier **et** dans les tags de Piwigo, pour que les deux restent cohérents avec digiKam.

### Fonctionnalités

- **Étiqueter** : navigation par albums physiques (répertoires) ou par tags, miniatures paginées, sélection multiple, glisser-déposer d'une photo sur un tag, création d'un tag à la volée. Les changements restent dans une file locale jusqu'à « Aperçu et appliquer ».
- **Gérer les tags** : arbre des tags avec renommer, fusionner, supprimer, ranger / déplacer un tag ; renommer / déplacer un groupe ; créer un tag ou un groupe ; supprimer un groupe vide. Chaque opération passe par un aperçu chiffré calculé par le serveur.
- **À réconcilier** : propose fusion ou renommage pour les tags de Piwigo dont le chemin ne se retrouve dans aucun fichier.
- **Moteur d'écriture sans recompression** : seuls les segments XMP (APP1), IPTC (APP13) et Exif (APP1, uniquement le champ XPKeywords) sont réécrits ; les pixels et les autres segments sont recopiés à l'identique. Vérification avant écriture, fichier temporaire puis renommage, copie `.original` facultative, aucune écriture si rien ne change.
- **File d'attente reprenable** : les lots sont enregistrés en base, exécutés par paquets, avec journal et reprise après interruption (100 derniers lots conservés).
- **Droits par groupe Piwigo** (voir ci-dessous) et page dédiée pour les non-webmestres.
- Les **tags de visages** (racine `Personnes`, gérés par `face_tag_editor` ou digiKam) ne sont jamais affichés ni modifiés.

### Limites

- **JPEG uniquement** (`.jpg`, `.jpeg`). Les PNG, vidéos, etc. n'ont pas de métadonnées exploitables ici et ne sont ni listés ni acceptés ; ils restent à étiqueter par la méthode standard de Piwigo.
- Albums **physiques** seulement (répertoires réels) ; les albums virtuels ne sont pas gérés.
- Mots-clés IPTC limités à 64 octets ; paquet XMP de plus de 64 Ko refusé ; XMP étendu non géré. Le point-virgule est interdit dans un nom de tag (séparateur d’Exif XPKeywords).
- PHP doit pouvoir **écrire** dans les fichiers (et créer des fichiers temporaires dans leur répertoire).

### Installation

1. Copier le dossier `tag_manager` dans `plugins/` (le nom du dossier doit rester `tag_manager`).
2. Activer le plugin dans **Administration > Plugins**.
3. Ouvrir **Plugins > tag_manager**, onglet **Index** : lancer l'indexation (lecture des chemins hiérarchiques dans les fichiers). Relancer l'indexation après des changements faits depuis digiKam.
4. Onglet **Gestionnaire** : l'interface complète. Réglages dans **Paramètres**.

**Sauvegardez vos photos avant un premier essai réel.** Le plugin réécrit les fichiers. L'option « Créer une copie `.original` » (désactivée par défaut) conserve l'original à côté.

### Droits et utilisateurs non webmestres

Les webmestres ont tous les droits. Pour les autres, créez un groupe Piwigo (par exemple **`TagManager`**), ajoutez-y les membres, puis donnez-lui un niveau dans **Paramètres > Droits** :

| Niveau | Permet |
|---|---|
| Étiqueter | ajouter / retirer des tags sur les photos |
| Gérer : créer | + créer des tags et des groupes |
| Gérer : tout | + renommer, fusionner, supprimer, ranger |

Les niveaux sont cumulatifs (un utilisateur cumule ses droits et ceux de ses groupes). Les utilisateurs autorisés voient un bouton **Tag manager** (icône d'étiquettes) dans la barre d'outils des pages d'album, qui ouvre une page dédiée hors administration. Les droits sont revérifiés **côté serveur** pour chaque action. Un non-webmestre ne voit et ne modifie que les photos que Piwigo lui permet de voir (albums interdits, niveau de confidentialité).

Le bouton est ajouté par le mécanisme standard de Piwigo pour les pages d'index (testé avec le thème `bootstrap_darkroom`).

### Développement

Le dossier `dev/` contient des outils hors plugin (banc d'essai du moteur sur des copies, comparaison de JPEG, génération de la feuille de style). Voir `dev/README.md`. `css/app.css` est **généré** à partir de la maquette par `dev/mkcss.js`.

---

## English

[Piwigo](https://piwigo.org) plugin to **tag photos and reorganize the hierarchical tag tree from digiKam**, writing straight into the **metadata of JPEG files** (XMP and IPTC), not only into Piwigo's database.

### How it works

digiKam stores tags as a hierarchy (`ALBUM|2024 NORWAY|Fjords`). Piwigo only keeps the last level (`Fjords`). The full path exists only in the files (`lr:hierarchicalSubject`, `digiKam:TagsList`). **The files are the source of truth**: the plugin reads them back to rebuild the tree, and every change is written to the file **and** to Piwigo's tags, so both stay consistent with digiKam.

### Features

- **Tag photos**: browse by physical albums (real directories) or by tags, paginated thumbnails, multiple selection, drag a photo onto a tag, create a tag on the fly. Changes stay in a local queue until you click "Preview and apply".
- **Manage tags**: tag tree with rename, merge, delete, move a tag; rename / move a group; create a tag or a group; delete an empty group. Every operation goes through a server-computed preview with counts.
- **To reconcile**: suggests a merge or rename for Piwigo tags whose path cannot be found in any file.
- **Lossless write engine**: only the XMP (APP1) and IPTC (APP13) segments are rewritten; pixels and all other segments are copied unchanged. Verified before writing, temporary file then rename, optional `.original` copy, nothing written when nothing changes.
- **Resumable queue**: batches are stored in the database, run in chunks, with a log and recovery after an interruption (last 100 batches kept).
- **Rights per Piwigo group** (see below) and a dedicated page for non-webmasters.
- **Face tags** (root `Personnes`, handled by `face_tag_editor` or digiKam) are never shown nor modified.

### Limitations

- **JPEG only** (`.jpg`, `.jpeg`). PNG, videos, etc. have no usable metadata here and are neither listed nor accepted; tag them with Piwigo's standard method.
- **Physical** albums only (real directories); virtual albums are not handled.
- IPTC keywords are limited to 64 bytes; XMP packets over 64 KB are refused; Extended XMP is not handled. A semicolon is not allowed in a tag name (Exif XPKeywords separator).
- PHP must be able to **write** to the files (and create temporary files in their directory).

### Installation

1. Copy the `tag_manager` folder into `plugins/` (the folder name must stay `tag_manager`).
2. Activate the plugin in **Administration > Plugins**.
3. Open **Plugins > tag_manager**, **Index** tab: run the indexing (reads hierarchical paths from the files). Run it again after changes made from digiKam.
4. **Manager** tab: the full interface. Options are in **Settings**.

**Back up your photos before a first real run.** The plugin rewrites files. The "Create a `.original` copy" option (off by default) keeps the original next to it.

### Rights and non-webmaster users

Webmasters have every right. For other users, create a Piwigo group (for example **`TagManager`**), add the members, then give it a level in **Settings > Rights**:

| Level | Allows |
|---|---|
| Tag | add / remove tags on photos |
| Manage: create | + create tags and groups |
| Manage: all | + rename, merge, delete, move |

Levels are cumulative (a user combines their own rights and those of their groups). Authorized users get a **Tag manager** button (tags icon) in the toolbar of album pages, opening a dedicated page outside the administration. Rights are re-checked **server-side** on every action. A non-webmaster only sees and edits the photos Piwigo lets them see (forbidden albums, privacy level).

The button uses Piwigo's standard mechanism for index pages (tested with the `bootstrap_darkroom` theme).

### Development

The `dev/` folder holds tools that are not part of the plugin (engine test bench working on copies, JPEG comparison, stylesheet generation). See `dev/README.md`. `css/app.css` is **generated** from the mockup by `dev/mkcss.js`.
