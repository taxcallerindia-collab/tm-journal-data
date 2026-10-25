<?php
/** Small PDF text reader for Trade Marks Journal files (object-by-offset, low memory). */
declare(strict_types=1);


/** Open a PDF for reading. Returns a reader array or null. */
function tmp_open(string $file): ?array
{
    $fh = @fopen($file, 'rb');
    if (!$fh) return null;
    $size = filesize($file);
    if ($size < 100 || fread($fh, 5) !== '%PDF-') { fclose($fh); return null; }
    $pdf = ['fh' => $fh, 'size' => $size, 'off' => [], 'root' => 0, 'fonts' => [], 'objs' => []];
    // Classic xref tables (with /Prev chain).
    fseek($fh, max(0, $size - 2048));
    $tail = (string)fread($fh, 2048);
    $pos = preg_match_all('~startxref\s+(\d+)~', $tail, $m) ? (int)end($m[1]) : 0;
    $seen = [];
    while ($pos > 0 && $pos < $size && !isset($seen[$pos])) {
        $seen[$pos] = 1;
        fseek($fh, $pos);
        $head = (string)fread($fh, 64);
        if (!str_starts_with(ltrim($head), 'xref')) break;
        fseek($fh, $pos + strpos($head, 'xref') + 4);
        $trailer = '';
        while (!feof($fh)) {
            $line = trim((string)fgets($fh, 256));
            if ($line === '') continue;
            if (str_starts_with($line, 'trailer')) { $trailer = $line . (string)fread($fh, 1024); break; }
            if (!preg_match('~^(\d+)\s+(\d+)$~', $line, $sm)) break;
            [$start, $cnt] = [(int)$sm[1], (int)$sm[2]];
            $blk = (string)fread($fh, 20 * $cnt);
            // Entries are 20 bytes, but some writers use 19 + CRLF variations: parse by regex.
            preg_match_all('~(\d{10})\s(\d{5})\s([nf])~', $blk, $em, PREG_SET_ORDER);
            foreach ($em as $k => $e) if ($e[3] === 'n' && !isset($pdf['off'][$start + $k])) $pdf['off'][$start + $k] = (int)$e[1];
        }
        if (!$pdf['root'] && preg_match('~/Root\s+(\d+)\s+0\s+R~', $trailer, $rm)) $pdf['root'] = (int)$rm[1];
        $pos = preg_match('~/Prev\s+(\d+)~', $trailer, $pm) ? (int)$pm[1] : 0;
    }
    // Fallback (xref streams, broken files, our test samples): scan the file for "N 0 obj".
    if (!$pdf['root'] || count($pdf['off']) < 3) {
        $pdf['off'] = [];
        fseek($fh, 0);
        $base = 0; $carry = '';
        while (!feof($fh)) {
            $chunk = $carry . (string)fread($fh, 1 << 20);
            if (preg_match_all('~(?:^|[\r\n])(\d+)\s+0\s+obj~', $chunk, $om, PREG_OFFSET_CAPTURE)) {
                foreach ($om[1] as $o) $pdf['off'][(int)$o[0]] = $base + $o[1];
            }
            if (preg_match_all('~/Root\s+(\d+)\s+0\s+R~', $chunk, $rm)) $pdf['root'] = (int)end($rm[1]);
            $keep = min(64, strlen($chunk));
            $base += strlen($chunk) - $keep;
            $carry = substr($chunk, -$keep);
        }
    }
    if (!$pdf['root']) { fclose($fh); return null; }
    return $pdf;
}

function tmp_close(array &$pdf): void { if (is_resource($pdf['fh'])) fclose($pdf['fh']); }

