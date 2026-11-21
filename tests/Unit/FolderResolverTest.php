<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class FolderResolverTest extends TestCase
{
    public function testLooksUpTheObjectFolderDirectlyUnderTheBase(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(
            FakeDrive::fileList([FakeDrive::folder('base-1')]),
            FakeDrive::fileList([FakeDrive::folder('object-1', 'server')]),
        );

        $this->assertSame('object-1', $drive->resolver(false)->getObjectFolderId());
        $this->assertStringContainsString("'base-1' in parents", $drive->query(1));
        $this->assertStringContainsString("key='pleskObject'", $drive->query(1));
        $this->assertSame(2, $drive->requestCount());
    }

    public function testCreatesTheObjectFolderDirectlyUnderTheBase(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(
            FakeDrive::fileList([FakeDrive::folder('base-1')]),
            FakeDrive::fileList([]),
            FakeDrive::fileList([]),
            FakeDrive::json(FakeDrive::folder('new-1', 'server', ['base-1'])),
            FakeDrive::fileList([FakeDrive::folder('new-1', 'server', ['base-1'])]),
        );

        $this->assertSame('new-1', $drive->resolver(false)->getObjectFolderId());
        $this->assertStringContainsString("'base-1' in parents", $drive->query(2));
        $create = (string)$drive->request(3)->getBody();
        $this->assertStringContainsString('"parents":["base-1"]', $create);
        $this->assertStringContainsString('pleskObject', $create);
    }

    public function testNamesANewSubscriptionFolderAfterTheDomain(): void
    {
        $drive = $this->subscription('example.com');
        $drive->mock->append(
            FakeDrive::fileList([FakeDrive::folder('base-1')]),
            FakeDrive::fileList([]),
            FakeDrive::fileList([]),
            FakeDrive::json(FakeDrive::folder('new-1', 'example.com', ['base-1'])),
            FakeDrive::fileList([FakeDrive::folder('new-1', 'example.com', ['base-1'])]),
        );

        $this->assertSame('new-1', $drive->resolver(false)->getObjectFolderId());
        $this->assertStringContainsString("name = 'subscription-33'", $drive->query(2));
        $create = (string)$drive->request(3)->getBody();
        $this->assertStringContainsString('"name":"example.com"', $create);
        $this->assertStringContainsString('pleskObject', $create);
    }

    public function testKeepsASubscriptionFolderThatAlreadyHasTheDomainName(): void
    {
        $drive = $this->subscription('example.com');
        $drive->mock->append(
            FakeDrive::fileList([FakeDrive::folder('base-1')]),
            FakeDrive::fileList([FakeDrive::folder('object-1', 'example.com', ['base-1'])]),
        );

        $this->assertSame('object-1', $drive->resolver(false)->getObjectFolderId());
        $this->assertSame(2, $drive->requestCount());
    }

    public function testRenamesTheSubscriptionFolderAfterADomainRename(): void
    {
        $drive = $this->subscription('example.org');
        $drive->mock->append(
            FakeDrive::fileList([FakeDrive::folder('base-1')]),
            FakeDrive::fileList([FakeDrive::folder('object-1', 'example.com', ['base-1'])]),
            FakeDrive::json(FakeDrive::folder('object-1', 'example.org', ['base-1'])),
        );

        $this->assertSame('object-1', $drive->resolver(false)->getObjectFolderId());
        $rename = $drive->request(2);
        $this->assertSame('PATCH', $rename->getMethod());
        $this->assertStringEndsWith('/files/object-1', $rename->getUri()->getPath());
        $this->assertStringContainsString('"name":"example.org"', (string)$rename->getBody());
        $this->assertSame(3, $drive->requestCount());
    }

    public function testAdoptsAnUntaggedFolderWithTheOldName(): void
    {
        // For example a 1.1.x folder that was moved up by hand after the 1.2.0 layout change.
        $drive = $this->subscription('example.com');
        $drive->mock->append(
            FakeDrive::fileList([FakeDrive::folder('base-1')]),
            FakeDrive::fileList([]),
            FakeDrive::fileList([FakeDrive::folder('legacy-1', 'subscription-33', ['base-1'])]),
            FakeDrive::json(FakeDrive::folder('legacy-1', 'example.com', ['base-1'])),
        );

        $this->assertSame('legacy-1', $drive->resolver(false)->getObjectFolderId());
        $update = (string)$drive->request(3)->getBody();
        $this->assertStringContainsString('"name":"example.com"', $update);
        $this->assertStringContainsString('pleskObject', $update);
    }

    public function testKeepsUsingTheFolderWhenTheRenameFails(): void
    {
        $drive = $this->subscription('example.org');
        $drive->mock->append(
            FakeDrive::fileList([FakeDrive::folder('base-1')]),
            FakeDrive::fileList([FakeDrive::folder('object-1', 'example.com', ['base-1'])]),
            new Response(403, ['Content-Type' => 'application/json'], '{"error":{"code":403,"message":"Forbidden"}}'),
        );

        $this->assertSame('object-1', $drive->resolver(false)->getObjectFolderId());
    }

    private function subscription(string $domain): FakeDrive
    {
        return new FakeDrive('subscription', 33, static fn(int $id): string => $id === 33 ? $domain : 'unexpected');
    }
}
