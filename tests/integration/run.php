<?php

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

function retry(string $label, int $timeoutSeconds, callable $action): void
{
    $start = time();
    $lastError = null;

    while ((time() - $start) < $timeoutSeconds) {
        try {
            $action();
            return;
        } catch (Throwable $e) {
            $lastError = $e;
            usleep(250000);
        }
    }

    $details = $lastError ? $lastError->getMessage() : 'unknown error';
    fail("Timed out waiting for {$label}: {$details}");
}

function httpRequest(string $url, string $hostHeader): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Host: {$hostHeader}\r\n",
            'timeout' => 2,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];

    if ($body === false) {
        $error = error_get_last();
        throw new RuntimeException($error['message'] ?? 'HTTP request failed');
    }

    return [$headers, $body];
}

function tcpExchange(string $host, int $port, string $payload): string
{
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 2);
    if ($socket === false) {
        throw new RuntimeException("TCP connect failed: {$errstr}");
    }

    stream_set_timeout($socket, 2);

    fwrite($socket, $payload);
    $response = fread($socket, 1024) ?: '';

    fclose($socket);

    return $response;
}

function smtpExchange(string $host, int $port, string $domain): array
{
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 2);
    if ($socket === false) {
        throw new RuntimeException("SMTP connect failed: {$errstr}");
    }

    stream_set_timeout($socket, 2);

    $greeting = fgets($socket, 1024) ?: '';

    fwrite($socket, "EHLO {$domain}\r\n");

    $responses = [];
    for ($i = 0; $i < 6; $i++) {
        $line = fgets($socket, 1024);
        if ($line === false) {
            break;
        }
        $responses[] = $line;
        if (str_starts_with($line, '250 ')) {
            break;
        }
    }

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return [$greeting, $responses];
}

$httpUrl = getenv('HTTP_PROXY_URL') ?: 'http://127.0.0.1:18080/';
$httpHost = getenv('HTTP_PROXY_HOST') ?: 'api.example.com';
$httpExpected = getenv('HTTP_EXPECTED_BODY') ?: 'ok';

$tcpHost = getenv('TCP_PROXY_HOST') ?: '127.0.0.1';
$tcpPort = (int)(getenv('TCP_PROXY_PORT') ?: 15432);
$tcpPayload = "user\0appwrite\0database\0db-abc123\0";
$tcpExpectedSnippet = "database\0db-abc123\0";

$smtpHost = getenv('SMTP_PROXY_HOST') ?: '127.0.0.1';
$smtpPort = (int)(getenv('SMTP_PROXY_PORT') ?: 1025);
$smtpDomain = 'example.com';

retry('HTTP proxy', 30, function () use ($httpUrl, $httpHost, $httpExpected) {
    [$headers, $body] = httpRequest($httpUrl, $httpHost);
    assertTrue(!empty($headers), 'Missing HTTP response headers');
    assertTrue(str_contains($headers[0], '200'), 'Unexpected HTTP status: ' . $headers[0]);
    assertTrue(str_contains($body, $httpExpected), 'Unexpected HTTP body');
});

retry('TCP proxy', 30, function () use ($tcpHost, $tcpPort, $tcpPayload, $tcpExpectedSnippet) {
    $response = tcpExchange($tcpHost, $tcpPort, $tcpPayload);
    assertTrue(str_contains($response, $tcpExpectedSnippet), 'TCP echo response missing expected payload');
});

retry('SMTP proxy', 30, function () use ($smtpHost, $smtpPort, $smtpDomain) {
    [$greeting, $responses] = smtpExchange($smtpHost, $smtpPort, $smtpDomain);
    assertTrue(str_starts_with($greeting, '220'), 'SMTP greeting missing 220 response');

    $hasEhlo = false;
    foreach ($responses as $line) {
        if (str_starts_with($line, '250')) {
            $hasEhlo = true;
            break;
        }
    }
    assertTrue($hasEhlo, 'SMTP EHLO response missing 250 response');
});

echo "Integration tests passed.\n";
