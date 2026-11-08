<?php
/**
 * Lecture seule des chemins hiérarchiques de tags dans le XMP d'un fichier image.
 * PHP pur : ni Imagick ni exiftool.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

define('TAGMANAGER_NS_LR', 'http://ns.adobe.com/lightroom/1.0/');
define('TAGMANAGER_NS_DK', 'http://www.digikam.org/ns/1.0/');
define('TAGMANAGER_NS_RDF', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');

/**
 * Extrait le paquet XMP (<x:xmpmeta ... </x:xmpmeta>) du début du fichier.
 * Lit par blocs jusqu'à 4 Mo : le XMP d'un JPEG est dans les premiers segments.
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
