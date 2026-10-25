<?php
/**
 * Trade Marks Journal page -> mark record. Layout of IP India journals (2020 onwards):
 * header with Application Number / Journal Number / dates, then a two-column table
 * (Hindi + English label on the left, value on the right), then "Advertised Trade Mark" with the mark below.
 */
declare(strict_types=1);

const TMJ_LABELS = [
    'address' => 'Applicant\s*Address', 'applicant' => 'Proprietor/Applicant', 'type' => 'Type\s*of\s*Applicant', 'class' => '(?<![a-z])Class',
    'goods' => 'Description\s*of', 'used' => 'Used\s*Since', 'juris' => 'Jurisdiction', 'cond' => 'Condition\s*And', 'assoc' => 'Associated\s*Marks',
    'name' => 'Advertised\s*Trade\s*Mark',
];

/** Cells of a page that are not the page number. */
function tmj_clean_cells(array $cells): array
{
    return array_values(array_filter($cells, fn($c) => !($c['y'] < 45 && preg_match('~^\d{1,4}$~', $c['t']))));
}

function tmj_record(array $cells, float $width = 595.0): ?array
{
    $cells = tmj_clean_cells($cells);
    if (!$cells) return null;
    $txt = tmp_cells_text($cells);
    if (!preg_match('~Application\s*Number\s*:?\s*(\d{4,9})~i', $txt, $m)) return tmj_record_classic($cells);
    $r = ['app_no' => $m[1], 'journal' => preg_match('~Journal\s*Number\s*:?\s*(\d{3,5})~i', $txt, $j) ? $j[1] : '', 'filed_on' => '',
        'name' => '', 'class' => '', 'applicant' => '', 'goods' => '', 'address' => '', 'used' => '', 'type' => '', 'juris' => '', 'name_pending' => false, 'name_y' => null];
    if (preg_match('~\b(\d{2})-(\d{2})-(\d{4})\b~', $txt, $d)) $r['filed_on'] = $d[3] . '-' . $d[2] . '-' . $d[1];

    $top = null;
    foreach ($cells as $c) if (stripos($c['t'], 'Application Details') !== false) { $top = $c['y']; break; }
    if ($top === null) foreach ($cells as $c) if (stripos($c['t'], 'PUBLICATION DETAILS') !== false) { $top = $c['y']; break; }
    $body = array_values(array_filter($cells, fn($c) => $top === null || $c['y'] < $top - 1));
    if (!$body) return $r;

    // Column split: left = labels, right = values.
    $minX = min(array_column($body, 'x0'));
    $colX = $minX + $width * 0.15;
    foreach ($body as $c) if ($c['x0'] < $colX && preg_match('~Proprietor/Applicant\s*$~i', $c['t'])) {
        foreach ($body as $v) if ($v['x0'] > $c['x1'] && abs($v['y'] - $c['y']) < 2) { $colX = ($c['x0'] + $v['x0']) / 2; break 2; }
    }

    // Label blocks: every label starts with its Hindi text; English-only lines continue the label above.
    $hasHindi = false;
    foreach ($body as $c) if ($c['x0'] < $colX && preg_match('~\p{Devanagari}~u', $c['t'])) { $hasHindi = true; break; }
    $blocks = [];
    foreach ($body as $c) {
        if ($c['x0'] >= $colX) continue;
        $start = $hasHindi ? (bool)preg_match('~\p{Devanagari}~u', $c['t']) : true;
        if ($start || !$blocks) $blocks[] = ['y' => $c['y'], 'label' => $c['t']];
        else $blocks[count($blocks) - 1]['label'] .= ' ' . $c['t'];
    }
    $keys = [];
    foreach ($blocks as $i => $b) {
        $en = trim((string)preg_replace('~[^\x20-\x7E]+~', ' ', $b['label']));
        $keys[$i] = '';
        foreach (TMJ_LABELS as $k => $re) if (preg_match('~' . $re . '~i', $en)) { $keys[$i] = $k; break; }
    }
    $nameAt = null;
    foreach ($blocks as $i => $b) if ($keys[$i] === 'name') { $nameAt = $b['y']; break; }

    // Value runs: right-column lines close to each other form one value (long goods lists run past their label).
    $runs = [];
    foreach ($body as $c) {
        if ($c['x0'] < $colX) continue;
        if ($nameAt !== null && $c['y'] < $nameAt - 1) continue;
        $k = count($runs) - 1;
        if ($k >= 0 && $runs[$k]['bot'] - $c['y'] <= $c['s'] * 1.45) { $runs[$k]['t'][] = $c['t']; $runs[$k]['bot'] = $c['y']; }
        else $runs[] = ['top' => $c['y'], 'bot' => $c['y'], 't' => [$c['t']]];
    }
    foreach ($runs as $run) {
        $best = null; $bd = INF;
        foreach ($blocks as $i => $b) {
            if ($keys[$i] === '' || $keys[$i] === 'name') continue;
            $d = ($b['y'] <= $run['top'] + 6 && $b['y'] >= $run['bot'] - 6) ? 0 : min(abs($b['y'] - $run['top']), abs($b['y'] - $run['bot']));
            if ($d < $bd) { $bd = $d; $best = $keys[$i]; }
        }
        if ($best !== null && isset($r[$best]) && $r[$best] === '') {
            $v = trim(implode(' ', $run['t']));
            if ($best === 'goods') $v = (string)preg_replace('~^Class\s*\d+\s*:\s*~i', '', $v);
            $r[$best] = $v;
        }
    }
    // The mark: everything printed below "Advertised Trade Mark".
    if ($nameAt !== null) {
        $vals = [];
        foreach ($body as $c) if ($c['y'] < $nameAt - 1) $vals[] = $c['t'];
        $r['name'] = implode(' ', $vals);
        $r['name_pending'] = trim($r['name']) === '' && $nameAt < 140;
        $r['name_y'] = $nameAt; // label at the foot of the page: the mark is on the next page
    }
    $r['class'] = preg_match('~(\d{1,2})~', $r['class'], $cm) ? $cm[1] : '';
    $r['name'] = mb_substr(trim((string)preg_replace('~\s+~u', ' ', $r['name'])), 0, 190);
    return $r;
}

