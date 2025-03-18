<?php

namespace Shah\LaravelUpdater\Classes;

use Illuminate\Support\Facades\File;
use ZipArchive;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Shah\LaravelUpdater\Traits\Loggable;
use Shah\LaravelUpdater\Traits\Versionable;

class RecoveryManager
{
    use Loggable, Versionable;

    /**
     * @var DatabaseBackup
     */
    protected $databaseBackup;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->databaseBackup = new DatabaseBackup();
    }

    /**
     * Recover the application from a backup
     * 
     * @return bool
     */
    public function recover(): bool
    {
        $recoveryZipPath = $this->getRecoveryPath();

        if (!$recoveryZipPath || !File::exists($recoveryZipPath)) {
            $this->log('No recovery zip file found.', 'error');
            return false;
        }

        $this->log('Starting recovery process from: ' . $recoveryZipPath, 'info');

        // Create a timestamped temporary directory
        $tmpDir = storage_path('app/updater/tmp');
        $extractDir = $tmpDir . '/recovery_extract_' . date('Ymd_His');

        // Ensure the directory exists
        if (!File::isDirectory($extractDir)) {
            File::makeDirectory($extractDir, 0755, true);
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($recoveryZipPath) !== true) {
                $this->log('Failed to open recovery zip file.', 'error');
                return false;
            }

            $zip->extractTo($extractDir);
            $zip->close();

            // Restore files
            $backupDir = $extractDir . '/backup';
            if (File::isDirectory($backupDir)) {
                $this->restoreFiles($backupDir);
            } else {
                $this->log('No backup directory found in recovery archive.', 'warning');
            }

            // Restore database
            $databaseBackupDir = $extractDir . '/database';
            if (File::isDirectory($databaseBackupDir)) {
                $this->restoreDatabase($databaseBackupDir);
            } else {
                $this->log('No database backup directory found in recovery archive.', 'warning');
            }

            // Clean up the temporary directory
            File::deleteDirectory($extractDir);
            $this->log('Recovery completed successfully.', 'info');

            return true;
        } catch (Exception $e) {
            $this->log('Recovery failed: ' . $e->getMessage(), 'error');

            // Clean up on failure
            if (File::isDirectory($extractDir)) {
                File::deleteDirectory($extractDir);
            }

            return false;
        }
    }

    /**
     * Restore files from backup
     * 
     * @param string $backupDir
     * @return void
     */
    protected function restoreFiles(string $backupDir): void
    {
        $files = File::allFiles($backupDir);

        foreach ($files as $file) {
            // Get relative path safely
            $relativePath = $this->getRelativePath($backupDir, $file->getPathname());

            // Skip files that might be outside the base path
            if ($this->isPathTraversal($relativePath)) {
                $this->log('Skipping suspicious path: ' . $relativePath, 'warning');
                continue;
            }

            $targetPath = base_path($relativePath);
            $targetDir = dirname($targetPath);

            if (!is_dir($targetDir)) {
                File::makeDirectory($targetDir, 0755, true, true);
            }

            // Copy the file and log the result
            if (File::copy($file->getPathname(), $targetPath)) {
                $this->log('Restored file: ' . $relativePath, 'info');
            } else {
                $this->log('Failed to restore file: ' . $relativePath, 'error');
            }
        }

        $this->log('File recovery completed.', 'info');
    }

    /**
     * Restore database from backup
     * 
     * @param string $databaseBackupDir
     * @return void
     */
    protected function restoreDatabase(string $databaseBackupDir): void
    {
        $backupFiles = File::files($databaseBackupDir);

        if (empty($backupFiles)) {
            $this->log('No database backup files found.', 'warning');
            return;
        }

        $databaseBackupPath = $backupFiles[0]->getPathname();

        if (!File::exists($databaseBackupPath)) {
            $this->log('Database backup file not found.', 'error');
            return;
        }

        try {
            // Use our DatabaseBackup class
            $success = $this->databaseBackup->restore($databaseBackupPath);

            if ($success) {
                $this->log('Database restored successfully.', 'info');
            } else {
                $this->log('Database restore failed.', 'error');
            }
        } catch (Exception $e) {
            $this->log('Error restoring database: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Get the recovery file path
     * 
     * @return string|null
     */
    protected function getRecoveryPath(): ?string
    {
        $recoveryDir = storage_path('app/updater/recovery');

        if (!File::isDirectory($recoveryDir)) {
            return null;
        }

        $files = File::files($recoveryDir);

        if (empty($files)) {
            return null;
        }

        // Get the most recent recovery file
        usort($files, function ($a, $b) {
            return $b->getMTime() - $a->getMTime();
        });

        return $files[0]->getPathname();
    }

    /**
     * Get the relative path correctly
     * 
     * @param string $basePath
     * @param string $path
     * @return string
     */
    protected function getRelativePath(string $basePath, string $path): string
    {
        // Normalize paths for consistent comparison
        $basePath = rtrim(str_replace('\\', '/', $basePath), '/') . '/';
        $path = str_replace('\\', '/', $path);

        return str_replace($basePath, '', $path);
    }

    /**
     * Check if a path might be a traversal attack
     * 
     * @param string $path
     * @return bool
     */
    protected function isPathTraversal(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        // Check for path traversal attempts
        if (strpos($normalized, '../') !== false || strpos($normalized, '..\\') !== false) {
            return true;
        }

        // Check for absolute paths
        if (strpos($normalized, '/') === 0 || preg_match('/^[A-Z]:\//i', $normalized)) {
            return true;
        }

        return false;
    }
}
