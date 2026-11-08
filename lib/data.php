<?php
/**
 * Données fournies à l'interface d'administration (lecture seule) et réglages du plugin.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

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
 * (table facetag_person_tags, absente si ces plugins n'ont jamais servi).
 */
function tagmanager_face_tag_ids()
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

/** Albums physiques (répertoires réels) avec le nombre de photos rangées directement dedans. */
// Photos envoyées par l'interface de Piwigo (./upload) : pas de répertoire d'album, donc un album virtuel -1.
define('TAGMANAGER_UPLOAD_ALBUM', -1);
define('TAGMANAGER_UPLOAD_SQL', "storage_category_id IS NULL AND path LIKE './upload/%'");
define('TAGMANAGER_UPLOAD_MONTH_RE', "path REGEXP '^[.]/upload/[0-9]{4}/[0-9]{2}/'");

/**
 * Album virtuel d'upload -> condition SQL sur images (sans préfixe de table).
 * -1 = racine (photos hors année/mois), -AAAA = année, -AAAAMM = mois.
 */
function tagmanager_upload_cond($id)
{
  $n = -intval($id);
  if ($n === 1) { return '(' . TAGMANAGER_UPLOAD_SQL . ' AND NOT ' . TAGMANAGER_UPLOAD_MONTH_RE . ')'; }
  if ($n >= 1000 && $n <= 9999) { return "(storage_category_id IS NULL AND path REGEXP '^[.]/upload/" . $n . "/[0-9]{2}/')"; }
  if ($n >= 100000 && $n <= 999999) { return "(storage_category_id IS NULL AND path LIKE './upload/" . intdiv($n, 100) . '/' . sprintf('%02d', $n % 100) . "/%')"; }
  return '(0)';
}

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

