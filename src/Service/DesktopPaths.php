<?php

namespace App\Service;

final class DesktopPaths
{
    public static function baseDir(string $projectDir): string
    {
        $configured = trim((string) getenv('SIMAUTO_DATA_DIR'));
        if ($configured !== '') {
            return rtrim(str_replace('\\', '/', $configured), '/');
        }

        return rtrim(str_replace('\\', '/', $projectDir), '/');
    }

    public static function dataDir(string $projectDir): string
    {
        return self::baseDir($projectDir) . '/data';
    }

    public static function varDir(string $projectDir): string
    {
        return self::baseDir($projectDir) . '/var';
    }

    public static function databasePath(string $projectDir): string
    {
        return self::dataDir($projectDir) . '/simauto.sqlite';
    }

    public static function backupDir(string $projectDir): string
    {
        return self::baseDir($projectDir) . '/backups';
    }

    public static function isDesktopMode(): bool
    {
        return trim((string) getenv('SIMAUTO_DATA_DIR')) !== '';
    }
}
