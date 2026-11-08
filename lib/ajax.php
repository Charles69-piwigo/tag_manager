<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Actions AJAX de l'interface, partagées entre l'administration (webmestres) et la page publique
 * (utilisateurs ayant un droit dans la table des droits).
 *
 * Les droits sont vérifiés ICI, côté serveur, pour chaque action : jamais sur le seul masquage d'un bouton.
 * Niveaux : 0 aucun, 1 étiqueter, 2 gérer (créer), 3 gérer (tout). Webmestre : 3.
 * Sont réservées aux webmestres : indexation, réglages (lecture complète et enregistrement), journal.
 *
 * @param bool $public true pour la page publique (webmestre non requis, niveau >= 1 requis)
 */
function tagmanager_ajax($public)
{
  header('Content-Type: application/json; charset=UTF-8');

  $reply = function ($data)
  {
    $data['stat'] = 'ok';
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  };
  $fail = function ($msg)
  {
    echo json_encode(array('stat' => 'fail', 'message' => $msg));
    exit;
  };

  if (!isset($_POST['pwg_token']) || $_POST['pwg_token'] !== get_pwg_token())
  {
    $fail('Invalid token');
  }

  $webmaster = is_webmaster();
  $level = tagmanager_user_level();
  if (!$webmaster && ($public ? $level < 1 : true))
  {
    $fail('Forbidden');
  }

  $need = function ($min) use ($level, $reply)
  {
    if ($level < $min)
    {
      $reply(array('ok' => false, 'error' => 'Droits insuffisants.'));
    }
  };
  $admin_only = function () use ($webmaster, $fail)
  {
    if (!$webmaster)
    {
      $fail('Forbidden');
    }
  };

  $ids = function ($key)
  {
    return isset($_POST[$key]) && $_POST[$key] !== '' ? array_map('intval', explode(',', $_POST[$key])) : array();
  };
  $flag = function ($key)
  {
    return !empty($_POST[$key]) && $_POST[$key] !== 'false' && $_POST[$key] !== '0';
  };

  switch (isset($_POST['action']) ? $_POST['action'] : '')
  {
    // --- indexation (onglet Index de l'administration) ---
    case 'index_start':
      $admin_only();
      $reply(array('run' => time(), 'total' => tagmanager_count_used_tags()));

    case 'index_batch':
      $admin_only();
      $reply(tagmanager_index_batch(
        isset($_POST['last_id']) ? intval($_POST['last_id']) : 0,
        isset($_POST['run']) ? intval($_POST['run']) : time()
      ));

    // --- lectures pour l'interface ---
    case 'albums':
      $need(1);
      $reply(array('albums' => tagmanager_data_albums()));

    case 'tags':
      $need(1);
      $reply(tagmanager_data_tags());

    case 'photos':
      $need(1);
      $reply(tagmanager_data_photos(
        (isset($_POST['mode']) && $_POST['mode'] === 'tag') ? 'tag' : 'album',
        $ids('ids'),
        isset($_POST['offset']) ? $_POST['offset'] : 0,
        isset($_POST['limit']) ? $_POST['limit'] : 240
      ));

    case 'tag_albums':
      $need(1);
      $reply(tagmanager_data_tag_albums($ids('ids')));

    case 'recon':
      $need(2);
      $reply(array('rows' => tagmanager_data_recon()));

    case 'settings':
      $need(1);
      if ($webmaster)
      {
        $reply(tagmanager_data_settings());
      }
      // autres utilisateurs : ni table des droits, ni liste des comptes et des groupes
      $c = tagmanager_settings_get();
      $reply(array('original' => $c['original'], 'thumb' => $c['thumb'], 'tw' => $c['tw'], 'reserved' => $c['reserved'],
        'rights' => array(), 'groups' => array(), 'users' => array(), 'level' => $level));

    // --- file d'attente d'écriture ---
    case 'job_create':
      $need(1);
      $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : null;
      $reply(tagmanager_job_create(is_array($items) ? $items : array(), $flag('original')));

    case 'job_active':
      $need(1);
      $reply(array('job' => tagmanager_job_active()));

    case 'job_step':
      $need(1);
      $reply(tagmanager_job_step(isset($_POST['job']) ? $_POST['job'] : 0, isset($_POST['limit']) ? $_POST['limit'] : 10));

    case 'job_cancel':
      $need(1);
      $reply(tagmanager_job_cancel(isset($_POST['job']) ? $_POST['job'] : 0));

    // --- opérations de structure : renommer / fusionner / supprimer / ranger (niveau 3) ---
    case 'struct_preview':
    case 'struct_create':
      $need(3);
      $sops = isset($_POST['ops']) ? json_decode(stripslashes($_POST['ops']), true) : null;
      $sops = is_array($sops) ? $sops : array();
      if ($_POST['action'] === 'struct_preview')
      {
        $reply(tagmanager_struct_preview($sops));
      }
      $reply(tagmanager_struct_create($sops, $flag('original')));

    case 'job_retry':
      $need(3);
      $reply(tagmanager_job_retry(isset($_POST['job']) ? $_POST['job'] : 0));

    // --- création (niveau 2) et suppression de groupe vide (niveau 3), sans réécriture de fichiers ---
    case 'group_create':
    case 'tag_create':
      $need(2);
      $par = isset($_POST['parent']) ? stripslashes($_POST['parent']) : '';
      $nm = isset($_POST['name']) ? stripslashes($_POST['name']) : '';
      $reply($_POST['action'] === 'group_create' ? tagmanager_group_create($par, $nm) : tagmanager_tag_create($par, $nm));

    case 'group_delete':
      $need(3);
      $reply(tagmanager_group_delete(isset($_POST['path']) ? stripslashes($_POST['path']) : ''));

    // --- journal et réglages : webmestres ---
    case 'job_log':
      $admin_only();
      $reply(array('rows' => tagmanager_job_log(20)));

    case 'job_errors':
      $need(1);
      $reply(array('rows' => tagmanager_job_errors(isset($_POST['job']) ? $_POST['job'] : 0)));

    case 'save_settings':
      $admin_only();
      // Piwigo ajoute des antislashs à toutes les valeurs reçues en POST : à retirer avant json_decode
      $saved = tagmanager_settings_save(isset($_POST['settings']) ? json_decode(stripslashes($_POST['settings']), true) : null);
      $reply(array('saved' => $saved));
  }

  $fail('Unknown action');
}
