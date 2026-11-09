<?php
/**
 * Exif IFD0:XPKeywords (étiquette 0x9C9E), le champ « Mots-clés » de l'Explorateur Windows.
 *
 * Valeur : octets UTF-16LE (quel que soit l'ordre des octets du TIFF), mots-clés séparés par « ; »,
 * terminés par un caractère nul. Seul le dernier niveau de chaque tag y figure (comme dc:subject).
 *
 * Écriture : le champ n'est jamais créé (seulement mis à jour s'il existe). Si la nouvelle valeur tient
 * dans l'ancien emplacement, elle l'y remplace ; sinon elle est AJOUTÉE à la fin du bloc TIFF et
 * l'entrée est repointée. Aucun autre octet du segment ne bouge, donc aucun autre décalage (vignette
 * IFD1, MakerNote...) n'est invalidé.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

define('TAGMANAGER_EXIF_ID', "Exif\0\0");
define('TAGMANAGER_TAG_XPKEYWORDS', 0x9C9E);

function tagmanager_utf16le_to_utf8($b)
{
  if (strlen($b) % 2)
  {
    $b = substr($b, 0, -1);
  }
  $u = ($b === '') ? array() : array_values(unpack('v*', $b));
  $out = '';
  $n = count($u);
  for ($i = 0; $i < $n; $i++)
  {
    $c = $u[$i];
    if ($c >= 0xD800 && $c <= 0xDBFF && $i + 1 < $n && $u[$i + 1] >= 0xDC00 && $u[$i + 1] <= 0xDFFF)
    {
      $c = 0x10000 + (($c - 0xD800) << 10) + ($u[$i + 1] - 0xDC00);
      $i++;
    }
    $out .= tagmanager_code_to_utf8($c);
  }
  return $out;
}

function tagmanager_code_to_utf8($c)
{
  if ($c < 0x80)
  {
    return chr($c);
  }
  if ($c < 0x800)
  {
    return chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
  }
  if ($c < 0x10000)
  {
    return chr(0xE0 | ($c >> 12)) . chr(0x80 | (($c >> 6) & 0x3F)) . chr(0x80 | ($c & 0x3F));
  }
  return chr(0xF0 | ($c >> 18)) . chr(0x80 | (($c >> 12) & 0x3F)) . chr(0x80 | (($c >> 6) & 0x3F)) . chr(0x80 | ($c & 0x3F));
}

/** @return string|null UTF-16LE ; null si $s n'est pas de l'UTF-8 valide */
function tagmanager_utf8_to_utf16le($s)
{
  if (!tagmanager_is_utf8($s))
  {
    return null;
  }
  $out = '';
  foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch)
  {
    $b = array_values(unpack('C*', $ch));
    switch (count($b))
    {
      case 1: $c = $b[0]; break;
      case 2: $c = (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F); break;
      case 3: $c = (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F); break;
      default: $c = (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F);
    }
    if ($c >= 0x10000)
    {
      $c -= 0x10000;
      $out .= pack('v', 0xD800 + ($c >> 10)) . pack('v', 0xDC00 + ($c & 0x3FF));
    }
    else
    {
      $out .= pack('v', $c);
    }
  }
  return $out;
}

/**
 * Repère l'entrée XPKeywords de l'IFD0.
 * @param string $payload charge utile de l'APP1 Exif (commence par « Exif\0\0 »)
 * @return array|null|false  false : structure non gérée ; null : champ absent ;
 *                           sinon array(entry => offset de l'entrée dans $payload, count, data => octets,
 *                           data_off => offset des données dans $payload (ou null si dans l'entrée),
 *                           little => bool)
 */
