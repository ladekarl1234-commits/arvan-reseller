<?php
/**
 * Compile a gettext .po into the binary .mo WordPress actually loads.
 *
 * WHY hand-rolled: `msgfmt` is a gettext-tools binary that is not installed on
 * a stock CI runner and is not a Composer package, so the only way the shipped
 * catalog can be reproducible from this repository alone is to write the format
 * directly. It is small — a 28-byte header, two (length, offset) tables and one
 * string arena.
 *
 * Usage:
 *   php bin/make-mo.php <catalog.po> [output.mo]     compile, then verify
 *   php bin/make-mo.php --verify <catalog.mo> [catalog.po]
 *
 * Exit code 0 on success, 1 on a usage/IO/verification error.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "make-mo.php is a CLI tool.\n");
    exit(1);
}

require_once __DIR__ . '/gettext.php';

const ARVRS_MO_MAGIC = 0x950412de;

/**
 * The (key => translation-block) map a .mo is built from.
 *
 * gettext looks an entry up by its original block: the msgctxt and the
 * singular joined by EOT (\x04), and for a plural entry the singular and the
 * plural joined by NUL. The translation block is the msgstr list joined by NUL
 * in plural-form order. Fuzzy and empty entries are dropped — a fuzzy string
 * compiled into the catalog is shown to users as if it were reviewed.
 *
 * @param array $po result of arvrs_po_parse()
 * @return array{pairs:array<string,string>,fuzzy:int,blank:int}
 */
function arvrs_mo_pairs(array $po)
{
    $pairs = array('' => $po['header']);
    $fuzzy = 0;
    $blank = 0;

    foreach ($po['entries'] as $entry) {
        $msgstr = $entry['msgstr'];
        ksort($msgstr, SORT_NUMERIC);
        $filled = array_filter($msgstr, function ($s) {
            return $s !== '';
        });
        if ($filled === array()) {
            $blank++;
            continue;
        }
        if (!empty($entry['fuzzy'])) {
            $fuzzy++;
            continue;
        }

        $original = arvrs_po_key($entry['context'], $entry['msgid']);
        if ($entry['plural'] !== null) {
            $original .= "\x00" . $entry['plural'];
        }
        $pairs[$original] = implode("\x00", array_values($msgstr));
    }

    return array('pairs' => $pairs, 'fuzzy' => $fuzzy, 'blank' => $blank);
}

/**
 * Serialise the pairs into .mo bytes.
 *
 * The hash table is declared as size 0, and readers fall back to a linear scan
 * of the (sorted) original table — which is why the sort below is not cosmetic.
 *
 * Its ADDRESS, however, must still point just past the translation index, not
 * at 0. WordPress' own reader (wp-includes/pomo/mo.php) derives the length of
 * the translation table as `hash_addr - translations_addr` and refuses the file
 * when that does not equal count*8. With hash_addr = 0 the subtraction goes
 * negative and `import_from_file()` returns false with zero entries — the
 * catalog ships, loads nothing, and translates nothing. Real gettext writes the
 * end-of-table offset here for exactly this reason.
 *
 * @param array<string,string> $pairs
 * @return string
 */
function arvrs_mo_pack(array $pairs)
{
    ksort($pairs, SORT_STRING);

    $count       = count($pairs);
    $originals   = '';
    $translations = '';
    // Header, then two tables of two uint32 each, then the string arena.
    $arena_start = 28 + ($count * 8 * 2);

    $offset = $arena_start;
    foreach ($pairs as $original => $translation) {
        $originals .= pack('VV', strlen($original), $offset);
        $offset    += strlen($original) + 1; // the NUL terminator is not counted
    }
    foreach ($pairs as $original => $translation) {
        $translations .= pack('VV', strlen($translation), $offset);
        $offset       += strlen($translation) + 1;
    }

    $arena = '';
    foreach ($pairs as $original => $translation) {
        $arena .= $original . "\x00";
    }
    foreach ($pairs as $original => $translation) {
        $arena .= $translation . "\x00";
    }

    return pack(
        'VVVVVVV',
        ARVRS_MO_MAGIC,
        0,                       // format revision
        $count,
        28,                      // originals table offset
        28 + ($count * 8),       // translations table offset
        0,                       // hash table size (no hash table)
        $arena_start             // hash table offset = end of the translation
                                 // index; readers use it to size that table
    ) . $originals . $translations . $arena;
}

/**
 * Read a .mo back with an independent parser, so "it wrote bytes" and "a
 * reader can use them" are two different claims and both get checked.
 *
 * @param string $path
 * @return array<string,string>|null null when the file is not a readable .mo
 */
