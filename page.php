<?php
/**
 * Page publique du plugin : accessible aux utilisateurs connectés qui ont un droit dans la table des
 * droits (Paramètres), sans passer par l'administration de Piwigo. Le bouton « Tag manager » de la barre
 * d'outils des pages d'index y mène. Les actions AJAX sont traitées par lib/ajax.php, qui vérifie le jeton
 * et le niveau de l'utilisateur pour chaque action.
 */
define('PHPWG_ROOT_PATH', '../../');
include_once(PHPWG_ROOT_PATH . 'include/common.inc.php');

if (!defined('TAGMANAGER_PATH'))
{
  http_response_code(404);
  die('Plugin inactif.');
}

include_once(TAGMANAGER_PATH . 'lib/indexer.php');
include_once(TAGMANAGER_PATH . 'lib/data.php');
include_once(TAGMANAGER_PATH . 'lib/queue.php');
include_once(TAGMANAGER_PATH . 'lib/struct.php');
include_once(TAGMANAGER_PATH . 'lib/ajax.php');

// Visiteur non connecté : écran d'identification
if (empty($user['id']) || is_a_guest())
{
  redirect(get_absolute_root_url() . 'identification.php');
}

tagmanager_ensure_tables();
load_language('plugin.lang', TAGMANAGER_PATH);

$tm_level = tagmanager_user_level();
if ($tm_level < 1)
{
  http_response_code(403);
  die('Accès refusé.');
}

if (isset($_POST['action']))
{
  tagmanager_ajax(true);
  exit;
}

$assets = get_absolute_root_url() . 'plugins/' . TAGMANAGER_ID . '/';

$template->assign(array(
  'TM_URL' => $assets . 'page.php',
  'TM_TOKEN' => get_pwg_token(),
  'TM_ASSETS' => $assets,
  'TM_CSS_V' => @filemtime(TAGMANAGER_PATH . 'css/app.css'),
  'TM_JS_V' => @filemtime(TAGMANAGER_PATH . 'js/app.js'),
  'TM_PUBLIC' => true,
  'TM_GALLERY' => get_absolute_root_url(),
  'TM_LEVEL' => $tm_level,
  'TM_HOME' => get_absolute_root_url() . 'index.php',
  'TM_LANG' => str_replace('_', '-', substr($user['language'], 0, 2)),
));

$template->set_filename('tag_manager_app', dirname(__FILE__) . '/template/app.tpl');
$template->assign_var_from_handle('TM_APP', 'tag_manager_app');
$template->set_filename('tag_manager_public', dirname(__FILE__) . '/template/public.tpl');
$template->pparse('tag_manager_public');
