/* tag_manager — interface d'administration (issue de la maquette).
 * Phase 1 : lecture des albums, des tags et des photos réels ; paramètres enregistrés.
 * Les modifications de tags restent dans une file locale : l'écriture dans les fichiers viendra ensuite. */
(function () {
  'use strict';

  // Traduction : la clé est le texte français (langue pivot) ; le dictionnaire est fourni par PHP
  // (language/<langue>/js.lang.php), vide pour un utilisateur francophone. Voir dev/i18n_wrap.js.
  const I18N = (window.TM_I18N && typeof window.TM_I18N === 'object') ? window.TM_I18N : {};
  const tx = s => (Object.prototype.hasOwnProperty.call(I18N, s) ? I18N[s] : s);

  const root = document.getElementById('tm-app');
  if (!root) { return; }
  const API = root.getAttribute('data-url');
  const TOKEN = root.getAttribute('data-token');
  const GALLERY = root.getAttribute('data-gallery') || '';
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
    setTimeout(() => t.remove(), Math.max(2800, String(m).length * 60)); // message long : le temps de le lire
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
    if (j.stat !== 'ok') { throw new Error(j.message || tx('erreur')); }
    return j;
  }

  // ---------- état ----------
  let ALB = [], albMap = {}, albTot = {}, albAll = {};
  let tagPath = {}, TAGID = {}, IDNAME = {}, TAGN = {}, FLAT = {}, NOPHOTO = {};
  const NOPHOTO_G = tx('(sans photo)'); // regroupement d'affichage des tags attachés à aucune photo (Gérer les tags seulement)
  let emptyGroups = new Set();
  let PH = {};
  let CUR = { list: [], total: 0, loading: false };
  let loadSeq = 0;
  const pend = {};
  let sel = new Set(), anchor = null;
  const view = { tab: 'albums', id: null, key: null, desc: true };
  const collapsed = new Set();
  let pFilter = '', pOnly = false;
  const tFilter = { albums: '', tags: '' }; // filtre de l'arbre de gauche d'« Étiqueter », un par onglet
  /** Forme de recherche : sans casse ni accents (comme Piwigo). */
  const fold = s => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const pCol = new Set();
  let mkey = '', mFilter = '', sub = 'tree', screen = 'tag';
  const mCol = new Set();
  let REC = [];
  let S = { original: false, thumb: 'large', reserved: [], rights: [], groups: [], users: [] };
  let reservedAtLoad = '', thumbAtLoad = 'large';

  // ---------- albums ----------
  function buildAlbums(list) {
    albMap = {};
    // le serveur renvoie les albums dans l'ordre de « Gestion des albums » (orphelines en dernier) : on le garde
    list.forEach(a => { albMap[a.id] = { a: { id: a.id, name: a.name, n: a.n, na: a.na || 0, smart: !!a.smart, broken: a.broken || '', orphans: !!a.orphans, ch: [] }, par: a.parent }; });
    ALB = [];
    list.forEach(a => {
      const e = albMap[a.id];
      if (a.parent && albMap[a.parent]) { albMap[a.parent].a.ch.push(e.a); } else { ALB.push(e.a); }
    });
    albTot = {}; albAll = {};
    const tot = a => { let n = a.n; a.ch.forEach(c => { n += tot(c); }); albTot[a.id] = n; return n; };
    // tous les fichiers, sous-albums compris : sert au lien ↗ (un album sans JPEG peut contenir des vidéos)
    const totAll = a => { let n = a.na || 0; a.ch.forEach(c => { n += totAll(c); }); albAll[a.id] = n; return n; };
    ALB.forEach(tot); ALB.forEach(totAll);
  }
  const albPath = id => { const r = []; let c = id; while (c && albMap[c]) { r.unshift(albMap[c].a.name); c = albMap[c].par; } return r; };
  const SMART_TIP = tx('Album SmartAlbums : son contenu est calculé d’après les tags. Ajouter ou retirer un tag peut faire entrer ou sortir des photos de cet album au prochain rafraîchissement.');
  const SMART_CLICK = tx('Cliquez sur l’icône pour mettre à jour cet album.');
  const BROKEN_TIP = {
    norule: tx('SmartAlbum sans critère : SmartAlbums y range presque toutes les photos de la galerie (son compte n’a pas de sens). À corriger dans SmartAlbums : redéfinir ses critères, ou décocher « SmartAlbum ».'),
    flood: tx('SmartAlbum qui contient presque toute la galerie : un de ses critères ne désigne probablement plus rien (album ou tag supprimé). À vérifier dans SmartAlbums.')
  };
  const albSub = id => { const r = [id]; ((albMap[id] && albMap[id].a.ch) || []).forEach(c => r.push.apply(r, albSub(c.id))); return r; };

  // ---------- tags ----------
  function buildTags(T) {
    tagPath = {}; TAGID = {}; IDNAME = {}; TAGN = {}; FLAT = {}; NOPHOTO = {};
    T.tags.forEach(t => {
      const ok = t.status === 'ok' && t.path;
      // tag créé dans Piwigo sans aucune photo : rien à lire dans les fichiers, regroupé sous « (sans photo) »
      const nophoto = !ok && !t.na;
      if (!t.n && !t.path && !nophoto) { return; } // photos invisibles pour cet utilisateur
      tagPath[t.name] = ok ? t.path.split('|').filter(Boolean) : [nophoto ? NOPHOTO_G : tx('(sans chemin)'), t.name];
      TAGID[t.name] = t.id; IDNAME[t.id] = t.name; TAGN[t.name] = t.n;
      if (!ok) { FLAT[t.name] = true; }
      if (nophoto) { NOPHOTO[t.name] = true; }
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
  const human = k => k ? k.split('|').join(' › ') : tx('(racine)');
  /** Même chemin, en ignorant casse et accents (comme MySQL / tagmanager_fold). */
  const tagmanagerSame = (a, b) => { const f = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(); return f(a) === f(b); };

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
      if (seq === loadSeq) { toast(tx('Chargement impossible : ') + e.message); }
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
  /** Petit lien ↗ (visible au survol de la ligne) vers la page de la galerie, dans un nouvel onglet. */
  function galLink(path, what) {
    if (!path || !GALLERY) { return ''; }
    return '<a class="gal" draggable="false" href="' + esc(GALLERY + path) + tx('" target="_blank" rel="noopener" title="Ouvrir ') + what + tx(' dans la galerie (nouvel onglet)" aria-label="Ouvrir ') + what + tx(' dans la galerie">↗</a>');
  }

  function renderTree() {
    const el = $('#tree');
    let h = '';
    const inp = $('#tfilter');
    if (inp) {
      inp.placeholder = view.tab === 'albums' ? tx('Filtrer les albums…') : tx('Filtrer les tags…');
      if (inp.value !== tFilter[view.tab]) { inp.value = tFilter[view.tab]; }
    }
    const f = fold(tFilter[view.tab]);
    // avec un filtre : un élément est montré s'il correspond, si un de ses descendants correspond (ses parents
    // sont alors ouverts) ou si un de ses ancêtres correspond (la branche trouvée reste consultable)
    let found = false;
    if (view.tab === 'albums') {
      const hit = a => !f || fold(a.name).indexOf(f) >= 0;
      const below = a => a.ch.some(c => hit(c) || below(c));
      const rec = (l, inHit) => '<ul class="tree">' + l.map(a => {
        const self = hit(a), deep = f ? below(a) : false;
        if (f && !inHit && !self && !deep) { return ''; }
        if (f && self) { found = true; }
        const has = a.ch.length, open = f && deep ? true : !collapsed.has(String(a.id));
        const empty = !albTot[a.id];
        const smart = a.broken ? '<span data-smart="' + a.id + '" role="button" tabindex="0" title="' + esc(BROKEN_TIP[a.broken] + ' ' + SMART_CLICK) + '" style="flex:none;margin-left:4px;color:#c0392b;cursor:pointer">⚠</span>' : a.smart ? '<span data-smart="' + a.id + '" role="button" tabindex="0" title="' + esc(SMART_TIP + ' ' + SMART_CLICK) + '" style="flex:none;margin-left:4px;color:var(--muted);cursor:pointer">⟳</span>' : '';
        return '<li><div class="node' + (view.id === a.id ? ' sel' : '') + '" tabindex="0" data-alb="' + a.id + '"' + (empty ? ' style="opacity:.5"' : '') + '><span class="caret" data-car="' + a.id + '">' + (has ? (open ? '▼' : '▶') : '') + '</span><span class="nm" title="' + esc(a.name) + (empty ? tx(' (aucune photo JPEG)') : '') + '"' + (a.orphans ? ' style="font-style:italic"' : '') + '>' + esc(a.name) + '</span>' + smart + '<span class="cnt">' + albTot[a.id] + '</span>' + galLink(a.id > 0 && albAll[a.id] ? 'index.php?/category/' + a.id : '', tx('l’album')) + '</div>' + (has && open ? rec(a.ch, inHit || (f && self)) : '') + '</li>';
      }).join('') + '</ul>';
      const tree = rec(ALB, false);
      h = (f && !found ? tx('<div class="empty">Aucun album ne correspond.</div>') : tree) + tx('<div class="hint">Albums de la galerie, dans l’ordre de « Gestion des albums » : une photo apparaît dans chaque album où elle est rangée, qu’elle vienne de la synchronisation ou d’un envoi (upload). Grisé : aucune photo JPEG. ⟳ : album SmartAlbums (son contenu suit les tags) ; ⚠ : SmartAlbum sans critère valable. « Photos orphelines » : photos rangées dans aucun album. ↗ (au survol) : ouvrir l’album dans la galerie.</div>');
    } else {
      const hit = c => !f || fold(c.name).indexOf(f) >= 0;
      const below = c => Object.keys(c.ch).some(k => hit(c.ch[k]) || below(c.ch[k]));
      const rec = (n, inHit) => '<ul class="tree">' + kids(n).map(c => {
        const self = hit(c), deep = f ? below(c) : false;
        if (f && !inHit && !self && !deep) { return ''; }
        if (f && self) { found = true; }
        const k2 = Object.keys(c.ch).length, open = f && deep ? true : !collapsed.has(c.key), leaf = isLeafNode(c);
        return '<li><div class="node' + (leaf ? '' : ' group') + (view.key === c.key ? ' sel' : '') + '" tabindex="0" data-tag="' + esc(c.key) + '"' + (leaf ? ' data-leaf="' + esc(c.name) + '"' : '') + '><span class="caret" data-car="' + esc(c.key) + '">' + (k2 ? (open ? '▼' : '▶') : '') + '</span>' + (leaf ? tx('<span class="leafmark" title="Tag Piwigo"></span>') : '') + '<span class="nm" title="' + esc(c.name) + '">' + esc(c.name) + '</span><span class="cnt">' + (leaf ? (TAGN[c.name] || 0) : tagSum(c.key)) + '</span>' + galLink(leaf && TAGID[c.name] && TAGN[c.name] ? 'index.php?/tags/' + TAGID[c.name] : '', tx('le tag')) + '</div>' + (k2 && open ? rec(c, inHit || (f && self)) : '') + '</li>';
      }).join('') + '</ul>';
      const tree = rec(tagTree(), false);
      h = (f && !found ? tx('<div class="empty">Aucun tag ne correspond.</div>') : tree) + tx('<div class="hint"><span class="leafmark" style="display:inline-block"></span> = tag connu de Piwigo (dernier niveau). Les niveaux grisés n’existent que dans les fichiers. Cliquez pour filtrer les photos, glissez des photos sur un tag pour l’affecter ; ↗ (au survol) ouvre le tag dans la galerie. « (sans chemin) » regroupe les tags dont le chemin n’a pas été retrouvé ; « (sans photo) », les tags attachés à aucune photo (leur groupe est demandé au premier ajout).</div>');
    }
    el.innerHTML = h;
  }

  function renderToolbar() {
    let title = '', lastName = '', isLeaf = false, canDesc = false, smartNote = false, brokenNote = false;
    if (view.tab === 'albums') {
      title = view.id ? albPath(view.id).join(' › ') : '';
      smartNote = !!(view.id && albSub(view.id).some(id => albMap[id] && albMap[id].a.smart));
      brokenNote = !!(view.id && albSub(view.id).some(id => albMap[id] && albMap[id].a.broken));
      canDesc = !!(view.id && albMap[view.id] && albMap[view.id].a.ch.length);
    } else {
      title = (view.key || '').split('|').join(' › ');
      lastName = (view.key || '').split('|').pop();
      isLeaf = !!(tagPath[lastName] && tagPath[lastName].join('|') === view.key);
      canDesc = !isLeaf;
    }
    const shown = CUR.list.length;
    let h = '<div class="crumb">' + esc(title) + '<small>' + (CUR.loading && !shown ? tx('chargement…') : CUR.total + tx(' photos') + (CUR.total > PAGE ? ' · page ' + ((CUR.page || 0) + 1) + ' / ' + pageCount() : '') + ' · ' + sel.size + (sel.size > 1 ? tx(' sélectionnées') : tx(' sélectionnée'))) + (brokenNote ? ' · <span data-smart="' + view.id + '" role="button" tabindex="0" style="color:#c0392b;cursor:pointer" title="' + esc(BROKEN_TIP.norule + ' ' + SMART_CLICK) + tx('">⚠ SmartAlbum sans critère valable : contient presque toute la galerie</span>') : smartNote ? ' · <span data-smart="' + view.id + '" role="button" tabindex="0" style="cursor:pointer" title="' + esc(SMART_TIP + ' ' + SMART_CLICK) + tx('">⟳ SmartAlbums : le contenu suit les tags</span>') : '') + '</small></div>';
    if (canDesc) { h += '<label class="chk"><input type="checkbox" id="desc"' + (view.desc ? ' checked' : '') + tx('> Inclure les sous-niveaux</label>'); }
    h += '<button class="btn" id="selall">' + (CUR.total > PAGE ? tx('Sélectionner la page') : tx('Tout sélectionner')) + '</button><button class="btn" id="selnone"' + (sel.size ? '' : ' disabled') + tx('>Désélectionner</button>');
    if (isLeaf) { h += '<button class="btn danger" id="rmtag"' + (sel.size ? '' : ' disabled') + tx('>Retirer « ') + esc(lastName) + tx(' » de la sélection</button>'); }
    $('#toolbar').innerHTML = h;
  }

  function renderGrid() {
    const vis = visible();
    renderPager();
    if (!vis.length) {
      $('#grid').innerHTML = '<div class="empty" style="grid-column:1/-1">' + (CUR.loading ? tx('Chargement des photos…') : tx('Aucune photo ici.')) + '</div>';
      return;
    }
    // Par tag : un bandeau avant chaque album (le serveur renvoie les photos groupées par album)
    const byAlb = view.tab !== 'albums';
    const albN = {};
    if (byAlb) { vis.forEach(p => { albN[p.alb] = (albN[p.alb] || 0) + 1; }); }
    let lastAlb;
    let h = vis.map((p, k) => {
      let band = '';
      if (byAlb && (k === 0 || p.alb !== lastAlb)) {
        const path = p.alb && albMap[p.alb] ? albPath(p.alb).join(' › ') : tx('Photos orphelines');
        band = '<div class="albh" title="' + esc(path) + '"><span class="albh-n">' + esc(path) + '</span><span class="cnt">' + albN[p.alb] + tx(' photo') + (albN[p.alb] > 1 ? 's' : '') + (k === 0 && CUR.page > 0 ? tx(' · suite') : '') + '</span></div>';
      }
      lastAlb = p.alb;
      return band + tileHtml(p);
    }).join('');
    $('#grid').innerHTML = h;
  }

  function tileHtml(p) {
    {
      const q = pend[p.id];
      const chips = [];
      Array.from(p.tags).sort(ncmp).forEach(t => chips.push(q && q.rem.has(t) ? '<span class="chip rem" title="' + esc(t) + '">− ' + esc(t) + '</span>' : '<span class="chip n" title="' + esc(t) + '">' + esc(t) + '</span>'));
      if (q) { q.add.forEach(t => chips.push('<span class="chip" title="' + esc(t) + '">+ ' + esc(t) + '</span>')); }
      if (!chips.length) { chips.push(tx('<span class="chip" style="background:transparent;color:var(--muted);font-weight:400">aucun tag</span>')); }
      return '<div class="tile' + (sel.has(p.id) ? ' sel' : '') + '" tabindex="0" draggable="true" data-id="' + p.id + '">' +
        '<img class="ph" loading="lazy" draggable="false" alt="" src="' + esc(p.thumb) + '"><span class="tick">✓</span>' +
        '<div class="cap"><span class="fn" title="' + esc(p.file) + '">' + esc(p.file) + '</span><span class="chips">' + chips.join('') + '</span></div></div>';
    }
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
    $('#panel-h').innerHTML = tx('Tags de la sélection <span class="cnt">') + N + tx(' photo') + (N > 1 ? 's' : '') + '</span>';
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
    $('#rows').innerHTML = (N ? '' : tx('<div class="hint">Sélectionnez des photos au centre pour cocher leurs tags.</div>')) + (html || tx('<div class="empty">Aucun tag ne correspond.</div>'));
  }

  function renderBar() {
    const c = pendCount();
    $('#pendbar').innerHTML = c.ph ? '<div class="pendbar"><span>' + c.ph + tx(' photo') + (c.ph > 1 ? 's' : '') + tx(' à modifier · ') + c.ch + tx(' changement') + (c.ch > 1 ? 's' : '') + tx(' dans la file</span><button class="btn" id="cancel">Annuler tout</button><button class="btn pend" id="preview">Aperçu et appliquer</button></div>') : '';
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
      const path = tagPath[t] || [tx('(sans chemin)'), t], o = m[t];
      return '<div class="op"><b>' + esc(t) + '</b>' +
        '<div>' + (o.add.length ? '<span class="plus">+ ' + o.add.length + '</span> ' : '') + (o.rem.length ? '<span class="minus">− ' + o.rem.length + '</span> ' : '') + '<span style="color:var(--muted)">photo' + (o.add.length + o.rem.length > 1 ? 's' : '') + '</span></div>' +
        '<div class="pl">dc:subject <i>' + esc(t) + '</i><br>' + (FLAT[t] ? tx('<i>tag sans chemin : seul le retrait est possible</i>') : 'lr:hierarchicalSubject <i>' + esc(path.join('|')) + '</i>') + '</div></div>';
    }).join('');
    const warn = flatAdds.length ? tx('<div class="off-note" style="margin:0;border-color:var(--danger);color:var(--danger)">Impossible d’ajouter ') + flatAdds.map(t => tx('« ') + esc(t) + tx(' »')).join(', ') + tx(' : pas de chemin connu. Rangez d’abord ces tags (vue À réconcilier).</div>') : '';
    const label = tx('Écrire dans ') + c.ph + tx(' fichier') + (c.ph > 1 ? 's' : '');
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + label + tx(' ?</h2><div class="body" id="dbody">') +
      tx('<div style="color:var(--muted)">Les tags sont écrits dans les métadonnées XMP et IPTC de chaque fichier, sans toucher à l’image, puis les tags Piwigo des photos sont mis à jour.</div>') + warn + body +
      '<label class="chk"><input type="checkbox" id="orig"' + (S.original ? ' checked' : '') + tx('> Créer une copie <span class="mono">.original</span> si elle n’existe pas</label>') +
      '<div class="prog" id="prog" hidden><div></div></div><div id="pmsg" style="color:var(--muted)" hidden></div></div>' +
      tx('<div class="foot"><button class="btn" id="dcancel">Annuler</button><button class="btn pend" id="dgo"') + (flatAdds.length ? ' disabled' : '') + '>' + label + '</button></div></div></div>';
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
      if (c) { c.disabled = false; c.textContent = tx('Fermer'); c.focus(); }
      if (retry && c) {
        const b = document.createElement('button');
        b.className = 'btn pend'; b.id = 'jretry'; b.textContent = tx('Réessayer les erreurs');
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
      if (!r.ok) { throw new Error(r.error || tx('erreur')); }
      const c = r.counts, fin = c.done + c.error + c.skipped;
      const bar = $('#prog > div');
      if (bar) { bar.style.width = (total ? 100 * fin / total : 100) + '%'; }
      const pm = $('#pmsg');
      if (pm) { pm.hidden = false; pm.textContent = fin + ' / ' + total + tx(' fichiers') + (c.error ? ' · ' + c.error + tx(' erreur') + (c.error > 1 ? 's' : '') : ''); }
      if (r.done) { break; }
    }
    const c = r.counts;
    const isStruct = r.kind === 'struct';
    let html;
    if (r.status === 'partial') {
      html = '<b style="color:var(--danger)">' + c.error + tx(' fichier') + (c.error > 1 ? 's' : '') + tx(' en erreur : les tags Piwigo n’ont pas été modifiés.</b>') +
        tx('<div style="color:var(--muted)">Les ') + c.done + tx(' autres fichiers ont déjà été réécrits. Corrigez la cause (droits, fichier absent…) puis réessayez : l’opération reprend là où elle s’est arrêtée.</div>');
    } else {
      html = '<b style="color:var(--ok)">' + c.done + tx(' fichier') + (c.done > 1 ? 's' : '') + tx(' mis à jour') + (isStruct ? tx(', tags Piwigo à jour.') : '.') + '</b>';
    }
    if (c.error) {
      const e = await api('job_errors', { job: jobId });
      html += '<div style="color:var(--danger);margin-top:6px">' + (r.status === 'partial' ? tx('Fichiers en erreur (laissés intacts) :') : c.error + tx(' en erreur (fichier laissé intact) :')) + '</div><ul style="margin:4px 0 0 18px">' +
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
      jobMessage(tx('Erreur : ') + esc(e.message) + tx('. Le lot reste enregistré : il peut être repris depuis la bannière.'), true);
      checkActive();
    }
  }

  function openProgressDialog(title) {
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body" id="dbody">' +
      '<div class="prog" id="prog"><div></div></div><div id="pmsg" style="color:var(--muted)">…</div></div>' +
      tx('<div class="foot"><button class="btn" id="dcancel" disabled>Fermer</button></div></div></div>');
  }

  async function resumeJob(id, total, retry) {
    openProgressDialog((retry ? tx('Nouvel essai du lot n° ') : tx('Reprise du lot n° ')) + id);
    setBusy(true);
    try {
      if (retry) { await api('job_retry', { job: id }); }
      await driveJob(id, total, false);
    } catch (e) { jobMessage(tx('Erreur : ') + esc(e.message), true); }
  }

  async function cancelJob(id) {
    try { await api('job_cancel', { job: id }); toast(tx('Lot abandonné')); } catch (e) { toast(tx('Annulation impossible : ') + e.message); }
    checkActive(); refreshData();
  }

  async function checkActive() {
    try {
      const r = await api('job_active');
      const j = r.job, el = $('#jobbar');
      if (!j) { el.innerHTML = ''; return; }
      const partial = j.status === 'partial';
      el.innerHTML = tx('<div class="pendbar" style="border:1px solid var(--pend);border-radius:6px"><span>Lot n° ') + j.id + (partial ? tx(' terminé avec ') + j.errors + tx(' erreur') + (j.errors > 1 ? 's' : '') + tx(' : tags Piwigo non modifiés') : tx(' non terminé')) + ' (' + esc(j.username) + ') : ' + j.done + ' / ' + j.total + tx(' fichiers · ') + esc(j.summary) + '</span>' +
        (partial ? '<button class="btn pend" data-jretry="' + j.id + '" data-jtotal="' + j.total + tx('">Réessayer les erreurs</button>') : '<button class="btn pend" data-jresume="' + j.id + '" data-jtotal="' + j.total + tx('">Reprendre</button>')) +
        '<button class="btn" data-jcancel="' + j.id + tx('">Abandonner le lot</button></div>');
    } catch (e) { /* facultatif */ }
  }

  async function loadLog() {
    try {
      const r = await api('job_log');
      const ST = { done: tx('terminé'), running: tx('en cours'), pending: tx('en attente'), cancelled: tx('annulé'), partial: tx('partiel') };
      const head = tx('<tr><th>N°</th><th>Date</th><th>Utilisateur</th><th>Opération</th><th>Fichiers</th><th>Erreurs</th><th>État</th></tr>');
      const rows = r.rows.map(j => '<tr><td>' + j.id + '</td><td>' + esc(j.date) + '</td><td>' + esc(j.username) + '</td><td style="white-space:normal;min-width:200px">' + esc(j.summary) + '</td><td>' + j.done + ' / ' + j.total + '</td><td>' + (parseInt(j.errors, 10) ? '<button class="btn" data-jerr="' + j.id + '">' + j.errors + '</button>' : '0') + '</td><td>' + (ST[j.status] || esc(j.status)) + '</td></tr>').join('');
      $('#joblog').innerHTML = head + (rows || tx('<tr><td colspan="7" style="text-align:left;color:var(--muted)">Aucune écriture pour l’instant.</td></tr>'));
    } catch (e) { /* facultatif */ }
  }

  async function showJobErrors(id) {
    try {
      const e = await api('job_errors', { job: id });
      $('#layer').innerHTML = tx('<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>Erreurs du lot n° ') + id + '</h2><div class="body"><ul style="margin:0 0 0 18px">' +
        e.rows.map(x => '<li><span class="mono">' + esc(x.file) + '</span> : ' + esc(x.message) + '</li>').join('') + tx('</ul></div><div class="foot"><button class="btn" id="dcancel">Fermer</button></div></div></div>');
    } catch (err) { toast(tx('Lecture impossible : ') + err.message); }
  }

  // ---------- opérations de structure : dialogue de saisie, aperçu serveur, exécution ----------
  const LEVEL = () => (S.level === undefined ? 3 : S.level);
  const nameBad = n => { n = (n || '').trim(); if (!n) { return tx('Saisissez un nom.'); } if (/[|>/\\]/.test(n)) { return tx('Les caractères | > / \\ sont interdits dans un nom.'); } return null; };
  const groupKeys = () => { const out = []; (function w(n) { kids(n).forEach(c => { if (!isLeafNode(c) && c.name.charAt(0) !== '(') { out.push(c.key); w(c); } }); })(tagTree()); return out; };
  const validTags = () => Object.keys(tagPath).filter(k => !FLAT[k]).sort(ncmp);

  /** Boîte de saisie ; build() renvoie un tableau d'opérations ou un message d'erreur (texte). */
  function structDialog(title, bodyHtml, build, okLabel) {
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body" id="dbody">' + bodyHtml +
      tx('<div id="gerr" style="color:var(--danger)" hidden></div></div><div class="foot"><button class="btn" id="dcancel">Annuler</button><button class="btn primary" id="sgo">') + esc(okLabel || tx('Voir l’aperçu')) + '</button></div></div></div>';
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
    try { r = await api('struct_preview', { ops: ops }); } catch (e) { toast(tx('Aperçu impossible : ') + e.message); return; }
    if (!r.ok) {
      const err = r.error ? '<b style="color:var(--danger)">' + esc(r.error) + '</b>' : tx('<b style="color:var(--danger)">Opération refusée.</b>');
      $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body">' + err +
        '<ul style="margin:0 0 0 18px">' + (r.errors || []).map(x => '<li>' + esc(x) + '</li>').join('') + tx('</ul></div><div class="foot"><button class="btn" id="dcancel">Fermer</button></div></div></div>');
      return;
    }
    if (r.split) { splitPreview(title, r); return; }
    const label = r.files ? tx('Écrire dans ') + r.files + tx(' fichier') + (r.files > 1 ? 's' : '') : tx('Appliquer');
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body" id="dbody">' +
      r.lines.map(l => '<div class="op"><b>' + esc(l) + '</b></div>').join('') +
      (r.warnings && r.warnings.length ? tx('<div style="border:1px solid var(--pend);border-radius:6px;padding:8px 10px;margin:6px 0"><b>À savoir avant d’appliquer</b><ul style="margin:4px 0 0 18px">') + r.warnings.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul></div>' : '') +
      tx('<div>Touche <b>') + r.files + '</b> photo' + (r.files > 1 ? 's' : '') + ' (' + r.tags + ' tag' + (r.tags > 1 ? 's' : '') + '). ' + (r.files ? tx('Chaque fichier est relu, modifié puis réécrit, sans toucher à l’image.') : tx('Aucun fichier à réécrire.')) + '</div>' +
      tx('<div style="color:var(--muted)">Les tags Piwigo ne sont modifiés qu’une fois <b>tous</b> les fichiers écrits. SmartAlbums suit les fusions et les suppressions par identifiant.</div>') +
      (r.files ? '<label class="chk"><input type="checkbox" id="orig"' + (S.original ? ' checked' : '') + tx('> Créer une copie <span class="mono">.original</span> si elle n’existe pas</label>') : '') +
      '<div class="prog" id="prog" hidden><div></div></div><div id="pmsg" style="color:var(--muted)" hidden></div></div>' +
      tx('<div class="foot"><button class="btn" id="dcancel">Annuler</button><button class="btn pend" id="sapply">') + esc(label) + '</button></div></div></div>';
    $('#dcancel').focus();
  }

  /** Aperçu de « Séparer » : les deux usages du nom, lus dans les fichiers, et ce qui sera fait. */
  function splitPreview(title, r) {
    const p = r.split, pl = n => n + tx(' photo') + (n > 1 ? 's' : '');
    const nb = p.normal + p.both, nPers = p.person + p.both, all = p.person + p.normal + p.both + p.none;
    const gp = esc(p.new_path.split('|').slice(0, -1).join(' › '));
    const row = (u, path, n, after) => '<tr><td><b>' + u + '</b><div class="why">' + esc((path || '').split('|').join(' › ')) + '</div></td><td class="n">' + n + '</td><td>' + after + '</td></tr>';
    const items = [tx('création du tag « ') + esc(p.name) + tx(' »') + (gp ? tx(' dans ') + gp : '') + ' ;',
      '<b>' + (r.files > 1 ? r.files + tx(' fichiers réécrits') : tx('1 fichier réécrit')) + tx('</b> : seul le tag ordinaire change de nom, le cadre de visage n’est pas touché ;'),
      tx('dans Piwigo, ') + (nb > 1 ? tx('ces photos passent') : tx('cette photo passe')) + tx(' de « ') + esc(p.tag) + tx(' » à « ') + esc(p.name) + tx(' » ;'),
      tx('« ') + esc(p.tag) + tx(' » ne reste que sur ') + (nPers > 1 ? tx('les photos de la personne') : tx('la photo de la personne')) + '.'];
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body" id="dbody">' +
      '<p style="margin:0">' + (all > 1 ? tx('Les ') + all + tx(' photos du tag ont été lues') : tx('La photo du tag a été lue')) + tx('. On y trouve deux usages du même nom :</p>') +
      tx('<table class="rc"><tr><th>Usage</th><th>Photos</th><th>Après séparation</th></tr>') +
      row(tx('Personne'), p.person_path, nPers, tx('inchangé : reste « ') + esc(p.tag) + tx(' »')) +
      row(tx('Tag ordinaire'), p.normal_path, nb, tx('<span class="chip n">devient</span> ') + esc(p.new_path.split('|').join(' › '))) + '</table>' +
      tx('<div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:8px 10px"><b>Ce qui sera fait</b><ul style="margin:4px 0 0 18px">') + items.map(x => '<li>' + x + '</li>').join('') + '</ul></div>' +
      (p.both ? '<div class="why">' + (p.both > 1 ? p.both + tx(' photos portent') : tx('1 photo porte')) + tx(' la personne et le tag ordinaire : ') + (p.both > 1 ? tx('elles gardent') : tx('elle garde')) + tx(' les deux tags.</div>') : '') +
      (p.none ? '<div class="why">' + pl(p.none) + tx(' sans mot-clé « ') + esc(p.tag) + tx(' » dans le fichier : ') + (p.none > 1 ? tx('elles restent') : tx('elle reste')) + tx(' sur « ') + esc(p.tag) + tx(' ».</div>') : '') +
      '<label class="chk"><input type="checkbox" id="orig"' + (S.original ? ' checked' : '') + tx('> Créer une copie <span class="mono">.original</span> si elle n’existe pas</label>') +
      '<div class="prog" id="prog" hidden><div></div></div><div id="pmsg" style="color:var(--muted)" hidden></div></div>' +
      tx('<div class="foot"><button class="btn" id="dcancel">Annuler</button><button class="btn pend" id="sapply">Séparer (') + pl(r.files) + ')</button></div></div></div>';
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
      jobMessage(tx('Erreur : ') + esc(e.message) + tx('. Le lot reste enregistré : il peut être repris depuis la bannière.'), true);
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
      toast(kind === 'group' ? tx('Groupe créé') : tx('Tag créé'));
      refreshData();
    } catch (e) { toast(tx('Création impossible : ') + e.message); }
  }

  async function deleteEmptyGroup(path) {
    try {
      const r = await api('group_delete', { path: path });
      if (!r.ok) { const g = $('#gerr'); if (g) { g.hidden = false; g.textContent = r.error; } return; }
      $('#layer').innerHTML = '';
      structFocus = path.split('|').slice(0, -1).join('|');
      toast(tx('Groupe supprimé'));
      refreshData();
    } catch (e) { toast(tx('Suppression impossible : ') + e.message); }
  }

  function simpleDialog(title, bodyHtml, okLabel, onOk, danger) {
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body" id="dbody">' + bodyHtml +
      tx('<div id="gerr" style="color:var(--danger)" hidden></div></div><div class="foot"><button class="btn" id="dcancel">Annuler</button><button class="btn ') + (danger ? 'danger' : 'primary') + '" id="sgo">' + esc(okLabel) + '</button></div></div></div>';
    $('#sgo').onclick = onOk;
    const i = $('#gin');
    if (i) { i.focus(); i.addEventListener('keydown', e => { if (e.key === 'Enter') { onOk(); } }); } else { $('#dcancel').focus(); }
  }

  /**
   * Icône ⟳ / ⚠ d'un SmartAlbum : aperçu calculé par SmartAlbums (sans écriture), puis mise à jour de l'album
   * sur confirmation. Aucun fichier n'est touché : seules les photos rangées par SmartAlbums changent.
   */
  async function smartDialog(id) {
    const a = albMap[id] && albMap[id].a;
    if (!a) { return; }
    const link = PUBLIC ? '' : '<p style="margin:0"><a href="' + esc(GALLERY + 'admin.php?page=plugin-SmartAlbums-album&cat_id=' + id) + '" target="_blank" rel="noopener">' + tx('Ouvrir ses critères dans SmartAlbums ↗') + '</a></p>';
    if (a.broken === 'norule') {
      $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(a.name) + '</h2><div class="body"><p style="margin:0">' + esc(BROKEN_TIP.norule) + '</p>' + link + '</div>' +
        '<div class="foot"><button class="btn primary" id="dcancel">' + tx('Fermer') + '</button></div></div></div>';
      $('#dcancel').focus();
      return;
    }
    const title = tx('Mettre à jour le SmartAlbum « ') + a.name + tx(' » ?');
    const shell = (body, foot) => '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body" id="dbody">' + body + '</div><div class="foot">' + foot + '</div></div></div>';
    $('#layer').innerHTML = shell('<p style="margin:0;color:var(--muted)">' + tx('SmartAlbums calcule le nouveau contenu…') + '</p>', '<button class="btn" id="dcancel">' + tx('Annuler') + '</button>');
    let r;
    try { r = await api('smart_preview', { album: id }); } catch (e) { r = { ok: false, error: e.message }; }
    if (!$('#dbody')) { return; } // fenêtre fermée entre-temps
    if (!r.ok) {
      $('#layer').innerHTML = shell('<p style="margin:0;color:var(--danger)">' + esc(r.error) + '</p>' + link, '<button class="btn primary" id="dcancel">' + tx('Fermer') + '</button>');
      return;
    }
    const pl = n => n + (n > 1 ? tx(' photos') : tx(' photo'));
    const same = !r.added && !r.removed;
    $('#layer').innerHTML = shell(
      '<p style="margin:0">' + tx('SmartAlbums recalcule le contenu de l’album d’après ses critères (tags, albums, dates…). Aucun fichier n’est modifié.') + '</p>' +
      '<div class="op"><b>' + (same ? tx('Déjà à jour : ') + pl(r.before) : pl(r.before) + ' → ' + pl(r.after)) + '</b>' +
      (same ? '' : '<div><span class="plus">+ ' + r.added + '</span> <span class="minus">− ' + r.removed + '</span></div>') + '</div>' +
      (a.broken === 'flood' ? '<p style="margin:0;color:#c0392b">' + esc(BROKEN_TIP.flood) + '</p>' : '') +
      (r.manual ? '<p class="why" style="margin:0">' + pl(r.manual) + (r.manual > 1 ? tx(' rangées à la main dans cet album ne bougent pas.') : tx(' rangée à la main dans cet album ne bouge pas.')) + '</p>' : '') + link,
      '<button class="btn" id="dcancel">' + tx('Annuler') + '</button><button class="btn pend" id="smart-go">' + (same ? tx('Mettre à jour quand même') : tx('Mettre à jour')) + '</button>');
    $('#smart-go').onclick = async () => {
      const b = $('#smart-go');
      b.disabled = true; b.textContent = tx('Mise à jour…');
      try {
        const u = await api('smart_refresh', { album: id });
        if (!u.ok) { throw new Error(u.error); }
        $('#layer').innerHTML = '';
        toast(tx('« ') + a.name + tx(' » mis à jour : ') + pl(u.after) + ' (+' + u.added + ' / −' + u.removed + ')');
        buildAlbums((await api('albums')).albums);
        refreshData();
      } catch (e) { b.disabled = false; b.textContent = tx('Mettre à jour'); toast(tx('Échec : ') + e.message); }
    };
    $('#smart-go').focus();
  }

  /** Message qui reste affiché jusqu'au clic sur « Fermer » (refus à lire en entier). */
  function infoDialog(title, message) {
    $('#layer').innerHTML = '<div class="veil" role="dialog" aria-modal="true"><div class="dlg"><h2>' + esc(title) + '</h2><div class="body"><p>' + esc(message) + '</p></div>' +
      '<div class="foot"><button class="btn primary" id="dclose">Fermer</button></div></div></div>';
    const b = $('#dclose');
    b.onclick = () => { $('#layer').innerHTML = ''; };
    b.focus();
  }

  /**
   * Premier ajout d'un tag « (sans photo) » : il n'a pas de chemin, on demande son groupe. Le rangement est
   * immédiat (index seulement, aucun fichier ne porte ce tag), puis l'ajout entre dans la file avec le chemin complet.
   */
  function askNoPhotoGroup(tag, ids) {
    if (LEVEL() < 2) { infoDialog(tx('Tag sans groupe'), tx('« ') + tag + tx(' » n’est rangé dans aucun groupe : il faut le droit « Gérer : créer » pour lui en donner un avant de l’affecter.')); return; }
    const dl = '<datalist id="dl-g">' + groupKeys().map(k => '<option value="' + esc(k) + '">').join('') + '</datalist>';
    simpleDialog(tx('Ranger « ') + tag + tx(' » avant de l’affecter'),
      tx('<div>Ce tag n’est attaché à aucune photo : il n’a pas encore de place dans l’arbre. Choisissez son groupe d’accueil (chemin complet, par exemple <span class="mono">ALBUM|2024 SLOVENIE</span>) :</div>') +
      tx('<input id="gin" class="fin" type="text" autocomplete="off" value="" placeholder="Tapez une partie du nom ou choisissez dans la liste" list="dl-g">') + dl +
      tx('<div style="color:var(--muted)">Le rangement est immédiat (aucun fichier touché) ; « ') + esc(tag) + tx(' » est ensuite ajouté à la file pour ') + ids.length + tx(' photo') + (ids.length > 1 ? 's' : '') + '.</div>',
      tx('Ranger et ajouter'), async () => {
        const v = $('#gin').value.trim(), g = $('#gerr');
        if (!v) { g.hidden = false; g.textContent = tx('Choisissez un groupe d’accueil.'); return; }
        try {
          const r = await api('tag_place_nophoto', { tag: tag, parent: v });
          if (!r.ok) { g.hidden = false; g.textContent = r.error; return; }
          $('#layer').innerHTML = '';
          buildTags(await api('tags'));
          setTag(ids, tag, true);
          renderAll();
          toast(tx('« ') + tag + tx(' » rangé dans ') + human(v) + tx(' et ajouté à la file pour ') + ids.length + tx(' photo') + (ids.length > 1 ? 's' : ''));
        } catch (e) { g.hidden = false; g.textContent = tx('Échec : ') + e.message; }
      });
  }

  /** Création d'un tag depuis l'écran Étiqueter : créé tout de suite, ajouté en file aux photos sélectionnées. */
  async function createFromPanel() {
    const name = $('#nt-name').value.trim(), group = $('#nt-group').value.trim();
    const bad = nameBad(name);
    if (bad) { toast(bad); return; }
    if (!group) { toast(tx('Choisissez le groupe d’accueil du tag.')); return; }
    if (LEVEL() < 2) { toast(tx('Droits insuffisants.')); return; }
    try {
      const r = await api('tag_create', { parent: group, name: name });
      if (!r.ok) { infoDialog(tx('Création impossible'), r.error); return; }
      buildTags(await api('tags'));
      if (sel.size) { setTag(Array.from(sel), name, true); }
      $('#nt-name').value = '';
      renderAll();
      toast(sel.size ? tx('« ') + name + tx(' » créé et ajouté à la file pour ') + sel.size + tx(' photo') + (sel.size > 1 ? 's' : '') : tx('« ') + name + tx(' » créé (aucune photo sélectionnée)'));
    } catch (e) { toast(tx('Création impossible : ') + e.message); }
  }

  /** « Créer un tag » de l'écran Gérer les tags : le tag est créé sans photo, puis affiché dans l'arbre. */
  // Le groupe choisi dans l'arbre n'est PAS écrit dans le champ : un champ pré-rempli sert de filtre à la liste
  // du navigateur, qui ne proposait alors que ce groupe. Il est affiché en texte indicatif et pris si le champ est vide.
  const MT_ROOT = tx('(racine)');
  let mtDefault = '';
  const mtGroup = id => { const v = $('#' + id).value.trim(); return v === MT_ROOT ? '' : (v || mtDefault); };
  function mtSyncGroup() {
    mtDefault = (!mkey || mkey.charAt(0) === '(') ? '' : (isLeafKey(mkey) ? mkey.split('|').slice(0, -1).join('|') : mkey);
    const t = $('#mt-group'), g = $('#mg-group');
    if (t) { t.placeholder = mtDefault ? tx('Dans ') + mtDefault + tx(' (ou choisissez un autre groupe)') : tx('Groupe, par exemple ALBUM|2024 SLOVENIE'); }
    if (g) { g.placeholder = mtDefault ? tx('Dans ') + mtDefault + tx(' ; ') + MT_ROOT + tx(' = premier niveau') : tx('Groupe parent (vide : premier niveau)'); }
  }
  async function createGroupFromMng() {
    const name = $('#mg-name').value.trim(), parent = mtGroup('mg-group');
    const bad = nameBad(name);
    if (bad) { toast(bad); return; }
    if (LEVEL() < 2) { toast(tx('Droits insuffisants.')); return; }
    try {
      const r = await api('group_create', { parent: parent, name: name });
      if (!r.ok) { infoDialog(tx('Création impossible'), r.error); return; }
      $('#mg-name').value = ''; $('#mg-group').value = '';
      structFocus = r.path;
      toast(tx('Groupe « ') + r.path.split('|').join(' › ') + tx(' » créé'));
      refreshData();
    } catch (e) { toast(tx('Création impossible : ') + e.message); }
  }
  async function createFromMng() {
    const name = $('#mt-name').value.trim(), group = mtGroup('mt-group');
    const bad = nameBad(name);
    if (bad) { toast(bad); return; }
    if (!group) { toast(tx('Choisissez le groupe d’accueil du tag.')); return; }
    if (LEVEL() < 2) { toast(tx('Droits insuffisants.')); return; }
    try {
      const r = await api('tag_create', { parent: group, name: name });
      if (!r.ok) { infoDialog(tx('Création impossible'), r.error); return; }
      $('#mt-name').value = ''; $('#mt-group').value = '';
      structFocus = r.path;
      toast(tx('« ') + name + tx(' » créé dans ') + group.split('|').join(' › '));
      refreshData();
    } catch (e) { toast(tx('Création impossible : ') + e.message); }
  }

  /** Action sur l'élément sélectionné dans l'arbre de « Gérer les tags ». */
  function runStructOp(op, key) {
    const leaf = isLeafKey(key), name = key.split('|').pop(), parent = key.split('|').slice(0, -1).join('|');
    const dl = '<datalist id="dl-g">' + groupKeys().map(k => '<option value="' + esc(k) + '">').join('') + '</datalist>';
    const inp = (id, val, ph, list) => '<input id="' + id + '" class="fin" type="text" autocomplete="off" value="' + esc(val || '') + '" placeholder="' + esc(ph || '') + '"' + (list ? ' list="' + list + '"' : '') + '>';

    if (op === 'newgroup' || op === 'newtag') {
      const par = leaf ? parent : key;
      simpleDialog(op === 'newgroup' ? tx('Nouveau groupe') : tx('Nouveau tag de dernier niveau'),
        tx('<div style="color:var(--muted)">Dans : <b style="color:var(--fg)">') + esc(par ? human(par) : tx('(racine)')) + '</b></div>' + inp('gin', '', tx('Nom')) +
        (op === 'newtag' ? tx('<div style="color:var(--muted)">Le tag est créé dans Piwigo sans photo ; il se remplit ensuite avec l’écran Étiqueter.</div>') : ''),
        tx('Créer'), () => { const v = $('#gin').value.trim(), b = nameBad(v); if (b) { const g = $('#gerr'); g.hidden = false; g.textContent = b; return; } createNode(op === 'newgroup' ? 'group' : 'tag', par, v); });
      return;
    }
    if (op === 'delete_group') {
      simpleDialog(tx('Supprimer le groupe « ') + name + tx(' » ?'), tx('<div>Ce groupe ne contient aucun tag. Il est retiré de l’index (aucun fichier n’est touché).</div>'), tx('Supprimer'), () => deleteEmptyGroup(key), true);
      return;
    }
    if (op === 'rename') {
      structDialog(tx('Renommer le tag'), tx('<div>Nouveau nom pour <b>') + esc(name) + '</b></div>' + inp('gin', name),
        () => { const v = $('#gin').value.trim(), b = nameBad(v); if (b) { return b; } structFocus = (FLAT[name] ? tagPath[name][0] : parent) + (FLAT[name] || parent ? '|' : '') + v; return [{ type: 'rename', tag: name, name: v }]; });
    } else if (op === 'place') {
      structDialog(FLAT[name] ? tx('Ranger « ') + name + tx(' » dans un groupe') : tx('Déplacer « ') + name + tx(' »'),
        (FLAT[name] ? '' : tx('<div style="color:var(--muted)">Actuellement : <b style="color:var(--fg)">') + esc(human(parent)) + '</b></div>') +
        tx('<div>Groupe d’accueil (chemin complet, par exemple <span class="mono">ALBUM|2024 SLOVENIE</span>) :</div>') + inp('gin', '', tx('Tapez une partie du nom ou choisissez dans la liste'), 'dl-g') + dl,
        () => { const v = $('#gin').value.trim(); if (!v) { return tx('Choisissez un groupe d’accueil.'); } if (!FLAT[name] && tagmanagerSame(v, parent)) { return tx('Le tag est déjà dans ce groupe.'); } structFocus = v + '|' + name; return [{ type: 'place', tag: name, group: v }]; });
    } else if (op === 'merge') {
      structDialog(tx('Fusionner « ') + name + tx(' »'),
        tx('<div>Fusionner dans le tag :</div>') + inp('gin', '', tx('Nom d’un tag existant'), 'dl-t') + '<datalist id="dl-t">' + validTags().filter(t => t !== name).map(t => '<option value="' + esc(t) + '">').join('') + '</datalist>' +
        tx('<div style="color:var(--muted)">Les photos de « ') + esc(name) + tx(' » recevront le tag choisi, puis « ') + esc(name) + tx(' » sera supprimé.</div>'),
        () => { const v = $('#gin').value.trim(); if (!v) { return tx('Choisissez le tag de destination.'); } structFocus = tagPath[v] ? tagPath[v].join('|') : null; return [{ type: 'merge', tag: name, to: v }]; });
    } else if (op === 'delete') {
      structDialog(tx('Supprimer le tag « ') + name + tx(' » ?'),
        tx('<div>Le tag est retiré de toutes ses photos (fichiers réécrits) puis supprimé de Piwigo.</div><div class="op" style="border-color:var(--danger)"><b style="color:var(--danger)">Attention</b><div style="color:var(--muted)">SmartAlbums retire ce tag de ses filtres (suivi par identifiant) : un album qui n’avait que ce tag devient vide. Les sauvegardes <span class="mono">.original</span> ne sont pas touchées.</div></div>'),
        () => { structFocus = parent; return [{ type: 'delete', tag: name }]; }, tx('Voir l’aperçu'));
    } else if (op === 'rename_group') {
      structDialog(tx('Renommer le groupe'), tx('<div>Nouveau nom pour <b>') + esc(name) + '</b></div>' + inp('gin', name),
        () => { const v = $('#gin').value.trim(), b = nameBad(v); if (b) { return b; } structFocus = (parent ? parent + '|' : '') + v; return [{ type: 'rename_group', group: key, name: v }]; });
    } else if (op === 'move_group') {
      const ROOT = tx('(racine)');
      const dlg = '<datalist id="dl-g">' + (parent ? '<option value="' + ROOT + '">' : '') + groupKeys().filter(k => k !== key && k.indexOf(key + '|') !== 0).map(k => '<option value="' + esc(k) + '">').join('') + '</datalist>';
      structDialog(tx('Déplacer le groupe « ') + name + tx(' »'),
        tx('<div style="color:var(--muted)">Actuellement dans : <b style="color:var(--fg)">') + esc(parent ? human(parent) : ROOT) + '</b></div>' +
        tx('<div>Nouveau parent (chemin complet, ou <span class="mono">') + ROOT + tx('</span>) :</div>') + inp('gin', '', tx('Tapez une partie du nom ou choisissez dans la liste'), 'dl-g') + dlg,
        () => { let v = $('#gin').value.trim(); if (!v) { return tx('Choisissez le nouveau parent.'); } if (v === ROOT) { v = ''; } if (tagmanagerSame(v, parent)) { return tx('Le groupe est déjà à cet endroit.'); } structFocus = (v ? v + '|' : '') + name; return [{ type: 'move_group', group: key, target: v }]; });
    }
  }

  // ---------- rendu : Gérer les tags ----------
  function renderMng() {
    mtSyncGroup();
    const f = mFilter.trim().toLowerCase();
    const rec = n => {
      let out = '';
      kids(n).forEach(c => {
        const leaf = isLeafNode(c);
        const s2 = leaf ? '' : rec(c);
        if (f && c.name.toLowerCase().indexOf(f) < 0 && !s2) { return; }
        const open = f ? true : !mCol.has(c.key);
        out += '<div class="node' + (leaf ? '' : ' group') + (mkey === c.key ? ' sel' : '') + '" tabindex="0" data-mt="' + esc(c.key) + '"><span class="caret" data-mcar="' + esc(c.key) + '">' + (s2 ? (open ? '▼' : '▶') : '') + '</span>' + (leaf ? tx('<span class="leafmark" title="Tag Piwigo"></span>') : '') + '<span class="nm" title="' + esc(c.name) + '">' + esc(c.name) + '</span><span class="cnt">' + (leaf ? (TAGN[c.name] || 0) : tagSum(c.key)) + '</span></div>' + (s2 && open ? '<div class="gkids">' + s2 + '</div>' : '');
      });
      return out;
    };
    $('#mtree').innerHTML = rec(tagTree()) || tx('<div class="empty">Aucun tag.</div>');
    renderFiche();
    const todo = REC.length;
    $('#rec-n').textContent = todo;
  }

  let ficheSeq = 0;
  function renderFiche() {
    const el = $('#mfiche');
    const synthetic = mkey === tx('(sans chemin)') || mkey === NOPHOTO_G;
    const exists = mkey && (Object.keys(tagPath).some(k => { const j = tagPath[k].join('|'); return j === mkey || j.indexOf(mkey + '|') === 0; }) || emptyGroups.has(mkey));
    if (!exists) { el.innerHTML = tx('<div class="empty">Sélectionnez un tag ou un groupe dans l’arbre.</div>'); return; }
    const leaf = isLeafKey(mkey), name = mkey.split('|').pop(), lv = leavesUnder(mkey);
    const flat = leaf && FLAT[name];
    const can = LEVEL() >= 3 ? '' : tx(' disabled title="Droits insuffisants"');
    const b = (op, label, cls, always) => '<button class="btn' + (cls ? ' ' + cls : '') + '" data-sop="' + op + '"' + (always && LEVEL() >= 2 ? '' : can) + '>' + label + '</button>';
    let actions = '';
    if (synthetic) { actions = '<span class="sub">' + (mkey === NOPHOTO_G ? tx('Regroupement d’affichage : tags créés dans Piwigo et attachés à aucune photo. Rangez-les dans un groupe, ou affectez-les directement dans l’écran Étiqueter (leur groupe est alors demandé), ou supprimez-les.') : tx('Regroupement d’affichage : ces tags n’ont pas de chemin dans les fichiers. Rangez-les dans un groupe, ou fusionnez-les.')) + '</span>'; }
    else if (leaf) { actions = b('rename', tx('Renommer')) + b('place', flat ? tx('Ranger dans un groupe…') : tx('Déplacer…')) + b('merge', tx('Fusionner avec…')) + b('delete', tx('Supprimer'), 'danger'); }
    else { actions = b('newgroup', tx('+ Sous-groupe'), '', true) + b('newtag', tx('+ Tag ici'), '', true) + b('rename_group', tx('Renommer')) + b('move_group', tx('Déplacer…')) + (lv.length ? '' : b('delete_group', tx('Supprimer'), 'danger')); }
    el.innerHTML = '<div class="fiche"><div class="fh"><h2>' + esc(name) + '</h2><span class="badge' + (leaf ? ' leaf' : '') + '">' + (leaf ? tx('Tag Piwigo') : tx('Groupe')) + '</span>' + (flat ? '<span class="chip">' + (NOPHOTO[name] ? tx('sans photo') : tx('sans chemin')) + '</span>' : '') + '</div>' +
      '<p class="sub">' + (leaf ? tx('Dernier niveau : c’est le tag que Piwigo connaît.') : tx('Niveau intermédiaire : il n’existe que dans les chemins des fichiers.')) + '</p>' +
      tx('<dl class="kv"><dt>Chemin</dt><dd class="mono">') + esc(flat ? (NOPHOTO[name] ? tx('(aucune photo : pas de chemin dans les fichiers)') : tx('(pas de chemin retrouvé dans les fichiers testés)')) : mkey) + '</dd><dt>Photos</dt><dd id="f-n">…</dd>' + (leaf ? '' : tx('<dt>Tags dessous</dt><dd>') + lv.length + '</dd>') +
      tx('<dt>Écrit dans</dt><dd class="mono">') + (leaf ? 'dc:subject, ' : '') + 'lr:hierarchicalSubject, digiKam:TagsList, MicrosoftPhoto, acdsee</dd></dl>' +
      '<div class="acts">' + actions + '</div>' +
      tx('<h3>Albums concernés</h3><ul class="alist" id="f-alb"><li><span style="color:var(--muted)">…</span></li></ul></div>');
    const seq = ++ficheSeq;
    api('tag_albums', { ids: lv.map(n => TAGID[n]).filter(Boolean).join(',') }).then(r => {
      if (seq !== ficheSeq) { return; }
      $('#f-n').textContent = r.total;
      $('#f-alb').innerHTML = r.albums.map(a => '<li><span>' + esc(a.alb && albMap[a.alb] ? albPath(a.alb).join(' › ') : tx('Photos orphelines')) + '</span><span class="cnt">' + a.n + '</span></li>').join('') || tx('<li><span style="color:var(--muted)">Aucune photo</span></li>');
    }).catch(() => { if (seq === ficheSeq) { $('#f-n').textContent = '?'; } });
  }

  // ---------- réconciliation ----------
  const RACT = { merge: tx('Fusionner vers…'), rename: tx('Renommer en…'), place: tx('Ranger dans un groupe…'), delete: tx('Supprimer le tag'), skip: tx('Laisser tel quel') };
  const recOp = r => {
    const t = (r.target || '').trim();
    if (r.act === 'merge') { return { type: 'merge', tag: r.name, to: t }; }
    if (r.act === 'rename') { return { type: 'rename', tag: r.name, name: t }; }
    if (r.act === 'place') { return { type: 'place', tag: r.name, group: t }; }
    if (r.act === 'delete') { return { type: 'delete', tag: r.name }; }
    return null;
  };
  function renderRec() {
    $('#rec-sum').innerHTML = '<b>' + REC.length + '</b> tag' + (REC.length > 1 ? 's' : '') + tx(' à réconcilier<small>d’après la dernière indexation</small>');
    const dlt = $('#dl-tags'), dlg2 = $('#dl-groups');
    if (dlt) { dlt.innerHTML = validTags().map(t => '<option value="' + esc(t) + '">').join(''); }
    if (dlg2) { dlg2.innerHTML = groupKeys().map(t => '<option value="' + esc(t) + '">').join(''); }
    const can = LEVEL() >= 3 ? '' : ' disabled';
    const head = tx('<tr><th>Tag dans Piwigo</th><th>Photos</th><th>Constat</th><th>Action</th><th>Cible</th><th></th></tr>');
    const rows = REC.map((r, i) => {
      if (r.status === 'split' || r.status === 'unface') { return recSpecialRow(r, i, can); }
      const badge = r.conf === 'haute' ? tx('<span class="chip n">suggestion fiable</span>') : tx('<span class="chip">à vérifier</span>');
      const opts = Object.keys(RACT).map(k => '<option value="' + k + '"' + (r.act === k ? ' selected' : '') + '>' + RACT[k] + '</option>').join('');
      const needs = r.act === 'merge' || r.act === 'rename' || r.act === 'place';
      const list = r.act === 'merge' ? ' list="dl-tags"' : r.act === 'place' ? ' list="dl-groups"' : '';
      const ph = r.act === 'merge' ? tx('Tag existant…') : r.act === 'rename' ? tx('Nouveau nom…') : tx('Groupe (ALBUM|…)');
      const tg = needs ? '<input type="text" class="rec-tg" data-ri="' + i + '" value="' + esc(r.target || '') + '" placeholder="' + ph + '"' + list + tx(' aria-label="Cible pour ') + esc(r.name) + '">' : '<span class="cnt">—</span>';
      const btn = r.act === 'skip' ? tx('<span class="cnt">ignoré</span>') : '<button class="btn pend" data-rgo="' + i + '"' + can + tx('>Appliquer</button>');
      return '<tr><td><div class="nm">' + esc(r.name) + '</div>' + (r.status === 'nofile' ? tx('<div class="why">fichier introuvable</div>') : '') + '</td><td class="n">' + r.n + '</td><td><div>' + badge + '</div><div class="why">' + esc(r.why) + '</div></td>' +
        '<td><select class="rec-act" data-ri="' + i + tx('" aria-label="Action pour ') + esc(r.name) + '">' + opts + '</select></td><td>' + tg + '</td><td>' + btn + '</td></tr>';
    }).join('');
    $('#rec-table').innerHTML = head + (rows || tx('<tr><td colspan="6" class="why">Aucun tag à réconcilier.</td></tr>'));
  }

  /** Ligne « personne et tag ordinaire » (Séparer) ou « marqué visage à tort » (Ce n'est pas un visage). */
  function recSpecialRow(r, i, can) {
    const cst = '<div style="margin-bottom:4px"><span class="chip">' + esc(r.chip || '') + '</span></div><div>' + esc(r.why) + '</div>' +
      (r.fix ? '<div class="why" style="margin-top:3px">' + esc(r.fix) + '</div>' : '');
    let act;
    if (r.status === 'split') {
      act = '<label for="rec-nn' + i + tx('" class="why" style="display:block;margin-bottom:3px">Nouveau nom du tag ordinaire</label>') +
        '<div style="display:flex;gap:6px;align-items:center"><input type="text" id="rec-nn' + i + '" class="rec-tg" data-ri="' + i + '" value="' + esc(r.target || '') + '" style="flex:1;min-width:0">' +
        '<button class="btn pend" data-rgo="' + i + '"' + can + tx('>Séparer…</button></div>');
    } else {
      act = '<div style="display:flex;justify-content:flex-end"><button class="btn pend" data-rgo="' + i + '"' + can + tx('>Ce n’est pas un visage…</button></div>');
    }
    return '<tr><td><div class="nm">' + esc(r.name) + '</div></td><td class="n">' + r.n + '</td><td>' + cst + '</td><td colspan="3">' + act + '</td></tr>';
  }

  /** « Ce n'est pas un visage » : le serveur relit les fichiers avant de retirer le tag de la liste des visages. */
  function recUnface(r) {
    const where = r.group ? tx('ses photos le rangent sous <b>') + esc(r.group) + tx('</b>, et ') : '';
    simpleDialog(tx('« ') + r.name + tx(' » n’est pas un visage ?'),
      tx('<p style="margin:0">« ') + esc(r.name) + tx(' » est marqué comme visage, donc caché comme une personne. Pourtant ') + where + tx('aucune photo lue n’a de cadre de visage à ce nom.</p>') +
      tx('<div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:8px 10px"><b>Ce qui sera fait</b><ul style="margin:4px 0 0 18px"><li>« ') + esc(r.name) + tx(' » est retiré de la liste des visages ;</li><li>aucun fichier ni aucune photo n’est modifié.</li></ul></div>') +
      tx('<p class="why" style="margin:0">Les fichiers de ses photos sont relus avant. Si un cadre de visage « ') + esc(r.name) + tx(' » est dessiné plus tard, il sera de nouveau marqué comme visage.</p>'),
      tx('Retirer des visages'), async () => {
        const g = $('#gerr'), b = $('#sgo');
        b.disabled = true; b.textContent = tx('Lecture des fichiers…');
        try {
          const res = await api('face_unflag', { tag: r.id });
          if (!res.ok) {
            g.hidden = false; g.textContent = res.error; b.textContent = tx('Retirer des visages');
            if (res.homonym) { api('recon').then(x => { REC = x.rows; renderMng(); renderRec(); }).catch(() => {}); } else { b.disabled = false; }
            return;
          }
          $('#layer').innerHTML = '';
          toast(tx('« ') + res.name + tx(' » n’est plus marqué comme visage'));
          refreshData();
        } catch (e) { g.hidden = false; g.textContent = tx('Échec : ') + e.message; b.disabled = false; b.textContent = tx('Retirer des visages'); }
      });
  }

  function recApply(i) {
    const r = REC[i];
    if (r.act === 'unface') { recUnface(r); return; }
    if (r.act === 'split') {
      structFocus = null;
      structPreview(tx('Séparer « ') + r.name + tx(' » ?'), [{ type: 'split', tag: r.name, name: (r.target || '').trim() }]);
      return;
    }
    const op = recOp(r);
    if (!op) { return; }
    structFocus = null;
    structPreview(tx('Réconciliation : ') + r.name, [op]);
  }
  function recBulk() {
    const ops = [];
    REC.forEach(r => { if (r.conf === 'haute' && (r.act === 'merge' || r.act === 'rename') && (r.target || '').trim()) { ops.push(recOp(r)); } });
    if (!ops.length) { toast(tx('Aucune suggestion fiable à appliquer.')); return; }
    structFocus = null;
    structPreview(tx('Appliquer ') + ops.length + tx(' suggestions fiables'), ops);
  }

  // ---------- rendu : Paramètres ----------
  const LVL = [tx('Étiqueter'), tx('Gérer : créer'), tx('Gérer : tout')];
  function renderSet() {
    $('#set-orig').checked = !!S.original;
    $('#set-thumb').value = S.thumb || 'large';
    if (document.activeElement !== $('#set-tw')) { $('#set-tw').value = S.tw || 300; }
    if (document.activeElement !== $('#set-reserved')) { $('#set-reserved').value = (S.reserved || []).join(', '); }
    const head = tx('<tr><th>Qui</th><th>Type</th>') + LVL.map(l => '<th>' + l + '</th>').join('') + '<th></th></tr>';
    const locked = [tx('Webmestres')].map(w => '<tr><td><b>' + w + tx('</b></td><td>Rôle Piwigo</td><td><input type="checkbox" checked disabled></td><td><input type="checkbox" checked disabled></td><td><input type="checkbox" checked disabled></td><td><span class="cnt">toujours</span></td></tr>')).join('');
    const rows = S.rights.map((r, i) => '<tr><td><b>' + esc(r.who) + '</b></td><td>' + esc(tx(r.type)) + '</td>' + r.p.map((v, j) => '<td><input type="checkbox" class="rcb" data-ri="' + i + '" data-pi="' + j + '"' + (v ? ' checked' : '') + ' aria-label="' + esc(r.who + ' : ' + LVL[j]) + '"></td>').join('') + '<td><button class="btn" data-rdel="' + i + tx('" aria-label="Retirer ') + esc(r.who) + tx(' de la table">Retirer</button></td></tr>')).join('');
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
    const gopts = groupKeys().map(k => '<option value="' + esc(k) + '">').join('');
    if (ntg) { ntg.innerHTML = gopts; }
    const mgg = $('#dl-mgg');
    if (mgg) { mgg.innerHTML = '<option value="' + MT_ROOT + '">' + gopts; }
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
    if (g('a.gal')) { return; } // lien vers la galerie : le navigateur l'ouvre, la sélection ne change pas
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
    if ((x = g('[data-smart]'))) { smartDialog(parseInt(x.getAttribute('data-smart'), 10)); return; }
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
    if (t.id === 'cancel') { Object.keys(pend).forEach(k => delete pend[k]); renderAll(); toast(tx('Modifications annulées')); return; }
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
    if (t.id === 'mt-go') { createFromMng(); return; }
    if (t.id === 'mg-go') { createGroupFromMng(); return; }
    if ((x = g('[data-jresume]'))) { resumeJob(parseInt(x.getAttribute('data-jresume'), 10), parseInt(x.getAttribute('data-jtotal'), 10)); return; }
    if ((x = g('[data-jcancel]'))) { cancelJob(parseInt(x.getAttribute('data-jcancel'), 10)); return; }
    if ((x = g('[data-jerr]'))) { showJobErrors(parseInt(x.getAttribute('data-jerr'), 10)); return; }
    if ((x = g('[data-pcar]'))) { const k = x.getAttribute('data-pcar'); pCol.has(k) ? pCol.delete(k) : pCol.add(k); renderPanel(); return; }
    if ((x = g('.row')) && sel.size) {
      const tag = x.getAttribute('data-t'), ids = Array.from(sel);
      const all = ids.every(id => PH[id] && eff(PH[id]).has(tag));
      if (!all && NOPHOTO[tag]) { askNoPhotoGroup(tag, ids); return; }
      setTag(ids, tag, !all); renderAll(); return;
    }
    if ((x = g('[data-mcar]'))) { const k = x.getAttribute('data-mcar'); mCol.has(k) ? mCol.delete(k) : mCol.add(k); renderMng(); return; }
    if ((x = g('[data-mt]'))) { mkey = x.getAttribute('data-mt'); renderMng(); return; }
    // paramètres
    if ((x = g('[data-rdel]'))) { S.rights.splice(parseInt(x.getAttribute('data-rdel'), 10), 1); renderSet(); return; }
    if (t.id === 'r-add') {
      const kind = $('#r-kind').value, n = $('#r-who').value;
      if (n) { S.rights.push({ k: kind + ':' + n, who: n, type: kind === 'g' ? tx('Groupe') : tx('Utilisateur'), p: [1, 0, 0] }); renderSet(); }
      return;
    }
    if (t.id === 'set-save') {
      api('save_settings', { settings: { original: S.original, thumb: S.thumb, tw: S.tw, reserved: S.reserved, rights: S.rights } })
        .then(r => {
          S.original = r.saved.original; S.thumb = r.saved.thumb; S.tw = r.saved.tw; applyThumbWidth(S.tw); S.rights = r.saved.rights; S.reserved = r.saved.reserved; renderSet();
          if (r.saved.thumb !== thumbAtLoad) {
            toast(tx('Paramètres enregistrés : rechargement pour appliquer la taille des miniatures…'));
            setTimeout(() => location.reload(), 1200);
          } else if (r.saved.reserved.join('|') !== reservedAtLoad) {
            toast(tx('Paramètres enregistrés : rechargement pour appliquer les tags réservés…'));
            setTimeout(() => location.reload(), 1200);
          } else { toast(tx('Paramètres enregistrés')); }
        })
        .catch(err => toast(tx('Échec de l’enregistrement : ') + err.message));
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

  // clic droit dans un arbre : déplier / replier la ligne cliquée (et tout ce qu'elle contient), ou tout l'arbre
  function closeCtx() { const m = $('.ctx'); if (m) { m.remove(); } }
  function treeTarget(el) {
    const keys = () => { const out = []; (function w(n) { Object.keys(n.ch).forEach(k => { const c = n.ch[k]; if (Object.keys(c.ch).length) { out.push(c.key); w(c); } }); })(tagTree()); return out; };
    // sous : clés repliables de la branche (la ligne comprise)
    const tagUnder = k => keys().filter(x => x === k || x.indexOf(k + '|') === 0);
    if (el.closest('#tree')) {
      if (view.tab === 'albums') {
        const n = el.closest('[data-alb]');
        const albUnder = id => { const out = []; (function w(a) { if (a.ch.length) { out.push(String(a.id)); a.ch.forEach(w); } })(albMap[id].a); return out; };
        return { set: collapsed, keys: () => Object.keys(albMap).filter(id => albMap[id].a.ch.length), draw: renderTree,
          node: n && albMap[n.getAttribute('data-alb')] ? { name: albMap[n.getAttribute('data-alb')].a.name, under: () => albUnder(n.getAttribute('data-alb')) } : null };
      }
      const n = el.closest('[data-tag]');
      return { set: collapsed, keys: keys, draw: renderTree, node: n ? { name: n.getAttribute('data-tag').split('|').pop(), under: () => tagUnder(n.getAttribute('data-tag')) } : null };
    }
    if (el.closest('#rows')) {
      const n = el.closest('[data-pcar]');
      return { set: pCol, keys: keys, draw: renderPanel, node: n ? { name: n.getAttribute('data-pcar').split('|').pop(), under: () => tagUnder(n.getAttribute('data-pcar')) } : null };
    }
    if (el.closest('#mtree')) {
      const n = el.closest('[data-mt]');
      return { set: mCol, keys: keys, draw: renderMng, node: n ? { name: n.getAttribute('data-mt').split('|').pop(), under: () => tagUnder(n.getAttribute('data-mt')) } : null };
    }
    return null;
  }
  root.addEventListener('contextmenu', function (e) {
    const tg = treeTarget(e.target);
    if (!tg) { return; }
    e.preventDefault();
    closeCtx();
    const m = document.createElement('div');
    m.className = 'ctx'; m.setAttribute('role', 'menu');
    const br = tg.node && tg.node.under().length ? tg.node : null; // ligne cliquée, si elle a des sous-niveaux
    m.innerHTML = (br ? tx('<button role="menuitem" data-ctx="bopen">Déplier « ') + esc(br.name) + tx(' »</button><button role="menuitem" data-ctx="bclose">Replier « ') + esc(br.name) + tx(' »</button><div class="ctx-sep" role="separator"></div>') : '') +
      tx('<button role="menuitem" data-ctx="open">Tout déplier</button><button role="menuitem" data-ctx="close">Tout replier</button>');
    m.style.left = Math.min(e.clientX, window.innerWidth - 260) + 'px';
    m.style.top = Math.min(e.clientY, window.innerHeight - (br ? 150 : 90)) + 'px';
    m.addEventListener('click', ev => {
      const b = ev.target.closest('[data-ctx]');
      if (!b) { return; }
      const a = b.getAttribute('data-ctx');
      if (a === 'bopen') { br.under().forEach(k => tg.set.delete(String(k))); }
      else if (a === 'bclose') { br.under().forEach(k => tg.set.add(String(k))); }
      else {
        tg.set.clear();
        if (a === 'close') { tg.keys().forEach(k => tg.set.add(String(k))); }
      }
      closeCtx(); tg.draw();
    });
    root.appendChild(m);
  });
  document.addEventListener('click', e => { if (!e.target.closest || !e.target.closest('.ctx')) { closeCtx(); } });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeCtx(); } });
  window.addEventListener('scroll', closeCtx, true);

  root.addEventListener('keydown', e => {
    if (e.key !== 'Enter') { return; }
    if (e.target.getAttribute && e.target.getAttribute('data-smart')) { e.preventDefault(); smartDialog(parseInt(e.target.getAttribute('data-smart'), 10)); return; }
    if (e.target.id === 'mt-name' || e.target.id === 'mt-group') { e.preventDefault(); createFromMng(); }
    if (e.target.id === 'mg-name' || e.target.id === 'mg-group') { e.preventDefault(); createGroupFromMng(); }
  });
  root.addEventListener('input', e => { const t = e.target; if (t.classList && t.classList.contains('rec-tg')) { REC[parseInt(t.getAttribute('data-ri'), 10)].target = t.value; } });
  $('#set-reserved').addEventListener('input', e => { S.reserved = e.target.value.split(',').map(x => x.trim()).filter(Boolean); });
  $('#pfilter').addEventListener('input', e => { pFilter = e.target.value; renderPanel(); });
  $('#tfilter').addEventListener('input', e => { tFilter[view.tab] = e.target.value; renderTree(); });
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
    if (NOPHOTO[n.getAttribute('data-leaf')]) { askNoPhotoGroup(n.getAttribute('data-leaf'), Array.from(sel)); return; }
    setTag(Array.from(sel), n.getAttribute('data-leaf'), true); renderAll();
    toast(tx('« ') + n.getAttribute('data-leaf') + tx(' » ajouté à la file pour ') + sel.size + tx(' photo') + (sel.size > 1 ? 's' : ''));
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
    if (b) { b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.textContent = on ? tx('✕ Quitter le plein écran') : tx('⛶ Plein écran'); }
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
    $('#tm-sub').textContent = tx('Chargement des albums et des tags…');
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
      $('#tm-sub').textContent = tx('Les modifications restent en attente jusqu’à « Aperçu et appliquer » ; elles sont alors écrites dans les fichiers.');
      applyThumbWidth(S.tw);
      await loadPhotos(0);
      renderAll(); renderSet(); checkActive();
      api('recon').then(r => { REC = r.rows; renderMng(); renderRec(); }).catch(() => { /* facultatif */ });
    } catch (e) {
      $('#tree').innerHTML = tx('<div class="empty">Chargement impossible : ') + esc(e.message) + '</div>';
      $('#tm-sub').textContent = '';
    }
  }
  boot();
})();
