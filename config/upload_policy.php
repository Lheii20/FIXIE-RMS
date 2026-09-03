<?php

declare(strict_types=1);

final class DrmsUploadValidationException extends RuntimeException
{
    private string $validationCode;

    public function __construct(string $validationCode, string $message)
    {
        parent::__construct($message);
        $this->validationCode = $validationCode;
    }

    public function validationCode(): string
    {
        return $this->validationCode;
    }
}

function drms_upload_allowed_document_limits_mb(): array
{
    return [2, 5, 10, 25];
}

function drms_upload_document_limit_mb(mysqli $conn): int
{
    static $cache = [];
    $connectionId = spl_object_id($conn);
    if (isset($cache[$connectionId])) {
        return $cache[$connectionId];
    }

    $limit = 5;
    try {
        $statement = $conn->prepare(
            "SELECT setting_value
             FROM system_settings
             WHERE setting_key = 'max_upload_size'
             LIMIT 1"
        );
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();

        $configured = filter_var(
            $row['setting_value'] ?? null,
            FILTER_VALIDATE_INT
        );
        if (
            $configured !== false &&
            in_array((int) $configured, drms_upload_allowed_document_limits_mb(), true)
        ) {
            $limit = (int) $configured;
        }
    } catch (Throwable $error) {
        error_log('Upload policy setting lookup failed: ' . $error->getMessage());
    }

    $cache[$connectionId] = $limit;
    return $limit;
}

function drms_upload_policy_limit_mb(mysqli $conn, string $policy): int
{
    switch ($policy) {
        case 'document':
            return drms_upload_document_limit_mb($conn);
        case 'proof':
            return 10;
        case 'workflow_document':
            return 10;
        case 'profile':
            return 5;
        default:
            throw new InvalidArgumentException('Unknown upload policy: ' . $policy);
    }
}

function drms_upload_policy_extensions(string $policy): array
{
    switch ($policy) {
        case 'document':
            return ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        case 'proof':
            return ['pdf', 'jpg', 'jpeg', 'png'];
        case 'workflow_document':
            return ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        case 'profile':
            return ['jpg', 'jpeg', 'png', 'webp'];
        default:
            throw new InvalidArgumentException('Unknown upload policy: ' . $policy);
    }
}

function drms_upload_policy_type_label(string $policy): string
{
    if ($policy === 'profile') {
        return 'JPG, PNG, or WEBP image';
    }
    if ($policy === 'proof') {
        return 'PDF, JPG, or PNG file';
    }
    return 'PDF, JPG, PNG, Word, or Excel file';
}

function drms_upload_error_message(int $uploadError, int $maxMb): string
{
    switch ($uploadError) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "The file exceeds the allowed $maxMb MB upload limit.";
        case UPLOAD_ERR_PARTIAL:
            return 'The file upload was interrupted. Please select the file and try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'Select a file to upload.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return 'The server could not receive the file. Contact the system administrator.';
        default:
            return 'The file upload could not be completed.';
    }
}

function drms_upload_fail(string $code, string $message): void
{
    throw new DrmsUploadValidationException($code, $message);
}

function drms_upload_read_prefix(string $path, int $length): string
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        drms_upload_fail('UnreadableFile', 'The uploaded file could not be inspected.');
    }
    $prefix = fread($handle, $length);
    fclose($handle);
    if ($prefix === false) {
        drms_upload_fail('UnreadableFile', 'The uploaded file could not be inspected.');
    }
    return $prefix;
}

function drms_upload_validate_image(string $path, string $extension, string $mime): void
{
    $image = @getimagesize($path);
    $expected = in_array($extension, ['jpg', 'jpeg'], true)
        ? 'image/jpeg'
        : ($extension === 'png' ? 'image/png' : 'image/webp');

    if (
        $image === false ||
        ($image['mime'] ?? '') !== $expected ||
        $mime !== $expected ||
        (int) ($image[0] ?? 0) < 1 ||
        (int) ($image[1] ?? 0) < 1
    ) {
        drms_upload_fail(
            'InvalidFileContent',
            'The selected image is invalid or its content does not match its file extension.'
        );
    }
}

function drms_upload_validate_pdf(string $path, string $mime): void
{
    if ($mime !== 'application/pdf' || drms_upload_read_prefix($path, 5) !== '%PDF-') {
        drms_upload_fail(
            'InvalidFileContent',
            'The selected file is not a valid PDF document.'
        );
    }
}

