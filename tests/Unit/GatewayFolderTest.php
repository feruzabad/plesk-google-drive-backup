<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class GatewayFolderTest extends TestCase
{
    public function testFindFolderPicksTheOldestMatch(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(FakeDrive::fileList([FakeDrive::folder('old-1'), FakeDrive::folder('new-2')]));

        $found = $drive->gateway->findFolder('server', 'base-1');

        $this->assertSame('old-1', $found['id'] ?? null);
        $this->assertStringContainsString('orderBy=createdTime', $drive->query(0));
    }

    public function testCreateFolderKeepsTheOldestDuplicate(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(
            FakeDrive::json(FakeDrive::folder('new-2', 'server', ['base-1'])),
            FakeDrive::fileList([FakeDrive::folder('old-1', 'server'), FakeDrive::folder('new-2', 'server')]),
            new Response(204),
        );

        $created = $drive->gateway->createFolder('server', 'base-1');

        $this->assertSame('old-1', $created['id']);
        $this->assertStringContainsString("name = 'server'", $drive->query(1));
        $this->assertStringContainsString("'base-1' in parents", $drive->query(1));
        $this->assertStringContainsString('orderBy=createdTime', $drive->query(1));
        $this->assertSame('DELETE', $drive->request(2)->getMethod());
        $this->assertStringEndsWith('/files/new-2', $drive->request(2)->getUri()->getPath());
    }

    public function testCreateFolderReturnsTheNewFolderWhenItIsTheOnlyOne(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(
            FakeDrive::json(FakeDrive::folder('new-1', 'server', ['base-1'])),
            FakeDrive::fileList([FakeDrive::folder('new-1', 'server')]),
        );

        $created = $drive->gateway->createFolder('server', 'base-1');

        $this->assertSame('new-1', $created['id']);
        $this->assertSame(2, $drive->requestCount());
    }
}