function tagmanager_exif_find($payload)
{
  $h = strlen(TAGMANAGER_EXIF_ID);
  $n = strlen($payload);
  if ($n < $h + 8 || substr($payload, 0, $h) !== TAGMANAGER_EXIF_ID)
  {
    return false;
  }
  $bo = substr($payload, $h, 2);
  if ($bo !== 'II' && $bo !== 'MM')
  {
    return false;
  }
  $little = ($bo === 'II');
  $u16 = function ($o) use ($payload, $little) { $x = unpack($little ? 'v' : 'n', substr($payload, $o, 2)); return $x[1]; };
  $u32 = function ($o) use ($payload, $little) { $x = unpack($little ? 'V' : 'N', substr($payload, $o, 4)); return $x[1]; };

  if ($u16($h + 2) !== 42)
  {
    return false;
  }
  $ifd = $h + $u32($h + 4);
  if ($ifd + 2 > $n)
  {
    return false;
  }
  $cnt = $u16($ifd);
  if ($ifd + 2 + 12 * $cnt > $n)
  {
    return false;
  }
  for ($i = 0; $i < $cnt; $i++)
  {
    $e = $ifd + 2 + 12 * $i;
    if ($u16($e) !== TAGMANAGER_TAG_XPKEYWORDS)
    {
      continue;
    }
    $type = $u16($e + 2);
    $count = $u32($e + 4);
    if ($type !== 1 && $type !== 7)
    {
      return false;
    }
    if ($count <= 4)
    {
      return array('entry' => $e, 'count' => $count, 'data' => substr($payload, $e + 8, $count), 'data_off' => null, 'little' => $little);
    }
    $off = $h + $u32($e + 8);
    if ($off + $count > $n)
    {
      return false;
    }
    return array('entry' => $e, 'count' => $count, 'data' => substr($payload, $off, $count), 'data_off' => $off, 'little' => $little);
  }
  return null;
}

/** Mots-clés (UTF-8) de la valeur XPKeywords. */
function tagmanager_xpkeywords_decode($data)
{
  $s = tagmanager_utf16le_to_utf8($data);
  $s = rtrim($s, "\0");
  if ($s === '')
  {
    return array();
  }
  return array_values(array_filter(array_map('trim', explode(';', $s)), function ($x) { return $x !== ''; }));
}

/** Mots-clés de XPKeywords dans le segment Exif, null si absent ou illisible. */
function tagmanager_exif_keywords($payload)
{
  $f = tagmanager_exif_find($payload);
  return ($f ? tagmanager_xpkeywords_decode($f['data']) : null);
}

/**
 * Applique les opérations à XPKeywords.
 * @param string $payload charge utile de l'APP1 Exif
 * @return string|null nouvelle charge utile, ou null si rien ne change (champ absent ou non géré)
 */
function tagmanager_exif_modify($payload, array $ops, &$warnings)
{
  $f = tagmanager_exif_find($payload);
  if ($f === false)
  {
    $warnings[] = 'exif_non_gere';
    return null;
  }
  if ($f === null)
  {
    return null; // pas de XPKeywords : on ne le crée pas
  }

  $current = tagmanager_xpkeywords_decode($f['data']);
  $new = tagmanager_list_apply($current, 'leaf', $ops);
  if ($new === $current)
  {
    return null;
  }
  foreach ($new as $k)
  {
    if (strpos($k, ';') !== false)
    {
      $warnings[] = 'exif_point_virgule'; // « ; » est le séparateur : le champ serait faux
      return null;
    }
  }

  $bin = tagmanager_utf8_to_utf16le(implode(';', $new));
  if ($bin === null)
  {
    $warnings[] = 'exif_encodage';
    return null;
  }
  $bin .= "\0\0";
  $len = strlen($bin);
  $h = strlen(TAGMANAGER_EXIF_ID);
  $pack32 = $f['little'] ? 'V' : 'N';

  $out = $payload;
  if ($len <= 4)
  {
    // Dans l'entrée elle-même
    $out = substr_replace($out, str_pad($bin, 4, "\0"), $f['entry'] + 8, 4);
    if ($f['data_off'] !== null)
    {
      $out = substr_replace($out, str_repeat("\0", $f['count']), $f['data_off'], $f['count']);
    }
  }
  elseif ($f['data_off'] !== null && $len <= $f['count'])
  {
    // Même emplacement (le reste de l'ancien emplacement est mis à zéro)
    $out = substr_replace($out, str_pad($bin, $f['count'], "\0"), $f['data_off'], $f['count']);
  }
  else
  {
    // Ajout en fin de bloc, aligné sur un octet pair ; l'ancien emplacement est mis à zéro
    if ($f['data_off'] !== null)
    {
      $out = substr_replace($out, str_repeat("\0", $f['count']), $f['data_off'], $f['count']);
    }
    if ((strlen($out) - $h) % 2)
    {
      $out .= "\0";
    }
    $new_off = strlen($out) - $h;
    $out .= $bin;
    $out = substr_replace($out, pack($pack32, $new_off), $f['entry'] + 8, 4);
  }
  $out = substr_replace($out, pack($pack32, $len), $f['entry'] + 4, 4);

  if (strlen($out) > 65533)
  {
    $warnings[] = 'exif_trop_gros';
    return null;
  }
  return $out;
}

?>
