<?php
/**
 * Opérations de structure sur les tags : renommer, fusionner, supprimer, ranger / déplacer un tag,
 * renommer / déplacer un groupe. Création de tags et de groupes, suppression d'un groupe vide.
 *
 * Une opération de structure se déroule en deux temps :
 *   1. tous les fichiers concernés sont réécrits par la file d'attente (moteur xmp_writer) ;
 *   2. SEULEMENT si tous les fichiers ont réussi, les tags Piwigo sont mis à jour (finalisation),
 *      par les mécanismes du cœur pour que SmartAlbums suive.
 * Si des fichiers échouent, les tags Piwigo ne sont pas touchés : le lot peut être réessayé.
 *
 * Ces opérations ne touchent jamais un tag de visage.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

include_once(dirname(__FILE__) . '/queue.php');

define('TAGMANAGER_MAX_STRUCT_FILES', 30000);

// ---------------------------------------------------------------------------------------------
// Outils
// ---------------------------------------------------------------------------------------------

/** @return string|null message d'erreur, null si le nom est valable */
function tagmanager_valid_name($n)
{
  $n = trim((string)$n);
  if ($n === '')
  {
    return 'Le nom est vide.';
  }
  if (strlen($n) > 255)
  {
    return 'Le nom dépasse 255 caractères.';
  }
  if (preg_match('/[|>\/\\\\]/', $n))
  {
    return 'Les caractères | > / \\ sont interdits dans un nom.';
  }
  if ($n !== strip_tags($n))
  {
    return 'Le nom ne peut pas contenir de balises.';
  }
  return null;
}

