<?php

declare(strict_types=1);

final class Modules_GdriveBackup_Drive_Path
{
    public const int MAX_SEGMENT_BYTES = 255;

    private function __construct()
    {
    }

    /**
     * Normalize a Plesk transport path into safe Google Drive name segments.
     *
     * @return list<string>
     * @throws InvalidArgumentException
     */
    public static function normalize(?string $path): array
    {
        $path = str_replace('\\', '/', $path ?? '');
        $path = trim($path, '/');
        if ($path === '') {
            return [];
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new InvalidArgumentException('Path traversal is not allowed.');
            }
            if (strlen($segment) > self::MAX_SEGMENT_BYTES) {
                throw new InvalidArgumentException('A path segment is longer than Google Drive allows (255 bytes).');
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function normalizeString(?string $path): string
    {
        return implode('/', self::normalize($path));
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function baseName(?string $path): string
    {
        $segments = self::normalize($path);
        return $segments === [] ? '' : $segments[count($segments) - 1];
    }

    /**
     * @return list<string>
     * @throws InvalidArgumentException
     */
    public static function parentSegments(?string $path): array
    {
        $segments = self::normalize($path);
        array_pop($segments);
        return $segments;
    }

    /**
     * @param list<string> $segments
     */
    public static function join(array $segments): string
    {
        return implode('/', $segments);
    }
}
