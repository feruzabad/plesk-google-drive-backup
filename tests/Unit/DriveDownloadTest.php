<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
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

    public function testRetriesWhenTheBodyIsEmpty(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(new Response(206, [], ''), new Response(206, [], 'hello'));

        $handle = new Modules_GdriveBackup_Drive_DownloadHandle('file-1', 0, 5);
        $read = $drive->fs->readFile($handle, $this->localFile);

        $this->assertSame(5, $read);
        $this->assertSame('hello', file_get_contents($this->localFile));
        $this->assertCount(1, $drive->sleeps);
    }

    public function testFailsInsteadOfReturningAShortReadWhenRetriesRunOut(): void
    {
        $drive = new FakeDrive();
        for ($i = 0; $i < 4; $i++) {
            $drive->mock->append(new Response(206, [], ''));
        }

        $handle = new Modules_GdriveBackup_Drive_DownloadHandle('file-1', 0, 5);

        $this->expectException(Modules_GdriveBackup_Drive_Exception::class);
        $drive->fs->readFile($handle, $this->localFile);
    }

    public function testResumesAfterTheBodyBreaksOffWithoutDuplicatingBytes(): void
    {
        $drive = new FakeDrive();
        $source = Utils::streamFor('he');
        $broken = FnStream::decorate($source, [
            'read' => static function (int $length) use ($source): string {
                $data = $source->read($length);
                if ($data === '') {
                    throw new RuntimeException('Unable to read from stream');
                }
                return $data;
            },
        ]);
        $drive->mock->append(new Response(206, [], $broken), new Response(206, [], 'llo'));

        $handle = new Modules_GdriveBackup_Drive_DownloadHandle('file-1', 0, 5);
        $read = $drive->fs->readFile($handle, $this->localFile);

        $this->assertSame(5, $read);
        $this->assertSame('hello', file_get_contents($this->localFile));
        $this->assertSame('bytes=2-4', $drive->request(1)->getHeaderLine('Range'));
    }

    public function testRetriesWhenAFullResponseEndsBeforeTheOffset(): void
    {
        $drive = new FakeDrive();
        $drive->mock->append(new Response(200, [], 'ab'), new Response(206, [], 'de'));

        $handle = new Modules_GdriveBackup_Drive_DownloadHandle('file-1', 3, 5);
        $read = $drive->fs->readFile($handle, $this->localFile);

        $this->assertSame(2, $read);
        $this->assertSame('de', file_get_contents($this->localFile));
    }
}
