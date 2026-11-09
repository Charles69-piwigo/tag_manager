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
include_once(dirname(__FILE__) . '/exif_keywords.php');

define('TAGMANAGER_NS_DC', 'http://purl.org/dc/elements/1.1/');
define('TAGMANAGER_NS_MS', 'http://ns.microsoft.com/photo/1.0/');
define('TAGMANAGER_NS_MS2', 'http://ns.microsoft.com/photo/1.0'); // forme écrite par ExifTool (sans « / » final)
define('TAGMANAGER_NS_MP', 'http://ns.iview-multimedia.com/mediapro/1.0/');
define('TAGMANAGER_NS_ACD', 'http://ns.acdsee.com/iptc/1.0/');

/**
 * Registre des champs XMP de mots-clés (tous écrits par digiKam). Tout champ qui porte un tag doit
 * figurer ici, sinon un renommage le laisse périmé (le contrôle « champ_non_gere » le signalerait).
 *
 *   ns     : espaces de noms acceptés en lecture (le premier sert à la création) ; les deux formes
 *            avec et sans « / » final existent dans les fichiers
 *   names  : noms locaux acceptés (la casse varie selon les logiciels) ; le premier sert à la création
 *   kind   : leaf (un niveau), hier (chemin, séparateur |), tags (chemin, séparateur /),
 *            acdsee (arbre XML dans un texte ; vu comme une liste de chemins « / »)
 *   create : true = le champ est créé s'il manque ; false = mis à jour seulement s'il existe
 */
function tagmanager_xmp_fields()
{
  return array(
    'leaf' => array('p' => 'dc',             'ns' => array(TAGMANAGER_NS_DC),                         'names' => array('subject'),             'kind' => 'leaf',   'box' => 'Bag', 'create' => true),
    'hier' => array('p' => 'lr',             'ns' => array(TAGMANAGER_NS_LR),                         'names' => array('hierarchicalSubject'), 'kind' => 'hier',   'box' => 'Bag', 'create' => true),
    'tags' => array('p' => 'digiKam',        'ns' => array(TAGMANAGER_NS_DK),                         'names' => array('TagsList'),            'kind' => 'tags',   'box' => 'Seq', 'create' => true),
    'ms'   => array('p' => 'MicrosoftPhoto', 'ns' => array(TAGMANAGER_NS_MS, TAGMANAGER_NS_MS2),      'names' => array('LastKeywordXMP'),      'kind' => 'tags',   'box' => 'Bag', 'create' => false),
    'cat'  => array('p' => 'mediapro',       'ns' => array(TAGMANAGER_NS_MP),                         'names' => array('CatalogSets'),         'kind' => 'hier',   'box' => 'Bag', 'create' => false),
    'acd'  => array('p' => 'acdsee',         'ns' => array(TAGMANAGER_NS_ACD),                        'names' => array('categories', 'Categories'), 'kind' => 'acdsee', 'box' => null,  'create' => false),
  );
}

/** Premier nœud du document qui correspond au champ (toutes les formes d'espace de noms et de nom). */
function tagmanager_xmp_find_node(DOMDocument $doc, array $f)
{
  foreach ($f['ns'] as $ns)
  {
    foreach ($f['names'] as $n)
    {
      $l = $doc->getElementsByTagNameNS($ns, $n);
      if ($l->length)
      {
        return $l->item(0);
      }
    }
  }
  return null;
}

// ---------------------------------------------------------------------------------------------
// acdsee:categories : <Categories><Category Assigned="0">ALBUM4<Category Assigned="1">Tag</Category>
// </Category></Categories> (un texte XML dans la valeur). Assigned="1" = tag réellement porté,
// "0" = simple ancêtre. Vu comme une liste de chemins « A/B/C » pour réutiliser les opérations.
// ---------------------------------------------------------------------------------------------

/** @return array|null chemins « A/B » des catégories portées ; null si le texte est illisible */
function tagmanager_acdsee_decode($text)
{
  $doc = new DOMDocument();
  $prev = libxml_use_internal_errors(true);
  $ok = $doc->loadXML($text, LIBXML_NONET);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);
  if (!$ok || $doc->documentElement->nodeName !== 'Categories')
  {
    return null;
  }
  $out = array();
  $walk = function (DOMElement $el, array $chain) use (&$walk, &$out)
  {
    foreach ($el->childNodes as $c)
    {
      if ($c->nodeType !== XML_ELEMENT_NODE || $c->nodeName !== 'Category')
      {
        continue;
      }
      $label = '';
      foreach ($c->childNodes as $t)
      {
        if ($t->nodeType === XML_TEXT_NODE || $t->nodeType === XML_CDATA_SECTION_NODE)
        {
          $label .= $t->nodeValue;
        }
      }
      $chain2 = array_merge($chain, array(trim($label)));
      if ($c->getAttribute('Assigned') === '1')
      {
        $out[] = implode('/', $chain2);
      }
      $walk($c, $chain2);
    }
  };
  $walk($doc->documentElement, array());
  return $out;
}

