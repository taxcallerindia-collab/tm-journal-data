<?php
/**
 * Logo-only marks: the journal prints the mark as a picture. We read the words in that picture with
 * Tesseract OCR (installed on the GitHub runner) so such marks can be found by name too.
 * Only clear text is kept; the record is labelled "logo mark" so nobody mistakes it for typed text.
 */
declare(strict_types=1);

/** Undo PNG row predictors (DecodeParms /Predictor 10..15). */
function tmo_unpredict(string $data, int $cols, int $bpp): ?string
{
    $row = $cols * $bpp; $out = ''; $prev = str_repeat("\0", $row); $len = strlen($data);
    for ($i = 0; $i + $row < $len + 1 && $i < $len; $i += $row + 1) {
        $t = ord($data[$i]); $cur = substr($data, $i + 1, $row);
        if (strlen($cur) < $row) break;
        $r = '';
        for ($x = 0; $x < $row; $x++) {
            $a = $x >= $bpp ? ord($r[$x - $bpp]) : 0; $b = ord($prev[$x]); $c = $x >= $bpp ? ord($prev[$x - $bpp]) : 0; $v = ord($cur[$x]);
            switch ($t) {
                case 1: $v += $a; break;
                case 2: $v += $b; break;
                case 3: $v += intdiv($a + $b, 2); break;
                case 4: $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c); $v += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c); break;
            }
            $r .= chr($v & 255);
        }
        $out .= $r; $prev = $r;
    }
    return $out === '' ? null : $out;
}

/** Write the mark picture to a file Tesseract can read. Returns the path or ''. */
function tmo_save_image(array &$pdf, int $n, string $base): string
{
    $o = tmp_obj($pdf, $n, true);
    if (!$o) return '';
    $d = $o['dict'];
    $w = preg_match('~/Width\s+(\d+)~', $d, $m) ? (int)$m[1] : 0;
    $h = preg_match('~/Height\s+(\d+)~', $d, $m) ? (int)$m[1] : 0;
    if ($w < 40 || $h < 20) return '';
    if (($o['filter'] ?? '') === 'DCTDecode' && !empty($o['raw'])) { file_put_contents($base . '.jpg', $o['raw']); return $base . '.jpg'; }
    if (!isset($o['stream']) || $o['stream'] === null) return '';
    $bits = preg_match('~/BitsPerComponent\s+(\d+)~', $d, $m) ? (int)$m[1] : 8;
    if ($bits !== 8) return '';
    $ch = preg_match('~/ColorSpace\s*/DeviceGray~', $d) ? 1 : (preg_match('~/ColorSpace\s*/DeviceRGB~', $d) ? 3 : (preg_match('~/ColorSpace\s*/DeviceCMYK~', $d) ? 4 : 0));
    if (!$ch) return '';
    $px = $o['stream'];
    if (preg_match('~/Predictor\s+(\d+)~', $d, $m) && (int)$m[1] >= 10) { $px = tmo_unpredict($px, $w, $ch); if ($px === null) return ''; }
    if (strlen($px) < $w * $h * $ch) return '';
    if ($ch === 4) { // CMYK -> RGB
        $rgb = '';
        for ($i = 0, $l = $w * $h * 4; $i < $l; $i += 4) { $k = ord($px[$i + 3]); $rgb .= chr(255 - min(255, ord($px[$i]) + $k)) . chr(255 - min(255, ord($px[$i + 1]) + $k)) . chr(255 - min(255, ord($px[$i + 2]) + $k)); }
        $px = $rgb; $ch = 3;
    }
    file_put_contents($base . '.pnm', ($ch === 1 ? "P5\n" : "P6\n") . "$w $h\n255\n" . substr($px, 0, $w * $h * $ch));
    return $base . '.pnm';
}

/** Clean OCR output into a mark name, or '' when it is not clear text. */
function tmo_clean(string $t): string
{
    $words = [];
    foreach (preg_split('~\R~u', $t) as $line) {
        $line = trim((string)preg_replace('~[^\p{L}\p{N}&\'.\- ]+~u', ' ', $line));
        $line = trim((string)preg_replace('~\s+~u', ' ', $line));
        if ($line === '') continue;
        $alnum = (int)preg_match_all('~[\p{L}\p{N}]~u', $line);
        if ($alnum < 2) continue;
        if (!preg_match('~\p{L}{2,}~u', $line)) continue;
        // single letters scattered = noise
        $parts = explode(' ', $line); $short = count(array_filter($parts, fn($p) => mb_strlen($p) === 1));
        if ($short > count($parts) / 2) continue;
        $words[] = $line;
        if (count($words) >= 3) break;
    }
    $name = trim(implode(' ', $words));
    if ((int)preg_match_all('~[\p{L}\p{N}]~u', $name) < 3 || mb_strlen($name) > 80) return '';
    return mb_strtoupper($name);
}

/** Read the mark picture printed below the "Advertised Trade Mark" label (call right after tmp_page_cells). */
function tmo_read_logo(array &$pdf, int $page, ?float $belowY): string
{
    static $ok = null;
    if ($ok === null) $ok = trim((string)shell_exec('command -v tesseract')) !== '';
    if (!$ok || empty($pdf['imgs'])) return '';
    $map = tmo_page_names($pdf, $page);
    $best = 0; $area = 0;
    foreach ($pdf['imgs'] as $im) {
        $n = $map[$im['name']] ?? 0;
        if (!$n) continue;
        if ($belowY !== null && $im['top'] > $belowY + 2) continue; // header emblem and anything above the label
        if ($im['w'] * $im['h'] > $area) { $area = $im['w'] * $im['h']; $best = $n; }
    }
    if (!$best) return '';
    $base = sys_get_temp_dir() . '/tmo-' . $best;
    $file = tmo_save_image($pdf, $best, $base);
    if ($file === '') return '';
    $txt = (string)shell_exec('timeout 20 tesseract ' . escapeshellarg($file) . ' stdout -l eng --psm 6 2>/dev/null');
    @unlink($file);
    return tmo_clean($txt);
}

/** XObject name => object number for a page. */
function tmo_page_names(array &$pdf, int $page): array
{
    $po = tmp_obj($pdf, $page, false);
    if (!$po) return [];
    $res = $po['dict'];
    if (preg_match('~/Resources\s+(\d+)\s+0\s+R~', $res, $m)) $res = (string)(tmp_obj($pdf, (int)$m[1], false)['dict'] ?? '');
    $xo = '';
    if (preg_match('~/XObject\s+(\d+)\s+0\s+R~', $res, $m)) $xo = (string)(tmp_obj($pdf, (int)$m[1], false)['dict'] ?? '');
    elseif (preg_match('~/XObject\s*<<(.*?)>>~s', $res, $m)) $xo = $m[1];
    $out = [];
    if (preg_match_all('~/([^\s/<>\[\]()]+)\s+(\d+)\s+0\s+R~', $xo, $mm, PREG_SET_ORDER)) foreach ($mm as $x) $out[$x[1]] = (int)$x[2];
    return $out;
}
