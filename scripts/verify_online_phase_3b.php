<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$failures = [];
$passes = [];

function phase3b_pass(array &$passes, string $message): void
{
    $passes[] = $message;
    echo "[PASS] {$message}" . PHP_EOL;
}

function phase3b_fail(array &$failures, string $message): void
{
    $failures[] = $message;
    echo "[FAIL] {$message}" . PHP_EOL;
}

function phase3b_assert(
    bool $condition,
    array &$passes,
    array &$failures,
    string $message
): void {
    if ($condition) {
        phase3b_pass($passes, $message);
        return;
    }

    phase3b_fail($failures, $message);
}

$requiredFiles = [
    'config/storage_security.php',
    'config/upload_policy.php',
    'uploads/.htaccess',
    'download.php',
    'api/upload_file.php',
    'actions/collection_payment_handler.php',
    'actions/delivery_completion_handler.php',
    'actions/document_handler.php',
    'actions/pr_handler.php',
    'actions/quotation_handler.php',
    'actions/po_handler.php',
    'actions/version_handler.php',
    'actions/user_handler.php',
];

foreach ($requiredFiles as $relativeFile) {
    phase3b_assert(
        is_file($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile)),
        $passes,
        $failures,
        "Required file exists: {$relativeFile}"
    );
}

if ($failures !== []) {
    echo PHP_EOL . 'Phase 3B verification stopped because required files are missing.' . PHP_EOL;
    exit(1);
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'storage_security.php';

try {
    $storageRoot = drms_storage_root();
    phase3b_assert(
        realpath($storageRoot) === realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads'),
        $passes,
        $failures,
        'Central storage root resolves to this installation uploads directory.'
    );
    phase3b_assert(
        is_writable($storageRoot),
        $passes,
        $failures,
        'Protected upload directory is writable by PHP.'
    );
} catch (Throwable $error) {
    phase3b_fail($failures, 'Storage root check failed: ' . $error->getMessage());
    $storageRoot = '';
}

$cleanPathChecks = [
    'uploads/payments/example.pdf' => 'payments/example.pdf',
    'avatars/example.jpg' => 'avatars/example.jpg',
    './uploads/client_po/example.pdf' => 'client_po/example.pdf',
];

foreach ($cleanPathChecks as $input => $expected) {
    try {
        $actual = drms_storage_clean_relative_path($input);
        phase3b_assert(
            $actual === $expected,
            $passes,
            $failures,
            "Stored path is normalized safely: {$input}"
        );
    } catch (Throwable $error) {
        phase3b_fail($failures, "Safe stored path was rejected: {$input}");
    }
}

$rejectedPaths = [
    '../config/runtime.php',
    'uploads/../config/runtime.php',
    'uploads/payments/../../index.php',
    '',
];

foreach ($rejectedPaths as $input) {
    $rejected = false;
    try {
        drms_storage_clean_relative_path($input);
    } catch (Throwable $error) {
        $rejected = true;
    }
    phase3b_assert(
        $rejected,
        $passes,
        $failures,
        'Traversal or empty path is rejected: ' . ($input === '' ? '[empty]' : $input)
    );
}

if ($storageRoot !== '') {
    $fixturePath = tempnam($storageRoot, 'phase3b_');
    if ($fixturePath === false) {
        phase3b_fail($failures, 'Temporary storage resolver fixture could not be created.');
    } else {
        try {
            file_put_contents($fixturePath, 'Phase 3B storage resolver fixture');
            $storedFixturePath = 'uploads/' . basename($fixturePath);
            $resolvedFixture = drms_storage_resolve_existing_file($storedFixturePath);
            phase3b_assert(
                realpath($fixturePath) === $resolvedFixture,
                $passes,
                $failures,
                'Authenticated file resolver accepts a valid file inside protected storage.'
            );
        } catch (Throwable $error) {
            phase3b_fail($failures, 'Protected file resolver failed: ' . $error->getMessage());
        } finally {
            if (is_file($fixturePath)) {
                @unlink($fixturePath);
            }
        }
    }
}

$outsidePathRejected = false;
try {
    drms_storage_resolve_existing_file('../config/runtime.php');
} catch (Throwable $error) {
    $outsidePathRejected = true;
}
phase3b_assert(
    $outsidePathRejected,
    $passes,
    $failures,
    'Resolver cannot open a file outside the uploads directory.'
);

$htaccess = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . '.htaccess');
phase3b_assert(
    stripos($htaccess, 'Options -Indexes') !== false,
    $passes,
    $failures,
    'Directory listing is disabled in uploads/.htaccess.'
);
phase3b_assert(
    stripos($htaccess, 'Require all denied') !== false
        && stripos($htaccess, 'Deny from all') !== false,
    $passes,
    $failures,
    'Both Apache 2.4 and legacy direct-access deny rules are present.'
);
phase3b_assert(
    stripos($htaccess, 'RemoveHandler') !== false,
    $passes,
    $failures,
    'Executable upload handlers are removed as defense in depth.'
);

$downloadSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'download.php');
phase3b_assert(
    strpos($downloadSource, "require_once 'config/storage_security.php';") !== false,
    $passes,
    $failures,
    'Authenticated download endpoint loads the central storage boundary.'
);
phase3b_assert(
    strpos($downloadSource, 'drms_storage_resolve_existing_file($stored_path)') !== false,
    $passes,
    $failures,
    'Authenticated download endpoint uses the traversal-safe resolver.'
);
phase3b_assert(
    strpos($downloadSource, "empty(\$_SESSION['user_id'])") !== false,
    $passes,
    $failures,
    'Authenticated download endpoint still requires a signed-in user.'
);

$uploadPolicySource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'upload_policy.php');
phase3b_assert(
    strpos($uploadPolicySource, "require_once __DIR__ . '/storage_security.php';") !== false,
    $passes,
    $failures,
    'Upload policy loads the central storage guard for every upload handler.'
);

$uploadWriters = [
    'api/upload_file.php',
    'actions/collection_payment_handler.php',
    'actions/delivery_completion_handler.php',
    'actions/document_handler.php',
    'actions/pr_handler.php',
    'actions/quotation_handler.php',
    'actions/po_handler.php',
    'actions/version_handler.php',
    'actions/user_handler.php',
];

foreach ($uploadWriters as $relativeFile) {
    $source = (string) file_get_contents(
        $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile)
    );
    phase3b_assert(
        strpos($source, 'drms_storage_move_uploaded_file(') !== false,
        $passes,
        $failures,
        "Upload writer uses the protected move function: {$relativeFile}"
    );
    phase3b_assert(
        preg_match('/(?<!drms_storage_)move_uploaded_file\s*\(/', $source) !== 1,
        $passes,
        $failures,
        "No raw upload move remains in: {$relativeFile}"
    );
}

$dangerousExtensions = ['php', 'phtml', 'pht', 'phar', 'cgi', 'pl', 'py', 'sh'];
$dangerousFiles = [];
if ($storageRoot !== '') {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storageRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
            continue;
        }
        $extension = strtolower((string) pathinfo($entry->getFilename(), PATHINFO_EXTENSION));
        if (in_array($extension, $dangerousExtensions, true)) {
            $dangerousFiles[] = $entry->getPathname();
        }
    }
}
phase3b_assert(
    $dangerousFiles === [],
    $passes,
    $failures,
    'No executable-script file is stored inside uploads.'
);
if ($dangerousFiles !== []) {
    foreach ($dangerousFiles as $dangerousFile) {
        echo '       Review: ' . $dangerousFile . PHP_EOL;
    }
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'ONLINE PHASE 3B FAILED: ' . count($failures) . ' check(s) need attention.' . PHP_EOL;
    exit(1);
}

echo 'ONLINE PHASE 3B PASSED: ' . count($passes) . ' checks completed successfully.' . PHP_EOL;
exit(0);

