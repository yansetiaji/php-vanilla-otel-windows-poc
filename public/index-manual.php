<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;

header('Content-Type: application/json');

/**
 * Runs $fn inside a new span: activates it, records exceptions, always ends it.
 */
function traced(TracerInterface $tracer, string $name, int $kind, array $attributes, callable $fn): mixed
{
    $span = $tracer->spanBuilder($name)
        ->setSpanKind($kind)
        ->setAttributes($attributes)
        ->startSpan();
    $scope = $span->activate();

    try {
        return $fn($span);
    } catch (\Throwable $e) {
        $span->recordException($e);
        $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
        throw $e;
    } finally {
        $scope->detach();
        $span->end();
    }
}

$tracer = Globals::tracerProvider()->getTracer('pure-php-api', '1.0.0');

$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Continue the caller's trace if a traceparent header was sent.
$incomingHeaders = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
$parentContext = TraceContextPropagator::getInstance()
    ->extract(array_change_key_case($incomingHeaders, CASE_LOWER));

// Root SERVER span for the whole request.
$root = $tracer->spanBuilder($method)
    ->setParent($parentContext)
    ->setSpanKind(SpanKind::KIND_SERVER)
    ->setAttribute('http.request.method', $method)
    ->setAttribute('url.path', $path)
    ->startSpan();
$rootScope = $root->activate();

$status  = 200;
$payload = [];

try {
    if ($method === 'GET' && $path === '/items') {
        $root->updateName('GET /items');
        $root->setAttribute('http.route', '/items');

        $pdo = new PDO('sqlite::memory:');

        traced($tracer, 'CREATE TABLE items', SpanKind::KIND_CLIENT, [
            'db.system.name' => 'sqlite',
            'db.query.text'  => 'CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)',
        ], fn () => $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)'));

        traced($tracer, 'INSERT items', SpanKind::KIND_CLIENT, [
            'db.system.name' => 'sqlite',
            'db.query.text'  => "INSERT INTO items (name) VALUES ('Apple'), ('Banana')",
        ], fn () => $pdo->exec("INSERT INTO items (name) VALUES ('Apple'), ('Banana')"));

        $payload = traced($tracer, 'SELECT items', SpanKind::KIND_CLIENT, [
            'db.system.name' => 'sqlite',
            'db.query.text'  => 'SELECT * FROM items',
        ], function (SpanInterface $span) use ($pdo) {
            $rows = $pdo->query('SELECT * FROM items')->fetchAll(PDO::FETCH_ASSOC);
            $span->setAttribute('db.response.returned_rows', count($rows));
            return $rows;
        });
    } elseif ($method === 'GET' && $path === '/external') {
        $root->updateName('GET /external');
        $root->setAttribute('http.route', '/external');

        $payload = traced($tracer, 'GET', SpanKind::KIND_CLIENT, [
            'http.request.method' => 'GET',
            'url.full'            => 'https://example.com',
            'server.address'      => 'example.com',
        ], function (SpanInterface $span) {
            // Propagate trace context to the upstream service.
            $carrier = [];
            TraceContextPropagator::getInstance()->inject($carrier);
            $headerLines = [];
            foreach ($carrier as $name => $value) {
                $headerLines[] = "$name: $value";
            }

            $ch = curl_init('https://example.com');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
            $body = curl_exec($ch);

            if ($body === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException('curl failed: ' . $error);
            }

            $upstreamStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $span->setAttribute('http.response.status_code', $upstreamStatus);
            if ($upstreamStatus >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return ['upstream_status' => $upstreamStatus, 'bytes' => strlen($body)];
        });
    } else {
        $status  = 404;
        $payload = ['error' => 'not found'];
    }
} catch (\Throwable $e) {
    $root->recordException($e);
    $root->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
    $status  = 500;
    $payload = ['error' => 'internal error'];
}

$root->setAttribute('http.response.status_code', $status);
if ($status >= 500) {
    $root->setStatus(StatusCode::STATUS_ERROR);
}
$rootScope->detach();
$root->end();

http_response_code($status);
echo json_encode($payload);