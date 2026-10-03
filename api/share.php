<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {

    /*
    |--------------------------------------------------------------------------
    | POST
    |--------------------------------------------------------------------------
    | Create share link
    |
    | Body:
    | {
    |     "file_id": 12
    | }
    */

    if ($method === 'POST') {

        $raw = file_get_contents('php://input');

        $data = json_decode(
            $raw ?: '{}',
            true
        );

        $fileId = filter_var(
            $data['file_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$fileId) {
            http_response_code(400);

            echo json_encode([
                'success' => false,
                'message' => 'Invalid file ID.',
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Check file
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare(
            'SELECT id, original_name, status
             FROM files
             WHERE id = :id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $fileId,
        ]);

        $file = $stmt->fetch();

        if (!$file) {
            http_response_code(404);

            echo json_encode([
                'success' => false,
                'message' => 'File not found.',
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($file['status'] !== 'completed') {
            http_response_code(409);

            echo json_encode([
                'success' => false,
                'message' => 'File is not ready for sharing.',
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Generate secure token
        |--------------------------------------------------------------------------
        */

        $token = bin2hex(random_bytes(32));

        $insert = $pdo->prepare(
            'INSERT INTO share_links
                (file_id, token, expires_at)
             VALUES
                (:file_id, :token, NULL)'
        );

        $insert->execute([
            'file_id' => $fileId,
            'token' => $token,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Build share URL
        |--------------------------------------------------------------------------
        */

        $scriptDirectory = rtrim(
            str_replace(
                '\\',
                '/',
                dirname($_SERVER['SCRIPT_NAME'] ?? '/api')
            ),
            '/'
        );

        $basePath = dirname($scriptDirectory);

        if ($basePath === '/' || $basePath === '\\') {
            $basePath = '';
        }

        $scheme = (
            (!empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off')
        )
            ? 'https'
            : 'http';

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $shareUrl =
            $scheme
            . '://'
            . $host
            . $basePath
            . '/api/download.php?token='
            . urlencode($token);

        echo json_encode([
            'success' => true,
            'token' => $token,
            'url' => $shareUrl,
            'file' => [
                'id' => (int) $file['id'],
                'name' => $file['original_name'],
            ],
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | GET
    |--------------------------------------------------------------------------
    | Read share information
    |--------------------------------------------------------------------------
    */

    if ($method === 'GET') {

        $token = trim(
            (string) ($_GET['token'] ?? '')
        );

        if ($token === '') {
            http_response_code(400);

            echo json_encode([
                'success' => false,
                'message' => 'Share token is required.',
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT
                share_links.id,
                share_links.token,
                share_links.expires_at,
                files.id AS file_id,
                files.original_name,
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

        $share = $stmt->fetch();

        if (!$share) {
            http_response_code(404);

            echo json_encode([
                'success' => false,
                'message' => 'Share link not found.',
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if (
            !empty($share['expires_at'])
            && strtotime($share['expires_at']) < time()
        ) {
            http_response_code(410);

            echo json_encode([
                'success' => false,
                'message' => 'Share link has expired.',
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        echo json_encode([
            'success' => true,
            'share' => [
                'id' => (int) $share['id'],
                'token' => $share['token'],
                'file_id' => (int) $share['file_id'],
                'name' => $share['original_name'],
                'mime_type' => $share['mime_type'],
                'size' => (int) $share['size'],
                'status' => $share['status'],
                'expires_at' => $share['expires_at'],
            ],
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Could not process share request.',
    ], JSON_UNESCAPED_UNICODE);
}