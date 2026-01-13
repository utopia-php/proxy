<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Platform\Action;
use Utopia\Proxy\Service\HTTP as HTTPService;
use Utopia\Proxy\Service\SMTP as SMTPService;
use Utopia\Proxy\Service\TCP as TCPService;

class ServiceTest extends TestCase
{
    public function testProtocolServiceTypes(): void
    {
        $this->assertSame('proxy.http', (new HTTPService())->getType());
        $this->assertSame('proxy.tcp', (new TCPService())->getType());
        $this->assertSame('proxy.smtp', (new SMTPService())->getType());
    }

    public function testServiceActionManagement(): void
    {
        $service = new HTTPService();
        $resolve = new class extends Action {};
        $log = new class extends Action {};

        $service->addAction('resolve', $resolve);
        $service->addAction('log', $log);

        $this->assertSame($resolve, $service->getAction('resolve'));
        $this->assertSame($log, $service->getAction('log'));
        $this->assertCount(2, $service->getActions());

        $service->removeAction('resolve');

        $this->assertNull($service->getAction('resolve'));
        $this->assertCount(1, $service->getActions());
    }
}
