{literal}
<style>
.tm-box{margin:0 0 20px 0;max-width:900px}
.tm-box h3{margin:0 0 8px 0}
.tm-stats{display:flex;flex-wrap:wrap;gap:8px 24px;margin:0 0 12px 0}
.tm-stats div{min-width:110px}
.tm-stats b{display:block;font-size:20px}
.tm-bar{height:10px;background:#ddd;border-radius:5px;overflow:hidden;margin:10px 0 6px 0}
.tm-bar>div{height:100%;width:0;background:#2f7a4d}
.tm-tree ul{list-style:none;margin:0;padding-left:18px}
.tm-tree>ul{padding-left:0}
.tm-tree .tm-group{font-weight:bold;color:#555}
.tm-tree .tm-group>ul{font-weight:normal;color:#222}
.tm-tree .tm-nb{color:#888;font-size:11px;font-family:monospace}
.tm-muted{color:#888}
</style>
{/literal}

<div class="titrePage">
  <h2>Tag Manager</h2>
</div>

<div id="tm-index" data-url="{$TM_URL}" data-token="{$TM_TOKEN}" data-t-read="{'fichiers lus'|@translate}" data-t-done="{'terminé'|@translate}" data-t-error="{'Erreur :'|@translate}">

  <div class="tm-box">
    <h3>{'Index des chemins de tags'|@translate}</h3>
    <p class="tm-muted">{'Piwigo ne conserve que le dernier niveau des tags. L’index lit une photo par tag pour retrouver son chemin complet dans les fichiers (lecture seule : aucun fichier ni aucun tag n’est modifié).'|@translate}</p>

    <div class="tm-stats">
      <div><b>{$TM_SUMMARY.total_tags}</b>{'tags utilisés'|@translate}</div>
      <div><b>{$TM_SUMMARY.ok}</b>{'avec chemin'|@translate}</div>
      <div><b>{$TM_SUMMARY.flat}</b>{'à plat'|@translate}</div>
      <div><b>{$TM_SUMMARY.nofile}</b>{'fichier introuvable'|@translate}</div>
      <div><b>{$TM_SUMMARY.nophoto}</b>{'sans photo'|@translate}</div>
    </div>

    <p>
      <button type="button" class="submit" id="tm-run">{'Lancer l’indexation'|@translate}</button>
      {if $TM_LAST}<span class="tm-muted">{'Dernière indexation'|@translate} : {$TM_LAST}</span>{/if}
    </p>
    <div class="tm-bar" id="tm-barwrap" hidden><div id="tm-bar"></div></div>
    <div class="tm-muted" id="tm-msg"></div>
  </div>

  {if $TM_PROBLEMS}
  <div class="tm-box">
    <h3>{'Tags sans chemin hiérarchique'|@translate}</h3>
    <p class="tm-muted">{'« À plat » : aucun des fichiers testés ne porte ce tag avec un niveau parent (huit photos au plus sont testées). Il peut exister avec son chemin dans d’autres fichiers. « Introuvable » : aucun des fichiers testés n’est lisible depuis le serveur.'|@translate}</p>
    <ul>
    {foreach from=$TM_PROBLEMS item=p}
      <li>{$p.name|escape} <span class="tm-muted">({if $p.status == 'flat'}{'à plat'|@translate}{else}{'fichier introuvable'|@translate}{/if})</span></li>
    {/foreach}
    </ul>
  </div>
  {/if}

  <div class="tm-box">
    <h3>{'Arbre indexé'|@translate}</h3>
    {if $TM_TREE}
      <div class="tm-tree">{$TM_TREE}</div>
    {else}
      <p class="tm-muted">{'Aucun chemin indexé pour l’instant.'|@translate}</p>
    {/if}
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
