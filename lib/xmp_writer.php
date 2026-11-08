<?php
/**
 * Moteur d'écriture des tags dans les métadonnées d'un JPEG (XMP + IPTC), sans ré-encoder l'image.
 *
 * Principe : chaque liste de mots-clés (dc:subject, lr:hierarchicalSubject, digiKam:TagsList,
 * MicrosoftPhoto:LastKeywordXMP, IPTC 2:25) est modifiée entrée par entrée. Une entrée que
 * l'opération ne concerne pas est conservée telle quelle, caractère pour caractère. Tout le reste
 * du XMP (historique, notes, régions de visages...) et du fichier est préservé.
 *
 * Opérations (tableaux) :
 *   array('op' => 'add', 'path' => array('ALBUM', 'X', 'Tag'))
 *       ajoute le tag (chemin complet) s'il manque
 *   array('op' => 'retag', 'from' => 'Tag', 'to' => array(...) | null, 'ensure' => bool)
 *       toute entrée dont le dernier niveau est 'from' devient le chemin 'to' (null : suppression).
 *       Sert à renommer, fusionner, supprimer. 'ensure' : si le fichier porte le tag à plat sans
 *       chemin, ajoute aussi le chemin (sert à « ranger » un tag).
 *   array('op' => 'replace_prefix', 'from' => array(...), 'to' => array(...))
 *       renomme ou déplace un groupe : tout chemin qui commence par 'from' commence par 'to'.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

include_once(dirname(__FILE__) . '/xmp_reader.php');
include_once(dirname(__FILE__) . '/jpeg.php');

define('TAGMANAGER_NS_DC', 'http://purl.org/dc/elements/1.1/');
define('TAGMANAGER_NS_MS', 'http://ns.microsoft.com/photo/1.0/');

/** Champs XMP gérés. 'kind' : leaf (un niveau), hier (séparateur |), tags (séparateur /). */
function tagmanager_xmp_fields()
{
  return array(
    'leaf' => array('p' => 'dc',             'ns' => TAGMANAGER_NS_DC, 'n' => 'subject',             'box' => 'Bag', 'create' => true),
    'hier' => array('p' => 'lr',             'ns' => TAGMANAGER_NS_LR, 'n' => 'hierarchicalSubject', 'box' => 'Bag', 'create' => true),
    'tags' => array('p' => 'digiKam',        'ns' => TAGMANAGER_NS_DK, 'n' => 'TagsList',            'box' => 'Seq', 'create' => true),
    'ms'   => array('p' => 'MicrosoftPhoto', 'ns' => TAGMANAGER_NS_MS, 'n' => 'LastKeywordXMP',      'box' => 'Bag', 'create' => false),
  );
}

// ---------------------------------------------------------------------------------------------
// Opérations sur les listes
// ---------------------------------------------------------------------------------------------

function tagmanager_split($s, $sep)
{
  return array_values(array_filter(array_map('trim', explode($sep, $s)), function ($x) { return $x !== ''; }));
}

/** @return array|null segments transformés, null pour supprimer l'entrée */
function tagmanager_path_op(array $segs, array $op)
{
  if ($op['op'] === 'retag')
  {
    if (!empty($segs) && end($segs) === $op['from'])
    {
      return $op['to'] === null ? null : $op['to'];
    }
  }
  elseif ($op['op'] === 'replace_prefix')
  {
    $k = count($op['from']);
    if ($k > 0 && count($segs) >= $k && array_slice($segs, 0, $k) === $op['from'])
    {
      return array_merge($op['to'], array_slice($segs, $k));
    }
  }
  return $segs;
}

/** @return string|null */
function tagmanager_leaf_op($leaf, array $op)
{
  if ($op['op'] === 'retag' && $leaf === $op['from'])
  {
    return $op['to'] === null ? null : end($op['to']);
  }
  return $leaf;
}

function tagmanager_has_path(array $list, $sep, array $path)
{
  foreach ($list as $s)
  {
    if (tagmanager_split($s, $sep) === $path)
    {
      return true;
    }
  }
  return false;
}

function tagmanager_has_leaf_path(array $list, $sep, $leaf)
{
  foreach ($list as $s)
  {
    $segs = tagmanager_split($s, $sep);
    if (!empty($segs) && end($segs) === $leaf)
    {
      return true;
    }
  }
  return false;
}

/**
 * Applique les opérations à une liste.
 * @param string $kind leaf | hier | tags
 */
