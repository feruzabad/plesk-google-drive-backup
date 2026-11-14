<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class FileSystemCacheTest extends TestCase
{
    private static function session(): Response
    {
        return new Response(200, ['Location' => 'https://upload.example.test/session']);
    }

    public function testRemembersAFolderCreatedAfterANegativeLookup(): void
    {
        $drive = new FakeDrive();
        $drive->useObjectRoot('root-1');
        $drive->mock->append(
            FakeDrive::fileList([]),
            FakeDrive::json(FakeDrive::folder('dir-1', 'dir', ['root-1'])),
            FakeDrive::fileList([FakeDrive::folder('dir-1', 'dir')]),
            FakeDrive::fileList([]),
            self::session(),
            FakeDrive::fileList([]),
            self::session(),
        );

        $this->assertNull($drive->fs->stat('dir'));
        $first = $drive->fs->openFileWrite('dir/a.tar');
        $second = $drive->fs->openFileWrite('dir/b.tar');

        $this->assertSame('dir-1', $first->parentId);
        $this->assertSame('dir-1', $second->parentId);
        $creates = array_filter(
            $drive->requests,
            static fn($request): bool => $request->getMethod() === 'POST'
                && $request->getUri()->getPath() === '/drive/v3/files',
        );
        $this->assertCount(1, $creates);
    }

    public function testStatAfterListingReturnsTheNewestDuplicate(): void
    {
        $drive = new FakeDrive();
        $drive->useObjectRoot('root-1');
        $drive->mock->append(FakeDrive::fileList([
            ['id' => 'new', 'name' => 'a.tar', 'size' => '2', 'modifiedTime' => '2026-02-01T00:00:00.000Z'],
            ['id' => 'old', 'name' => 'a.tar', 'size' => '1', 'modifiedTime' => '2026-01-01T00:00:00.000Z'],
        ]));

        $listing = $drive->fs->listDir('');
        $stat = $drive->fs->stat('a.tar');

        $this->assertSame(2, $listing[0]['size']);
        $this->assertSame(2, $stat['size'] ?? null);
        $this->assertSame(1, $drive->requestCount());
    }
}