function tagmanager_data_albums()
{
  $vis = tagmanager_visible_sql('i');
  $visAnd = $vis === '' ? '' : ' AND ' . $vis;

  $rows = query2array('
SELECT id, name, id_uppercat
  FROM ' . CATEGORIES_TABLE . '
  WHERE dir IS NOT NULL
;');

  $counts = query2array('
SELECT i.storage_category_id AS c, COUNT(*) AS n
  FROM ' . IMAGES_TABLE . ' i
  WHERE i.storage_category_id IS NOT NULL' . $visAnd . '
  GROUP BY i.storage_category_id
;', 'c', 'n');

  $out = array();
  foreach ($rows as $r)
  {
    $out[] = array(
      'id' => (int)$r['id'],
      'name' => $r['name'],
      'parent' => $r['id_uppercat'] === null ? null : (int)$r['id_uppercat'],
      'n' => isset($counts[$r['id']]) ? (int)$counts[$r['id']] : 0,
    );
  }

  // Les répertoires physiques ne sont pas les albums de la galerie : un utilisateur peut y voir des photos
  // par ses albums virtuels sans avoir le droit sur le répertoire. On garde donc les répertoires qui
  // contiennent (eux ou leurs sous-répertoires) au moins une photo visible ; les compteurs, déjà
  // filtrés, ne comptent que les photos visibles.
  if (!is_webmaster())
  {
    $kids = array();
    foreach ($out as $k => $a)
    {
      $kids[$a['parent'] === null ? 0 : $a['parent']][] = $k;
    }
    $keep = array();
    $tot = function ($k) use (&$tot, &$keep, $out, $kids)
    {
      $n = $out[$k]['n'];
      foreach (isset($kids[$out[$k]['id']]) ? $kids[$out[$k]['id']] : array() as $c)
      {
        $n += $tot($c);
      }
      if ($n > 0) { $keep[$k] = true; }
      return $n;
    };
    foreach (isset($kids[0]) ? $kids[0] : array() as $k)
    {
      $tot($k);
    }
    $out = array_values(array_intersect_key($out, $keep));
  }

  $months = query2array('
SELECT SUBSTRING(path, 10, 4) AS y, SUBSTRING(path, 15, 2) AS m, COUNT(*) AS n
  FROM ' . IMAGES_TABLE . ' i
  WHERE ' . TAGMANAGER_UPLOAD_SQL . ' AND ' . TAGMANAGER_UPLOAD_MONTH_RE . $visAnd . '
  GROUP BY y, m
;');
  $row = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM ' . IMAGES_TABLE . ' i WHERE ' . TAGMANAGER_UPLOAD_SQL . $visAnd . ';'));
  $in_months = 0;
  $years = array();
  foreach ($months as $r)
  {
    $y = (int)$r['y'];
    $years[$y] = true;
    $in_months += (int)$r['n'];
    $out[] = array('id' => -($y * 100 + (int)$r['m']), 'name' => $r['y'] . '-' . $r['m'], 'parent' => -$y, 'n' => (int)$r['n']);
  }
  foreach (array_keys($years) as $y)
  {
    $out[] = array('id' => -$y, 'name' => (string)$y, 'parent' => TAGMANAGER_UPLOAD_ALBUM, 'n' => 0);
  }
  $out[] = array('id' => TAGMANAGER_UPLOAD_ALBUM, 'name' => 'upload', 'parent' => null, 'n' => (int)$row[0] - $in_months);
  return $out;
}

/** Tags de Piwigo avec leur chemin indexé et le nombre de photos. */
function tagmanager_data_tags()
{
  $rows = query2array('
SELECT t.id, t.name, COUNT(i.id) AS n, p.path, p.status
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
    $real = array_values(array_filter($ids, function ($v) { return $v > 0; }));
    $conds = array();
    if ($real) { $conds[] = 'i.storage_category_id IN (' . implode(',', $real) . ')'; }
    foreach ($ids as $v)
    {
      if ($v < 0) { $conds[] = tagmanager_upload_cond($v); }
    }
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

  $imgs = query2array('
SELECT DISTINCT i.*
  FROM ' . $from . '
  WHERE ' . $where . '
  ORDER BY i.date_creation, i.id
  LIMIT ' . $offset . ', ' . $limit . '
;');

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
      'alb' => $i['storage_category_id'] === null ? null : (int)$i['storage_category_id'],
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

  $row = pwg_db_fetch_row(pwg_query('
SELECT COUNT(DISTINCT it.image_id)
  FROM ' . IMAGE_TAG_TABLE . ' it JOIN ' . IMAGES_TABLE . ' i ON i.id = it.image_id
  WHERE it.tag_id IN (' . $in . ')' . $visAnd . '
;'));

  $albums = query2array('
SELECT i.storage_category_id AS alb, COUNT(DISTINCT i.id) AS n
  FROM ' . IMAGE_TAG_TABLE . ' it JOIN ' . IMAGES_TABLE . ' i ON i.id = it.image_id
  WHERE it.tag_id IN (' . $in . ')' . $visAnd . '
  GROUP BY i.storage_category_id
  ORDER BY n DESC
  LIMIT 50
;');

  $out = array();
  foreach ($albums as $a)
  {
    $out[] = array('alb' => $a['alb'] === null ? null : (int)$a['alb'], 'n' => (int)$a['n']);
  }
  return array('total' => (int)$row[0], 'albums' => $out);
}

// ---------------------------------------------------------------------------------------------
// Réconciliation : tags de Piwigo dont le nom ne se retrouve pas avec un chemin dans les fichiers
// ---------------------------------------------------------------------------------------------

function tagmanager_norm_cmp($s)
{
  $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
  return trim(preg_replace('/\s+/', ' ', $s));
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

    // Noms de dernier niveau présents dans le fichier témoin
    $file_leaves = array();
    if ($r['status'] === 'flat' && $r['sample_image_id'])
    {
      $img = pwg_db_fetch_assoc(pwg_query('SELECT path FROM ' . IMAGES_TABLE . ' WHERE id = ' . intval($r['sample_image_id']) . ';'));
      if ($img)
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
      $row['why'] = 'Fichiers illisibles depuis le serveur';
    }
    elseif ($best !== null && $best_pct >= 80)
    {
      $row['act'] = $best_valid ? 'merge' : 'rename';
      $row['target'] = $best;
      $row['conf'] = ($best_pct >= 88) ? 'haute' : 'moyenne';
      $row['why'] = 'Nom très proche de « ' . $best . ' »' . ($best_valid ? '' : ' (présent dans le fichier, absent de Piwigo)');
    }
    elseif ($n === 0)
    {
      $row['act'] = 'delete';
      $row['conf'] = 'moyenne';
      $row['why'] = 'Aucune photo rattachée';
    }
    else
    {
      $row['act'] = 'place';
      $row['why'] = 'Aucun niveau parent trouvé dans les fichiers testés';
    }

    $out[] = $row;
  }

  return $out;
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
