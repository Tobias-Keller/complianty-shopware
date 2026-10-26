<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Support;

/**
 * Startet einen echten HTTP-Server, der die Verbindungs-API von Complianty nachstellt.
 *
 * Das Netz bleibt damit Teil des Tests: Der Plugin-Client spricht über seinen echten HTTP-Client
 * mit einem echten Socket. Der Server hält keinen Zustand, er liest das Szenario pro Anfrage aus einer Datei.
 */
final class FakeComplianty
{
    private static ?self $instance = null;

    /**
     * @var resource|null
     */
    private $process;

    private function __construct(
        public readonly string $baseUrl,
        private readonly string $scenarioFile,
        private readonly string $logFile,
    ) {
    }

    public static function start(): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $dir = sys_get_temp_dir() . '/complianty-fake-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $scenario = $dir . '/scenario.json';
        $log = $dir . '/requests.log';
        touch($log);

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException('Kein freier Port: ' . $error);
        }
        $port = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];
        fclose($socket);

        $instance = new self('http://127.0.0.1:' . $port . '/api/v1/connect', $scenario, $log);
        $process = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/FakeApi/router.php'],
            [['pipe', 'r'], ['file', $dir . '/server.out', 'a'], ['file', $dir . '/server.out', 'a']],
            $pipes,
            __DIR__ . '/FakeApi',
            ['COMPLIANTY_FAKE_SCENARIO' => $scenario, 'COMPLIANTY_FAKE_LOG' => $log, 'PATH' => (string) getenv('PATH')],
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Fake-API konnte nicht gestartet werden.');
        }
        $instance->process = $process;
        register_shutdown_function(static fn () => $instance->stop());

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, timeout: 0.2);
            if ($connection !== false) {
                fclose($connection);

                return self::$instance = $instance;
            }
            usleep(50_000);
        }

        $instance->stop();

        throw new \RuntimeException('Fake-API antwortet nicht auf Port ' . $port);
    }

    public static function instance(): self
    {
        return self::$instance ?? throw new \LogicException('FakeComplianty::start() wurde nicht aufgerufen.');
    }

    /**
     * Leert Szenario und Anfrage-Log. Jeder Test startet damit ohne Vorgeschichte.
     */
    public function reset(): void
    {
        file_put_contents($this->scenarioFile, '{}');
        file_put_contents($this->logFile, '');
    }

    /**
     * @param array<string, mixed>|string $body
     */
    public function respond(string $route, int $status, array|string $body = [], string $contentType = 'application/json'): void
    {
        $scenario = json_decode((string) file_get_contents($this->scenarioFile), true, 512, \JSON_THROW_ON_ERROR);
        $scenario[$route] = ['status' => $status, 'body' => $body, 'content_type' => $contentType];
        file_put_contents($this->scenarioFile, json_encode($scenario, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{method: string, route: string, authorization: ?string, query: string}>
     */
    public function requests(): array
    {
        $lines = file($this->logFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $requests = [];
        foreach ($lines as $line) {
            /** @var array{method: string, route: string, authorization: ?string, query: string} $request */
            $request = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $requests[] = $request;
        }

        return $requests;
    }

    /**
     * @return list<string>
     */
    public function routes(): array
    {
        return array_column($this->requests(), 'route');
    }

    private function stop(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }
}
