/* tag_manager — interface d'administration (issue de la maquette).
 * Phase 1 : lecture des albums, des tags et des photos réels ; paramètres enregistrés.
 * Les modifications de tags restent dans une file locale : l'écriture dans les fichiers viendra ensuite. */
(function () {
  'use strict';

  const root = document.getElementById('tm-app');
  if (!root) { return; }
  const API = root.getAttribute('data-url');
  const TOKEN = root.getAttribute('data-token');
  // page publique (utilisateurs non webmestres) : pas de Paramètres, pas de plein écran à basculer
  const PUBLIC = root.getAttribute('data-public') === '1';

  // ---------- utilitaires ----------
  const $ = s => root.querySelector(s);
  const $$ = s => Array.prototype.slice.call(root.querySelectorAll(s));
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ncmp = (a, b) => String(a).localeCompare(String(b), 'fr', { numeric: true });

  function toast(m) {
    const t = document.createElement('div');
    t.className = 'toast';
    t.textContent = m;
    root.appendChild(t);
    setTimeout(() => t.remove(), 2800);
  }

  async function api(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('pwg_token', TOKEN);
    Object.keys(data || {}).forEach(k => {
      const v = data[k];
      fd.append(k, (v !== null && typeof v === 'object') ? JSON.stringify(v) : v);
    });
    const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await r.json();
    if (j.stat !== 'ok') { throw new Error(j.message || 'erreur'); }
    return j;
  }

  // ---------- état ----------
  let ALB = [], albMap = {}, albTot = {};
  let tagPath = {}, TAGID = {}, IDNAME = {}, TAGN = {}, FLAT = {};
  let emptyGroups = new Set();
  let PH = {};
  let CUR = { list: [], total: 0, loading: false };
  let loadSeq = 0;
  const pend = {};
  let sel = new Set(), anchor = null;
  const view = { tab: 'albums', id: null, key: null, desc: true };
  const collapsed = new Set();
  let pFilter = '', pOnly = false;
  const pCol = new Set();
  let mkey = '', mFilter = '', sub = 'tree', screen = 'tag';
  const mCol = new Set();
  let REC = [];
  let S = { original: false, thumb: 'large', reserved: [], rights: [], groups: [], users: [] };
  let reservedAtLoad = '', thumbAtLoad = 'large';

  // ---------- albums ----------
  function buildAlbums(list) {
    albMap = {};
    list.forEach(a => { albMap[a.id] = { a: { id: a.id, name: a.name, n: a.n, ch: [] }, par: a.parent }; });
    ALB = [];
    list.forEach(a => {
      const e = albMap[a.id];
      if (a.parent && albMap[a.parent]) { albMap[a.parent].a.ch.push(e.a); } else { ALB.push(e.a); }
    });
    const sortRec = l => { l.sort((x, y) => ncmp(x.name, y.name)); l.forEach(a => sortRec(a.ch)); };
    sortRec(ALB);
    albTot = {};
    const tot = a => { let n = a.n; a.ch.forEach(c => { n += tot(c); }); albTot[a.id] = n; return n; };
    ALB.forEach(tot);
  }
  const albPath = id => { const r = []; let c = id; while (c && albMap[c]) { r.unshift(albMap[c].a.name); c = albMap[c].par; } return r; };
  const albSub = id => { const r = [id]; ((albMap[id] && albMap[id].a.ch) || []).forEach(c => r.push.apply(r, albSub(c.id))); return r; };

  // ---------- tags ----------
  function buildTags(T) {
    tagPath = {}; TAGID = {}; IDNAME = {}; TAGN = {}; FLAT = {};
    T.tags.forEach(t => {
      if (!t.n && !t.path) { return; }
      const ok = t.status === 'ok' && t.path;
      tagPath[t.name] = ok ? t.path.split('|').filter(Boolean) : ['(sans chemin)', t.name];
      TAGID[t.name] = t.id; IDNAME[t.id] = t.name; TAGN[t.name] = t.n;
      if (!ok) { FLAT[t.name] = true; }
    });
    emptyGroups = new Set(T.groups || []);
  }

  function tagTree() {
    const root0 = { ch: {}, key: '' };
    const add = (p, leaf) => {
      let n = root0;
      p.forEach((s, i) => {
        const k = p.slice(0, i + 1).join('|');
        n.ch[s] = n.ch[s] || { name: s, key: k, ch: {}, leaf: false };
        n = n.ch[s];
        if (leaf && i === p.length - 1) { n.leaf = true; }
      });
    };
    Object.keys(tagPath).forEach(k => add(tagPath[k], true));
    emptyGroups.forEach(k => add(k.split('|'), false));
    return root0;
  }
  // « (sans chemin) » est un regroupement d'affichage : on le range en dernier
  const kids = n => Object.keys(n.ch).map(k => n.ch[k]).sort((a, b) => {
    const pa = a.name.charAt(0) === '(', pb = b.name.charAt(0) === '(';
    return pa !== pb ? (pa ? 1 : -1) : ncmp(a.name, b.name);
  });
  // tag le plus utilisé : point de départ de la vue par tags
  const topTag = () => Object.keys(tagPath).filter(k => !FLAT[k]).sort((a, b) => (TAGN[b] || 0) - (TAGN[a] || 0))[0];
  const isLeafNode = c => c.leaf && !Object.keys(c.ch).length;
  const leavesUnder = key => Object.keys(tagPath).filter(k => { const j = tagPath[k].join('|'); return j === key || j.indexOf(key + '|') === 0; });
  const isLeafKey = k => { const n = k.split('|').pop(); return !!tagPath[n] && tagPath[n].join('|') === k; };
  const tagSum = key => leavesUnder(key).reduce((s, n) => s + (TAGN[n] || 0), 0);
  const human = k => k ? k.split('|').join(' › ') : '(racine)';

  // ---------- photos ----------
  const eff = p => {
    const s = new Set(p.tags);
    const q = pend[p.id];
    if (q) { q.add.forEach(t => s.add(t)); q.rem.forEach(t => s.delete(t)); }
    return s;
  };
  const visible = () => CUR.list.map(id => PH[id]).filter(Boolean);

  const PAGE = 240;
  const pageCount = () => Math.max(1, Math.ceil(CUR.total / PAGE));

  async function loadPhotos(page) {
    const seq = ++loadSeq;
    page = Math.max(0, page || 0);
    let mode, ids;
    if (view.tab === 'albums') {
      if (!view.id) { CUR = { list: [], total: 0, loading: false, page: 0 }; return; }
      mode = 'album';
      ids = view.desc ? albSub(view.id) : [view.id];
    } else {
      mode = 'tag';
      const names = view.desc ? leavesUnder(view.key) : [view.key.split('|').pop()];
      ids = names.map(n => TAGID[n]).filter(Boolean);
    }
    CUR = { list: [], total: 0, loading: true, page: page };
    renderGrid(); renderToolbar();
    try {
      let r = await api('photos', { mode: mode, ids: ids.join(','), offset: page * PAGE, limit: PAGE });
      if (seq !== loadSeq) { return; }
      // la page demandée n'existe plus (moins de photos qu'avant) : dernière page
      if (!r.photos.length && r.total > 0 && page * PAGE >= r.total) {
        page = Math.ceil(r.total / PAGE) - 1;
        r = await api('photos', { mode: mode, ids: ids.join(','), offset: page * PAGE, limit: PAGE });
        if (seq !== loadSeq) { return; }
        CUR.page = page;
      }
      r.photos.forEach(p => {
        PH[p.id] = { id: p.id, file: p.file, alb: p.alb, thumb: p.thumb, tags: new Set(p.tags.map(i => IDNAME[i]).filter(Boolean)) };
        if (CUR.list.indexOf(p.id) < 0) { CUR.list.push(p.id); }
      });
      CUR.total = r.total;
    } catch (e) {
      if (seq === loadSeq) { toast('Chargement impossible : ' + e.message); }
    }
    if (seq === loadSeq) { CUR.loading = false; renderGrid(); renderToolbar(); renderPanel(); }
  }

  // aller à une page de la vue courante (la sélection est conservée)
  async function gotoPage(p) {
    p = Math.max(0, Math.min(pageCount() - 1, p));
    if (p === CUR.page || CUR.loading) { return; }
    await loadPhotos(p);
    const sc = $('#grid').parentNode; if (sc) { sc.scrollTop = 0; }
  }

  function resetSelection() { sel = new Set(); anchor = null; }
  async function changeView() { resetSelection(); await loadPhotos(0); renderAll(); }

  // ---------- file de modifications (locale pour l'instant) ----------
  function setTag(ids, tag, on) {
    ids.forEach(id => {
      const p = PH[id];
      if (!p) { return; }
      const q = pend[id] || (pend[id] = { add: new Set(), rem: new Set() });
      if (on) { q.rem.delete(tag); if (!p.tags.has(tag)) { q.add.add(tag); } }
      else { q.add.delete(tag); if (p.tags.has(tag)) { q.rem.add(tag); } }
      if (!q.add.size && !q.rem.size) { delete pend[id]; }
    });
  }
  const pendCount = () => { let ph = 0, ch = 0; Object.keys(pend).forEach(k => { ph++; ch += pend[k].add.size + pend[k].rem.size; }); return { ph: ph, ch: ch }; };

  // ---------- rendu : Étiqueter ----------
  function renderTree() {
    const el = $('#tree');
    let h = '';
    if (view.tab === 'albums') {
      const rec = l => '<ul class="tree">' + l.map(a => {
        const has = a.ch.length, open = !collapsed.has(String(a.id));
        return '<li><div class="node' + (view.id === a.id ? ' sel' : '') + '" tabindex="0" data-alb="' + a.id + '"><span class="caret" data-car="' + a.id + '">' + (has ? (open ? '▼' : '▶') : '') + '</span><span class="nm" title="' + esc(a.name) + '">' + esc(a.name) + '</span><span class="cnt">' + albTot[a.id] + '</span></div>' + (has && open ? rec(a.ch) : '') + '</li>';
      }).join('') + '</ul>';
      h = rec(ALB) + '<div class="hint">Répertoires physiques (liens symboliques vers le NAS). Les noms ne suivent pas les tags.</div>';
    } else {
      const rec = n => '<ul class="tree">' + kids(n).map(c => {
        const k2 = Object.keys(c.ch).length, open = !collapsed.has(c.key), leaf = isLeafNode(c);
        return '<li><div class="node' + (leaf ? '' : ' group') + (view.key === c.key ? ' sel' : '') + '" tabindex="0" data-tag="' + esc(c.key) + '"' + (leaf ? ' data-leaf="' + esc(c.name) + '"' : '') + '><span class="caret" data-car="' + esc(c.key) + '">' + (k2 ? (open ? '▼' : '▶') : '') + '</span>' + (leaf ? '<span class="leafmark" title="Tag Piwigo"></span>' : '') + '<span class="nm" title="' + esc(c.name) + '">' + esc(c.name) + '</span><span class="cnt">' + (leaf ? (TAGN[c.name] || 0) : tagSum(c.key)) + '</span></div>' + (k2 && open ? rec(c) : '') + '</li>';
      }).join('') + '</ul>';
      h = rec(tagTree()) + '<div class="hint"><span class="leafmark" style="display:inline-block"></span> = tag connu de Piwigo (dernier niveau). Les niveaux grisés n’existent que dans les fichiers. Cliquez pour filtrer les photos, glissez des photos sur un tag pour l’affecter. « (sans chemin) » regroupe les tags dont le chemin n’a pas été retrouvé.</div>';
    }
    el.innerHTML = h;
  }

  function renderToolbar() {
    let title = '', lastName = '', isLeaf = false, canDesc = false;
    if (view.tab === 'albums') {
      title = view.id ? albPath(view.id).join(' › ') : '';
      canDesc = !!(view.id && albMap[view.id] && albMap[view.id].a.ch.length);
    } else {
      title = (view.key || '').split('|').join(' › ');
      lastName = (view.key || '').split('|').pop();
      isLeaf = !!(tagPath[lastName] && tagPath[lastName].join('|') === view.key);
      canDesc = !isLeaf;
    }
    const shown = CUR.list.length;
    let h = '<div class="crumb">' + esc(title) + '<small>' + (CUR.loading && !shown ? 'chargement…' : CUR.total + ' photos' + (CUR.total > PAGE ? ' · page ' + ((CUR.page || 0) + 1) + ' / ' + pageCount() : '') + ' · ' + sel.size + ' sélectionnée' + (sel.size > 1 ? 's' : '')) + '</small></div>';
    if (canDesc) { h += '<label class="chk"><input type="checkbox" id="desc"' + (view.desc ? ' checked' : '') + '> Inclure les sous-niveaux</label>'; }
    h += '<button class="btn" id="selall">' + (CUR.total > PAGE ? 'Sélectionner la page' : 'Tout sélectionner') + '</button><button class="btn" id="selnone"' + (sel.size ? '' : ' disabled') + '>Désélectionner</button>';
    if (isLeaf) { h += '<button class="btn danger" id="rmtag"' + (sel.size ? '' : ' disabled') + '>Retirer « ' + esc(lastName) + ' » de la sélection</button>'; }
    $('#toolbar').innerHTML = h;
  }

  function renderGrid() {
    const vis = visible();
    renderPager();
    if (!vis.length) {
      $('#grid').innerHTML = '<div class="empty" style="grid-column:1/-1">' + (CUR.loading ? 'Chargement des photos…' : 'Aucune photo ici.') + '</div>';
      return;
    }
    let h = vis.map(p => {
      const q = pend[p.id];
      const chips = [];
      Array.from(p.tags).sort(ncmp).forEach(t => chips.push(q && q.rem.has(t) ? '<span class="chip rem" title="' + esc(t) + '">− ' + esc(t) + '</span>' : '<span class="chip n" title="' + esc(t) + '">' + esc(t) + '</span>'));
      if (q) { q.add.forEach(t => chips.push('<span class="chip" title="' + esc(t) + '">+ ' + esc(t) + '</span>')); }
      if (!chips.length) { chips.push('<span class="chip" style="background:transparent;color:var(--muted);font-weight:400">aucun tag</span>'); }
      return '<div class="tile' + (sel.has(p.id) ? ' sel' : '') + '" tabindex="0" draggable="true" data-id="' + p.id + '">' +
        '<img class="ph" loading="lazy" draggable="false" alt="" src="' + esc(p.thumb) + '"><span class="tick">✓</span>' +
        '<div class="cap"><span class="fn" title="' + esc(p.file) + '">' + esc(p.file) + '</span><span class="chips">' + chips.join('') + '</span></div></div>';
    }).join('');
    $('#grid').innerHTML = h;
  }

  // pagination numérotée : ‹ 1 … 4 5 [6] 7 8 … 20 ›
  function renderPager() {
    const el = $('#pager'), n = pageCount(), cur = CUR.page || 0;
    if (n < 2) { el.innerHTML = ''; return; }
    const set = new Set([0, n - 1]);
    for (let i = cur - 2; i <= cur + 2; i++) { if (i >= 0 && i < n) { set.add(i); } }
    const nums = Array.from(set).sort((a, b) => a - b);
    const b = (p, label, extra, dis) => '<button class="btn' + (extra || '') + '" data-page="' + p + '"' + (dis ? ' disabled' : '') + (p === cur && label !== '‹' && label !== '›' ? ' aria-current="page"' : '') + '>' + label + '</button>';
    let h = b(cur - 1, '‹', '', cur === 0);
    let prev = -1;
    nums.forEach(p => {
      if (prev >= 0 && p - prev > 1) { h += '<span class="cnt">…</span>'; }
      h += b(p, String(p + 1), p === cur ? ' pend' : '', false);
      prev = p;
    });
    h += b(cur + 1, '›', '', cur === n - 1);
    el.innerHTML = h;
  }

  function renderPanel() {
    const ids = Array.from(sel), N = ids.length;
    $('#panel-h').innerHTML = 'Tags de la sélection <span class="cnt">' + N + ' photo' + (N > 1 ? 's' : '') + '</span>';
    const f = pFilter.trim().toLowerCase();
    const info = t => {
      const have = ids.filter(id => PH[id] && eff(PH[id]).has(t)).length;
      const chg = ids.some(id => { const q = pend[id]; return q && (q.add.has(t) || q.rem.has(t)); });
      return { have: have, chg: chg, st: N && have === N ? 'all' : have ? 'some' : 'none' };
    };
    const show = (leaf, path) => {
      if (f && !(leaf.toLowerCase().indexOf(f) >= 0 || path.join(' ').toLowerCase().indexOf(f) >= 0)) { return false; }
      if (pOnly) { if (!N) { return false; } const i = info(leaf); if (!i.have && !i.chg) { return false; } }
      return true;
    };
    const rec = n => {
      let out = '';
      kids(n).forEach(c => {
        if (isLeafNode(c)) {
          const path = tagPath[c.name] || [c.name];
          if (!show(c.name, path)) { return; }
          const i = info(c.name);
          out += '<div class="row' + (i.chg ? ' chg' : '') + '" tabindex="0" data-t="' + esc(c.name) + '"><span class="cb ' + i.st + '">' + (i.st === 'all' ? '✓' : i.st === 'some' ? '–' : '') + '</span><span class="t"><b title="' + esc(path.join(' › ')) + '">' + esc(c.name) + '</b></span><span class="n">' + (N ? i.have + '/' + N : '') + '</span></div>';
        } else {
          const s2 = rec(c);
          if (!s2) { return; }
          const open = (f || pOnly) ? true : !pCol.has(c.key);
          out += '<div class="grow" data-pcar="' + esc(c.key) + '"><span class="caret">' + (open ? '▼' : '▶') + '</span>' + esc(c.name) + '</div>' + (open ? '<div class="gkids">' + s2 + '</div>' : '');
        }
      });
      return out;
    };
    const html = rec(tagTree());
    $('#rows').className = 'pane-b' + (N ? '' : ' rows-off');
    $('#rows').innerHTML = (N ? '' : '<div class="hint">Sélectionnez des photos au centre pour cocher leurs tags.</div>') + (html || '<div class="empty">Aucun tag ne correspond.</div>');
  }

  function renderBar() {
    const c = pendCount();
    $('#pendbar').innerHTML = c.ph ? '<div class="pendbar"><span>' + c.ph + ' photo' + (c.ph > 1 ? 's' : '') + ' à modifier · ' + c.ch + ' changement' + (c.ch > 1 ? 's' : '') + ' dans la file</span><button class="btn" id="cancel">Annuler tout</button><button class="btn pend" id="preview">Aperçu et appliquer</button></div>' : '';
  }

  // ---------- aperçu et écriture (file d'attente côté serveur) ----------
  let jobRunning = false;
  let structOps = null, structFocus = null;

  function itemsFromPend() {
    return Object.keys(pend).map(id => ({ id: parseInt(id, 10), add: Array.from(pend[id].add), rem: Array.from(pend[id].rem) }));
  }

  function openPreview() {
    const m = {};
    Object.keys(pend).forEach(id => {
      const p = PH[id], q = pend[id];
      q.add.forEach(t => (m[t] = m[t] || { add: [], rem: [] }).add.push(p.file));
      q.rem.forEach(t => (m[t] = m[t] || { add: [], rem: [] }).rem.push(p.file));
    });
    const c = pendCount();
    const flatAdds = Object.keys(m).filter(t => FLAT[t] && m[t].add.length);
    const body = Object.keys(m).sort(ncmp).map(t => {
      const path = tagPath[t] || ['(sans chemin)', t], o = m[t];
      return '<div class="op"><b>' + esc(t) + '</b>' +
        '<div>' + (o.add.length ? '<span class="plus">+ ' + o.add.length + '</span> ' : '') + (o.rem.length ? '<span class="minus">− ' + o.rem.length + '</span> ' : '') + '<span style="color:var(--muted)">photo' + (o.add.length + o.rem.length > 1 ? 's' : '') + '</span></div>' +
        '<div class="pl">dc:subject <i>' + esc(t) + '</i><br>' + (FLAT[t] ? '<i>tag sans chemin : seul le retrait est possible</i>' : 'lr:hierarchicalSubject <i>' + esc(path.join('|')) + '</i>') + '</div></div>';
    }).join('');
    const warn = flatAdds.length ? '<div class="off-note" style="margin:0;border-color:var(--danger);color:var(--danger)">Impossible d’ajouter ' + flatAdds.map(t => '« ' + esc(t) + ' »').join(', ') + ' : pas de chemin connu. Rangez d’abord ces tags (vue À réconcilier).</div>' : '';
    const label = 'Écrire dans ' + c.ph + ' fichier' + (c.ph > 1 ? 's' : '');
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + label + ' ?</h2><div class="body" id="dbody">' +
      '<div style="color:var(--muted)">Les tags sont écrits dans les métadonnées XMP et IPTC de chaque fichier, sans toucher à l’image, puis les tags Piwigo des photos sont mis à jour.</div>' + warn + body +
      '<label class="chk"><input type="checkbox" id="orig"' + (S.original ? ' checked' : '') + '> Créer une copie <span class="mono">.original</span> si elle n’existe pas</label>' +
      '<div class="prog" id="prog" hidden><div></div></div><div id="pmsg" style="color:var(--muted)" hidden></div></div>' +
      '<div class="foot"><button class="btn" id="dcancel">Annuler</button><button class="btn pend" id="dgo"' + (flatAdds.length ? ' disabled' : '') + '>' + label + '</button></div></div></div>';
    $('#dcancel').focus();
  }

  function setBusy(b) {
    jobRunning = b;
    ['#dgo', '#sapply', '#dcancel', '#orig'].forEach(s => { const e = $(s); if (e) { e.disabled = b; } });
    const pr = $('#prog'), pm = $('#pmsg');
    if (b && pr) { pr.hidden = false; }
    if (b && pm) { pm.hidden = false; }
  }

  function jobMessage(html, end, retry) {
    const pm = $('#pmsg');
    if (pm) { pm.hidden = false; pm.style.color = 'var(--fg)'; pm.innerHTML = html; }
    if (end) {
      jobRunning = false;
      ['#dgo', '#sapply'].forEach(s => { const g = $(s); if (g) { g.hidden = true; } });
      const c = $('#dcancel');
      if (c) { c.disabled = false; c.textContent = 'Fermer'; c.focus(); }
      if (retry && c) {
        const b = document.createElement('button');
        b.className = 'btn pend'; b.id = 'jretry'; b.textContent = 'Réessayer les erreurs';
        b.setAttribute('data-job', retry.id); b.setAttribute('data-total', retry.total);
        c.parentNode.insertBefore(b, c);
      }
    }
  }

  async function refreshData() {
    try {
      const t = await api('tags'); buildTags(t);
      if (structFocus !== null) { mkey = structFocus; structFocus = null; }
      // on revient à la page et à la position de défilement d'avant l'opération
      const keepPage = CUR.page || 0, sc = $('#grid').parentNode, keepTop = sc ? sc.scrollTop : 0;
      await loadPhotos(keepPage);
      // les photos des autres pages ont pu changer : la sélection se limite à la page affichée
      sel = new Set(Array.from(sel).filter(id => CUR.list.indexOf(id) >= 0));
      renderAll();
      if (sc) { sc.scrollTop = keepTop; }
      api('recon').then(r => { REC = r.rows; renderMng(); renderRec(); }).catch(() => { /* facultatif */ });
    } catch (e) { /* l'écran reste utilisable */ }
  }

  async function driveJob(jobId, total, clearDrafts) {
    let r;
    for (;;) {
      r = await api('job_step', { job: jobId, limit: 10 });
      if (!r.ok) { throw new Error(r.error || 'erreur'); }
      const c = r.counts, fin = c.done + c.error + c.skipped;
      const bar = $('#prog > div');
      if (bar) { bar.style.width = (total ? 100 * fin / total : 100) + '%'; }
      const pm = $('#pmsg');
      if (pm) { pm.hidden = false; pm.textContent = fin + ' / ' + total + ' fichiers' + (c.error ? ' · ' + c.error + ' erreur' + (c.error > 1 ? 's' : '') : ''); }
      if (r.done) { break; }
    }
    const c = r.counts;
    const isStruct = r.kind === 'struct';
    let html;
    if (r.status === 'partial') {
      html = '<b style="color:var(--danger)">' + c.error + ' fichier' + (c.error > 1 ? 's' : '') + ' en erreur : les tags Piwigo n’ont pas été modifiés.</b>' +
        '<div style="color:var(--muted)">Les ' + c.done + ' autres fichiers ont déjà été réécrits. Corrigez la cause (droits, fichier absent…) puis réessayez : l’opération reprend là où elle s’est arrêtée.</div>';
    } else {
      html = '<b style="color:var(--ok)">' + c.done + ' fichier' + (c.done > 1 ? 's' : '') + ' mis à jour' + (isStruct ? ', tags Piwigo à jour.' : '.') + '</b>';
    }
    if (c.error) {
      const e = await api('job_errors', { job: jobId });
      html += '<div style="color:var(--danger);margin-top:6px">' + (r.status === 'partial' ? 'Fichiers en erreur (laissés intacts) :' : c.error + ' en erreur (fichier laissé intact) :') + '</div><ul style="margin:4px 0 0 18px">' +
        e.rows.map(x => '<li><span class="mono">' + esc(x.file) + '</span> : ' + esc(x.message) + '</li>').join('') + '</ul>';
    }
    if (r.final && r.final.messages && r.final.messages.length) {
      html += '<ul style="margin:6px 0 0 18px;color:var(--danger)">' + r.final.messages.map(m => '<li>' + esc(m) + '</li>').join('') + '</ul>';
    }
    jobMessage(html, true, r.status === 'partial' ? { id: jobId, total: total } : null);
    if (clearDrafts) { Object.keys(pend).forEach(k => delete pend[k]); }
    await refreshData();
    checkActive();
  }

  async function startJob() {
    const items = itemsFromPend(), original = $('#orig') ? $('#orig').checked : false;
    setBusy(true);
    try {
      const c = await api('job_create', { items: items, original: original ? 1 : 0 });
      if (!c.ok) {
        jobMessage('<b style="color:var(--danger)">' + esc(c.error) + '</b>' + (c.details ? '<ul style="margin:4px 0 0 18px">' + c.details.map(d => '<li>' + esc(d) + '</li>').join('') + '</ul>' : ''), true);
        return;
      }
      await driveJob(c.job, c.total, true);
    } catch (e) {
      jobMessage('Erreur : ' + esc(e.message) + '. Le lot reste enregistré : il peut être repris depuis la bannière.', true);
      checkActive();
    }
  }

  function openProgressDialog(title) {
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body" id="dbody">' +
      '<div class="prog" id="prog"><div></div></div><div id="pmsg" style="color:var(--muted)">…</div></div>' +
      '<div class="foot"><button class="btn" id="dcancel" disabled>Fermer</button></div></div></div>';
  }

  async function resumeJob(id, total, retry) {
    openProgressDialog((retry ? 'Nouvel essai du lot n° ' : 'Reprise du lot n° ') + id);
    setBusy(true);
    try {
      if (retry) { await api('job_retry', { job: id }); }
      await driveJob(id, total, false);
    } catch (e) { jobMessage('Erreur : ' + esc(e.message), true); }
  }

  async function cancelJob(id) {
    try { await api('job_cancel', { job: id }); toast('Lot abandonné'); } catch (e) { toast('Annulation impossible : ' + e.message); }
    checkActive(); refreshData();
  }

  async function checkActive() {
    try {
      const r = await api('job_active');
      const j = r.job, el = $('#jobbar');
      if (!j) { el.innerHTML = ''; return; }
      const partial = j.status === 'partial';
      el.innerHTML = '<div class="pendbar" style="border:1px solid var(--pend);border-radius:6px"><span>Lot n° ' + j.id + (partial ? ' terminé avec ' + j.errors + ' erreur' + (j.errors > 1 ? 's' : '') + ' : tags Piwigo non modifiés' : ' non terminé') + ' (' + esc(j.username) + ') : ' + j.done + ' / ' + j.total + ' fichiers · ' + esc(j.summary) + '</span>' +
        (partial ? '<button class="btn pend" data-jretry="' + j.id + '" data-jtotal="' + j.total + '">Réessayer les erreurs</button>' : '<button class="btn pend" data-jresume="' + j.id + '" data-jtotal="' + j.total + '">Reprendre</button>') +
        '<button class="btn" data-jcancel="' + j.id + '">Abandonner le lot</button></div>';
    } catch (e) { /* facultatif */ }
  }

  async function loadLog() {
    try {
      const r = await api('job_log');
      const ST = { done: 'terminé', running: 'en cours', pending: 'en attente', cancelled: 'annulé', partial: 'partiel' };
      const head = '<tr><th>N°</th><th>Date</th><th>Utilisateur</th><th>Opération</th><th>Fichiers</th><th>Erreurs</th><th>État</th></tr>';
      const rows = r.rows.map(j => '<tr><td>' + j.id + '</td><td>' + esc(j.date) + '</td><td>' + esc(j.username) + '</td><td style="white-space:normal;min-width:200px">' + esc(j.summary) + '</td><td>' + j.done + ' / ' + j.total + '</td><td>' + (parseInt(j.errors, 10) ? '<button class="btn" data-jerr="' + j.id + '">' + j.errors + '</button>' : '0') + '</td><td>' + (ST[j.status] || esc(j.status)) + '</td></tr>').join('');
      $('#joblog').innerHTML = head + (rows || '<tr><td colspan="7" style="text-align:left;color:var(--muted)">Aucune écriture pour l’instant.</td></tr>');
    } catch (e) { /* facultatif */ }
  }

  async function showJobErrors(id) {
    try {
      const e = await api('job_errors', { job: id });
      $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>Erreurs du lot n° ' + id + '</h2><div class="body"><ul style="margin:0 0 0 18px">' +
        e.rows.map(x => '<li><span class="mono">' + esc(x.file) + '</span> : ' + esc(x.message) + '</li>').join('') + '</ul></div><div class="foot"><button class="btn" id="dcancel">Fermer</button></div></div></div>';
    } catch (err) { toast('Lecture impossible : ' + err.message); }
  }

  // ---------- opérations de structure : dialogue de saisie, aperçu serveur, exécution ----------
  const LEVEL = () => (S.level === undefined ? 3 : S.level);
  const nameBad = n => { n = (n || '').trim(); if (!n) { return 'Saisissez un nom.'; } if (/[|>/\\]/.test(n)) { return 'Les caractères | > / \\ sont interdits dans un nom.'; } return null; };
  const groupKeys = () => { const out = []; (function w(n) { kids(n).forEach(c => { if (!isLeafNode(c) && c.name.charAt(0) !== '(') { out.push(c.key); w(c); } }); })(tagTree()); return out; };
  const validTags = () => Object.keys(tagPath).filter(k => !FLAT[k]).sort(ncmp);

  /** Boîte de saisie ; build() renvoie un tableau d'opérations ou un message d'erreur (texte). */
  function structDialog(title, bodyHtml, build, okLabel) {
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body" id="dbody">' + bodyHtml +
      '<div id="gerr" style="color:var(--danger)" hidden></div></div><div class="foot"><button class="btn" id="dcancel">Annuler</button><button class="btn primary" id="sgo">' + esc(okLabel || 'Voir l’aperçu') + '</button></div></div></div>';
    const go = () => {
      const ops = build();
      if (typeof ops === 'string') { const g = $('#gerr'); g.hidden = false; g.textContent = ops; return; }
      structPreview(title, ops);
    };
    $('#sgo').onclick = go;
    const i = $('#gin');
    if (i) { i.focus(); if (i.select) { i.select(); } i.addEventListener('keydown', e => { if (e.key === 'Enter') { go(); } }); } else { $('#dcancel').focus(); }
  }

  /** Aperçu calculé par le serveur (fichiers concernés, erreurs), puis confirmation. */
  async function structPreview(title, ops) {
    structOps = ops;
    let r;
    try { r = await api('struct_preview', { ops: ops }); } catch (e) { toast('Aperçu impossible : ' + e.message); return; }
    if (!r.ok) {
      const err = r.error ? '<b style="color:var(--danger)">' + esc(r.error) + '</b>' : '<b style="color:var(--danger)">Opération refusée.</b>';
      $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body">' + err +
        '<ul style="margin:0 0 0 18px">' + (r.errors || []).map(x => '<li>' + esc(x) + '</li>').join('') + '</ul></div><div class="foot"><button class="btn" id="dcancel">Fermer</button></div></div></div>';
      return;
    }
    const label = r.files ? 'Écrire dans ' + r.files + ' fichier' + (r.files > 1 ? 's' : '') : 'Appliquer';
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body" id="dbody">' +
      r.lines.map(l => '<div class="op"><b>' + esc(l) + '</b></div>').join('') +
      (r.warnings && r.warnings.length ? '<div style="border:1px solid var(--pend);border-radius:6px;padding:8px 10px;margin:6px 0"><b>À savoir avant d’appliquer</b><ul style="margin:4px 0 0 18px">' + r.warnings.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul></div>' : '') +
      '<div>Touche <b>' + r.files + '</b> photo' + (r.files > 1 ? 's' : '') + ' (' + r.tags + ' tag' + (r.tags > 1 ? 's' : '') + '). ' + (r.files ? 'Chaque fichier est relu, modifié puis réécrit, sans toucher à l’image.' : 'Aucun fichier à réécrire.') + '</div>' +
      '<div style="color:var(--muted)">Les tags Piwigo ne sont modifiés qu’une fois <b>tous</b> les fichiers écrits. SmartAlbums suit les fusions et les suppressions par identifiant.</div>' +
      (r.files ? '<label class="chk"><input type="checkbox" id="orig"' + (S.original ? ' checked' : '') + '> Créer une copie <span class="mono">.original</span> si elle n’existe pas</label>' : '') +
      '<div class="prog" id="prog" hidden><div></div></div><div id="pmsg" style="color:var(--muted)" hidden></div></div>' +
      '<div class="foot"><button class="btn" id="dcancel">Annuler</button><button class="btn pend" id="sapply">' + esc(label) + '</button></div></div></div>';
    $('#dcancel').focus();
  }

  async function applyStruct() {
    const original = $('#orig') ? $('#orig').checked : false;
    setBusy(true);
    try {
      const c = await api('struct_create', { ops: structOps, original: original ? 1 : 0 });
      if (!c.ok) {
        jobMessage('<b style="color:var(--danger)">' + esc(c.error) + '</b>' + (c.details ? '<ul style="margin:4px 0 0 18px">' + c.details.map(d => '<li>' + esc(d) + '</li>').join('') + '</ul>' : ''), true);
        return;
      }
      await driveJob(c.job, c.total, false);
    } catch (e) {
      jobMessage('Erreur : ' + esc(e.message) + '. Le lot reste enregistré : il peut être repris depuis la bannière.', true);
      checkActive();
    }
  }

  /** Création d'un groupe ou d'un tag : immédiat (aucun fichier à réécrire). */
  async function createNode(kind, parent, name) {
    try {
      const r = await api(kind === 'group' ? 'group_create' : 'tag_create', { parent: parent, name: name });
      if (!r.ok) { const g = $('#gerr'); if (g) { g.hidden = false; g.textContent = r.error; } return; }
      $('#layer').innerHTML = '';
      structFocus = r.path;
      toast(kind === 'group' ? 'Groupe créé' : 'Tag créé');
      refreshData();
    } catch (e) { toast('Création impossible : ' + e.message); }
  }

  async function deleteEmptyGroup(path) {
    try {
      const r = await api('group_delete', { path: path });
      if (!r.ok) { const g = $('#gerr'); if (g) { g.hidden = false; g.textContent = r.error; } return; }
      $('#layer').innerHTML = '';
      structFocus = path.split('|').slice(0, -1).join('|');
      toast('Groupe supprimé');
      refreshData();
    } catch (e) { toast('Suppression impossible : ' + e.message); }
  }

  function simpleDialog(title, bodyHtml, okLabel, onOk, danger) {
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body" id="dbody">' + bodyHtml +
      '<div id="gerr" style="color:var(--danger)" hidden></div></div><div class="foot"><button class="btn" id="dcancel">Annuler</button><button class="btn ' + (danger ? 'danger' : 'primary') + '" id="sgo">' + esc(okLabel) + '</button></div></div></div>';
    $('#sgo').onclick = onOk;
    const i = $('#gin');
    if (i) { i.focus(); i.addEventListener('keydown', e => { if (e.key === 'Enter') { onOk(); } }); } else { $('#dcancel').focus(); }
  }

  /** Création d'un tag depuis l'écran Étiqueter : créé tout de suite, ajouté en file aux photos sélectionnées. */
  async function createFromPanel() {
    const name = $('#nt-name').value.trim(), group = $('#nt-group').value.trim();
    const bad = nameBad(name);
    if (bad) { toast(bad); return; }
    if (!group) { toast('Choisissez le groupe d’accueil du tag.'); return; }
    if (LEVEL() < 2) { toast('Droits insuffisants.'); return; }
    try {
      const r = await api('tag_create', { parent: group, name: name });
      if (!r.ok) { toast(r.error); return; }
      buildTags(await api('tags'));
      if (sel.size) { setTag(Array.from(sel), name, true); }
      $('#nt-name').value = '';
      renderAll();
      toast(sel.size ? '« ' + name + ' » créé et ajouté à la file pour ' + sel.size + ' photo' + (sel.size > 1 ? 's' : '') : '« ' + name + ' » créé (aucune photo sélectionnée)');
    } catch (e) { toast('Création impossible : ' + e.message); }
  }

  /** Action sur l'élément sélectionné dans l'arbre de « Gérer les tags ». */
  function runStructOp(op, key) {
    const leaf = isLeafKey(key), name = key.split('|').pop(), parent = key.split('|').slice(0, -1).join('|');
    const dl = '<datalist id="dl-g">' + groupKeys().map(k => '<option value="' + esc(k) + '">').join('') + '</datalist>';
    const inp = (id, val, ph, list) => '<input id="' + id + '" class="fin" type="text" autocomplete="off" value="' + esc(val || '') + '" placeholder="' + esc(ph || '') + '"' + (list ? ' list="' + list + '"' : '') + '>';

    if (op === 'newgroup' || op === 'newtag') {
      const par = leaf ? parent : key;
      simpleDialog(op === 'newgroup' ? 'Nouveau groupe' : 'Nouveau tag de dernier niveau',
        '<div style="color:var(--muted)">Dans : <b style="color:var(--fg)">' + esc(par ? human(par) : '(racine)') + '</b></div>' + inp('gin', '', 'Nom') +
        (op === 'newtag' ? '<div style="color:var(--muted)">Le tag est créé dans Piwigo sans photo ; il se remplit ensuite avec l’écran Étiqueter.</div>' : ''),
        'Créer', () => { const v = $('#gin').value.trim(), b = nameBad(v); if (b) { const g = $('#gerr'); g.hidden = false; g.textContent = b; return; } createNode(op === 'newgroup' ? 'group' : 'tag', par, v); });
      return;
    }
    if (op === 'delete_group') {
      simpleDialog('Supprimer le groupe « ' + name + ' » ?', '<div>Ce groupe ne contient aucun tag. Il est retiré de l’index (aucun fichier n’est touché).</div>', 'Supprimer', () => deleteEmptyGroup(key), true);
      return;
    }
    if (op === 'rename') {
      structDialog('Renommer le tag', '<div>Nouveau nom pour <b>' + esc(name) + '</b></div>' + inp('gin', name),
        () => { const v = $('#gin').value.trim(), b = nameBad(v); if (b) { return b; } structFocus = (FLAT[name] ? '(sans chemin)' : parent) + (FLAT[name] || parent ? '|' : '') + v; return [{ type: 'rename', tag: name, name: v }]; });
    } else if (op === 'place') {
      structDialog(FLAT[name] ? 'Ranger « ' + name + ' » dans un groupe' : 'Déplacer « ' + name + ' »',
        '<div>Groupe d’accueil (chemin complet, par exemple <span class="mono">ALBUM|2024 SLOVENIE</span>) :</div>' + inp('gin', FLAT[name] ? '' : parent, 'ALBUM|…', 'dl-g') + dl,
        () => { const v = $('#gin').value.trim(); if (!v) { return 'Choisissez un groupe d’accueil.'; } structFocus = v + '|' + name; return [{ type: 'place', tag: name, group: v }]; });
    } else if (op === 'merge') {
      structDialog('Fusionner « ' + name + ' »',
        '<div>Fusionner dans le tag :</div>' + inp('gin', '', 'Nom d’un tag existant', 'dl-t') + '<datalist id="dl-t">' + validTags().filter(t => t !== name).map(t => '<option value="' + esc(t) + '">').join('') + '</datalist>' +
        '<div style="color:var(--muted)">Les photos de « ' + esc(name) + ' » recevront le tag choisi, puis « ' + esc(name) + ' » sera supprimé.</div>',
        () => { const v = $('#gin').value.trim(); if (!v) { return 'Choisissez le tag de destination.'; } structFocus = tagPath[v] ? tagPath[v].join('|') : null; return [{ type: 'merge', tag: name, to: v }]; });
    } else if (op === 'delete') {
      structDialog('Supprimer le tag « ' + name + ' » ?',
        '<div>Le tag est retiré de toutes ses photos (fichiers réécrits) puis supprimé de Piwigo.</div><div class="op" style="border-color:var(--danger)"><b style="color:var(--danger)">Attention</b><div style="color:var(--muted)">SmartAlbums retire ce tag de ses filtres (suivi par identifiant) : un album qui n’avait que ce tag devient vide. Les sauvegardes <span class="mono">.original</span> ne sont pas touchées.</div></div>',
        () => { structFocus = parent; return [{ type: 'delete', tag: name }]; }, 'Voir l’aperçu');
    } else if (op === 'rename_group') {
      structDialog('Renommer le groupe', '<div>Nouveau nom pour <b>' + esc(name) + '</b></div>' + inp('gin', name),
        () => { const v = $('#gin').value.trim(), b = nameBad(v); if (b) { return b; } structFocus = (parent ? parent + '|' : '') + v; return [{ type: 'rename_group', group: key, name: v }]; });
    } else if (op === 'move_group') {
      structDialog('Déplacer le groupe « ' + name + ' »', '<div>Nouveau parent (chemin complet, vide pour la racine) :</div>' + inp('gin', parent, 'ALBUM|…', 'dl-g') + dl,
        () => { const v = $('#gin').value.trim(); structFocus = (v ? v + '|' : '') + name; return [{ type: 'move_group', group: key, target: v }]; });
    }
  }

  // ---------- rendu : Gérer les tags ----------
  function renderMng() {
    const f = mFilter.trim().toLowerCase();
    const rec = n => {
      let out = '';
      kids(n).forEach(c => {
        const leaf = isLeafNode(c);
        const s2 = leaf ? '' : rec(c);
        if (f && c.name.toLowerCase().indexOf(f) < 0 && !s2) { return; }
        const open = f ? true : !mCol.has(c.key);
        out += '<div class="node' + (leaf ? '' : ' group') + (mkey === c.key ? ' sel' : '') + '" tabindex="0" data-mt="' + esc(c.key) + '"><span class="caret" data-mcar="' + esc(c.key) + '">' + (s2 ? (open ? '▼' : '▶') : '') + '</span>' + (leaf ? '<span class="leafmark" title="Tag Piwigo"></span>' : '') + '<span class="nm" title="' + esc(c.name) + '">' + esc(c.name) + '</span><span class="cnt">' + (leaf ? (TAGN[c.name] || 0) : tagSum(c.key)) + '</span></div>' + (s2 && open ? '<div class="gkids">' + s2 + '</div>' : '');
      });
      return out;
    };
    $('#mtree').innerHTML = rec(tagTree()) || '<div class="empty">Aucun tag.</div>';
    renderFiche();
    const todo = REC.length;
    $('#rec-n').textContent = todo;
  }

  let ficheSeq = 0;
  function renderFiche() {
    const el = $('#mfiche');
    const synthetic = mkey === '(sans chemin)';
    const exists = mkey && (Object.keys(tagPath).some(k => { const j = tagPath[k].join('|'); return j === mkey || j.indexOf(mkey + '|') === 0; }) || emptyGroups.has(mkey));
    if (!exists) { el.innerHTML = '<div class="empty">Sélectionnez un tag ou un groupe dans l’arbre.</div>'; return; }
    const leaf = isLeafKey(mkey), name = mkey.split('|').pop(), lv = leavesUnder(mkey);
    const flat = leaf && FLAT[name];
    const can = LEVEL() >= 3 ? '' : ' disabled title="Droits insuffisants"';
    const b = (op, label, cls, always) => '<button class="btn' + (cls ? ' ' + cls : '') + '" data-sop="' + op + '"' + (always && LEVEL() >= 2 ? '' : can) + '>' + label + '</button>';
    let actions = '';
    if (synthetic) { actions = '<span class="sub">Regroupement d’affichage : ces tags n’ont pas de chemin dans les fichiers. Rangez-les dans un groupe, ou fusionnez-les.</span>'; }
    else if (leaf) { actions = b('rename', 'Renommer') + b('place', flat ? 'Ranger dans un groupe…' : 'Déplacer…') + b('merge', 'Fusionner avec…') + b('delete', 'Supprimer', 'danger'); }
    else { actions = b('newgroup', '+ Sous-groupe', '', true) + b('newtag', '+ Tag ici', '', true) + b('rename_group', 'Renommer') + b('move_group', 'Déplacer…') + (lv.length ? '' : b('delete_group', 'Supprimer', 'danger')); }
    el.innerHTML = '<div class="fiche"><div class="fh"><h2>' + esc(name) + '</h2><span class="badge' + (leaf ? ' leaf' : '') + '">' + (leaf ? 'Tag Piwigo' : 'Groupe') + '</span>' + (flat ? '<span class="chip">sans chemin</span>' : '') + '</div>' +
      '<p class="sub">' + (leaf ? 'Dernier niveau : c’est le tag que Piwigo connaît.' : 'Niveau intermédiaire : il n’existe que dans les chemins des fichiers.') + '</p>' +
      '<dl class="kv"><dt>Chemin</dt><dd class="mono">' + esc(flat ? '(pas de chemin retrouvé dans les fichiers testés)' : mkey) + '</dd><dt>Photos</dt><dd id="f-n">…</dd>' + (leaf ? '' : '<dt>Tags dessous</dt><dd>' + lv.length + '</dd>') +
      '<dt>Écrit dans</dt><dd class="mono">' + (leaf ? 'dc:subject, ' : '') + 'lr:hierarchicalSubject, digiKam:TagsList, MicrosoftPhoto, acdsee</dd></dl>' +
      '<div class="acts">' + actions + '</div>' +
      '<h3>Albums concernés</h3><ul class="alist" id="f-alb"><li><span style="color:var(--muted)">…</span></li></ul></div>';
    const seq = ++ficheSeq;
    api('tag_albums', { ids: lv.map(n => TAGID[n]).filter(Boolean).join(',') }).then(r => {
      if (seq !== ficheSeq) { return; }
      $('#f-n').textContent = r.total;
      $('#f-alb').innerHTML = r.albums.map(a => '<li><span>' + esc(a.alb && albMap[a.alb] ? albPath(a.alb).join(' › ') : '(sans album)') + '</span><span class="cnt">' + a.n + '</span></li>').join('') || '<li><span style="color:var(--muted)">Aucune photo</span></li>';
    }).catch(() => { if (seq === ficheSeq) { $('#f-n').textContent = '?'; } });
  }

  // ---------- réconciliation ----------
  const RACT = { merge: 'Fusionner vers…', rename: 'Renommer en…', place: 'Ranger dans un groupe…', delete: 'Supprimer le tag', skip: 'Laisser tel quel' };
  const recOp = r => {
    const t = (r.target || '').trim();
    if (r.act === 'merge') { return { type: 'merge', tag: r.name, to: t }; }
    if (r.act === 'rename') { return { type: 'rename', tag: r.name, name: t }; }
    if (r.act === 'place') { return { type: 'place', tag: r.name, group: t }; }
    if (r.act === 'delete') { return { type: 'delete', tag: r.name }; }
    return null;
  };
  function renderRec() {
    $('#rec-sum').innerHTML = '<b>' + REC.length + '</b> tag' + (REC.length > 1 ? 's' : '') + ' à réconcilier<small>d’après la dernière indexation</small>';
    const dlt = $('#dl-tags'), dlg2 = $('#dl-groups');
    if (dlt) { dlt.innerHTML = validTags().map(t => '<option value="' + esc(t) + '">').join(''); }
    if (dlg2) { dlg2.innerHTML = groupKeys().map(t => '<option value="' + esc(t) + '">').join(''); }
    const can = LEVEL() >= 3 ? '' : ' disabled';
    const head = '<tr><th>Tag dans Piwigo</th><th>Photos</th><th>Constat</th><th>Action</th><th>Cible</th><th></th></tr>';
    const rows = REC.map((r, i) => {
      const badge = r.conf === 'haute' ? '<span class="chip n">suggestion fiable</span>' : '<span class="chip">à vérifier</span>';
      const opts = Object.keys(RACT).map(k => '<option value="' + k + '"' + (r.act === k ? ' selected' : '') + '>' + RACT[k] + '</option>').join('');
      const needs = r.act === 'merge' || r.act === 'rename' || r.act === 'place';
      const list = r.act === 'merge' ? ' list="dl-tags"' : r.act === 'place' ? ' list="dl-groups"' : '';
      const ph = r.act === 'merge' ? 'Tag existant…' : r.act === 'rename' ? 'Nouveau nom…' : 'Groupe (ALBUM|…)';
      const tg = needs ? '<input type="text" class="rec-tg" data-ri="' + i + '" value="' + esc(r.target || '') + '" placeholder="' + ph + '"' + list + ' aria-label="Cible pour ' + esc(r.name) + '">' : '<span class="cnt">—</span>';
      const btn = r.act === 'skip' ? '<span class="cnt">ignoré</span>' : '<button class="btn pend" data-rgo="' + i + '"' + can + '>Appliquer</button>';
      return '<tr><td><div class="nm">' + esc(r.name) + '</div>' + (r.status === 'nofile' ? '<div class="why">fichier introuvable</div>' : '') + '</td><td class="n">' + r.n + '</td><td><div>' + badge + '</div><div class="why">' + esc(r.why) + '</div></td>' +
        '<td><select class="rec-act" data-ri="' + i + '" aria-label="Action pour ' + esc(r.name) + '">' + opts + '</select></td><td>' + tg + '</td><td>' + btn + '</td></tr>';
    }).join('');
    $('#rec-table').innerHTML = head + (rows || '<tr><td colspan="6" class="why">Aucun tag à réconcilier.</td></tr>');
  }

  function recApply(i) {
    const r = REC[i], op = recOp(r);
    if (!op) { return; }
    structFocus = null;
    structPreview('Réconciliation : ' + r.name, [op]);
  }
  function recBulk() {
    const ops = [];
    REC.forEach(r => { if (r.conf === 'haute' && (r.act === 'merge' || r.act === 'rename') && (r.target || '').trim()) { ops.push(recOp(r)); } });
    if (!ops.length) { toast('Aucune suggestion fiable à appliquer.'); return; }
    structFocus = null;
    structPreview('Appliquer ' + ops.length + ' suggestions fiables', ops);
  }

  // ---------- rendu : Paramètres ----------
  const LVL = ['Étiqueter', 'Gérer : créer', 'Gérer : tout'];
  function renderSet() {
    $('#set-orig').checked = !!S.original;
    $('#set-thumb').value = S.thumb || 'large';
    if (document.activeElement !== $('#set-tw')) { $('#set-tw').value = S.tw || 300; }
    if (document.activeElement !== $('#set-reserved')) { $('#set-reserved').value = (S.reserved || []).join(', '); }
    const head = '<tr><th>Qui</th><th>Type</th>' + LVL.map(l => '<th>' + l + '</th>').join('') + '<th></th></tr>';
    const locked = ['Webmestres'].map(w => '<tr><td><b>' + w + '</b></td><td>Rôle Piwigo</td><td><input type="checkbox" checked disabled></td><td><input type="checkbox" checked disabled></td><td><input type="checkbox" checked disabled></td><td><span class="cnt">toujours</span></td></tr>').join('');
    const rows = S.rights.map((r, i) => '<tr><td><b>' + esc(r.who) + '</b></td><td>' + esc(r.type) + '</td>' + r.p.map((v, j) => '<td><input type="checkbox" class="rcb" data-ri="' + i + '" data-pi="' + j + '"' + (v ? ' checked' : '') + ' aria-label="' + esc(r.who + ' : ' + LVL[j]) + '"></td>').join('') + '<td><button class="btn" data-rdel="' + i + '" aria-label="Retirer ' + esc(r.who) + ' de la table">Retirer</button></td></tr>').join('');
    $('#rights').innerHTML = head + locked + rows;
    const kind = $('#r-kind').value, used = new Set(S.rights.map(r => r.k));
    const cand = (kind === 'g' ? S.groups : S.users).filter(n => !used.has(kind + ':' + n));
    $('#r-who').innerHTML = cand.map(n => '<option>' + esc(n) + '</option>').join('');
    $('#r-add').disabled = !cand.length;
  }

  // ---------- tout redessiner ----------
  function renderAll() {
    renderTree(); renderToolbar(); renderGrid(); renderPanel(); renderBar(); renderMng(); renderRec();
    const ntg = $('#dl-ntg');
    if (ntg) { ntg.innerHTML = groupKeys().map(k => '<option value="' + esc(k) + '">').join(''); }
    $$('.tab').forEach(t => t.setAttribute('aria-selected', t.getAttribute('data-tab') === view.tab));
  }

  function showScreen(s) {
    screen = s;
    $('#app-tag').hidden = s !== 'tag';
    $('#app-mng').hidden = s !== 'mng';
    $('#app-set').hidden = s !== 'set';
    $$('.stab,.gear').forEach(b => b.setAttribute('aria-selected', b.getAttribute('data-screen') === s));
    renderAll(); renderSet();
    if (s === 'set') { loadLog(); }
  }
  function showSub(s) {
    sub = s;
    $('#mng-tree').hidden = s !== 'tree';
    $('#mng-rec').hidden = s !== 'rec';
    $$('.subtab').forEach(b => b.setAttribute('aria-selected', b.getAttribute('data-sub') === s));
    renderAll();
  }

  // ---------- événements ----------
  root.addEventListener('click', function (e) {
    const t = e.target;
    const g = sel2 => t.closest(sel2);
    let x;
    if (g('#fs-toggle')) { setFull(!root.classList.contains('full')); return; }
    if ((x = g('[data-screen]'))) { showScreen(x.getAttribute('data-screen')); return; }
    if ((x = g('[data-sub]'))) { showSub(x.getAttribute('data-sub')); return; }
    if ((x = g('[data-car]'))) { const k = x.getAttribute('data-car'); collapsed.has(k) ? collapsed.delete(k) : collapsed.add(k); renderTree(); return; }
    if ((x = g('[data-tab]'))) {
      view.tab = x.getAttribute('data-tab');
      if (view.tab === 'tags' && !view.key) {
        const first = topTag();
        view.key = first ? tagPath[first].join('|') : '';
      }
      view.desc = true; changeView(); return;
    }
    if ((x = g('[data-alb]'))) { view.id = parseInt(x.getAttribute('data-alb'), 10); view.desc = true; changeView(); return; }
    if ((x = g('[data-tag]'))) { view.key = x.getAttribute('data-tag'); view.desc = true; changeView(); return; }
    if ((x = g('.tile'))) {
      const id = parseInt(x.getAttribute('data-id'), 10), vis = visible().map(p => p.id);
      if (e.shiftKey && anchor) { const a = vis.indexOf(anchor), b = vis.indexOf(id); vis.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(v => sel.add(v)); }
      else if (e.ctrlKey || e.metaKey) { sel.has(id) ? sel.delete(id) : sel.add(id); anchor = id; }
      else { sel = new Set([id]); anchor = id; }
      renderToolbar(); renderGrid(); renderPanel(); return;
    }
    if ((x = g('[data-page]'))) { if (!x.disabled) { gotoPage(parseInt(x.getAttribute('data-page'), 10)); } return; }
    if (t.id === 'selall') { sel = new Set(visible().map(p => p.id)); renderToolbar(); renderGrid(); renderPanel(); return; }
    if (t.id === 'selnone') { sel = new Set(); renderToolbar(); renderGrid(); renderPanel(); return; }
    if (t.id === 'rmtag') { setTag(Array.from(sel), view.key.split('|').pop(), false); renderAll(); return; }
    if (t.id === 'cancel') { Object.keys(pend).forEach(k => delete pend[k]); renderAll(); toast('Modifications annulées'); return; }
    if (t.id === 'preview') { openPreview(); return; }
    if (t.id === 'dcancel') { if (!jobRunning) { $('#layer').innerHTML = ''; } return; }
    if (t.id === 'dgo') { startJob(); return; }
    if (t.id === 'sapply') { applyStruct(); return; }
    if (t.id === 'jretry') { resumeJob(parseInt(t.getAttribute('data-job'), 10), parseInt(t.getAttribute('data-total'), 10), true); return; }
    if ((x = g('[data-jretry]'))) { resumeJob(parseInt(x.getAttribute('data-jretry'), 10), parseInt(x.getAttribute('data-jtotal'), 10), true); return; }
    if ((x = g('[data-sop]'))) { if (!x.disabled) { runStructOp(x.getAttribute('data-sop'), mkey); } return; }
    if ((x = g('[data-rgo]'))) { recApply(parseInt(x.getAttribute('data-rgo'), 10)); return; }
    if (t.id === 'rec-bulk') { recBulk(); return; }
    if (t.id === 'nt-go') { createFromPanel(); return; }
    if ((x = g('[data-jresume]'))) { resumeJob(parseInt(x.getAttribute('data-jresume'), 10), parseInt(x.getAttribute('data-jtotal'), 10)); return; }
    if ((x = g('[data-jcancel]'))) { cancelJob(parseInt(x.getAttribute('data-jcancel'), 10)); return; }
    if ((x = g('[data-jerr]'))) { showJobErrors(parseInt(x.getAttribute('data-jerr'), 10)); return; }
    if ((x = g('[data-pcar]'))) { const k = x.getAttribute('data-pcar'); pCol.has(k) ? pCol.delete(k) : pCol.add(k); renderPanel(); return; }
    if ((x = g('.row')) && sel.size) {
      const tag = x.getAttribute('data-t'), ids = Array.from(sel);
      const all = ids.every(id => PH[id] && eff(PH[id]).has(tag));
      setTag(ids, tag, !all); renderAll(); return;
    }
    if ((x = g('[data-mcar]'))) { const k = x.getAttribute('data-mcar'); mCol.has(k) ? mCol.delete(k) : mCol.add(k); renderMng(); return; }
    if ((x = g('[data-mt]'))) { mkey = x.getAttribute('data-mt'); renderMng(); return; }
    // paramètres
    if ((x = g('[data-rdel]'))) { S.rights.splice(parseInt(x.getAttribute('data-rdel'), 10), 1); renderSet(); return; }
    if (t.id === 'r-add') {
      const kind = $('#r-kind').value, n = $('#r-who').value;
      if (n) { S.rights.push({ k: kind + ':' + n, who: n, type: kind === 'g' ? 'Groupe' : 'Utilisateur', p: [1, 0, 0] }); renderSet(); }
      return;
    }
    if (t.id === 'set-save') {
      api('save_settings', { settings: { original: S.original, thumb: S.thumb, tw: S.tw, reserved: S.reserved, rights: S.rights } })
        .then(r => {
          S.original = r.saved.original; S.thumb = r.saved.thumb; S.tw = r.saved.tw; applyThumbWidth(S.tw); S.rights = r.saved.rights; S.reserved = r.saved.reserved; renderSet();
          if (r.saved.thumb !== thumbAtLoad) {
            toast('Paramètres enregistrés : rechargement pour appliquer la taille des miniatures…');
            setTimeout(() => location.reload(), 1200);
          } else if (r.saved.reserved.join('|') !== reservedAtLoad) {
            toast('Paramètres enregistrés : rechargement pour appliquer les tags réservés…');
            setTimeout(() => location.reload(), 1200);
          } else { toast('Paramètres enregistrés'); }
        })
        .catch(err => toast('Échec de l’enregistrement : ' + err.message));
    }
  });

  root.addEventListener('change', function (e) {
    const t = e.target;
    if (t.id === 'ponly') { pOnly = t.checked; renderPanel(); return; }
    if (t.id === 'desc') { view.desc = t.checked; changeView(); return; }
    if (t.id === 'set-orig') { S.original = t.checked; return; }
    if (t.id === 'set-thumb') { S.thumb = t.value; return; }
    if (t.classList && t.classList.contains('rec-act')) { const r = REC[parseInt(t.getAttribute('data-ri'), 10)]; r.act = t.value; renderRec(); return; }
    if (t.id === 'r-kind') { renderSet(); return; }
    if (t.classList && t.classList.contains('rcb')) {
      const r = S.rights[parseInt(t.getAttribute('data-ri'), 10)], j = parseInt(t.getAttribute('data-pi'), 10);
      if (t.checked) { for (let k = 0; k <= j; k++) { r.p[k] = 1; } } else { for (let k = j; k < 3; k++) { r.p[k] = 0; } }
      renderSet();
    }
  });

  // clic droit dans un arbre : tout déplier / tout replier
  function closeCtx() { const m = $('.ctx'); if (m) { m.remove(); } }
  function treeTarget(el) {
    const keys = () => { const out = []; (function w(n) { Object.keys(n.ch).forEach(k => { const c = n.ch[k]; if (Object.keys(c.ch).length) { out.push(c.key); w(c); } }); })(tagTree()); return out; };
    if (el.closest('#tree')) {
      return view.tab === 'albums'
        ? { set: collapsed, keys: () => Object.keys(albMap).filter(id => albMap[id].a.ch.length), draw: renderTree }
        : { set: collapsed, keys: keys, draw: renderTree };
    }
    if (el.closest('#rows')) { return { set: pCol, keys: keys, draw: renderPanel }; }
    if (el.closest('#mtree')) { return { set: mCol, keys: keys, draw: renderMng }; }
    return null;
  }
  root.addEventListener('contextmenu', function (e) {
    const tg = treeTarget(e.target);
    if (!tg) { return; }
    e.preventDefault();
    closeCtx();
    const m = document.createElement('div');
    m.className = 'ctx'; m.setAttribute('role', 'menu');
    m.innerHTML = '<button role="menuitem" data-ctx="open">Tout déplier</button><button role="menuitem" data-ctx="close">Tout replier</button>';
    m.style.left = Math.min(e.clientX, window.innerWidth - 200) + 'px';
    m.style.top = Math.min(e.clientY, window.innerHeight - 90) + 'px';
    m.addEventListener('click', ev => {
      const b = ev.target.closest('[data-ctx]');
      if (!b) { return; }
      tg.set.clear();
      if (b.getAttribute('data-ctx') === 'close') { tg.keys().forEach(k => tg.set.add(String(k))); }
      closeCtx(); tg.draw();
    });
    root.appendChild(m);
  });
  document.addEventListener('click', e => { if (!e.target.closest || !e.target.closest('.ctx')) { closeCtx(); } });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeCtx(); } });
  window.addEventListener('scroll', closeCtx, true);

  root.addEventListener('input', e => { const t = e.target; if (t.classList && t.classList.contains('rec-tg')) { REC[parseInt(t.getAttribute('data-ri'), 10)].target = t.value; } });
  $('#set-reserved').addEventListener('input', e => { S.reserved = e.target.value.split(',').map(x => x.trim()).filter(Boolean); });
  $('#pfilter').addEventListener('input', e => { pFilter = e.target.value; renderPanel(); });
  $('#mfilter').addEventListener('input', e => { mFilter = e.target.value; renderMng(); });
  function applyThumbWidth(v) {
    v = Math.max(90, Math.min(400, parseInt(v, 10) || 300));
    $('#app-tag').style.setProperty('--tw', v + 'px');
    $('#tsize').value = v; $('#tsizev').textContent = v + ' px';
  }
  $('#tsize').addEventListener('input', e => { applyThumbWidth(e.target.value); });
  $('#set-tw').addEventListener('input', e => { S.tw = e.target.value; });

  root.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && $('#layer').firstChild && !jobRunning) { $('#layer').innerHTML = ''; }
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.node,.tile,.row')) { e.preventDefault(); e.target.click(); }
  });

  // glisser-déposer : photos -> tag (file locale)
  root.addEventListener('dragstart', function (e) {
    const tile = e.target.closest && e.target.closest('.tile');
    if (!tile) { return; }
    const id = parseInt(tile.getAttribute('data-id'), 10);
    if (!sel.has(id)) { sel = new Set([id]); renderToolbar(); renderGrid(); renderPanel(); }
    e.dataTransfer.setData('text/plain', 'photos');
    e.dataTransfer.effectAllowed = 'copy';
  });
  root.addEventListener('dragover', function (e) {
    $$('.node.drop').forEach(x => x.classList.remove('drop'));
    const n = e.target.closest && e.target.closest('[data-leaf]');
    if (n) { e.preventDefault(); n.classList.add('drop'); }
  });
  root.addEventListener('drop', function (e) {
    $$('.node.drop').forEach(x => x.classList.remove('drop'));
    const n = e.target.closest && e.target.closest('[data-leaf]');
    if (!n) { return; }
    e.preventDefault();
    setTag(Array.from(sel), n.getAttribute('data-leaf'), true); renderAll();
    toast('« ' + n.getAttribute('data-leaf') + ' » ajouté à la file pour ' + sel.size + ' photo' + (sel.size > 1 ? 's' : ''));
  });
  root.addEventListener('dragend', function () { $$('.node.drop').forEach(x => x.classList.remove('drop')); });

  // largeur des panneaux (poignées, flèches, double-clic = valeur par défaut)
  let L = {};
  try { L = JSON.parse(localStorage.getItem('tm_layout') || '{}'); } catch (_) { L = {}; }
  const LIM = { w1: [180, 520], w3: [220, 560], m1: [240, 640] };
  function applyL() {
    ['w1', 'w3'].forEach(k => { if (L[k]) { $('#app-tag').style.setProperty('--' + k, L[k] + 'px'); } });
    if (L.m1) { $('#mng-tree').style.setProperty('--m1', L.m1 + 'px'); }
  }
  const saveL = () => { try { localStorage.setItem('tm_layout', JSON.stringify(L)); } catch (_) { /* sans stockage */ } };
  const setW = (k, v) => { L[k] = Math.max(LIM[k][0], Math.min(LIM[k][1], Math.round(v))); applyL(); };
  const rzPane = h => h.getAttribute('data-rz') === 'w3' ? h.nextElementSibling : h.previousElementSibling;
  const rzDir = h => h.getAttribute('data-rz') === 'w3' ? -1 : 1;
  root.addEventListener('pointerdown', function (e) {
    const h = e.target.closest && e.target.closest('.rz');
    if (!h) { return; }
    e.preventDefault();
    const k = h.getAttribute('data-rz'), w0 = rzPane(h).getBoundingClientRect().width, x0 = e.clientX, d = rzDir(h);
    h.classList.add('on'); h.setPointerCapture(e.pointerId);
    const mv = ev => setW(k, w0 + d * (ev.clientX - x0));
    const up = () => { h.classList.remove('on'); h.removeEventListener('pointermove', mv); h.removeEventListener('pointerup', up); saveL(); };
    h.addEventListener('pointermove', mv); h.addEventListener('pointerup', up);
  });
  root.addEventListener('keydown', function (e) {
    const h = e.target.closest && e.target.closest('.rz');
    if (!h || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) { return; }
    e.preventDefault();
    setW(h.getAttribute('data-rz'), rzPane(h).getBoundingClientRect().width + rzDir(h) * (e.key === 'ArrowRight' ? 20 : -20)); saveL();
  });
  root.addEventListener('dblclick', function (e) {
    const h = e.target.closest && e.target.closest('.rz');
    if (!h) { return; }
    const k = h.getAttribute('data-rz');
    delete L[k];
    (k === 'm1' ? $('#mng-tree') : $('#app-tag')).style.removeProperty('--' + k);
    saveL();
  });

  // ---------- plein écran (masque les menus et l'en-tête de Piwigo) ----------
  function setFull(on) {
    root.classList.toggle('full', on);
    document.body.classList.toggle('tm-full', on);
    const b = $('#fs-toggle');
    if (b) { b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.textContent = on ? '✕ Quitter le plein écran' : '⛶ Plein écran'; }
    try { localStorage.setItem('tm_full', on ? '1' : '0'); } catch (_) { /* sans stockage */ }
  }
  if (!PUBLIC) {
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && root.classList.contains('full') && !$('#layer').innerHTML) { setFull(false); } });
    try { if (localStorage.getItem('tm_full') === '1') { setFull(true); } } catch (_) { /* sans stockage */ }
  } else {
    document.body.classList.add('tm-full');
  }

  // écrans accessibles selon le niveau (le serveur revérifie chaque action)
  function applyAccess() {
    if (!PUBLIC) { return; }
    const gear = $('.gear'); if (gear) { gear.hidden = true; }
    const mng = $('[data-screen="mng"]'); if (mng) { mng.hidden = LEVEL() < 2; }
  }

  // ---------- démarrage ----------
  async function boot() {
    $('#tm-sub').textContent = 'Chargement des albums et des tags…';
    try {
      const res = await Promise.all([api('albums'), api('tags'), api('settings')]);
      buildAlbums(res[0].albums);
      buildTags(res[1]);
      S = res[2];
      applyAccess();
      reservedAtLoad = (S.reserved || []).join('|');
      thumbAtLoad = S.thumb;
      view.id = ALB.length ? ALB[0].id : null;
      const first = topTag();
      mkey = first ? tagPath[first].slice(0, -1).join('|') : '';
      applyL();
      $('#tm-sub').textContent = 'Les modifications restent en attente jusqu’à « Aperçu et appliquer » ; elles sont alors écrites dans les fichiers.';
      applyThumbWidth(S.tw);
      await loadPhotos(0);
      renderAll(); renderSet(); checkActive();
      api('recon').then(r => { REC = r.rows; renderMng(); renderRec(); }).catch(() => { /* facultatif */ });
    } catch (e) {
      $('#tree').innerHTML = '<div class="empty">Chargement impossible : ' + esc(e.message) + '</div>';
      $('#tm-sub').textContent = '';
    }
  }
  boot();
})();
