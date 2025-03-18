<?php

namespace Shah\LaravelUpdater\Classes;

use Illuminate\Support\Facades\File;
use ZipArchive;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Shah\LaravelUpdater\Traits\Loggable;
use Shah\LaravelUpdater\Traits\Versionable;

class BackupManager
{
    use Loggable, Versionable;

    private $backupDir;

    public function createBackup(string $zipFile): string|false
    {
        // Use a temporary directory for the backup that will be removed after zipping
        $this->backupDir = storage_path('app/updater/backup_' . date('Ymd_His'));

        // Ensure the directory exists
        if (!File::isDirectory($this->backupDir)) {
            File::makeDirectory($this->backupDir, 0755, true);
        }

        $this->log('Creating backup in: ' . $this->backupDir, 'info');

        $zip = new ZipArchive();
        if ($zip->open($zipFile) === true) {
            $directoriesToBackup = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (substr($entry, -1) === '/') {
                    $directoriesToBackup[] = $entry;
                } else {
                    $dirname = pathinfo($entry, PATHINFO_DIRNAME);
                    if ($dirname !== '.' && !in_array($dirname . '/', $directoriesToBackup)) {
                        $directoriesToBackup[] = $dirname . '/';
                    }
                }
            }
            $zip->close();

            $excludedPaths = config('updater.excluded_paths',);

            foreach ($directoriesToBackup as $dir) {
                $fullPath = base_path($dir);
                if (File::isDirectory($fullPath) && !$this->isPathExcluded($dir, $excludedPaths)) {
                    $this->backupDirectory($fullPath, $this->backupDir . '/' . $dir);
                } elseif (File::isFile($fullPath) && !$this->isPathExcluded($dir, $excludedPaths)) {
                    $this->backupFile($fullPath, $this->backupDir . '/' . $dir);
                }
            }

            // Backup database
            $databaseBackupPath = $this->backupDatabase($this->backupDir);
            if (!$databaseBackupPath) {
                $this->log('Failed to backup database.', 'warning');
            }

            // Create recovery zip
            $recoveryZipPath = $this->createRecoveryZip($this->backupDir, $databaseBackupPath);
            if ($recoveryZipPath) {
                $this->setRecoveryPath($recoveryZipPath);
                $this->log('Recovery zip created at: ' . $recoveryZipPath, 'info');

                // Clean up temporary backup directory
                File::deleteDirectory($this->backupDir);

                return $recoveryZipPath; // Return zip path instead of directory
            } else {
                $this->log('Failed to create recovery zip.', 'error');

                // Clean up if failed too
                File::deleteDirectory($this->backupDir);

                return false;
            }
        } else {
            $this->log('Could not open the update zip file to determine directories for backup.', 'error');
            return false;
        }
    }

    private function backupDirectory(string $source, string $destination)
    {
        // Normalize paths
        $source = str_replace(['\\', '//'], '/', $source);
        $destination = str_replace(['\\', '//'], '/', $destination);

        // Create the destination directory if it doesn't exist
        $destinationDir = dirname($destination);
        if (!File::isDirectory($destinationDir)) {
            File::makeDirectory($destinationDir, 0755, true);
        }

        File::copyDirectory($source, $destination);
        $this->log('Backed up directory: ' . str_replace(base_path() . '/', '', $source), 'info');
    }

    private function backupFile(string $source, string $destination)
    {
        File::copy($source, $destination);
        $this->log('Backed up file: ' . str_replace(base_path() . '/', '', $source), 'info');
    }

    private function backupDatabase(string $backupDir): string|false
    {
        try {
            $databaseBackup = new DatabaseBackup();
            return $databaseBackup->backup($backupDir);
        } catch (Exception $e) {
            $this->log('Error backing up database: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    private function createRecoveryZip(string $backupDir, string|false $databaseBackupPath): string|false
    {
        $zip = new ZipArchive();
        $zipFilename = base_path('recovery_' . date('Ymd_His') . '.zip');

        if ($zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            // Add all files from the backup directory
            $this->addDirectoryToZip($zip, $backupDir, 'backup');

            // Add database backup file if it exists
            if ($databaseBackupPath && File::exists($databaseBackupPath)) {
                $zip->addFile($databaseBackupPath, 'database/' . basename($databaseBackupPath));
            }

            $zip->close();
            return $zipFilename;
        } else {
            return false;
        }
    }

    /**
     * Helper method to recursively add a directory to a zip file
     */
    private function addDirectoryToZip(ZipArchive $zip, string $directory, string $zipPath): void
    {
        if (!File::isDirectory($directory)) {
            return;
        }

        // Create empty directory in zip
        $zip->addEmptyDir($zipPath);

        $files = File::allFiles($directory);
        foreach ($files as $file) {
            // Get the relative path within the backup directory
            $relativePath = $this->getRelativePath($file->getPathname(), $this->backupDir);
            $zipEntryPath = $zipPath . '/' . $relativePath;

            $zip->addFile($file->getPathname(), $zipEntryPath);
        }

        // Also add empty directories
        $dirs = File::directories($directory);
        foreach ($dirs as $dir) {
            $relativePath = $this->getRelativePath($dir, $this->backupDir);
            $this->addDirectoryToZip($zip, $dir, $zipPath . '/' . $relativePath);
        }
    }

    /**
     * Get the relative path from a base directory
     */
    private function getRelativePath(string $path, string $basePath): string
    {
        // Make sure both paths use consistent directory separators
        $path = str_replace('\\', '/', $path);
        $basePath = str_replace('\\', '/', $basePath);

        // Remove base path and leading slashes
        return trim(str_replace($basePath, '', $path), '/');
    }

    private function isPathExcluded(string $path, array $excludedPaths): bool
    {
        foreach ($excludedPaths as $excluded) {
            $excluded = trim($excluded, '/');
            $path = trim($path, '/');
            if ($path === $excluded || strpos($path, $excluded . '/') === 0) {
                return true;
            }
        }
        return false;
    }
}