/** Object N: ['dict' => string, 'stream' => ?string (decoded)]. */
function tmp_obj(array &$pdf, int $n, bool $withStream = true): ?array
{
    if (isset($pdf['objs'][$n]) && (!$withStream || array_key_exists('stream', $pdf['objs'][$n]))) return $pdf['objs'][$n];
    $o = $pdf['off'][$n] ?? null;
    if ($o === null) return null;
    fseek($pdf['fh'], $o);
    $buf = '';
    while (!feof($pdf['fh']) && strlen($buf) < 4 << 20) {
        $buf .= (string)fread($pdf['fh'], 8192);
        $s = strpos($buf, 'stream'); $e = strpos($buf, 'endobj');
        if ($e !== false && ($s === false || $e < $s)) { $s = false; break; }
        if ($s !== false && strlen($buf) > $s + 8) break;
    }
    $start = strpos($buf, 'obj');
    if ($start === false) return null;
    $isStream = $s !== false && preg_match('~stream\r?\n~', $buf, $mm, 0, $s) && strpos($buf, $mm[0], $s) === $s;
    $dict = trim(substr($buf, $start + 3, ($isStream ? $s : (int)strpos($buf, 'endobj')) - $start - 3));
    $r = ['dict' => $dict];
    if ($isStream && $withStream) {
        $len = 0;
        if (preg_match('~/Length\s+(\d+)\s+0\s+R~', $dict, $lm)) { $lo = tmp_obj($pdf, (int)$lm[1], false); $len = (int)($lo['dict'] ?? 0); }
        elseif (preg_match('~/Length\s+(\d+)~', $dict, $lm)) $len = (int)$lm[1];
        $dataAt = $o + $s + strlen($mm[0]);
        if ($len <= 0 || $len > 64 << 20) return $r + ['stream' => null];
        fseek($pdf['fh'], $dataAt);
        $raw = (string)fread($pdf['fh'], $len);
        if (preg_match('~/Filter\s*(?:\[\s*)?/(\w+)~', $dict, $fm)) {
            if ($fm[1] !== 'FlateDecode') return $r + ['stream' => null];
            $orig = $raw;
            $raw = @gzuncompress($orig);
            if ($raw === false) $raw = @gzinflate(substr($orig, 2));
            if ($raw === false) $raw = null;
        }
        $r['stream'] = $raw;
    }
    if (strlen($dict) < 600 && !$isStream) $pdf['objs'][$n] = $r; // cache small dictionaries only
    return $r;
}

/** All page object numbers, in order. */
function tmp_pages(array &$pdf): array
{
    $cat = tmp_obj($pdf, $pdf['root'], false);
    if (!$cat || !preg_match('~/Pages\s+(\d+)\s+0\s+R~', $cat['dict'], $m)) return [];
    $out = []; $stack = [(int)$m[1]]; $guard = 0;
    while ($stack && $guard++ < 200000) {
        $n = array_shift($stack);
        $o = tmp_obj($pdf, $n, false);
        if (!$o) continue;
        if (preg_match('~/Type\s*/Pages\b~', $o['dict']) && preg_match('~/Kids\s*\[([^\]]*)\]~', $o['dict'], $km)) {
            preg_match_all('~(\d+)\s+0\s+R~', $km[1], $kk);
            $stack = array_merge(array_map('intval', $kk[1]), $stack);
        } else {
            $out[] = $n;
        }
    }
    return $out;
}

/** Parse a ToUnicode CMap into [code(int) => utf8]. */
function tmp_cmap(string $s): array
{
    $map = [];
    $u = fn(string $hex) => (string)mb_convert_encoding((string)hex2bin(strlen($hex) % 4 ? str_pad($hex, (int)ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT) : $hex), 'UTF-8', 'UTF-16BE');
    if (preg_match_all('~beginbfchar(.*?)endbfchar~s', $s, $bc)) foreach ($bc[1] as $blk) {
        preg_match_all('~<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>~', $blk, $pp, PREG_SET_ORDER);
        foreach ($pp as $p) $map[hexdec($p[1])] = $u($p[2]);
    }
    if (preg_match_all('~beginbfrange(.*?)endbfrange~s', $s, $br)) foreach ($br[1] as $blk) {
        preg_match_all('~<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])~', $blk, $pp, PREG_SET_ORDER);
        foreach ($pp as $p) {
            $a = hexdec($p[1]); $b = min(hexdec($p[2]), $a + 65535);
            if ($p[3][0] === '[') {
                preg_match_all('~<([0-9A-Fa-f]*)>~', $p[3], $dl);
                foreach ($dl[1] as $i => $d) if ($a + $i <= $b) $map[$a + $i] = $u($d);
            } else {
                $d = substr($p[3], 1, -1); $base = hexdec($d);
                for ($c = $a; $c <= $b; $c++) $map[$c] = $u(str_pad(dechex($base + $c - $a), max(4, strlen($d)), '0', STR_PAD_LEFT));
            }
        }
    }
    return $map;
}

/** Parse a CID /W array into [cid => width]. */
function tmp_widths_cid(string $w): array
{
    $out = [];
    if (!preg_match_all('~\[|\]|-?\d+(?:\.\d+)?~', $w, $t)) return $out;
    $t = $t[0]; $i = 0; $n = count($t);
    if (($t[0] ?? '') === '[') $i = 1;
    while ($i < $n) {
        if ($t[$i] === ']') { $i++; continue; }
        $c = (int)$t[$i];
        if (($t[$i + 1] ?? '') === '[') {
            $i += 2;
            while ($i < $n && $t[$i] !== ']') $out[$c++] = (float)$t[$i++];
            $i++;
        } elseif (isset($t[$i + 2]) && $t[$i + 1] !== '[' && $t[$i + 1] !== ']') {
            for ($k = $c; $k <= (int)$t[$i + 1] && $k - $c < 65536; $k++) $out[$k] = (float)$t[$i + 2];
            $i += 3;
        } else break;
    }
    return $out;
}

