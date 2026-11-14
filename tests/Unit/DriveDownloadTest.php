<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class DriveDownloadTest extends TestCase
{
    private string $localFile;

    protected function setUp(): void
    {
        $this->localFile = tempnam(sys_get_temp_dir(), 'gdrive-test-') ?: throw new RuntimeException('tempnam failed');
    }

    protected function tearDown(): void
    {
        @unlink($this->localFile);
    }

    public function testRequestsTheSliceWithAValidRangeHeader(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(new Response(206, [], str_repeat('b', 50)));

        $handle = new Modules_GdriveBackup_Drive_DownloadHandle('file-1', 100, 1000);
        $read = $drive->fs->readFile($handle, $this->localFile, 50);

        $this->assertSame(50, $read);
        $this->assertSame('bytes=100-149', $drive->request(0)->getHeaderLine('Range'));
        $this->assertSame(str_repeat('b', 50), file_get_contents($this->localFile));
        $this->assertSame(150, $handle->offset);
    }

    public function testRetriesAfterANetworkError(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(
            new ConnectException('Connection reset', new Request('GET', 'https://example.test')),
            new Response(206, [], 'hello'),
        );

        $handle = new Modules_GdriveBackup_Drive_DownloadHandle('file-1', 0, 5);
        $read = $drive->fs->readFile($handle, $this->localFile);

        $this->assertSame(5, $read);
        $this->assertSame('hello', file_get_contents($this->localFile));
        $this->assertSame(2, $drive->requestCount());
        $this->assertCount(1, $drive->sleeps);
    }
}
