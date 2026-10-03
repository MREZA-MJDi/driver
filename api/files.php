<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {

    /*
    |--------------------------------------------------------------------------
    | GET /api/files.php
    |--------------------------------------------------------------------------
    | Return all files
    */

    if ($method === 'GET') {

        $stmt = $pdo->query(
            'SELECT
                id,
                name,
                original_name,
                path,
                mime_type,
                size,
                disk,
                status,
                created_at,
                updated_at
             FROM files
             WHERE status = "completed"
             ORDER BY created_at DESC'
        );

        $files = $stmt->fetchAll();

        foreach ($files as &$file) {
            $file['id'] = (int) $file['id'];
            $file['size'] = (int) $file['size'];
        }

        unset($file);

        echo json_encode([
            'success' => true,
            'files' => $files,
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE /api/files.php?id=123
    |--------------------------------------------------------------------------
    */

    if ($method === 'DELETE') {

        $id = filter_input(
            INPUT_GET,
            'id',
            FILTER_VALIDATE_INT
        );

        if (!$id) {
            http_response_code(400);

            echo json_encode([
                'success' => false,
                'message' => 'Invalid file ID.',
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT id, path
             FROM files
             WHERE id = :id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
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

        $pdo->beginTransaction();

        try {

            $delete = $pdo->prepare(
                'DELETE FROM files
                 WHERE id = :id'
            );

            $delete->execute([
                'id' => $id,
            ]);

            $pdo->commit();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        /*
         * Remove physical file if it exists.
         *
         * For local development this points to:
         * ../storage/files/
         */

        if (!empty($file['path'])) {

            $relativePath = ltrim(
                str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file['path']),
                DIRECTORY_SEPARATOR
            );

            $physicalPath = dirname(__DIR__)
                . DIRECTORY_SEPARATOR
                . 'storage'
                . DIRECTORY_SEPARATOR
                . 'files'
                . DIRECTORY_SEPARATOR
                . $relativePath;

            if (
                is_file($physicalPath)
                && is_writable($physicalPath)
            ) {
                @unlink($physicalPath);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'File deleted.',
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
        'message' => 'Could not process file request.',
    ], JSON_UNESCAPED_UNICODE);
}