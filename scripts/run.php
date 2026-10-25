<?php
/**
 * Weekly robot (GitHub Actions): reads the official Trade Marks Journal PDFs from IP India
 * and writes one small CSV per journal into data/journals/, plus data/index.json.
 * taxcaller.com only downloads these small files; no PDF ever touches the website server.
 *
 *   php scripts/run.php            normal run (new journals first, then older ones)
 *   php scripts/run.php --test     only checks that IP India is reachable and reads 30 pages
 */
declare(strict_types=1);
require __DIR__ . '/pdf.php';
require __DIR__ . '/parse.php';

const HOST = 'https://search.ipindia.gov.in';
const LIST_URL = HOST . '/IPOJournal/Journal/Trademark';
const VIEW_URL = HOST . '/IPOJournal/Journal/ViewJournal';
const BACK = 26;                 // also import the 26 journals before the first one seen (about 6 months)
const MAX_JOURNALS = 3;          // per run
const TIME_LIMIT = 5 * 3600;     // GitHub allows 6 hours per job

$root = dirname(__DIR__);
$dataDir = $root . '/data';
@mkdir($dataDir . '/journals', 0777, true);
$indexFile = $dataDir . '/index.json';
$index = is_file($indexFile) ? (json_decode((string)file_get_contents($indexFile), true) ?: []) : [];
$index += ['journals' => [], 'first' => 0, 'last_check' => '', 'last_error' => '', 'latest_on_ipindia' => null];
$test = in_array('--test', $argv, true);
$debug = (int)(getenv('TMJ_DEBUG') ?: 0); // journal number: dump page text for checking the reader
$t0 = time();

function say(string $s, bool $note = false): void
{
    echo '[' . gmdate('H:i:s') . '] ' . $s . "\n";
    if ($note && getenv('GITHUB_ACTIONS')) echo '::notice::' . str_replace(["\r", "\n", '%'], [' ', ' ', '%25'], $s) . "\n"; // shows in the run summary
}

