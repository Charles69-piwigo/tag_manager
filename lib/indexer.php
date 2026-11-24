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

include_once(TAGMANAGER_PATH . 'lib/names.php');
include_once(TAGMANAGER_PATH . 'lib/file_resolver.php');
include_once(TAGMANAGER_PATH . 'lib/xmp_reader.php');

// Nombre maximal de photos testées par tag avant de le déclarer « à plat » ou « introuvable »
define('TAGMANAGER_SAMPLE', 8);

// ---------------------------------------------------------------------------------------------
// Indexation incrémentale (1.8.4) : « Mettre à jour l'index » ne relit que les tags qui ont pu changer ;
// « Tout réindexer » (mode full) relit tous les tags comme avant.
// ---------------------------------------------------------------------------------------------

/** Colonne nb_photos de la table des chemins (nombre de photos du tag à sa dernière indexation). */
function tagmanager_ensure_paths_columns()
{
  static $done = false;
  if ($done)
  {
    return;
  }
  $done = true;
  $cols = query2array('SHOW COLUMNS FROM `' . TAGMANAGER_PATHS_TABLE . '`;', 'Field', 'Field');
  if (!isset($cols['nb_photos']))
  {
    // NULL = inconnu : le tag sera relu à la prochaine mise à jour
    pwg_query('ALTER TABLE `' . TAGMANAGER_PATHS_TABLE . '` ADD COLUMN `nb_photos` INT UNSIGNED NULL;');
  }
  if (!isset($cols['changed_at']))
  {
    // 1.8.6 : date du dernier changement de chemin ou de statut, et statut d'avant (NULL = tag nouveau),
    // pour le bilan affiché à la fin d'une indexation
    pwg_query('ALTER TABLE `' . TAGMANAGER_PATHS_TABLE . '` ADD COLUMN `changed_at` INT UNSIGNED NULL, ADD COLUMN `prev_status` VARCHAR(16) NULL;');
  }
}

/**
 * Bilan d'une indexation commencée à $run : chemins changés, nouveaux tags rangés, tags relus restés sans chemin.
 * @return array changed, placed, flat
 */