/** Font info for object N: [two_byte, map, widths, default_width]. Cached per file. */
function tmp_font(array &$pdf, int $n): array
{
    if (isset($pdf['fonts'][$n])) return $pdf['fonts'][$n];
    $f = ['two' => false, 'map' => [], 'w' => [], 'dw' => 500.0, 'first' => 0];
    $o = tmp_obj($pdf, $n, false);
    $d = (string)($o['dict'] ?? '');
    $f['two'] = (bool)preg_match('~/Subtype\s*/Type0\b~', $d);
    if (preg_match('~/ToUnicode\s+(\d+)\s+0\s+R~', $d, $m)) { $cm = tmp_obj($pdf, (int)$m[1]); if (!empty($cm['stream'])) $f['map'] = tmp_cmap($cm['stream']); }
    if ($f['two']) {
        if (preg_match('~/DescendantFonts\s*\[\s*(\d+)\s+0\s+R~', $d, $m) || preg_match('~/DescendantFonts\s+(\d+)\s+0\s+R~', $d, $m)) {
            $dd = (string)(tmp_obj($pdf, (int)$m[1], false)['dict'] ?? '');
            if (preg_match('~^\s*\[\s*(\d+)\s+0\s+R~', $dd, $m2)) $dd = (string)(tmp_obj($pdf, (int)$m2[1], false)['dict'] ?? ''); // array object
            if (preg_match('~/DW\s+(\d+)~', $dd, $m2)) $f['dw'] = (float)$m2[1]; else $f['dw'] = 1000.0;
            if (preg_match('~/W\s+(\d+)\s+0\s+R~', $dd, $m2)) $f['w'] = tmp_widths_cid((string)(tmp_obj($pdf, (int)$m2[1], false)['dict'] ?? ''));
            elseif (preg_match('~/W\s*(\[(?:[^\[\]]|\[[^\]]*\])*\])~', $dd, $m2)) $f['w'] = tmp_widths_cid($m2[1]);
        }
    } else {
        $f['first'] = preg_match('~/FirstChar\s+(\d+)~', $d, $m) ? (int)$m[1] : 0;
        $wd = '';
        if (preg_match('~/Widths\s+(\d+)\s+0\s+R~', $d, $m)) $wd = (string)(tmp_obj($pdf, (int)$m[1], false)['dict'] ?? '');
        elseif (preg_match('~/Widths\s*\[([^\]]*)\]~', $d, $m)) $wd = $m[1];
        if ($wd !== '' && preg_match_all('~-?\d+(?:\.\d+)?~', $wd, $wm)) foreach ($wm[0] as $i => $v) $f['w'][$f['first'] + $i] = (float)$v;
    }
    return $pdf['fonts'][$n] = $f;
}

/** Decode a PDF string token (literal or hex) into raw bytes. */
function tmp_str(string $tok): string
{
    if ($tok[0] === '<') return (string)hex2bin(strlen($h = (string)preg_replace('~[^0-9A-Fa-f]~', '', substr($tok, 1, -1))) % 2 ? $h . '0' : $h);
    $s = substr($tok, 1, -1);
    return (string)preg_replace_callback('~\\\\([0-7]{1,3}|\r\n|.)~s', function ($m) {
        $c = $m[1];
        if (ctype_digit($c)) return chr(octdec($c) & 255);
        return ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", "\n" => '', "\r" => '', "\r\n" => ''][$c] ?? $c;
    }, $s);
}

/** Bytes -> [utf8 text, width in text space units (1000 = font size)]. */
function tmp_decode(array $f, string $bytes): array
{
    $out = ''; $w = 0.0;
    $codes = [];
    if ($f['two']) { for ($i = 0, $l = strlen($bytes) - 1; $i < $l; $i += 2) $codes[] = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]); }
    else { for ($i = 0, $l = strlen($bytes); $i < $l; $i++) $codes[] = ord($bytes[$i]); }
    foreach ($codes as $c) {
        if (isset($f['map'][$c])) $out .= $f['map'][$c];
        elseif (!$f['two']) $out .= $c < 128 ? chr($c) : (string)mb_convert_encoding(chr($c), 'UTF-8', 'Windows-1252');
        $w += $f['w'][$c] ?? $f['dw'];
    }
    return [$out, $w];
}

