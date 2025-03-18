<?php

namespace Shah\LaravelUpdater\Classes;

use Illuminate\Support\Facades\File;
use ZipArchive;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Shah\LaravelUpdater\Traits\Loggable;
use Shah\LaravelUpdater\Traits\Versionable;

class Installer
{
    use Loggable, Versionable;

    public function install(string $zipFile): bool
    {
        $this->log('Starting installation process', 'info');

        // Normalize the path
        $zipFile = $this->normalizePath($zipFile);
        $tmpDir = storage_path('app/updater/tmp');
        $extractDir = $tmpDir . '/extract';

        // Clean up any existing extract directory
        if (File::isDirectory($extractDir)) {
            File::deleteDirectory($extractDir);
        }

        if (!File::isDirectory($tmpDir)) {
            File::makeDirectory($tmpDir, 0755, true);
        }

        File::makeDirectory($extractDir, 0755, true);

        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            $this->log('Could not open the zip file: ' . $zipFile, 'error');
            return false;
        }

        $zip->extractTo($extractDir);
        $zip->close();

        $metadataFileInZip = $extractDir . '/update.json'; // Assuming update.json
        $newVersion = null;
        $updateInfo = null;

        if (File::exists($metadataFileInZip)) {
            $updateInfo = json_decode(File::get($metadataFileInZip), true);
            $newVersion = $updateInfo['version'] ?? null;
            if ($newVersion) {
                $this->log('New version from package: ' . $newVersion, 'info');
            }
        }

        $this->log('Installing files...', 'info');
        $excludedPaths = config('updater.excluded_paths', []);

        // First, create all directories
        $directories = $this->getAllDirectories($extractDir);
        foreach ($directories as $directory) {
            $relativePath = $this->getRelativePath($directory, $extractDir);

            if ($this->isPathExcluded($relativePath, $excludedPaths)) {
                continue;
            }

            $targetPath = base_path($relativePath);

            if (!File::isDirectory($targetPath)) {
                try {
                    File::makeDirectory($targetPath, 0755, true);
                    $this->log('Created directory: ' . $relativePath, 'info');
                } catch (Exception $e) {
                    $this->log('Failed to create directory: ' . $targetPath . ' - ' . $e->getMessage(), 'error');
                    return false;
                }
            }
        }

        // Then copy all files
        $allFiles = File::allFiles($extractDir);
        foreach ($allFiles as $file) {
            $relativePath = $this->getRelativePath($file->getPathname(), $extractDir);

            if ($relativePath === 'update.json' || $this->isPathExcluded($relativePath, $excludedPaths)) {
                continue;
            }

            $targetPath = base_path($relativePath);

            try {
                File::copy($file->getPathname(), $targetPath);
                $this->log('Installed file: ' . $relativePath, 'info');
            } catch (Exception $e) {
                $this->log('Failed to copy file: ' . $relativePath . ' - ' . $e->getMessage(), 'error');
                return false;
            }
        }

        // Handle vendor directory update
        if (isset($updateInfo['vendor_update']) && $updateInfo['vendor_update'] === true) {
            $vendorUpdater = new VendorUpdater($this);
            if (!$vendorUpdater->update($extractDir)) {
                $this->log('Vendor directory update failed.', 'error');
                return false;
            }
        }

        // Execute update script if it exists
        $updateScript = $extractDir . '/' . config('updater.script_filename', 'upgrade.php');
        if (File::exists($updateScript)) {
            $this->log('Executing update script', 'info');
            try {
                ob_start();
                $result = require $updateScript;
                ob_end_clean();

                if ($result === false) {
                    $this->log('Update script returned failure', 'error');
                    return false;
                }
                $this->log('Update script executed successfully', 'info');

                if (File::delete($updateScript)) {
                    $this->log('Update script Deleted successfully', 'info');
                }
            } catch (Exception $e) {
                $this->log('Error executing update script: ' . $e->getMessage(), 'error');
                return false;
            }
        }

        if ($newVersion) {
            $this->setCurrentVersion($newVersion);
            $this->addUpdateLog('Updated to version: ' . $newVersion);
        }

        // Run post-update commands
        if (config('updater.post_update_commands', false)) {
            $this->log('Running post-update commands', 'info');
            foreach (config('updater.post_update_commands') as $command) {
                $this->log('Running: ' . $command, 'info');
                try {
                    Artisan::call($command);
                    $this->log('Command output: ' . trim(Artisan::output()), 'info');
                } catch (Exception $e) {
                    $this->log('Command failed: ' . $e->getMessage(), 'warning');
                }
            }
        }

        // Clean up
        File::deleteDirectory($extractDir);
        File::delete($zipFile);
        $this->log('Cleanup completed', 'info');

        return true;
    }

    /**
     * Get all directories in a given path
     */
    private function getAllDirectories(string $path): array
    {
        $directories = [];
        $items = File::directories($path);

        foreach ($items as $item) {
            $directories[] = $item;
            $subdirs = $this->getAllDirectories($item);
            $directories = array_merge($directories, $subdirs);
        }

        return $directories;
    }

    /**
     * Get the relative path from a base directory
     */
    private function getRelativePath(string $path, string $basePath): string
    {
        // Normalize both paths
        $path = $this->normalizePath($path);
        $basePath = $this->normalizePath($basePath);

        // Remove base path and leading/trailing slashes
        $relativePath = str_replace($basePath, '', $path);
        return trim($relativePath, '/\\');
    }

    /**
     * Normalize a path to use consistent directory separators
     */
    private function normalizePath(string $path): string
    {
        // Replace backslashes with forward slashes
        return str_replace('\\', '/', $path);
    }

    private function isPathExcluded(string $path, array $excludedPaths): bool
    {
        // Normalize path for comparison
        $path = trim($path, '/\\');

        foreach ($excludedPaths as $excluded) {
            $excluded = trim($excluded, '/\\');

            if (
                $path === $excluded ||
                strpos($path . '/', $excluded . '/') === 0 ||
                strpos($path, $excluded . '/') === 0
            ) {
                return true;
            }
        }

        return false;
    }
}
