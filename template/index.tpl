<link rel="stylesheet" href="{$TM_ASSETS}css/app.css?v={$TM_CSS_V}">
{literal}
<style>
#tm-app.tm-index{height:auto;min-height:0;padding-bottom:30px}
#tm-app .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;max-width:880px}
#tm-app .stat{background:var(--surface);box-shadow:var(--shadow);padding:10px 12px;color:var(--txt);font-size:11.5px}
#tm-app .stat b{display:block;font-size:20px;font-weight:600;color:var(--strong);font-variant-numeric:tabular-nums}
#tm-app .bar{height:8px;background:var(--surface);box-shadow:var(--shadow);overflow:hidden;flex:1 1 240px;max-width:420px}
#tm-app .bar>div{height:100%;width:0;background:var(--acc)}
#tm-app .itree ul{list-style:none;margin:0;padding-left:18px}
#tm-app .itree>ul{padding-left:0}
#tm-app .itree li{line-height:1.7}
#tm-app .itree .tm-group{font-weight:700;color:var(--strong)}
#tm-app .itree .tm-group>ul{font-weight:400;color:var(--txt)}
#tm-app .itree .tm-nb,#tm-app .flist .tm-nb{display:inline-block;font-size:10.5px;font-weight:700;padding:0 6px;border-radius:9px;background:var(--grey-b);color:var(--grey-f);margin-left:4px}
#tm-app .plist li{display:block}
#tm-app .tm-cols{display:flex;flex-wrap:wrap;gap:0 24px;align-items:flex-start}
#tm-app .tm-cols>fieldset{flex:1 1 360px;min-width:0}
#tm-app .flist{list-style:none;margin:0;padding:0}
#tm-app .flist li{display:block;line-height:1.8}
#tm-app .flist .fn{color:var(--strong)}
#tm-app .flist .fs{font-size:11px;color:var(--muted,#777);margin-left:8px}
#tm-app .flist .fs.warn{color:var(--warn-f);font-weight:600}
</style>
{/literal}

<div class="titrePage">
  <h2>Tag Manager</h2>
</div>

<div id="tm-app" class="tm-index{if $TM_DARK} dark{/if}">
<div class="conf" id="tm-index" data-url="{$TM_URL}" data-token="{$TM_TOKEN}" data-t-read="{'fichiers lus'|@translate}" data-t-done="{'terminé'|@translate}" data-t-error="{'Erreur :'|@translate}" data-t-faces="{'contrôle des visages :'|@translate}" data-t-facenone="{'tag Piwigo seul'|@translate}">

  <fieldset>
    <legend><span class="ic blue"><i class="icon-chart-bar"></i></span>{'Index des chemins de tags'|@translate}</legend>
    <ul>
      <li><p class="help" style="margin-top:0">{'Piwigo ne conserve que le dernier niveau des tags. L’index lit une photo par tag pour retrouver son chemin complet dans les fichiers (lecture seule : aucun fichier ni aucun tag n’est modifié).'|@translate} {'Il contrôle aussi chaque photo qui porte un tag de visage : la première fois toutes, ensuite seulement les nouvelles et celles dont Piwigo a resynchronisé les métadonnées.'|@translate}</p>
        <div class="stats">
          <div class="stat"><b>{$TM_SUMMARY.total_tags}</b>{'tags utilisés'|@translate}</div>
          <div class="stat"><b>{$TM_SUMMARY.ok}</b>{'avec chemin'|@translate}</div>
          <div class="stat"><b>{$TM_SUMMARY.flat}</b>{'à plat'|@translate}</div>
          <div class="stat"><b>{$TM_SUMMARY.nofile}</b>{'fichier introuvable'|@translate}</div>
          <div class="stat"><b>{$TM_SUMMARY.nophoto}</b>{'sans photo'|@translate}</div>
        </div></li>
    </ul>
  </fieldset>

  <fieldset>
    <legend><span class="ic purple"><i class="icon-arrows-cw"></i></span>{'Indexation'|@translate}</legend>
    <ul>
      <li>
        <button type="button" class="btn primary" id="tm-run"><i class="icon-arrows-cw"></i> {'Lancer l’indexation'|@translate}</button>
        <div class="bar" id="tm-barwrap" hidden><div id="tm-bar"></div></div>
        {if $TM_LAST}<span class="badge">{'Dernière indexation'|@translate} : {$TM_LAST}</span>{/if}
        <p class="help" id="tm-msg"></p>
      </li>
    </ul>
  </fieldset>

  {if $TM_PROBLEMS}
  <fieldset>
    <legend><span class="ic yellow"><i class="icon-attention"></i></span>{'Tags sans chemin hiérarchique'|@translate}</legend>
    <ul class="plist">
      <li><p class="help" style="margin-top:0">{'« À plat » : aucun des fichiers testés ne porte ce tag avec un niveau parent (huit photos au plus sont testées). Il peut exister avec son chemin dans d’autres fichiers. « Introuvable » : aucun des fichiers testés n’est lisible depuis le serveur.'|@translate}</p></li>
    {foreach from=$TM_PROBLEMS item=p}
      <li><b style="color:var(--strong)">{$p.name|escape}</b> <span class="status {if $p.status == 'flat'}n{else}err{/if}">{if $p.status == 'flat'}{'à plat'|@translate}{else}{'fichier introuvable'|@translate}{/if}</span></li>
    {/foreach}
    </ul>
  </fieldset>
  {/if}

  <div class="tm-cols">
  <fieldset>
    <legend><span class="ic green"><i class="icon-flow-tree"></i></span>{'Arbre indexé'|@translate}</legend>
    <ul><li style="display:block">
    {if $TM_TREE}
      <div class="itree">{$TM_TREE}</div>
    {else}
      <p class="help">{'Aucun chemin indexé pour l’instant.'|@translate}</p>
    {/if}
    </li></ul>
  </fieldset>

  <fieldset>
    <legend><span class="ic yellow"><i class="icon-user"></i></span>{'Visages'|@translate} ({$TM_FACES|@count})</legend>
    <ul><li style="display:block">
      <p class="help" style="margin-top:0">{'Tags de visage (liste de face_tag et noms qui ont un cadre de visage dans un fichier), avec le résultat du dernier contrôle des photos. Affichage seul : ils ne sont pas gérés par ce plugin.'|@translate}</p>
    {if $TM_FACES}
      <ul class="flist">
      {foreach from=$TM_FACES item=f}
        <li><span class="fn">{$f.name|escape}</span><span class="tm-nb" title="{'photos'|@translate}">{$f.total}</span>
          {if $f.face}<span class="fs">{$f.face} {'avec cadre'|@translate}</span>{/if}
          {if $f.path}<span class="fs" title="{'Le fichier porte le mot-clé de la personne, mais aucun cadre de visage à ce nom'|@translate}">{$f.path} {'visage sans cadre'|@translate}</span>{/if}
          {if $f.none}<span class="fs warn" title="{'Le fichier ne porte ni cadre ni mot-clé à ce nom : le tag n\'existe que dans Piwigo'|@translate}">{$f.none} {'tag Piwigo seul'|@translate}</span>{/if}
          {if $f.ignored}<span class="fs">{$f.ignored} {'ignorée(s)'|@translate}</span>{/if}
          {if $f.other}<span class="fs">{$f.other} {'autre(s)'|@translate}</span>{/if}
        </li>
      {/foreach}
      </ul>
    {else}
      <p class="help">{'Aucun tag de visage.'|@translate}</p>
    {/if}
    </li></ul>
  </fieldset>
  </div>
