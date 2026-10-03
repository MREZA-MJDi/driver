<?php

declare(strict_types=1);

$config = [
    'host' => getenv('PERSONAL_DRIVE_DB_HOST') ?: '127.0.0.1',
    'name' => getenv('PERSONAL_DRIVE_DB_NAME') ?: 'personal_drive',
    'user' => getenv('PERSONAL_DRIVE_DB_USER') ?: 'root',
    'pass' => getenv('PERSONAL_DRIVE_DB_PASS') ?: '',
];

$localConfig = __DIR__ . '/.env.php';

if (is_file($localConfig)) {
    $local = require $localConfig;

    if (is_array($local)) {
        $config = array_replace($config, $local);
    }
}

$charset = 'utf8mb4';
$dsn = "mysql:host={$config['host']};dbname={$config['name']};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO(
        $dsn,
        $config['user'],
        $config['pass'],
        $options
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.',
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
