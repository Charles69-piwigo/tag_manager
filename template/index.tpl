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
#tm-app .tm-steps{display:flex;flex-direction:column;gap:14px;max-width:860px;margin:4px 0 8px}
#tm-app .tm-step{display:flex;flex-direction:column;gap:6px}
#tm-app .tm-step.wait{opacity:.55}
#tm-app .tm-step-h{display:flex;justify-content:space-between;gap:12px;color:var(--strong);font-weight:600}
#tm-app .tm-step-h span+span{font-weight:400;color:var(--txt)}
#tm-app .tm-step .bar{max-width:none;flex:none}
#tm-app .tm-chips{display:flex;flex-wrap:wrap;gap:8px;font-size:12px}
#tm-app .tm-chips span{background:var(--blue-b);color:var(--blue-f);padding:2px 9px}
#tm-app .tm-chips span.grey{background:var(--grey-b);color:var(--grey-f)}
#tm-app .tm-done{background:var(--ok-b);color:var(--ok-f);padding:12px 16px;max-width:860px;display:flex;flex-direction:column;gap:6px;margin:8px 0}
#tm-app .tm-done b{font-weight:600}
#tm-app .tm-done.err{background:var(--err-b);color:var(--err-f)}
#tm-app .tm-old{background:var(--warn-b);color:var(--warn-f);padding:10px 16px;max-width:860px;line-height:1.5;margin:8px 0}
</style>
{/literal}

<div class="titrePage">
  <h2>Tag Manager</h2>
</div>

