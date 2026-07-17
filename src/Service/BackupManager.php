<?php

namespace App\Service;

use RuntimeException;

final class BackupManager
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function databasePath(): string
    {
        return DesktopPaths::databasePath($this->projectDir);
    }

    public function backupDir(): string
    {
        return DesktopPaths::backupDir($this->projectDir);
    }

    public function backups(): array
    {
        $dir = $this->backupDir();
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/simauto-*.sqlite') ?: [];
        rsort($files, SORT_NATURAL);

        return array_map(static function (string $path): array {
            return [
                'name' => basename($path),
                'path' => $path,
                'size' => filesize($path) ?: 0,
                'created_at' => date('Y-m-d H:i:s', filemtime($path) ?: time()),
            ];
        }, $files);
    }

    public function openFolder(): void
    {
        $dir = $this->backupDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('فتح المجلد متاح فقط على نسخة Windows');
        }

        pclose(popen('explorer.exe "' . str_replace('"', '', $dir) . '"', 'r'));
    }
}
