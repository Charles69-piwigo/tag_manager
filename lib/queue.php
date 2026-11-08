<?php
/**
 * File d'attente d'écriture des tags : lots reprenables, journal, mise à jour des tags Piwigo.
 *
 * Le navigateur crée un lot (liste de photos avec les tags à ajouter / retirer), puis appelle
 * tagmanager_job_step() jusqu'à épuisement. Tout est en base : une page fermée en cours de route
 * peut reprendre le lot plus tard, et un fichier déjà traité n'est jamais réécrit sans raison.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

include_once(dirname(__FILE__) . '/file_resolver.php');
include_once(dirname(__FILE__) . '/xmp_writer.php');
include_once(dirname(__FILE__) . '/data.php');

define('TAGMANAGER_MAX_ITEMS', 5000);   // photos par lot
define('TAGMANAGER_CLAIM_TTL', 300);    // secondes avant qu'une réservation soit considérée abandonnée

function tagmanager_ensure_tables()
{
  pwg_query('
CREATE TABLE IF NOT EXISTS `' . TAGMANAGER_JOBS_TABLE . '` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `username`   VARCHAR(100) NOT NULL DEFAULT \'\',
  `status`     VARCHAR(12) NOT NULL DEFAULT \'pending\',
  `original`   TINYINT(1) NOT NULL DEFAULT 0,
  `total`      INT UNSIGNED NOT NULL DEFAULT 0,
  `done`       INT UNSIGNED NOT NULL DEFAULT 0,
  `errors`     INT UNSIGNED NOT NULL DEFAULT 0,
  `summary`    VARCHAR(255) NOT NULL DEFAULT \'\',
  PRIMARY KEY (`id`),
  KEY `st` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');

  pwg_query('
CREATE TABLE IF NOT EXISTS `' . TAGMANAGER_JOBFILES_TABLE . '` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`       INT UNSIGNED NOT NULL,
  `image_id`     INT UNSIGNED NOT NULL,
  `status`       VARCHAR(10) NOT NULL DEFAULT \'pending\',
  `claim`        VARCHAR(16) NULL,
  `claimed_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  `ops`          MEDIUMTEXT NOT NULL,
  `message`      VARCHAR(255) NOT NULL DEFAULT \'\',
  `processed_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `job_status` (`job_id`, `status`),
  KEY `claim` (`claim`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');

  // Colonnes ajoutées avec les opérations de structure (la table peut exister sans elles)
  $cols = query2array('SHOW COLUMNS FROM `' . TAGMANAGER_JOBS_TABLE . '`;', 'Field', 'Field');
  if (!isset($cols['kind']))
  {
    pwg_query('ALTER TABLE `' . TAGMANAGER_JOBS_TABLE . '`
  ADD COLUMN `kind` VARCHAR(10) NOT NULL DEFAULT \'tags\',
  ADD COLUMN `final` MEDIUMTEXT NULL;');
  }
}

// ---------------------------------------------------------------------------------------------
// Droits (vérifiés par le serveur, jamais seulement par l'interface)
// ---------------------------------------------------------------------------------------------

/**
 * Niveau de l'utilisateur courant : 0 aucun, 1 étiqueter, 2 gérer (créer), 3 gérer (tout).
 * Webmestre : toujours 3. Les autres cumulent les droits de leur compte et de leurs groupes.
 */
