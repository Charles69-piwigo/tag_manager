<?php
/**
 * Indexation des chemins hiérarchiques des tags (lecture seule sur les fichiers).
 *
 * Piwigo ne conserve que le dernier niveau des tags. Les noms de dernier niveau étant uniques,
 * une seule photo par tag suffit pour connaître son chemin complet. Chaque fichier lu renseigne
 * en plus tous les autres tags qu'il porte : le nombre de lectures reste très inférieur au
 * nombre de photos.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

include_once(TAGMANAGER_PATH . 'lib/file_resolver.php');
include_once(TAGMANAGER_PATH . 'lib/xmp_reader.php');

// Nombre maximal de photos testées par tag avant de le déclarer « à plat » ou « introuvable »
define('TAGMANAGER_SAMPLE', 8);

function tagmanager_norm($s)
{
  return mb_strtolower(trim((string)$s), 'UTF-8');
}

/** Tags qui ont au moins une photo. */
function tagmanager_count_used_tags()
{
  $row = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*)
  FROM ' . TAGS_TABLE . ' t
  WHERE EXISTS (SELECT 1 FROM ' . IMAGE_TAG_TABLE . ' it WHERE it.tag_id = t.id)' . tagmanager_sql_not_face('t.id') . '
;'));
  return (int)$row[0];
}