<div id="tm-app" class="tm-index{if $TM_DARK} dark{/if}">
<div class="conf" id="tm-index" data-url="{$TM_URL}" data-token="{$TM_TOKEN}" data-t-read="{'fichiers lus'|@translate}" data-t-error="{'Erreur :'|@translate}" data-t-new="{'nouveaux'|@translate}" data-t-count="{'photos ajoutées ou retirées'|@translate}" data-t-file="{'fichier témoin modifié'|@translate}" data-t-nopath="{'sans chemin, photos changées'|@translate}" data-t-unchanged="{'inchangés (non relus)'|@translate}" data-t-busy-update="{'Mise à jour en cours…'|@translate}" data-t-busy-full="{'Indexation en cours…'|@translate}" data-t-step1-update="{'1. Tags à relire : %s sur %s'|@translate}" data-t-step1-full="{'1. Tags à relire : %s'|@translate}" data-t-wait="{'en attente'|@translate}" data-t-end-update="{'Mise à jour terminée en %s s'|@translate}" data-t-end-full="{'Indexation complète terminée en %s s'|@translate}" data-t-end-tags="{'Tags : %s relus sur %s (%s fichiers lus)'|@translate}" data-t-changed="{'%s chemins changés'|@translate}" data-t-placed="{'%s nouveaux tags rangés'|@translate}" data-t-flat="{'%s tags toujours sans chemin'|@translate}" data-t-nochange="{'aucun chemin changé'|@translate}" data-t-end-faces="{'Visages : %s photos contrôlées'|@translate}" data-t-end-nofaces="{'Visages : aucune photo nouvelle ou modifiée'|@translate}" data-t-path-diff="{'« visage sans cadre » : %s en moins, %s en plus'|@translate}" data-t-none-diff="{'« tag Piwigo seul » : %s en moins, %s en plus'|@translate}" data-t-facesame="{'aucun changement'|@translate}">

  <fieldset>
    <legend><span class="ic blue"><i class="icon-chart-bar"></i></span>{'Index des chemins de tags'|@translate}</legend>
    <ul>
      <li><p class="help" style="margin-top:0">{'Piwigo ne conserve que le dernier niveau des tags. L’index lit une photo par tag pour retrouver son chemin complet dans les fichiers (lecture seule : aucun fichier ni aucun tag n’est modifié).'|@translate} {'Il contrôle aussi chaque photo qui porte un tag de visage : la première fois toutes, ensuite seulement les nouvelles et celles qui ont changé (dans Piwigo ou sur le disque).'|@translate}</p>
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
        <button type="button" class="btn primary" id="tm-run" data-mode="update"><i class="icon-arrows-cw"></i> {'Mettre à jour l’index'|@translate}</button>
        <button type="button" class="btn" id="tm-run-full" data-mode="full">{'Tout réindexer'|@translate}</button>
        {if $TM_LAST_UPDATE}<span class="badge">{'Dernière mise à jour'|@translate} : {$TM_LAST_UPDATE}</span>{/if}
        {if $TM_LAST_FULL}<span class="badge">{'Dernière indexation complète'|@translate} : {$TM_LAST_FULL}</span>{/if}
      </li>
      <li style="display:block">
        <div class="tm-done" id="tm-done" hidden></div>
        <p class="help" id="tm-help" style="margin-top:0">{'« Mettre à jour l’index » ne relit que les tags nouveaux, ceux dont les photos ont changé (ajoutées, retirées, fichier témoin modifié dans Piwigo ou sur le disque) et ceux qui n’ont pas encore de chemin ; puis le contrôle des visages relit les photos nouvelles ou modifiées. « Tout réindexer » relit tous les tags : à faire de temps en temps, ou après un gros changement dans digiKam. Lecture seule : aucun fichier ni aucun tag n’est modifié.'|@translate} {'Les photos en anomalie (« visage sans cadre », « tag Piwigo seul ») sont relues à chaque fois : après un renommage dans Piwigo, une mise à jour suffit.'|@translate} {'Les vidéos, PDF et autres fichiers qui ne sont pas des photos ne sont pas contrôlés.'|@translate}</p>
        <div class="tm-steps" id="tm-steps" hidden>
          <div class="tm-step">
            <div class="tm-step-h"><span id="tm-s1-title"></span><span id="tm-s1-count"></span></div>
            <div class="bar"><div id="tm-bar"></div></div>
            <div class="tm-chips" id="tm-reasons"></div>
          </div>
          <div class="tm-step wait" id="tm-s2">
            <div class="tm-step-h"><span>{'2. Contrôle des visages'|@translate}</span><span id="tm-s2-count"></span></div>
            <div class="bar"><div id="tm-bar2"></div></div>
          </div>
          <p class="help" style="margin:0">{'Quitter la page interrompt l’indexation sans dommage : les tags déjà relus le restent, la mise à jour suivante reprend le reste.'|@translate}</p>
        </div>
        {if $TM_FULL_OLD}<div class="tm-old">{'Dernière indexation complète il y a plus de 30 jours : la mise à jour ne regarde que la photo témoin de chaque tag, un changement fait dans digiKam sur d’autres photos de ce tag peut lui échapper. Pensez à « Tout réindexer ».'|@translate}</div>{/if}
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
  var btnFull = document.getElementById('tm-run-full');
  var btnLabel = btn.innerHTML;
  var $ = function (id) { return document.getElementById(id); };
  var T = function (k) { return root.getAttribute('data-t-' + k) || k; }; // textes traduits par le gabarit
  // texte traduit avec ses %s remplacés dans l'ordre
  var F = function (k) { var a = [].slice.call(arguments, 1), i = 0; return T(k).replace(/%s/g, function () { return a[i++]; }); };

  function post(data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fd.append('pwg_token', token);
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  function line(text, bold) {
    var d = document.createElement('div');
    if (bold) { var b = document.createElement('b'); b.textContent = text; d.appendChild(b); } else { d.textContent = text; }
    return d;
  }

  // Bilan affiché après le rechargement de la page (maquette « Mise à jour terminée »)
  function showReport(r) {
    var box = $('tm-done');
    box.textContent = '';
    box.className = 'tm-done';
    box.appendChild(line(F(r.mode === 'update' ? 'end-update' : 'end-full', r.secs), true));
    var tags = [];
    if (r.report.changed) { tags.push(F('changed', r.report.changed)); }
    if (r.report.placed) { tags.push(F('placed', r.report.placed)); }
    if (r.report.flat) { tags.push(F('flat', r.report.flat)); }
    box.appendChild(line(F('end-tags', r.done, r.all, r.reads) + ' — ' + (tags.length ? tags.join(', ') : T('nochange')) + '.'));
    if (r.faces.n) {
      var fc = [];
      if (r.faces.path_gone || r.faces.path_new) { fc.push(F('path-diff', r.faces.path_gone, r.faces.path_new)); }
      if (r.faces.none_gone || r.faces.none_new) { fc.push(F('none-diff', r.faces.none_gone, r.faces.none_new)); }
      box.appendChild(line(F('end-faces', r.faces.n) + ' — ' + (fc.length ? fc.join(' ; ') : T('facesame')) + '.'));
    } else {
      box.appendChild(line(T('end-nofaces') + '.'));
    }
    box.hidden = false;
  }
  try {
    var kept = sessionStorage.getItem('tm_index_report');
    if (kept) { sessionStorage.removeItem('tm_index_report'); showReport(JSON.parse(kept)); }
  } catch (e) {}

  async function run(mode) {
    btn.disabled = true;
    btnFull.disabled = true;
    (mode === 'update' ? btn : btnFull).textContent = T(mode === 'update' ? 'busy-update' : 'busy-full');
    $('tm-done').hidden = true;
    $('tm-help').hidden = true;
    $('tm-steps').hidden = false;
    $('tm-bar').style.width = '0';
    $('tm-bar2').style.width = '0';
    $('tm-s2').className = 'tm-step wait';
    $('tm-s2-count').textContent = T('wait');
    $('tm-s1-count').textContent = '…';
    var chips = $('tm-reasons');
    chips.textContent = '';
    var t0 = Date.now();
    try {
      var s = await post({ action: 'index_start', mode: mode });
      if (s.stat !== 'ok') { throw new Error(s.message || 'start'); }
      $('tm-s1-title').textContent = s.mode === 'update' ? F('step1-update', s.total, s.all) : F('step1-full', s.total);
      if (s.mode === 'update' && s.reasons) {
        ['new', 'count', 'file', 'nopath'].forEach(function (k) {
          if (s.reasons[k]) { var c = document.createElement('span'); c.textContent = s.reasons[k] + ' ' + T(k); chips.appendChild(c); }
        });
        var u = document.createElement('span');
        u.className = 'grey';
        u.textContent = (s.all - s.total) + ' ' + T('unchanged');
        chips.appendChild(u);
      }
      var done = 0, last = 0, reads = 0, report = { changed: 0, placed: 0, flat: 0 };
      for (;;) {
        var r = await post({ action: 'index_batch', run: s.run, last_id: last, mode: s.mode });
        if (r.stat !== 'ok') { throw new Error(r.message || 'batch'); }
        last = r.last_id;
        done += r.processed;
        reads += r.reads;
        $('tm-bar').style.width = (s.total ? Math.min(100, 100 * done / s.total) : 100) + '%';
        $('tm-s1-count').textContent = done + ' / ' + s.total + ' — ' + reads + ' ' + T('read');
        if (r.done) { if (r.report) { report = r.report; } break; }
      }
      // Contrôle des visages : seulement les photos nouvelles ou modifiées depuis le dernier contrôle
      $('tm-s2').className = 'tm-step';
      $('tm-s2-count').textContent = '…';
      var f = await post({ action: 'face_check_start' });
      if (f.stat !== 'ok') { throw new Error(f.message || 'face_check_start'); }
      var faces = { n: 0, path_new: 0, path_gone: 0, none_new: 0, none_gone: 0 };
      $('tm-s2-count').textContent = '0 / ' + f.total;
      if (!f.total) { $('tm-bar2').style.width = '100%'; }
      while (f.total > 0) {
        var b = await post({ action: 'face_check_batch' });
        if (b.stat !== 'ok') { throw new Error(b.message || 'face_check_batch'); }
        faces.n += b.processed;
        ['path_new', 'path_gone', 'none_new', 'none_gone'].forEach(function (k) { faces[k] += b[k] || 0; });
        $('tm-bar2').style.width = Math.min(100, 100 * faces.n / f.total) + '%';
        $('tm-s2-count').textContent = faces.n + ' / ' + f.total;
        if (b.done || !b.processed) { break; }
      }
      var res = { mode: s.mode, secs: Math.round((Date.now() - t0) / 1000), done: done, all: s.all, reads: reads, report: report, faces: faces };
      try { sessionStorage.setItem('tm_index_report', JSON.stringify(res)); } catch (e) {}
      setTimeout(function () { location.reload(); }, 600); // compteurs, arbre et visages recalculés par la page
    } catch (e) {
      var box = $('tm-done');
      box.className = 'tm-done err';
      box.textContent = T('error') + ' ' + e.message;
      box.hidden = false;
      btn.disabled = false;
      btnFull.disabled = false;
      btn.innerHTML = btnLabel;
      btnFull.textContent = btnFull.getAttribute('data-label');
    }
  }

  btnFull.setAttribute('data-label', btnFull.textContent);
  btn.addEventListener('click', function () { run('update'); });
  btnFull.addEventListener('click', function () { run('full'); });
})();
</script>
{/literal}
