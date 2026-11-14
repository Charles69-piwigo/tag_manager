<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');
defined('TAGMANAGER_PATH') or die('Hacking attempt!');

// Cette page n'est accessible qu'aux webmestres (« Has Settings: webmaster »), mais on revérifie
// côté serveur : ne jamais compter sur le seul masquage d'un menu.
if (!is_webmaster())
{
  die('Hacking attempt!');
}

include_once(PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php');
include_once(TAGMANAGER_PATH . 'lib/indexer.php');
include_once(TAGMANAGER_PATH . 'lib/data.php');
include_once(TAGMANAGER_PATH . 'lib/queue.php');
include_once(TAGMANAGER_PATH . 'lib/struct.php');
include_once(TAGMANAGER_PATH . 'lib/ajax.php');
include_once(TAGMANAGER_PATH . 'lib/i18n.php');

tagmanager_ensure_tables();

load_language('plugin.lang', TAGMANAGER_PATH);

// ---------- onglets (ceux de Piwigo) ----------
// tag / mng / set : trois écrans de la même page (js/app.js bascule de l'un à l'autre sans recharger,
// pour ne pas perdre les changements en attente) ; index : page à part. « app » = ancienne adresse.
$page['tab'] = isset($_GET['tab']) ? $_GET['tab'] : 'tag';
if ($page['tab'] === 'app')
{
  $page['tab'] = 'tag';
}
if (!in_array($page['tab'], array('tag', 'mng', 'index', 'set')))
{
  $page['tab'] = 'tag';
}

$tabsheet = new tabsheet();
$tabsheet->set_id('tag_manager');
$tabsheet->add('tag', '<span class="icon-tags"></span>' . l10n('Étiqueter'), TAGMANAGER_ADMIN . '&amp;tab=tag');
$tabsheet->add('mng', '<span class="icon-flow-tree"></span>' . l10n('Gérer les tags'), TAGMANAGER_ADMIN . '&amp;tab=mng');
$tabsheet->add('index', '<span class="icon-database"></span>' . l10n('Index'), TAGMANAGER_ADMIN . '&amp;tab=index');
$tabsheet->add('set', '<span class="icon-cog"></span>' . l10n('Paramètres'), TAGMANAGER_ADMIN . '&amp;tab=set');
$tabsheet->select($page['tab']);
$tabsheet->assign();

// thème de l'administration : « roma » est sombre, la feuille du plugin suit
$tm_dark = userprefs_get_param('admin_theme', 'clear') === 'roma';

// ---------- actions AJAX (partagées avec la page publique : lib/ajax.php) ----------
if (isset($_POST['action']))
{
  tagmanager_ajax(false);
  exit;
}

// ---------- affichage ----------
if ($page['tab'] !== 'index')
{
  $template->assign(array(
    'TM_URL' => TAGMANAGER_ADMIN . '&tab=tag',
    'TM_SCREEN' => $page['tab'],
    'TM_DARK' => $tm_dark,
    'TM_INDEX_URL' => TAGMANAGER_ADMIN . '&tab=index',
    'TM_TOKEN' => get_pwg_token(),
    'TM_ASSETS' => get_root_url() . TAGMANAGER_PATH,
    'TM_CSS_V' => @filemtime(TAGMANAGER_PATH . 'css/app.css'),
    'TM_JS_V' => @filemtime(TAGMANAGER_PATH . 'js/app.js'),
    'TM_PUBLIC' => false,
    'TM_I18N' => tagmanager_js_dict_json(),
    'TM_GALLERY' => get_absolute_root_url(),
  ));
  $template->set_filename('tag_manager_app', dirname(__FILE__) . '/template/app.tpl');
  $template->assign_var_from_handle('ADMIN_CONTENT', 'tag_manager_app');
}
else
{
  $summary = tagmanager_index_summary();
  $template->assign(array(
    'TM_URL' => TAGMANAGER_ADMIN . '&tab=index',
    'TM_DARK' => $tm_dark,
    'TM_ASSETS' => get_root_url() . TAGMANAGER_PATH,
    'TM_CSS_V' => @filemtime(TAGMANAGER_PATH . 'css/app.css'),
    'TM_TOKEN' => get_pwg_token(),
    'TM_SUMMARY' => $summary,
    'TM_LAST' => $summary['last'] ? date('d/m/Y H:i', $summary['last']) : '',
    'TM_TREE' => tagmanager_index_tree_html(),
    'TM_PROBLEMS' => tagmanager_index_problems(),
  ));
  $template->set_filename('tag_manager_index', dirname(__FILE__) . '/template/index.tpl');
  $template->assign_var_from_handle('ADMIN_CONTENT', 'tag_manager_index');
}

?>
