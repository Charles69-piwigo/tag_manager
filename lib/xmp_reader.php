<?php
/**
 * Lecture seule des chemins hiérarchiques de tags dans le XMP d'un fichier image.
 * PHP pur : ni Imagick ni exiftool.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

define('TAGMANAGER_NS_LR', 'http://ns.adobe.com/lightroom/1.0/');
define('TAGMANAGER_NS_DK', 'http://www.digikam.org/ns/1.0/');
define('TAGMANAGER_NS_RDF', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');

define('TAGMANAGER_READER_XMP_ID', "http://ns.adobe.com/xap/1.0/\0");

/** <x:xmpmeta ... </x:xmpmeta> dans une chaîne, ou null. */
function tagmanager_xmpmeta_in($s)
{
  $a = strpos($s, '<x:xmpmeta');
  if ($a === false)
  {
    return null;
  }
  $b = strpos($s, '</x:xmpmeta>', $a);
  return $b === false ? null : substr($s, $a, $b - $a + 12);
}

/**
 * Paquet XMP d'un JPEG, lu dans le segment APP1 qui porte l'identifiant XMP standard.
 * On ne cherche PAS « le premier <x:xmpmeta » du fichier : un bloc Exif peut contenir un paquet XMP
 * vide (ajouté par Picasa, par exemple) AVANT le vrai XMP, et on lirait alors un fichier sans tags.
 *
 * @return string|null|false  false : ce n'est pas un JPEG exploitable (l'appelant peut chercher autrement)
 */
function tagmanager_read_xmp_segment($fh)
{
  if (fread($fh, 2) !== "\xFF\xD8")
  {
    return false;
  }
  $idlen = strlen(TAGMANAGER_READER_XMP_ID);
  while (true)
  {
    $b = fread($fh, 1);
    if ($b === false || $b === '')
    {
      return null;
    }
    if ($b !== "\xFF")
    {
      return null;
    }
    do { $m = fread($fh, 1); } while ($m === "\xFF");
    if ($m === false || $m === '')
    {
      return null;
    }
    $m = ord($m);
    if ($m === 0xDA || $m === 0xD9)
    {
      return null; // début des données d'image : plus de métadonnées
    }
    if ($m === 0x01 || ($m >= 0xD0 && $m <= 0xD8))
    {
      continue;
    }
    $l = fread($fh, 2);
    if (strlen($l) !== 2)
    {
      return null;
    }
    $len = (ord($l[0]) << 8) | ord($l[1]);
    if ($len < 2)
    {
      return null;
    }
    $size = $len - 2;
    if ($m === 0xE1 && $size > $idlen)
    {
      $payload = fread($fh, $size);
      if (strlen($payload) === $size && substr($payload, 0, $idlen) === TAGMANAGER_READER_XMP_ID)
      {
        $x = tagmanager_xmpmeta_in(substr($payload, $idlen));
        if ($x !== null)
        {
          return $x;
        }
      }
      continue;
    }
    if (fseek($fh, $size, SEEK_CUR) !== 0)
    {
      return null;
    }
  }
}

/**
 * Extrait le paquet XMP (<x:xmpmeta ... </x:xmpmeta>) d'un fichier.
 * JPEG : segment XMP standard. Autre format : première occurrence dans les 4 premiers Mo.
 *
 * @return string|null
 */
function tagmanager_read_xmp_packet($file)
{
  $fh = @fopen($file, 'rb');
  if ($fh === false)
  {
    return null;
  }

  $seg = tagmanager_read_xmp_segment($fh);
  if ($seg !== false)
  {
    fclose($fh);
    return $seg;
  }
  rewind($fh);

  $buf = '';
  $max = 4 * 1024 * 1024;
  $needle = '<x:xmpmeta';
  $end = '</x:xmpmeta>';

  while (!feof($fh) && strlen($buf) < $max)
  {
    $chunk = fread($fh, 262144);
    if ($chunk === false || $chunk === '')
    {
      break;
    }
    $buf .= $chunk;

    $start = strpos($buf, $needle);
    if ($start !== false)
    {
      $stop = strpos($buf, $end, $start);
      if ($stop !== false)
      {
        fclose($fh);
        return substr($buf, $start, $stop - $start + strlen($end));
      }
    }
  }

  fclose($fh);
  return null;
}

/**
 * Chemins hiérarchiques d'un paquet XMP.
 * Source préférée : lr:hierarchicalSubject (séparateur "|", sans ambiguïté).
 * Repli : digiKam:TagsList (séparateur "/").
 *
 * @return array liste de chemins, chaque chemin étant un tableau de segments
 */
function tagmanager_parse_hierarchy($xmp)
{
  $dom = new DOMDocument();
  $prev = libxml_use_internal_errors(true);
  $ok = $dom->loadXML($xmp, LIBXML_NONET);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);
  if (!$ok)
  {
    return array();
  }

  $list = function ($ns, $name) use ($dom)
  {
    $out = array();
    foreach ($dom->getElementsByTagNameNS($ns, $name) as $node)
    {
      foreach ($node->getElementsByTagNameNS(TAGMANAGER_NS_RDF, 'li') as $li)
      {
        $v = trim($li->textContent);
        if ($v !== '')
        {
          $out[] = $v;
        }
      }
    }
    return $out;
  };

  $paths = array();

  // Certains logiciels écrivent un séparateur initial ("|ALBUM|…") : on écarte les niveaux vides
  $clean = function ($segments)
  {
    return array_values(array_filter(array_map('trim', $segments), function ($s) { return $s !== ''; }));
  };

  foreach ($list(TAGMANAGER_NS_LR, 'hierarchicalSubject') as $v)
  {
    $p = $clean(explode('|', $v));
    if (!empty($p))
    {
      $paths[] = $p;
    }
  }

  if (empty($paths))
  {
    foreach ($list(TAGMANAGER_NS_DK, 'TagsList') as $v)
    {
      $p = $clean(explode('/', $v));
      if (!empty($p))
      {
        $paths[] = $p;
      }
    }
  }

  return $paths;
}

/**
 * @return array|null null si le fichier est illisible, sinon la liste des chemins (éventuellement vide)
 */
function tagmanager_read_hierarchy($file)
{
  // is_readable() est peu fiable sur un lecteur réseau Windows : on teste l'ouverture réelle
  if (!is_file($file))
  {
    return null;
  }
  $probe = @fopen($file, 'rb');
  if ($probe === false)
  {
    return null;
  }
  fclose($probe);

  $xmp = tagmanager_read_xmp_packet($file);
  if ($xmp === null)
  {
    return array();
  }

  return tagmanager_parse_hierarchy($xmp);
}

?>
