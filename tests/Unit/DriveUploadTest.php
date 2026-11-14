<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class DriveUploadTest extends TestCase
{
    private const string SESSION = 'https://upload.example.test/session';

    /**
     * @param list<string> $existingIds
     */
    private function handle(string $pending, int $uploaded = 0, array $existingIds = []): Modules_GdriveBackup_Drive_UploadHandle
    {
        $handle = new Modules_GdriveBackup_Drive_UploadHandle(self::SESSION, '.plesk-tmp-abc', 'backup.tar', 'parent-1', $existingIds);
        $handle->pending = $pending;
        $handle->uploaded = $uploaded;
        $handle->total = $uploaded + strlen($pending);
        return $handle;
    }

    private static function completed(string $id = 'file-1'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => $id, 'md5Checksum' => 'abc'], JSON_THROW_ON_ERROR));
    }

    private static function renamed(string $id = 'file-1'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => $id, 'name' => 'backup.tar'], JSON_THROW_ON_ERROR));
    }

    public function testRetriesTheFinalChunkAfterANetworkError(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(
            new ConnectException('Connection reset', new Request('PUT', self::SESSION)),
            self::completed(),
            self::renamed(),
        );

        $handle = $this->handle('payload');
        $drive->fs->closeFile($handle);

        $this->assertTrue($handle->closed);
        $this->assertSame('file-1', $handle->fileId);
        $this->assertCount(1, $drive->sleeps);
    }

    public function testTreatsA308WithoutRangeAsNothingReceived(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(new Response(308), self::completed(), self::renamed());

        $handle = $this->handle('payload');
        $drive->fs->closeFile($handle);

        $this->assertTrue($handle->closed);
        $this->assertSame(3, $drive->requestCount());
        $this->assertSame('bytes 0-6/7', $drive->request(1)->getHeaderLine('Content-Range'));
        $this->assertSame('payload', (string)$drive->request(1)->getBody());
    }

    public function testQueriesTheUploadStatusBeforeResendingAfterAFailure(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(
            new ConnectException('Connection reset', new Request('PUT', self::SESSION)),
            new Response(308, ['Range' => 'bytes=0-2']),
            self::completed(),
            self::renamed(),
        );

        $handle = $this->handle('payload');
        $drive->fs->closeFile($handle);

        $this->assertTrue($handle->closed);
        $status = $drive->request(1);
        $this->assertSame('PUT', $status->getMethod());
        $this->assertSame('bytes */7', $status->getHeaderLine('Content-Range'));
        $this->assertSame('', (string)$status->getBody());
        $this->assertSame('bytes 3-6/7', $drive->request(2)->getHeaderLine('Content-Range'));
        $this->assertSame('load', (string)$drive->request(2)->getBody());
    }

    public function testFailsWhenGoogleLostAcknowledgedData(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(new Response(308, ['Range' => 'bytes=0-4']));

        $handle = $this->handle('x', 10);

        $this->expectException(Modules_GdriveBackup_Drive_Exception::class);
        $this->expectExceptionMessage('already acknowledged');
        try {
            $drive->fs->closeFile($handle);
        } finally {
            $this->assertSame([], $drive->sleeps);
        }
    }

    private static function error(int $status, string $reason = 'badRequest'): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'error' => ['code' => $status, 'message' => 'Denied', 'errors' => [['reason' => $reason, 'message' => 'Denied']]],
        ], JSON_THROW_ON_ERROR));
    }

    public function testKeepsTheStoredBackupWhenDeletingTheOldCopyFails(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(self::completed(), self::renamed(), self::error(403, 'insufficientFilePermissions'));

        $handle = $this->handle('payload', 0, ['old-1']);
        $drive->fs->closeFile($handle);

        $this->assertTrue($handle->closed);
        $this->assertSame(3, $drive->requestCount());
        $this->assertSame('DELETE', $drive->request(2)->getMethod());
    }

    public function testKeepsUnacknowledgedBytesSoCloseCanBeRetried(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(
            new Response(308, ['Range' => 'bytes=0-2']),
            self::error(400),
        );

        $handle = $this->handle('payload');
        try {
            $drive->fs->closeFile($handle);
            $this->fail('closeFile should have failed.');
        } catch (Modules_GdriveBackup_Drive_Exception) {
        }

        $this->assertFalse($handle->closed);
        $this->assertSame(3, $handle->uploaded);
        $this->assertSame('load', $handle->pending);

        $drive->mock->append(self::completed(), self::renamed());
        $drive->fs->closeFile($handle);

        $this->assertTrue($handle->closed);
        $this->assertSame('bytes 3-6/7', $drive->request(2)->getHeaderLine('Content-Range'));
    }

    public function testRetryingCloseAfterAFailedRenameDoesNotResendData(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(self::completed(), self::error(400));

        $handle = $this->handle('payload');
        try {
            $drive->fs->closeFile($handle);
            $this->fail('closeFile should have failed.');
        } catch (Modules_GdriveBackup_Drive_Exception) {
        }

        $drive->mock->append(self::renamed());
        $drive->fs->closeFile($handle);

        $this->assertTrue($handle->closed);
        $this->assertSame(3, $drive->requestCount());
        $this->assertSame('PATCH', $drive->request(2)->getMethod());
    }
}
