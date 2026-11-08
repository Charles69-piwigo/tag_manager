<?php
/**
 * Lecture et réécriture des segments de métadonnées d'un JPEG, SANS ré-encoder l'image.
 *
 * Seuls les segments APP1 XMP et APP13 (IPTC) sont remplacés ; tous les autres segments et toutes
 * les données de l'image (à partir du marqueur SOS) sont recopiés octet pour octet.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

define('TAGMANAGER_XMP_ID', "http://ns.adobe.com/xap/1.0/\0");
define('TAGMANAGER_XMP_EXT_ID', "http://ns.adobe.com/xmp/extension/\0");
define('TAGMANAGER_PS_ID', "Photoshop 3.0\0");
define('TAGMANAGER_XMP_MAX', 65504); // 65535 - 2 (longueur) - 29 (identifiant)

// ---------------------------------------------------------------------------------------------
// Structure JPEG
// ---------------------------------------------------------------------------------------------

/**
 * @return array|null array('segs' => [...], 'sos' => offset du marqueur SOS) ; null si le fichier
 *                    n'est pas un JPEG exploitable
 *   chaque segment : m (marqueur), s (début, 0xFF inclus), e (fin), p (début de la charge utile)
 */
function tagmanager_jpeg_parse($d)
{
  $n = strlen($d);
  if ($n < 4 || $d[0] !== "\xFF" || $d[1] !== "\xD8")
  {
    return null;
  }

  $segs = array();
  $o = 2;
  while ($o < $n)
  {
    if ($d[$o] !== "\xFF")
    {
      return null;
    }
    while ($o < $n && $d[$o] === "\xFF")
    {
      $o++; // octets de bourrage
    }
    if ($o >= $n)
    {
      return null;
    }
    $m = ord($d[$o]);
    $start = $o - 1;
    $o++;

    if ($m === 0xDA)
    {
      return array('segs' => $segs, 'sos' => $start);
    }
    if ($m === 0x01 || ($m >= 0xD0 && $m <= 0xD8))
    {
      $segs[] = array('m' => $m, 's' => $start, 'e' => $o, 'p' => $o);
      continue;
    }
    if ($o + 2 > $n)
    {
      return null;
    }
    $len = (ord($d[$o]) << 8) | ord($d[$o + 1]);
    if ($len < 2 || $o + $len > $n)
    {
      return null;
    }
    $segs[] = array('m' => $m, 's' => $start, 'e' => $o + $len, 'p' => $o + 2);
    $o += $len;
  }

  return null;
}

/** Index du premier segment APP<marqueur> dont la charge utile commence par $prefix, ou -1. */
function tagmanager_jpeg_find($d, $segs, $marker, $prefix)
{
  $l = strlen($prefix);
  foreach ($segs as $i => $s)
  {
    if ($s['m'] === $marker && substr($d, $s['p'], $l) === $prefix)
    {
      return $i;
    }
  }
  return -1;
}

function tagmanager_jpeg_segment($marker, $payload)
{
  return "\xFF" . chr($marker) . pack('n', 2 + strlen($payload)) . $payload;
}

/** Nombre de segments en tête de fichier (APP0 JFIF, APP1 Exif) après lesquels on insère le XMP. */
function tagmanager_jpeg_insert_index($d, $segs)
{
  $i = 0;
  foreach ($segs as $s)
  {
    $is_app0 = ($s['m'] === 0xE0);
    $is_exif = ($s['m'] === 0xE1 && substr($d, $s['p'], 6) === "Exif\0\0");
    if (!$is_app0 && !$is_exif)
    {
      break;
    }
    $i++;
  }
  return $i;
}

// ---------------------------------------------------------------------------------------------
// Outils de texte sans dépendance à mbstring
// ---------------------------------------------------------------------------------------------

function tagmanager_is_utf8($s)
{
  return preg_match('//u', $s) === 1;
}

/** Coupe une chaîne UTF-8 à $max octets au plus, sur une frontière de caractère. */
function tagmanager_utf8_cut($s, $max)
{
  if (strlen($s) <= $max)
  {
    return $s;
  }
  $cut = substr($s, 0, $max);
  while ($cut !== '' && !tagmanager_is_utf8($cut))
  {
    $cut = substr($cut, 0, -1);
  }
  return $cut;
}

