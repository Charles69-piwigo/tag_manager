<link rel="stylesheet" href="{$TM_ASSETS}css/app.css?v={$TM_CSS_V}">

{* l'administration de Piwigo prend le PREMIER h2 de la page comme titre (admin.tpl) : sans celui-ci, c'était « Sauvegardes » (Paramètres) *}
{if !$TM_PUBLIC}<div class="titrePage"><h2>Tag Manager</h2></div>{/if}
<div id="tm-app" class="{if $TM_PUBLIC}full{/if}{if $TM_DARK} dark{/if}"{if $TM_PUBLIC} data-public="1" data-level="{$TM_LEVEL}"{/if} data-url="{$TM_URL}" data-token="{$TM_TOKEN}" data-gallery="{$TM_GALLERY}" data-start="{$TM_SCREEN}">
<div class="app">
  {* Onglets internes : affichés en plein écran et sur la page publique ; dans l'administration, ce sont les onglets de Piwigo *}
  <div class="tm-bar">
    <ul class="tm-tabs" role="tablist" aria-label="{'Écran'|@translate}">
      <li><button class="tm-tab" role="tab" data-screen="tag" aria-selected="true"><i class="icon-tags"></i>{'Étiqueter'|@translate}</button></li>
      <li><button class="tm-tab" role="tab" data-screen="mng" aria-selected="false"><i class="icon-flow-tree"></i>{'Gérer les tags'|@translate}</button></li>
      {if !$TM_PUBLIC}<li><a class="tm-tab" href="{$TM_INDEX_URL}"><i class="icon-database"></i>{'Index'|@translate}</a></li>
      <li><button class="tm-tab gear" role="tab" data-screen="set" aria-selected="false"><i class="icon-cog"></i>{'Paramètres'|@translate}</button></li>{/if}
    </ul>
    <span class="sp"></span>
    {if $TM_PUBLIC}<a class="btn" href="{$TM_HOME}"><i class="icon-left-open"></i> {'Galerie'|@translate}</a>
    {else}<button class="btn" id="fs-toggle" aria-pressed="false" title="{'Masquer les menus de Piwigo'|@translate}"><i class="icon-resize-full"></i> {'Plein écran'|@translate}</button>{/if}
  </div>

  <div id="jobbar"></div>

  <!-- Écran 1 : étiqueter des photos -->
  <div class="main" id="app-tag">
    <section class="pane" aria-label="{'Navigation'|@translate}">
      <div class="pane-h">
        <div class="tabs" role="tablist">
          <button class="tab" role="tab" id="tab-albums" aria-selected="true" data-tab="albums"><i class="icon-folder-open"></i> {'Albums'|@translate}</button>
          <button class="tab" role="tab" id="tab-tags" aria-selected="false" data-tab="tags"><i class="icon-tags"></i> {'Tags'|@translate}</button>
        </div>
      </div>
      <div class="pf"><label class="search"><i class="icon-search"></i><input id="tfilter" type="search" autocomplete="off" placeholder="{'Filtrer les albums…'|@translate}" aria-label="{'Filtrer l’arbre de navigation'|@translate}"></label></div>
      <div class="pane-b" id="tree"><div class="loading">…</div></div>
    </section>

    <div class="rz" role="separator" aria-orientation="vertical" tabindex="0" data-rz="w1" aria-label="{'Largeur du panneau de navigation'|@translate}"></div>
    <section class="pane center" aria-label="{'Photos'|@translate}">
      <div class="toolbar" id="toolbar"></div>
      <div class="pane-b" style="padding:0"><div class="grid" id="grid"></div></div>
      <div id="pager" class="pager"></div>
      <div class="sizebar"><label for="tsize">{'Taille des miniatures'|@translate}</label><input type="range" id="tsize" min="90" max="400" step="2" value="118"><span id="tsizev">118 px</span></div>
    </section>

    <div class="rz" role="separator" aria-orientation="vertical" tabindex="0" data-rz="w3" aria-label="{'Largeur du panneau des tags de la sélection'|@translate}"></div>
    <section class="pane" aria-label="{'Tags de la sélection'|@translate}">
      <div class="pane-h" id="panel-h">{'Tags de la sélection'|@translate}</div>
      <div class="pf">
        <label class="search"><i class="icon-search"></i><input id="pfilter" type="search" autocomplete="off" placeholder="{'Filtrer les tags…'|@translate}" aria-label="{'Filtrer les tags disponibles'|@translate}"></label>
        <label class="chk"><input type="checkbox" id="ponly"> {'Seulement les tags attribués'|@translate}</label>
        <div class="legend"><span><i class="sw all"></i>{'toutes'|@translate}</span><span><i class="sw some"></i>{'une partie'|@translate}</span><span><i class="sw"></i>{'aucune'|@translate}</span></div>
      </div>
      <div class="pane-b" id="rows"></div>
      <div class="add" id="newtag-box">
        <b><span class="ic sm green"><i class="icon-plus"></i></span>{'Créer un tag'|@translate}</b>
        <input id="nt-name" class="fin" type="text" autocomplete="off" placeholder="{'Nom du nouveau tag'|@translate}" aria-label="{'Nom du nouveau tag'|@translate}">
        <input id="nt-group" class="fin" type="text" autocomplete="off" list="dl-ntg" placeholder="{'Groupe, par exemple ALBUM|2024 SLOVENIE'|@translate}" aria-label="{'Groupe d’accueil du nouveau tag'|@translate}">
        <datalist id="dl-ntg"></datalist>
        <div><button class="btn primary" id="nt-go"><i class="icon-plus"></i> {'Créer et ajouter à la sélection'|@translate}</button></div>
        <small>{'Le tag est créé tout de suite dans Piwigo ; son ajout aux photos sélectionnées passe par « Aperçu et appliquer ».'|@translate}</small>
      </div>
    </section>
  </div>
  <div class="savebar" id="pendbar"></div>

  <!-- Écran 2 : gérer l'arbre des tags -->
  <div class="mngwrap" id="app-mng" hidden>
    <div class="subtabs" role="tablist" aria-label="{'Vue'|@translate}">
      <button class="subtab" role="tab" data-sub="tree" aria-selected="true"><i class="icon-flow-tree"></i> {'Arbre des tags'|@translate}</button>
      <button class="subtab" role="tab" data-sub="rec" aria-selected="false"><i class="icon-attention"></i> {'À réconcilier'|@translate} <span class="cnt" id="rec-n">…</span></button>
    </div>
    <div class="main mng" id="mng-tree">
      <section class="pane" aria-label="{'Arbre des tags'|@translate}">
        <div class="pf"><label class="search"><i class="icon-search"></i><input id="mfilter" type="search" autocomplete="off" placeholder="{'Chercher un tag ou un groupe…'|@translate}" aria-label="{'Chercher dans l’arbre des tags'|@translate}"></label></div>
        <div class="pane-b" id="mtree"></div>
        <div class="hint" style="padding:6px 10px 8px">{'Les compteurs des groupes additionnent leurs tags.'|@translate}</div>
      </section>
      <div class="rz" role="separator" aria-orientation="vertical" tabindex="0" data-rz="m1" aria-label="{'Largeur de l’arbre des tags'|@translate}"></div>
      <section class="pane" aria-label="{'Fiche'|@translate}">
        <div class="pane-b" style="padding:0"><div id="mfiche"></div>
        <div class="mkadd">
          <div class="add" id="mnewtag-box">
            <b><span class="ic sm green"><i class="icon-tags"></i></span>{'Créer un tag'|@translate}</b>
            <input id="mt-name" class="fin" type="text" autocomplete="off" placeholder="{'Nom du nouveau tag'|@translate}" aria-label="{'Nom du nouveau tag'|@translate}">
            <input id="mt-group" class="fin" type="text" autocomplete="off" list="dl-ntg" placeholder="{'Groupe, par exemple ALBUM|2024 SLOVENIE'|@translate}" aria-label="{'Groupe d’accueil du nouveau tag'|@translate}">
            <div><button class="btn primary" id="mt-go"><i class="icon-plus"></i> {'Créer le tag'|@translate}</button></div>
            <small>{'Créé tout de suite dans Piwigo, sans photo ; il se remplit ensuite avec l’écran Étiqueter.'|@translate}</small>
          </div>
          <div class="add" id="mnewgroup-box">
            <b><span class="ic sm blue"><i class="icon-flow-tree"></i></span>{'Créer un groupe'|@translate}</b>
            <input id="mg-name" class="fin" type="text" autocomplete="off" placeholder="{'Nom du nouveau groupe'|@translate}" aria-label="{'Nom du nouveau groupe'|@translate}">
            <datalist id="dl-mgg"></datalist>
            <input id="mg-group" class="fin" type="text" autocomplete="off" list="dl-mgg" placeholder="{'Groupe parent (vide : premier niveau)'|@translate}" aria-label="{'Groupe parent du nouveau groupe, vide pour le premier niveau'|@translate}">
            <div><button class="btn primary" id="mg-go"><i class="icon-plus"></i> {'Créer le groupe'|@translate}</button></div>
            <small>{'Un groupe vide n’existe que dans le plugin, jusqu’à ce qu’on y crée un tag.'|@translate}</small>
          </div>
        </div></div>
      </section>
    </div>

    <div class="pane rec" id="mng-rec" hidden>
      <div class="toolbar"><div class="crumb" id="rec-sum"></div><button class="btn primary" id="rec-bulk"><i class="icon-ok"></i> {'Appliquer les suggestions fiables'|@translate}</button></div>
      <div class="hint" style="padding:8px 12px">{'Tags de Piwigo dont le nom ne se retrouve avec un chemin dans aucun des fichiers testés (huit par tag). Les propositions sont calculées à partir du nom et du fichier témoin. « Appliquer » ouvre un aperçu : rien n’est écrit avant votre confirmation.'|@translate}</div>
      <div class="pane-b" style="padding:0 10px"><div class="tscroll"><table class="rc" id="rec-table"></table></div></div>
      <datalist id="dl-tags"></datalist><datalist id="dl-groups"></datalist>
    </div>
  </div>

  <!-- Écran 3 : paramètres (même forme que la page Configuration de Piwigo) -->
  <div class="set" id="app-set" hidden>
    <div class="conf">
      <fieldset aria-labelledby="h-save">
        <legend id="h-save"><span class="ic blue"><i class="icon-floppy"></i></span>{'Sauvegardes'|@translate}</legend>
        <ul><li><label class="chk"><input type="checkbox" id="set-orig"> {'Créer une copie .original avant la première écriture dans un fichier'|@translate}</label>
          <p class="help">{'Désactivé par défaut. Un renommage ou un déplacement sur plusieurs milliers de photos créerait autant de copies. Cette case donne la valeur initiale de la case équivalente de chaque aperçu avant écriture.'|@translate}</p></li></ul>
      </fieldset>
      <fieldset aria-labelledby="h-thumb">
        <legend id="h-thumb"><span class="ic purple"><i class="icon-picture"></i></span>{'Miniatures'|@translate}</legend>
        <ul>
          <li><label for="set-thumb" class="lbl">{'Taille de l’image chargée'|@translate}</label>
            <select class="fin" style="width:auto" id="set-thumb"><option value="medium">Medium</option><option value="large">Large</option><option value="xlarge">XL</option></select>
            <p class="help">{'Taille de l’image chargée pour les miniatures de l’écran Étiqueter. Plus la taille est grande, plus les miniatures sont nettes (et plus le chargement est lourd). Les photos sont toujours affichées entières, sans recadrage.'|@translate}</p></li>
          <li><label for="set-tw" class="lbl">{'Taille d’affichage des miniatures'|@translate}</label>
            <input class="fin" style="width:90px" type="number" min="90" max="400" step="10" id="set-tw"> px
            <p class="help">{'Largeur par défaut des miniatures à l’ouverture de l’écran Étiqueter, en pixels (90 à 400). Le curseur sous la grille permet de la changer ponctuellement.'|@translate}</p></li>
        </ul>
      </fieldset>
      <fieldset aria-labelledby="h-res">
        <legend id="h-res"><span class="ic yellow"><i class="icon-user"></i></span>{'Tags de visages'|@translate}</legend>
        <ul><li><p class="help" style="margin-top:0">{'Les tags de personnes sont gérés par face_tag_editor et digiKam, pas par ce plugin : ils n’apparaissent nulle part ici et ne sont jamais modifiés. Racines de hiérarchie réservées, séparées par des virgules :'|@translate}</p>
          <input class="fin" id="set-reserved" type="text" autocomplete="off" style="max-width:420px" aria-label="{'Racines réservées'|@translate}">
          <p class="help">{'Les tags enregistrés comme tags de visage par face_tag_editor sont aussi exclus automatiquement, où qu’ils soient rangés.'|@translate}</p></li></ul>
      </fieldset>
      <fieldset aria-labelledby="h-rights">
        <legend id="h-rights"><span class="ic green"><i class="icon-key"></i></span>{'Droits'|@translate}</legend>
        <ul><li style="display:block"><p class="help" style="margin-top:0">{'Attribuez les droits à des groupes Piwigo. Ajoutez un utilisateur seulement pour une exception. Un utilisateur cumule ses droits et ceux de tous ses groupes : le plus large l’emporte. Les webmestres ont toujours tous les droits.'|@translate}</p>
          <div class="tscroll"><table class="rt" id="rights"></table></div>
          <div class="addrow">
            <select class="fin" style="width:auto" id="r-kind" aria-label="{'Type'|@translate}"><option value="g">{'Groupe'|@translate}</option><option value="u">{'Utilisateur'|@translate}</option></select>
            <select class="fin" style="width:auto;min-width:160px" id="r-who" aria-label="{'Groupe ou utilisateur à ajouter'|@translate}"></select>
            <button class="btn" id="r-add"><i class="icon-plus"></i> {'Ajouter à la table'|@translate}</button>
          </div>
          <dl class="kv">
            <dt>{'Étiqueter'|@translate}</dt><dd>{'Affecter et retirer des tags existants, sur les photos que la personne peut voir.'|@translate}</dd>
            <dt>{'Gérer : créer'|@translate}</dt><dd>{'Écran « Gérer les tags », limité à la création de groupes et de tags.'|@translate}</dd>
            <dt>{'Gérer : tout'|@translate}</dt><dd>{'En plus : renommer, déplacer, fusionner et supprimer.'|@translate}</dd>
          </dl>
          <p class="help">{'Ces droits sont vérifiés par le serveur à chaque action. Les membres autorisés qui ne sont pas webmestres ouvrent le plugin avec le bouton « Tag manager » de la barre d’outils de la galerie ; l’onglet Paramètres reste réservé aux webmestres.'|@translate}</p></li></ul>
      </fieldset>
      <fieldset aria-labelledby="h-log">
        <legend id="h-log"><span class="ic grey"><i class="icon-back-in-time"></i></span>{'Journal des écritures'|@translate}</legend>
        <ul><li style="display:block"><div class="tscroll"><table class="rt" id="joblog"></table></div></li></ul>
      </fieldset>
    </div>
    <div class="savebar"><span class="start"></span><span class="end"><button class="btn primary" id="set-save"><i class="icon-floppy"></i> {'Enregistrer les paramètres'|@translate}</button></span></div>
  </div>
</div>
<div id="layer"></div>
</div>

<script>window.TM_I18N = {$TM_I18N};</script>
<script src="{$TM_ASSETS}js/app.js?v={$TM_JS_V}"></script>
