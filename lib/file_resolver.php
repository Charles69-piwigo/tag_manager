<?php
/**
 * Chemin réel d'une photo Piwigo.
 * Les liens symboliques sont posés sur des répertoires (galleries/Chrono -> /volume1/...),
 * realpath() les résout tous, y compris ceux des répertoires intermédiaires.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

function tagmanager_resolve_path($path)
{
  $full = PHPWG_ROOT_PATH . $path;
  $full = str_replace('/./', '/', $full);

  $real = realpath($full);
  return ($real === false) ? $full : $real;
}

?>
