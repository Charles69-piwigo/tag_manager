<?php
/**
 * Comparaison des noms de tags, comme le fait Piwigo.
 *
 * Piwigo retrouve un tag d'après un mot-clé de fichier par `WHERE name = '...'`, puis par url_name :
 * dans les deux cas MySQL ignore la casse ET les accents (collation *_ci). « Selection » dans un fichier
 * et « sélection » dans la base sont donc le MÊME tag. Toute comparaison de noms du plugin passe par
 * tagmanager_fold(), sinon un tag dont l'orthographe diffère d'un accent ou d'une majuscule est pris
 * pour un tag absent des fichiers (« sans chemin »).
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

/** Table caractère accentué -> lettre de base (minuscule), majuscules comprises. */
function tagmanager_accent_map()
{
  static $map = null;
  if ($map !== null)
  {
    return $map;
  }
  $groups = array(
    'a' => 'àáâãäåāăąÀÁÂÃÄÅĀĂĄ', 'c' => 'çćĉċčÇĆĈĊČ', 'd' => 'ďđĎĐ', 'e' => 'èéêëēĕėęěÈÉÊËĒĔĖĘĚ',
    'g' => 'ĝğġģĜĞĠĢ', 'h' => 'ĥħĤĦ', 'i' => 'ìíîïĩīĭįıÌÍÎÏĨĪĬĮİ', 'j' => 'ĵĴ', 'k' => 'ķĶ',
    'l' => 'ĺļľŀłĹĻĽĿŁ', 'n' => 'ñńņňŉÑŃŅŇ', 'o' => 'òóôõöøōŏőÒÓÔÕÖØŌŎŐ', 'r' => 'ŕŗřŔŖŘ',
    's' => 'śŝşšŚŜŞŠ', 't' => 'ţťŧŢŤŦ', 'u' => 'ùúûüũūŭůűųÙÚÛÜŨŪŬŮŰŲ', 'w' => 'ŵŴ', 'y' => 'ýÿŷÝŶŸ',
    'z' => 'źżžŹŻŽ',
  );
  $map = array();
  foreach ($groups as $base => $chars)
  {
    foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $c)
    {
      $map[$c] = $base;
    }
  }
  $map['ß'] = 'ss';
  $map['æ'] = 'ae';
  $map['Æ'] = 'ae';
  $map['œ'] = 'oe';
  $map['Œ'] = 'oe';
  return $map;
}

/**
 * Forme de comparaison d'un nom : sans casse, sans accents (composés ou décomposés), sans espaces de
 * tête ni de queue. Deux noms équivalents pour Piwigo ont la même forme.
 */
function tagmanager_fold($s)
{
  $s = trim((string)$s);
  if ($s === '')
  {
    return '';
  }
  if (function_exists('mb_strtolower'))
  {
    $s = mb_strtolower($s, 'UTF-8');
  }
  $s = preg_replace('/\p{Mn}+/u', '', $s);   // accents décomposés (e + U+0301)
  $s = strtr($s, tagmanager_accent_map());   // accents composés
  return strtolower($s);
}

/** Deux noms de tags équivalents pour Piwigo ? */
function tagmanager_same_name($a, $b)
{
  return $a === $b || tagmanager_fold($a) === tagmanager_fold($b);
}

/** Deux chemins (listes de segments) équivalents segment par segment ? */
function tagmanager_same_segs(array $a, array $b)
{
  if (count($a) !== count($b))
  {
    return false;
  }
  foreach ($a as $i => $x)
  {
    if (!tagmanager_same_name($x, $b[$i]))
    {
      return false;
    }
  }
  return true;
}

/** Les premiers segments de $segs sont-ils équivalents à $prefix ? */
function tagmanager_segs_start_with(array $segs, array $prefix)
{
  $k = count($prefix);
  return $k > 0 && count($segs) >= $k && tagmanager_same_segs(array_slice($segs, 0, $k), $prefix);
}

/** in_array() avec équivalence de noms. */
function tagmanager_in_names($needle, array $list)
{
  foreach ($list as $v)
  {
    if (tagmanager_same_name($needle, $v))
    {
      return true;
    }
  }
  return false;
}

?>
