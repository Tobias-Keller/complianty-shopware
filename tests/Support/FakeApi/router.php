<?php declare(strict_types=1);

/**
 * Router für den Fake der Complianty-Verbindungs-API (php -S).
 *
 * Antworten kommen aus einer Szenario-Datei, die der Test schreibt: { "/status": {"status": 200, "body": {...}} }.
 * Jede Anfrage wird in der Log-Datei vermerkt. Nicht konfigurierte Pfade antworten 404, damit ein Test
 * nie versehentlich eine Antwort bekommt, die er nicht bestellt hat.
 */
$scenarioFile = (string) getenv('COMPLIANTY_FAKE_SCENARIO');
$logFile = (string) getenv('COMPLIANTY_FAKE_LOG');

$prefix = '/api/v1/connect';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
$route = str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : $path;

file_put_contents($logFile, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'route' => $route,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'query' => $_SERVER['QUERY_STRING'] ?? '',
], \JSON_THROW_ON_ERROR) . "\n", \FILE_APPEND | \LOCK_EX);

$scenario = is_file($scenarioFile) ? json_decode((string) file_get_contents($scenarioFile), true) : [];
$entry = \is_array($scenario) ? ($scenario[$route] ?? null) : null;

if (!\is_array($entry)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"detail":"not_configured"}';

    return;
}

http_response_code((int) ($entry['status'] ?? 200));
header('Content-Type: ' . ($entry['content_type'] ?? 'application/json'));
foreach ($entry['headers'] ?? [] as $name => $value) {
    header($name . ': ' . $value);
}
echo \is_string($entry['body'] ?? null) ? $entry['body'] : json_encode($entry['body'] ?? new stdClass(), \JSON_THROW_ON_ERROR);
