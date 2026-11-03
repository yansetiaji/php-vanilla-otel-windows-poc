<?php
require __DIR__ . '/../vendor/autoload.php'; // needed for the instrumentation packages to load

header('Content-Type: application/json');

$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && $path === '/items') {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec("INSERT INTO items (name) VALUES ('Apple'), ('Banana')");
    $rows = $pdo->query('SELECT * FROM items')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

if ($method === 'GET' && $path === '/external') {
    $ch = curl_init('https://example.com');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo json_encode(['upstream_status' => $status, 'bytes' => strlen($body)]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not found']);