function tagmanager_user_level()
{
  global $user;

  if (is_webmaster())
  {
    return 3;
  }
  if (empty($user['id']))
  {
    return 0;
  }

  $c = tagmanager_settings_get();
  $groups = query2array('
SELECT g.name
  FROM ' . USER_GROUP_TABLE . ' ug JOIN ' . GROUPS_TABLE . ' g ON g.id = ug.group_id
  WHERE ug.user_id = ' . intval($user['id']) . '
;', null, 'name');

  $level = 0;
  foreach ($c['rights'] as $r)
  {
    $match = (strpos($r['k'], 'u:') === 0 && substr($r['k'], 2) === $user['username'])
      || (strpos($r['k'], 'g:') === 0 && in_array(substr($r['k'], 2), $groups, true));
    if ($match)
    {
      $level = max($level, !empty($r['p'][2]) ? 3 : (!empty($r['p'][1]) ? 2 : (!empty($r['p'][0]) ? 1 : 0)));
    }
  }
  return $level;
}

function tagmanager_err_text($code)
{
  $t = array(
    'lecture' => 'fichier illisible',
    'pas_jpeg' => 'format non géré (JPEG seulement)',
    'xmp_illisible' => 'XMP illisible : fichier laissé intact',
    'xmp_sans_rdf' => 'XMP sans bloc RDF : fichier laissé intact',
    'xmp_trop_gros' => 'XMP trop volumineux : fichier laissé intact',
    'verification_structure' => 'vérification avant écriture échouée : fichier laissé intact',
    'verification_image' => 'vérification avant écriture échouée : fichier laissé intact',
    'verification_segments' => 'vérification avant écriture échouée : fichier laissé intact',
    'verification_xmp' => 'vérification avant écriture échouée : fichier laissé intact',
    'copie_original' => 'copie .original impossible : fichier laissé intact',
    'ecriture' => 'écriture impossible (droits sur le fichier ?)',
    'renommage' => 'remplacement du fichier impossible (droits sur le dossier ?)',
  );
  return isset($t[$code]) ? $t[$code] : $code;
}

// ---------------------------------------------------------------------------------------------
// Création d'un lot
// ---------------------------------------------------------------------------------------------

/**
 * @param array $items   liste de array('id' => image_id, 'add' => [noms], 'rem' => [noms])
 * @param bool  $original créer path.original si absent
 * @return array  ok (bool), job (id), total, ou error + details
 */
function tagmanager_job_create(array $items, $original)
{
  global $user;
  tagmanager_ensure_tables();

  if (empty($items))
  {
    return array('ok' => false, 'error' => 'Aucune modification à écrire.');
  }
  if (count($items) > TAGMANAGER_MAX_ITEMS)
  {
    return array('ok' => false, 'error' => 'Trop de photos pour un seul lot (maximum ' . TAGMANAGER_MAX_ITEMS . ').');
  }

  $active = tagmanager_job_active();
  if ($active)
  {
    return array('ok' => false, 'error' => 'Un lot est déjà en cours (n° ' . $active['id'] . ', ' . $active['done'] . ' / ' . $active['total'] . '). Reprenez-le ou annulez-le d’abord.');
  }
  tagmanager_job_purge(100);

  // Noms de tags -> identifiants et chemins
  $names = array();
  foreach ($items as $it)
  {
    foreach (array('add', 'rem') as $k)
    {
      if (!empty($it[$k]) && is_array($it[$k]))
      {
        foreach ($it[$k] as $n)
        {
          $names[(string)$n] = true;
        }
      }
    }
  }
  if (empty($names))
  {
    return array('ok' => false, 'error' => 'Aucun tag à ajouter ni à retirer.');
  }

  $quoted = array();
  foreach (array_keys($names) as $n)
  {
    $quoted[] = '\'' . pwg_db_real_escape_string($n) . '\'';
  }
  $rows = query2array('
SELECT t.id, t.name, p.path, p.status
  FROM ' . TAGS_TABLE . ' t
    LEFT JOIN ' . TAGMANAGER_PATHS_TABLE . ' p ON p.tag_id = t.id AND p.kind = \'tag\'
  WHERE t.name IN (' . implode(',', $quoted) . ')
;');
  $tag = array();
  foreach ($rows as $r)
  {
    $tag[$r['name']] = $r;
  }

  $adds = array();
  foreach ($items as $it)
  {
    if (!empty($it['add']) && is_array($it['add']))
    {
      foreach ($it['add'] as $n)
      {
        $adds[(string)$n] = true;
      }
    }
  }

  $problems = array();
  foreach (array_keys($names) as $n)
  {
    if (!isset($tag[$n]))
    {
      $problems[] = '« ' . $n . ' » : tag inconnu de Piwigo';
      continue;
    }
    $r = $tag[$n];
    if (tagmanager_is_reserved($r['id'], $r['name'], $r['path']))
    {
      $problems[] = '« ' . $n . ' » : tag de visage, géré par face_tag_editor et digiKam';
    }
    elseif (isset($adds[$n]) && ($r['status'] !== 'ok' || empty($r['path'])))
    {
      $problems[] = '« ' . $n . ' » : pas de chemin connu, à ranger d’abord (onglet À réconcilier)';
    }
  }
  if (!empty($problems))
  {
    return array('ok' => false, 'error' => 'Lot refusé.', 'details' => $problems);
  }

  // Photos
  $ids = array();
  foreach ($items as $it)
  {
    $ids[] = intval(isset($it['id']) ? $it['id'] : 0);
  }
  $existing = query2array('SELECT id FROM ' . IMAGES_TABLE . ' WHERE id IN (' . implode(',', array_unique($ids)) . ');', 'id', 'id');

  // un non-webmestre ne peut modifier que les photos qu'il peut voir dans la galerie
  $vis = tagmanager_visible_sql('i');
  if ($vis !== '' && !empty($existing))
  {
    $visible = query2array('SELECT i.id FROM ' . IMAGES_TABLE . ' i WHERE i.id IN (' . implode(',', array_keys($existing)) . ') AND ' . $vis . ';', 'id', 'id');
    if (count($visible) < count($existing))
    {
      return array('ok' => false, 'error' => 'Lot refusé : il contient des fichiers qui ne sont pas des JPEG ou des photos auxquelles vous n’avez pas accès.');
    }
  }

  $files = array();
  $n_add = 0;
  $n_rem = 0;
  foreach ($items as $it)
  {
    $id = intval(isset($it['id']) ? $it['id'] : 0);
    if (!isset($existing[$id]))
    {
      continue;
    }
    $ops = array();
    $add_ids = array();
    $rem_ids = array();
    foreach ((isset($it['add']) && is_array($it['add'])) ? $it['add'] : array() as $n)
    {
      $r = $tag[(string)$n];
      $ops[] = array('op' => 'add', 'path' => explode('|', $r['path']));
      $add_ids[] = (int)$r['id'];
      $n_add++;
    }
    foreach ((isset($it['rem']) && is_array($it['rem'])) ? $it['rem'] : array() as $n)
    {
      $r = $tag[(string)$n];
      $ops[] = array('op' => 'retag', 'from' => (string)$n, 'to' => null);
      $rem_ids[] = (int)$r['id'];
      $n_rem++;
    }
    if (empty($ops))
    {
      continue;
    }
    // mass_inserts() n'échappe pas les valeurs : on le fait ici
    $files[] = array('image_id' => $id, 'ops' => pwg_db_real_escape_string(json_encode(array('ops' => $ops, 'add' => $add_ids, 'rem' => $rem_ids))));
  }
  if (empty($files))
  {
    return array('ok' => false, 'error' => 'Aucune photo valide dans ce lot.');
  }

  $summary = '+' . $n_add . ' / −' . $n_rem . ' tags sur ' . count($files) . ' photo' . (count($files) > 1 ? 's' : '');
  pwg_query('
INSERT INTO ' . TAGMANAGER_JOBS_TABLE . ' (created_at, user_id, username, status, original, total, summary)
VALUES (' . time() . ', ' . intval($user['id']) . ', \'' . pwg_db_real_escape_string($user['username']) . '\', \'pending\', '
  . ($original ? 1 : 0) . ', ' . count($files) . ', \'' . pwg_db_real_escape_string($summary) . '\')
;');
  $job_id = pwg_db_insert_id();

  $rows = array();
  foreach ($files as $f)
  {
    $rows[] = array('job_id' => $job_id, 'image_id' => $f['image_id'], 'ops' => $f['ops']);
  }
  mass_inserts(TAGMANAGER_JOBFILES_TABLE, array('job_id', 'image_id', 'ops'), $rows);

  return array('ok' => true, 'job' => (int)$job_id, 'total' => count($files), 'summary' => $summary);
}

// ---------------------------------------------------------------------------------------------
// Traitement
// ---------------------------------------------------------------------------------------------

/** Lot non terminé (pending / running), le plus récent. */
function tagmanager_job_active()
{
  tagmanager_ensure_tables();
  $j = pwg_db_fetch_assoc(pwg_query('
SELECT id, created_at, username, status, original, total, done, errors, summary, kind
  FROM ' . TAGMANAGER_JOBS_TABLE . '
  WHERE status IN (\'pending\', \'running\', \'partial\')
  ORDER BY id DESC
  LIMIT 1
;'));
  return $j ? $j : null;
}

function tagmanager_job_counts($job_id)
{
  $c = array('pending' => 0, 'running' => 0, 'done' => 0, 'error' => 0, 'skipped' => 0);
  $res = pwg_query('SELECT status, COUNT(*) AS n FROM ' . TAGMANAGER_JOBFILES_TABLE . ' WHERE job_id = ' . intval($job_id) . ' GROUP BY status;');
  while ($r = pwg_db_fetch_assoc($res))
  {
    $c[$r['status']] = (int)$r['n'];
  }
  return $c;
}

/** Met à jour les tags Piwigo d'une photo d'après ce qui vient d'être écrit dans son fichier. */
function tagmanager_sync_image_tags($image_id, array $add_ids, array $rem_ids, $real_path)
{
  $image_id = intval($image_id);

  if (!empty($rem_ids))
  {
    pwg_query('DELETE FROM ' . IMAGE_TAG_TABLE . ' WHERE image_id = ' . $image_id . ' AND tag_id IN (' . implode(',', array_map('intval', $rem_ids)) . ');');
  }
  if (!empty($add_ids))
  {
    $have = query2array('SELECT tag_id FROM ' . IMAGE_TAG_TABLE . ' WHERE image_id = ' . $image_id . ' AND tag_id IN (' . implode(',', array_map('intval', $add_ids)) . ');', null, 'tag_id');
    $rows = array();
    foreach (array_diff(array_map('intval', $add_ids), array_map('intval', $have)) as $tid)
    {
      $rows[] = array('image_id' => $image_id, 'tag_id' => $tid);
    }
    if (!empty($rows))
    {
      mass_inserts(IMAGE_TAG_TABLE, array('image_id', 'tag_id'), $rows);
    }
  }

  // Le fichier a été réécrit : taille et date de synchro à jour (sans repasser par sync_metadata())
  $set = array('date_metadata_update = \'' . date('Y-m-d') . '\'');
  $size = @filesize($real_path);
  if ($size !== false)
  {
    $set[] = 'filesize = ' . intval(floor($size / 1024));
  }
  pwg_query('UPDATE ' . IMAGES_TABLE . ' SET ' . implode(', ', $set) . ' WHERE id = ' . $image_id . ';');
}

/**
 * Traite jusqu'à $limit fichiers du lot.
 * @return array état du lot et erreurs de ce passage
 */
function tagmanager_job_step($job_id, $limit)
{
  @set_time_limit(120);
  tagmanager_ensure_tables();
  $job_id = intval($job_id);
  $limit = max(1, min(50, intval($limit)));

  $job = pwg_db_fetch_assoc(pwg_query('SELECT * FROM ' . TAGMANAGER_JOBS_TABLE . ' WHERE id = ' . $job_id . ';'));
  if (!$job)
  {
    return array('ok' => false, 'error' => 'Lot introuvable.');
  }
  if ($job['status'] !== 'pending' && $job['status'] !== 'running')
  {
    return array('ok' => true, 'done' => true, 'status' => $job['status'], 'counts' => tagmanager_job_counts($job_id), 'errors' => array(), 'total' => (int)$job['total']);
  }

  pwg_query('UPDATE ' . TAGMANAGER_JOBS_TABLE . ' SET status = \'running\' WHERE id = ' . $job_id . ' AND status = \'pending\';');

  // Réservations abandonnées (onglet fermé en cours de route) : on les remet dans la file
  pwg_query('
UPDATE ' . TAGMANAGER_JOBFILES_TABLE . '
  SET status = \'pending\', claim = NULL
  WHERE job_id = ' . $job_id . ' AND status = \'running\' AND claimed_at < ' . (time() - TAGMANAGER_CLAIM_TTL) . '
;');

  // Réservation atomique : deux onglets ne traitent jamais le même fichier
  $token = substr(md5(uniqid('', true)), 0, 16);
  pwg_query('
UPDATE ' . TAGMANAGER_JOBFILES_TABLE . '
  SET status = \'running\', claim = \'' . $token . '\', claimed_at = ' . time() . '
  WHERE job_id = ' . $job_id . ' AND status = \'pending\'
  ORDER BY id
  LIMIT ' . $limit . '
;');

  $rows = query2array('
SELECT f.id, f.image_id, f.ops, i.path, i.file
  FROM ' . TAGMANAGER_JOBFILES_TABLE . ' f JOIN ' . IMAGES_TABLE . ' i ON i.id = f.image_id
  WHERE f.claim = \'' . $token . '\' AND f.status = \'running\'
  ORDER BY f.id
;');

  $errors = array();
  $any_ok = false;
  foreach ($rows as $f)
  {
    $spec = json_decode($f['ops'], true);
    $real = tagmanager_resolve_path($f['path']);
    $res = tagmanager_write_file($real, $spec['ops'], array('original' => !empty($job['original'])));

    if ($res['error'])
    {
      $msg = tagmanager_err_text($res['error']);
      $status = 'error';
      $errors[] = array('file' => $f['file'], 'message' => $msg);
    }
    else
    {
      tagmanager_sync_image_tags($f['image_id'], $spec['add'], $spec['rem'], $real);
      $status = 'done';
      $msg = $res['changed'] ? 'modifié' : 'déjà à jour';
      if (!empty($res['warnings']))
      {
        $msg .= ' (avertissement : ' . implode(', ', $res['warnings']) . ')';
      }
      $any_ok = true;
    }

    pwg_query('
UPDATE ' . TAGMANAGER_JOBFILES_TABLE . '
  SET status = \'' . $status . '\', message = \'' . pwg_db_real_escape_string(substr($msg, 0, 250)) . '\', processed_at = ' . time() . '
  WHERE id = ' . intval($f['id']) . '
;');
  }

  if ($any_ok)
  {
    invalidate_user_cache(); // SmartAlbums et les listes de tags se mettent à jour
  }

  $c = tagmanager_job_counts($job_id);
  $finished = ($c['pending'] + $c['running']) === 0;
  $status = 'running';
  $final = null;
  if ($finished)
  {
    if (isset($job['kind']) && $job['kind'] === 'struct')
    {
      if ($c['error'] === 0)
      {
        // Tous les fichiers ont réussi : on met à jour les tags Piwigo (SmartAlbums suit)
        $final = tagmanager_job_finalize($job_id);
        $status = 'done';
      }
      else
      {
        $status = 'partial'; // les tags Piwigo ne sont pas touchés tant qu'il reste des fichiers en erreur
      }
    }
    else
    {
      $status = 'done';
    }
  }
  pwg_query('
UPDATE ' . TAGMANAGER_JOBS_TABLE . '
  SET done = ' . $c['done'] . ', errors = ' . $c['error'] . ', status = \'' . $status . '\'
  WHERE id = ' . $job_id . '
;');

  return array(
    'ok' => true,
    'done' => $finished,
    'status' => $status,
    'counts' => $c,
    'errors' => $errors,
    'total' => (int)$job['total'],
    'kind' => isset($job['kind']) ? $job['kind'] : 'tags',
    'final' => $final,
  );
}

/** Remet les fichiers en erreur d'un lot « partial » dans la file, pour un nouvel essai. */
function tagmanager_job_retry($job_id)
{
  $job_id = intval($job_id);
  pwg_query('UPDATE ' . TAGMANAGER_JOBFILES_TABLE . ' SET status = \'pending\', claim = NULL, message = \'\' WHERE job_id = ' . $job_id . ' AND status = \'error\';');
  pwg_query('UPDATE ' . TAGMANAGER_JOBS_TABLE . ' SET status = \'running\' WHERE id = ' . $job_id . ' AND status = \'partial\';');
  return array('ok' => true, 'counts' => tagmanager_job_counts($job_id));
}

function tagmanager_job_cancel($job_id)
{
  $job_id = intval($job_id);
  pwg_query('UPDATE ' . TAGMANAGER_JOBFILES_TABLE . ' SET status = \'skipped\', message = \'annulé\' WHERE job_id = ' . $job_id . ' AND status = \'pending\';');
  $c = tagmanager_job_counts($job_id);
  pwg_query('
UPDATE ' . TAGMANAGER_JOBS_TABLE . '
  SET status = \'cancelled\', done = ' . $c['done'] . ', errors = ' . $c['error'] . '
  WHERE id = ' . $job_id . ' AND status IN (\'pending\', \'running\', \'partial\')
;');
  return array('ok' => true, 'counts' => $c);
}

/**
 * Purge du journal : ne garde que les $keep derniers lots (celui qu'on va créer compris).
 * Seuls les lots terminés ou annulés sont supprimés (jamais un lot en attente, en cours ou partiel),
 * avec leurs lignes de fichiers.
 */
function tagmanager_job_purge($keep = 100)
{
  $keep = max(10, intval($keep));
  $row = pwg_db_fetch_row(pwg_query('SELECT id FROM ' . TAGMANAGER_JOBS_TABLE . ' ORDER BY id DESC LIMIT ' . ($keep - 2) . ', 1;'));
  if (!$row)
  {
    return;
  }
  $limit = intval($row[0]);
  $ids = query2array('SELECT id FROM ' . TAGMANAGER_JOBS_TABLE . ' WHERE id < ' . $limit . ' AND status IN (\'done\', \'cancelled\');', null, 'id');
  if (empty($ids))
  {
    return;
  }
  $list = implode(',', array_map('intval', $ids));
  pwg_query('DELETE FROM ' . TAGMANAGER_JOBFILES_TABLE . ' WHERE job_id IN (' . $list . ');');
  pwg_query('DELETE FROM ' . TAGMANAGER_JOBS_TABLE . ' WHERE id IN (' . $list . ');');
}

/** Journal : derniers lots. */
function tagmanager_job_log($limit = 20)
{
  tagmanager_ensure_tables();
  $rows = query2array('
SELECT id, created_at, username, status, original, total, done, errors, summary, kind
  FROM ' . TAGMANAGER_JOBS_TABLE . '
  ORDER BY id DESC
  LIMIT ' . max(1, min(100, intval($limit))) . '
;');
  foreach ($rows as &$r)
  {
    $r['date'] = date('d/m/Y H:i', (int)$r['created_at']);
  }
  unset($r);
  return $rows;
}

function tagmanager_job_errors($job_id)
{
  return query2array('
SELECT i.file, f.message
  FROM ' . TAGMANAGER_JOBFILES_TABLE . ' f JOIN ' . IMAGES_TABLE . ' i ON i.id = f.image_id
  WHERE f.job_id = ' . intval($job_id) . ' AND f.status = \'error\'
  ORDER BY f.id
  LIMIT 200
;');
}

?>
