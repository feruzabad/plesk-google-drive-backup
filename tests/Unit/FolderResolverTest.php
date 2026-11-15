<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class FolderResolverTest extends TestCase
{
    public function testLooksUpTheObjectFolderDirectlyUnderTheBase(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(
            FakeDrive::fileList([FakeDrive::folder('base-1')]),
            FakeDrive::fileList([FakeDrive::folder('object-1')]),
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
}
