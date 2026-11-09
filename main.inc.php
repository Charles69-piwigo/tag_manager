<?php
/*
Plugin Name: tag_manager
Version: auto
Description: Gestionnaire de tags hiérarchiques (digiKam) : étiqueter les photos et réorganiser l'arbre des tags, en écrivant dans les métadonnées des fichiers
Plugin URI: auto
Author: Charles69
Has Settings: webmaster
*/

//============= VERSIONS ============================================
/*

version 1.1 - 09/11/2026
    le moteur réécrit les huit champs de mots-clés écrits par digiKam (dc:subject, lr:hierarchicalSubject, digiKam:TagsList, MicrosoftPhoto:LastKeywordXMP, mediapro:CatalogSets, acdsee:categories, IPTC 2:25, Exif XPKeywords) : renommer, déplacer ou supprimer un tag ne laisse plus l’ancien nom dans une partie des métadonnées
    renommer ou ranger un tag qui a des tags dessous fait suivre leurs chemins ; supprimer ou fusionner un tel tag avertit avant d’appliquer
    les entrées de personnes (Personnes|…) ne sont jamais modifiées, même quand un nom est à la fois un tag et une personne ; ces homonymes sont signalés dans « À réconcilier »
    corrigé : renommer un tag dont le nom complet dépasse 64 octets (IPTC 2:25) échouait avec verification_champs : la valeur IPTC coupée à 64 octets était identique à l'ancien nom (ex. « … (Siem » pour « … (Siem Reap) ») et le contrôle final la prenait pour un reste périmé. La coupure retire aussi l'espace final, et une liste IPTC inchangée après coupure n'est plus réécrite
    corrigé : le plugin lisait la PREMIÈRE occurrence de <x:xmpmeta du fichier. Certains fichiers (ex. retouchés avec Picasa) ont un paquet XMP vide dans leur bloc Exif AVANT le vrai XMP : leurs mots-clés étaient ignorés et leur tag classé « sans chemin » alors que le fichier porte le chemin complet. Le XMP est maintenant lu dans le segment APP1 standard (structure JPEG), comme le fait ExifTool ; la relecture de contrôle après écriture aussi
    amélioré : pour proposer un renommage, « À réconcilier » lit jusqu'à 6 photos du tag (au lieu d'une seule) : un fichier témoin sans chemin ne masque plus le nom présent dans les autres
    corrigé : un tag dont le nom, dans Piwigo, ne diffère de celui des fichiers que par un accent ou une majuscule (ex. « sélection » dans Piwigo, « Selection » dans les fichiers) était classé « sans chemin » alors que les fichiers portent le chemin complet. Piwigo compare les noms de tags sans tenir compte des accents ni de la casse (collation MySQL) : tous les noms sont maintenant comparés ainsi (nouveau lib/names.php), par l'indexeur, la réconciliation et le moteur d'écriture (renommer, fusionner, supprimer et ranger retrouvent l'entrée du fichier même écrite autrement). Le chemin enregistré garde l'orthographe du tag Piwigo. Relancer l'indexation après la mise à jour
    regroupe les versions 1.0.1, 1.0.2 et 1.1.1

version 1.0.2 - 09/11/2026
    corrigé : un même nom porté à la fois par un tag normal et par une personne (ex. « Pic » : TAG_FAUNE|OISEAU|Pic et Personnes|Pic) : le moteur ne touche JAMAIS aux entrées sous une racine réservée (Personnes) ; les listes de feuilles (dc:subject, IPTC, XPKeywords) gardent le nom tant qu’une personne du fichier le porte
    corrigé : acdsee:categories écrit comme attribut de rdf:Description (forme compacte d’Exiv2 / digiKam) n’était pas vu, donc jamais mis à jour
    ajouté : l’indexation repère ces homonymes (table tag_manager_homonyms) et les signale dans « À réconcilier » sans rien modifier (analyse en profondeur, jusqu'à 40 photos, des tags déjà enregistrés par face_tag puis déverrouillés) ; elle n’écrase plus le chemin d’un tag par celui d’une personne

version 1.0.1 - 09/11/2026
    corrigé : renommer / déplacer / supprimer un tag laissait l’ancien nom dans une partie des métadonnées du fichier. Le moteur réécrit maintenant les huit champs de mots-clés écrits par digiKam : dc:subject, lr:hierarchicalSubject, digiKam:TagsList, MicrosoftPhoto:LastKeywordXMP, mediapro:CatalogSets, acdsee:categories, IPTC 2:25 et Exif XPKeywords
    corrigé : MicrosoftPhoto:LastKeywordXMP n’était pas reconnu quand le fichier déclare l’espace de noms sans « / » final (forme écrite par ExifTool)
    ajouté : acdsee:categories (arbre XML), mediapro:CatalogSets et Exif XPKeywords (UTF-16, nouvelle valeur ajoutée en fin de bloc si elle est plus longue) ; ces champs sont mis à jour seulement s’ils existent, jamais créés
    ajouté : contrôles de sécurité : avertissement champ_non_gere si une propriété XMP hors registre porte encore l’ancien nom ; relecture du fichier produit avant écriture (erreur verification_champs si un champ est resté périmé)
    corrigé : renommer ou ranger un tag qui a des tags dessous (comme dans digiKam) laissait l’ancien nom dans le chemin des photos de ses descendants ; ils suivent maintenant. Supprimer ou fusionner un tag qui a des descendants ne touche que ce tag ; l’aperçu avertit (liste des tags dessous, leur nom ne subsiste que comme groupe) et l’utilisateur accepte ou refuse
    modifié : l’aperçu d’un renommage ou déplacement de groupe détaille les tags touchés et leur nombre de photos
    dev : test_writer.php surveille les huit champs ; audit_fields.php (lecture seule) repère les fichiers dont les champs ne concordent plus

version 1.0 - 08/11/2026
    première publication sur github
    regroupe les versions 0.0.1 à 0.0.9

version 0.0.9 - 08/11/2026
    ajouté : réglage « taille d'affichage des miniatures » (300 px par défaut, modifiable)
    modifié : « Charger la suite » remplacé par une pagination numérotée (240 photos par page)
    modifié : après un lot (Aperçu et appliquer), retour à la même page et à la même position
    ajouté : accès des non-webmestres : bouton « Tag manager » dans la barre d'outils des pages d'index
    (utilisateurs ayant un droit dans Paramètres) vers page.php, hors administration ; actions AJAX
    communes dans lib/ajax.php, niveau revérifié pour chaque action ; ni Paramètres ni plein écran
    corrigé : arbre des albums d'un non-webmestre : un répertoire physique reste listé s'il contient des photos
    visibles pour lui (par ses albums virtuels), même sans droit sur le répertoire lui-même
    sécurité : un non-webmestre ne voit et ne modifie que les photos visibles pour lui dans Piwigo (albums
    interdits, niveau de confidentialité) : listes, compteurs et création de lot (refus si photo interdite)
    modifié : groupe autorisé par défaut « TagManager » (au lieu de FaceTag), niveau Étiqueter
    ajouté : clic droit dans les arbres (albums, tags, tags de la sélection, gérer) : tout déplier / tout replier
    modifié : seuls les JPEG (métadonnées XMP/IPTC) sont listés, comptés et acceptés dans un lot ; PNG,
    vidéos, etc. restent à étiqueter par la méthode standard de Piwigo
    ajouté : purge automatique du journal (100 derniers lots gardés, lots terminés ou annulés seulement)

version 0.0.8 - 07/11/2026 (suite)
    lot réel de 9 opérations de réconciliation (287 photos, aucune erreur) : renommages Maroc,
    Cambodge, Vietnam, Documentation ; fusions Pologne et Grèce

version 0.0.8 - 07/11/2026
    ajouté : création d'un tag à la volée dans l'écran Étiqueter (nom + groupe d'accueil) : le tag
    est créé tout de suite dans Piwigo, puis ajouté en file aux photos sélectionnées
    première opération de structure réelle sur la galerie : fusion de « 2026 GRECE - 06 Corfou » dans
    « 2026 GRECE - 05 Corfou » (reste d'ancienne numérotation, une seule photo)

version 0.0.7 - 07/11/2026
    ajouté : opérations de structure (lib/struct.php), par la même file d'attente
    - tag : renommer (même identifiant Piwigo), fusionner, supprimer, ranger / déplacer dans un groupe
    - groupe : renommer, déplacer ; créer un groupe ou un tag ; supprimer un groupe vide
    - écran « Gérer les tags » : boutons d'action sur la fiche, aperçu calculé par le serveur
      (photos et tags concernés, erreurs) puis confirmation ; écran « À réconcilier » : action et
      cible modifiables par ligne, « Appliquer », « Appliquer les suggestions fiables » (un seul lot)
    - deux temps : tous les fichiers sont réécrits d'abord ; les tags Piwigo (renommage, fusion par
      l'événement merge_tags, suppression par delete_tags) ne sont mis à jour qu'une fois TOUS les
      fichiers réussis, pour que SmartAlbums suive ; en cas d'erreur le lot passe en « partiel » et
      « Réessayer les erreurs » reprend sans rien refaire
    - refus côté serveur : tag de visage ou racine réservée, tag inconnu, nom invalide ou déjà pris,
      destination sans chemin, même tag dans deux opérations, groupe déplacé dans lui-même
    - tables : colonnes kind et final ajoutées à tag_manager_jobs ; 30000 photos au plus par lot
    - limites : un groupe non vide ne peut pas être supprimé (déplacer ou supprimer ses tags d'abord) ;
      création de tag : le tag existe dans Piwigo sans photo jusqu'à sa première affectation

version 0.0.6 - 07/11/2026
    ajouté : file d'attente d'écriture (lib/queue.php) pour l'écran Étiqueter
    - le bouton « Aperçu et appliquer » crée un lot en base, traité par paquets de 10 fichiers avec
      barre de progression ; un lot interrompu se reprend (bannière) ou s'annule
    - tables tag_manager_jobs et tag_manager_job_files (créées à la volée) ; journal des écritures
      dans Paramètres, avec la liste des fichiers en erreur
    - réservation atomique des fichiers (deux onglets ne traitent jamais le même fichier), un seul
      lot actif à la fois, 5000 photos au plus par lot
    - droits vérifiés par le serveur dans chaque action d'écriture (tagmanager_user_level, d'après la
      table des droits ; webmestre = tout)
    - refus côté serveur d'un lot qui touche un tag de visage, un tag inconnu ou un tag sans chemin
      (à ranger d'abord)
    - après chaque fichier écrit : tags Piwigo de la photo, taille et date de synchro mis à jour, puis
      invalidate_user_cache() pour SmartAlbums
    - un fichier en erreur est laissé intact ; le lot continue et les erreurs sont listées
    - non encore couvert : opérations de structure (renommer, fusionner, supprimer, ranger)

version 0.0.5 - 07/11/2026
    les tags de visages / personnes ne relèvent pas de ce plugin (gérés par face_tag_editor et digiKam) :
    exclus de l'interface, de l'index, de la réconciliation et des compteurs, et ne seront jamais modifiés
    sont exclus : les tags enregistrés par face_tag / face_tag_editor (table facetag_person_tags) et
    ceux dont le chemin commence par une racine réservée (réglage « Tags de visages » dans Paramètres,
    « Personnes » par défaut)
    corrigé : la réconciliation ne propose plus de noms mal formés (commençant par « > »)

version 0.0.4 - 07/11/2026
    ajouté : onglet « Gestionnaire » = l'interface de la maquette, branchée sur les données réelles
    (lecture seule sur les photos) : albums physiques, tags et photos réels avec vignettes, écran
    « Gérer les tags » (arbre, fiche, albums concernés), vue « À réconcilier » avec propositions
    calculées, paramètres (option .original, table des droits) enregistrés dans tag_manager_config
    les modifications de tags restent dans une file locale : aucune écriture dans les fichiers
    la table des droits est enregistrée mais pas encore appliquée (accès réservé aux webmestres)
    nouveaux fichiers : lib/data.php, js/app.js, css/app.css, template/app.tpl
    note : Piwigo ajoute des antislashs aux valeurs POST (stripslashes avant json_decode)

version 0.0.3 - 07/11/2026
    ajouté : moteur d'écriture des tags dans les JPEG (lib/jpeg.php, lib/xmp_writer.php), non branché
    à l'interface pour l'instant
    - remplace uniquement les segments XMP (APP1) et IPTC (APP13) ; l'image n'est jamais ré-encodée
      (face_tag_editor passe par Imagick::writeImage, qui recompresse le JPEG)
    - modifie chaque liste de mots-clés entrée par entrée (dc:subject, lr:hierarchicalSubject,
      digiKam:TagsList, MicrosoftPhoto:LastKeywordXMP, IPTC 2:25) ; le reste du XMP est préservé
    - opérations : add, retag (renommer, fusionner, supprimer, ranger), replace_prefix (groupe)
    - vérification avant écriture (pixels et autres segments identiques), fichier temporaire puis
      renommage, .original facultatif, aucune écriture si rien ne change
    - limites : JPEG seulement ; acdsee:categories non mis à jour ; refus (sans perte) si le paquet
      XMP dépasse la taille d'un segment

version 0.0.2 - 07/11/2026
    corrigé : l'indexation déclarait « à plat » des tags qui portent bien leur chemin dans d'autres
    fichiers (trois photos testées seulement, parmi les premières de la liste) ; elle teste maintenant
    jusqu'à huit photos réparties sur toute la liste du tag
    corrigé : test de lecture du fichier par ouverture réelle (is_readable() renvoie « faux » sur un
    lecteur réseau Windows)

version 0.0.1 - 07/11/2026
    création du plugin : squelette, table tag_manager_paths, indexation en lecture seule des
    chemins hiérarchiques (lr:hierarchicalSubject / digiKam:TagsList) depuis les fichiers,
    page d'administration « Index »
    aucune écriture dans les fichiers ni dans les tags Piwigo à ce stade

*/
//====================================================================

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'tag_manager')
{
  add_event_handler('init', 'tag_manager_error');
  function tag_manager_error()
  {
    global $page;
    $page['errors'][] = 'Désactiver le plugin et renommer le répertoire "tag_manager"';
  }
  return;
}

