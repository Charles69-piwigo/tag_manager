<?php
/**
 * Données fournies à l'interface d'administration (lecture seule) et réglages du plugin.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

include_once(dirname(__FILE__) . '/i18n.php');

include_once(dirname(__FILE__) . '/names.php');
include_once(dirname(__FILE__) . '/file_resolver.php');
include_once(dirname(__FILE__) . '/xmp_reader.php');

// ---------------------------------------------------------------------------------------------
// Tags réservés : les tags de visages / personnes sont gérés par face_tag_editor et digiKam
// ---------------------------------------------------------------------------------------------

/** Racines de hiérarchie réservées (réglage « reserved », « Personnes » par défaut). */
function tagmanager_reserved_roots()
{
  $c = tagmanager_settings_get();
  return $c['reserved'];
}

/**
 * Identifiants des tags enregistrés comme tags de visage par face_tag / face_tag_editor
 * (table facetag_person_tags, absente si ces plugins n'ont jamais servi), tels quels.
 */
function tagmanager_face_registry_ids()
{
  static $ids = null;
  if ($ids !== null)
  {
    return $ids;
  }
  global $prefixeTable;
  $ids = array();
  $t = $prefixeTable . 'facetag_person_tags';
  $r = pwg_query('SHOW TABLES LIKE \'' . pwg_db_real_escape_string(str_replace('_', '\\_', $t)) . '\';');
  if (pwg_db_num_rows($r) > 0)
  {
    $ids = array_map('intval', query2array('SELECT tag_id FROM `' . $t . '` WHERE is_face_tag = 1;', null, 'tag_id'));
  }
  return $ids;
}

/**
 * Tags inscrits comme visages dans le registre de face_tag mais que les fichiers rangent sous une racine
 * normale (chemin indexé « ok » hors racines réservées) : tag_id => chemin.
 * Cas réel : un cadre de visage « Arak » dessiné sur un chien puis supprimé ; face_tag / face_tag_editor
 * inscrivent un nom dans le registre mais ne l'en retirent jamais. Les fichiers font foi.
 * Un tag du registre « à plat » (sans chemin) reste réservé : rien ne prouve que ce n'est pas une personne.
 */
