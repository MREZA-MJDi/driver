<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

const MAX_FILE_SIZE = 10 * 1024 * 1024 * 1024; // 10 GB
const MAX_CHUNK_SIZE = 16 * 1024 * 1024;       // 16 MB
const MIN_CHUNK_SIZE = 1 * 1024 * 1024;        // 1 MB
const UPLOAD_CHUNK_SIZE = 8 * 1024 * 1024;      // 8 MB
const STORAGE_LIMIT = 40 * 1024 * 1024 * 1024; // 40 GB

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$storageRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
$filesRoot = $storageRoot . DIRECTORY_SEPARATOR . 'files';
$tempRoot = $storageRoot . DIRECTORY_SEPARATOR . 'temp';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create storage directory.');
    }
}

function requestJson(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function safeOriginalName(string $name): string
{
    $name = trim(str_replace(["\\0", "\\", "/"], '', $name));

    if ($name === '') {
        return 'file';
    }

    return mb_substr($name, 0, 255);
}

function uploadRow(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT id, original_name, mime_type, size, path, status
         FROM files
         WHERE id = :id
         LIMIT 1'
    );

    $stmt->execute(['id' => $id]);

    $file = $stmt->fetch();

    if (!$file) {
        respond(['success' => false, 'message' => 'Upload not found.'], 404);
    }

    return $file;
}

