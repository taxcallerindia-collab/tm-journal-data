<?php
/**
 * Daily robot step: government updates for trademark classes and filing.
 * Reads (1) IP India "News & Updates" (current and archived lists) and (2) the WIPO Nice Classification
 * version list, and writes data/updates.json. taxcaller.com shows these on /trademark-class-search.
 * Titles and links are copied as published; nothing is rewritten.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
@mkdir($root . '/data', 0777, true);
$file = $root . '/data/updates.json';
$old = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];

function get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 90, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/html,*/*;q=0.8', 'Accept-Language: en-IN,en;q=0.8']]);
    $b = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $e = curl_error($ch); curl_close($ch);
    return [$c, is_string($b) ? $b : '', $e];
}

function clean(string $s): string { return trim((string)preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'))); }

/** Kind of notice, from words in its title. Empty = not about trademark filing or classes. */
function kind(string $title, string $cat): string
{
    $t = mb_strtolower($title);
    if (preg_match('~quotation|tender|recruit|vacanc|kavi|pratiyogita|hindi|logo|examination result|pae|tae|hearing|adjourn|holiday|closure of office~', $t)) return '';
    $tm = stripos($cat, 'trade') !== false || preg_match('~trade ?marks?|\btm\b|nice classification|well[- ]known~', $t);
    if (!$tm) return '';
    if (preg_match('~nice|classif|\bclass(es)?\b~', $t)) return 'Classification';
    if (preg_match('~well[- ]known~', $t)) return 'Well-known marks';
    if (preg_match('~\bfees?\b|refund|payment~', $t)) return 'Fees';
    if (preg_match('~\brules?\b|amend|act,|gazette~', $t)) return 'Rules';
    if (preg_match('~manual|practice|procedure|guideline~', $t)) return 'Practice and procedure';
    if (preg_match('~e-?filing|form|filing|online|module|portal~', $t)) return 'Filing';
    if (preg_match('~journal~', $t)) return 'Trade Marks Journal';
    return 'Notice';
}

$errors = [];
$notices = [];
foreach (['https://ipindia.gov.in/dynamic/news-updates?month=0&category=0', 'https://ipindia.gov.in/dynamic/news-updates-archived?month=0&category=0',
          'https://ipindia.gov.in/dynamic/news-updates-archived?month=0&category=3'] as $u) {
    [$c, $html, $e] = get($u);
    if ($c !== 200 || $html === '') { $errors[] = "IP India $u: HTTP $c $e"; continue; }
    if (!preg_match_all('~<tr\b[^>]*>(.*?)</tr>~si', $html, $rows)) continue;
    foreach ($rows[1] as $r) {
        if (!preg_match_all('~<td\b[^>]*>(.*?)</td>~si', $r, $td) || count($td[1]) < 4) continue;
        $cat = clean($td[1][1]);
        if (!preg_match('~(\d{2})-(\d{2})-(\d{4})~', clean($td[1][2]), $d)) continue;
        $title = clean($td[1][3]);
        if ($title === '' || ($k = kind($title, $cat)) === '') continue;
        $link = preg_match('~href="([^"]*news-details/\d+)"~i', $td[1][3] . ($td[1][5] ?? ''), $m) ? $m[1] : '';
        $pdf = preg_match('~href="([^"]+\.pdf)"~i', $td[1][4] ?? '', $m) ? $m[1] : '';
        if ($pdf !== '' && $pdf[0] === '/') $pdf = 'https://ipindia.gov.in' . $pdf;
        $key = md5(mb_strtolower($title) . $d[0]);
        $notices[$key] = ['date' => "$d[3]-$d[2]-$d[1]", 'title' => mb_substr($title, 0, 300), 'kind' => $k, 'url' => $link, 'pdf' => $pdf, 'source' => 'IP India'];
    }
}
usort($notices, fn($a, $b) => strcmp($b['date'], $a['date']));
$notices = array_slice(array_values($notices), 0, 15);
if (!$notices && !empty($old['notices'])) $notices = $old['notices']; // a failed read never empties the list

// WIPO Nice Classification: the version selected by default is the one in force; a later one listed above it is published but not yet in force.
$nice = $old['nice'] ?? null;
[$c, $html, $e] = get('https://nclpub.wipo.int/enfr/');
if ($c === 200 && preg_match('~edition_version_selector.*?</select>~si', $html, $sel) && preg_match_all('~<option value="(\d*)"([^>]*)>\s*(\d{1,2}-\d{4})\s*</option>~i', $sel[0], $op, PREG_SET_ORDER)) {
    $versions = []; $inForce = ''; $upcoming = '';
    foreach ($op as $o) { $versions[] = $o[3]; if (stripos($o[2], 'selected') !== false) $inForce = $o[3]; }
    if ($inForce !== '') foreach ($versions as $v) { if ($v === $inForce) break; $upcoming = $v; }
    if ($inForce !== '') $nice = ['in_force' => $inForce, 'upcoming' => $upcoming, 'versions' => array_slice($versions, 0, 6), 'url' => 'https://nclpub.wipo.int/enfr/'];
} else $errors[] = "WIPO NCL: HTTP $c $e";

$out = ['checked' => gmdate('c'), 'nice' => $nice, 'notices' => $notices, 'errors' => $errors];
$same = $old && json_encode([$old['nice'] ?? null, $old['notices'] ?? []]) === json_encode([$nice, $notices]);
$out['changed'] = $same ? ($old['changed'] ?? gmdate('c')) : gmdate('c'); // date of the last real change
file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Nice in force: ' . ($nice['in_force'] ?? '?') . ', upcoming: ' . (($nice['upcoming'] ?? '') ?: 'none') . ' | notices kept: ' . count($notices) . ($errors ? ' | errors: ' . implode('; ', $errors) : '') . "\n";
if (getenv('GITHUB_ACTIONS')) echo '::notice::Updates: Nice ' . ($nice['in_force'] ?? '?') . (($nice['upcoming'] ?? '') ? ' (next ' . $nice['upcoming'] . ')' : '') . ', ' . count($notices) . ' trademark notices' . ($errors ? ', errors: ' . count($errors) : '') . "\n";