/** Reconstruit le texte XML à partir des chemins portés (ancêtres : Assigned="0"). */
function tagmanager_acdsee_encode(array $paths)
{
  $tree = array(); // nom => array('a' => porté, 'c' => enfants)
  foreach ($paths as $p)
  {
    $segs = tagmanager_split($p, '/');
    $level = &$tree;
    foreach ($segs as $i => $s)
    {
      if (!isset($level[$s]))
      {
        $level[$s] = array('a' => false, 'c' => array());
      }
      if ($i === count($segs) - 1)
      {
        $level[$s]['a'] = true;
      }
      $level = &$level[$s]['c'];
    }
    unset($level);
  }
  $render = function (array $nodes) use (&$render)
  {
    $s = '';
    foreach ($nodes as $name => $n)
    {
      $s .= '<Category Assigned="' . ($n['a'] ? '1' : '0') . '">'
        . htmlspecialchars((string) $name, ENT_XML1 | ENT_NOQUOTES, 'UTF-8')
        . $render($n['c']) . '</Category>';
    }
    return $s;
  };
  return '<Categories>' . $render($tree) . '</Categories>';
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
  $fields = tagmanager_xmp_fields();
  foreach ($fields as $k => $f)
  {
    if (isset($L[$k]) && $L[$k] !== null)
    {
      $L[$k] = tagmanager_list_apply($L[$k], tagmanager_list_kind($f), $ops);
    }
  }

  foreach ($ops as $op)
  {
    if ($op['op'] === 'retag' && !empty($op['ensure']) && !empty($op['to']))
    {
      $leaf = end($op['to']);
      if (in_array($leaf, $L['leaf'], true))
      {
        foreach ($fields as $k => $f)
        {
          $kind = tagmanager_list_kind($f);
          if ($kind === 'leaf' || !isset($L[$k]) || $L[$k] === null)
          {
            continue;
          }
          $sep = ($kind === 'hier') ? '|' : '/';
          if (!tagmanager_has_leaf_path($L[$k], $sep, $leaf))
          {
            $L[$k][] = implode($sep, $op['to']);
          }
        }
      }
    }
  }
  return $L;
}

/** Nature de la liste pour tagmanager_list_apply : leaf, hier (|) ou tags (/). */
function tagmanager_list_kind(array $f)
{
  return $f['kind'] === 'acdsee' ? 'tags' : $f['kind'];
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

/** La valeur $t porte-t-elle encore ce que l'opération devait faire disparaître ? */
function tagmanager_value_is_stale($t, array $op)
{
  $t = trim($t);
  if ($t === '')
  {
    return false;
  }
  if ($op['op'] === 'retag')
  {
    $same = ($op['to'] !== null && end($op['to']) === $op['from']);
    if ($same)
    {
      return false;
    }
    $c = array($t);
    foreach (array('|', '/') as $sep)
    {
      $segs = tagmanager_split($t, $sep);
      if (!empty($segs))
      {
        $c[] = end($segs);
      }
    }
    return in_array($op['from'], $c, true);
  }
  if ($op['op'] === 'replace_prefix')
  {
    $k = count($op['from']);
    if ($k > 0 && array_slice($op['to'], 0, $k) === $op['from'])
    {
      return false;
    }
    foreach (array('|', '/') as $sep)
    {
      $segs = tagmanager_split($t, $sep);
      if ($k > 0 && count($segs) >= $k && array_slice($segs, 0, $k) === $op['from'])
      {
        return true;
      }
    }
  }
  return false;
}

/**
 * Parcourt TOUT le XMP (comme le fait metadata_display) et liste les propriétés hors registre dont la
 * valeur serait encore périmée après l'opération (ex. mwg-kw, MicrosoftPhoto:LastKeywordIPTC).
 * @return array noms « préfixe:nom », sans doublon
 */
function tagmanager_xmp_unmanaged_hits(DOMDocument $doc, array $managed, array $ops)
{
  $hits = array();
  $check = array();
  foreach ($ops as $op)
  {
    if ($op['op'] === 'retag' || $op['op'] === 'replace_prefix')
    {
      $check[] = $op;
    }
  }
  if (empty($check))
  {
    return $hits;
  }
  foreach ($doc->getElementsByTagName('*') as $el)
  {
    $leafish = true;
    foreach ($el->childNodes as $c)
    {
      if ($c->nodeType === XML_ELEMENT_NODE)
      {
        $leafish = false;
        break;
      }
    }
    if (!$leafish || $el->namespaceURI === 'adobe:ns:meta/')
    {
      continue;
    }
    $prop = $el;
    while ($prop && $prop->nodeType === XML_ELEMENT_NODE && $prop->namespaceURI === TAGMANAGER_NS_RDF && $prop->localName !== 'Description')
    {
      $prop = $prop->parentNode;
    }
    if (!$prop || $prop->nodeType !== XML_ELEMENT_NODE || $prop->namespaceURI === TAGMANAGER_NS_RDF)
    {
      continue;
    }
    $skip = false;
    for ($a = $el; $a && $a->nodeType === XML_ELEMENT_NODE; $a = $a->parentNode)
    {
      if (isset($managed[spl_object_id($a)]))
      {
        $skip = true;
        break;
      }
    }
    if ($skip)
    {
      continue;
    }
    foreach ($check as $op)
    {
      if (tagmanager_value_is_stale($el->textContent, $op))
      {
        $hits[$prop->nodeName] = true;
        break;
      }
    }
  }
  return array_keys($hits);
}

/**
 * Listes de mots-clés lues dans un paquet XMP, par clé du registre (null : champ absent ou illisible).
 * Sert à contrôler le fichier produit.
 */
function tagmanager_xmp_lists($packet)
{
  $doc = new DOMDocument();
  $prev = libxml_use_internal_errors(true);
  $ok = $doc->loadXML($packet, LIBXML_NONET);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);
  $out = array();
  foreach (tagmanager_xmp_fields() as $k => $f)
  {
    $out[$k] = null;
    if (!$ok)
    {
      continue;
    }
    $n = tagmanager_xmp_find_node($doc, $f);
    if ($n)
    {
      $out[$k] = ($f['kind'] === 'acdsee') ? tagmanager_acdsee_decode($n->textContent) : tagmanager_xmp_read_list($n);
    }
  }
  return $out;
}