function tagmanager_index_report($run)
{
  $run = intval($run);
  $row = pwg_db_fetch_assoc(pwg_query('
SELECT
  SUM(p.changed_at >= ' . $run . ' AND p.status = \'ok\' AND p.prev_status IS NOT NULL) AS changed,
  SUM(p.changed_at >= ' . $run . ' AND p.status = \'ok\' AND p.prev_status IS NULL) AS placed,
  SUM(p.indexed_at >= ' . $run . ' AND p.status <> \'ok\') AS flat
  FROM ' . TAGMANAGER_PATHS_TABLE . ' p
    JOIN ' . TAGS_TABLE . ' t ON t.id = p.tag_id
  WHERE p.kind = \'tag\' AND ' . tagmanager_sql_not_reserved('p.path', 'p.tag_id') . '
;'));
  return array('changed' => (int)$row['changed'], 'placed' => (int)$row['placed'], 'flat' => (int)$row['flat']);
}

/** Dates des dernières indexations : array('update' => horodatage, 'full' => horodatage). */
function tagmanager_index_dates()
{
  global $conf;
  $d = isset($conf['tag_manager_index_dates']) ? json_decode($conf['tag_manager_index_dates'], true) : null;
  return array(
    'update' => (is_array($d) && !empty($d['update'])) ? (int)$d['update'] : 0,
    'full' => (is_array($d) && !empty($d['full'])) ? (int)$d['full'] : 0,
  );
}

function tagmanager_index_dates_save($mode, $ts)
{
  $d = tagmanager_index_dates();
  $d['update'] = $ts; // une indexation complète met aussi l'index à jour
  if ($mode === 'full')
  {
    $d['full'] = $ts;
  }
  conf_update_param('tag_manager_index_dates', json_encode($d), true);
}

/**
 * Tags à relire par une mise à jour, avec la raison de chacun (première raison trouvée) :
 *   new     : tag jamais indexé ;
 *   count   : nombre de photos différent de la dernière indexation (photos ajoutées, supprimées, tag posé
 *             ou retiré dans Piwigo) ; aussi un tag indexé avant 1.8.4 (nombre inconnu) ;
 *   file    : le fichier de la photo témoin a changé (dans Piwigo : images.lastmodified ; sur le disque :
 *             date du fichier), ou cette photo n'existe plus ;
 *   nopath  : tag sans chemin (à plat, fichier introuvable) dont une photo a changé dans Piwigo.
 * @return array todo (identifiants), reasons (raison => nombre), all (tags examinés)
 */
function tagmanager_index_plan_update()
{
  @set_time_limit(300);
  tagmanager_ensure_paths_columns();
  $out = array('todo' => array(), 'reasons' => array('new' => 0, 'count' => 0, 'file' => 0, 'nopath' => 0), 'all' => 0);

  $nopath_changed = array_flip(query2array('
SELECT DISTINCT it.tag_id
  FROM ' . IMAGE_TAG_TABLE . ' it
    JOIN ' . IMAGES_TABLE . ' i ON i.id = it.image_id
    JOIN ' . TAGMANAGER_PATHS_TABLE . ' p ON p.tag_id = it.tag_id AND p.kind = \'tag\'
  WHERE p.status <> \'ok\' AND UNIX_TIMESTAMP(i.lastmodified) > p.indexed_at
;', null, 'tag_id'));

  $res = pwg_query('
SELECT t.id, n.nb, p.status, p.nb_photos, p.indexed_at, p.sample_image_id,
       si.id AS sid, si.path AS spath, UNIX_TIMESTAMP(si.lastmodified) AS smod
  FROM ' . TAGS_TABLE . ' t
    JOIN (SELECT tag_id, COUNT(*) AS nb FROM ' . IMAGE_TAG_TABLE . ' GROUP BY tag_id) n ON n.tag_id = t.id
    LEFT JOIN ' . TAGMANAGER_PATHS_TABLE . ' p ON p.tag_id = t.id AND p.kind = \'tag\'
    LEFT JOIN ' . IMAGES_TABLE . ' si ON si.id = p.sample_image_id
  ORDER BY t.id
;');
  while ($r = pwg_db_fetch_assoc($res))
  {
    $out['all']++;
    $reason = null;
    if ($r['status'] === null)
    {
      $reason = 'new';
    }
    elseif ($r['nb_photos'] === null || (int)$r['nb_photos'] !== (int)$r['nb'])
    {
      $reason = 'count';
    }
    elseif ($r['status'] === 'ok')
    {
      if ($r['sid'] === null)
      {
        $reason = 'file'; // photo témoin supprimée (ou inconnue)
      }
      elseif ((int)$r['smod'] > (int)$r['indexed_at'])
      {
        $reason = 'file';
      }
      else
      {
        $mtime = @filemtime(PHPWG_ROOT_PATH . $r['spath']);
        if ($mtime !== false && $mtime > (int)$r['indexed_at'])
        {
          $reason = 'file';
        }
      }
    }
    elseif (isset($nopath_changed[$r['id']]))
    {
      $reason = 'nopath';
    }
    if ($reason !== null)
    {
      $out['todo'][] = (int)$r['id'];
      $out['reasons'][$reason]++;
    }
  }
  return $out;
}

/**
 * Début d'une indexation.
 * @param string $mode 'update' (mise à jour) ou 'full' (tout réindexer)
 * @return array run, mode, total (tags à lire), all (tags examinés), reasons (mise à jour seulement)
 */
function tagmanager_index_start($mode)
{
  tagmanager_ensure_paths_columns();
  $run = time();
  if ($mode !== 'update')
  {
    conf_delete_param('tag_manager_index_todo');
    $n = tagmanager_count_index_tags();
    return array('run' => $run, 'mode' => 'full', 'total' => $n, 'all' => $n);
  }
  // Première mise à jour après 1.8.4 : aucune date enregistrée ; la dernière indexation (complète, avant
  // 1.8.4) est la date la plus récente de l'index, lue AVANT que cette mise à jour ne la change
  $d = tagmanager_index_dates();
  if (!$d['full'])
  {
    $sum = tagmanager_index_summary();
    if ($sum['last'])
    {
      conf_update_param('tag_manager_index_dates', json_encode(array('update' => $d['update'], 'full' => (int)$sum['last'])), true);
    }
  }
  $p = tagmanager_index_plan_update();
  conf_update_param('tag_manager_index_todo', implode(',', $p['todo']), true);
  return array('run' => $run, 'mode' => 'update', 'total' => count($p['todo']), 'all' => $p['all'], 'reasons' => $p['reasons']);
}

/** Liste des tags à relire de la mise à jour en cours. */
function tagmanager_index_todo()
{
  global $conf;
  $s = isset($conf['tag_manager_index_todo']) ? trim($conf['tag_manager_index_todo']) : '';
  return ($s === '') ? array() : array_map('intval', explode(',', $s));
}

/** Forme de comparaison d'un nom : comme Piwigo, sans casse ni accents (voir names.php). */
function tagmanager_norm($s)
{
  return tagmanager_fold($s);
}

/** Tags examinés par l'indexation : tous ceux qui ont au moins une photo (registre de visages compris). */
function tagmanager_count_index_tags()
{
  $row = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*)
  FROM ' . TAGS_TABLE . ' t
  WHERE EXISTS (SELECT 1 FROM ' . IMAGE_TAG_TABLE . ' it WHERE it.tag_id = t.id)
;'));
  return (int)$row[0];
}

/** Tags qui ont au moins une photo, hors tags de visage. */
function tagmanager_count_used_tags()
{
  $row = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*)
  FROM ' . TAGS_TABLE . ' t
  WHERE EXISTS (SELECT 1 FROM ' . IMAGE_TAG_TABLE . ' it WHERE it.tag_id = t.id)' . tagmanager_sql_not_face('t.id') . '
;'));
  return (int)$row[0];
}

/**
 * Tags créés dans Piwigo et attachés à aucune photo, hors tags de visage : aucun fichier à lire,
 * l'indexation ne peut pas leur trouver de chemin. Ceux qui en ont un (créés depuis « Gérer les tags »,
 * ou vidés de leurs photos) restent à leur place ; les autres sont regroupés sous « (sans photo) ».
 */
function tagmanager_count_nophoto_tags()
{
  $n = 0;
  $res = pwg_query('
SELECT t.id, t.name, p.path
  FROM ' . TAGS_TABLE . ' t
    LEFT JOIN ' . TAGMANAGER_PATHS_TABLE . ' p ON p.tag_id = t.id AND p.kind = \'tag\' AND p.status = \'ok\'
  WHERE NOT EXISTS (SELECT 1 FROM ' . IMAGE_TAG_TABLE . ' it WHERE it.tag_id = t.id)
    AND p.tag_id IS NULL
;');
  while ($r = pwg_db_fetch_assoc($res))
  {
    if (!tagmanager_is_reserved($r['id'], $r['name'], $r['path']))
    {
      $n++;
    }
  }
  return $n;
}

function tagmanager_upsert_tag_path($tag_id, $path, $image_id, $status, $now)
{
  pwg_query('
INSERT INTO ' . TAGMANAGER_PATHS_TABLE . ' (kind, tag_id, path, sample_image_id, status, indexed_at, changed_at)
VALUES (\'tag\', ' . intval($tag_id) . ', \'' . pwg_db_real_escape_string($path) . '\', '
  . ($image_id ? intval($image_id) : 'NULL') . ', \'' . pwg_db_real_escape_string($status) . '\', ' . intval($now) . ', ' . intval($now) . ')
ON DUPLICATE KEY UPDATE
  prev_status = IF(BINARY path <> BINARY VALUES(path) OR status <> VALUES(status), status, prev_status),
  changed_at = IF(BINARY path <> BINARY VALUES(path) OR status <> VALUES(status), VALUES(changed_at), changed_at),
  path = VALUES(path),
  sample_image_id = VALUES(sample_image_id),
  status = VALUES(status),
  indexed_at = VALUES(indexed_at)
;');
}

/** Table des homonymes : un tag normal dont le nom est aussi celui d'une personne (Personnes|nom). */
function tagmanager_ensure_homonym_table()
{
  pwg_query('
CREATE TABLE IF NOT EXISTS `' . TAGMANAGER_HOMONYMS_TABLE . '` (
  `tag_id`          INT UNSIGNED NOT NULL,
  `person_path`     VARCHAR(500) NOT NULL,
  `sample_image_id` INT UNSIGNED NULL,
  `indexed_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
}

function tagmanager_record_homonym($tag_id, $person_path, $image_id, $now)
{
  pwg_query('
INSERT INTO ' . TAGMANAGER_HOMONYMS_TABLE . ' (tag_id, person_path, sample_image_id, indexed_at)
VALUES (' . intval($tag_id) . ', \'' . pwg_db_real_escape_string($person_path) . '\', ' . ($image_id ? intval($image_id) : 'NULL') . ', ' . intval($now) . ')
ON DUPLICATE KEY UPDATE person_path = VALUES(person_path), sample_image_id = VALUES(sample_image_id), indexed_at = VALUES(indexed_at)
;');
}

/**
 * Tags dont le nom est celui d'un cadre de visage (régions MWG / Microsoft) dans au moins un fichier lu.
 * C'est la preuve qu'un nom est une personne, indépendante de la racine de hiérarchie et du réglage
 * « Racines réservées » (voir tagmanager_face_registry_overridden()). Table remplie par l'indexation.
 */
function tagmanager_ensure_faces_table()
{
  static $done = false;
  if ($done)
  {
    return;
  }
  $done = true;
  pwg_query('
CREATE TABLE IF NOT EXISTS `' . TAGMANAGER_FACES_TABLE . '` (
  `tag_id`          INT UNSIGNED NOT NULL,
  `sample_image_id` INT UNSIGNED NULL,
  `indexed_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
}

function tagmanager_record_face($tag_id, $image_id, $now)
{
  pwg_query('
INSERT INTO ' . TAGMANAGER_FACES_TABLE . ' (tag_id, sample_image_id, indexed_at)
VALUES (' . intval($tag_id) . ', ' . ($image_id ? intval($image_id) : 'NULL') . ', ' . intval($now) . ')
ON DUPLICATE KEY UPDATE sample_image_id = VALUES(sample_image_id), indexed_at = VALUES(indexed_at)
;');
}

/** Le tag a-t-il déjà un chemin valide HORS des racines réservées ? */
function tagmanager_has_normal_path($tag_id)
{
  $r = pwg_db_fetch_assoc(pwg_query('SELECT path FROM ' . TAGMANAGER_PATHS_TABLE . ' WHERE kind = \'tag\' AND tag_id = ' . intval($tag_id) . ' AND status = \'ok\';'));
  return $r && !tagmanager_is_reserved_root($r['path']);
}

/**
 * Traite un lot de tags (ordre croissant des identifiants, au-delà de $last_id).
 *
 * @param int $last_id dernier identifiant de tag déjà traité (0 pour commencer)
 * @param int $run     horodatage de début d'indexation (sert à repérer les tags déjà renseignés)
 * @param int $limit   nombre de tags examinés par lot
 * @return array processed, last_id, done, ok, flat, nofile, reads
 */
function tagmanager_index_batch($last_id, $run, $limit = 20, $mode = 'full')
{
  @set_time_limit(60);
  tagmanager_ensure_paths_columns();
  $update = ($mode === 'update');
  $todo = $update ? tagmanager_index_todo() : array();

  $by_name = array();
  $name_of = array();
  $res = pwg_query('SELECT id, name FROM ' . TAGS_TABLE . ';');
  while ($r = pwg_db_fetch_assoc($res))
  {
    $by_name[tagmanager_norm($r['name'])] = (int)$r['id'];
    $name_of[(int)$r['id']] = $r['name'];
  }

  // Les tags du registre de visages de face_tag sont aussi lus : ce registre n'est jamais nettoyé (un nom
  // qui a servi une fois de visage y reste), seuls les fichiers disent si le tag est une personne
  // (Personnes|…) ou un tag normal (voir tagmanager_face_registry_overridden()).
  $tags = query2array('
SELECT t.id, t.name
  FROM ' . TAGS_TABLE . ' t
  WHERE t.id > ' . intval($last_id) . '
    AND EXISTS (SELECT 1 FROM ' . IMAGE_TAG_TABLE . ' it WHERE it.tag_id = t.id)'
    . ($update ? ' AND t.id IN (' . (empty($todo) ? '0' : implode(',', $todo)) . ')' : '') . '
  ORDER BY t.id
  LIMIT ' . intval($limit) . '
;');

  $out = array('processed' => 0, 'last_id' => (int)$last_id, 'done' => false, 'ok' => 0, 'flat' => 0, 'nofile' => 0, 'reads' => 0);
  $now = time();
  $deep_ids = tagmanager_face_registry_unflagged_ids(); // candidats aux homonymes : analyse en profondeur

  tagmanager_ensure_homonym_table();
  tagmanager_ensure_faces_table();
  if (intval($last_id) === 0 && $update)
  {
    // Mise à jour : seuls les tags relus perdent leurs homonymes (retrouvés par la lecture qui suit ou par
    // le contrôle des visages) ; les « noms vus en cadre » sont gardés (une personne vue dans un fichier non
    // relu doit le rester), sauf ceux des tags supprimés de Piwigo
    if (!empty($todo))
    {
      foreach (array_chunk($todo, 500) as $chunk)
      {
        pwg_query('DELETE FROM ' . TAGMANAGER_HOMONYMS_TABLE . ' WHERE tag_id IN (' . implode(',', $chunk) . ');');
      }
    }
    pwg_query('
DELETE h FROM ' . TAGMANAGER_HOMONYMS_TABLE . ' h LEFT JOIN ' . TAGS_TABLE . ' t ON t.id = h.tag_id WHERE t.id IS NULL
;');
    pwg_query('
DELETE f FROM ' . TAGMANAGER_FACES_TABLE . ' f LEFT JOIN ' . TAGS_TABLE . ' t ON t.id = f.tag_id WHERE t.id IS NULL
;');
    pwg_query('
DELETE p FROM ' . TAGMANAGER_PATHS_TABLE . ' p
  LEFT JOIN ' . TAGS_TABLE . ' t ON t.id = p.tag_id
  WHERE p.kind = \'tag\' AND t.id IS NULL
;');
  }
  elseif (intval($last_id) === 0)
  {
    pwg_query('DELETE FROM ' . TAGMANAGER_HOMONYMS_TABLE . ';'); // début d'une indexation : on repart de zéro
    pwg_query('DELETE FROM ' . TAGMANAGER_FACES_TABLE . ';');
    // chemins de tags supprimés de Piwigo (à la main, ou par une synchronisation après suppression de photos) :
    // la ligne resterait sinon pour toujours dans l'index (cas « 2023 ISLANDE » sur phototest, 15/11/2026)
    pwg_query('
DELETE p FROM ' . TAGMANAGER_PATHS_TABLE . ' p
  LEFT JOIN ' . TAGS_TABLE . ' t ON t.id = p.tag_id
  WHERE p.kind = \'tag\' AND t.id IS NULL
;');
  }

  foreach ($tags as $tag)
  {
    $out['processed']++;
    $out['last_id'] = (int)$tag['id'];

    // Déjà renseigné pendant cette indexation (par un fichier lu pour un autre tag)
    $known = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*) FROM ' . TAGMANAGER_PATHS_TABLE . '
  WHERE tag_id = ' . intval($tag['id']) . ' AND indexed_at >= ' . intval($run) . '
;'));
    $deep = in_array((int)$tag['id'], $deep_ids, true);
    if ((int)$known[0] > 0 && !$deep)
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
    $take = min($deep ? 40 : TAGMANAGER_SAMPLE, $count);
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
      $meta = tagmanager_read_hierarchy_faces(tagmanager_resolve_path($img['path']));
      if ($meta === null)
      {
        continue; // fichier introuvable ou illisible : on essaie la photo suivante
      }
      $paths = $meta['paths'];

      $read_any = true;
      $out['reads']++;
      $sample = $img['id'];

      // Cadres de visage du fichier : leur nom est celui d'une personne, quelle que soit la racine
      $faces = array();
      foreach ($meta['faces'] as $fn)
      {
        $fk = tagmanager_norm($fn);
        $faces[$fk] = true;
        if (isset($by_name[$fk]))
        {
          tagmanager_record_face($by_name[$fk], $img['id'], $now);
        }
      }

      // Chemins du fichier, par nom : « normal » (hors racines réservées) et « personne » (Personnes|...)
      $per_leaf = array();
      foreach ($paths as $p)
      {
        $leaf = tagmanager_norm(end($p));
        if (isset($by_name[$leaf]) && count($p) > 0)
        {
          $p[count($p) - 1] = $name_of[$by_name[$leaf]]; // orthographe de Piwigo (le fichier peut différer d'un accent ou d'une majuscule)
          $kind = tagmanager_is_reserved_root(implode('|', $p)) ? 'person' : 'normal';
          $per_leaf[$leaf][$kind] = implode('|', $p);
          if ($kind === 'normal')
          {
            $per_leaf[$leaf]['normals'][implode('|', $p)] = true;
          }
        }
      }
      foreach ($per_leaf as $leaf => $k)
      {
        $tid = $by_name[$leaf];
        if (isset($k['normal']))
        {
          tagmanager_upsert_tag_path($tid, $k['normal'], $img['id'], 'ok', $now);
          if (isset($k['person']))
          {
            tagmanager_record_homonym($tid, $k['person'], $img['id'], $now); // le nom est aussi celui d'une personne
          }
          elseif (isset($faces[$leaf]) && count($k['normals']) > 1)
          {
            // Cadre de visage à ce nom et plusieurs chemins, aucun sous une racine réservée (ex. People|Paul et
            // Lieux|Paul alors que « People » n'est pas dans le réglage) : on ne sait pas lequel est la personne.
            // Enregistré avec les chemins candidats (« * » puis chemins séparés par une tabulation).
            $cand = array_slice(array_keys($k['normals']), 0, 3);
            while (count($cand) > 1 && strlen(implode("\t", $cand)) > 490) { array_pop($cand); } // colonne VARCHAR(500)
            tagmanager_record_homonym($tid, '*' . implode("\t", $cand), $img['id'], $now);
          }
          if ((string)$leaf === $wanted) { $found = true; }
        }
        elseif (tagmanager_has_normal_path($tid))
        {
          // Fichier où seule la personne porte ce nom : on ne touche pas au chemin du tag normal
          tagmanager_record_homonym($tid, $k['person'], $img['id'], $now);
        }
        else
        {
          tagmanager_upsert_tag_path($tid, $k['person'], $img['id'], 'ok', $now); // tag de personne pur
          if ((string)$leaf === $wanted) { $found = true; }
        }
      }

      if ($found && !$deep)
      {
        break; // sauf analyse en profondeur : on lit tous les fichiers choisis pour repérer une personne homonyme
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

  // Nombre de photos de chaque tag examiné : la mise à jour suivante relit le tag s'il change
  $seen = array();
  foreach ($tags as $tag)
  {
    $seen[] = (int)$tag['id'];
  }
  if (!empty($seen))
  {
    pwg_query('
UPDATE ' . TAGMANAGER_PATHS_TABLE . ' p
  SET p.nb_photos = (SELECT COUNT(*) FROM ' . IMAGE_TAG_TABLE . ' it WHERE it.tag_id = p.tag_id)
  WHERE p.kind = \'tag\' AND p.tag_id IN (' . implode(',', $seen) . ')
;');
  }

  $out['done'] = count($tags) < $limit;
  if ($out['done'])
  {
    tagmanager_index_dates_save($update ? 'update' : 'full', (int)$run);
    conf_delete_param('tag_manager_index_todo');
    $out['report'] = tagmanager_index_report($run);
  }
  return $out;
}

// ---------------------------------------------------------------------------------------------
// Contrôle des visages (incrémental) : un tag de visage attaché dans Piwigo à une photo dont le
// fichier n'a ni cadre de visage ni chemin à ce nom (ajouté avec l'éditeur de tags de Piwigo, qui
// n'écrit rien dans le fichier : cas « Sarah » du 20/11/2026). L'indexation ne lit que quelques
// photos par tag : on garde chaque couple photo + tag de visage vérifié, et on ne relit que les
// nouveaux couples et les photos dont Piwigo a resynchronisé les métadonnées depuis.
// Statuts : face (cadre), path (chemin de personne sans cadre), normal (chemin ordinaire : homonyme),
// none (rien dans le fichier), unreadable, ignored (choisi par l'utilisateur).
// ---------------------------------------------------------------------------------------------

define('TAGMANAGER_FACE_CHECK_BATCH', 25);
// Révision du contrôle : un couple contrôlé par une révision plus ancienne est relu (1 = 1.7a.2 à 1.7a.5 ;
// 2 = 1.7a.6, relevé des cadres sans tag ; 4 = 1.7a.7, un cadre doit avoir une zone : les noms Microsoft sans rectangle ne comptent plus). Augmenter ce numéro quand le contrôle relève quelque chose de nouveau.
define('TAGMANAGER_FACE_CHECK_REV', 4);

function tagmanager_ensure_face_checks_table()
{
  static $done = false;
  if ($done)
  {
    return;
  }
  $done = true;
  pwg_query('
CREATE TABLE IF NOT EXISTS `' . TAGMANAGER_FACE_CHECKS_TABLE . '` (
  `image_id`   INT UNSIGNED NOT NULL,
  `tag_id`     INT UNSIGNED NOT NULL,
  `status`     VARCHAR(10) NOT NULL DEFAULT \'none\',
  `checked_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `frames`     VARCHAR(255) NOT NULL DEFAULT \'\',
  `rev`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`image_id`, `tag_id`),
  KEY `tag` (`tag_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
  // colonnes ajoutées après 1.7a.2 (la table d'une version de développement peut ne pas les avoir) ;
  // un couple sans révision (rev = 0) est relu au contrôle suivant
  $cols = query2array('SHOW COLUMNS FROM `' . TAGMANAGER_FACE_CHECKS_TABLE . '`;', 'Field', 'Field');
  if (!isset($cols['frames']))
  {
    pwg_query('ALTER TABLE `' . TAGMANAGER_FACE_CHECKS_TABLE . '` ADD COLUMN `frames` VARCHAR(255) NOT NULL DEFAULT \'\';');
  }
  if (!isset($cols['rev']))
  {
    pwg_query('ALTER TABLE `' . TAGMANAGER_FACE_CHECKS_TABLE . '` ADD COLUMN `rev` TINYINT UNSIGNED NOT NULL DEFAULT 0;');
  }
}

/** Tags contrôlés : visages du registre de face_tag (hors ceux que les fichiers rangent ailleurs) et noms vus en cadre. */
function tagmanager_face_check_tag_ids()
{
  $ids = array_flip(tagmanager_face_tag_ids());
  foreach (tagmanager_face_seen_ids() as $id => $x)
  {
    $ids[$id] = true;
  }
  return array_keys($ids);
}

/**
 * 1.8.6 : condition SQL « vraie photo » (alias $a = images). Les vidéos, PDF, TIFF… (fichier hors
 * $conf['picture_ext'], ou image représentative dans pwg_representative/) ne sont pas contrôlés : le fichier
 * lu serait la vidéo ou le PDF, qui n'a ni cadre ni mot-clé lisible, et ils apparaissaient en « tag Piwigo seul ».
 */
function tagmanager_face_check_photo_sql($a = 'i')
{
  global $conf;
  $ext = array();
  foreach ((isset($conf['picture_ext']) ? $conf['picture_ext'] : array('jpg', 'jpeg', 'png', 'gif', 'webp')) as $e)
  {
    $ext[] = '\'' . pwg_db_real_escape_string(strtolower($e)) . '\'';
  }
  return $a . '.representative_ext IS NULL AND LOWER(SUBSTRING_INDEX(' . $a . '.path, \'.\', -1)) IN (' . implode(',', $ext) . ')';
}

/** Condition SQL « couple à (re)lire » (alias it = image_tag, i = images, c = contrôles). */
function tagmanager_face_check_pending_sql(array $tag_ids)
{
  return 'it.tag_id IN (' . implode(',', $tag_ids) . ')
    AND ' . tagmanager_face_check_photo_sql('i') . '
    AND (c.image_id IS NULL
      OR (c.status <> \'ignored\' AND c.rev < ' . TAGMANAGER_FACE_CHECK_REV . ')
      OR (c.status <> \'ignored\' AND UNIX_TIMESTAMP(i.lastmodified) > c.checked_at))';
  // images.lastmodified (date ET heure, mis à jour par MySQL à chaque modification de la ligne : synchronisation des
  // métadonnées, édition…) ; date_metadata_update ne garde que le JOUR : une synchronisation faite le jour même du
  // contrôle n'était pas vue (cas « Georges Algérie », 20/11/2026). Une photo simplement vue (compteur « hit ») est
  // aussi relue : coût faible.
}

/**
 * 1.8.3 : fichiers modifiés hors de Piwigo (digiKam, Synology Drive…) depuis leur dernier contrôle. Piwigo ne
 * change images.lastmodified qu'à sa propre synchronisation : sans elle, un fichier corrigé ailleurs gardait
 * son ancien résultat (cas réel : cadres « Chevalier » renommés « Chevallier » dans digiKam, 22/11/2026).
 * La date du fichier sur le disque est comparée à celle du contrôle ; un couple concerné est remis à la
 * révision 0, donc relu par le contrôle qui suit. Une lecture de date par photo ; arrêt au bout de
 * TAGMANAGER_FACE_STAT_BUDGET secondes, la suite au contrôle suivant (curseur tag_manager_face_stat_cursor).
 * @return int nombre de photos remises à relire
 */
define('TAGMANAGER_FACE_STAT_BUDGET', 20);
function tagmanager_face_check_mark_changed_files()
{
  global $conf;
  $cursor = isset($conf['tag_manager_face_stat_cursor']) ? (int)$conf['tag_manager_face_stat_cursor'] : 0;
  $rows = query2array('
SELECT c.image_id, MIN(c.checked_at) AS checked_at, i.path
  FROM ' . TAGMANAGER_FACE_CHECKS_TABLE . ' c JOIN ' . IMAGES_TABLE . ' i ON i.id = c.image_id
  WHERE c.status <> \'ignored\' AND c.rev >= ' . TAGMANAGER_FACE_CHECK_REV . '
  GROUP BY c.image_id, i.path
  ORDER BY (c.image_id <= ' . $cursor . '), c.image_id
;');
  $start = microtime(true);
  $changed = array();
  $last = 0;
  $complete = true;
  foreach ($rows as $r)
  {
    if (microtime(true) - $start > TAGMANAGER_FACE_STAT_BUDGET)
    {
      $complete = false;
      break;
    }
    $last = (int)$r['image_id'];
    $mtime = @filemtime(PHPWG_ROOT_PATH . $r['path']);
    if ($mtime !== false && $mtime > (int)$r['checked_at'])
    {
      $changed[] = $last;
    }
  }
  conf_update_param('tag_manager_face_stat_cursor', $complete ? 0 : $last, true);
  foreach (array_chunk($changed, 500) as $chunk)
  {
    pwg_query('
UPDATE ' . TAGMANAGER_FACE_CHECKS_TABLE . '
  SET rev = 0
  WHERE status <> \'ignored\' AND image_id IN (' . implode(',', $chunk) . ')
;');
  }
  return count($changed);
}

/** Chemin de personne à afficher pour un homonyme trouvé par le contrôle. */
function tagmanager_face_check_person_path($tag_id, $name)
{
  $r = pwg_db_fetch_assoc(pwg_query('SELECT path FROM ' . TAGMANAGER_PATHS_TABLE . ' WHERE kind = \'tag\' AND tag_id = ' . intval($tag_id) . ';'));
  if ($r && tagmanager_is_reserved_root($r['path']))
  {
    return $r['path'];
  }
  $roots = tagmanager_reserved_roots();
  return $roots[0] . '|' . $name;
}

/**
 * Début du contrôle (après l'indexation des tags) : retire les couples qui n'existent plus, réinscrit
 * les homonymes déjà connus (la table des homonymes est vidée au début de chaque indexation).
 * @return array total (photos à lire)
 */
function tagmanager_face_check_start()
{
  tagmanager_ensure_face_checks_table();
  tagmanager_ensure_frame_issues_table(); // avant le comptage : sa création relance le contrôle de toutes les photos
  tagmanager_ensure_homonym_table();
  pwg_query('
DELETE f FROM ' . TAGMANAGER_FRAME_ISSUES_TABLE . ' f
  LEFT JOIN ' . IMAGES_TABLE . ' i ON i.id = f.image_id
  WHERE i.id IS NULL OR NOT (' . tagmanager_face_check_photo_sql('i') . ')
;');
  // 1.8.6 : résultats des vidéos, PDF… contrôlés par les versions précédentes
  pwg_query('
DELETE c FROM ' . TAGMANAGER_FACE_CHECKS_TABLE . ' c
  JOIN ' . IMAGES_TABLE . ' i ON i.id = c.image_id
  WHERE NOT (' . tagmanager_face_check_photo_sql('i') . ')
;');
  $ids = tagmanager_face_check_tag_ids();
  if (empty($ids))
  {
    pwg_query('DELETE FROM ' . TAGMANAGER_FACE_CHECKS_TABLE . ';');
    return array('total' => 0);
  }
  pwg_query('DELETE FROM ' . TAGMANAGER_FACE_CHECKS_TABLE . ' WHERE tag_id NOT IN (' . implode(',', $ids) . ');');
  pwg_query('
DELETE c FROM ' . TAGMANAGER_FACE_CHECKS_TABLE . ' c
  LEFT JOIN ' . IMAGE_TAG_TABLE . ' it ON it.image_id = c.image_id AND it.tag_id = c.tag_id
  WHERE it.image_id IS NULL
;');
  tagmanager_face_check_mark_changed_files();
  // 1.8.7 : les couples en anomalie (« visage sans cadre », « tag Piwigo seul ») sont relus à chaque contrôle.
  // Renommer un tag dans Piwigo ne change ni le fichier ni la photo : sans cela, l'ancien résultat restait
  // (cas P1240001.JPG, tag renommé « Anne-Marie »). Révision 0 = relu une fois par ce contrôle.
  pwg_query('UPDATE ' . TAGMANAGER_FACE_CHECKS_TABLE . ' SET rev = 0 WHERE status IN (\'none\', \'path\');');
  tagmanager_frame_issues_refresh_orphans(); // 1.8.9
  $now = time();
  foreach (query2array('
SELECT c.tag_id, MIN(c.image_id) AS image_id, t.name
  FROM ' . TAGMANAGER_FACE_CHECKS_TABLE . ' c JOIN ' . TAGS_TABLE . ' t ON t.id = c.tag_id
  WHERE c.status = \'normal\'
  GROUP BY c.tag_id, t.name
;') as $h)
  {
    tagmanager_record_homonym($h['tag_id'], tagmanager_face_check_person_path($h['tag_id'], $h['name']), $h['image_id'], $now);
  }
  $row = pwg_db_fetch_row(pwg_query('
SELECT COUNT(DISTINCT it.image_id)
  FROM ' . IMAGE_TAG_TABLE . ' it
    JOIN ' . IMAGES_TABLE . ' i ON i.id = it.image_id
    LEFT JOIN ' . TAGMANAGER_FACE_CHECKS_TABLE . ' c ON c.image_id = it.image_id AND c.tag_id = it.tag_id
  WHERE ' . tagmanager_face_check_pending_sql($ids) . '
;'));
  return array('total' => (int)$row[0]);
}

/**
 * Lit les fichiers d'un lot de photos à contrôler et enregistre le statut de chaque couple.
 * @return array processed, reads, none (couples sans rien dans le fichier), done
 */
function tagmanager_face_check_batch()
{
  @set_time_limit(60);
  tagmanager_ensure_face_checks_table();
  tagmanager_ensure_homonym_table();
  $out = array('processed' => 0, 'reads' => 0, 'none' => 0, 'done' => true,
    'path_new' => 0, 'path_gone' => 0, 'none_new' => 0, 'none_gone' => 0); // 1.8.6 : changements, pour le bilan
  $ids = tagmanager_face_check_tag_ids();
  if (empty($ids))
  {
    return $out;
  }
  $imgs = query2array('
SELECT DISTINCT it.image_id
  FROM ' . IMAGE_TAG_TABLE . ' it
    JOIN ' . IMAGES_TABLE . ' i ON i.id = it.image_id
    LEFT JOIN ' . TAGMANAGER_FACE_CHECKS_TABLE . ' c ON c.image_id = it.image_id AND c.tag_id = it.tag_id
  WHERE ' . tagmanager_face_check_pending_sql($ids) . '
  ORDER BY it.image_id
  LIMIT ' . TAGMANAGER_FACE_CHECK_BATCH . '
;', null, 'image_id');
  if (empty($imgs))
  {
    return $out;
  }
  $out['done'] = count($imgs) < TAGMANAGER_FACE_CHECK_BATCH;
  $now = time();

  // Tous les tags de Piwigo (forme de comparaison) et tous les tags de chaque photo du lot : pour relever les
  // cadres de visage dont le nom n'a pas de tag dans Piwigo, ou dont le tag n'est pas sur la photo
  tagmanager_ensure_frame_issues_table();
  $tag_by_fold = array();
  $res = pwg_query('SELECT id, name FROM ' . TAGS_TABLE . ';');
  while ($t = pwg_db_fetch_assoc($res))
  {
    $tag_by_fold[tagmanager_fold($t['name'])] = (int)$t['id'];
  }
  $attached = array();
  foreach (query2array('SELECT image_id, tag_id FROM ' . IMAGE_TAG_TABLE . ' WHERE image_id IN (' . implode(',', $imgs) . ');') as $a)
  {
    $attached[(int)$a['image_id']][(int)$a['tag_id']] = true;
  }

  // Tags de visage de chaque photo du lot (les couples déjà ignorés restent ignorés)
  $pairs = array();
  foreach (query2array('
SELECT it.image_id, it.tag_id, t.name, c.status
  FROM ' . IMAGE_TAG_TABLE . ' it
    JOIN ' . TAGS_TABLE . ' t ON t.id = it.tag_id
    LEFT JOIN ' . TAGMANAGER_FACE_CHECKS_TABLE . ' c ON c.image_id = it.image_id AND c.tag_id = it.tag_id
  WHERE it.image_id IN (' . implode(',', $imgs) . ') AND it.tag_id IN (' . implode(',', $ids) . ')
;') as $p)
  {
    $pairs[(int)$p['image_id']][] = $p;
  }

  foreach (query2array('SELECT id, path FROM ' . IMAGES_TABLE . ' WHERE id IN (' . implode(',', $imgs) . ');') as $img)
  {
    $out['processed']++;
    $meta = tagmanager_read_hierarchy_faces(tagmanager_resolve_path($img['path']));
    if ($meta !== null)
    {
      $out['reads']++;
    }
    $values = array();
    foreach (isset($pairs[(int)$img['id']]) ? $pairs[(int)$img['id']] : array() as $p)
    {
      if ($p['status'] === 'ignored')
      {
        $st = 'ignored';
      }
      elseif ($meta === null)
      {
        $st = 'unreadable';
      }
      else
      {
        $st = 'none';
        foreach ($meta['faces'] as $fn)
        {
          if (tagmanager_same_name($fn, $p['name'])) { $st = 'face'; break; }
        }
        if ($st === 'none')
        {
          foreach ($meta['paths'] as $segs)
          {
            if (!empty($segs) && count($segs) > 1 && tagmanager_same_name(end($segs), $p['name']))
            {
              if (tagmanager_is_reserved_root(implode('|', $segs))) { $st = 'path'; break; }
              $st = 'normal'; // chemin ordinaire : on continue, un chemin de personne l'emporte
            }
          }
        }
      }
      if ($st === 'none') { $out['none']++; }
      foreach (array('path', 'none') as $k)
      {
        if ($p['status'] !== $k && $st === $k) { $out[$k . '_new']++; }
        if ($p['status'] === $k && $st !== $k) { $out[$k . '_gone']++; }
      }
      if ($st === 'normal')
      {
        tagmanager_record_homonym($p['tag_id'], tagmanager_face_check_person_path($p['tag_id'], $p['name']), $img['id'], $now);
      }
      // Cadres présents sur la photo quand aucun ne porte ce nom (affichés dans « À réconcilier » : ex. « @ macareux »)
      $frames = '';
      if ($st === 'none' && $meta !== null && !empty($meta['faces']))
      {
        $list = array_slice($meta['faces'], 0, 10);
        while (count($list) > 1 && strlen(implode("\t", $list)) > 250) { array_pop($list); }
        $frames = substr(implode("\t", $list), 0, 250);
      }
      $values[] = '(' . (int)$img['id'] . ', ' . (int)$p['tag_id'] . ', \'' . $st . '\', ' . $now . ', \'' . pwg_db_real_escape_string($frames) . '\', ' . TAGMANAGER_FACE_CHECK_REV . ')';
    }
    if (!empty($values))
    {
      pwg_query('
INSERT INTO ' . TAGMANAGER_FACE_CHECKS_TABLE . ' (image_id, tag_id, status, checked_at, frames, rev)
VALUES ' . implode(', ', $values) . '
ON DUPLICATE KEY UPDATE status = VALUES(status), checked_at = VALUES(checked_at), frames = VALUES(frames), rev = VALUES(rev)
;');
    }

    // Cadres de visage sans tag Piwigo (ex. « @ macareux ») ou dont le tag n'est pas sur cette photo
    if ($meta !== null)
    {
      pwg_query('DELETE FROM ' . TAGMANAGER_FRAME_ISSUES_TABLE . ' WHERE image_id = ' . (int)$img['id'] . ';');
      $issues = array();
      foreach ($meta['faces'] as $fn)
      {
        $fk = tagmanager_fold($fn);
        $tid = isset($tag_by_fold[$fk]) ? $tag_by_fold[$fk] : 0;
        if ($tid && isset($attached[(int)$img['id']][$tid]))
        {
          continue; // cadre et tag présents : normal
        }
        $issues[$fk] = '(' . (int)$img['id'] . ', \'' . pwg_db_real_escape_string(substr($fn, 0, 190)) . '\', ' . ($tid ? $tid : 'NULL') . ', ' . $now . ', \'\')';
      }
      if (!empty($issues))
      {
        pwg_query('
INSERT IGNORE INTO ' . TAGMANAGER_FRAME_ISSUES_TABLE . ' (image_id, name, tag_id, checked_at, src)
VALUES ' . implode(', ', $issues) . '
;');
      }
    }
  }
  return $out;
}

/**
 * 1.8.9 : photos dont des cadres sont en anomalie mais qui ne portent plus aucun tag de visage contrôlé. Le
 * contrôle ne lit que les photos qui ont un tag de visage : sans cette relecture, leur ligne restait dans
 * « À réconcilier » même après correction (cas réel 166217 « @ macareux » : cadre et tag retirés, ligne toujours là).
 * Relues à chaque contrôle (liste courte) : un tag ajouté ou retiré dans Piwigo ne change pas le fichier.
 * Même relevé que tagmanager_face_check_batch().
 * @return int nombre de photos relues
 */
function tagmanager_frame_issues_refresh_orphans()
{
  $imgs = query2array('
SELECT DISTINCT i.id, i.path
  FROM ' . TAGMANAGER_FRAME_ISSUES_TABLE . ' f
    JOIN ' . IMAGES_TABLE . ' i ON i.id = f.image_id
    LEFT JOIN ' . TAGMANAGER_FACE_CHECKS_TABLE . ' c ON c.image_id = f.image_id
  WHERE c.image_id IS NULL
;');
  if (empty($imgs))
  {
    return 0;
  }
  $ids = array();
  foreach ($imgs as $img)
  {
    $ids[] = (int)$img['id'];
  }
  $tag_by_fold = array();
  $res = pwg_query('SELECT id, name FROM ' . TAGS_TABLE . ';');
  while ($t = pwg_db_fetch_assoc($res))
  {
    $tag_by_fold[tagmanager_fold($t['name'])] = (int)$t['id'];
  }
  $attached = array();
  foreach (query2array('SELECT image_id, tag_id FROM ' . IMAGE_TAG_TABLE . ' WHERE image_id IN (' . implode(',', $ids) . ');') as $a)
  {
    $attached[(int)$a['image_id']][(int)$a['tag_id']] = true;
  }
  $now = time();
  foreach ($imgs as $img)
  {
    $meta = tagmanager_read_hierarchy_faces(tagmanager_resolve_path($img['path']));
    if ($meta === null)
    {
      continue; // fichier illisible : l'ancien relevé est gardé
    }
    pwg_query('DELETE FROM ' . TAGMANAGER_FRAME_ISSUES_TABLE . ' WHERE image_id = ' . (int)$img['id'] . ';');
    $issues = array();
    foreach ($meta['faces'] as $fn)
    {
      $fk = tagmanager_fold($fn);
      $tid = isset($tag_by_fold[$fk]) ? $tag_by_fold[$fk] : 0;
      if ($tid && isset($attached[(int)$img['id']][$tid]))
      {
        continue; // cadre et tag présents : normal
      }
      $issues[$fk] = '(' . (int)$img['id'] . ', \'' . pwg_db_real_escape_string(substr($fn, 0, 190)) . '\', ' . ($tid ? $tid : 'NULL') . ', ' . $now . ', \'\')';
    }
    if (!empty($issues))
    {
      pwg_query('
INSERT IGNORE INTO ' . TAGMANAGER_FRAME_ISSUES_TABLE . ' (image_id, name, tag_id, checked_at, src)
VALUES ' . implode(', ', $issues) . '
;');
    }
  }
  return count($imgs);
}

/**
 * Cadres de visage relevés par le contrôle dont le nom n'a pas de tag dans Piwigo (tag_id NULL : la personne
 * n'apparaît dans aucune recherche) ou dont le tag existe mais n'est pas sur la photo.
 * Table créée par 1.7a.6 : les photos déjà contrôlées sont relues une fois pour la remplir.
 */
function tagmanager_ensure_frame_issues_table()
{
  static $done = false;
  if ($done)
  {
    return;
  }
  $done = true;
  pwg_query('
CREATE TABLE IF NOT EXISTS `' . TAGMANAGER_FRAME_ISSUES_TABLE . '` (
  `image_id`   INT UNSIGNED NOT NULL,
  `name`       VARCHAR(190) NOT NULL,
  `tag_id`     INT UNSIGNED NULL,
  `checked_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `src`        VARCHAR(8) NOT NULL DEFAULT \'\',
  PRIMARY KEY (`image_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
  // colonne « src » (1.7a.7, réservée : origine du relevé ; '' = cadre sans tag)
  $cols = query2array('SHOW COLUMNS FROM `' . TAGMANAGER_FRAME_ISSUES_TABLE . '`;', 'Field', 'Field');
  if (!isset($cols['src']))
  {
    pwg_query('ALTER TABLE `' . TAGMANAGER_FRAME_ISSUES_TABLE . '` ADD COLUMN `src` VARCHAR(8) NOT NULL DEFAULT \'\';');
  }
}

/**
 * Visages pour l'onglet Index (lecture seule) : tags de visage contrôlés, avec le nombre de photos
 * par résultat du dernier contrôle. Avant le premier contrôle, seul le total est connu.
 * @return array liste de array(name, total, face, path, none, ignored, other), triée par nom
 */
function tagmanager_index_faces()
{
  $ids = tagmanager_face_check_tag_ids();
  if (empty($ids))
  {
    return array();
  }
  $out = array();
  foreach (query2array('
SELECT t.id, t.name, (SELECT COUNT(*) FROM ' . IMAGE_TAG_TABLE . ' it JOIN ' . IMAGES_TABLE . ' i ON i.id = it.image_id
    WHERE it.tag_id = t.id AND ' . tagmanager_face_check_photo_sql('i') . ') AS total
  FROM ' . TAGS_TABLE . ' t
  WHERE t.id IN (' . implode(',', $ids) . ')
;') as $t)
  {
    $out[(int)$t['id']] = array('name' => $t['name'], 'total' => (int)$t['total'],
      'face' => 0, 'path' => 0, 'none' => 0, 'ignored' => 0, 'other' => 0);
  }
  $r = pwg_query('SHOW TABLES LIKE \'' . pwg_db_real_escape_string(str_replace('_', '\\_', TAGMANAGER_FACE_CHECKS_TABLE)) . '\';');
  if (pwg_db_num_rows($r) > 0)
  {
    foreach (query2array('
SELECT c.tag_id, c.status, COUNT(*) AS n
  FROM ' . TAGMANAGER_FACE_CHECKS_TABLE . ' c
    JOIN ' . IMAGE_TAG_TABLE . ' it ON it.image_id = c.image_id AND it.tag_id = c.tag_id
  GROUP BY c.tag_id, c.status
;') as $c)
    {
      $k = (int)$c['tag_id'];
      if (!isset($out[$k]))
      {
        continue;
      }
      $st = in_array($c['status'], array('face', 'path', 'none', 'ignored'), true) ? $c['status'] : 'other';
      $out[$k][$st] += (int)$c['n'];
    }
  }
  $out = array_values(array_filter($out, function ($f) { return $f['total'] > 0; }));
  usort($out, function ($a, $b) { return strcasecmp(tagmanager_fold($a['name']), tagmanager_fold($b['name'])); });
  return $out;
}

/** Résumé de l'index pour la page d'administration. */
function tagmanager_index_summary()
{
  $sum = array('total_tags' => tagmanager_count_used_tags(), 'ok' => 0, 'flat' => 0, 'nofile' => 0, 'nophoto' => tagmanager_count_nophoto_tags(), 'last' => 0);

  $res = pwg_query('
SELECT p.status, COUNT(*) AS n, MAX(p.indexed_at) AS last
  FROM ' . TAGMANAGER_PATHS_TABLE . ' p
    JOIN ' . TAGS_TABLE . ' t ON t.id = p.tag_id
  WHERE p.kind = \'tag\' AND ' . tagmanager_sql_not_reserved('p.path', 'p.tag_id') . '
    AND ' . tagmanager_sql_not_name('t.name') . '
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
    JOIN ' . TAGS_TABLE . ' t ON t.id = p.tag_id
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
