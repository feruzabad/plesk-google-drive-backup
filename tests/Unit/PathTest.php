<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    public function testNormalizesSlashesAndTrims(): void
    {
        $this->assertSame(['a', 'b', 'c'], Modules_GdriveBackup_Drive_Path::normalize('/a\\b//c/'));
        $this->assertSame('a/b/c', Modules_GdriveBackup_Drive_Path::normalizeString('/a\\b//c/'));
    }

    public function testEmptyPath(): void
    {
        $this->assertSame([], Modules_GdriveBackup_Drive_Path::normalize(''));
        $this->assertSame([], Modules_GdriveBackup_Drive_Path::normalize(null));
        $this->assertSame([], Modules_GdriveBackup_Drive_Path::normalize('/'));
    }

    public function testRejectsTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Modules_GdriveBackup_Drive_Path::normalize('a/../b');
    }

    public function testRejectsLongSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Modules_GdriveBackup_Drive_Path::normalize(str_repeat('a', 256));
    }

    public function testParentSegments(): void
    {
        $this->assertSame(['a', 'b'], Modules_GdriveBackup_Drive_Path::parentSegments('a/b/c'));
        $this->assertSame(['a'], Modules_GdriveBackup_Drive_Path::parentSegments('a/b'));
        $this->assertSame([], Modules_GdriveBackup_Drive_Path::parentSegments('a'));
    }
}