function tagmanager_upsert_tag_path($tag_id, $path, $image_id, $status, $now)
{
  pwg_query('
INSERT INTO ' . TAGMANAGER_PATHS_TABLE . ' (kind, tag_id, path, sample_image_id, status, indexed_at)
VALUES (\'tag\', ' . intval($tag_id) . ', \'' . pwg_db_real_escape_string($path) . '\', '
  . ($image_id ? intval($image_id) : 'NULL') . ', \'' . pwg_db_real_escape_string($status) . '\', ' . intval($now) . ')
ON DUPLICATE KEY UPDATE
  path = VALUES(path),
  sample_image_id = VALUES(sample_image_id),
  status = VALUES(status),
  indexed_at = VALUES(indexed_at)
;');
}

/**
 * Traite un lot de tags (ordre croissant des identifiants, au-delà de $last_id).
 *
 * @param int $last_id dernier identifiant de tag déjà traité (0 pour commencer)
 * @param int $run     horodatage de début d'indexation (sert à repérer les tags déjà renseignés)
 * @param int $limit   nombre de tags examinés par lot
 * @return array processed, last_id, done, ok, flat, nofile, reads
 */
function tagmanager_index_batch($last_id, $run, $limit = 20)
{
  @set_time_limit(60);

  $by_name = array();
  $res = pwg_query('SELECT id, name FROM ' . TAGS_TABLE . ';');
  while ($r = pwg_db_fetch_assoc($res))
  {
    $by_name[tagmanager_norm($r['name'])] = (int)$r['id'];
  }

  $tags = query2array('
SELECT t.id, t.name
  FROM ' . TAGS_TABLE . ' t
  WHERE t.id > ' . intval($last_id) . '
    AND EXISTS (SELECT 1 FROM ' . IMAGE_TAG_TABLE . ' it WHERE it.tag_id = t.id)' . tagmanager_sql_not_face('t.id') . '
  ORDER BY t.id
  LIMIT ' . intval($limit) . '
;');

  $out = array('processed' => 0, 'last_id' => (int)$last_id, 'done' => false, 'ok' => 0, 'flat' => 0, 'nofile' => 0, 'reads' => 0);
  $now = time();

  foreach ($tags as $tag)
  {
    $out['processed']++;
    $out['last_id'] = (int)$tag['id'];

    // Déjà renseigné pendant cette indexation (par un fichier lu pour un autre tag)
    $known = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*) FROM ' . TAGMANAGER_PATHS_TABLE . '
  WHERE tag_id = ' . intval($tag['id']) . ' AND indexed_at >= ' . intval($run) . '
;'));
    if ((int)$known[0] > 0)
    {
      $out['ok']++;
      continue;
    }

    $wanted = tagmanager_norm($tag['name']);

    // Un tag peut être attaché en base à des photos dont le fichier ne le porte plus (ou pas avec
    // son chemin) alors que d'autres fichiers l'ont : on teste jusqu'à TAGMANAGER_SAMPLE photos
    // réparties sur toute la liste, et on s'arrête dès qu'un fichier donne le chemin.
    $all_ids = query2array('
SELECT image_id
  FROM ' . IMAGE_TAG_TABLE . '
  WHERE tag_id = ' . intval($tag['id']) . '
  ORDER BY image_id
;', null, 'image_id');
    $count = count($all_ids);
    $take = min(TAGMANAGER_SAMPLE, $count);
    $pick = array();
    for ($k = 0; $k < $take; $k++)
    {
      $pick[] = intval($all_ids[(int)floor($k * $count / $take)]);
    }
    $images = empty($pick) ? array() : query2array('
SELECT id, path
  FROM ' . IMAGES_TABLE . '
  WHERE id IN (' . implode(',', $pick) . ')
  ORDER BY id
;');

    $found = false;
    $read_any = false;
    $sample = null;

    foreach ($images as $img)
    {
      $paths = tagmanager_read_hierarchy(tagmanager_resolve_path($img['path']));
      if ($paths === null)
      {
        continue; // fichier introuvable ou illisible : on essaie la photo suivante
      }

      $read_any = true;
      $out['reads']++;
      $sample = $img['id'];

      foreach ($paths as $p)
      {
        $leaf = tagmanager_norm(end($p));
        if (isset($by_name[$leaf]) && count($p) > 0)
        {
          tagmanager_upsert_tag_path($by_name[$leaf], implode('|', $p), $img['id'], 'ok', $now);
          if ($leaf === $wanted)
          {
            $found = true;
          }
        }
      }

      if ($found)
      {
        break;
      }
    }

    if ($found)
    {
      $out['ok']++;
    }
    elseif ($read_any)
    {
      // Fichier lu mais le tag n'y figure qu'à plat : le chemin se réduit au nom
      tagmanager_upsert_tag_path($tag['id'], $tag['name'], $sample, 'flat', $now);
      $out['flat']++;
    }
    else
    {
      tagmanager_upsert_tag_path($tag['id'], $tag['name'], null, 'nofile', $now);
      $out['nofile']++;
    }
  }

  $out['done'] = count($tags) < $limit;
  return $out;
}

/** Résumé de l'index pour la page d'administration. */
function tagmanager_index_summary()
{
  $sum = array('total_tags' => tagmanager_count_used_tags(), 'ok' => 0, 'flat' => 0, 'nofile' => 0, 'last' => 0);

  $res = pwg_query('
SELECT p.status, COUNT(*) AS n, MAX(p.indexed_at) AS last
  FROM ' . TAGMANAGER_PATHS_TABLE . ' p
    LEFT JOIN ' . TAGS_TABLE . ' t ON t.id = p.tag_id
  WHERE p.kind = \'tag\' AND ' . tagmanager_sql_not_reserved('p.path', 'p.tag_id') . '
    AND (t.name IS NULL OR ' . tagmanager_sql_not_name('t.name') . ')
  GROUP BY p.status
;');
  while ($r = pwg_db_fetch_assoc($res))
  {
    if (isset($sum[$r['status']]))
    {
      $sum[$r['status']] = (int)$r['n'];
    }
    $sum['last'] = max($sum['last'], (int)$r['last']);
  }

  return $sum;
}

/** Arbre HTML (listes imbriquées) des chemins indexés. */
function tagmanager_index_tree_html()
{
  $rows = query2array('
SELECT p.path, p.status, t.name, (SELECT COUNT(*) FROM ' . IMAGE_TAG_TABLE . ' it WHERE it.tag_id = p.tag_id) AS nb
  FROM ' . TAGMANAGER_PATHS_TABLE . ' p
    LEFT JOIN ' . TAGS_TABLE . ' t ON t.id = p.tag_id
  WHERE p.kind = \'tag\' AND p.status = \'ok\' AND ' . tagmanager_sql_not_reserved('p.path', 'p.tag_id') . '
  ORDER BY p.path
;');

  $tree = array();
  foreach ($rows as $r)
  {
    $segments = explode('|', $r['path']);
    $leaf = array_pop($segments);
    $node = &$tree;
    foreach ($segments as $s)
    {
      if (!isset($node[$s]) || !isset($node[$s]['ch']))
      {
        $node[$s] = array('ch' => array());
      }
      $node = &$node[$s]['ch'];
    }
    $node[$leaf] = array('nb' => (int)$r['nb']);
    unset($node);
  }

  $render = function ($nodes) use (&$render)
  {
    $h = '<ul>';
    ksort($nodes, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($nodes as $name => $n)
    {
      if (isset($n['ch']))
      {
        $h .= '<li class="tm-group">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . $render($n['ch']) . '</li>';
      }
      else
      {
        $h .= '<li class="tm-leaf">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' <span class="tm-nb">' . $n['nb'] . '</span></li>';
      }
    }
    return $h . '</ul>';
  };

  return empty($tree) ? '' : $render($tree);
}

/** Tags sans chemin hiérarchique exploitable (à plat, ou fichier introuvable). */
function tagmanager_index_problems()
{
  return query2array('
SELECT t.name, p.status
  FROM ' . TAGMANAGER_PATHS_TABLE . ' p
    JOIN ' . TAGS_TABLE . ' t ON t.id = p.tag_id
  WHERE p.kind = \'tag\' AND p.status <> \'ok\' AND ' . tagmanager_sql_not_reserved('p.path', 'p.tag_id') . '
    AND ' . tagmanager_sql_not_name('t.name') . '
  ORDER BY p.status, t.name
;');
}

?>