/** UTF-8 -> ISO-8859-1 ; null si un caractère n'y est pas représentable. */
function tagmanager_to_latin1($s)
{
  $out = '';
  $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
  if ($chars === false)
  {
    return null;
  }
  foreach ($chars as $c)
  {
    $b = unpack('C*', $c);
    if (count($b) === 1)
    {
      $out .= $c;
    }
    elseif (count($b) === 2 && $b[1] >= 0xC2 && $b[1] <= 0xC3)
    {
      $out .= chr((($b[1] & 0x03) << 6) | ($b[2] & 0x3F));
    }
    else
    {
      return null;
    }
  }
  return $out;
}

/** ISO-8859-1 -> UTF-8. */
function tagmanager_from_latin1($s)
{
  $out = '';
  $n = strlen($s);
  for ($i = 0; $i < $n; $i++)
  {
    $c = ord($s[$i]);
    $out .= ($c < 0x80) ? $s[$i] : chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
  }
  return $out;
}

// ---------------------------------------------------------------------------------------------
// IPTC (APP13 / Photoshop 3.0 / ressource 8BIM 0x0404)
// ---------------------------------------------------------------------------------------------

/** @return array|null array('res' => [id, name, data], 'tail' => octets restants) */
function tagmanager_ps_parse($payload)
{
  $l = strlen(TAGMANAGER_PS_ID);
  if (substr($payload, 0, $l) !== TAGMANAGER_PS_ID)
  {
    return null;
  }
  $o = $l;
  $n = strlen($payload);
  $res = array();
  while ($o + 12 <= $n && substr($payload, $o, 4) === '8BIM')
  {
    $u = unpack('nid', substr($payload, $o + 4, 2));
    $nl = ord($payload[$o + 6]);
    $namelen = 1 + $nl;
    if ($namelen % 2)
    {
      $namelen++;
    }
    $p = $o + 6 + $namelen;
    if ($p + 4 > $n)
    {
      return null;
    }
    $sz = unpack('Nsize', substr($payload, $p, 4));
    $size = $sz['size'];
    $p += 4;
    if ($p + $size > $n)
    {
      return null;
    }
    $res[] = array('id' => $u['id'], 'name' => substr($payload, $o + 6, $namelen), 'data' => substr($payload, $p, $size));
    $o = $p + $size + ($size % 2);
  }
  return array('res' => $res, 'tail' => substr($payload, min($o, $n)));
}

function tagmanager_ps_build($ps)
{
  $out = TAGMANAGER_PS_ID;
  foreach ($ps['res'] as $r)
  {
    $out .= '8BIM' . pack('n', $r['id']) . $r['name'] . pack('N', strlen($r['data'])) . $r['data'];
    if (strlen($r['data']) % 2)
    {
      $out .= "\0";
    }
  }
  return $out . $ps['tail'];
}

/** @return array|null liste de array(enregistrement, jeu, valeur) ; null si format non géré */
function tagmanager_iptc_parse($data)
{
  $o = 0;
  $n = strlen($data);
  $out = array();
  while ($o < $n)
  {
    if ($n - $o < 5 || $data[$o] !== "\x1C")
    {
      return null;
    }
    $len = (ord($data[$o + 3]) << 8) | ord($data[$o + 4]);
    if ($len & 0x8000 || $o + 5 + $len > $n)
    {
      return null; // longueur étendue ou données tronquées : on ne touche à rien
    }
    $out[] = array(ord($data[$o + 1]), ord($data[$o + 2]), substr($data, $o + 5, $len));
    $o += 5 + $len;
  }
  return $out;
}

function tagmanager_iptc_build($ds)
{
  $out = '';
  foreach ($ds as $d)
  {
    $out .= "\x1C" . chr($d[0]) . chr($d[1]) . pack('n', strlen($d[2])) . $d[2];
  }
  return $out;
}

/**
 * Applique les opérations aux mots-clés IPTC (2:25).
 *
 * @param string|null $payload charge utile de l'APP13 existant (null s'il n'y en a pas)
 * @param array       $ops     opérations (voir xmp_writer.php)
 * @param array       $warnings (sortie)
 * @return string|null nouvelle charge utile APP13, ou null si rien ne change (ou non géré)
 */
