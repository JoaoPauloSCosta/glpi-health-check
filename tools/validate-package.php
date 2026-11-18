<?php

/**
 * Validates a release archive of the Health Check plugin.
 *
 *   php tools/validate-package.php dist/glpi-healthcheck-1.1.0.tar.bz2 [--check-urls]
 *
 * Checks:
 *  1. archive integrity (every entry is decompressed and read);
 *  2. a single root directory named "healthcheck";
 *  3. required files are present;
 *  4. no development or temporary files;
 *  5. the version in the archive name, setup.php and healthcheck.xml match,
 *     and healthcheck.xml is well formed with the expected plugin key;
 *  6. PHP syntax of every PHP file (with the PHP binary running this script);
 *  7. with --check-urls: the download URLs declared in healthcheck.xml answer HTTP 200.
 *
 * Exit code: 0 when every check passed, 1 otherwise.
 * Requires the phar and bz2 PHP extensions.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args       = array_slice($argv, 1);
$check_urls = in_array('--check-urls', $args, true);
$args       = array_values(array_filter($args, static fn ($a) => strpos($a, '--') !== 0));

if (count($args) !== 1) {
    fwrite(STDERR, "Usage: php tools/validate-package.php <archive.tar.bz2> [--check-urls]\n");
    exit(1);
}

$archive = $args[0];
$errors  = 0;

function pass(string $msg): void
{
    echo "  [OK]   $msg\n";
}

function fail(string $msg): void
{
    global $errors;
    $errors++;
    echo "  [FAIL] $msg\n";
}

function info(string $msg): void
{
    echo "  [INFO] $msg\n";
}

echo "Validating $archive\n";

foreach (['phar', 'bz2'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "The PHP extension '$ext' is required.\n");
        exit(1);
    }
}

// --- 1. Integrity ---------------------------------------------------------------------------
echo "\n1. Archive integrity\n";
if (!is_file($archive)) {
    fail("File not found: $archive");
    exit(1);
}
if (!preg_match('/^glpi-healthcheck-(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)\.tar\.bz2$/', basename($archive), $m)) {
    fail('Archive name must follow "glpi-healthcheck-<version>.tar.bz2", got "' . basename($archive) . '"');
    exit(1);
}
$archive_version = $m[1];
pass('Archive name follows glpi-healthcheck-<version>.tar.bz2');

$entries = []; // relative path => size (directories end with "/")
try {
    $phar   = new PharData(realpath($archive));
    $prefix = 'phar://' . str_replace('\\', '/', realpath($archive)) . '/';
    $it     = new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        $rel  = substr($path, strlen($prefix));
        if ($file->isDir()) {
            $entries[$rel . '/'] = 0;
            continue;
        }
        // Read the whole content to make sure the entry can be decompressed
        $content = file_get_contents($path);
        if ($content === false || strlen($content) !== $file->getSize()) {
            fail("Unable to read entry $rel");
            continue;
        }
        $entries[$rel] = strlen($content);
    }
} catch (\Throwable $e) {
    fail('Unable to open the archive: ' . $e->getMessage());
    exit(1);
}
$nb_files = count(array_filter(array_keys($entries), static fn ($p) => substr($p, -1) !== '/'));
pass(sprintf('Archive decompressed and read: %d files, %s bytes', $nb_files, number_format(array_sum($entries), 0, '.', ' ')));

// --- 2. Root directory ----------------------------------------------------------------------
echo "\n2. Root directory\n";
$roots = [];
foreach (array_keys($entries) as $rel) {
    $roots[explode('/', $rel)[0]] = true;
}
$roots = array_keys($roots);
if ($roots === ['healthcheck']) {
    pass('Single root directory "healthcheck/"');
} else {
    fail('Expected a single root directory "healthcheck/", found: ' . implode(', ', $roots));
}

// --- 3. Required files ----------------------------------------------------------------------
echo "\n3. Required files\n";
$required = [
    'setup.php',
    'hook.php',
    'healthcheck.xml',
    'LICENSE',
    'README.md',
    'README.pt_BR.md',
    'CHANGELOG.md',
    'public/css/healthcheck.css',
    'src/Compat.php',
    'src/Menu.php',
    'src/Runner.php',
    'front/dashboard.php',
    'templates/dashboard.html.twig',
    'locales/pt_BR.mo',
    'locales/en_GB.mo',
    'locales/en_US.mo',
];
foreach ($required as $file) {
    if (isset($entries["healthcheck/$file"])) {
        pass($file);
    } else {
        fail("Missing $file");
    }
}
foreach (['front' => '.php', 'src/Check' => '.php', 'templates' => '.twig'] as $dir => $ext) {
    $nb = count(array_filter(
        array_keys($entries),
        static fn ($p) => strpos($p, "healthcheck/$dir/") === 0 && substr($p, -strlen($ext)) === $ext
    ));
    $nb > 0 ? pass("$dir/: $nb $ext files") : fail("No $ext file in $dir/");
}

// --- 4. Forbidden files ---------------------------------------------------------------------
echo "\n4. Development and temporary files\n";
$forbidden = [
    '#(^|/)\.git(/|$)#'                    => 'git metadata',
    '#(^|/)\.git(ignore|attributes)$#'     => 'git configuration',
    '#(^|/)\.github/#'                     => 'GitHub configuration',
    '#^healthcheck/(tools|dist|tests?)/#'  => 'development directory',
    '#(^|/)(node_modules|\.idea|\.vscode)/#' => 'dependencies / IDE files',
    '#(^|/)(\.DS_Store|Thumbs\.db|desktop\.ini)$#i' => 'OS metadata',
    '#\.(tar|bz2|gz|tgz|zip|7z|rar)$#i'    => 'nested archive',
    '#(~|\.swp|\.swo|\.bak|\.orig|\.rej|\.tmp|\.log)$#i' => 'temporary / backup file',
    '#^healthcheck/RELEASING\.md$#'        => 'maintainer documentation',
];
$found = 0;
foreach (array_keys($entries) as $rel) {
    foreach ($forbidden as $regex => $label) {
        if (preg_match($regex, $rel)) {
            fail("Unexpected $label: $rel");
            $found++;
            break;
        }
    }
}
if ($found === 0) {
    pass('No development, temporary or OS file');
}

// --- 5. Versions and descriptor -------------------------------------------------------------
echo "\n5. Version consistency and healthcheck.xml\n";
$setup = @file_get_contents($prefix . 'healthcheck/setup.php');
$setup_version = null;
if ($setup !== false && preg_match("/define\('PLUGIN_HEALTHCHECK_VERSION',\s*'([^']+)'\)/", $setup, $sm)) {
    $setup_version = $sm[1];
}
if ($setup_version === $archive_version) {
    pass("setup.php version $setup_version matches the archive name");
} else {
    fail('setup.php version (' . var_export($setup_version, true) . ") does not match the archive version $archive_version");
}

$xml_content = @file_get_contents($prefix . 'healthcheck/healthcheck.xml');
$download_urls = [];
if ($xml_content === false) {
    fail('Unable to read healthcheck.xml');
} else {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_content);
    if ($xml === false) {
        foreach (libxml_get_errors() as $error) {
            fail('healthcheck.xml: ' . trim($error->message) . ' (line ' . $error->line . ')');
        }
    } else {
        pass('healthcheck.xml is well formed');
        (string) $xml->key === 'healthcheck'
            ? pass('Plugin key is "healthcheck"')
            : fail('Plugin key is "' . $xml->key . '", expected "healthcheck"');

        foreach (['name', 'key', 'state', 'homepage', 'download', 'issues', 'readme', 'license'] as $tag) {
            if (trim((string) $xml->$tag) === '') {
                fail("healthcheck.xml: <$tag> is empty or missing");
            }
        }
        if (!isset($xml->description->short->en) || !isset($xml->description->long->en)) {
            fail('healthcheck.xml: an English short and long description is required');
        }

        $found_version = false;
        foreach ($xml->versions->version as $version) {
            $num  = (string) $version->num;
            $comp = (string) $version->compatibility;
            $url  = (string) $version->download_url;
            if (!preg_match('/^~\d+\.\d+\.\d+$/', $comp)) {
                fail("healthcheck.xml: version $num has an unexpected compatibility \"$comp\" (expected ~X.Y.0)");
            }
            if ($url !== '') {
                $download_urls[$url] = true;
            }
            if ($num === $archive_version) {
                $found_version = true;
                if ($url !== '' && basename($url) !== basename($archive)) {
                    fail("healthcheck.xml: download_url of $num points to " . basename($url) . ', expected ' . basename($archive));
                }
            }
        }
        $found_version
            ? pass("healthcheck.xml declares version $archive_version")
            : fail("healthcheck.xml does not declare version $archive_version");

        // GLPI branches declared in XML must be within the range declared in setup.php
        if ($setup !== false && preg_match("/PLUGIN_HEALTHCHECK_MIN_GLPI_VERSION',\s*'([^']+)'/", $setup, $min)
            && preg_match("/PLUGIN_HEALTHCHECK_MAX_GLPI_VERSION',\s*'([^']+)'/", $setup, $max)) {
            foreach ($xml->versions->version as $version) {
                if ((string) $version->num !== $archive_version) {
                    continue;
                }
                $branch = ltrim((string) $version->compatibility, '~');
                if (version_compare($branch, $min[1], '<') || version_compare($branch, $max[1], '>')) {
                    fail("healthcheck.xml declares GLPI $branch, outside setup.php range {$min[1]} - {$max[1]}");
                } else {
                    pass("GLPI $branch is within setup.php range {$min[1]} - {$max[1]}");
                }
            }
        }
    }
}

// --- 6. PHP syntax --------------------------------------------------------------------------
echo "\n6. PHP syntax (" . PHP_BINARY . ', PHP ' . PHP_VERSION . ")\n";
$tmp = sys_get_temp_dir() . '/hc-validate-' . bin2hex(random_bytes(4));
try {
    $phar->extractTo($tmp);
    $lint_errors = 0;
    $nb_php      = 0;
    foreach (array_keys($entries) as $rel) {
        if (substr($rel, -4) !== '.php') {
            continue;
        }
        $nb_php++;
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg("$tmp/$rel") . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $lint_errors++;
            fail("$rel: " . implode(' ', $out));
        }
    }
    if ($lint_errors === 0) {
        pass("$nb_php PHP files without syntax error");
    }
} finally {
    // Remove the extracted copy
    if (is_dir($tmp)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($tmp);
    }
}

// --- 7. Download URLs -----------------------------------------------------------------------
echo "\n7. Download URLs declared in healthcheck.xml\n";
if (!$check_urls) {
    info('Skipped (use --check-urls once the GitHub release is published)');
} elseif (!function_exists('curl_init')) {
    fail('The curl PHP extension is required for --check-urls');
} else {
    foreach (array_keys($download_urls) as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'healthcheck-release-validator',
        ]);
        // CA bundle for PHP builds without curl.cainfo
        if (($ca = getenv('CURL_CA_BUNDLE')) !== false && is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);
        $status === 200 ? pass("$url (HTTP 200)") : fail("$url (HTTP $status $error)");
    }
}

echo "\n" . ($errors === 0 ? 'Package is valid.' : "$errors problem(s) found.") . "\n";
exit($errors === 0 ? 0 : 1);
