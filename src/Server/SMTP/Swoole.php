<?php

namespace Utopia\Proxy\Server\SMTP;

use Swoole\Constant;
use Swoole\Coroutine\Client;
use Swoole\Server;
use Utopia\Console;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver;

/**
 * High-performance SMTP proxy server
 *
 * Example:
 * ```php
 * $resolver = new MyEmailResolver();
 * $server = new Swoole($resolver, new Config(host: '0.0.0.0', port: 25));
 * $server->start();
 * ```
 */
class Swoole
{
    private const RECV_BUFFER = 8192;

    private const PACKAGE_MAX_LENGTH = 10 * 1024 * 1024;

    private const GREETING = "220 utopia-php.io ESMTP Proxy\r\n";

    private const GREETING_CODE = '220';

    private const DATA_READY_CODE = '354';

    private const ERROR_UNKNOWN_COMMAND = "500 Unknown command\r\n";

    private const ERROR_SYNTAX = "501 Syntax error\r\n";

    private const ERROR_UNAVAILABLE = "421 Service not available\r\n";

    private const DATA_TERMINATOR = '.';

    private const DEFAULT_PORT = 25;

    protected Server $server;

    protected Adapter $adapter;

    protected Config $config;

    /** @var array<int, Connection> */
    protected array $connections = [];

    public function __construct(
        protected Resolver $resolver,
        ?Config $config = null,
    ) {
        $this->config = $config ?? new Config();
        $this->server = new Server(
            $this->config->host,
            $this->config->port,
            SWOOLE_PROCESS,
            SWOOLE_SOCK_TCP,
        );
        $this->configure();
    }

    protected function configure(): void
    {
        $this->server->set([
            'worker_num' => $this->config->workers,
            'max_connection' => $this->config->maxConnections,
            'max_coroutine' => $this->config->maxCoroutine,
            'socket_buffer_size' => $this->config->socketBufferSize,
            'buffer_output_size' => $this->config->bufferOutputSize,
            'enable_coroutine' => $this->config->enableCoroutine,
            'max_wait_time' => $this->config->maxWaitTime,
            'open_tcp_nodelay' => true,
            'tcp_fastopen' => true,
            'open_cpu_affinity' => true,
            'open_length_check' => false,
            'open_eof_check' => true,
            'package_eof' => "\r\n",
            'package_max_length' => self::PACKAGE_MAX_LENGTH,
            'task_enable_coroutine' => true,
        ]);

        $this->server->on(Constant::EVENT_START, $this->onStart(...));
        $this->server->on(Constant::EVENT_WORKER_START, $this->onWorkerStart(...));
        $this->server->on(Constant::EVENT_CONNECT, $this->onConnect(...));
        $this->server->on(Constant::EVENT_RECEIVE, $this->onReceive(...));
        $this->server->on(Constant::EVENT_CLOSE, $this->onClose(...));
    }

    public function onStart(Server $server): void
    {
        Console::success("SMTP Proxy Server started at {$this->config->host}:{$this->config->port}");
        Console::log("Workers: {$this->config->workers}");
        Console::log("Max connections: {$this->config->maxConnections}");
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->adapter = new Adapter(
            $this->resolver,
            protocol: Protocol::SMTP
        );
        $this->adapter->setCacheTTL($this->config->cacheTTL);

        if ($this->config->skipValidation) {
            $this->adapter->setSkipValidation(true);
        }

        Console::log("Worker #{$workerId} started ({$this->adapter->getProtocol()->value})");
    }

    /**
     * Handle new SMTP connection - send greeting
     */
    public function onConnect(Server $server, int $fd, int $reactorId): void
    {
        Console::log("Client #{$fd} connected");

        $server->send($fd, self::GREETING);

        $this->connections[$fd] = new Connection();
    }