function tagmanager_face_registry_overridden()
{
  static $over = null;
  if ($over !== null)
  {
    return $over;
  }
  $over = array();
  $ids = tagmanager_face_registry_ids();
  if (empty($ids))
  {
    return $over;
  }
  $roots = tagmanager_reserved_roots();
  foreach (query2array('
SELECT tag_id, path FROM ' . TAGMANAGER_PATHS_TABLE . '
  WHERE kind = \'tag\' AND status = \'ok\' AND tag_id IN (' . implode(',', $ids) . ')
;') as $r)
  {
    $reserved = false;
    foreach ($roots as $root)
    {
      if ($r['path'] === $root || strpos($r['path'], $root . '|') === 0)
      {
        $reserved = true;
        break;
      }
    }
    if (!$reserved)
    {
      $over[(int)$r['tag_id']] = $r['path'];
    }
  }
  return $over;
}

/** Tags de visage effectifs : registre de face_tag, moins les tags que les fichiers rangent ailleurs. */
function tagmanager_face_tag_ids()
{
  static $ids = null;
  if ($ids !== null)
  {
    return $ids;
  }
  $over = tagmanager_face_registry_overridden();
  $ids = array_values(array_filter(tagmanager_face_registry_ids(), function ($id) use ($over) { return !isset($over[$id]); }));
  return $ids;
}

/**
 * Identifiants des tags que face_tag / face_tag_editor ont enregistrés puis qui ont été
 * déverrouillés (is_face_tag = 0) : ce sont les candidats aux homonymes personne / tag normal.
 */
function tagmanager_face_registry_unflagged_ids()
{
  global $prefixeTable;
  $t = $prefixeTable . 'facetag_person_tags';
  $r = pwg_query('SHOW TABLES LIKE \'' . pwg_db_real_escape_string(str_replace('_', '\_', $t)) . '\';');
  if (pwg_db_num_rows($r) === 0)
  {
    return array();
  }
  return array_map('intval', query2array('SELECT tag_id FROM `' . $t . '` WHERE is_face_tag = 0;', null, 'tag_id'));
}

/** Vrai si le tag relève des visages / personnes (donc pas de ce plugin). */
function tagmanager_is_reserved($tag_id, $name, $path)
{
  if ($tag_id !== null && in_array((int)$tag_id, tagmanager_face_tag_ids(), true))
  {
    return true;
  }
  foreach (tagmanager_reserved_roots() as $root)
  {
    if ($name === $root)
    {
      return true; // le nom du groupe lui-même, utilisé comme tag à plat
    }
    if ($path !== null && $path !== '' && ($path === $root || strpos($path, $root . '|') === 0))
    {
      return true;
    }
  }
  return false;
}

/** Fragment SQL « n'est pas un tag réservé » (sans AND initial), pour la table des chemins. */
function tagmanager_sql_not_reserved($path_col, $tag_col)
{
  $w = array('1 = 1');
  foreach (tagmanager_reserved_roots() as $root)
  {
    $like = pwg_db_real_escape_string(str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $root));
    $eq = pwg_db_real_escape_string($root);
    $w[] = '(' . $path_col . ' IS NULL OR (' . $path_col . ' NOT LIKE \'' . $like . '|%\' AND ' . $path_col . ' <> \'' . $eq . '\'))';
  }
  $ids = tagmanager_face_tag_ids();
  if (!empty($ids))
  {
    $w[] = '(' . $tag_col . ' IS NULL OR ' . $tag_col . ' NOT IN (' . implode(',', $ids) . '))';
  }
  return implode(' AND ', $w);
}

/** Fragment SQL « le nom n'est pas celui d'une racine réservée » (pour les tags à plat). */
function tagmanager_sql_not_name($name_col)
{
  $roots = tagmanager_reserved_roots();
  if (empty($roots))
  {
    return '1 = 1';
  }
  $q = array();
  foreach ($roots as $r)
  {
    $q[] = '\'' . pwg_db_real_escape_string($r) . '\'';
  }
  return $name_col . ' NOT IN (' . implode(',', $q) . ')';
}

/** Fragment « AND t.id NOT IN (...) » pour exclure les tags de visage enregistrés (vide si aucun). */
function tagmanager_sql_not_face($tag_col)
{
  $ids = tagmanager_face_tag_ids();
  return empty($ids) ? '' : ' AND ' . $tag_col . ' NOT IN (' . implode(',', $ids) . ')';
}

// ---------------------------------------------------------------------------------------------
// Albums physiques et tags
// ---------------------------------------------------------------------------------------------

/**
 * Condition SQL « photo visible par l'utilisateur courant » sur la table des images d'alias $alias,
 * selon les mécanismes de Piwigo (albums interdits, niveau de confidentialité, accès aux images).
 * Seulement les fichiers JPEG. Webmestres : aucune autre restriction. Les autres utilisateurs ne voient et ne modifient
 * que les photos qu'ils pourraient voir dans la galerie.
 */
function tagmanager_visible_sql($alias = 'i')
{
  global $user;
  // seuls les JPEG portent des métadonnées XMP/IPTC écrites par le moteur : PNG, vidéos, etc. sont exclus
  // pour tout le monde (ils se taguent par la méthode standard de Piwigo)
  $conds = array('LOWER(' . $alias . '.path) REGEXP \'[.]jpe?g$\'');
  if (is_webmaster())
  {
    return $conds[0];
  }
  if (!empty($user['forbidden_categories']))
  {
    $conds[] = 'EXISTS (SELECT 1 FROM ' . IMAGE_CATEGORY_TABLE . ' tmic WHERE tmic.image_id = ' . $alias . '.id AND tmic.category_id NOT IN (' . $user['forbidden_categories'] . '))';
  }
  else
  {
    $conds[] = 'EXISTS (SELECT 1 FROM ' . IMAGE_CATEGORY_TABLE . ' tmic WHERE tmic.image_id = ' . $alias . '.id)';
  }
  $lvl = get_sql_condition_FandF(array('forbidden_images' => $alias . '.id'), null, true);
  if ($lvl !== '' && $lvl !== null)
  {
    $conds[] = $lvl;
  }
  return '(' . implode(' AND ', $conds) . ')';
}

/**
 * Albums tels que la galerie les montre (« Gestion des albums ») : albums synchronisés depuis galleries et
 * albums créés dans Piwigo (où arrivent les photos envoyées par upload), dans l'ordre de l'arbre. Une photo
 * appartient à tous les albums auxquels elle est associée (image_category), quel que soit son fichier.
 * Photos sans aucun album (« orphelines ») : album fictif -1, webmestres seulement.
 */
define('TAGMANAGER_ORPHANS', -1);

/** Albums interdits à l'utilisateur courant (clés), aucun pour un webmestre. */
function tagmanager_forbidden_albums()
{
  global $user;
  if (is_webmaster() || empty($user['forbidden_categories']))
  {
    return array();
  }
  return array_flip(array_map('intval', explode(',', $user['forbidden_categories'])));
}

/**
 * Position de chaque album dans l'arbre de « Gestion des albums » (id => rang 0, 1, 2…).
 * global_rank est un texte (« 1.10 » avant « 1.2 » en SQL) : tri en PHP, segment par segment.
 */
function tagmanager_album_order()
{
  static $order = null;
  if ($order !== null)
  {
    return $order;
  }
  $rows = query2array('SELECT id, global_rank FROM ' . CATEGORIES_TABLE . ';');
  foreach ($rows as $k => $r)
  {
    $rows[$k]['r'] = ($r['global_rank'] === null || $r['global_rank'] === '') ? array(PHP_INT_MAX) : array_map('intval', explode('.', (string)$r['global_rank']));
  }
  usort($rows, function ($x, $y)
  {
    $rx = $x['r']; $ry = $y['r'];
    for ($k = 0; $k < min(count($rx), count($ry)); $k++)
    {
      if ($rx[$k] !== $ry[$k]) { return $rx[$k] < $ry[$k] ? -1 : 1; }
    }
    if (count($rx) !== count($ry)) { return count($rx) < count($ry) ? -1 : 1; }
    return (int)$x['id'] - (int)$y['id'];
  });
  $order = array();
  foreach ($rows as $k => $r)
  {
    $order[(int)$r['id']] = $k;
  }
  return $order;
}

/**
 * Albums SmartAlbums (contenu calculé d'après les tags) : id => 1, ou 2 s'il n'a plus aucun critère (seulement
 * les lignes « mode » / « limit ») : SmartAlbums construit alors une requête sans WHERE et y range presque toutes
 * les photos de la galerie (cas « 1979-1980 Maroc » sur photodev2). Vide si le plugin est absent.
 */
function tagmanager_smart_albums()
{
  global $prefixeTable;
  static $smart = null;
  if ($smart !== null)
  {
    return $smart;
  }
  $smart = array();
  $t = $prefixeTable . 'category_filters';
  if (!pwg_db_num_rows(pwg_query('SHOW TABLES LIKE \'' . pwg_db_real_escape_string($t) . '\';')))
  {
    return $smart;
  }
  $res = pwg_query('SELECT category_id AS c, SUM(type NOT IN (\'mode\', \'limit\')) AS rules FROM ' . $t . ' GROUP BY category_id;');
  while ($r = pwg_db_fetch_assoc($res))
  {
    $smart[(int)$r['c']] = (int)$r['rules'] > 0 ? 1 : 2;
  }
  return $smart;
}

/**
 * Mise à jour d'un SmartAlbum depuis l'écran Étiqueter (icône ⟳) : SmartAlbums recalcule le contenu d'un album
 * d'après ses critères avec smart_make_associations() (le même appel que son bouton « Régénérer »).
 * Seules les associations « smart » changent ; les photos rangées à la main dans l'album restent.
 *  = false : aperçu sans écriture (smart_get_pictures() ne fait que lire).
 */
function tagmanager_smart_refresh($cat_id, $apply)
{
  $cat_id = (int)$cat_id;
  $smart = tagmanager_smart_albums();
  if (!function_exists('smart_make_associations') || !function_exists('smart_get_pictures') || !defined('CATEGORY_FILTERS_TABLE'))
  {
    return array('ok' => false, 'error' => tagmanager_t('Le plugin SmartAlbums n\'est pas actif.'));
  }
  $forb = tagmanager_forbidden_albums();
  if (!isset($smart[$cat_id]) || isset($forb[$cat_id]))
  {
    return array('ok' => false, 'error' => tagmanager_t('Cet album n\'est pas un SmartAlbum.'));
  }
  if ($smart[$cat_id] === 2)
  {
    return array('ok' => false, 'error' => tagmanager_t('Ce SmartAlbum n\'a aucun critère : sa mise à jour y rangerait presque toute la galerie. Corrigez d\'abord ses critères dans SmartAlbums.'));
  }
  @set_time_limit(120);

  // colonne ENUM('true','false') de SmartAlbums : comparer au texte (« smart = false » ne trouve aucune ligne)
  $before = array_map('intval', query2array('SELECT image_id FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id = ' . $cat_id . ' AND smart = \'true\';', null, 'image_id'));
  $manual = (int)pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id = ' . $cat_id . ' AND smart <> \'true\';'))[0];
  $after = $apply ? smart_make_associations($cat_id) : smart_get_pictures($cat_id);
  $after = array_values(array_unique(array_map('intval', is_array($after) ? $after : array())));

  if ($apply)
  {
    // comme SmartAlbums après une régénération : vider le cache sans relancer le calcul de tous les SmartAlbums
    if (!defined('SMART_NOT_UPDATE'))
    {
      define('SMART_NOT_UPDATE', 1);
    }
    invalidate_user_cache();
  }

  $name = query2array('SELECT name FROM ' . CATEGORIES_TABLE . ' WHERE id = ' . $cat_id . ';', null, 'name');
  return array(
    'ok' => true,
    'applied' => (bool)$apply,
    'name' => $name ? strip_tags(trigger_change('render_category_name', $name[0], 'tag_manager')) : '',
    'before' => count($before),
    'after' => count($after),
    'added' => count(array_diff($after, $before)),
    'removed' => count(array_diff($before, $after)),
    'manual' => $manual,
  );
}