function http(string $url, array $opt = []): array
{
    $ch = curl_init($url);
    $toFile = isset($opt[CURLOPT_FILE]);
    curl_setopt_array($ch, $opt + ($toFile ? [] : [CURLOPT_RETURNTRANSFER => true]) + [ CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 180, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/pdf;q=0.9,*/*;q=0.8', 'Accept-Language: en-IN,en;q=0.8']]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, is_string($body) ? $body : '', $err];
}

function journal_list(): array
{
    for ($try = 1; $try <= 3; $try++) {
        [$code, $html, $err] = http(LIST_URL);
        if ($code === 200 && $html !== '') break;
        say("list attempt $try failed: HTTP $code $err");
        sleep(20 * $try);
    }
    if ($code !== 200 || $html === '') throw new RuntimeException('IP India journal list not reachable (HTTP ' . $code . ($err !== '' ? ', ' . $err : '') . ')');
    $out = [];
    if (preg_match_all('~<tr[^>]*>(.*?)</tr>~si', $html, $rows)) foreach ($rows[1] as $r) {
        if (!preg_match_all('~<td[^>]*>(.*?)</td>~si', $r, $td) || count($td[1]) < 5) continue;
        $no = (int)trim(strip_tags($td[1][1]));
        if ($no < 1000) continue;
        preg_match_all('~name="FileName"\s+value="([^"]+)"~i', $td[1][4], $fm);
        $files = array_values(array_filter(array_map(fn($f) => html_entity_decode($f, ENT_QUOTES), $fm[1]), fn($f) => (bool)preg_match('~\.pdf$~i', $f)));
        if ($files) $out[] = ['no' => $no, 'date' => trim(strip_tags($td[1][2])), 'files' => $files];
    }
    if (!$out) throw new RuntimeException('IP India journal page changed: no journals found');
    usort($out, fn($a, $b) => $b['no'] <=> $a['no']);
    return $out;
}

function download(string $fileName, string $dest): string
{
    for ($try = 1; $try <= 3; $try++) {
        $fh = fopen($dest, 'wb');
        [$code, , $err] = http(VIEW_URL, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['FileName' => $fileName]), CURLOPT_FILE => $fh,
            CURLOPT_TIMEOUT => 900, CURLOPT_REFERER => LIST_URL]);
        fclose($fh);
        $head = (string)@file_get_contents($dest, false, null, 0, 5);
        if ($code === 200 && $head === '%PDF-' && filesize($dest) > 2000) return '';
        say("download attempt $try failed: HTTP $code $err " . ($head !== '%PDF-' ? '(not a PDF)' : ''));
        @unlink($dest);
        sleep(30 * $try);
    }
    return 'download failed (HTTP ' . $code . ($err !== '' ? ', ' . $err : '') . ')';
}

/** Read one PDF; returns [rows, logos, pages]. */
function read_pdf(string $file, int $maxPages = 0): array
{
    $pdf = tmp_open($file);
    if (!$pdf) throw new RuntimeException('not a readable PDF');
    $rows = []; $logos = 0; $pending = null;
    $pages = tmp_pages($pdf);
    $flush = function (?array $r) use (&$rows, &$logos) {
        if (!$r) return;
        if ($r['name'] === '' || !preg_match('~[\p{L}\p{N}]~u', $r['name'])) { $logos++; return; }
        $rows[] = $r;
    };
    foreach ($pages as $i => $pg) {
        if ($maxPages && $i >= $maxPages) break;
        try { $cells = tmp_page_cells($pdf, $pg); } catch (Throwable) { continue; }
        $r = tmj_record($cells);
        if ($r) {
            $flush($pending);
            $pending = $r['name_pending'] ? $r : null;
            if (!$r['name_pending']) $flush($r);
        } elseif ($pending) {
            $pending['name'] = tmj_continuation($cells);
            $flush($pending);
            $pending = null;
        }
    }
    $flush($pending);
    tmp_close($pdf);
    return [$rows, $logos, count($pages)];
}

function save_index(string $file, array $index): void
{
    usort($index['journals'], fn($a, $b) => $b['no'] <=> $a['no']);
    file_put_contents($file, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

$index['last_check'] = gmdate('c');
try {
    $list = journal_list();
} catch (Throwable $e) {
    $index['last_error'] = $e->getMessage() . ' (' . gmdate('Y-m-d H:i') . ' UTC)';
    save_index($indexFile, $index);
    say('ERROR ' . $e->getMessage(), true);
    exit($test ? 1 : 0);
}
$index['latest_on_ipindia'] = ['no' => $list[0]['no'], 'date' => $list[0]['date'], 'files' => count($list[0]['files'])];
say('IP India reachable. Latest journal ' . $list[0]['no'] . ' (' . $list[0]['date'] . '), ' . count($list[0]['files']) . ' PDF files, ' . count($list) . ' journals listed', true);

if ($debug) {
    $j = null; foreach ($list as $x) if ($x['no'] === $debug) $j = $x;
    if (!$j) { say("journal $debug not listed", true); exit(1); }
    @mkdir($root . '/debug', 0777, true);
    $out = '';
    foreach (array_slice($j['files'], 0, 2) as $f) {
        $tmp = sys_get_temp_dir() . '/tmj-dbg.pdf';
        if (($e = download($f, $tmp)) !== '') { $out .= "$f: $e\n"; continue; }
        $pdf = tmp_open($tmp); $pages = tmp_pages($pdf); $out .= "=== $f: " . count($pages) . " pages, " . filesize($tmp) . " bytes\n";
        $logoShown = 0;
        foreach ($pages as $i => $pg) {
            $cells = tmp_page_cells($pdf, $pg); $r = tmj_record($cells);
            $isLogo = $r && ($r['name'] === '' && !$r['name_pending']);
            if ($i < 14 || ($isLogo && $logoShown++ < 6)) {
                $out .= "--- page " . ($i + 1) . ($r ? ' record ' . json_encode(['app' => $r['app_no'], 'name' => $r['name'], 'class' => $r['class'], 'pending' => $r['name_pending']]) : ' no record') . "\n";
                foreach ($cells as $c) $out .= sprintf("%6.1f %6.1f %s\n", $c['y'], $c['x0'], mb_substr($c['t'], 0, 140));
            }
            if ($i > 400) break;
        }
        tmp_close($pdf); @unlink($tmp);
    }
    file_put_contents($root . '/debug/' . $debug . '.txt', $out);
    say('debug written', true);
    exit(0);
}

if ($test) {
    $tmp = sys_get_temp_dir() . '/tmj-test.pdf';
    $t = microtime(true);
    $err = download($list[0]['files'][0], $tmp);
    if ($err !== '') { say('ERROR ' . $err, true); exit(1); }
    say('Downloaded ' . basename(str_replace('\\', '/', $list[0]['files'][0])) . ': ' . round(filesize($tmp) / 1048576, 1) . ' MB in ' . round(microtime(true) - $t) . 's', true);
    [$rows, $logos, $pages] = read_pdf($tmp, 40);
    say("Read 40 of $pages pages: " . count($rows) . " word marks, $logos logo-only marks", true);
    foreach (array_slice($rows, 0, 6) as $r) say($r['app_no'] . ' | ' . $r['name'] . ' | class ' . $r['class'] . ' | ' . mb_substr($r['applicant'], 0, 40) . ' | ' . mb_substr($r['goods'], 0, 60), true);
    @unlink($tmp);
    exit(0);
}

if (!$index['first']) $index['first'] = (int)$list[0]['no'];
$done = array_column($index['journals'], 'no');
$todo = array_values(array_filter($list, fn($j) => !in_array($j['no'], $done, true) && $j['no'] >= $index['first'] - BACK));
$errors = [];
foreach (array_slice($todo, 0, MAX_JOURNALS) as $j) {
    if (time() - $t0 > TIME_LIMIT) break;
    say('Journal ' . $j['no'] . ' (' . $j['date'] . '): ' . count($j['files']) . ' files');
    $all = []; $logos = 0; $fileErr = []; $pagesTotal = 0;
    foreach ($j['files'] as $f) {
        $short = basename(str_replace('\\', '/', $f));
        $tmp = sys_get_temp_dir() . '/tmj-' . $j['no'] . '.pdf';
        $err = download($f, $tmp);
        if ($err !== '') { $fileErr[] = $short . ': ' . $err; continue; }
        try {
            [$rows, $lg, $pages] = read_pdf($tmp);
            $pagesTotal += $pages; $logos += $lg;
            foreach ($rows as $r) $all[$r['app_no']] = $r;
            say("  $short: $pages pages, " . count($rows) . " word marks, $lg logo-only");
            if (!$rows && !$lg && $pages > 20) $fileErr[] = $short . ': no marks recognised (layout may have changed)';
        } catch (Throwable $e) {
            $fileErr[] = $short . ': ' . $e->getMessage();
        }
        @unlink($tmp);
    }
    if (!$all && $fileErr) { $errors[] = 'Journal ' . $j['no'] . ': ' . $fileErr[0]; continue; } // try again next run
    $csv = 'journals/' . $j['no'] . '.csv.gz';
    $gz = gzopen($dataDir . '/' . $csv, 'wb9');
    $out = fopen('php://memory', 'w+');
    fputcsv($out, ['app_no', 'name', 'class', 'applicant', 'goods', 'filed_on', 'journal_no', 'journal_date', 'used_since', 'jurisdiction'], ',', '"', '');
    foreach ($all as $r) fputcsv($out, [$r['app_no'], $r['name'], $r['class'], mb_substr($r['applicant'], 0, 250), mb_substr($r['goods'], 0, 600), $r['filed_on'],
        $r['journal'] ?: (string)$j['no'], $j['date'], mb_substr($r['used'], 0, 60), mb_substr($r['juris'], 0, 40)], ',', '"', '');
    rewind($out);
    gzwrite($gz, (string)stream_get_contents($out));
    gzclose($gz); fclose($out);
    $index['journals'][] = ['no' => $j['no'], 'date' => $j['date'], 'marks' => count($all), 'logos' => $logos, 'pages' => $pagesTotal, 'files' => count($j['files']),
        'errors' => $fileErr, 'csv' => $csv, 'bytes' => filesize($dataDir . '/' . $csv), 'done_at' => gmdate('c')];
    save_index($indexFile, $index);
    say('Journal ' . $j['no'] . ': saved ' . count($all) . " word marks, $logos logo-only" . ($fileErr ? ', problems: ' . implode('; ', $fileErr) : ''), true);
}
$index['last_error'] = $errors ? implode('; ', $errors) . ' (' . gmdate('Y-m-d H:i') . ' UTC)' : '';
save_index($indexFile, $index);
say('Done. ' . count($index['journals']) . ' journals in data.');
