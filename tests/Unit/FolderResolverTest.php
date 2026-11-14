<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class FolderResolverTest extends TestCase
{
    public function testLooksUpTheServerFolderUnderTheCurrentBase(): void
    {
        $drive = new FakeDrive();
        $drive->configure(['folder' => 'New Backups']);
        $drive->mock->append(
            FakeDrive::fileList([FakeDrive::folder('base-new')]),
            FakeDrive::fileList([FakeDrive::folder('server-new')]),
        );

        $this->assertSame('server-new', $drive->resolver(false)->getServerFolderId());
        $this->assertStringContainsString("'base-new' in parents", $drive->query(1));
        $this->assertStringContainsString("key='pleskServer'", $drive->query(1));
    }

    public function testIgnoresASavedServerFolderFromAnotherBase(): void
    {
        $drive = new FakeDrive();
        $drive->configure(['folder' => 'New Backups', 'folder_id' => 'base-new', 'server_folder_id' => 'server-old']);
        $drive->mock->append(
            FakeDrive::json(FakeDrive::folder('base-new', 'New Backups', ['root'])),
            FakeDrive::json(FakeDrive::folder('server-old', 'host', ['base-old'])),
            FakeDrive::fileList([FakeDrive::folder('server-new')]),
        );

        $this->assertSame('server-new', $drive->resolver(true)->getServerFolderId());
        $this->assertSame('server-new', $drive->settings->getServerFolderId());
        $this->assertStringContainsString("'base-new' in parents", $drive->query(2));
    }

    public function testLooksUpTheObjectFolderUnderTheServerFolder(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(
            FakeDrive::fileList([FakeDrive::folder('base-1')]),
            FakeDrive::fileList([FakeDrive::folder('server-1')]),
            FakeDrive::fileList([FakeDrive::folder('object-1')]),
        );

        $this->assertSame('object-1', $drive->resolver(false)->getObjectFolderId());
        $this->assertStringContainsString("'server-1' in parents", $drive->query(2));
        $this->assertStringContainsString("key='pleskObject'", $drive->query(2));
    }
}
