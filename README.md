# tag_manager

[Français](#français) · [English](#english)

---

## Français

Plugin [Piwigo](https://piwigo.org) pour **étiqueter les photos et réorganiser l'arbre des tags hiérarchiques de digiKam**, en écrivant directement dans les **métadonnées des fichiers JPEG** (XMP et IPTC), et pas seulement dans la base de données de Piwigo.

### Principe

digiKam range les tags en hiérarchie (`ALBUM|2024 NORVEGE|Fjords`). Piwigo, lui, ne retient que le dernier niveau (`Fjords`). Le chemin complet n'existe que dans les fichiers (`lr:hierarchicalSubject`, `digiKam:TagsList`). **Les fichiers sont la source de vérité** : le plugin les relit pour reconstituer l'arbre, et toute modification est écrite dans le fichier **et** dans les tags de Piwigo, pour que les deux restent cohérents avec digiKam.

### Fonctionnalités

- **Étiqueter** : navigation par albums (ceux de la galerie, dans l'ordre de « Gestion des albums », que les photos viennent de la synchronisation ou d'un envoi par upload ; « Photos orphelines » pour les webmestres) ou par tags, miniatures paginées, sélection multiple, glisser-déposer d'une photo sur un tag, création d'un tag à la volée. Les changements restent dans une file locale jusqu'à « Aperçu et appliquer ». Par tag, les photos sont regroupées par album, avec un bandeau qui indique l'album. Au survol d'un album ou d'un tag, l'icône ↗ ouvre sa page dans la galerie (nouvel onglet). Un champ de filtre au-dessus de chaque arbre (sans casse ni accents) ouvre les branches qui contiennent le texte cherché.
- **Gérer les tags** : arbre des tags avec renommer, fusionner, supprimer, ranger / déplacer un tag ; renommer / déplacer un groupe ; supprimer un groupe vide. Sous la fiche, deux panneaux « Créer un tag » et « Créer un groupe » (un groupe peut être créé au premier niveau). Chaque opération passe par un aperçu chiffré calculé par le serveur.
- **Tags sans photo** : les tags créés dans Piwigo et attachés à aucune photo apparaissent dans un regroupement « (sans photo) ». On peut les ranger, renommer, fusionner ou supprimer ; au premier ajout à des photos, leur groupe est demandé.
- **À réconcilier** : propose fusion ou renommage pour les tags de Piwigo dont le chemin ne se retrouve dans aucun fichier. Deux cas liés aux visages ont leur propre ligne, en langage courant :
  - un même nom pour une personne et un tag ordinaire : **Séparer** donne un autre nom au tag ordinaire (fichiers et Piwigo), la personne garde le sien ;
  - un tag marqué comme visage par face_tag alors que ses photos le rangent ailleurs (cadre de visage supprimé) : **Ce n'est pas un visage** le retire de la liste des visages, après relecture des fichiers.
- **Arbres** : le clic droit déplie ou replie la ligne cliquée et tous ses sous-niveaux, ou tout l'arbre.
- **Moteur d'écriture sans recompression** : seuls les segments XMP (APP1), IPTC (APP13) et Exif (APP1, uniquement le champ XPKeywords) sont réécrits ; les pixels et les autres segments sont recopiés à l'identique. Vérification avant écriture, fichier temporaire puis renommage, copie `.original` facultative, aucune écriture si rien ne change.
- **File d'attente reprenable** : les lots sont enregistrés en base, exécutés par paquets, avec journal et reprise après interruption (100 derniers lots conservés).
- **Droits par groupe Piwigo** (voir ci-dessous) et page dédiée pour les non-webmestres.
- **Interface en français et en anglais** (selon la langue de l'utilisateur dans Piwigo).
- Les **tags de visages** (racine `Personnes`, gérés par `face_tag_editor` ou digiKam) ne sont jamais affichés ni modifiés.

### Limites

- **JPEG uniquement** (`.jpg`, `.jpeg`). Les PNG, vidéos, etc. n'ont pas de métadonnées exploitables ici et ne sont ni listés ni acceptés ; ils restent à étiqueter par la méthode standard de Piwigo.
- Les albums SmartAlbums sont marqués ⟳ : leur contenu dépend des tags. Un clic sur ⟳ montre ce que SmartAlbums changerait (photos entrées / sorties) et met l'album à jour sur confirmation. Un SmartAlbum sans critère valable (qui contient presque toute la galerie) est marqué ⚠ et n'est pas mis à jour.
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


---

## English

[Piwigo](https://piwigo.org) plugin to **tag photos and reorganize the hierarchical tag tree from digiKam**, writing straight into the **metadata of JPEG files** (XMP and IPTC), not only into Piwigo's database.

### How it works

digiKam stores tags as a hierarchy (`ALBUM|2024 NORWAY|Fjords`). Piwigo only keeps the last level (`Fjords`). The full path exists only in the files (`lr:hierarchicalSubject`, `digiKam:TagsList`). **The files are the source of truth**: the plugin reads them back to rebuild the tree, and every change is written to the file **and** to Piwigo's tags, so both stay consistent with digiKam.

### Features

- **Tag photos**: browse by albums (the gallery's albums, in the « Manage albums » order, whether photos come from synchronization or upload; « Orphan photos » for webmasters) or by tags, paginated thumbnails, multiple selection, drag a photo onto a tag, create a tag on the fly. Changes stay in a local queue until you click "Preview and apply". When browsing by tag, photos are grouped by album, each group with a banner naming the album. Hovering an album or a tag shows a ↗ icon that opens its gallery page in a new tab. A filter box above each tree (case and accent insensitive) opens the branches that contain the searched text.
- **Manage tags**: tag tree with rename, merge, delete, move a tag; rename / move a group; delete an empty group. Below the details pane, two side-by-side boxes "Create a tag" and "Create a group" (a group can be created at the top level). Every operation goes through a server-computed preview with counts.
- **Tags without photos**: tags created in Piwigo and attached to no photo appear under a “(no photo)” grouping. They can be placed, renamed, merged or deleted; when first added to photos, their group is asked for.
- **To reconcile**: suggests a merge or rename for Piwigo tags whose path cannot be found in any file. Two face-related cases get their own plain-language line:
  - the same name used for a person and for an ordinary tag: **Separate** gives the ordinary tag another name (files and Piwigo), the person keeps theirs;
  - a tag marked as a face by face_tag although its photos file it elsewhere (face frame removed): **Not a face** removes it from the face list, after re-reading the files.
- **Trees**: right-click expands or collapses the clicked line with all its sub-levels, or the whole tree.
- **Lossless write engine**: only the XMP (APP1), IPTC (APP13) and Exif (APP1, XPKeywords field only) segments are rewritten; pixels and all other segments are copied unchanged. Verified before writing, temporary file then rename, optional `.original` copy, nothing written when nothing changes.
- **Resumable queue**: batches are stored in the database, run in chunks, with a log and recovery after an interruption (last 100 batches kept).
- **Rights per Piwigo group** (see below) and a dedicated page for non-webmasters.
- **French and English interface** (following the user's language in Piwigo).
- **Face tags** (root `Personnes`, handled by `face_tag_editor` or digiKam) are never shown nor modified.

### Limitations

- **JPEG only** (`.jpg`, `.jpeg`). PNG, videos, etc. have no usable metadata here and are neither listed nor accepted; tag them with Piwigo's standard method.
- SmartAlbums albums are marked ⟳: their content depends on tags. Clicking ⟳ shows what SmartAlbums would change (photos in / out) and updates the album on confirmation. A SmartAlbum with no valid criterion (holding almost the whole gallery) is marked ⚠ and is not updated.
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
| Tagging | add / remove tags on photos |
| Manage: create | + create tags and groups |
| Manage: all | + rename, merge, delete, move |

Levels are cumulative (a user combines their own rights and those of their groups). Authorized users get a **Tag manager** button (tags icon) in the toolbar of album pages, opening a dedicated page outside the administration. Rights are re-checked **server-side** on every action. A non-webmaster only sees and edits the photos Piwigo lets them see (forbidden albums, privacy level).

The button uses Piwigo's standard mechanism for index pages (tested with the `bootstrap_darkroom` theme).