    /**
     * Main SMTP command handler
     */
    public function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        try {
            if (!isset($this->connections[$fd])) {
                $this->connections[$fd] = new Connection();
            }

            $connection = $this->connections[$fd];

            if ($connection->isData()) {
                $this->forwardData($server, $fd, $data, $connection);

                return;
            }

            $command = strtoupper(substr(trim($data), 0, 4));

            switch ($command) {
                case 'EHLO':
                case 'HELO':
                    $this->handleHelo($server, $fd, $data, $connection);
                    break;

                case 'MAIL':
                case 'RCPT':
                case 'DATA':
                case 'RSET':
                case 'NOOP':
                case 'QUIT':
                    $this->forwardCommand($server, $fd, $data, $connection);
                    break;

                default:
                    $server->send($fd, self::ERROR_UNKNOWN_COMMAND);
            }

        } catch (\Exception $e) {
            Console::error("Error handling SMTP from #{$fd}: {$e->getMessage()}");
            $server->send($fd, self::ERROR_UNAVAILABLE);
            $server->close($fd);
        }
    }

    /**
     * Handle EHLO/HELO - extract domain and route to backend
     */
    protected function handleHelo(Server $server, int $fd, string $data, Connection $connection): void
    {
        if (preg_match('/^(EHLO|HELO)\s+([^\s]+)/i', $data, $matches)) {
            $domain = $matches[2];
            $connection->domain = $domain;

            $result = $this->adapter->route($domain);

            $connection->backend = $this->connectToBackend($result->endpoint, self::DEFAULT_PORT);

            $this->forwardCommand($server, $fd, $data, $connection);

        } else {
            $server->send($fd, self::ERROR_SYNTAX);
        }
    }

    /**
     * Forward SMTP command to backend and relay response inline.
     *
     * SMTP is a sequential request-response protocol so we recv
     * inline rather than spawning a goroutine.
     */
    protected function forwardCommand(Server $server, int $fd, string $data, Connection $connection): void
    {
        if ($connection->backend === null) {
            throw new \Exception('No backend connection');
        }

        $isDataCommand = strtoupper(substr(trim($data), 0, 4)) === 'DATA'
            && strtoupper(trim($data)) === 'DATA';

        if ($connection->backend->send($data) === false) {
            throw new \Exception('Failed to send command to backend');
        }

        /** @var string|false $response */
        $response = $connection->backend->recv(self::RECV_BUFFER);

        if (\is_string($response) && $response !== '') {
            $server->send($fd, $response);

            if ($isDataCommand && str_starts_with($response, self::DATA_READY_CODE)) {
                $connection->state = 'data';
            }
        }
    }

    /**
     * Forward raw message body data during DATA mode.
     *
     * In DATA mode all lines are forwarded verbatim without command
     * parsing. The mode ends when the terminator line (single dot) is seen.
     */
    protected function forwardData(Server $server, int $fd, string $data, Connection $connection): void
    {
        if ($connection->backend === null) {
            throw new \Exception('No backend connection');
        }

        if ($connection->backend->send($data) === false) {
            throw new \Exception('Failed to send data to backend');
        }

        if (trim($data) === self::DATA_TERMINATOR) {
            $connection->state = 'command';

            $response = $connection->backend->recv(self::RECV_BUFFER);

            if ($response !== false && $response !== '') {
                $server->send($fd, $response);
            }
        }
    }

    protected function connectToBackend(string $endpoint, int $defaultPort): Client
    {
        [$host, $port] = Adapter::parseEndpoint($endpoint, $defaultPort);

        $client = new Client(SWOOLE_SOCK_TCP);

        $client->set([
            'timeout' => $this->config->timeout,
        ]);

        if (!$client->connect($host, $port, $this->config->connectTimeout)) {
            throw new \Exception("Failed to connect to backend SMTP: {$host}:{$port}");
        }

        /** @var string|false $greeting */
        $greeting = $client->recv(self::RECV_BUFFER);

        if (!\is_string($greeting) || $greeting === '' || !str_starts_with(trim($greeting), self::GREETING_CODE)) {
            $client->close();
            throw new \Exception('Backend SMTP greeting failed: '.(\is_string($greeting) ? $greeting : 'no response'));
        }

        return $client;
    }

    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        Console::log("Client #{$fd} disconnected");

        if (isset($this->connections[$fd]) && $this->connections[$fd]->backend !== null) {
            $this->connections[$fd]->backend->close();
        }

        unset($this->connections[$fd]);
    }

    public function start(): void
    {
        $this->server->start();
    }

}
