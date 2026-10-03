<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {

    $token = trim(
        (string) ($_GET['token'] ?? '')
    );

    if ($token === '') {
        http_response_code(400);
        exit('Share token is required.');
    }

    $stmt = $pdo->prepare(
        'SELECT
            share_links.expires_at,
            files.original_name,
            files.path,
            files.mime_type,
            files.size,
            files.status
         FROM share_links
         INNER JOIN files
            ON files.id = share_links.file_id
         WHERE share_links.token = :token
         LIMIT 1'
    );

    $stmt->execute([
        'token' => $token,
    ]);

    $file = $stmt->fetch();

    if (!$file) {
        http_response_code(404);
        exit('File not found.');
    }

    /*
    |--------------------------------------------------------------------------
    | Expiration
    |--------------------------------------------------------------------------
    */

    if (
        !empty($file['expires_at'])
        && strtotime($file['expires_at']) < time()
    ) {
        http_response_code(410);
        exit('Share link expired.');
    }

    /*
    |--------------------------------------------------------------------------
    | Upload must be completed
    |--------------------------------------------------------------------------
    */

    if ($file['status'] !== 'completed') {
        http_response_code(409);
        exit('File is not ready.');
    }

    if (empty($file['path'])) {
        http_response_code(404);
        exit('File path is missing.');
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve physical path
    |--------------------------------------------------------------------------
    */

    $relativePath = ltrim(
        str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            $file['path']
        ),
        DIRECTORY_SEPARATOR
    );

    $physicalPath =
        dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'storage'
        . DIRECTORY_SEPARATOR
        . 'files'
        . DIRECTORY_SEPARATOR
        . $relativePath;

    if (!is_file($physicalPath)) {
        http_response_code(404);
        exit('Physical file not found.');
    }

    /*
    |--------------------------------------------------------------------------
    | Download headers
    |--------------------------------------------------------------------------
    */

    $downloadName =
        $file['original_name']
        ?: basename($physicalPath);

    $mimeType =
        $file['mime_type']
        ?: 'application/octet-stream';

    $size = filesize($physicalPath);

    if ($size === false) {
        http_response_code(500);
        exit('Could not determine file size.');
    }

    /*
    |--------------------------------------------------------------------------
    | Range support
    |--------------------------------------------------------------------------
    |
    | Important later for video streaming and large downloads.
    |
    */

    $start = 0;
    $end = $size - 1;

    $rangeHeader =
        $_SERVER['HTTP_RANGE'] ?? '';

    if (
        $rangeHeader
        && preg_match(
            '/bytes=(\d*)-(\d*)/',
            $rangeHeader,
            $matches
        )
    ) {

        if ($matches[1] !== '') {
            $start = (int) $matches[1];
        }

        if ($matches[2] !== '') {
            $end = (int) $matches[2];
        }

        if ($start > $end || $start >= $size) {
            header(
                'Content-Range: bytes */' . $size
            );

            http_response_code(416);
            exit;
        }

        $end = min(
            $end,
            $size - 1
        );

        http_response_code(206);

        header(
            'Content-Range: bytes '
            . $start
            . '-'
            . $end
            . '/'
            . $size
        );

    } else {

        http_response_code(200);
    }

    $length = $end - $start + 1;

    header(
        'Content-Type: ' . $mimeType
    );

    header(
        'Content-Length: ' . $length
    );

    header(
        'Accept-Ranges: bytes'
    );

    $inline = filter_var($_GET['inline'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'download';

    header(
        'Content-Disposition: '
        . ($inline ? 'inline' : 'attachment')
        . '; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName)
    );

    header(
        'Cache-Control: private, no-store'
    );

    /*
    |--------------------------------------------------------------------------
    | Stream file
    |--------------------------------------------------------------------------
    */

    $handle = fopen(
        $physicalPath,
        'rb'
    );

    if ($handle === false) {
        http_response_code(500);
        exit('Could not open file.');
    }

    fseek(
        $handle,
        $start
    );

    $remaining = $length;

    while (
        $remaining > 0
        && !feof($handle)
    ) {

        $chunkSize = min(
            1024 * 1024,
            $remaining
        );

        $buffer = fread(
            $handle,
            $chunkSize
        );

        if ($buffer === false) {
            break;
        }

        echo $buffer;

        $remaining -= strlen($buffer);

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    fclose($handle);

    exit;

} catch (Throwable $e) {

    http_response_code(500);

    exit('Download failed.');
}