function arvrs_mo_read($path)
{
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || strlen($bytes) < 28) {
        return null;
    }

    $magic = unpack('V', substr($bytes, 0, 4));
    // Byte order is declared by the magic number; we only ever write LE, but a
    // BE catalog from msgfmt must still verify rather than look like garbage.
    $endian = 'V';
    if ($magic[1] !== ARVRS_MO_MAGIC) {
        $magic = unpack('N', substr($bytes, 0, 4));
        if ($magic[1] !== ARVRS_MO_MAGIC) {
            return null;
        }
        $endian = 'N';
    }

    $head = unpack(
        $endian . 'revision/' . $endian . 'count/' . $endian . 'orig/' . $endian . 'trans/'
        . $endian . 'hash_len/' . $endian . 'hash_addr',
        substr($bytes, 4, 24)
    );

    // Reproduce the exact structural checks WordPress' own reader applies
    // (wp-includes/pomo/mo.php::import_from_reader). Verifying with a more
    // forgiving reader than the consumer is how a catalog ships that round-
    // trips perfectly here and loads zero strings in WordPress — which is
    // precisely what happened with hash_addr = 0.
    if ($head['revision'] !== 0) {
        return null;
    }
    if (($head['trans'] - $head['orig']) !== $head['count'] * 8) {
        return null;
    }
    if (($head['hash_addr'] - $head['trans']) !== $head['count'] * 8) {
        return null;
    }

    $out = array();

    for ($i = 0; $i < $head['count']; $i++) {
        $o = unpack($endian . 'len/' . $endian . 'off', substr($bytes, $head['orig'] + ($i * 8), 8));
        $t = unpack($endian . 'len/' . $endian . 'off', substr($bytes, $head['trans'] + ($i * 8), 8));
        if ($o['off'] + $o['len'] > strlen($bytes) || $t['off'] + $t['len'] > strlen($bytes)) {
            return null;
        }
        $out[substr($bytes, $o['off'], $o['len'])] = substr($bytes, $t['off'], $t['len']);
    }

    return $out;
}

/**
 * Compare a compiled .mo against the .po it came from.
 *
 * @param string $mo_path
 * @param string $po_path
 * @return int process exit code
 */
function arvrs_mo_verify($mo_path, $po_path)
{
    $read = arvrs_mo_read($mo_path);
    if ($read === null) {
        fwrite(STDERR, $mo_path . ": not a readable .mo\n");
        return 1;
    }
    if (!is_file($po_path)) {
        fwrite(STDERR, $po_path . ": missing, cannot verify against source\n");
        return 1;
    }

    $expected = arvrs_mo_pairs(arvrs_po_parse($po_path));
    $want     = $expected['pairs'];
    ksort($want, SORT_STRING);

    if (!isset($read[''])) {
        fwrite(STDERR, $mo_path . ": header entry (empty msgid) is missing\n");
        return 1;
    }
    if (strpos($read[''], 'Plural-Forms:') === false) {
        fwrite(STDERR, $mo_path . ": header carries no Plural-Forms\n");
        return 1;
    }

    $bad = 0;
    foreach ($want as $original => $translation) {
        if (!array_key_exists($original, $read)) {
            $bad++;
            if ($bad <= 5) {
                fwrite(STDERR, "missing: " . str_replace("\x00", '\\0', $original) . "\n");
            }
            continue;
        }
        if ($read[$original] !== $translation) {
            $bad++;
            if ($bad <= 5) {
                fwrite(STDERR, "differs: " . str_replace("\x00", '\\0', $original) . "\n");
            }
        }
    }
    if (count($read) !== count($want)) {
        fwrite(STDERR, sprintf("count mismatch: %d in .mo, %d in .po\n", count($read), count($want)));
        $bad++;
    }
    // Readers that skip the hash table rely on the original table being sorted.
    $keys   = array_keys($read);
    $sorted = $keys;
    sort($sorted, SORT_STRING);
    if ($keys !== $sorted) {
        fwrite(STDERR, $mo_path . ": original table is not sorted\n");
        $bad++;
    }

    if ($bad > 0) {
        fwrite(STDERR, sprintf("%s: FAILED (%d problems)\n", $mo_path, $bad));
        return 1;
    }
    printf("%s: verified, %d entries round-trip against %s\n", $mo_path, count($read) - 1, $po_path);
    return 0;
}

$args   = array_slice($argv, 1);
$verify = false;
$paths  = array();
foreach ($args as $arg) {
    if ($arg === '--verify') {
        $verify = true;
    } elseif (strpos($arg, '--') === 0) {
        fwrite(STDERR, "Usage: php bin/make-mo.php [--verify] <catalog.po|catalog.mo> [output]\n");
        exit(1);
    } else {
        $paths[] = str_replace('\\', '/', $arg);
    }
}

if ($paths === array()) {
    $paths[] = str_replace('\\', '/', dirname(__DIR__)) . '/languages/arvan-reseller-fa_IR.po';
}

if ($verify) {
    $mo = $paths[0];
    $po = isset($paths[1]) ? $paths[1] : preg_replace('/\.mo$/', '.po', $mo);
    exit(arvrs_mo_verify($mo, $po));
}

$po_path = $paths[0];
$mo_path = isset($paths[1]) ? $paths[1] : preg_replace('/\.po$/', '.mo', $po_path);
if (!is_file($po_path)) {
    fwrite(STDERR, $po_path . ": no such file\n");
    exit(1);
}
if ($mo_path === $po_path) {
    fwrite(STDERR, "Refusing to overwrite the source catalog; pass an explicit .mo path.\n");
    exit(1);
}

$parsed = arvrs_po_parse($po_path);
$built  = arvrs_mo_pairs($parsed);
if (file_put_contents($mo_path, arvrs_mo_pack($built['pairs'])) === false) {
    fwrite(STDERR, 'Cannot write ' . $mo_path . "\n");
    exit(1);
}

printf(
    "%s: %d translated entries (%d untranslated, %d fuzzy skipped), %d bytes\n",
    $mo_path,
    count($built['pairs']) - 1,
    $built['blank'],
    $built['fuzzy'],
    (int) filesize($mo_path)
);

// A catalog is only shipped once it has been read back, so a packing mistake
// fails the build instead of shipping a silently empty translation.
exit(arvrs_mo_verify($mo_path, $po_path));
