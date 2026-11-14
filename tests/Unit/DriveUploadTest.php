<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class DriveUploadTest extends TestCase
{
    private const string SESSION = 'https://upload.example.test/session';

    private function handle(string $pending, int $uploaded = 0): Modules_GdriveBackup_Drive_UploadHandle
    {
        $handle = new Modules_GdriveBackup_Drive_UploadHandle(self::SESSION, '.plesk-tmp-abc', 'backup.tar', 'parent-1', []);
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
}
