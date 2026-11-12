<?php
// fr_FR est la langue pivot : la clé EST le texte français. On reprend donc telles quelles toutes les clés
// du fichier anglais : un texte ajouté au plugin s'affiche toujours en français pour un utilisateur
// francophone, même si on oublie de le déclarer ici (l'anglais est chargé en premier, voir main.inc.php).

$tm_fr_keys = call_user_func(function ()
{
  $lang = array();
  include(dirname(__FILE__) . '/../en_UK/plugin.lang.php');
  return array_keys($lang);
});
foreach ($tm_fr_keys as $tm_fr_k)
{
  $lang[$tm_fr_k] = $tm_fr_k;
}
unset($tm_fr_keys, $tm_fr_k);

?>
