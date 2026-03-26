<?php

$envInt = static function (string $key, int $default): int {
    $value = getenv($key);

    return $value === false ? $default : (int) $value;
};

$host = getenv('BACKEND_HOST') ?: '127.0.0.1';
$port = $envInt('BACKEND_PORT', 15432);
$workers = $envInt('BACKEND_WORKERS', function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4);
$reactorNum = $envInt('BACKEND_REACTOR_NUM', function_exists('swoole_cpu_num') ? swoole_cpu_num() * 2 : 4);
$backlog = $envInt('BACKEND_BACKLOG', 65535);

$server = new Swoole\Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);

$server->set([
    'worker_num' => $workers,
    'reactor_num' => $reactorNum,
    'max_connection' => 200_000,
    'max_coroutine' => 200_000,
    'enable_coroutine' => true,
    'open_tcp_nodelay' => true,
    'tcp_fastopen' => true,
    'open_cpu_affinity' => true,
    'enable_reuse_port' => true,
    'backlog' => $backlog,
    'log_level' => SWOOLE_LOG_ERROR,
]);

$server->on('receive', static function (Swoole\Server $server, int $fd, int $reactorId, string $data): void {
    $server->send($fd, $data);
});

$server->start();