function tagmanager_list_apply(array $list, $kind, array $ops)
{
  $sep = ($kind === 'hier') ? '|' : '/';
  foreach ($ops as $op)
  {
    $out = array();
    foreach ($list as $s)
    {
      if ($kind === 'leaf')
      {
        $n = tagmanager_leaf_op($s, $op);
        if ($n !== null)
        {
          $out[] = $n;
        }
        continue;
      }
      $segs = tagmanager_split($s, $sep);
      $n = tagmanager_path_op($segs, $op);
      if ($n === null)
      {
        continue;
      }
      $out[] = ($n === $segs) ? $s : implode($sep, $n);
    }

    if ($op['op'] === 'add')
    {
      if ($kind === 'leaf')
      {
        $leaf = end($op['path']);
        if (!in_array($leaf, $out, true))
        {
          $out[] = $leaf;
        }
      }
      elseif (!tagmanager_has_path($out, $sep, $op['path']))
      {
        $out[] = implode($sep, $op['path']);
      }
    }

    $list = array_values(array_unique($out));
  }
  return $list;
}

/**
 * Applique les opérations à l'ensemble des listes d'un fichier.
 * @param array $L  clés leaf, hier, tags, ms (null si le champ est absent et ne doit pas être créé)
 */
function tagmanager_lists_apply(array $L, array $ops)
{
  foreach (array('leaf', 'hier', 'tags', 'ms') as $k)
  {
    if ($L[$k] !== null)
    {
      $L[$k] = tagmanager_list_apply($L[$k], $k === 'ms' ? 'tags' : $k, $ops);
    }
  }

  foreach ($ops as $op)
  {
    if ($op['op'] === 'retag' && !empty($op['ensure']) && !empty($op['to']))
    {
      $leaf = end($op['to']);
      if (in_array($leaf, $L['leaf'], true))
      {
        if (!tagmanager_has_leaf_path($L['hier'], '|', $leaf))
        {
          $L['hier'][] = implode('|', $op['to']);
        }
        if (!tagmanager_has_leaf_path($L['tags'], '/', $leaf))
        {
          $L['tags'][] = implode('/', $op['to']);
        }
      }
    }
  }
  return $L;
}

// ---------------------------------------------------------------------------------------------
// XMP
// ---------------------------------------------------------------------------------------------

function tagmanager_xmp_skeleton()
{
  return '<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="tag_manager"><rdf:RDF xmlns:rdf="' . TAGMANAGER_NS_RDF . '">'
    . '<rdf:Description rdf:about=""/></rdf:RDF></x:xmpmeta>';
}

function tagmanager_xmp_read_list(DOMElement $field)
{
  $out = array();
  foreach ($field->getElementsByTagNameNS(TAGMANAGER_NS_RDF, 'li') as $li)
  {
    $out[] = trim($li->textContent);
  }
  return array_values(array_filter($out, function ($v) { return $v !== ''; }));
}

/**
 * Modifie un paquet XMP.
 *
 * @param string|null $packet paquet existant (<x:xmpmeta ...>), null s'il n'y en a pas
 * @return array  error (string) ou changed (bool), packet (string), fields (liste), warnings
 */
