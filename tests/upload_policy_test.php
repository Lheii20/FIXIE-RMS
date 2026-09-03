<?php

declare(strict_types=1);

$projectRoot = getenv('DRMS_PROJECT_ROOT') ?: 'C:\xampp\htdocs\fixie_drms';
$policyPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'upload_policy.php';
$databasePath = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';

if (!is_file($databasePath)) {
    fwrite(STDERR, "Missing side-effect-free database bootstrap: $databasePath\n");
    exit(1);
}

require $databasePath;
require $policyPath;

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fixie_upload_policy_' . bin2hex(random_bytes(6));
if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    fwrite(STDERR, "Unable to create the upload-policy test directory.\n");
    exit(1);
}

$createdFiles = [];
$passed = 0;
$failed = 0;

function test_file(string $directory, array &$createdFiles, string $name, string $contents): string
{
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Unable to create test fixture: ' . $name);
    }
    $createdFiles[] = $path;
    return $path;
}

function test_upload_array(string $path, string $name): array
{
    return [
        'name' => $name,
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($path),
    ];
}

function test_expect_pass(string $label, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo "PASS: $label\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL: $label — " . $error->getMessage() . "\n";
    }
}

function test_expect_rejection(string $label, string $expectedCode, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $failed++;
        echo "FAIL: $label — file was incorrectly accepted\n";
    } catch (DrmsUploadValidationException $error) {
        if ($error->validationCode() !== $expectedCode) {
            $failed++;
            echo "FAIL: $label — expected $expectedCode, received " . $error->validationCode() . "\n";
            return;
        }
        $passed++;
        echo "PASS: $label\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL: $label — unexpected error: " . $error->getMessage() . "\n";
    }
}