</div>
</div>

{literal}
<script>
(function () {
  var root = document.getElementById('tm-index');
  var url = root.getAttribute('data-url');
  var token = root.getAttribute('data-token');
  var btn = document.getElementById('tm-run');
  var bar = document.getElementById('tm-bar');
  var wrap = document.getElementById('tm-barwrap');
  var msg = document.getElementById('tm-msg');
  var T = function (k) { return root.getAttribute('data-t-' + k) || k; }; // textes traduits par le gabarit

  function post(data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fd.append('pwg_token', token);
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  btn.addEventListener('click', async function () {
    btn.disabled = true;
    wrap.hidden = false;
    bar.style.width = '0';
    msg.textContent = '…';
    try {
      var s = await post({ action: 'index_start' });
      if (s.stat !== 'ok') { throw new Error(s.message || 'start'); }
      var done = 0, last = 0, reads = 0;
      for (;;) {
        var r = await post({ action: 'index_batch', run: s.run, last_id: last });
        if (r.stat !== 'ok') { throw new Error(r.message || 'batch'); }
        last = r.last_id;
        done += r.processed;
        reads += r.reads;
        bar.style.width = (s.total ? Math.min(100, 100 * done / s.total) : 100) + '%';
        msg.textContent = done + ' / ' + s.total + ' tags, ' + reads + ' ' + T('read');
        if (r.done) { break; }
      }
      // Contrôle des visages : seulement les photos nouvelles ou resynchronisées depuis le dernier contrôle
      var tagMsg = msg.textContent;
      var f = await post({ action: 'face_check_start' });
      if (f.stat !== 'ok') { throw new Error(f.message || 'face_check_start'); }
      var fdone = 0, fnone = 0;
      bar.style.width = '0';
      while (f.total > 0) {
        var b = await post({ action: 'face_check_batch' });
        if (b.stat !== 'ok') { throw new Error(b.message || 'face_check_batch'); }
        fdone += b.processed;
        fnone += b.none;
        bar.style.width = Math.min(100, 100 * fdone / f.total) + '%';
        msg.textContent = tagMsg + ' — ' + T('faces') + ' ' + fdone + ' / ' + f.total + (fnone ? ', ' + fnone + ' ' + T('facenone') : '');
        if (b.done || !b.processed) { break; }
      }
      msg.textContent += ' — ' + T('done');
      setTimeout(function () { location.reload(); }, 800);
    } catch (e) {
      msg.textContent = T('error') + ' ' + e.message;
      btn.disabled = false;
    }
  });
})();
</script>
{/literal}
