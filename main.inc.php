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

version 1.6 - 14/11/2026 - regroupe 1.5.1
    modifié : interface habillée comme l'administration de Piwigo : onglets de Piwigo (Étiqueter, Gérer les tags, Index, Paramètres), icônes, boutons et couleurs de l'admin, thème sombre « roma » suivi
    modifié : « Tags de la sélection » un tag par ligne ; barre d'enregistrement en bas de l'écran Étiqueter ; fiche d'un tag avec albums concernés en colonne ; Paramètres et Index au format de la page Configuration
    ajouté : changer d'onglet garde les changements en attente ; confirmation si on quitte la page avec des changements en attente ; nombre de tags à réconcilier sur l'onglet « Gérer les tags »

version 1.5.1 - 14/11/2026
    modifié : habillage de l'interface aligné sur l'administration de Piwigo (demande sur le forum) : onglets de Piwigo (Étiqueter, Gérer les tags, Index, Paramètres) au lieu des deux niveaux d'onglets du plugin ; icônes de l'administration (police fontello) ; boutons orange / gris de Piwigo ; pastilles de couleur (dossier, groupe, tag) ; fil d'Ariane en étiquettes ; barre d'enregistrement en bas de l'écran Étiqueter (changements en attente, « Aperçu et appliquer ») ; Paramètres et Index sous la forme de la page Configuration ; fenêtres, réconciliation et fiche d'un tag au même style. Thème sombre « roma » suivi. Feuille css/app.css écrite à la main (n'est plus générée depuis la maquette). Le design 1.5 est conservé dans la branche git design-v1.5
    modifié : « Étiqueter » et « Gérer les tags » / « Paramètres » restent des écrans de la même page : un clic sur l'onglet de Piwigo bascule sans recharger (les changements en attente sont gardés) ; quitter la page avec des changements en attente demande confirmation ; nombre de tags à réconcilier affiché sur l'onglet « Gérer les tags »
    modifié : fiche d'un tag : albums concernés un par ligne, avec leur chemin ; un SmartAlbum garde l'icône d'album, avec ⟳ à droite pour le mettre à jour
    modifié : bouton « Plein écran » à droite des onglets de Piwigo ; en plein écran, onglets internes au même style

version 1.5 - 12/11/2026 - regroupe 1.4.1
    ajouté : tags créés dans Piwigo et attachés à aucune photo, jusqu'ici invisibles : regroupement « (sans photo) » dans « Gérer les tags », « Étiqueter › Tags » et « Tags de la sélection » ; au premier ajout à des photos, une fenêtre demande leur groupe d'accueil ; compteur « sans photo » dans l'onglet Index
    ajouté : champ de filtre au-dessus des arbres « Étiqueter › Albums » et « Étiqueter › Tags » (sans casse ni accents, un filtre par onglet)
    ajouté : clic sur l'icône ⟳ d'un SmartAlbum : aperçu de ce que SmartAlbums changerait (photos entrées / sorties, photos rangées à la main inchangées) puis mise à jour de cet album sur confirmation ; sur ⚠, explication et lien vers ses critères
    ajouté : traduction anglaise complète (écrans, messages du serveur, interface JavaScript) ; le français reste la langue de référence
    corrigé : listes de groupes de « Créer un tag » / « Créer un groupe » bloquées sur le groupe choisi dans l'arbre ; « (racine) » proposé pour un groupe de premier niveau
    corrigé : titre « Sauvegardes » au lieu de « Tag Manager » sur l'onglet Manager ; texte périmé dans Paramètres › Droits
    corrigé : icône ↗ retirée des tags sans photo (erreur dans la galerie) et des albums vides

version 1.4.1 - 12/11/2026
    ajouté : les tags créés dans Piwigo et attachés à aucune photo (jusqu'ici invisibles : l'indexation ne lit que les tags qui ont des photos) apparaissent dans un regroupement « (sans photo) », en fin d'arbre, dans « Gérer les tags », « Étiqueter › Tags » et « Tags de la sélection ». Dans « Gérer les tags » : renommer, ranger dans un groupe, fusionner ou supprimer (aucun fichier touché). Un tag sans photo qui a déjà un chemin (créé depuis « Gérer les tags ») reste à sa place
    ajouté : au premier ajout d'un tag « (sans photo) » à des photos (case cochée ou glisser-déposer), une fenêtre demande son groupe d'accueil ; le rangement est immédiat (index seulement, action tag_place_nophoto, niveau « Gérer : créer »), puis l'ajout entre dans la file avec le chemin complet
    ajouté : onglet Index, compteur « sans photo »
    ajouté : champ de filtre au-dessus de l'arbre « Étiqueter › Albums » et « Étiqueter › Tags » (un filtre gardé par onglet), sans casse ni accents ; les parents d'un élément trouvé sont ouverts, la branche d'un élément trouvé reste consultable
    corrigé : « Gérer les tags », panneaux « Créer un tag » / « Créer un groupe » : la liste des groupes ne proposait que le groupe choisi dans l'arbre (champ pré-rempli, que la liste du navigateur prenait pour un filtre). Le champ reste vide, le groupe de l'arbre est affiché en indication et pris si on ne saisit rien ; « (racine) » proposé pour créer un groupe au premier niveau
    ajouté : « Étiqueter › Albums » : un clic sur l'icône ⟳ d'un SmartAlbum (ou sur la mention ⟳ de la barre d'outils) propose de le mettre à jour : aperçu calculé par SmartAlbums sans rien écrire (nombre de photos avant → après, + entrées / − sorties, photos rangées à la main inchangées), puis smart_make_associations() sur confirmation (le même calcul que « Régénérer » de SmartAlbums, cet album seulement). Sur ⚠ : explication et lien vers les critères dans SmartAlbums (webmestres) ; un SmartAlbum sans critère n'est pas mis à jour. Actions smart_preview / smart_refresh (niveau « Étiqueter », albums visibles par l'utilisateur). Corrigé après le premier essai : message d'erreur furtif et photos affichées « orphelines » après la mise à jour (liste des albums mal rechargée : la mise à jour était faite, l'affichage non) ; photos rangées à la main jamais comptées (colonne smart = ENUM texte)
    ajouté : traduction anglaise complète (jusqu'ici une quinzaine de libellés de l'onglet Index) : gabarits, messages du serveur (erreurs, aperçus, réconciliation, journal) et toute l'interface JavaScript (dictionnaire language/en_UK/js.lang.php, envoyé à la page seulement si la langue de l'utilisateur n'est pas le français). Le français reste la langue pivot : language/fr_FR reprend automatiquement toutes les clés. Outils : dev/i18n_wrap.js (--check), dev/i18n_php_keys.js
    corrigé : Paramètres › Droits disait encore « pour l'instant seuls les webmestres accèdent à cette page » : les droits sont vérifiés à chaque action et les non-webmestres autorisés passent par le bouton « Tag manager » de la galerie
    corrigé : titre de la page d'administration « Sauvegardes » au lieu de « Tag Manager » sur l'onglet Manager (Piwigo prend le premier h2 de la page, ici celui des Paramètres)
    corrigé : icône ↗ (ouvrir dans la galerie) retirée des tags sans photo (la galerie affichait une erreur) et des albums vides (page vide) ; elle reste sur un album sans JPEG qui contient d'autres fichiers (vidéos…)

version 1.4 - 11/11/2026 - regroupe 1.3.1
    modifié : « Étiqueter › Albums » montre les albums de la galerie (synchronisés et créés dans Piwigo) dans l'ordre de « Gestion des albums », au lieu des répertoires physiques et du faux album « upload / année / mois » ; une photo apparaît dans chaque album où elle est rangée, qu'elle vienne de galleries ou d'un envoi (upload)
    ajouté : « Photos orphelines » en fin d'arbre (webmestres seulement) ; albums sans photo JPEG grisés ; icône ⟳ et mention dans la barre d'outils pour les albums SmartAlbums (contenu calculé d'après les tags)
    modifié : par tag, bandeaux et liste « Albums » de la fiche par album de rattachement (album de stockage, sinon le premier de ses albums dans l'arbre, albums ordinaires avant les SmartAlbums)
    ajouté : icône ⚠ pour un SmartAlbum sans critère (SmartAlbums y range presque toute la galerie, ex. « 1979-1980 Maroc ») ou contenant plus de 80 % des photos ; jamais choisi comme album de rattachement

version 1.3.1 - 11/11/2026
    modifié : « Étiqueter › Albums » montre les albums de la galerie (synchronisés et créés dans Piwigo) dans l'ordre de « Gestion des albums », au lieu des répertoires physiques et du faux album « upload / année / mois » ; une photo apparaît dans chaque album où elle est rangée, qu'elle vienne de galleries ou d'un envoi (upload)
    ajouté : « Photos orphelines » en fin d'arbre (webmestres seulement) ; albums sans photo JPEG grisés ; icône ⟳ et mention dans la barre d'outils pour les albums SmartAlbums (contenu calculé d'après les tags)
    modifié : par tag, bandeaux et liste « Albums » de la fiche par album de rattachement (album de stockage, sinon le premier de ses albums dans l'arbre, albums ordinaires avant les SmartAlbums)
    ajouté : icône ⚠ pour un SmartAlbum sans critère (SmartAlbums y range presque toute la galerie, ex. « 1979-1980 Maroc ») ou contenant plus de 80 % des photos ; jamais choisi comme album de rattachement

version 1.3 - 11/11/2026 - regroupe 1.2.1 à 1.2.5
    modifié : « À réconcilier » en langage courant, une ligne par nom partagé entre une personne et un tag ordinaire
    ajouté : « Séparer » (personne et tag ordinaire de même nom : le tag ordinaire prend un autre nom, la personne garde le sien) et « Ce n'est pas un visage » (tag marqué visage par face_tag à tort, retiré de la liste des visages après relecture des fichiers)
    ajouté : par tag, photos regroupées par album avec un bandeau d'album ; icône ↗ au survol d'un album ou d'un tag pour l'ouvrir dans la galerie
    ajouté : « Gérer les tags » : panneaux « Créer un tag » et « Créer un groupe » (groupe possible au premier niveau)
    ajouté : clic droit dans les arbres : déplier / replier la ligne cliquée et ses sous-niveaux
    corrigé : titres des fenêtres invisibles (l'administration de Piwigo masque les h2)
    corrigé : « Déplacer… » (tag ou groupe) ne proposait que le groupe actuel (champ pré-rempli, que la liste du navigateur prenait pour un filtre) : champ vide, emplacement actuel affiché au-dessus, « (racine) » proposé pour un groupe, un groupe n'est plus proposé dans lui-même, refus si la destination est l'emplacement actuel

version 1.2.5 - 11/11/2026
    ajouté : le menu du clic droit des arbres (Albums, Tags, Tags de la sélection, Gérer les tags) propose aussi « Déplier « X » » et « Replier « X » » pour la ligne cliquée : la branche entière (la ligne et tous ses sous-niveaux) ; « Tout déplier / Tout replier » restent en dessous. Sur une ligne sans sous-niveau, seul « Tout … » est proposé

version 1.2.4 - 11/11/2026
    ajouté : dans « Gérer les tags », sous la fiche, deux panneaux côte à côte « Créer un tag » et « Créer un groupe » : nom + groupe d'accueil, prérempli avec le groupe choisi dans l'arbre (ou celui du tag choisi) tant qu'on ne le saisit pas soi-même ; Entrée valide. Le tag est créé sans photo ; le groupe est créé vide, au premier niveau si le groupe parent est vide (impossible jusqu'ici). L'élément créé est sélectionné dans l'arbre

version 1.2.3 - 11/11/2026
    ajouté : dans « Étiqueter », au survol d'un album (onglet Albums) ou d'un tag (onglet Tags) du panneau de gauche, une petite icône ↗ ouvre sa page dans la galerie, dans un nouvel onglet (vrai lien : clic molette possible ; le clic sur le nom garde son rôle ; un groupe de tags, qui n'est pas un tag Piwigo, n'a pas d'icône). Remplace le double clic d'abord essayé, qui se mélangeait avec le simple clic

version 1.2.2 - 11/11/2026
    modifié : dans « Étiqueter », quand on choisit un tag dans le panneau de gauche, les photos sont regroupées par album (ordre de l'arbre des albums) et un bandeau indique l'album avant ses photos (chemin complet, nombre de photos sur la page, « suite » en haut d'une page qui continue l'album précédent)

version 1.2.1 - 11/11/2026
    modifié : « À réconcilier » affiche une seule ligne, en langage courant, par nom partagé entre une personne et un tag ordinaire (au lieu de deux lignes « Inscrit comme visage par face_tag » et « Homonyme » qui renvoyaient vers face_tag ou digiKam)
    ajouté : « Séparer » (personne et tag ordinaire de même nom) : le tag ordinaire prend un autre nom (« visage (tag) » proposé) dans les fichiers et dans Piwigo, la personne garde le tag d'origine. Aperçu exact : toutes les photos du tag sont lues (500 au plus) ; le cadre de visage et l'entrée Personnes|… ne sont jamais touchés ; une photo qui porte les deux garde les deux tags
    ajouté : « Ce n'est pas un visage » (tag marqué visage par face_tag alors que ses photos le rangent ailleurs, ex. le chien « Arak ») : après relecture des fichiers, le tag est retiré de la liste des visages de face_tag (ligne supprimée, comme le fait face_tag_editor). Si une photo le porte bien comme personne, rien n'est retiré et la ligne propose « Séparer »
    corrigé : le titre des fenêtres (aperçus, confirmations) n'apparaissait pas : l'administration de Piwigo masque tous les titres h2

version 1.2 - 10/11/2026 - regroupe 1.1.2 et 1.1.3
    corrigé : un tag ordinaire inscrit un jour comme visage dans le registre de face_tag (ex. « Arak », un chien rangé sous FAUNE|Chiens famille) restait invisible dans le plugin. Les fichiers font foi : s'ils le rangent hors de « Personnes », il est traité comme un tag normal et signalé dans « À réconcilier ». Relancer l'indexation après la mise à jour
    modifié : créer ou renommer un tag avec le nom d'un tag de visage affiche un message explicite (dans une fenêtre avec un bouton « Fermer » depuis « Étiqueter »)

version 1.1.3 - 10/11/2026
    modifié : créer ou renommer un tag avec le nom d'un tag de visage (personne, invisible dans l'arbre du plugin) affiche un message explicite au lieu de « existe déjà » ; dans « Étiqueter », un refus de création s'affiche dans une fenêtre avec un bouton « Fermer » (et non plus dans un message qui disparaît)

version 1.1.2 - 10/11/2026
    corrigé : un tag normal inscrit un jour comme visage dans le registre de face_tag (table facetag_person_tags) restait invisible, même après suppression du cadre de visage, car face_tag / face_tag_editor n'en retirent jamais un nom (ex. « Arak », un chien : FAUNE|Chiens famille|Arak dans tous les fichiers). Les fichiers font foi : un tag du registre dont l'indexation trouve un chemin hors racine réservée est traité comme un tag normal et signalé dans « À réconcilier » ; un tag du registre sans chemin (à plat) reste réservé
    modifié : l'indexation lit aussi les tags du registre de visages (pour savoir s'ils sont sous Personnes|… ou ailleurs). Relancer l'indexation après la mise à jour

version 1.1 - 09/11/2026
    le moteur réécrit les huit champs de mots-clés écrits par digiKam (dc:subject, lr:hierarchicalSubject, digiKam:TagsList, MicrosoftPhoto:LastKeywordXMP, mediapro:CatalogSets, acdsee:categories, IPTC 2:25, Exif XPKeywords) : renommer, déplacer ou supprimer un tag ne laisse plus l’ancien nom dans une partie des métadonnées
    renommer ou ranger un tag qui a des tags dessous fait suivre leurs chemins ; supprimer ou fusionner un tel tag avertit avant d’appliquer
    les entrées de personnes (Personnes|…) ne sont jamais modifiées, même quand un nom est à la fois un tag et une personne ; ces homonymes sont signalés dans « À réconcilier »
    corrigé : renommer un tag dont le nom complet dépasse 64 octets (IPTC 2:25) échouait avec verification_champs : la valeur IPTC coupée à 64 octets était identique à l'ancien nom (ex. « … (Siem » pour « … (Siem Reap) ») et le contrôle final la prenait pour un reste périmé. La coupure retire aussi l'espace final, et une liste IPTC inchangée après coupure n'est plus réécrite
    corrigé : le plugin lisait la PREMIÈRE occurrence de <x:xmpmeta du fichier. Certains fichiers (ex. retouchés avec Picasa) ont un paquet XMP vide dans leur bloc Exif AVANT le vrai XMP : leurs mots-clés étaient ignorés et leur tag classé « sans chemin » alors que le fichier porte le chemin complet. Le XMP est maintenant lu dans le segment APP1 standard (structure JPEG), comme le fait ExifTool ; la relecture de contrôle après écriture aussi
    amélioré : pour proposer un renommage, « À réconcilier » lit jusqu'à 6 photos du tag (au lieu d'une seule) : un fichier témoin sans chemin ne masque plus le nom présent dans les autres
    corrigé : un tag dont le nom, dans Piwigo, ne diffère de celui des fichiers que par un accent ou une majuscule (ex. « sélection » dans Piwigo, « Selection » dans les fichiers) était classé « sans chemin » alors que les fichiers portent le chemin complet. Piwigo compare les noms de tags sans tenir compte des accents ni de la casse (collation MySQL) : tous les noms sont maintenant comparés ainsi (nouveau lib/names.php), par l'indexeur, la réconciliation et le moteur d'écriture (renommer, fusionner, supprimer et ranger retrouvent l'entrée du fichier même écrite autrement). Le chemin enregistré garde l'orthographe du tag Piwigo. Relancer l'indexation après la mise à jour
    corrigé : « Ranger » (et « Fusionner ») un tag n'inscrivait rien dans les fichiers qui ne portaient aucun mot-clé de ce nom : le classement n'existait que dans Piwigo et disparaissait à la réindexation (ex. un tag dont les photos n'ont aucun mot-clé XMP / IPTC). Ces fichiers reçoivent maintenant le chemin complet (dc:subject, lr, digiKam, IPTC) ; un fichier qui porte déjà le nom est traité comme avant, et celui où seule une personne porte ce nom n'est pas touché
    modifié : « Ranger » dans le groupe où le tag est déjà classé est accepté (il complète les fichiers) ; l'aperçu avertit que des fichiers seront complétés
    corrigé : « ranger » ajoutait le chemin d'un tag dans digiKam:TagsList d'un fichier où seule une personne portait ce nom
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
