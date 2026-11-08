<?php

declare(strict_types=1);

final class Modules_GdriveBackup_Drive_Query
{
    private function __construct()
    {
    }

    public static function escapeValue(string $value): string
    {
        return (string)str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    public static function name(string $name): string
    {
        return "name = '" . self::escapeValue($name) . "'";
    }

    public static function parent(string $parentId): string
    {
        return "'" . self::escapeValue($parentId) . "' in parents";
    }

    public static function appProperty(string $key, string $value): string
    {
        return "appProperties has { key='" . self::escapeValue($key) . "' and value='" . self::escapeValue($value) . "' }";
    }
}
