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

define('TAGMANAGER_NS_MWGRS', 'http://www.metadataworkinggroup.com/schemas/regions/');
define('TAGMANAGER_NS_MPREG', 'http://ns.microsoft.com/photo/1.2/t/Region#');

/**
 * Noms des cadres de visage d'un paquet XMP, lus comme face_tag : régions MWG (digiKam, Lightroom,
 * face_tag_editor ; nom en balise ou en attribut) et régions Microsoft (MPReg:PersonDisplayName).
 * C'est la norme qui dit qu'un nom est une personne, quelle que soit la racine de hiérarchie
 * (« Personnes », « People », « Persone »… selon la langue de digiKam).
 * Les régions MWG de type BarCode ou Focus ne sont pas des visages.
 *
 * Un cadre n'est compté que s'il a une zone (MWG : mwg-rs:Area ; Microsoft : MPReg:Rectangle), comme face_tag :
 * une entrée qui porte un nom SEUL n'est pas un cadre de visage (cas réel, photo n° 214023 : « Rosine Chevalier »
 * sans rectangle, à côté des cadres d'Alain, Annie et Charles ; logiciel d'origine non établi).
 *
 * @return string[] noms distincts (orthographe du fichier), régions MWG puis Microsoft
 */
function tagmanager_parse_faces($xmp)
{
  $d = tagmanager_parse_faces_detail($xmp);
  $out = $d['mwg'];
  foreach ($d['mp'] as $m)
  {
    $in = false;
    foreach ($out as $w)
    {
      if (strtolower($w) === strtolower($m)) { $in = true; break; }
    }
    if (!$in) { $out[] = $m; }
  }
  return $out;
}

/** L'élément de la région porte-t-il une zone (attribut ou élément enfant $local dans l'espace $ns) ? */
function tagmanager_region_has($owner, $ns, $local)
{
  if (!($owner instanceof DOMElement))
  {
    return false;
  }
  if ($owner->hasAttributeNS($ns, $local))
  {
    return true;
  }
  foreach ($owner->childNodes as $c)
  {
    if ($c instanceof DOMElement && $c->namespaceURI === $ns && $c->localName === $local)
    {
      return true;
    }
  }
  return false;
}

/** @return array mwg => noms des régions MWG, mp => noms des régions Microsoft */
function tagmanager_parse_faces_detail($xmp)
{
  if (strpos($xmp, 'regions/') === false && strpos($xmp, 'PersonDisplayName') === false)
  {
    return array('mwg' => array(), 'mp' => array()); // ni MWG ni Microsoft : rien à analyser
  }
  $dom = new DOMDocument();
  $prev = libxml_use_internal_errors(true);
  $ok = $dom->loadXML($xmp, LIBXML_NONET);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);
  if (!$ok)
  {
    return array('mwg' => array(), 'mp' => array());
  }
  $xp = new DOMXPath($dom);
  $xp->registerNamespace('mwgrs', TAGMANAGER_NS_MWGRS);
  $xp->registerNamespace('mp', TAGMANAGER_NS_MPREG);

  $names = array();
  $add = function ($v) use (&$names)
  {
    $v = trim($v);
    if ($v !== '' && !in_array($v, $names, true))
    {
      $names[] = $v;
    }
  };
  $mp = array();
  $add_mp = function ($v) use (&$mp)
  {
    $v = trim($v);
    if ($v !== '' && !in_array($v, $mp, true))
    {
      $mp[] = $v;
    }
  };

  // MWG : <mwg-rs:Name>…</mwg-rs:Name> ou mwg-rs:Name="…", à côté du Type de la même région
  foreach ($xp->query('//mwgrs:Name | //@mwgrs:Name') as $n)
  {
    $owner = ($n instanceof DOMAttr) ? $n->ownerElement : $n->parentNode;
    $type = '';
    if ($owner instanceof DOMElement)
    {
      if ($owner->hasAttributeNS(TAGMANAGER_NS_MWGRS, 'Type'))
      {
        $type = $owner->getAttributeNS(TAGMANAGER_NS_MWGRS, 'Type');
      }
      else
      {
        foreach ($owner->childNodes as $c)
        {
          if ($c instanceof DOMElement && $c->namespaceURI === TAGMANAGER_NS_MWGRS && $c->localName === 'Type')
          {
            $type = $c->textContent;
            break;
          }
        }
      }
    }
    if (in_array(strtolower(trim($type)), array('barcode', 'focus'), true))
    {
      continue;
    }
    if (!tagmanager_region_has($owner, TAGMANAGER_NS_MWGRS, 'Area'))
    {
      continue; // nom sans zone : pas un cadre
    }
    $add($n instanceof DOMAttr ? $n->value : $n->textContent);
  }
  foreach ($xp->query('//mp:PersonDisplayName | //@mp:PersonDisplayName') as $n)
  {
    $owner = ($n instanceof DOMAttr) ? $n->ownerElement : $n->parentNode;
    if (!tagmanager_region_has($owner, TAGMANAGER_NS_MPREG, 'Rectangle'))
    {
      continue; // nom sans rectangle : pas un cadre de visage
    }
    $add_mp($n instanceof DOMAttr ? $n->value : $n->textContent);
  }
  return array('mwg' => $names, 'mp' => $mp);
}

/**
 * Chemins ET noms des cadres de visage d'un fichier, en une seule lecture.
 * @return array|null null si le fichier est illisible, sinon array('paths' => …, 'faces' => …)
 */
function tagmanager_read_hierarchy_faces($file)
{
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
    return array('paths' => array(), 'faces' => array());
  }
  return array('paths' => tagmanager_parse_hierarchy($xmp), 'faces' => tagmanager_parse_faces($xmp));
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
