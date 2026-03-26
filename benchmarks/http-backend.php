<?php

$host = getenv('BACKEND_HOST') ?: '127.0.0.1';
$port = (int) (getenv('BACKEND_PORT') ?: 5678);
$workers = (int) (getenv('BACKEND_WORKERS') ?: (function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4));

$server = new Swoole\Http\Server($host, $port, SWOOLE_PROCESS);

$server->set([
    'worker_num' => $workers,
    'max_connection' => 200_000,
    'max_coroutine' => 200_000,
    'enable_coroutine' => true,
    'open_tcp_nodelay' => true,
    'tcp_fastopen' => true,
    'open_cpu_affinity' => true,
    'log_level' => SWOOLE_LOG_ERROR,
]);

$server->on('request', static function (Swoole\Http\Request $request, Swoole\Http\Response $response): void {
    $response->header('Content-Type', 'text/plain');
    $response->end('ok');
});

$server->start();