try {
    $limitMb = drms_upload_document_limit_mb($conn);
    test_expect_pass('configured document limit is an allowed value', function () use ($limitMb): void {
        if (!in_array($limitMb, drms_upload_allowed_document_limits_mb(), true)) {
            throw new RuntimeException('Unexpected limit: ' . $limitMb . ' MB');
        }
    });

    $pdf = test_file(
        $temporaryDirectory,
        $createdFiles,
        'valid.pdf',
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
    );
    test_expect_pass('valid PDF content', function () use ($conn, $pdf): void {
        $result = drms_upload_validate($conn, test_upload_array($pdf, 'valid.pdf'), 'document', false);
        if ($result['extension'] !== 'pdf' || $result['mime'] !== 'application/pdf') {
            throw new RuntimeException('PDF metadata mismatch.');
        }
    });
    test_expect_pass('valid PDF workflow proof', function () use ($conn, $pdf): void {
        $result = drms_upload_validate($conn, test_upload_array($pdf, 'valid.pdf'), 'proof', false);
        if ($result['max_mb'] !== 10) {
            throw new RuntimeException('Workflow proof limit is not 10 MB.');
        }
    });

    $fakePdf = test_file($temporaryDirectory, $createdFiles, 'fake.pdf', 'This is not a PDF file.');
    test_expect_rejection('fake PDF content', 'InvalidFileContent', function () use ($conn, $fakePdf): void {
        drms_upload_validate($conn, test_upload_array($fakePdf, 'fake.pdf'), 'document', false);
    });

    $pngBytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true
    );
    if ($pngBytes === false) {
        throw new RuntimeException('Unable to decode the PNG fixture.');
    }
    $png = test_file($temporaryDirectory, $createdFiles, 'valid.png', $pngBytes);
    test_expect_pass('valid PNG image content', function () use ($conn, $png): void {
        drms_upload_validate($conn, test_upload_array($png, 'valid.png'), 'document', false);
    });
    test_expect_pass('valid PNG profile photo with fixed 5 MB policy', function () use ($conn, $png): void {
        $result = drms_upload_validate($conn, test_upload_array($png, 'profile.png'), 'profile', false);
        if ($result['max_mb'] !== 5 || $result['policy'] !== 'profile') {
            throw new RuntimeException('Profile upload policy metadata mismatch.');
        }
    });
    test_expect_rejection('image extension mismatch', 'InvalidFileContent', function () use ($conn, $png): void {
        drms_upload_validate($conn, test_upload_array($png, 'renamed.jpg'), 'document', false);
    });
    test_expect_rejection('PDF cannot be used as a profile photo', 'InvalidFileType', function () use ($conn, $pdf): void {
        drms_upload_validate($conn, test_upload_array($pdf, 'profile.pdf'), 'profile', false);
    });

    $docx = $temporaryDirectory . DIRECTORY_SEPARATOR . 'valid.docx';
    $zip = new ZipArchive();
    if ($zip->open($docx, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create DOCX fixture.');
    }
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
    $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
    $zip->close();
    $createdFiles[] = $docx;
    test_expect_pass('valid DOCX container', function () use ($conn, $docx): void {
        drms_upload_validate($conn, test_upload_array($docx, 'valid.docx'), 'document', false);
    });
    test_expect_pass('valid DOCX workflow attachment', function () use ($conn, $docx): void {
        $result = drms_upload_validate($conn, test_upload_array($docx, 'valid.docx'), 'workflow_document', false);
        if ($result['max_mb'] !== 10) {
            throw new RuntimeException('Workflow attachment limit is not 10 MB.');
        }
    });
    test_expect_rejection('DOCX cannot be used as proof', 'InvalidFileType', function () use ($conn, $docx): void {
        drms_upload_validate($conn, test_upload_array($docx, 'renamed.docx'), 'proof', false);
    });

    $xlsx = $temporaryDirectory . DIRECTORY_SEPARATOR . 'valid.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($xlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create XLSX fixture.');
    }
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"></workbook>');
    $zip->close();
    $createdFiles[] = $xlsx;
    test_expect_pass('valid XLSX container', function () use ($conn, $xlsx): void {
        drms_upload_validate($conn, test_upload_array($xlsx, 'valid.xlsx'), 'document', false);
    });

    $invalidDocx = test_file($temporaryDirectory, $createdFiles, 'invalid.docx', "PK\x03\x04not-an-office-file");
    test_expect_rejection('invalid DOCX container', 'InvalidFileContent', function () use ($conn, $invalidDocx): void {
        drms_upload_validate($conn, test_upload_array($invalidDocx, 'invalid.docx'), 'document', false);
    });

    $empty = test_file($temporaryDirectory, $createdFiles, 'empty.pdf', '');
    test_expect_rejection('empty file', 'EmptyFile', function () use ($conn, $empty): void {
        drms_upload_validate($conn, test_upload_array($empty, 'empty.pdf'), 'document', false);
    });

    $oversized = $temporaryDirectory . DIRECTORY_SEPARATOR . 'oversized.pdf';
    $handle = fopen($oversized, 'wb');
    if ($handle === false || !ftruncate($handle, ($limitMb * 1024 * 1024) + 1)) {
        throw new RuntimeException('Unable to create oversized fixture.');
    }
    fclose($handle);
    $createdFiles[] = $oversized;
    test_expect_rejection('configured size limit is enforced', 'FileSizeExceeded', function () use ($conn, $oversized): void {
        drms_upload_validate($conn, test_upload_array($oversized, 'oversized.pdf'), 'document', false);
    });

    $oversizedProof = $temporaryDirectory . DIRECTORY_SEPARATOR . 'oversized-proof.pdf';
    $handle = fopen($oversizedProof, 'wb');
    if ($handle === false || !ftruncate($handle, (10 * 1024 * 1024) + 1)) {
        throw new RuntimeException('Unable to create oversized proof fixture.');
    }
    fclose($handle);
    $createdFiles[] = $oversizedProof;
    test_expect_rejection('fixed 10 MB proof limit is enforced', 'FileSizeExceeded', function () use ($conn, $oversizedProof): void {
        drms_upload_validate($conn, test_upload_array($oversizedProof, 'oversized-proof.pdf'), 'proof', false);
    });

    $oversizedProfile = $temporaryDirectory . DIRECTORY_SEPARATOR . 'oversized-profile.png';
    $handle = fopen($oversizedProfile, 'wb');
    if ($handle === false || !ftruncate($handle, (5 * 1024 * 1024) + 1)) {
        throw new RuntimeException('Unable to create oversized profile fixture.');
    }
    fclose($handle);
    $createdFiles[] = $oversizedProfile;
    test_expect_rejection('fixed 5 MB profile-photo limit is enforced', 'FileSizeExceeded', function () use ($conn, $oversizedProfile): void {
        drms_upload_validate($conn, test_upload_array($oversizedProfile, 'oversized-profile.png'), 'profile', false);
    });

    test_expect_rejection('unsupported executable extension', 'InvalidFileType', function () use ($conn, $pdf): void {
        drms_upload_validate($conn, test_upload_array($pdf, 'payload.exe'), 'document', false);
    });
} finally {
    foreach ($createdFiles as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($temporaryDirectory);
}

echo "\nResult: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);