try {
    ensureDirectory($filesRoot);
    ensureDirectory($tempRoot);

    /*
     * GET /api/files.php
     * GET /api/files.php?action=upload&id=123
     */
    if ($method === 'GET') {
        $action = trim((string) ($_GET['action'] ?? ''));

        if ($action === 'upload') {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

            if (!$id) {
                respond(['success' => false, 'message' => 'Invalid upload ID.'], 400);
            }

            $file = uploadRow($pdo, (int) $id);
            $chunkDir = $tempRoot . DIRECTORY_SEPARATOR . (int) $id;

            $received = [];

            if (is_dir($chunkDir)) {
                foreach (glob($chunkDir . DIRECTORY_SEPARATOR . '*.part') ?: [] as $chunkPath) {
                    $index = (int) pathinfo($chunkPath, PATHINFO_FILENAME);
                    $received[] = $index;
                }

                sort($received, SORT_NUMERIC);
            }

            respond([
                'success' => true,
                'upload' => [
                    'id' => (int) $file['id'],
                    'name' => $file['original_name'],
                    'size' => (int) $file['size'],
                    'status' => $file['status'],
                    'received_chunks' => $received,
                ],
            ]);
        }

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

        $storageStmt = $pdo->query(
            'SELECT COALESCE(SUM(size), 0) AS used
             FROM files
             WHERE status = "completed"'
        );

        $used = (int) $storageStmt->fetchColumn();

        respond([
            'success' => true,
            'files' => $files,
            'storage' => [
                'used' => $used,
                'limit' => STORAGE_LIMIT,
            ],
        ]);
    }

    /*
     * POST /api/files.php
     * action=init     -> create resumable upload
     * action=chunk    -> upload one chunk
     * action=complete -> assemble chunks
     */
    if ($method === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'init') {
            $name = safeOriginalName((string) ($_POST['name'] ?? ''));
            $size = filter_var($_POST['size'] ?? null, FILTER_VALIDATE_INT);

            if ($size === false || $size < 1 || $size > MAX_FILE_SIZE) {
                respond([
                    'success' => false,
                    'message' => 'Invalid file size or file is too large.',
                ], 422);
            }

            $usageStmt = $pdo->query(
                'SELECT COALESCE(SUM(size), 0)
                 FROM files
                 WHERE status IN ("completed", "uploading")'
            );

            $used = (int) $usageStmt->fetchColumn();

            if ($used + $size > STORAGE_LIMIT) {
                respond([
                    'success' => false,
                    'message' => 'Storage limit exceeded.',
                ], 413);
            }

            $mime = trim((string) ($_POST['mime_type'] ?? 'application/octet-stream'));
            $uploadId = bin2hex(random_bytes(16));
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $storedName = $uploadId . ($extension !== '' ? '.' . preg_replace('/[^a-zA-Z0-9]+/', '', $extension) : '');

            $stmt = $pdo->prepare(
                'INSERT INTO files
                    (name, original_name, path, mime_type, size, disk, status)
                 VALUES
                    (:name, :original_name, :path, :mime_type, :size, :disk, "uploading")'
            );

            $stmt->execute([
                'name' => $storedName,
                'original_name' => $name,
                'path' => $storedName,
                'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
                'size' => $size,
                'disk' => 'local',
            ]);

            $id = (int) $pdo->lastInsertId();
            $chunkDir = $tempRoot . DIRECTORY_SEPARATOR . $id;

            ensureDirectory($chunkDir);

            respond([
                'success' => true,
                'upload' => [
                    'id' => $id,
                    'name' => $name,
                    'size' => $size,
                    'chunk_size' => UPLOAD_CHUNK_SIZE,
                    'status' => 'uploading',
                ],
            ], 201);
        }

        if ($action === 'chunk') {
            $id = filter_var($_POST['upload_id'] ?? null, FILTER_VALIDATE_INT);
            $index = filter_var($_POST['chunk_index'] ?? null, FILTER_VALIDATE_INT);

            if (!$id || $index === false || $index < 0) {
                respond(['success' => false, 'message' => 'Invalid chunk request.'], 400);
            }

            $file = uploadRow($pdo, (int) $id);

            if ($file['status'] !== 'uploading') {
                respond(['success' => false, 'message' => 'Upload is not accepting chunks.'], 409);
            }

            if (
                !isset($_FILES['chunk'])
                || !is_array($_FILES['chunk'])
                || ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            ) {
                respond(['success' => false, 'message' => 'Chunk upload failed.'], 422);
            }

            $chunk = $_FILES['chunk'];

            if (!is_uploaded_file($chunk['tmp_name'])) {
                respond(['success' => false, 'message' => 'Invalid uploaded chunk.'], 400);
            }

            $chunkSize = (int) $chunk['size'];

            if ($chunkSize < 1 || $chunkSize > MAX_CHUNK_SIZE) {
                respond(['success' => false, 'message' => 'Invalid chunk size.'], 422);
            }

            $chunkDir = $tempRoot . DIRECTORY_SEPARATOR . (int) $id;
            ensureDirectory($chunkDir);

            $target = $chunkDir . DIRECTORY_SEPARATOR . (int) $index . '.part';

            if (!move_uploaded_file($chunk['tmp_name'], $target)) {
                respond(['success' => false, 'message' => 'Could not store chunk.'], 500);
            }

            respond([
                'success' => true,
                'chunk_index' => (int) $index,
                'size' => $chunkSize,
            ]);
        }

        if ($action === 'complete') {
            $id = filter_var($_POST['upload_id'] ?? null, FILTER_VALIDATE_INT);
            $chunkSize = filter_var($_POST['chunk_size'] ?? null, FILTER_VALIDATE_INT);
            $totalChunks = filter_var($_POST['total_chunks'] ?? null, FILTER_VALIDATE_INT);

            if (
                !$id
                || $chunkSize === false
                || $chunkSize !== UPLOAD_CHUNK_SIZE
                || $chunkSize < MIN_CHUNK_SIZE
                || $chunkSize > MAX_CHUNK_SIZE
                || $totalChunks === false
                || $totalChunks < 1
            ) {
                respond(['success' => false, 'message' => 'Invalid completion request.'], 400);
            }

            $file = uploadRow($pdo, (int) $id);

            if ($file['status'] !== 'uploading') {
                respond(['success' => false, 'message' => 'Upload is already completed.'], 409);
            }

            $expectedChunks = (int) ceil(((int) $file['size']) / $chunkSize);

            if ($totalChunks !== $expectedChunks) {
                respond(['success' => false, 'message' => 'Chunk count does not match the file size.'], 422);
            }

            $chunkDir = $tempRoot . DIRECTORY_SEPARATOR . (int) $id;
            $finalPath = $filesRoot . DIRECTORY_SEPARATOR . basename($file['path']);

            $output = fopen($finalPath . '.part', 'wb');

            if ($output === false) {
                respond(['success' => false, 'message' => 'Could not create final file.'], 500);
            }

            $written = 0;

            try {
                for ($index = 0; $index < $totalChunks; $index++) {
                    $chunkPath = $chunkDir . DIRECTORY_SEPARATOR . $index . '.part';

                    if (!is_file($chunkPath)) {
                        throw new RuntimeException('Missing chunk ' . $index . '.');
                    }

                    $input = fopen($chunkPath, 'rb');

                    if ($input === false) {
                        throw new RuntimeException('Could not read chunk ' . $index . '.');
                    }

                    while (!feof($input)) {
                        $buffer = fread($input, 1024 * 1024);

                        if ($buffer === false) {
                            fclose($input);
                            throw new RuntimeException('Could not read upload chunk.');
                        }

                        if ($buffer === '') {
                            continue;
                        }

                        $length = strlen($buffer);
                        $offset = 0;

                        while ($offset < $length) {
                            $result = fwrite($output, substr($buffer, $offset));

                            if ($result === false) {
                                fclose($input);
                                throw new RuntimeException('Could not assemble final file.');
                            }

                            $offset += $result;
                            $written += $result;
                        }
                    }

                    fclose($input);
                }

                fclose($output);

                if ($written !== (int) $file['size']) {
                    @unlink($finalPath . '.part');
                    throw new RuntimeException('Uploaded size does not match the expected size.');
                }

                if (!rename($finalPath . '.part', $finalPath)) {
                    @unlink($finalPath . '.part');
                    throw new RuntimeException('Could not finalize uploaded file.');
                }

                $mimeType = 'application/octet-stream';

                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);

                    if ($finfo) {
                        $detected = finfo_file($finfo, $finalPath);

                        if (is_string($detected) && $detected !== '') {
                            $mimeType = $detected;
                        }

                        finfo_close($finfo);
                    }
                }

                $update = $pdo->prepare(
                    'UPDATE files
                     SET mime_type = :mime_type,
                         status = "completed",
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );

                $update->execute([
                    'mime_type' => $mimeType,
                    'id' => (int) $id,
                ]);

                foreach (glob($chunkDir . DIRECTORY_SEPARATOR . '*.part') ?: [] as $chunkPath) {
                    @unlink($chunkPath);
                }

                @rmdir($chunkDir);

                respond([
                    'success' => true,
                    'file' => [
                        'id' => (int) $id,
                        'name' => $file['original_name'],
                        'size' => (int) $file['size'],
                        'status' => 'completed',
                    ],
                ]);
            } catch (Throwable $e) {
                if (is_resource($output)) {
                    fclose($output);
                }

                throw $e;
            }
        }

        respond(['success' => false, 'message' => 'Unknown upload action.'], 400);
    }

    /*
     * DELETE /api/files.php?id=123
     */
    if ($method === 'DELETE') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$id) {
            respond(['success' => false, 'message' => 'Invalid file ID.'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT id, path, status
             FROM files
             WHERE id = :id
             LIMIT 1'
        );

        $stmt->execute(['id' => $id]);
        $file = $stmt->fetch();

        if (!$file) {
            respond(['success' => false, 'message' => 'File not found.'], 404);
        }

        $pdo->beginTransaction();

        try {
            $delete = $pdo->prepare('DELETE FROM files WHERE id = :id');
            $delete->execute(['id' => $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        if (!empty($file['path'])) {
            $physicalPath = $filesRoot . DIRECTORY_SEPARATOR . basename($file['path']);

            if (is_file($physicalPath)) {
                @unlink($physicalPath);
            }
        }

        $chunkDir = $tempRoot . DIRECTORY_SEPARATOR . (int) $id;

        if (is_dir($chunkDir)) {
            foreach (glob($chunkDir . DIRECTORY_SEPARATOR . '*.part') ?: [] as $chunkPath) {
                @unlink($chunkPath);
            }

            @rmdir($chunkDir);
        }

        respond([
            'success' => true,
            'message' => 'File deleted.',
        ]);
    }

    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'message' => $e->getMessage(),
    ], 500);
}