/** Text of a page that only continues the previous mark (the mark word printed on the next page). */
function tmj_continuation(array $cells): string
{
    $cells = tmj_clean_cells($cells);
    if (!$cells || preg_match('~Application\s*Number~i', tmp_cells_text($cells))) return '';
    return mb_substr(trim((string)preg_replace('~\s+~u', ' ', implode(' ', array_column($cells, 't')))), 0, 190);
}

/**
 * Classic journal layout (most journals before Oct 2026): no labels, fixed order of lines.
 *   Trade Marks Journal No: 2280 , 28/09/2026 Class 1
 *   FEIYANG                                  <- the mark (missing when it is a logo)
 *   5906141 24/04/2023                       <- application number and filing date
 *   SATYAMS POLY TRADELINK PRIVATE LIMITED   <- applicant
 *   address lines …, type of applicant
 *   Address for service in India/Agents address: + agent lines (optional)
 *   Used Since :01/09/2022  |  Proposed to be Used
 *   AHMEDABAD                                <- Registry office
 *   goods / services …
 */
function tmj_record_classic(array $cells): ?array
{
    $lines = []; $ys = [];
    foreach (preg_split('~\n~', tmp_cells_text($cells)) as $i => $l) { $l = trim(str_replace("\t", ' ', $l)); if ($l !== '') $lines[] = $l; }
    // y of each joined line (first cell on that baseline)
    $prev = null; foreach ($cells as $c) { if ($prev === null || abs($c['y'] - $prev) > max(1.5, $c['s'] * 0.3)) $ys[] = $c['y']; $prev = $c['y']; }
    $h = -1; $k = -1; $journal = ''; $class = '';
    foreach ($lines as $i => $l) {
        if ($h < 0 && preg_match('~Trade\s*Marks\s*Journal\s*No\s*:?\s*(\d{3,5})\s*,?\s*\d{2}/\d{2}/\d{4}\s*Class\s*(\d{1,2})~i', $l, $m)) { $h = $i; $journal = $m[1]; $class = $m[2]; continue; }
        if ($h >= 0 && preg_match('~^(\d{5,9})\s+(\d{2})/(\d{2})/(\d{4})$~', $l, $m)) { $k = $i; $app = $m[1]; $filed = $m[4] . '-' . $m[3] . '-' . $m[2]; break; }
    }
    if ($h < 0 || $k < 0) return null;
    $r = ['app_no' => $app, 'journal' => $journal, 'filed_on' => $filed, 'name' => '', 'class' => $class, 'applicant' => $lines[$k + 1] ?? '', 'goods' => '', 'address' => '',
        'used' => '', 'type' => '', 'juris' => '', 'name_pending' => false, 'name_y' => ($ys[$h] ?? null) !== null ? $ys[$h] - 1 : null];
    $r['name'] = mb_substr(trim(implode(' ', array_slice($lines, $h + 1, $k - $h - 1))), 0, 190);
    $a = -1; $u = -1;
    for ($i = $k + 2, $n = count($lines); $i < $n; $i++) {
        if ($a < 0 && stripos($lines[$i], 'Address for service') === 0) $a = $i;
        if (preg_match('~^(Used\s*Since|Proposed\s*to\s*be\s*Used)~i', $lines[$i])) { $u = $i; break; }
    }
    if ($u < 0) return $r;
    $stop = $a >= 0 ? $a : $u;
    if ($stop - 1 > $k + 1) { $r['type'] = $lines[$stop - 1]; $r['address'] = implode(', ', array_slice($lines, $k + 2, $stop - $k - 3)); }
    $r['used'] = preg_match('~Used\s*Since\s*:?\s*(\S.*)$~i', $lines[$u], $m) ? 'Used since ' . trim($m[1]) : 'Proposed to be Used';
    $r['juris'] = $lines[$u + 1] ?? '';
    if (!preg_match('~^[A-Z][A-Z .]{2,30}$~', $r['juris'])) { $r['juris'] = ''; $g0 = $u + 1; } else $g0 = $u + 2;
    $goods = [];
    foreach (array_slice($lines, $g0) as $l) {
        if (preg_match('~^(THIS IS CONDITION|CONDITION|PRIORITY|ASSOCIATED|Advertised|Registration of this trade ?mark|Trade Marks Journal No)~i', $l)) break;
        $goods[] = $l;
    }
    $r['goods'] = trim(implode(' ', $goods));
    return $r;
}