/**
 * Entrées encore périmées (champ => valeurs) dans des listes lues, après les opérations.
 * @param array $lists  clé => liste ; la clé « iptc » et « xp » désignent des listes de feuilles
 */
function tagmanager_stale_entries(array $lists, array $ops)
{
  $fields = tagmanager_xmp_fields();
  $bad = array();
  foreach ($lists as $k => $list)
  {
    if ($list === null)
    {
      continue;
    }
    $kind = isset($fields[$k]) ? tagmanager_list_kind($fields[$k]) : 'leaf';
    $sep = ($kind === 'hier') ? '|' : '/';
    foreach ($list as $v)
    {
      foreach ($ops as $op)
      {
        if ($op['op'] === 'retag' || $op['op'] === 'replace_prefix')
        {
          $segs = ($kind === 'leaf') ? array($v) : tagmanager_split($v, $sep);
          $stale = false;
          if ($op['op'] === 'retag')
          {
            $stale = !empty($segs) && end($segs) === $op['from']
              && !($op['to'] !== null && end($op['to']) === $op['from']);
          }
          else
          {
            $stale = $kind !== 'leaf' && tagmanager_value_is_stale($v, $op);
          }
          if ($stale)
          {
            $bad[$k][] = $v;
          }
        }
      }
    }
  }
  return $bad;
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
  $managed = array();
  foreach ($fields as $k => $f)
  {
    $nodes[$k] = tagmanager_xmp_find_node($doc, $f);
    if ($nodes[$k])
    {
      $managed[spl_object_id($nodes[$k])] = true;
      if ($f['kind'] === 'acdsee')
      {
        $L[$k] = tagmanager_acdsee_decode($nodes[$k]->textContent);
        if ($L[$k] === null)
        {
          $res['warnings'][] = 'acdsee_illisible'; // champ laissé tel quel
        }
      }
      else
      {
        $L[$k] = tagmanager_xmp_read_list($nodes[$k]);
      }
    }
    else
    {
      $L[$k] = $f['create'] ? array() : null;
    }
  }

  // Filet de sécurité : une valeur périmée dans un champ hors registre est signalée
  foreach (tagmanager_xmp_unmanaged_hits($doc, $managed, $ops) as $hit)
  {
    $res['warnings'][] = 'champ_non_gere:' . $hit;
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
    $res['fields'][] = $f['p'] . ':' . $f['names'][0];

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
      $prefix = $desc0->lookupPrefix($f['ns'][0]);
      if (!$prefix)
      {
        $prefix = $f['p'];
        $desc0->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $f['ns'][0]);
      }
      $node = $doc->createElement($prefix . ':' . $f['names'][0]);
      $desc0->appendChild($node);
    }

    if ($f['kind'] === 'acdsee')
    {
      while ($node->firstChild)
      {
        $node->removeChild($node->firstChild);
      }
      $node->appendChild($doc->createTextNode(tagmanager_acdsee_encode($N[$k])));
      continue;
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

  // --- Exif (XPKeywords) ---
  $ei = tagmanager_jpeg_find($data, $segs, 0xE1, TAGMANAGER_EXIF_ID);
  $exif_new = null;
  if ($ei >= 0)
  {
    $epayload = substr($data, $segs[$ei]['p'], $segs[$ei]['e'] - $segs[$ei]['p']);
    $exif_new = tagmanager_exif_modify($epayload, $ops, $res['warnings']);
    if ($exif_new !== null)
    {
      $res['fields'][] = 'exif:XPKeywords';
    }
  }

  if (!$x['changed'] && $iptc_new === null && $exif_new === null)
  {
    return $res; // rien à écrire : le fichier n'est pas touché
  }

  // --- Assemblage : tout est recopié sauf les deux segments remplacés ---
  $new_xmp_seg = $x['changed'] ? tagmanager_jpeg_segment(0xE1, TAGMANAGER_XMP_ID . $x['packet']) : null;
  $new_ipt_seg = ($iptc_new !== null) ? tagmanager_jpeg_segment(0xED, $iptc_new) : null;
  $new_exif_seg = ($exif_new !== null) ? tagmanager_jpeg_segment(0xE1, $exif_new) : null;
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
    if ($i === $ei && $new_exif_seg !== null)
    {
      $out .= $new_exif_seg;
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
  $replaced_e = ($exif_new !== null) ? tagmanager_jpeg_find($out, $chk['segs'], 0xE1, TAGMANAGER_EXIF_ID) : -1;
  foreach ($chk['segs'] as $i => $s)
  {
    if (($x['changed'] && $i === $replaced_x) || ($iptc_new !== null && $i === $replaced_i) || ($exif_new !== null && $i === $replaced_e))
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

  // Aucun champ du registre ne doit porter encore l'ancien nom (relecture du fichier produit)
  $stale = tagmanager_stale_in_file($out, $chk['segs'], $ops);
  foreach (array('iptc' => '/^iptc_/', 'xp' => '/^exif_/') as $key => $re)
  {
    // Champ que le moteur a déclaré ne pas savoir traiter (jeu de caractères...) : simple avertissement
    if (isset($stale[$key]) && count(preg_grep($re, $res['warnings'])))
    {
      $res['warnings'][] = 'champ_perime:' . $key;
      unset($stale[$key]);
    }
  }
  if (!empty($stale))
  {
    $res['error'] = 'verification_champs'; // ex. « dc:subject »
    $res['warnings'][] = 'champs_perimes:' . implode(',', array_keys($stale));
    return $res;
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

/**
 * Relit tous les champs de mots-clés d'un JPEG en mémoire (XMP, IPTC 2:25, Exif XPKeywords) et renvoie
 * ceux qui portent encore une valeur périmée après les opérations (clé du registre, « iptc » ou « xp »).
 */
function tagmanager_stale_in_file($out, array $segs, array $ops)
{
  $lists = array();
  $p = tagmanager_read_xmp_packet_from_string($out);
  if ($p !== null)
  {
    $lists = tagmanager_xmp_lists($p);
  }
  $ii = tagmanager_jpeg_find($out, $segs, 0xED, TAGMANAGER_PS_ID);
  if ($ii >= 0)
  {
    $ps = tagmanager_ps_parse(substr($out, $segs[$ii]['p'], $segs[$ii]['e'] - $segs[$ii]['p']));
    foreach (($ps ? $ps['res'] : array()) as $r)
    {
      if ($r['id'] === 0x0404 && ($ds = tagmanager_iptc_parse($r['data'])) !== null)
      {
        $lists['iptc'] = array();
        foreach ($ds as $d)
        {
          if ($d[0] === 2 && $d[1] === 25)
          {
            $lists['iptc'][] = tagmanager_is_utf8($d[2]) ? $d[2] : tagmanager_from_latin1($d[2]);
          }
        }
      }
    }
  }
  $ei = tagmanager_jpeg_find($out, $segs, 0xE1, TAGMANAGER_EXIF_ID);
  if ($ei >= 0)
  {
    $lists['xp'] = tagmanager_exif_keywords(substr($out, $segs[$ei]['p'], $segs[$ei]['e'] - $segs[$ei]['p']));
  }
  return tagmanager_stale_entries($lists, $ops);
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
