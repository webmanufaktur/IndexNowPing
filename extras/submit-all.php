<?php namespace ProcessWire;

/**
 * IndexNowPing extras/submit-all.php — one-time initial submission of all
 * indexable pages to IndexNow via the IndexNowPing module.
 *
 * Generic companion script for the IndexNowPing module
 * (https://github.com/webmanufaktur/IndexNowPing). Copy it into your
 * ProcessWire root (or any location inside the PW installation) and run it
 * from there — it needs a full ProcessWire boot to load the module.
 *
 * The template whitelist, canonical host, key and endpoint all come from the
 * module configuration (Modules → IndexNowPing); this script only gathers
 * the URLs and hands them to $modules->get('IndexNowPing')->submit().
 *
 * Usage (from the ProcessWire root of your site):
 *     php submit-all.php --dry-run        # default: print what would be sent
 *     php submit-all.php --yes            # actually submit
 *     php submit-all.php --yes --limit=50 # cap the number of URLs
 *
 * Run --dry-run first and check the output. Submitting the full stock more
 * than once is pointless — IndexNow asks to be told about *changes*; after
 * the initial run the module's page hooks keep engines up to date.
 */

if (PHP_SAPI !== 'cli') {
    die("CLI only.\n");
}

// Boot ProcessWire from wherever this script lives inside the site tree.
$root = dirname(__DIR__);
while ($root !== '/' && !is_file($root . '/index.php')) {
    $root = dirname($root);
}
if (!is_file($root . '/index.php')) {
    die("Could not locate a ProcessWire index.php above this script.\n");
}
chdir($root);
include 'index.php';
if (!isset($wire)) {
    die("Bootstrapping ProcessWire failed.\n");
}

/** @var IndexNowPing $module */
$module = $modules->get('IndexNowPing');
$cfg = $module->resolveConfig();

if (!empty($cfg['killswitch'])) {
    die("Kill-switch active (\$config->indexNow === false) — nothing will be sent.\n");
}
if (empty($cfg['enabled'])) {
    die("IndexNowPing is disabled — enable it in Modules → IndexNowPing first.\n");
}
if (empty($cfg['templates'])) {
    die("No templates configured in Modules → IndexNowPing — nothing to submit.\n");
}

$dryRun = true;
$limit = 0;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--yes') {
        $dryRun = false;
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, 8));
    } else {
        die("Unknown option: $arg\n");
    }
}

$selector = 'status=published, template=' . implode('|', $cfg['templates']) . ', sort=path';
$host = $cfg['host'];

$urls = [];
$skippedHidden = 0;
$skippedNoindex = 0;
$firstRawUrl = null;

foreach (wire('pages')->find($selector) as $page) {
    if ($page->isHidden()) {
        $skippedHidden++;
        continue;
    }

    $robots = '';
    if ($page->hasField('seo_robots')) {
        $value = $page->getUnformatted('seo_robots');
        $robots = is_object($value) ? (string) $value->title : (string) $value;
    }
    if (stripos($robots, 'noindex') !== false) {
        $skippedNoindex++;
        continue;
    }

    if ($firstRawUrl === null) {
        $firstRawUrl = $page->httpUrl();
    }
    // The module normalizes again on submit; this keeps the preview honest.
    $urls[] = preg_replace('#^https?://[^/]+#', "https://{$host}", $page->httpUrl());

    if ($limit && count($urls) >= $limit) {
        break;
    }
}

$urls = array_values(array_unique($urls));

$hostCounts = [];
foreach ($urls as $url) {
    $h = parse_url($url, PHP_URL_HOST) ?: '(no host)';
    $hostCounts[$h] = ($hostCounts[$h] ?? 0) + 1;
}

echo "IndexNowPing — submit-all\n";
echo '  Selector          : ' . $selector . "\n";
echo '  URLs              : ' . count($urls) . "\n";
echo '  Skipped           : ' . $skippedHidden . ' hidden, ' . $skippedNoindex . " noindex\n";
echo '  Raw sample        : ' . ($firstRawUrl ?? '-') . "  (CLI httpUrl() before normalization)\n";
echo '  Hosts             : ' . ($hostCounts ? implode(', ', array_map(
    fn ($h) => "$h ({$hostCounts[$h]})",
    array_keys($hostCounts)
)) : '-') . "\n";
echo '  Mode              : ' . ($dryRun ? "DRY-RUN (no request)" : 'LIVE') . "\n\n";

foreach (array_slice($urls, 0, 10) as $i => $url) {
    echo sprintf("  %2d. %s\n", $i + 1, $url);
}
if (count($urls) > 10) {
    echo '  … ' . (count($urls) - 10) . " more\n";
}

if ($dryRun) {
    echo "\nDry-run done — nothing was sent. To submit for real: --yes\n";
    exit(0);
}

if (!$urls) {
    echo "\nNo URLs — nothing to submit.\n";
    exit(0);
}

$result = $module->submit($urls, 'batch');
echo "\nResult: " . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
echo 'Log: ' . wire('config')->paths->logs . "indexnow.txt\n";
