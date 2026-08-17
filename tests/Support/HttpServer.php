<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

final class HttpServer
{
    /** @var resource */
    private $process;
    /** @var array<int, resource> */
    private array $pipes;
    private readonly int $port;

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function __construct(int $port, $process, array $pipes)
    {
        $this->port = $port;
        $this->process = $process;
        $this->pipes = $pipes;
    }

    public static function start(): self
    {
        $port = self::findFreePort();
        $docroot = dirname(__DIR__, 2) . '/public';

        $process = proc_open(
            sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docroot)),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start php -S process.');
        }

        stream_set_blocking($pipes[2], false);

        $server = new self($port, $process, $pipes);
        $server->waitUntilReady();

        return $server;
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    /**
     * @param array<string, string> $headers e.g. ['Cookie' => 'PHPSESSID=...']
     */
    public function request(string $method, string $path, array $headers = [], string $body = ''): HttpResponse
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        if ($body !== '') {
            $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 5,
        ]]);

        $result = @file_get_contents("http://127.0.0.1:{$this->port}{$path}", false, $context);

        return HttpResponse::fromRaw($http_response_header ?? [], $result === false ? '' : $result);
    }

    private function waitUntilReady(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(20_000);
        }

        $stderr = stream_get_contents($this->pipes[2]) ?: '';
        $this->stop();
        throw new RuntimeException("php -S did not become ready on port {$this->port}. stderr: {$stderr}");
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Could not find a free port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