/** Text cells of one page: [y, x0, x1, text, size] in page space (y grows upwards). */
function tmp_page_cells(array &$pdf, int $page): array
{
    $po = tmp_obj($pdf, $page, false);
    if (!$po) return [];
    $d = $po['dict'];
    // Fonts of this page.
    $fontRefs = [];
    $res = $d;
    if (preg_match('~/Resources\s+(\d+)\s+0\s+R~', $d, $m)) $res = (string)(tmp_obj($pdf, (int)$m[1], false)['dict'] ?? '');
    if (preg_match('~/Font\s+(\d+)\s+0\s+R~', $res, $m)) $res = (string)(tmp_obj($pdf, (int)$m[1], false)['dict'] ?? '');
    elseif (preg_match('~/Font\s*<<(.*?)>>~s', $res, $m)) $res = $m[1];
    if (preg_match_all('~/([^\s/<>\[\]()]+)\s+(\d+)\s+0\s+R~', $res, $fm, PREG_SET_ORDER)) foreach ($fm as $x) $fontRefs[$x[1]] = (int)$x[2];
    // Content streams.
    $content = '';
    if (preg_match('~/Contents\s*(\[[^\]]*\]|\d+\s+0\s+R)~', $d, $m)) {
        preg_match_all('~(\d+)\s+0\s+R~', $m[1], $cm);
        foreach ($cm[1] as $c) {
            $co = tmp_obj($pdf, (int)$c);
            // /Contents may point to an array object: [12 0 R 13 0 R …] (PDFium-written journals)
            if ($co && !array_key_exists('stream', $co) || ($co && $co['stream'] === null && str_starts_with(ltrim($co['dict']), '['))) {
                if (preg_match_all('~(\d+)\s+0\s+R~', (string)$co['dict'], $sub)) foreach ($sub[1] as $sc) { $so = tmp_obj($pdf, (int)$sc); $content .= ($so['stream'] ?? '') . "\n"; }
                continue;
            }
            $content .= ($co['stream'] ?? '') . "\n";
        }
    }
    if ($content === '') return [];
    $re = '~(?<s>\((?:[^()\\\\]++|\\\\.|(?&s))*\))|<<|>>|<[0-9A-Fa-f\s]*>|\[|\]|/[^\s/\[\]()<>{}%]*|[-+]?(?:\d+\.?\d*|\.\d+)|[A-Za-z\'"*][A-Za-z0-9*]*|%[^\r\n]*~s';
    if (!preg_match_all($re, $content, $tm)) return [];
    $mul = fn(array $A, array $B) => [$A[0] * $B[0] + $A[1] * $B[2], $A[0] * $B[1] + $A[1] * $B[3], $A[2] * $B[0] + $A[3] * $B[2], $A[2] * $B[1] + $A[3] * $B[3],
        $A[4] * $B[0] + $A[5] * $B[2] + $B[4], $A[4] * $B[1] + $A[5] * $B[3] + $B[5]];
    $I = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
    $ctm = $I; $gs = []; $Tm = $I; $Tlm = $I;
    $items = []; $stack = []; $nofont = ['two' => false, 'map' => [], 'w' => [], 'dw' => 500.0, 'first' => 0]; $font = $nofont; $size = 10.0;
    $lead = 0.0; $arr = null; $skipUntilEI = false; $seen = [];
    $emit = function (string $bytes) use (&$items, &$font, &$size, &$Tm, &$ctm, &$seen, $mul) {
        [$t, $w] = tmp_decode($font, $bytes);
        $adv = $w / 1000 * $size;
        $M = $mul($Tm, $ctm);
        if ($t !== '') {
            $sx = hypot($M[0], $M[1]); $sy = hypot($M[2], $M[3]);
            $k = $t . '|' . round($M[4] / 1.5) . '|' . round($M[5] / 1.5);
            if (!isset($seen[$k])) { $seen[$k] = 1; $items[] = [$M[5], $M[4], $M[4] + $adv * $sx, $t, max(1.0, $size * $sy)]; } // fake-bold double prints are dropped
        }
        $Tm = $mul([1.0, 0.0, 0.0, 1.0, $adv, 0.0], $Tm);
    };
    foreach ($tm[0] as $tok) {
        if ($skipUntilEI) { if ($tok === 'EI') $skipUntilEI = false; continue; }
        $c = $tok[0];
        if ($c === '%') continue;
        if ($tok === '[') { $arr = []; continue; }
        if ($tok === ']') { $stack[] = $arr ?? []; $arr = null; continue; }
        if ($arr !== null) { $arr[] = $tok; continue; }
        if ($c === '(' || ($c === '<' && $tok !== '<<') || $c === '/' || is_numeric($tok)) { $stack[] = $tok; continue; }
        $n = count($stack);
        $num = fn(int $i) => (float)(is_string($stack[$n - $i] ?? null) ? $stack[$n - $i] : 0);
        switch ($tok) {
            case 'q': $gs[] = $ctm; break;
            case 'Q': if ($gs) $ctm = array_pop($gs); break;
            case 'cm': if ($n >= 6) $ctm = $mul([$num(6), $num(5), $num(4), $num(3), $num(2), $num(1)], $ctm); break;
            case 'BT': $Tm = $Tlm = $I; break;
            case 'Tf': $size = abs($num(1)) ?: 10.0; $nm = ltrim((string)($stack[$n - 2] ?? ''), '/');
                $font = isset($fontRefs[$nm]) ? tmp_font($pdf, $fontRefs[$nm]) : $nofont; break;
            case 'Tm': if ($n >= 6) $Tm = $Tlm = [$num(6), $num(5), $num(4), $num(3), $num(2), $num(1)]; break;
            case 'Td': case 'TD': if ($n >= 2) { $Tm = $Tlm = $mul([1.0, 0.0, 0.0, 1.0, $num(2), $num(1)], $Tlm); if ($tok === 'TD') $lead = -$num(1); } break;
            case 'TL': $lead = $num(1); break;
            case 'T*': $Tm = $Tlm = $mul([1.0, 0.0, 0.0, 1.0, 0.0, -$lead], $Tlm); break;
            case 'Tj': $s = end($stack); if (is_string($s) && $s !== '' && ($s[0] === '(' || $s[0] === '<')) $emit(tmp_str($s)); break;
            case "'": case '"': $Tm = $Tlm = $mul([1.0, 0.0, 0.0, 1.0, 0.0, -$lead], $Tlm); $s = end($stack); if (is_string($s) && $s !== '' && ($s[0] === '(' || $s[0] === '<')) $emit(tmp_str($s)); break;
            case 'TJ': $a = end($stack);
                if (is_array($a)) foreach ($a as $el) {
                    if ($el[0] === '(' || $el[0] === '<') $emit(tmp_str($el));
                    elseif (is_numeric($el)) {
                        $sh = -(float)$el / 1000 * $size;
                        if ($sh > $size * 0.25) { $M = $mul($Tm, $ctm); $items[] = [$M[5], $M[4], $M[4] + $sh * hypot($M[0], $M[1]), ' ', $size * hypot($M[2], $M[3])]; }
                        $Tm = $mul([1.0, 0.0, 0.0, 1.0, $sh, 0.0], $Tm);
                    }
                }
                break;
            case 'BI': $skipUntilEI = true; break;
        }
        $stack = [];
    }
    if (!$items) return [];
    // Cells: pieces on the same baseline that touch each other. Reading order: top to bottom, left to right.
    usort($items, fn($a, $b) => [-round($a[0]), $a[1]] <=> [-round($b[0]), $b[1]]);
    $cells = [];
    foreach ($items as $it) {
        $k = count($cells) - 1;
        $last = $k >= 0 ? $cells[$k] : null;
        if ($last && abs($it[0] - $last['y']) <= max(1.5, $it[4] * 0.3) && $it[1] - $last['x1'] <= $it[4] * 0.9 && $it[1] >= $last['x0'] - 1) {
            $gap = $it[1] - $last['x1'];
            $cells[$k]['t'] .= ($gap > $it[4] * 0.2 && !str_ends_with($last['t'], ' ') && $it[3][0] !== ' ') ? ' ' . $it[3] : $it[3];
            $cells[$k]['x1'] = max($last['x1'], $it[2]);
        } else {
            if (trim($it[3]) === '') continue;
            $cells[] = ['y' => $it[0], 'x0' => $it[1], 'x1' => $it[2], 't' => $it[3], 's' => $it[4]];
        }
    }
    foreach ($cells as &$c) $c['t'] = trim((string)preg_replace('~\s{2,}~u', ' ', $c['t']));
    unset($c);
    return array_values(array_filter($cells, fn($c) => $c['t'] !== ''));
}

/** Page cells joined as text lines (cells on one baseline separated by TAB). */
function tmp_cells_text(array $cells): string
{
    $out = ''; $y = null;
    foreach ($cells as $c) {
        if ($y !== null && abs($c['y'] - $y) <= max(1.5, $c['s'] * 0.3)) $out .= "\t" . $c['t'];
        else $out .= ($out === '' ? '' : "\n") . $c['t'];
        $y = $c['y'];
    }
    return $out;
}
