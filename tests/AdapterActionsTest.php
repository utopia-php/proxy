<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Platform\Action;
use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Utopia\Proxy\Adapter\SMTP\Swoole as SMTPAdapter;
use Utopia\Proxy\Adapter\TCP\Swoole as TCPAdapter;
use Utopia\Proxy\Service\HTTP as HTTPService;
use Utopia\Proxy\Service\SMTP as SMTPService;
use Utopia\Proxy\Service\TCP as TCPService;

class AdapterActionsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }
    }

    public function testDefaultServicesAreAssigned(): void
    {
        $http = new HTTPAdapter();
        $tcp = new TCPAdapter(port: 5432);
        $smtp = new SMTPAdapter();

        $this->assertInstanceOf(HTTPService::class, $http->getService());
        $this->assertInstanceOf(TCPService::class, $tcp->getService());
        $this->assertInstanceOf(SMTPService::class, $smtp->getService());
    }

    public function testResolveActionRoutesAndRunsLifecycleActions(): void
    {
        $adapter = new HTTPAdapter();
        $service = new HTTPService();

        $initHost = null;
        $shutdownEndpoint = null;

        $service->addAction('resolve', (new class extends Action {})
            ->callback(function (string $hostname): string {
                return "127.0.0.1:8080";
            }));

        $service->addAction('beforeRoute', (new class extends Action {})
            ->setType(Action::TYPE_INIT)
            ->callback(function (string $hostname) use (&$initHost) {
                $initHost = $hostname;
            }));

        $service->addAction('afterRoute', (new class extends Action {})
            ->setType(Action::TYPE_SHUTDOWN)
            ->callback(function (string $hostname, string $endpoint, $result) use (&$shutdownEndpoint) {
                $shutdownEndpoint = $endpoint;
            }));

        $adapter->setService($service);

        $result = $adapter->route('api.example.com');

        $this->assertSame('127.0.0.1:8080', $result->endpoint);
        $this->assertSame('api.example.com', $initHost);
        $this->assertSame('127.0.0.1:8080', $shutdownEndpoint);
    }

    public function testErrorActionRunsOnRoutingFailure(): void
    {
        $adapter = new HTTPAdapter();
        $service = new HTTPService();

        $errorMessage = null;
        $errorHost = null;

        $service->addAction('resolve', (new class extends Action {})
            ->callback(function (string $hostname): string {
                throw new \Exception("No backend");
            }));

        $service->addAction('onRoutingError', (new class extends Action {})
            ->setType(Action::TYPE_ERROR)
            ->callback(function (string $hostname, \Exception $e) use (&$errorMessage, &$errorHost) {
                $errorHost = $hostname;
                $errorMessage = $e->getMessage();
            }));

        $adapter->setService($service);

        try {
            $adapter->route('api.example.com');
            $this->fail('Expected routing error was not thrown.');
        } catch (\Exception $e) {
            $this->assertSame('No backend', $e->getMessage());
        }

        $this->assertSame('api.example.com', $errorHost);
        $this->assertSame('No backend', $errorMessage);
    }

    public function testMissingResolveActionThrows(): void
    {
        $adapter = new HTTPAdapter();
        $adapter->setService(new HTTPService());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No resolve action registered');

        $adapter->route('api.example.com');
    }

    public function testResolveActionRejectsEmptyEndpoint(): void
    {
        $adapter = new HTTPAdapter();
        $service = new HTTPService();

        $service->addAction('resolve', (new class extends Action {})
            ->callback(function (string $hostname): string {
                return '';
            }));

        $adapter->setService($service);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Resolve action returned empty endpoint');

        $adapter->route('api.example.com');
    }

    public function testInitActionsRunInRegistrationOrder(): void
    {
        $adapter = new HTTPAdapter();
        $service = new HTTPService();

        $calls = [];

        $service->addAction('resolve', (new class extends Action {})
            ->callback(function (string $hostname): string {
                return '127.0.0.1:8080';
            }));

        $service->addAction('first', (new class extends Action {})
            ->setType(Action::TYPE_INIT)
            ->callback(function () use (&$calls) {
                $calls[] = 'first';
            }));

        $service->addAction('second', (new class extends Action {})
            ->setType(Action::TYPE_INIT)
            ->callback(function () use (&$calls) {
                $calls[] = 'second';
            }));

        $adapter->setService($service);
        $adapter->route('api.example.com');

        $this->assertSame(['first', 'second'], $calls);
    }
}