/** Tous les tags : nom => id, name, path (chemin indexé ou null), status, ok (chemin exploitable), reserved. */
function tagmanager_tag_map()
{
  $rows = query2array('
SELECT t.id, t.name, p.path, p.status
  FROM ' . TAGS_TABLE . ' t
    LEFT JOIN ' . TAGMANAGER_PATHS_TABLE . ' p ON p.tag_id = t.id AND p.kind = \'tag\'
;');
  $map = array();
  foreach ($rows as $r)
  {
    $ok = ($r['status'] === 'ok' && !empty($r['path']));
    $map[$r['name']] = array(
      'id' => (int)$r['id'],
      'name' => $r['name'],
      'path' => $ok ? $r['path'] : null,
      'status' => $r['status'],
      'ok' => $ok,
      'reserved' => tagmanager_is_reserved($r['id'], $r['name'], $ok ? $r['path'] : null),
    );
  }
  return $map;
}

function tagmanager_group_rows()
{
  return query2array('SELECT path FROM ' . TAGMANAGER_PATHS_TABLE . ' WHERE kind = \'group\';', null, 'path');
}

/** Identifiants distincts des photos qui portent au moins un de ces tags. */
function tagmanager_images_with_tags(array $tag_ids)
{
  $tag_ids = array_values(array_filter(array_map('intval', $tag_ids)));
  if (empty($tag_ids))
  {
    return array();
  }
  $out = array();
  foreach (array_chunk($tag_ids, 500) as $chunk)
  {
    foreach (query2array('SELECT DISTINCT it.image_id FROM ' . IMAGE_TAG_TABLE . ' it JOIN ' . IMAGES_TABLE . ' i ON i.id = it.image_id WHERE it.tag_id IN (' . implode(',', $chunk) . ');', null, 'image_id') as $id)
    {
      $out[(int)$id] = true;
    }
  }
  return array_keys($out);
}

function tagmanager_is_reserved_root($path)
{
  foreach (tagmanager_reserved_roots() as $root)
  {
    if ($path === $root || strpos($path, $root . '|') === 0)
    {
      return true;
    }
  }
  return false;
}

function tagmanager_path_starts($path, $prefix)
{
  return $path === $prefix || strpos($path, $prefix . '|') === 0;
}

// ---------------------------------------------------------------------------------------------
// Plan : validation et calcul des fichiers concernés (aucune écriture)
// ---------------------------------------------------------------------------------------------

/**
 * @param array $ops liste d'opérations, chacune avec 'type' et ses paramètres :
 *   rename       tag, name
 *   merge        tag (à fusionner), to (tag conservé)
 *   delete       tag
 *   place        tag, group (chemin « A|B » du groupe d'accueil)   [ranger / déplacer un tag]
 *   rename_group group (chemin), name
 *   move_group   group (chemin), target (chemin du nouveau parent)
 * @return array ok, errors, lines, file_ops (image_id => ops), final, tags (nombre de tags touchés)
 */
function tagmanager_struct_plan(array $ops)
{
  $plan = array('ok' => true, 'errors' => array(), 'warnings' => array(), 'lines' => array(), 'file_ops' => array(), 'final' => array(), 'tags' => 0);
  if (empty($ops))
  {
    $plan['ok'] = false;
    $plan['errors'][] = 'Aucune opération.';
    return $plan;
  }

  $map = tagmanager_tag_map();
  $groups = tagmanager_group_rows();
  $mutated = array();
  $refs = array();

  $err = function ($m) use (&$plan) { $plan['ok'] = false; $plan['errors'][] = $m; };

  $name_taken = function ($name, $except_id) use ($map)
  {
    $n = tagmanager_norm_cmp($name);
    foreach ($map as $t)
    {
      if ($t['id'] !== $except_id && tagmanager_norm_cmp($t['name']) === $n)
      {
        return true;
      }
    }
    return false;
  };

  $leaves_under = function ($prefix) use ($map)
  {
    $out = array();
    foreach ($map as $t)
    {
      if ($t['path'] !== null && tagmanager_path_starts($t['path'], $prefix))
      {
        $out[] = $t;
      }
    }
    return $out;
  };

  /** Tags dont le chemin passe par ce tag (ses descendants), sans lui-même. */
  $descendants = function ($t) use ($map)
  {
    $out = array();
    if ($t['path'] === null)
    {
      return $out;
    }
    foreach ($map as $o)
    {
      if ($o['id'] !== $t['id'] && $o['path'] !== null && strpos($o['path'], $t['path'] . '|') === 0)
      {
        $out[] = $o;
      }
    }
    return $out;
  };

  /** « alpha (4), Eclipse 2026 (4) » : photos de chaque tag, pour l'aperçu. */
  $describe = function (array $tags)
  {
    $ids = array();
    foreach ($tags as $t) { $ids[] = $t['id']; }
    $n = array();
    if (!empty($ids))
    {
      foreach (query2array('SELECT tag_id, COUNT(*) AS n FROM ' . IMAGE_TAG_TABLE . ' WHERE tag_id IN (' . implode(',', array_map('intval', $ids)) . ') GROUP BY tag_id;') as $r)
      {
        $n[(int)$r['tag_id']] = (int)$r['n'];
      }
    }
    $parts = array();
    foreach ($tags as $t)
    {
      $parts[] = '« ' . $t['name'] . ' » (' . (isset($n[$t['id']]) ? $n[$t['id']] : 0) . ')';
    }
    return implode(', ', $parts);
  };

  $group_exists = function ($path) use ($map, $groups)
  {
    foreach ($map as $t)
    {
      if ($t['path'] !== null && strpos($t['path'], $path . '|') === 0)
      {
        return true;
      }
    }
    foreach ($groups as $g)
    {
      if (tagmanager_path_starts($g, $path))
      {
        return true;
      }
    }
    return false;
  };

  $add_files = function (array $tag_ids, array $fileop) use (&$plan)
  {
    foreach (tagmanager_images_with_tags($tag_ids) as $img)
    {
      $plan['file_ops'][$img][] = $fileop;
    }
  };

  foreach ($ops as $op)
  {
    $type = isset($op['type']) ? $op['type'] : '';
    $tname = isset($op['tag']) ? (string)$op['tag'] : '';

    switch ($type)
    {
      case 'rename':
      case 'merge':
      case 'delete':
      case 'place':
        if (!isset($map[$tname]))
        {
          $err('« ' . $tname . ' » : tag inconnu de Piwigo.');
          break;
        }
        $t = $map[$tname];
        if ($t['reserved'])
        {
          $err('« ' . $tname . ' » : tag de visage, géré par face_tag_editor et digiKam.');
          break;
        }
        $mutated[] = $tname;

        // Un tag peut avoir des tags dessous (comme dans digiKam) : leurs chemins passent par lui
        $kids = $descendants($t);
        if (!empty($kids) && ($type === 'merge' || $type === 'delete'))
        {
          // Seul ce tag est touché : ses descendants ne bougent pas. À l'utilisateur d'accepter en connaissance de cause.
          $plan['warnings'][] = '« ' . $tname . ' » contient ' . count($kids) . ' tag(s) : ' . $describe($kids) . '. '
            . 'Seul « ' . $tname . ' » sera ' . ($type === 'delete' ? 'supprimé' : 'fusionné') . ' ; ces tags ne sont pas modifiés et « ' . $tname
            . ' » ne subsistera que comme groupe dans leur chemin.';
          $kids = array();
        }
        foreach ($kids as $k)
        {
          if ($k['reserved']) { $err('« ' . $tname . ' » contient un tag de visage (« ' . $k['name'] . ' ») : opération refusée.'); break 2; }
          $mutated[] = $k['name'];
        }
        $kid_ids = array();
        foreach ($kids as $k) { $kid_ids[] = $k['id']; }

        if ($type === 'rename')
        {
          $new = trim(isset($op['name']) ? (string)$op['name'] : '');
          if (($e = tagmanager_valid_name($new)) !== null) { $err('« ' . $tname . ' » : ' . $e); break; }
          if ($new === $tname) { $err('« ' . $tname . ' » : le nom est inchangé.'); break; }
          if ($name_taken($new, $t['id'])) { $err('Un tag « ' . $new . ' » existe déjà.'); break; }
          $to = $t['ok'] ? array_merge(array_slice(explode('|', $t['path']), 0, -1), array($new)) : array($new);
          $add_files(array($t['id']), array('op' => 'retag', 'from' => $tname, 'to' => $to));
          $plan['final'][] = array('a' => 'rename_tag', 'id' => $t['id'], 'name' => $new, 'path' => $t['ok'] ? implode('|', $to) : null);
          if (!empty($kids))
          {
            $add_files($kid_ids, array('op' => 'replace_prefix', 'from' => explode('|', $t['path']), 'to' => $to));
            $plan['final'][] = array('a' => 'prefix', 'from' => $t['path'], 'to' => implode('|', $to));
          }
          $plan['lines'][] = 'Renommer « ' . $tname . ' » en « ' . $new . ' »'
            . (!empty($kids) ? ' (et ses ' . count($kids) . ' tag(s) dessous : ' . $describe($kids) . ')' : '');
        }
        elseif ($type === 'merge')
        {
          $dname = isset($op['to']) ? (string)$op['to'] : '';
          if (!isset($map[$dname])) { $err('« ' . $dname . ' » : tag de destination inconnu.'); break; }
          $d = $map[$dname];
          if ($d['id'] === $t['id']) { $err('Un tag ne peut pas être fusionné avec lui-même.'); break; }
          if ($d['reserved']) { $err('« ' . $dname . ' » : tag de visage, géré par face_tag_editor et digiKam.'); break; }
          if (!$d['ok']) { $err('« ' . $dname . ' » : pas de chemin connu, impossible de fusionner vers ce tag.'); break; }
          $refs[] = $dname;
          $add_files(array($t['id']), array('op' => 'retag', 'from' => $tname, 'to' => explode('|', $d['path']), 'ensure' => true));
          $plan['final'][] = array('a' => 'merge', 'dst' => $d['id'], 'src' => array($t['id']));
          $plan['lines'][] = 'Fusionner « ' . $tname . ' » dans « ' . $dname . ' »';
        }
        elseif ($type === 'delete')
        {
          $add_files(array($t['id']), array('op' => 'retag', 'from' => $tname, 'to' => null));
          $plan['final'][] = array('a' => 'delete_tag', 'id' => $t['id']);
          $plan['lines'][] = 'Supprimer « ' . $tname . ' »';
        }
        else // place
        {
          $g = trim(isset($op['group']) ? (string)$op['group'] : '');
          $segs = ($g === '') ? array() : explode('|', $g);
          if (empty($segs)) { $err('« ' . $tname . ' » : choisissez un groupe d\'accueil.'); break; }
          foreach ($segs as $s) { if (($e = tagmanager_valid_name($s)) !== null) { $err('Groupe « ' . $g . ' » : ' . $e); break 2; } }
          if (tagmanager_is_reserved_root($g)) { $err('Le groupe « ' . $g . ' » est réservé aux tags de visage.'); break; }
          $to = array_merge($segs, array($tname));
          if ($t['ok'] && $t['path'] === implode('|', $to))
          {
            // Déjà classé dans Piwigo : on laisse relancer, pour inscrire le tag dans les fichiers qui ne le portent pas
            $plan['warnings'][] = '« ' . $tname . ' » est déjà dans ce groupe dans Piwigo : l\'opération ne fera que compléter les fichiers qui ne le portent pas encore.';
          }
          if (!empty($kids) && $t['ok'] && tagmanager_path_starts(implode('|', $to), $t['path']))
          {
            $err('« ' . $tname . ' » ne peut pas être rangé dans l\'un de ses propres tags.');
            break;
          }
          $add_files(array($t['id']), array('op' => 'retag', 'from' => $tname, 'to' => $to, 'ensure' => true));
          $plan['final'][] = array('a' => 'set_path', 'id' => $t['id'], 'path' => implode('|', $to));
          if (!empty($kids) && $t['ok'])
          {
            $add_files($kid_ids, array('op' => 'replace_prefix', 'from' => explode('|', $t['path']), 'to' => $to));
            $plan['final'][] = array('a' => 'prefix', 'from' => $t['path'], 'to' => implode('|', $to));
          }
          $plan['lines'][] = 'Ranger « ' . $tname . ' » dans « ' . str_replace('|', ' › ', $g) . ' »'
            . (!empty($kids) ? ' (et ses ' . count($kids) . ' tag(s) dessous : ' . $describe($kids) . ')' : '');
        }
        break;

      case 'rename_group':
      case 'move_group':
        $P = trim(isset($op['group']) ? (string)$op['group'] : '');
        if ($P === '' || !$group_exists($P)) { $err('Groupe « ' . str_replace('|', ' › ', $P) . ' » introuvable.'); break; }
        if (tagmanager_is_reserved_root($P)) { $err('Le groupe « ' . $P . ' » est réservé aux tags de visage.'); break; }
        $psegs = explode('|', $P);
        if ($type === 'rename_group')
        {
          $new = trim(isset($op['name']) ? (string)$op['name'] : '');
          if (($e = tagmanager_valid_name($new)) !== null) { $err('Groupe « ' . end($psegs) . ' » : ' . $e); break; }
          $qsegs = array_merge(array_slice($psegs, 0, -1), array($new));
        }
        else
        {
          $target = trim(isset($op['target']) ? (string)$op['target'] : '');
          $tsegs = ($target === '') ? array() : explode('|', $target);
          foreach ($tsegs as $s) { if (($e = tagmanager_valid_name($s)) !== null) { $err('Destination « ' . $target . ' » : ' . $e); break 2; } }
          $qsegs = array_merge($tsegs, array(end($psegs)));
        }
        $Q = implode('|', $qsegs);
        if ($Q === $P) { $err('Le groupe est déjà à cet endroit sous ce nom.'); break; }
        if (tagmanager_path_starts($Q, $P)) { $err('Impossible de déplacer un groupe dans lui-même.'); break; }
        if (tagmanager_is_reserved_root($Q)) { $err('La destination « ' . $Q . ' » est réservée aux tags de visage.'); break; }
        if ($group_exists($Q)) { $err('« ' . str_replace('|', ' › ', $Q) . ' » existe déjà.'); break; }

        $leaves = $leaves_under($P);
        foreach ($leaves as $l)
        {
          if ($l['reserved']) { $err('Le groupe contient un tag de visage (« ' . $l['name'] . ' ») : opération refusée.'); break 2; }
          $mutated[] = $l['name'];
        }
        $ids = array();
        foreach ($leaves as $l) { $ids[] = $l['id']; }
        $add_files($ids, array('op' => 'replace_prefix', 'from' => $psegs, 'to' => $qsegs));
        $plan['final'][] = array('a' => 'prefix', 'from' => $P, 'to' => $Q);
        $plan['lines'][] = ($type === 'rename_group' ? 'Renommer' : 'Déplacer') . ' le groupe « ' . str_replace('|', ' › ', $P) . ' »' . ($type === 'rename_group' ? ' en « ' . end($qsegs) . ' »' : ' vers « ' . str_replace('|', ' › ', $Q) . ' »')
          . ' (' . count($leaves) . ' tag(s) : ' . $describe($leaves) . ')';
        break;

      default:
        $err('Opération inconnue : « ' . $type . ' ».');
    }
  }

  // Ranger / fusionner inscrivent le tag dans les fichiers qui ne le portent pas encore
  $writes_tag = false;
  foreach ($plan['file_ops'] as $fops)
  {
    foreach ($fops as $fo)
    {
      if (isset($fo['ensure']) && $fo['ensure'])
      {
        $writes_tag = true;
        break 2;
      }
    }
  }
  if ($writes_tag)
  {
    $plan['warnings'][] = 'Les photos que Piwigo associe à ce tag mais dont le fichier n\'en porte aucun mot-clé le recevront dans leurs métadonnées (chemin complet). Sans cela, le classement n\'existerait que dans Piwigo et disparaîtrait à la prochaine indexation.';
  }

  $dups = array_diff_assoc($mutated, array_unique($mutated));
  if (!empty($dups))
  {
    $err('Un même tag figure dans deux opérations : « ' . implode('», « ', array_unique($dups)) . ' ».');
  }
  $clash = array_intersect($refs, $mutated);
  if (!empty($clash))
  {
    $err('Un tag de destination est aussi modifié dans le même lot : « ' . implode('», « ', array_unique($clash)) . ' ».');
  }

  $plan['tags'] = count(array_unique($mutated));
  if (count($plan['file_ops']) > TAGMANAGER_MAX_STRUCT_FILES)
  {
    $err('Trop de photos pour un seul lot (' . count($plan['file_ops']) . ', maximum ' . TAGMANAGER_MAX_STRUCT_FILES . ').');
  }
  return $plan;
}

function tagmanager_struct_preview(array $ops)
{
  $plan = tagmanager_struct_plan($ops);
  return array(
    'ok' => $plan['ok'],
    'errors' => $plan['errors'],
    'warnings' => $plan['warnings'],
    'lines' => $plan['lines'],
    'files' => count($plan['file_ops']),
    'tags' => $plan['tags'],
  );
}

/** Crée le lot (kind = struct). Rien n'est écrit dans les fichiers : c'est job_step qui s'en charge. */
function tagmanager_struct_create(array $ops, $original)
{
  global $user;
  tagmanager_ensure_tables();

  $active = tagmanager_job_active();
  if ($active)
  {
    return array('ok' => false, 'error' => 'Un lot est déjà en cours (n° ' . $active['id'] . '). Reprenez-le, réessayez ses erreurs ou annulez-le d\'abord.');
  }
  tagmanager_job_purge(100);

  $plan = tagmanager_struct_plan($ops);
  if (!$plan['ok'])
  {
    return array('ok' => false, 'error' => 'Lot refusé.', 'details' => $plan['errors']);
  }

  $summary = implode(' ; ', $plan['lines']);
  if (strlen($summary) > 250)
  {
    $summary = substr($summary, 0, 247) . '…';
  }
  $files = $plan['file_ops'];

  pwg_query('
INSERT INTO ' . TAGMANAGER_JOBS_TABLE . ' (created_at, user_id, username, status, original, total, summary, kind, final)
VALUES (' . time() . ', ' . intval($user['id']) . ', \'' . pwg_db_real_escape_string($user['username']) . '\', \'pending\', '
  . ($original ? 1 : 0) . ', ' . count($files) . ', \'' . pwg_db_real_escape_string($summary) . '\', \'struct\', \''
  . pwg_db_real_escape_string(json_encode($plan['final'])) . '\')
;');
  $job_id = pwg_db_insert_id();

  $rows = array();
  foreach ($files as $image_id => $fops)
  {
    $rows[] = array('job_id' => $job_id, 'image_id' => $image_id,
      'ops' => pwg_db_real_escape_string(json_encode(array('ops' => $fops, 'add' => array(), 'rem' => array()))));
  }
  mass_inserts(TAGMANAGER_JOBFILES_TABLE, array('job_id', 'image_id', 'ops'), $rows);

  // Aucun fichier à réécrire (tags sans photo) : on finalise tout de suite
  return array('ok' => true, 'job' => (int)$job_id, 'total' => count($files), 'summary' => $summary);
}

// ---------------------------------------------------------------------------------------------
// Finalisation : mise à jour des tags Piwigo, après la réussite de TOUS les fichiers
// ---------------------------------------------------------------------------------------------

function tagmanager_upsert_path($tag_id, $path)
{
  pwg_query('
INSERT INTO ' . TAGMANAGER_PATHS_TABLE . ' (kind, tag_id, path, sample_image_id, status, indexed_at)
VALUES (\'tag\', ' . intval($tag_id) . ', \'' . pwg_db_real_escape_string($path) . '\', NULL, \'ok\', ' . time() . ')
ON DUPLICATE KEY UPDATE path = VALUES(path), status = \'ok\', indexed_at = VALUES(indexed_at)
;');
}

/** @return array ok (bool), messages */
function tagmanager_job_finalize($job_id)
{
  include_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');

  $job = pwg_db_fetch_assoc(pwg_query('SELECT final FROM ' . TAGMANAGER_JOBS_TABLE . ' WHERE id = ' . intval($job_id) . ';'));
  $final = ($job && $job['final']) ? json_decode($job['final'], true) : array();
  $msgs = array();
  $ok = true;

  foreach ($final as $f)
  {
    switch ($f['a'])
    {
      case 'rename_tag':
        $id = intval($f['id']);
        $dup = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM ' . TAGS_TABLE . ' WHERE name = \'' . pwg_db_real_escape_string($f['name']) . '\' AND id <> ' . $id . ';'));
        if ((int)$dup[0] > 0)
        {
          $msgs[] = 'Renommage ignoré : « ' . $f['name'] . ' » existe déjà.';
          $ok = false;
          break;
        }
        single_update(
          TAGS_TABLE,
          array('name' => pwg_db_real_escape_string($f['name']), 'url_name' => pwg_db_real_escape_string(trigger_change('render_tag_url', $f['name']))),
          array('id' => $id)
        );
        if (function_exists('pwg_activity')) { pwg_activity('tag', $id, 'edit'); }
        if (!empty($f['path'])) { tagmanager_upsert_path($id, $f['path']); }
        break;

      case 'set_path':
        tagmanager_upsert_path($f['id'], $f['path']);
        break;

      case 'prefix':
        $from = $f['from'];
        $to = $f['to'];
        $like = pwg_db_real_escape_string(str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $from));
        $rows = query2array('SELECT id, path FROM ' . TAGMANAGER_PATHS_TABLE . ' WHERE path = \'' . pwg_db_real_escape_string($from) . '\' OR path LIKE \'' . $like . '|%\';');
        foreach ($rows as $r)
        {
          pwg_query('UPDATE ' . TAGMANAGER_PATHS_TABLE . ' SET path = \'' . pwg_db_real_escape_string($to . substr($r['path'], strlen($from))) . '\' WHERE id = ' . intval($r['id']) . ';');
        }
        break;

      case 'merge':
        $dst = intval($f['dst']);
        $src = array_values(array_diff(array_map('intval', $f['src']), array($dst)));
        if (empty($src)) { break; }
        $in_src = query2array('SELECT DISTINCT image_id FROM ' . IMAGE_TAG_TABLE . ' WHERE tag_id IN (' . implode(',', $src) . ');', null, 'image_id');
        $in_dst = query2array('SELECT image_id FROM ' . IMAGE_TAG_TABLE . ' WHERE tag_id = ' . $dst . ';', null, 'image_id');
        $inserts = array();
        foreach (array_diff($in_src, $in_dst) as $img)
        {
          $inserts[] = array('tag_id' => $dst, 'image_id' => intval($img));
        }
        mass_inserts(IMAGE_TAG_TABLE, array('tag_id', 'image_id'), $inserts, array('ignore' => true));
        trigger_notify('merge_tags', $dst, $src);   // SmartAlbums remplace l'ancien identifiant par le nouveau
        delete_tags($src);
        pwg_query('DELETE FROM ' . TAGMANAGER_PATHS_TABLE . ' WHERE kind = \'tag\' AND tag_id IN (' . implode(',', $src) . ');');
        break;

      case 'delete_tag':
        delete_tags(array(intval($f['id'])));       // SmartAlbums retire le tag de ses filtres
        pwg_query('DELETE FROM ' . TAGMANAGER_PATHS_TABLE . ' WHERE kind = \'tag\' AND tag_id = ' . intval($f['id']) . ';');
        break;
    }
  }

  invalidate_user_cache();
  return array('ok' => $ok, 'messages' => $msgs);
}

// ---------------------------------------------------------------------------------------------
// Opérations immédiates (sans réécriture de fichiers)
// ---------------------------------------------------------------------------------------------

/** Crée un groupe vide (mémorisé dans l'index ; il apparaîtra dans les fichiers à la première affectation). */
function tagmanager_group_create($parent, $name)
{
  $name = trim($name);
  if (($e = tagmanager_valid_name($name)) !== null) { return array('ok' => false, 'error' => $e); }
  $parent = trim($parent);
  $path = ($parent === '') ? $name : $parent . '|' . $name;
  if (tagmanager_is_reserved_root($path)) { return array('ok' => false, 'error' => 'Ce groupe est réservé aux tags de visage.'); }

  $map = tagmanager_tag_map();
  foreach ($map as $t)
  {
    if ($t['path'] !== null && tagmanager_path_starts($t['path'], $path))
    {
      return array('ok' => false, 'error' => 'Ce groupe existe déjà.');
    }
  }
  foreach (tagmanager_group_rows() as $g)
  {
    if (tagmanager_path_starts($g, $path)) { return array('ok' => false, 'error' => 'Ce groupe existe déjà.'); }
  }
  pwg_query('INSERT INTO ' . TAGMANAGER_PATHS_TABLE . ' (kind, tag_id, path, status, indexed_at) VALUES (\'group\', NULL, \'' . pwg_db_real_escape_string($path) . '\', \'ok\', ' . time() . ');');
  return array('ok' => true, 'path' => $path);
}

/** Crée un tag de dernier niveau dans un groupe (tag Piwigo sans photo + chemin dans l'index). */
function tagmanager_tag_create($parent, $name)
{
  include_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');

  $name = trim($name);
  if (($e = tagmanager_valid_name($name)) !== null) { return array('ok' => false, 'error' => $e); }
  $parent = trim($parent);
  if ($parent === '') { return array('ok' => false, 'error' => 'Choisissez un groupe d\'accueil.'); }
  if (tagmanager_is_reserved_root($parent)) { return array('ok' => false, 'error' => 'Ce groupe est réservé aux tags de visage.'); }

  $n = tagmanager_norm_cmp($name);
  foreach (tagmanager_tag_map() as $t)
  {
    if (tagmanager_norm_cmp($t['name']) === $n)
    {
      return array('ok' => false, 'error' => 'Un tag « ' . $t['name'] . ' » existe déjà : les noms de dernier niveau doivent rester uniques.');
    }
  }

  $r = create_tag(pwg_db_real_escape_string($name));
  if (isset($r['error']) || empty($r['id']))
  {
    return array('ok' => false, 'error' => 'Création impossible.');
  }
  tagmanager_upsert_path($r['id'], $parent . '|' . $name);
  // le groupe est désormais porté par ce tag : on retire la ligne de groupe vide éventuelle
  pwg_query('DELETE FROM ' . TAGMANAGER_PATHS_TABLE . ' WHERE kind = \'group\' AND path = \'' . pwg_db_real_escape_string($parent) . '\';');
  return array('ok' => true, 'id' => (int)$r['id'], 'path' => $parent . '|' . $name);
}

/** Supprime un groupe qui ne contient aucun tag (les sous-groupes vides partent avec lui). */
function tagmanager_group_delete($path)
{
  $path = trim($path);
  if ($path === '') { return array('ok' => false, 'error' => 'Groupe inconnu.'); }
  if (tagmanager_is_reserved_root($path)) { return array('ok' => false, 'error' => 'Ce groupe est réservé aux tags de visage.'); }

  foreach (tagmanager_tag_map() as $t)
  {
    if ($t['path'] !== null && strpos($t['path'], $path . '|') === 0)
    {
      return array('ok' => false, 'error' => 'Ce groupe contient des tags : déplacez-les ou supprimez-les d\'abord.');
    }
  }
  $like = pwg_db_real_escape_string(str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $path));
  pwg_query('DELETE FROM ' . TAGMANAGER_PATHS_TABLE . ' WHERE kind = \'group\' AND (path = \'' . pwg_db_real_escape_string($path) . '\' OR path LIKE \'' . $like . '|%\');');
  return array('ok' => true);
}

?>