function tagmanager_xmp_modify($packet, array $ops)
{
  $res = array('changed' => false, 'packet' => null, 'fields' => array(), 'warnings' => array(), 'error' => null);

  $doc = new DOMDocument();
  $doc->preserveWhiteSpace = true;
  $prev = libxml_use_internal_errors(true);
  $ok = $doc->loadXML($packet === null ? tagmanager_xmp_skeleton() : $packet, LIBXML_NONET);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);
  if (!$ok)
  {
    $res['error'] = 'xmp_illisible'; // on n'écrase jamais un XMP que l'on ne sait pas lire
    return $res;
  }

  $rdfs = $doc->getElementsByTagNameNS(TAGMANAGER_NS_RDF, 'RDF');
  if ($rdfs->length === 0)
  {
    $res['error'] = 'xmp_sans_rdf';
    return $res;
  }
  $rdf = $rdfs->item(0);
  $descs = $rdf->getElementsByTagNameNS(TAGMANAGER_NS_RDF, 'Description');
  if ($descs->length === 0)
  {
    $d = $doc->createElement('rdf:Description');
    $d->setAttribute('rdf:about', '');
    $rdf->appendChild($d);
    $desc0 = $d;
  }
  else
  {
    $desc0 = $descs->item(0);
  }

  if ($packet !== null && strpos($packet, 'HasExtendedXMP') !== false)
  {
    $res['warnings'][] = 'xmp_etendu';
  }

  $fields = tagmanager_xmp_fields();
  $nodes = array();
  $L = array();
  foreach ($fields as $k => $f)
  {
    $list = $doc->getElementsByTagNameNS($f['ns'], $f['n']);
    $nodes[$k] = $list->length ? $list->item(0) : null;
    $L[$k] = $nodes[$k] ? tagmanager_xmp_read_list($nodes[$k]) : ($f['create'] ? array() : null);
  }

  $N = tagmanager_lists_apply($L, $ops);

  $rdfp = $desc0->lookupPrefix(TAGMANAGER_NS_RDF);
  if (!$rdfp)
  {
    $rdfp = 'rdf';
  }

  foreach ($fields as $k => $f)
  {
    if ($N[$k] === null || $N[$k] === $L[$k])
    {
      continue;
    }
    $res['changed'] = true;
    $res['fields'][] = $f['p'] . ':' . $f['n'];

    $node = $nodes[$k];
    if (empty($N[$k]))
    {
      if ($node)
      {
        $node->parentNode->removeChild($node);
      }
      continue;
    }

    if (!$node)
    {
      $prefix = $desc0->lookupPrefix($f['ns']);
      if (!$prefix)
      {
        $prefix = $f['p'];
        $desc0->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $f['ns']);
      }
      $node = $doc->createElement($prefix . ':' . $f['n']);
      $desc0->appendChild($node);
    }

    // Conteneur existant (Bag / Seq / Alt) conservé tel quel, sinon créé
    $box = null;
    foreach ($node->childNodes as $c)
    {
      if ($c->nodeType === XML_ELEMENT_NODE && $c->namespaceURI === TAGMANAGER_NS_RDF
        && in_array($c->localName, array('Bag', 'Seq', 'Alt'), true))
      {
        $box = $c;
        break;
      }
    }
    if (!$box)
    {
      $box = $doc->createElement($rdfp . ':' . $f['box']);
      $node->appendChild($box);
    }
    while ($box->firstChild)
    {
      $box->removeChild($box->firstChild);
    }
    foreach ($N[$k] as $v)
    {
      $li = $doc->createElement($rdfp . ':li');
      $li->appendChild($doc->createTextNode($v));
      $box->appendChild($li);
    }
  }

  if (!$res['changed'])
  {
    return $res;
  }

  $doc->encoding = 'UTF-8';
  $xml = $doc->saveXML($doc->documentElement);
  $packet_out = "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n" . $xml . "\n<?xpacket end=\"w\"?>";

  if (strlen($packet_out) > TAGMANAGER_XMP_MAX)
  {
    $res['error'] = 'xmp_trop_gros'; // on ne sacrifie jamais d'autres métadonnées pour faire tenir le paquet
    return $res;
  }

  $res['packet'] = $packet_out;
  return $res;
}

// ---------------------------------------------------------------------------------------------
// Fichier
// ---------------------------------------------------------------------------------------------

/**
 * Applique des opérations de tags à un fichier JPEG.
 *
 * @param string $path chemin réel du fichier
 * @param array  $ops  opérations (voir l'en-tête)
 * @param array  $opt  original (bool : créer path.original s'il n'existe pas), dry_run (bool)
 * @return array changed (bool), error (string|null), warnings (array), fields (array)
 */
