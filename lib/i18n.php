<?php
/**
 * Traduction de l'interface JavaScript (js/app.js).
 *
 * Le français est la langue pivot : les textes du JS sont écrits en français et passent par tx().
 * Pour un autre utilisateur, on fournit le dictionnaire language/<langue>/js.lang.php, ou l'anglais
 * s'il n'existe pas pour sa langue. Le dictionnaire se maintient avec dev/i18n_wrap.js.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

/**
 * Message traduit (clé = texte français, voir language/en_UK/plugin.lang.php), avec des %s remplis par
 * les arguments suivants : tagmanager_t('Tag inconnu : « %s ».', $nom).
 */
function tagmanager_t($fr)
{
  $args = func_get_args();
  array_shift($args);
  $s = function_exists('l10n') ? l10n($fr) : $fr;
  return empty($args) ? $s : vsprintf($s, $args);
}

/** Dictionnaire (texte français => traduction) pour la langue de l'utilisateur ; vide en français. */
function tagmanager_js_dict()
{
  global $user;
  $lang = isset($user['language']) ? (string)$user['language'] : 'en_UK';
  if (strpos($lang, 'fr') === 0)
  {
    return array();
  }
  $file = TAGMANAGER_PATH . 'language/' . preg_replace('/[^A-Za-z_]/', '', $lang) . '/js.lang.php';
  if (!is_file($file))
  {
    $file = TAGMANAGER_PATH . 'language/en_UK/js.lang.php';
  }
  $tm_js = array();
  include($file);
  return is_array($tm_js) ? $tm_js : array();
}

/** Le même dictionnaire en JSON, prêt à être placé dans une balise script (« </script> » neutralisé). */
function tagmanager_js_dict_json()
{
  $d = tagmanager_js_dict();
  return json_encode(empty($d) ? new stdClass() : $d, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
}

?>