global $prefixeTable;

// Plugin constants
define('TAGMANAGER_ID', basename(dirname(__FILE__)));
define('TAGMANAGER_PATH', PHPWG_PLUGINS_PATH . TAGMANAGER_ID . '/');
define('TAGMANAGER_ADMIN', get_root_url() . 'admin.php?page=plugin-' . TAGMANAGER_ID);
define('TAGMANAGER_PATHS_TABLE', $prefixeTable . 'tag_manager_paths');
define('TAGMANAGER_HOMONYMS_TABLE', $prefixeTable . 'tag_manager_homonyms');
define('TAGMANAGER_JOBS_TABLE', $prefixeTable . 'tag_manager_jobs');
define('TAGMANAGER_JOBFILES_TABLE', $prefixeTable . 'tag_manager_job_files');

//===================== BOUTON « TAG MANAGER » =====================================
// Barre d'outils des pages d'index, pour les utilisateurs qui ont un droit dans Paramètres (et les
// webmestres). Il mène à page.php, hors administration : les non-webmestres n'ont pas accès au menu
// Plugins. Le niveau est revérifié côté serveur dans page.php et dans chaque action.
add_event_handler('loc_end_index', 'tag_manager_add_index_button');
function tag_manager_add_index_button()
{
  global $template, $user;

  if (empty($user['id']) || is_a_guest())
  {
    return;
  }
  include_once(TAGMANAGER_PATH . 'lib/data.php');
  include_once(TAGMANAGER_PATH . 'lib/queue.php');
  if (tagmanager_user_level() < 1)
  {
    return;
  }

  $url = get_root_url() . 'plugins/' . TAGMANAGER_ID . '/page.php';
  $label = l10n('Tag manager');
  if (isset($user['theme']) && $user['theme'] === 'bootstrap_darkroom')
  {
    $button = '<a class="nav-link" href="' . $url . '" title="' . $label . '" rel="nofollow"><i class="fas fa-tags fa-fw" aria-hidden="true"></i><span class="d-lg-none ml-2">' . $label . '</span></a>';
  }
  else
  {
    $button = '<a href="' . $url . '" title="' . $label . '" class="pwg-state-default pwg-button" rel="nofollow"><span class="pwg-icon pwg-icon-tags"></span><span class="pwg-button-text">' . $label . '</span></a>';
  }
  $template->add_index_button($button, BUTTONS_RANK_NEUTRAL);
}

//===================== CHARGEMENT DES LANGUES , UK PAR DEFAUT ==================
// Charger d'abord l'anglais comme base
load_language('plugin.lang', TAGMANAGER_PATH, array('language' => 'en_UK', 'no_fallback' => true));
// Puis la langue de l'utilisateur (qui écrase l'anglais si c'est le français)
load_language('plugin.lang', TAGMANAGER_PATH);
//=================================================================================

?>