function tagmanager_write_file($path, array $ops, array $opt = array())
{
  $res = array('changed' => false, 'error' => null, 'warnings' => array(), 'fields' => array());

  $data = @file_get_contents($path);
  if ($data === false)
  {
    $res['error'] = 'lecture';
    return $res;
  }

  $jpeg = tagmanager_jpeg_parse($data);
  if ($jpeg === null)
  {
    $res['error'] = 'pas_jpeg'; // seuls les JPEG sont gérés pour l'instant
    return $res;
  }
  $segs = $jpeg['segs'];

  // --- XMP ---
  $xi = tagmanager_jpeg_find($data, $segs, 0xE1, TAGMANAGER_XMP_ID);
  $packet = null;
  if ($xi >= 0)
  {
    $l = strlen(TAGMANAGER_XMP_ID);
    $packet = substr($data, $segs[$xi]['p'] + $l, $segs[$xi]['e'] - $segs[$xi]['p'] - $l);
    $s = strpos($packet, '<x:xmpmeta');
    $e = strrpos($packet, '</x:xmpmeta>');
    if ($s === false || $e === false)
    {
      $res['error'] = 'xmp_illisible';
      return $res;
    }
    $packet = substr($packet, $s, $e - $s + strlen('</x:xmpmeta>'));
  }
  $x = tagmanager_xmp_modify($packet, $ops);
  if ($x['error'])
  {
    $res['error'] = $x['error'];
    return $res;
  }
  $res['warnings'] = array_merge($res['warnings'], $x['warnings']);
  $res['fields'] = $x['fields'];

  // --- IPTC ---
  $ii = tagmanager_jpeg_find($data, $segs, 0xED, TAGMANAGER_PS_ID);
  $ipayload = ($ii >= 0) ? substr($data, $segs[$ii]['p'], $segs[$ii]['e'] - $segs[$ii]['p']) : null;
  $iptc_new = tagmanager_iptc_modify($ipayload, $ops, $res['warnings']);
  if ($iptc_new !== null)
  {
    $res['fields'][] = 'iptc:2#025';
  }

  if (!$x['changed'] && $iptc_new === null)
  {
    return $res; // rien à écrire : le fichier n'est pas touché
  }

  // --- Assemblage : tout est recopié sauf les deux segments remplacés ---
  $new_xmp_seg = $x['changed'] ? tagmanager_jpeg_segment(0xE1, TAGMANAGER_XMP_ID . $x['packet']) : null;
  $new_ipt_seg = ($iptc_new !== null) ? tagmanager_jpeg_segment(0xED, $iptc_new) : null;
  $ins = tagmanager_jpeg_insert_index($data, $segs);

  $out = "\xFF\xD8";
  $kept = array(); // segments recopiés, pour la vérification
  foreach ($segs as $i => $s)
  {
    if ($i === $ins)
    {
      if ($xi < 0 && $new_xmp_seg !== null)
      {
        $out .= $new_xmp_seg;
      }
      if ($ii < 0 && $new_ipt_seg !== null)
      {
        $out .= $new_ipt_seg;
      }
    }
    if ($i === $xi && $new_xmp_seg !== null)
    {
      $out .= $new_xmp_seg;
      continue;
    }
    if ($i === $ii && $new_ipt_seg !== null)
    {
      $out .= $new_ipt_seg;
      continue;
    }
    $bytes = substr($data, $s['s'], $s['e'] - $s['s']);
    $kept[] = md5($bytes);
    $out .= $bytes;
  }
  if ($ins >= count($segs))
  {
    if ($xi < 0 && $new_xmp_seg !== null)
    {
      $out .= $new_xmp_seg;
    }
    if ($ii < 0 && $new_ipt_seg !== null)
    {
      $out .= $new_ipt_seg;
    }
  }
  $out .= substr($data, $jpeg['sos']);

  // --- Vérification avant toute écriture ---
  $chk = tagmanager_jpeg_parse($out);
  if ($chk === null)
  {
    $res['error'] = 'verification_structure';
    return $res;
  }
  if (substr($out, $chk['sos']) !== substr($data, $jpeg['sos']))
  {
    $res['error'] = 'verification_image'; // les données de l'image doivent rester identiques
    return $res;
  }
  $got = array();
  $replaced_x = $x['changed'] ? tagmanager_jpeg_find($out, $chk['segs'], 0xE1, TAGMANAGER_XMP_ID) : -1;
  $replaced_i = ($iptc_new !== null) ? tagmanager_jpeg_find($out, $chk['segs'], 0xED, TAGMANAGER_PS_ID) : -1;
  foreach ($chk['segs'] as $i => $s)
  {
    if (($x['changed'] && $i === $replaced_x) || ($iptc_new !== null && $i === $replaced_i))
    {
      continue;
    }
    $got[] = md5(substr($out, $s['s'], $s['e'] - $s['s']));
  }
  if ($got !== $kept)
  {
    $res['error'] = 'verification_segments';
    return $res;
  }
  if ($x['changed'])
  {
    $p2 = tagmanager_read_xmp_packet_from_string($out);
    if ($p2 === null || tagmanager_xmp_modify($p2, array())['error'] !== null)
    {
      $res['error'] = 'verification_xmp';
      return $res;
    }
  }

  $res['changed'] = true;
  if (!empty($opt['dry_run']))
  {
    return $res;
  }

  // --- Écriture : copie .original facultative, puis fichier temporaire et renommage ---
  if (!empty($opt['original']) && !file_exists($path . '.original'))
  {
    if (!@copy($path, $path . '.original'))
    {
      $res['changed'] = false;
      $res['error'] = 'copie_original';
      return $res;
    }
    @chmod($path . '.original', 0444);
  }

  $tmp = $path . '.tmp' . getmypid();
  $mode = @fileperms($path);
  if (@file_put_contents($tmp, $out, LOCK_EX) !== strlen($out))
  {
    @unlink($tmp);
    $res['changed'] = false;
    $res['error'] = 'ecriture';
    return $res;
  }
  if ($mode !== false)
  {
    @chmod($tmp, $mode & 0777);
  }
  if (!@rename($tmp, $path))
  {
    @unlink($tmp);
    $res['changed'] = false;
    $res['error'] = 'renommage';
    return $res;
  }
  clearstatcache(true, $path);

  return $res;
}

/** Paquet XMP d'un JPEG déjà en mémoire (même logique que tagmanager_read_xmp_packet). */
function tagmanager_read_xmp_packet_from_string($d)
{
  $s = strpos($d, '<x:xmpmeta');
  if ($s === false)
  {
    return null;
  }
  $e = strpos($d, '</x:xmpmeta>', $s);
  return ($e === false) ? null : substr($d, $s, $e - $s + strlen('</x:xmpmeta>'));
}

?>