function drms_upload_validate_legacy_office(string $path, string $extension, string $mime): void
{
    $oleSignature = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    $allowedMimes = $extension === 'doc'
        ? ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream']
        : ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'];

    $header = drms_upload_read_prefix($path, 512);
    $byteOrder = substr($header, 28, 2);
    $sectorShift = substr($header, 30, 2);

    if (
        strlen($header) < 512 ||
        substr($header, 0, 8) !== $oleSignature ||
        $byteOrder !== "\xFE\xFF" ||
        !in_array($sectorShift, ["\x09\x00", "\x0C\x00"], true) ||
        !in_array($mime, $allowedMimes, true)
    ) {
        drms_upload_fail(
            'InvalidFileContent',
            'The selected legacy Word or Excel file is invalid or mislabeled.'
        );
    }
}

function drms_upload_validate_openxml(string $path, string $extension, string $mime): void
{
    if (!class_exists('ZipArchive')) {
        drms_upload_fail(
            'ServerValidationUnavailable',
            'The server cannot validate modern Word or Excel files. Contact the system administrator.'
        );
    }

    $allowedMimes = $extension === 'docx'
        ? [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ]
        : [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ];

    if (!in_array($mime, $allowedMimes, true)) {
        drms_upload_fail(
            'InvalidFileContent',
            'The selected Word or Excel file does not match its file extension.'
        );
    }

    $zip = new ZipArchive();
    $opened = $zip->open($path, ZipArchive::RDONLY);
    if ($opened !== true) {
        drms_upload_fail('InvalidFileContent', 'The selected Word or Excel file is damaged.');
    }

    $contentTypes = $zip->locateName('[Content_Types].xml') !== false;
    $mainPart = $extension === 'docx'
        ? $zip->locateName('word/document.xml') !== false
        : $zip->locateName('xl/workbook.xml') !== false;
    $zip->close();

    if (!$contentTypes || !$mainPart) {
        drms_upload_fail(
            'InvalidFileContent',
            'The selected file is not a valid ' . strtoupper($extension) . ' document.'
        );
    }
}

function drms_upload_validate_content(string $path, string $extension, string $mime): void
{
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        drms_upload_validate_image($path, $extension, $mime);
        return;
    }
    if ($extension === 'pdf') {
        drms_upload_validate_pdf($path, $mime);
        return;
    }
    if (in_array($extension, ['doc', 'xls'], true)) {
        drms_upload_validate_legacy_office($path, $extension, $mime);
        return;
    }
    if (in_array($extension, ['docx', 'xlsx'], true)) {
        drms_upload_validate_openxml($path, $extension, $mime);
        return;
    }

    drms_upload_fail('InvalidFileType', 'The selected file type is not supported.');
}

function drms_upload_validate(
    mysqli $conn,
    ?array $file,
    string $policy = 'document',
    bool $requireHttpUpload = true
): array {
    $maxMb = drms_upload_policy_limit_mb($conn, $policy);

    if ($file === null) {
        drms_upload_fail('NoFile', 'Select a file to upload.');
    }

    foreach (['name', 'tmp_name', 'error', 'size'] as $key) {
        if (!array_key_exists($key, $file) || is_array($file[$key])) {
            drms_upload_fail('MalformedUpload', 'The upload request is malformed.');
        }
    }

    $uploadError = (int) $file['error'];
    if ($uploadError !== UPLOAD_ERR_OK) {
        drms_upload_fail('UploadError', drms_upload_error_message($uploadError, $maxMb));
    }

    $temporaryPath = (string) $file['tmp_name'];
    if (
        $temporaryPath === '' ||
        !is_file($temporaryPath) ||
        ($requireHttpUpload && !is_uploaded_file($temporaryPath))
    ) {
        drms_upload_fail('InvalidTemporaryFile', 'The uploaded file could not be verified.');
    }

    $actualSize = filesize($temporaryPath);
    $maxBytes = $maxMb * 1024 * 1024;
    if ($actualSize === false || $actualSize < 1) {
        drms_upload_fail('EmptyFile', 'The selected file is empty.');
    }
    if ($actualSize > $maxBytes) {
        drms_upload_fail('FileSizeExceeded', "The file exceeds the allowed $maxMb MB upload limit.");
    }

    $originalName = basename(str_replace("\0", '', (string) $file['name']));
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, drms_upload_policy_extensions($policy), true)) {
        drms_upload_fail(
            'InvalidFileType',
            'Unsupported file type. Select a ' . drms_upload_policy_type_label($policy) . '.'
        );
    }

    if (!class_exists('finfo')) {
        drms_upload_fail(
            'ServerValidationUnavailable',
            'The server file-validation service is unavailable. Contact the system administrator.'
        );
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    if (!is_string($mime) || $mime === '') {
        drms_upload_fail('UnreadableFile', 'The uploaded file content could not be identified.');
    }

    drms_upload_validate_content($temporaryPath, $extension, $mime);

    return [
        'original_name' => $originalName,
        'tmp_name' => $temporaryPath,
        'extension' => $extension,
        'mime' => $mime,
        'size' => (int) $actualSize,
        'max_mb' => $maxMb,
        'max_bytes' => $maxBytes,
        'policy' => $policy,
    ];
}
