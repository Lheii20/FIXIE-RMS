<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__);

$phaseVerifiers = [
    '4A — Authentication, session, and access' =>
        'verify_final_readiness_phase_4a.php',
    '4B — Transaction workflow' =>
        'verify_final_readiness_phase_4b.php',
    '4C — Record lifecycle safety' =>
        'verify_final_readiness_phase_4c.php',
    '4D — Record metadata actions' =>
        'verify_final_readiness_phase_4d.php',
    '4E — Folder and keyword actions' =>
        'verify_final_readiness_phase_4e.php',
    '4F — Document and version integrity' =>
        'verify_final_readiness_phase_4f.php',
    '4G — Workflow evidence integrity' =>
        'verify_final_readiness_phase_4g.php',
    '4H — Protected file access audit' =>
        'verify_final_readiness_phase_4h.php',
];

function phase4iRunVerifier(
    string $phpBinary,
    string $scriptPath,
    string $projectRoot
): array {
    if (!function_exists('proc_open')) {
        return [
            'exit_code' => 1,
            'output' => 'PHP proc_open is unavailable. Run the Phase 4 verifiers individually.',
        ];
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        [$phpBinary, $scriptPath, $projectRoot],
        $descriptorSpec,
        $pipes,
        $projectRoot,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return [
            'exit_code' => 1,
            'output' => 'The verifier process could not be started.',
        ];
    }

    fclose($pipes[0]);
    $standardOutput = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'output' => trim(
            (string) $standardOutput
            . ($errorOutput !== '' ? "\n" . $errorOutput : '')
        ),
    ];
}

$failures = [];
$passes = [];
$notes = [];
$scriptsDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'scripts';

foreach ($phaseVerifiers as $label => $fileName) {
    $scriptPath = $scriptsDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($scriptPath)) {
        $failures[] = $label . ': missing scripts/' . $fileName;
        continue;
    }

    $result = phase4iRunVerifier(PHP_BINARY, $scriptPath, $projectRoot);
    if ((int) $result['exit_code'] !== 0) {
        $failures[] = $label . " failed:\n" . $result['output'];
        continue;
    }

    if (strpos((string) $result['output'], 'verification PASSED') === false) {
        $failures[] = $label
            . ': verifier returned success without its expected PASS marker.';
        continue;
    }

    $passes[] = $label;
    foreach (preg_split('/\R/', (string) $result['output']) ?: [] as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '- Note:')) {
            $notes[] = substr($trimmed, 2);
        }
    }
}

if ($failures) {
    fwrite(STDERR, "Phase 4I cumulative verification FAILED:\n\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    fwrite(
        STDERR,
        "\nNo verifier intentionally changes workflow records. Resolve the listed phase before proceeding.\n"
    );
    exit(1);
}

echo "Phase 4I cumulative verification PASSED.\n\n";
foreach ($passes as $pass) {
    echo '- PASSED: ' . $pass . "\n";
}
foreach (array_values(array_unique($notes)) as $note) {
    echo '- ' . $note . "\n";
}
echo "\nAll Phase 4A-4H verifiers completed successfully.\n";
echo "This cumulative runner and its child verifiers are read-only.\n";