function tagmanager_iptc_modify($payload, array $ops, &$warnings)
{
  if ($payload === null)
  {
    $ps = array('res' => array(), 'tail' => '');
  }
  else
  {
    $ps = tagmanager_ps_parse($payload);
    if ($ps === null)
    {
      $warnings[] = 'iptc_non_gere';
      return null;
    }
  }

  $idx = -1;
  foreach ($ps['res'] as $i => $r)
  {
    if ($r['id'] === 0x0404)
    {
      $idx = $i;
      break;
    }
  }

  $ds = array();
  if ($idx >= 0)
  {
    $ds = tagmanager_iptc_parse($ps['res'][$idx]['data']);
    if ($ds === null)
    {
      $warnings[] = 'iptc_non_gere';
      return null;
    }
  }

  // Jeu de caractères déclaré (1:90). Seul « ESC % G » (UTF-8) est géré.
  $flag = false;
  foreach ($ds as $d)
  {
    if ($d[0] === 1 && $d[1] === 90)
    {
      if ($d[2] !== "\x1B%G")
      {
        $warnings[] = 'iptc_charset';
        return null;
      }
      $flag = true;
    }
  }

  $eightbit = false;
  $all_utf8 = true;
  foreach ($ds as $d)
  {
    if (preg_match('/[\x80-\xFF]/', $d[2]))
    {
      $eightbit = true;
      if (!tagmanager_is_utf8($d[2]))
      {
        $all_utf8 = false;
      }
    }
  }

  $utf8_out = $flag || ($eightbit && $all_utf8);

  $current = array();
  foreach ($ds as $d)
  {
    if ($d[0] === 2 && $d[1] === 25)
    {
      $current[] = ($utf8_out || !$eightbit) ? $d[2] : tagmanager_from_latin1($d[2]);
    }
  }

  $new = tagmanager_list_apply($current, 'leaf', $ops);
  if ($new === $current)
  {
    return null;
  }

  // Encodage de sortie
  $add_flag = false;
  $non_ascii = false;
  foreach ($new as $k)
  {
    if (preg_match('/[\x80-\xFF]/', $k))
    {
      $non_ascii = true;
    }
  }
  if (!$flag && !$eightbit && $non_ascii)
  {
    $add_flag = true;
    $utf8_out = true;
  }

  $values = array();
  foreach ($new as $k)
  {
    if ($utf8_out || !preg_match('/[\x80-\xFF]/', $k))
    {
      $values[] = tagmanager_utf8_cut($k, 64);
    }
    else
    {
      $l1 = tagmanager_to_latin1($k);
      if ($l1 === null)
      {
        $warnings[] = 'iptc_encodage';
        return null;
      }
      $values[] = substr($l1, 0, 64);
    }
  }
  if (count($values) !== count(array_unique($values)))
  {
    $values = array_values(array_unique($values));
  }

  // Reconstruction : on retire les 2:25 existants et on insère les nouveaux à leur place triée
  $rest = array();
  foreach ($ds as $d)
  {
    if (!($d[0] === 2 && $d[1] === 25))
    {
      $rest[] = $d;
    }
  }
  if ($add_flag)
  {
    $pos = 0;
    foreach ($rest as $i => $d)
    {
      if ($d[0] < 1 || ($d[0] === 1 && $d[1] < 90))
      {
        $pos = $i + 1;
      }
    }
    array_splice($rest, $pos, 0, array(array(1, 90, "\x1B%G")));
  }
  $pos = count($rest);
  foreach ($rest as $i => $d)
  {
    if ($d[0] > 2 || ($d[0] === 2 && $d[1] > 25))
    {
      $pos = $i;
      break;
    }
  }
  $ins = array();
  foreach ($values as $v)
  {
    $ins[] = array(2, 25, $v);
  }
  array_splice($rest, $pos, 0, $ins);

  $data = tagmanager_iptc_build($rest);
  if ($idx >= 0)
  {
    $ps['res'][$idx]['data'] = $data;
  }
  else
  {
    $ps['res'][] = array('id' => 0x0404, 'name' => "\0\0", 'data' => $data);
  }

  // Condensé IPTC (ressource 0x0425, MD5 des données IPTC) : les outils s'en servent pour savoir si
  // l'IPTC a été modifié depuis le XMP. On le recalcule s'il existe, sinon il resterait périmé.
  foreach ($ps['res'] as $i => $r)
  {
    if ($r['id'] === 0x0425)
    {
      $ps['res'][$i]['data'] = md5($data, true);
    }
  }

  $out = tagmanager_ps_build($ps);
  if (strlen($out) > 65533)
  {
    $warnings[] = 'iptc_trop_gros';
    return null;
  }
  return $out;
}

?>
