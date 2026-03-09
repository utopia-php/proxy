<?php

namespace Utopia\Proxy\Server\SMTP;

use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Server;
use Utopia\Proxy\Adapter\SMTP\Swoole as SMTPAdapter;
use Utopia\Proxy\Resolver;

/**
 * High-performance SMTP proxy server
 *
 * Example:
 * ```php
 * $resolver = new MyEmailResolver();
 * $server = new Swoole($resolver, host: '0.0.0.0', port: 25);
 * $server->start();
 * ```
 */
class Swoole
{
    protected Server $server;

    protected SMTPAdapter $adapter;

    /** @var array<string, mixed> */
    protected array $config;

    /** @var array<int, array{state: string, domain: ?string, backend: ?Client}> */
    protected array $connections = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Resolver $resolver,
        string $host = '0.0.0.0',
        int $port = 25,
        int $workers = 16,
        array $config = []
    ) {
        $this->config = array_merge([
            'host' => $host,
            'port' => $port,
            'workers' => $workers,
            'max_connections' => 50000,
            'max_coroutine' => 50000,
            'socket_buffer_size' => 2 * 1024 * 1024, // 2MB
            'buffer_output_size' => 2 * 1024 * 1024,
            'enable_coroutine' => true,
            'max_wait_time' => 60,
        ], $config);

        $this->server = new Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
        $this->configure();
    }

    protected function configure(): void
    {
        $this->server->set([
            'worker_num' => $this->config['workers'],
            'max_connection' => $this->config['max_connections'],
            'max_coroutine' => $this->config['max_coroutine'],
            'socket_buffer_size' => $this->config['socket_buffer_size'],
            'buffer_output_size' => $this->config['buffer_output_size'],
            'enable_coroutine' => $this->config['enable_coroutine'],
            'max_wait_time' => $this->config['max_wait_time'],

            // TCP performance tuning
            'open_tcp_nodelay' => true,
            'tcp_fastopen' => true,
            'open_cpu_affinity' => true,

            // SMTP-specific settings
            'open_length_check' => false, // SMTP uses CRLF line endings
            'package_eof' => "\r\n",
            'package_max_length' => 10 * 1024 * 1024, // 10MB max email

            // Enable stats
            'task_enable_coroutine' => true,
        ]);

        $this->server->on('start', [$this, 'onStart']);
        $this->server->on('workerStart', [$this, 'onWorkerStart']);
        $this->server->on('connect', [$this, 'onConnect']);
        $this->server->on('receive', [$this, 'onReceive']);
        $this->server->on('close', [$this, 'onClose']);
    }

    public function onStart(Server $server): void
    {
        /** @var string $host */
        $host = $this->config['host'];
        /** @var int $port */
        $port = $this->config['port'];
        /** @var int $workers */
        $workers = $this->config['workers'];
        /** @var int $maxConnections */
        $maxConnections = $this->config['max_connections'];
        echo "SMTP Proxy Server started at {$host}:{$port}\n";
        echo "Workers: {$workers}\n";
        echo "Max connections: {$maxConnections}\n";
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->adapter = new SMTPAdapter($this->resolver);

        // Apply skip_validation config if set
        if (! empty($this->config['skip_validation'])) {
            $this->adapter->setSkipValidation(true);
        }

        echo "Worker #{$workerId} started (Adapter: {$this->adapter->getName()})\n";
    }

    /**
     * Handle new SMTP connection - send greeting
     */
    public function onConnect(Server $server, int $fd, int $reactorId): void
    {
        echo "Client #{$fd} connected\n";

        // Send SMTP greeting
        $server->send($fd, "220 appwrite.io ESMTP Proxy\r\n");

        // Initialize connection state
        $this->connections[$fd] = [
            'state' => 'greeting',
            'domain' => null,
            'backend' => null,
        ];
    }

    /**
     * Main SMTP command handler
     *
     * Performance: <1ms per command
     */
    public function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        try {
            if (! isset($this->connections[$fd])) {
                $this->connections[$fd] = [
                    'state' => 'greeting',
                    'domain' => null,
                    'backend' => null,
                ];
            }

            $conn = &$this->connections[$fd];

            // Parse SMTP command
            $command = strtoupper(substr(trim($data), 0, 4));

            switch ($command) {
                case 'EHLO':
                case 'HELO':
                    $this->handleHelo($server, $fd, $data, $conn);
                    break;

                case 'MAIL':
                case 'RCPT':
                case 'DATA':
                case 'RSET':
                case 'NOOP':
                case 'QUIT':
                    $this->forwardToBackend($server, $fd, $data, $conn);
                    break;

                default:
                    $server->send($fd, "500 Unknown command\r\n");
            }

        } catch (\Exception $e) {
            echo "Error handling SMTP from #{$fd}: {$e->getMessage()}\n";
            $server->send($fd, "421 Service not available\r\n");
            $server->close($fd);
        }
    }

    /**
     * Handle EHLO/HELO - extract domain and route to backend
     *
     * @param  array{state: string, domain: ?string, backend: ?Client}  $conn
     */
    protected function handleHelo(Server $server, int $fd, string $data, array &$conn): void
    {
        // Extract domain from EHLO/HELO command
        if (preg_match('/^(EHLO|HELO)\s+([^\s]+)/i', $data, $matches)) {
            $domain = $matches[2];
            $conn['domain'] = $domain;

            // Route to backend using adapter
            $result = $this->adapter->route($domain);

            // Connect to backend SMTP server
            $backendClient = $this->connectToBackend($result->endpoint, 25);
            $conn['backend'] = $backendClient;

            // Forward EHLO to backend and relay response
            $this->forwardToBackend($server, $fd, $data, $conn);

        } else {
            $server->send($fd, "501 Syntax error\r\n");
        }
    }

    /**
     * Forward command to backend SMTP server
     *
     * @param  array{state: string, domain: ?string, backend: ?Client}  $conn
     */
    protected function forwardToBackend(Server $server, int $fd, string $data, array &$conn): void
    {
        if (! isset($conn['backend'])) {
            throw new \Exception('No backend connection');
        }

        $backendClient = $conn['backend'];

        // Send to backend
        $backendClient->send($data);

        // Relay response back to client (in coroutine)
        Coroutine::create(function () use ($server, $fd, $backendClient) {
            $response = $backendClient->recv(8192);

            if ($response !== false && $response !== '') {
                $server->send($fd, $response);
            }
        });
    }

    /**
     * Connect to backend SMTP server
     */
    protected function connectToBackend(string $endpoint, int $port): Client
    {
        [$host, $port] = explode(':', $endpoint.':'.$port);
        $port = (int) $port;

        $client = new Client(SWOOLE_SOCK_TCP);

        if (! $client->connect($host, $port, 30)) {
            throw new \Exception("Failed to connect to backend SMTP: {$host}:{$port}");
        }

        $client->set([
            'timeout' => 5,
        ]);

        // Read backend greeting
        $client->recv(8192);

        return $client;
    }

    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        echo "Client #{$fd} disconnected\n";

        // Close backend connection if exists
        if (isset($this->connections[$fd]['backend'])) {
            $this->connections[$fd]['backend']->close();
        }

        unset($this->connections[$fd]);
    }

    public function start(): void
    {
        $this->server->start();
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        /** @var array<string, mixed> $serverStats */
        $serverStats = $this->server->stats();
        /** @var array<string, mixed> $coroutineStats */
        $coroutineStats = Coroutine::stats();

        return [
            'connections' => $serverStats['connection_num'] ?? 0,
            'workers' => $serverStats['worker_num'] ?? 0,
            'coroutines' => $coroutineStats['coroutine_num'] ?? 0,
            'adapter' => $this->adapter->getStats(),
        ];
    }
}