/**
 * Condition SQL « photo sans aucun album » sur la table des images d'alias $alias.
 */
function tagmanager_orphan_sql($alias = 'i')
{
  return 'NOT EXISTS (SELECT 1 FROM ' . IMAGE_CATEGORY_TABLE . ' tmo WHERE tmo.image_id = ' . $alias . '.id)';
}

function tagmanager_data_albums()
{
  $vis = tagmanager_visible_sql('i');
  $forb = tagmanager_forbidden_albums();
  $smart = tagmanager_smart_albums();

  $rows = query2array('SELECT id, name, id_uppercat FROM ' . CATEGORIES_TABLE . ';', 'id');
  // photos visibles associées directement à chaque album (les sous-albums sont additionnés par le navigateur)
  $counts = query2array('
SELECT ic.category_id AS c, COUNT(DISTINCT i.id) AS n
  FROM ' . IMAGE_CATEGORY_TABLE . ' ic JOIN ' . IMAGES_TABLE . ' i ON i.id = ic.image_id
  WHERE ' . $vis . '
  GROUP BY ic.category_id
;', 'c', 'n');

  // tous les fichiers (vidéos, PNG… compris) : un album sans JPEG n'est pas forcément vide dans la galerie (lien ↗)
  $all_counts = query2array('
SELECT category_id AS c, COUNT(*) AS n
  FROM ' . IMAGE_CATEGORY_TABLE . '
  GROUP BY category_id
;', 'c', 'n');

  // SmartAlbum « inondé » : critères perdus ou ne désignant plus rien (ex. « pas dans l'album X » avec X
  // supprimé) -> il contient l'essentiel de la galerie
  $row = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM ' . IMAGES_TABLE . ' i WHERE ' . $vis . ';'));
  $all = (int)$row[0];

  $out = array();
  foreach (tagmanager_album_order() as $id => $rank)
  {
    if (!isset($rows[$id]) || isset($forb[$id]))
    {
      continue;
    }
    $r = $rows[$id];
    $par = $r['id_uppercat'] === null ? null : (int)$r['id_uppercat'];
    $n = isset($counts[$id]) ? (int)$counts[$id] : 0;
    $a = array(
      'id' => $id,
      'name' => strip_tags(trigger_change('render_category_name', $r['name'], 'tag_manager')),
      'parent' => ($par !== null && !isset($forb[$par])) ? $par : null,
      'n' => $n,
      'na' => isset($all_counts[$id]) ? (int)$all_counts[$id] : 0,
      'smart' => isset($smart[$id]),
    );
    if (isset($smart[$id]) && ($smart[$id] === 2 || ($all > 100 && $n >= 0.8 * $all)))
    {
      $a['broken'] = $smart[$id] === 2 ? 'norule' : 'flood';
    }
    $out[] = $a;
  }

  // les orphelines ne sont visibles que des webmestres (la galerie ne les montre à personne d'autre)
  if (is_webmaster())
  {
    $row = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM ' . IMAGES_TABLE . ' i WHERE ' . tagmanager_orphan_sql('i') . ' AND ' . $vis . ';'));
    $out[] = array('id' => TAGMANAGER_ORPHANS, 'name' => tagmanager_t('Photos orphelines'), 'parent' => null, 'n' => (int)$row[0], 'smart' => false, 'orphans' => true);
  }
  return $out;
}

/**
 * Album de rattachement de chaque photo (bandeaux de la vue par tag, liste « Albums » de la fiche) :
 * son album de stockage s'il est visible (photo synchronisée), sinon le premier de ses albums dans l'ordre
 * de l'arbre, en passant les albums ordinaires avant les SmartAlbums, et un SmartAlbum sans critère en
 * dernier ; null = aucun album visible (orpheline).
 *
 * @return array id => array('id', 'alb', 'd')
 */
function tagmanager_home_albums($from, $where)
{
  $order = tagmanager_album_order();
  $smart = tagmanager_smart_albums();
  $big = count($order) + 1;
  foreach ($smart as $c => $kind)
  {
    if (isset($order[$c])) { $order[$c] += $kind * $big; }
  }
  $forb = tagmanager_forbidden_albums();
  $res = pwg_query('
SELECT i.id, i.storage_category_id AS s, i.date_creation AS d, ic.category_id AS c
  FROM ' . $from . ' LEFT JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.image_id = i.id
  WHERE ' . $where . '
;');
  $out = array();
  $stored = array();
  while ($r = pwg_db_fetch_assoc($res))
  {
    $id = (int)$r['id'];
    if (!isset($out[$id]))
    {
      $out[$id] = array('id' => $id, 'alb' => null, 'd' => $r['d']);
    }
    if ($r['c'] === null || isset($stored[$id]))
    {
      continue;
    }
    $c = (int)$r['c'];
    if (isset($forb[$c]) || !isset($order[$c]))
    {
      continue;
    }
    if ($r['s'] !== null && (int)$r['s'] === $c)
    {
      $out[$id]['alb'] = $c;
      $stored[$id] = true;
    }
    elseif ($out[$id]['alb'] === null || $order[$c] < $order[$out[$id]['alb']])
    {
      $out[$id]['alb'] = $c;
    }
  }
  return $out;
}

/** Tags de Piwigo avec leur chemin indexé et le nombre de photos. */
function tagmanager_data_tags()
{
  $rows = query2array('
SELECT t.id, t.name, COUNT(i.id) AS n, COUNT(it.image_id) AS na, p.path, p.status
  FROM ' . TAGS_TABLE . ' t
    LEFT JOIN ' . IMAGE_TAG_TABLE . ' it ON it.tag_id = t.id
    LEFT JOIN ' . IMAGES_TABLE . ' i ON i.id = it.image_id' . (tagmanager_visible_sql('i') === '' ? '' : ' AND ' . tagmanager_visible_sql('i')) . '
    LEFT JOIN ' . TAGMANAGER_PATHS_TABLE . ' p ON p.tag_id = t.id AND p.kind = \'tag\'
  GROUP BY t.id, t.name, p.path, p.status
  ORDER BY t.name
;');

  $tags = array();
  foreach ($rows as $r)
  {
    if (tagmanager_is_reserved($r['id'], $r['name'], $r['path']))
    {
      continue; // tags de visage : gérés par face_tag_editor / digiKam
    }
    $tags[] = array(
      'id' => (int)$r['id'],
      'name' => $r['name'],
      'n' => (int)$r['n'],
      'na' => (int)$r['na'], // toutes les photos, visibles ou non : 0 = tag sans photo
      'path' => $r['path'],
      'status' => $r['status'],
    );
  }

  $groups = query2array('SELECT path FROM ' . TAGMANAGER_PATHS_TABLE . ' WHERE kind = \'group\';', null, 'path');

  return array('tags' => $tags, 'groups' => $groups);
}

/**
 * Photos d'un album (avec ou sans sous-albums) ou d'un ensemble de tags, par pages.
 *
 * @param string $mode  album | tag
 * @param array  $ids   identifiants d'albums ou de tags
 */
function tagmanager_data_photos($mode, array $ids, $offset, $limit)
{
  include_once(PHPWG_ROOT_PATH . 'include/derivative.inc.php');

  $ids = array_values(array_filter(array_map('intval', $ids)));
  $offset = max(0, intval($offset));
  $limit = max(1, min(500, intval($limit)));
  if (empty($ids))
  {
    return array('total' => 0, 'photos' => array());
  }
  $in = implode(',', $ids);

  if ($mode === 'album')
  {
    $from = IMAGES_TABLE . ' i';
    // photos associées à l'album (image_category), qu'elles viennent de galleries ou d'un upload
    $forb = tagmanager_forbidden_albums();
    $real = array_values(array_filter($ids, function ($v) use ($forb) { return $v > 0 && !isset($forb[$v]); }));
    $conds = array();
    if ($real) { $conds[] = 'EXISTS (SELECT 1 FROM ' . IMAGE_CATEGORY_TABLE . ' tma WHERE tma.image_id = i.id AND tma.category_id IN (' . implode(',', $real) . '))'; }
    if (in_array(TAGMANAGER_ORPHANS, $ids, true) && is_webmaster()) { $conds[] = tagmanager_orphan_sql('i'); }
    if (!$conds) { return array('total' => 0, 'photos' => array()); }
    $where = '(' . implode(' OR ', $conds) . ')';
  }
  else
  {
    $from = IMAGES_TABLE . ' i JOIN ' . IMAGE_TAG_TABLE . ' it ON it.image_id = i.id';
    $where = 'it.tag_id IN (' . $in . ')';
  }

  $vis = tagmanager_visible_sql('i');
  if ($vis !== '')
  {
    $where = '(' . $where . ') AND ' . $vis;
  }

  $row = pwg_db_fetch_row(pwg_query('SELECT COUNT(DISTINCT i.id) FROM ' . $from . ' WHERE ' . $where . ';'));
  $total = (int)$row[0];

  $home = array();
  if ($mode === 'album')
  {
    $imgs = query2array('
SELECT DISTINCT i.*
  FROM ' . $from . '
  WHERE ' . $where . '
  ORDER BY i.date_creation, i.id
  LIMIT ' . $offset . ', ' . $limit . '
;');
  }
  else
  {
    // Par tag : photos regroupées par album de rattachement, dans l'ordre de l'arbre des albums (bandeaux
    // d'album dans la grille) ; orphelines à la fin.
    $all = array_values(tagmanager_home_albums($from, $where));
    $order = tagmanager_album_order();
    usort($all, function ($x, $y) use ($order)
    {
      $rx = $x['alb'] !== null ? $order[$x['alb']] : PHP_INT_MAX;
      $ry = $y['alb'] !== null ? $order[$y['alb']] : PHP_INT_MAX;
      if ($rx !== $ry) { return $rx < $ry ? -1 : 1; }
      if ((string)$x['d'] !== (string)$y['d']) { return strcmp((string)$x['d'], (string)$y['d']); }
      return $x['id'] - $y['id'];
    });
    foreach ($all as $a) { $home[$a['id']] = $a['alb']; }
    $page_ids = array();
    foreach (array_slice($all, $offset, $limit) as $a) { $page_ids[] = (int)$a['id']; }
    $imgs = array();
    if (!empty($page_ids))
    {
      $byid = array();
      foreach (query2array('SELECT * FROM ' . IMAGES_TABLE . ' WHERE id IN (' . implode(',', $page_ids) . ');') as $i) { $byid[(int)$i['id']] = $i; }
      foreach ($page_ids as $id) { if (isset($byid[$id])) { $imgs[] = $byid[$id]; } }
    }
  }

  $ids_page = array();
  foreach ($imgs as $i)
  {
    $ids_page[] = (int)$i['id'];
  }

  $tags_of = array();
  if (!empty($ids_page))
  {
    $res = pwg_query('SELECT image_id, tag_id FROM ' . IMAGE_TAG_TABLE . ' WHERE image_id IN (' . implode(',', $ids_page) . ');');
    while ($r = pwg_db_fetch_assoc($res))
    {
      $tags_of[(int)$r['image_id']][] = (int)$r['tag_id'];
    }
  }

  $types = array('medium' => IMG_MEDIUM, 'large' => IMG_LARGE, 'xlarge' => IMG_XLARGE);
  $cfg = tagmanager_settings_get();
  $thumb_type = $types[$cfg['thumb']];

  $photos = array();
  foreach ($imgs as $i)
  {
    $id = (int)$i['id'];
    $photos[] = array(
      'id' => $id,
      'file' => $i['file'],
      'alb' => isset($home[$id]) ? $home[$id] : null,
      'thumb' => DerivativeImage::url($thumb_type, $i),
      'tags' => isset($tags_of[$id]) ? $tags_of[$id] : array(),
    );
  }

  return array('total' => $total, 'photos' => $photos);
}

/** Photos distinctes et albums concernés par un ensemble de tags. */
function tagmanager_data_tag_albums(array $tag_ids)
{
  $ids = array_values(array_filter(array_map('intval', $tag_ids)));
  if (empty($ids))
  {
    return array('total' => 0, 'albums' => array());
  }
  $in = implode(',', $ids);
  $vis = tagmanager_visible_sql('i');
  $visAnd = $vis === '' ? '' : ' AND ' . $vis;

  // chaque photo compte une fois, dans son album de rattachement (comme les bandeaux de la vue par tag)
  $home = tagmanager_home_albums(IMAGES_TABLE . ' i JOIN ' . IMAGE_TAG_TABLE . ' it ON it.image_id = i.id', 'it.tag_id IN (' . $in . ')' . $visAnd);
  $count = array();
  foreach ($home as $h)
  {
    $k = $h['alb'] === null ? 0 : $h['alb'];
    $count[$k] = isset($count[$k]) ? $count[$k] + 1 : 1;
  }
  arsort($count);

  $out = array();
  foreach (array_slice($count, 0, 50, true) as $alb => $n)
  {
    $out[] = array('alb' => $alb === 0 ? null : (int)$alb, 'n' => (int)$n);
  }
  return array('total' => count($home), 'albums' => $out);
}

// ---------------------------------------------------------------------------------------------
// Réconciliation : tags de Piwigo dont le nom ne se retrouve pas avec un chemin dans les fichiers
// ---------------------------------------------------------------------------------------------

function tagmanager_norm_cmp($s)
{
  return trim(preg_replace('/\s+/', ' ', tagmanager_fold($s)));
}

/**
 * @return array liste de lignes : id, name, n, status, why, act, target, conf
 *   act : merge | rename | place | delete | skip
 */
function tagmanager_data_recon()
{
  @set_time_limit(60);

  $valid = array();
  foreach (query2array('
SELECT t.id, t.name, p.path
  FROM ' . TAGMANAGER_PATHS_TABLE . ' p JOIN ' . TAGS_TABLE . ' t ON t.id = p.tag_id
  WHERE p.kind = \'tag\' AND p.status = \'ok\'
;') as $v)
  {
    if (!tagmanager_is_reserved($v['id'], $v['name'], $v['path']))
    {
      $valid[] = $v['name'];
    }
  }

  $rows = query2array('
SELECT t.id, t.name, p.status, p.sample_image_id,
       (SELECT COUNT(*) FROM ' . IMAGE_TAG_TABLE . ' it JOIN ' . IMAGES_TABLE . ' i ON i.id = it.image_id WHERE it.tag_id = t.id) AS n
  FROM ' . TAGMANAGER_PATHS_TABLE . ' p JOIN ' . TAGS_TABLE . ' t ON t.id = p.tag_id
  WHERE p.kind = \'tag\' AND p.status <> \'ok\'
  ORDER BY t.name
;');

  $valid_set = array();
  foreach ($valid as $v)
  {
    $valid_set[tagmanager_norm_cmp($v)] = true;
  }

  $out = array();
  foreach ($rows as $r)
  {
    if (tagmanager_is_reserved($r['id'], $r['name'], null))
    {
      continue;
    }
    $name = $r['name'];
    $n = (int)$r['n'];
    $row = array('id' => (int)$r['id'], 'name' => $name, 'n' => $n, 'status' => $r['status'],
      'why' => '', 'act' => 'skip', 'target' => '', 'conf' => 'faible');

    // Noms de dernier niveau présents dans les fichiers témoins : jusqu'à 6 photos du tag, réparties
    // sur toute la liste (un seul fichier peut n'avoir aucun chemin alors que d'autres l'ont)
    $file_leaves = array();
    if ($r['status'] === 'flat')
    {
      $ids = query2array('SELECT image_id FROM ' . IMAGE_TAG_TABLE . ' WHERE tag_id = ' . intval($r['id']) . ' ORDER BY image_id;', null, 'image_id');
      $pick = array();
      $cnt = count($ids);
      $take = min(6, $cnt);
      for ($k = 0; $k < $take; $k++)
      {
        $pick[] = intval($ids[(int)floor($k * $cnt / $take)]);
      }
      if ($r['sample_image_id'] && !in_array((int)$r['sample_image_id'], $pick, true))
      {
        $pick[] = (int)$r['sample_image_id'];
      }
      foreach (empty($pick) ? array() : query2array('SELECT path FROM ' . IMAGES_TABLE . ' WHERE id IN (' . implode(',', $pick) . ');') as $img)
      {
        $paths = tagmanager_read_hierarchy(tagmanager_resolve_path($img['path']));
        if ($paths)
        {
          foreach ($paths as $p)
          {
            $file_leaves[] = end($p);
          }
        }
      }
      $file_leaves = array_values(array_unique($file_leaves));
    }

    // Candidat le plus proche, parmi les tags valides puis les noms lus dans le fichier
    $best = null;
    $best_pct = 0;
    $best_valid = false;
    foreach (array_merge($valid, $file_leaves) as $idx => $cand)
    {
      if (tagmanager_norm_cmp($cand) === tagmanager_norm_cmp($name) || preg_match('/^[>|\/]/', trim($cand)))
      {
        continue; // même nom, ou nom mal formé (commence par un séparateur de niveau)
      }
      similar_text(tagmanager_norm_cmp($name), tagmanager_norm_cmp($cand), $pct);
      if ($pct > $best_pct)
      {
        $best_pct = $pct;
        $best = $cand;
        $best_valid = isset($valid_set[tagmanager_norm_cmp($cand)]);
      }
    }

    if ($r['status'] === 'nofile')
    {
      $row['why'] = tagmanager_t('Fichiers illisibles depuis le serveur');
    }
    elseif ($best !== null && $best_pct >= 80)
    {
      $row['act'] = $best_valid ? 'merge' : 'rename';
      $row['target'] = $best;
      $row['conf'] = ($best_pct >= 88) ? 'haute' : 'moyenne';
      $row['why'] = $best_valid ? tagmanager_t('Nom très proche de « %s »', $best) : tagmanager_t('Nom très proche de « %s » (présent dans le fichier, absent de Piwigo)', $best);
    }
    elseif ($n === 0)
    {
      $row['act'] = 'delete';
      $row['conf'] = 'moyenne';
      $row['why'] = tagmanager_t('Aucune photo rattachée');
    }
    else
    {
      $row['act'] = 'place';
      $row['why'] = tagmanager_t('Aucun niveau parent trouvé dans les fichiers testés');
    }

    $out[] = $row;
  }

  // Un même nom pour une personne et un tag ordinaire (homonyme, relevé par l'indexation) : une seule
  // ligne « Séparer », que le tag soit inscrit ou non comme visage dans le registre de face_tag.
  tagmanager_ensure_homonym_table();
  $homonyms = array();
  foreach (query2array('
SELECT t.id, t.name, h.person_path, p.path,
       (SELECT COUNT(*) FROM ' . IMAGE_TAG_TABLE . ' it JOIN ' . IMAGES_TABLE . ' i ON i.id = it.image_id WHERE it.tag_id = t.id) AS n
  FROM ' . TAGMANAGER_HOMONYMS_TABLE . ' h JOIN ' . TAGS_TABLE . ' t ON t.id = h.tag_id
    LEFT JOIN ' . TAGMANAGER_PATHS_TABLE . ' p ON p.tag_id = t.id AND p.kind = \'tag\' AND p.status = \'ok\'
  ORDER BY t.name
;') as $h)
  {
    if (tagmanager_is_reserved($h['id'], $h['name'], null))
    {
      continue;
    }
    $homonyms[(int)$h['id']] = true;
    $where = ($h['path'] && !tagmanager_is_reserved_root($h['path'])) ? ' (' . str_replace('|', ' › ', implode('|', array_slice(explode('|', $h['path']), 0, -1))) . ')' : '';
    $out[] = array('id' => (int)$h['id'], 'name' => $h['name'], 'n' => (int)$h['n'], 'status' => 'split',
      'chip' => tagmanager_t('personne et tag ordinaire'),
      'why' => tagmanager_t('« %s » sert à deux choses : c\'est le nom d\'une personne (cadre de visage) et un tag ordinaire%s. Piwigo n\'a qu\'un seul tag pour les deux et ne peut pas les distinguer.', $h['name'], $where),
      'fix' => tagmanager_t('Solution : donner un autre nom au tag ordinaire. La personne garde le nom « %s ».', $h['name']),
      'act' => 'split', 'target' => $h['name'] . ' (tag)', 'conf' => 'faible');
  }

  // Tag inscrit comme visage par face_tag alors que ses fichiers le rangent ailleurs, sans personne de ce
  // nom : face_tag ne retire jamais un nom de son registre (cadre de visage supprimé, ex. le chien « Arak »).
  $over = tagmanager_face_registry_overridden();
  $over = array_diff_key($over, $homonyms);
  if (!empty($over))
  {
    foreach (query2array('
SELECT t.id, t.name,
       (SELECT COUNT(*) FROM ' . IMAGE_TAG_TABLE . ' it JOIN ' . IMAGES_TABLE . ' i ON i.id = it.image_id WHERE it.tag_id = t.id) AS n
  FROM ' . TAGS_TABLE . ' t
  WHERE t.id IN (' . implode(',', array_keys($over)) . ')
  ORDER BY t.name
;') as $f)
    {
      $grp = implode('|', array_slice(explode('|', $over[(int)$f['id']]), 0, -1));
      $out[] = array('id' => (int)$f['id'], 'name' => $f['name'], 'n' => (int)$f['n'], 'status' => 'unface',
        'chip' => tagmanager_t('marqué visage à tort'), 'group' => str_replace('|', ' › ', $grp),
        'why' => tagmanager_t('« %s » est marqué comme visage, mais aucune photo lue n\'a de cadre de visage à ce nom : ses photos le rangent sous %s. Il est donc caché comme une personne alors que c\'est un tag ordinaire.', $f['name'], str_replace('|', ' › ', $grp !== '' ? $grp : $over[(int)$f['id']])),
        'fix' => tagmanager_t('Solution : le retirer de la liste des visages. Aucun fichier n\'est modifié.'),
        'act' => 'unface', 'target' => '', 'conf' => 'faible');
    }
  }

  return $out;
}

/**
 * « Ce n'est pas un visage » : retire de la liste des visages de face_tag (table facetag_person_tags)
 * un tag que les fichiers rangent hors racine réservée. La ligne est SUPPRIMÉE, comme le fait
 * face_tag_editor : si un cadre de ce nom est dessiné plus tard, face_tag l'inscrira de nouveau.
 * Vérifie d'abord, en lisant les fichiers du tag (300 au plus), qu'aucune photo ne le porte comme
 * personne ; sinon c'est un homonyme : il est enregistré comme tel (la ligne devient « Séparer »).
 * Table absente : refus, rien n'est créé.
 */
function tagmanager_face_unflag($tag_id)
{
  global $prefixeTable;
  include_once(dirname(__FILE__) . '/struct.php');
  $tag_id = (int)$tag_id;
  $t = $prefixeTable . 'facetag_person_tags';
  $r = pwg_query('SHOW TABLES LIKE \'' . pwg_db_real_escape_string(str_replace('_', '\\_', $t)) . '\';');
  if (pwg_db_num_rows($r) === 0)
  {
    return array('ok' => false, 'error' => tagmanager_t('Aucune liste de visages (face_tag / face_tag_editor non installé) : rien à corriger.'));
  }
  $over = tagmanager_face_registry_overridden();
  if (!isset($over[$tag_id]))
  {
    return array('ok' => false, 'error' => tagmanager_t('Ce tag n\'est pas marqué comme visage, ou ses photos ne le rangent pas hors de « Personnes ».'));
  }
  $name = query2array('SELECT name FROM ' . TAGS_TABLE . ' WHERE id = ' . $tag_id . ';', null, 'name');
  $name = $name ? $name[0] : '';
  $scan = tagmanager_usage_scan($tag_id, $name, 300);
  if (!empty($scan['person']) || !empty($scan['both']))
  {
    tagmanager_ensure_homonym_table();
    $any = !empty($scan['both']) ? $scan['both'][0] : $scan['person'][0];
    tagmanager_record_homonym($tag_id, $scan['person_path'], $any, time());
    $n = count($scan['person']) + count($scan['both']);
    return array('ok' => false, 'homonym' => true, 'error' => tagmanager_t('« %s » est bien une personne sur %s photo(s) (%s) : ce n\'est pas une erreur de la liste des visages, mais un même nom pour une personne et un tag ordinaire. La ligne propose maintenant « Séparer ».', $name, $n, str_replace('|', ' › ', $scan['person_path'])));
  }
  pwg_query('DELETE FROM `' . $t . '` WHERE tag_id = ' . $tag_id . ' AND is_face_tag = 1;');
  return array('ok' => true, 'name' => $name, 'read' => $scan['read'], 'total' => $scan['total']);
}

// ---------------------------------------------------------------------------------------------
// Réglages
// ---------------------------------------------------------------------------------------------

function tagmanager_settings_defaults()
{
  return array(
    'original' => false,
    'thumb' => 'large',
    'tw' => 300,
    'reserved' => array('Personnes'),
    'rights' => array(
      array('k' => 'g:TagManager', 'who' => 'TagManager', 'type' => 'Groupe', 'p' => array(1, 0, 0)),
    ),
  );
}

function tagmanager_settings_get()
{
  $raw = conf_get_param('tag_manager_config', null);
  $c = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
  if (!is_array($c))
  {
    $c = array();
  }
  $def = tagmanager_settings_defaults();
  $c['original'] = isset($c['original']) ? (bool)$c['original'] : $def['original'];
  $c['thumb'] = (isset($c['thumb']) && in_array($c['thumb'], array('medium', 'large', 'xlarge'), true)) ? $c['thumb'] : $def['thumb'];
  $c['tw'] = isset($c['tw']) ? max(90, min(400, (int)$c['tw'])) : $def['tw'];
  $c['rights'] = (isset($c['rights']) && is_array($c['rights'])) ? $c['rights'] : $def['rights'];
  $c['reserved'] = (isset($c['reserved']) && is_array($c['reserved'])) ? array_values($c['reserved']) : $def['reserved'];
  return $c;
}

/** Valide et enregistre. @return array réglages enregistrés */
function tagmanager_settings_save($input)
{
  $in = is_array($input) ? $input : array();
  $rights = array();
  if (isset($in['rights']) && is_array($in['rights']))
  {
    foreach (array_slice($in['rights'], 0, 200) as $r)
    {
      if (!is_array($r) || empty($r['k']) || !preg_match('/^(g|u):/', $r['k']) || empty($r['who']))
      {
        continue;
      }
      $p = isset($r['p']) && is_array($r['p']) ? array_values($r['p']) : array(0, 0, 0);
      $p = array(empty($p[0]) ? 0 : 1, empty($p[1]) ? 0 : 1, empty($p[2]) ? 0 : 1);
      if ($p[2]) { $p[1] = 1; }
      if ($p[1]) { $p[0] = 1; }
      $rights[] = array(
        'k' => substr((string)$r['k'], 0, 255),
        'who' => substr((string)$r['who'], 0, 255),
        'type' => (strpos($r['k'], 'g:') === 0) ? 'Groupe' : 'Utilisateur',
        'p' => $p,
      );
    }
  }

  $reserved = array();
  if (isset($in['reserved']) && is_array($in['reserved']))
  {
    foreach (array_slice($in['reserved'], 0, 20) as $r)
    {
      $r = trim((string)$r);
      if ($r !== '' && strpos($r, '|') === false)
      {
        $reserved[] = substr($r, 0, 255);
      }
    }
  }

  $thumb = (isset($in['thumb']) && in_array($in['thumb'], array('medium', 'large', 'xlarge'), true)) ? $in['thumb'] : 'large';
  $tw = isset($in['tw']) ? max(90, min(400, (int)$in['tw'])) : 300;
  $c = array('original' => !empty($in['original']), 'thumb' => $thumb, 'tw' => $tw, 'reserved' => array_values(array_unique($reserved)), 'rights' => $rights);
  conf_update_param('tag_manager_config', $c, true, function ($v) { return addslashes(json_encode($v)); });
  return $c;
}

/** Données pour l'écran Paramètres : réglages + groupes et utilisateurs candidats. */
function tagmanager_data_settings()
{
  global $conf;

  $groups = query2array('SELECT name FROM ' . GROUPS_TABLE . ' ORDER BY name;', null, 'name');

  $guest = isset($conf['guest_id']) ? intval($conf['guest_id']) : 2;
  $users = query2array('SELECT username FROM ' . USERS_TABLE . ' WHERE id <> ' . $guest . ' ORDER BY username;', null, 'username');

  $c = tagmanager_settings_get();
  return array('original' => $c['original'], 'thumb' => $c['thumb'], 'tw' => $c['tw'], 'reserved' => $c['reserved'], 'rights' => $c['rights'], 'groups' => $groups, 'users' => $users, 'level' => tagmanager_user_level());
}

?>
