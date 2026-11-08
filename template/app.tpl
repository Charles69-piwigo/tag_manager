<link rel="stylesheet" href="{$TM_ASSETS}css/app.css?v={$TM_CSS_V}">

<div id="tm-app"{if $TM_PUBLIC} class="full" data-public="1" data-level="{$TM_LEVEL}"{/if} data-url="{$TM_URL}" data-token="{$TM_TOKEN}">
<div class="app">
  <div class="top">
    <div class="stabs" role="tablist" aria-label="{'Écran'|@translate}">
      <button class="stab" role="tab" data-screen="tag" aria-selected="true">{'Étiqueter'|@translate}</button>
      <button class="stab" role="tab" data-screen="mng" aria-selected="false">{'Gérer les tags'|@translate}</button>
    </div>
    <p id="tm-sub"></p>
    {if $TM_PUBLIC}<a class="btn" href="{$TM_HOME}" style="text-decoration:none">← {'Galerie'|@translate}</a>
    {else}<button class="btn" id="fs-toggle" aria-pressed="false" title="{'Masquer les menus de Piwigo'|@translate}">⛶ {'Plein écran'|@translate}</button>{/if}
    <button class="btn gear" data-screen="set" aria-selected="false">⚙ {'Paramètres'|@translate}</button>
  </div>

  <div id="jobbar"></div>

  <!-- Écran 1 : étiqueter des photos -->
  <div class="main" id="app-tag">
    <section class="pane" aria-label="{'Navigation'|@translate}">
      <div class="tabs" role="tablist">
        <button class="tab" role="tab" id="tab-albums" aria-selected="true" data-tab="albums">{'Albums'|@translate}</button>
        <button class="tab" role="tab" id="tab-tags" aria-selected="false" data-tab="tags">{'Tags'|@translate}</button>
      </div>
      <div class="pane-b" id="tree"><div class="loading">…</div></div>
    </section>

    <div class="rz" role="separator" aria-orientation="vertical" tabindex="0" data-rz="w1" aria-label="{'Largeur du panneau de navigation'|@translate}"></div>
    <section class="pane center" aria-label="{'Photos'|@translate}">
      <div class="toolbar" id="toolbar"></div>
      <div class="pane-b" style="padding:0"><div class="grid" id="grid"></div></div>
      <div id="pager" style="display:flex;flex-wrap:wrap;gap:4px;justify-content:center;align-items:center;padding:6px 10px"></div>
      <div id="pendbar"></div>
      <div class="sizebar"><label for="tsize">{'Taille des miniatures'|@translate}</label><input type="range" id="tsize" min="90" max="400" step="2" value="118"><span class="cnt" id="tsizev">118 px</span></div>
    </section>

    <div class="rz" role="separator" aria-orientation="vertical" tabindex="0" data-rz="w3" aria-label="{'Largeur du panneau des tags de la sélection'|@translate}"></div>
    <section class="pane" aria-label="{'Tags de la sélection'|@translate}">
      <div class="pane-h" id="panel-h">{'Tags de la sélection'|@translate}</div>
      <div class="pf">
        <input id="pfilter" type="search" autocomplete="off" placeholder="{'Filtrer les tags…'|@translate}" aria-label="{'Filtrer les tags disponibles'|@translate}">
        <label class="chk"><input type="checkbox" id="ponly"> {'Seulement les tags attribués'|@translate}</label>
      </div>
      <div class="pane-b" id="rows"></div>
      <div class="add" id="newtag-box">
        <b>{'Créer un tag'|@translate}</b>
        <input id="nt-name" class="fin" type="text" autocomplete="off" placeholder="{'Nom du nouveau tag'|@translate}" aria-label="{'Nom du nouveau tag'|@translate}">
        <input id="nt-group" class="fin" type="text" autocomplete="off" list="dl-ntg" placeholder="{'Groupe, par exemple ALBUM|2024 SLOVENIE'|@translate}" aria-label="{'Groupe d’accueil du nouveau tag'|@translate}">
        <datalist id="dl-ntg"></datalist>
        <button class="btn" id="nt-go">{'Créer et ajouter à la sélection'|@translate}</button>
        <small>{'Le tag est créé tout de suite dans Piwigo ; son ajout aux photos sélectionnées passe par « Aperçu et appliquer ».'|@translate}</small>
      </div>
    </section>
  </div>

  <!-- Écran 2 : gérer l'arbre des tags -->
  <div class="mngwrap" id="app-mng" hidden>
    <div class="subtabs" role="tablist" aria-label="{'Vue'|@translate}">
      <button class="subtab" role="tab" data-sub="tree" aria-selected="true">{'Arbre des tags'|@translate}</button>
      <button class="subtab" role="tab" data-sub="rec" aria-selected="false">{'À réconcilier'|@translate} <span class="chip" id="rec-n">…</span></button>
    </div>
    <div class="main mng" id="mng-tree">
      <section class="pane" aria-label="{'Arbre des tags'|@translate}">
        <div class="pf"><input id="mfilter" type="search" autocomplete="off" placeholder="{'Chercher un tag ou un groupe…'|@translate}" aria-label="{'Chercher dans l’arbre des tags'|@translate}"></div>
        <div class="pane-b" id="mtree"></div>
        <div class="hint" style="padding:6px 10px 8px">{'Les compteurs des groupes additionnent leurs tags.'|@translate}</div>
      </section>
      <div class="rz" role="separator" aria-orientation="vertical" tabindex="0" data-rz="m1" aria-label="{'Largeur de l’arbre des tags'|@translate}"></div>
      <section class="pane" aria-label="{'Fiche'|@translate}">
        <div class="pane-b" id="mfiche" style="padding:0"></div>
      </section>
    </div>

    <div class="pane rec" id="mng-rec" hidden>
      <div class="toolbar"><div class="crumb" id="rec-sum"></div><button class="btn" id="rec-bulk">{'Appliquer les suggestions fiables'|@translate}</button></div>
      <div class="hint" style="padding:8px 12px">{'Tags de Piwigo dont le nom ne se retrouve avec un chemin dans aucun des fichiers testés (huit par tag). Les propositions sont calculées à partir du nom et du fichier témoin. « Appliquer » ouvre un aperçu : rien n’est écrit avant votre confirmation.'|@translate}</div>
      <div class="pane-b" style="padding:0"><div class="tscroll"><table class="rc" id="rec-table"></table></div></div>
      <datalist id="dl-tags"></datalist><datalist id="dl-groups"></datalist>
    </div>
  </div>

  <!-- Écran 3 : paramètres -->
  <div class="set" id="app-set" hidden>
    <div class="setwrap">
      <section class="card" aria-labelledby="h-save">
        <h2 id="h-save">{'Sauvegardes'|@translate}</h2>
        <label class="chk"><input type="checkbox" id="set-orig"> {'Créer une copie .original avant la première écriture dans un fichier'|@translate}</label>
        <p class="sub">{'Désactivé par défaut. Un renommage ou un déplacement sur plusieurs milliers de photos créerait autant de copies. Cette case donne la valeur initiale de la case équivalente de chaque aperçu avant écriture.'|@translate}</p>
      </section>
      <section class="card" aria-labelledby="h-thumb">
        <h2 id="h-thumb">{'Miniatures'|@translate}</h2>
        <p class="sub">{'Taille de l’image chargée pour les miniatures de l’écran Étiqueter. Plus la taille est grande, plus les miniatures sont nettes (et plus le chargement est lourd). Les photos sont toujours affichées entières, sans recadrage.'|@translate}</p>
        <select class="fin" style="width:auto" id="set-thumb" aria-label="{'Taille des miniatures'|@translate}">
          <option value="medium">Medium</option><option value="large">Large</option><option value="xlarge">XL</option>
        </select>
      </section>
      <section class="card" aria-labelledby="h-tw">
        <h2 id="h-tw">{'Taille d’affichage des miniatures'|@translate}</h2>
        <p class="sub">{'Largeur par défaut des miniatures à l’ouverture de l’écran Étiqueter, en pixels (90 à 400). Le curseur sous la grille permet de la changer ponctuellement.'|@translate}</p>
        <input class="fin" style="width:90px" type="number" min="90" max="400" step="10" id="set-tw" aria-label="{'Taille d’affichage des miniatures'|@translate}"> px
      </section>
      <section class="card" aria-labelledby="h-res">
        <h2 id="h-res">{'Tags de visages'|@translate}</h2>
        <p class="sub">{'Les tags de personnes sont gérés par face_tag_editor et digiKam, pas par ce plugin : ils n’apparaissent nulle part ici et ne sont jamais modifiés. Racines de hiérarchie réservées, séparées par des virgules :'|@translate}</p>
        <input class="fin" id="set-reserved" type="text" autocomplete="off" aria-label="{'Racines réservées'|@translate}">
        <p class="sub" style="margin:0">{'Les tags enregistrés comme tags de visage par face_tag_editor sont aussi exclus automatiquement, où qu’ils soient rangés.'|@translate}</p>
      </section>
      <section class="card" aria-labelledby="h-rights">
        <h2 id="h-rights">{'Droits'|@translate}</h2>
        <p class="sub">{'Attribuez les droits à des groupes Piwigo. Ajoutez un utilisateur seulement pour une exception. Un utilisateur cumule ses droits et ceux de tous ses groupes : le plus large l’emporte. Les webmestres ont toujours tous les droits.'|@translate}</p>
        <div class="tscroll"><table class="rt" id="rights"></table></div>
        <div class="addrow">
          <select class="fin" style="width:auto" id="r-kind" aria-label="{'Type'|@translate}"><option value="g">{'Groupe'|@translate}</option><option value="u">{'Utilisateur'|@translate}</option></select>
          <select class="fin" style="width:auto;min-width:160px" id="r-who" aria-label="{'Groupe ou utilisateur à ajouter'|@translate}"></select>
          <button class="btn" id="r-add">{'Ajouter à la table'|@translate}</button>
        </div>
        <dl class="kv">
          <dt>{'Étiqueter'|@translate}</dt><dd>{'Affecter et retirer des tags existants, sur les photos que la personne peut voir.'|@translate}</dd>
          <dt>{'Gérer : créer'|@translate}</dt><dd>{'Écran « Gérer les tags », limité à la création de groupes et de tags.'|@translate}</dd>
          <dt>{'Gérer : tout'|@translate}</dt><dd>{'En plus : renommer, déplacer, fusionner et supprimer.'|@translate}</dd>
        </dl>
        <p class="sub" style="margin:0">{'Ces droits seront appliqués par le serveur à l’étape d’écriture ; pour l’instant seuls les webmestres accèdent à cette page.'|@translate}</p>
      </section>
      <section class="card" aria-labelledby="h-log">
        <h2 id="h-log">{'Journal des écritures'|@translate}</h2>
        <div class="tscroll"><table class="rt" id="joblog"></table></div>
      </section>
      <div><button class="btn primary" id="set-save">{'Enregistrer les paramètres'|@translate}</button></div>
    </div>
  </div>
</div>
<div id="layer"></div>
</div>

<script src="{$TM_ASSETS}js/app.js?v={$TM_JS_V}"></script>
