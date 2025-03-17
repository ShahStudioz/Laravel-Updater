<?php

namespace Shah\LaravelUpdater;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Exception;
use Illuminate\Support\Facades\Auth;
use ZipArchive;

class Updater
{
    /**
     * Temporary backup directory
     *
     * @var string|null
     */
    private $backupDir = null;

    /**
     * Response messages
     *
     * @var array
     */
    private $messages = [];

    /**
     * License information
     *
     * @var array
     */
    private $license = null;

    /**
     * Timeout for the the http request
     *
     * @var int
     */
    private $requestTimeout = 60;

    public function __construct()
    {
        // unlimited max execution time
        set_time_limit(0);

        // increase memory_limit to 1GB
        ini_set('memory_limit', '-1');

        // increase max_execution_time to 1 hour
        ini_set('max_execution_time', 3600);
    }

    /**
     * Check if current user has permission to perform updates.
     *
     * @return bool
     */
    public function checkPermission()
    {
        $allowedUsers = config('updater.allow_users_id');

        if ($allowedUsers === false) {
            return true;
        }

        if (is_array($allowedUsers) && Auth::check()) {
            return in_array(Auth::id(), $allowedUsers);
        }

        return false;
    }

    /**
     * Log a message.
     *
     * @param string $message
     * @param string $type
     * @return void
     */
    public function log($message, $type = 'info')
    {
        $header = "MangaCMSUpdater - ";

        // Add to messages array
        $this->messages[] = [
            'message' => $message,
            'type' => $type
        ];

        // Log to Laravel logs
        if ($type == 'info') {
            Log::info($header . '[info] ' . $message);
        } elseif ($type == 'warn' || $type == 'warning') {
            Log::warning($header . '[warn] ' . $message);
        } elseif ($type == 'err' || $type == 'error') {
            Log::error($header . '[err] ' . $message);
        }
    }

    /**
     * Get all logged messages.
     *
     * @return array
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * Get the current version.
     *
     * @return string
     */
    public function getCurrentVersion()
    {
        $versionFile = config('updater.version_file', base_path('version.txt'));

        if (!File::exists($versionFile)) {
            $this->log('Version file not found. Creating with default version 1.0.0', 'info');
            File::put($versionFile, '1.0.0');
            return '1.0.0';
        }

        return trim(File::get($versionFile));
    }

    /**
     * Set the current version.
     *
     * @param string $version
     * @return bool
     */
    public function setCurrentVersion($version)
    {
        $versionFile = config('updater.version_file', base_path('version.txt'));

        try {
            File::put($versionFile, $version);
            return true;
        } catch (Exception $e) {
            $this->log('Failed to update version file: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Check for updates from the remote server.
     *
     * @return array|null
     */
    public function checkForUpdates()
    {
        if (!config('updater.online_check', true)) {
            $this->log('Online update check is disabled', 'info');
            return null;
        }

        try {
            $currentVersion = $this->getCurrentVersion();
            $this->log('Checking for updates. Current version: ' . $currentVersion, 'info');

            $updateUrl = rtrim(config('updater.update_baseurl'), '/') . '/updater.json';

            // Include license information if available
            $headers = [];
            if ($this->license) {
                $headers = [
                    'X-License-Key' => $this->license['key'] ?? null,
                    'X-License-Name' => $this->license['name'] ?? null,
                    'X-License-Email' => $this->license['email'] ?? null,
                    'X-Domain' => request()->getHost()
                ];
            }

            $response = Http::withHeaders($headers)->get($updateUrl);

            if ($response->successful()) {
                $updateData = $response->json();

                if (isset($updateData['version']) && version_compare($updateData['version'], $currentVersion, '>')) {
                    $this->log('New version available: ' . $updateData['version'], 'info');
                    return $updateData;
                } else {
                    $this->log('No updates available', 'info');
                    return null;
                }
            } else {
                $this->log('Failed to check for updates: ' . $response->status(), 'error');
                return null;
            }
        } catch (Exception $e) {
            $this->log('Exception while checking for updates: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Set license information.
     *
     * @param array $license
     * @return $this
     */
    public function setLicense($license)
    {
        $this->license = $license;
        return $this;
    }

    /**
     * Set a custom timeout for request
     *
     * @param int $timeOut
     * @return $this
     */
    public function setTimeOut(int $timeOut = 60)
    {
        $this->requestTimeout = $timeOut;

        return $this;
    }

    /**
     * Download an update package.
     *
     * @param string $filename
     * @return string|false
     */
    public function download($filename)
    {
        $tmpDir = base_path() . '/' . config('updater.tmp_directory', 'updater_tmp');

        if (!is_dir($tmpDir)) {
            File::makeDirectory($tmpDir, 0755, true);
        }

        try {
            $localFile = $tmpDir . '/' . $filename;
            $remoteFileUrl = rtrim(config('updater.update_baseurl'), '/') . '/' . $filename;

            $this->log('Downloading update from: ' . $remoteFileUrl, 'info');

            // Include license information if available
            $headers = [];
            if ($this->license) {
                $headers = [
                    'X-License-Key' => $this->license['key'] ?? null,
                    'X-License-Name' => $this->license['name'] ?? null,
                    'X-License-Email' => $this->license['email'] ?? null,
                    'X-Domain' => request()->getHost()
                ];
            }

            $response = Http::timeout($this->requestTimeout)->withHeaders($headers)->get($remoteFileUrl);

            if ($response->successful()) {
                File::put($localFile, $response->body());
                $this->log('Download successful', 'info');
                return $localFile;
            } else {
                $this->log('Download failed: ' . $response->status(), 'error');
                return false;
            }
        } catch (Exception $e) {
            $this->log('Exception while downloading update: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Install an update from a local zip file.
     *
     * @param string $zipFile Path to the zip file
     * @return bool
     */
    public function installFromZip($zipFile)
    {
        if (!File::exists($zipFile)) {
            $this->log('Update file not found: ' . $zipFile, 'error');
            return false;
        }

        try {
            Artisan::call('down');
            $this->log('Maintenance mode enabled', 'info');

            $result = $this->extractAndInstall($zipFile);

            Artisan::call('up');
            $this->log('Maintenance mode disabled', 'info');

            return $result;
        } catch (Exception $e) {
            $this->log('Exception during installation: ' . $e->getMessage(), 'error');
            $this->recovery();
            Artisan::call('up');
            $this->log('Maintenance mode disabled after error', 'info');
            return false;
        }
    }

    /**
     * Extract and install an update from a zip file.
     *
     * @param string $zipFile
     * @return bool
     */
    private function extractAndInstall($zipFile)
    {
        $this->log('Starting installation process', 'info');
        $tmpDir = dirname($zipFile);
        $extractDir = $tmpDir . '/extract';

        // Create extraction directory
        if (!is_dir($extractDir)) {
            File::makeDirectory($extractDir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            $this->log('Could not open the zip file', 'error');
            return false;
        }

        // Extract zip
        $zip->extractTo($extractDir);
        $zip->close();

        // Check for version file
        $versionFile = $extractDir . '/version.txt';
        $newVersion = null;

        if (File::exists($versionFile)) {
            $newVersion = trim(File::get($versionFile));
            $this->log('New version from package: ' . $newVersion, 'info');
        }

        // Look for update script
        $updateScript = $extractDir . '/' . config('updater.script_filename', 'upgrade.php');
        $hasUpdateScript = File::exists($updateScript);

        $this->log('Installing files...', 'info');

        // Process all files recursively
        $allFiles = File::allFiles($extractDir);

        foreach ($allFiles as $file) {
            $relativePath = str_replace($extractDir . '/', '', $file->getPathname());

            // Skip the update script and version file for now
            if ($relativePath === 'version.txt' || $relativePath === config('updater.script_filename', 'upgrade.php')) {
                continue;
            }

            // Skip metadata files
            if (strpos($relativePath, '__MACOSX') === 0 || strpos($relativePath, '.DS_Store') !== false) {
                continue;
            }

            $targetPath = base_path() . '/' . $relativePath;
            $targetDir = dirname($targetPath);

            // Create directory if it doesn't exist
            if (!is_dir($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
                $this->log('Created directory: ' . $targetDir, 'info');
            }

            // Backup existing file before overwriting
            if (File::exists($targetPath)) {
                $this->backup($relativePath);
            }

            // Copy file to target location
            File::copy($file->getPathname(), $targetPath);
            $this->log('Installed file: ' . $relativePath, 'info');
        }

        // Execute update script if it exists
        if ($hasUpdateScript) {
            $this->log('Executing update script', 'info');
            try {
                // Include the script in a safe context
                ob_start();
                $result = require $updateScript;
                ob_end_clean();

                if ($result === false) {
                    $this->log('Update script returned failure', 'error');
                    return false;
                }

                $this->log('Update script executed successfully', 'info');
            } catch (Exception $e) {
                $this->log('Error executing update script: ' . $e->getMessage(), 'error');
                return false;
            }
        }

        // If we have a new version, update the version file
        if ($newVersion) {
            $this->setCurrentVersion($newVersion);
            $this->log('Updated system version to: ' . $newVersion, 'info');
        }

        // Run post-update commands
        if (config('updater.post_update_commands', [])) {
            $this->log('Running post-update commands', 'info');
            foreach (config('updater.post_update_commands') as $command) {
                $this->log('Running: ' . $command, 'info');
                try {
                    Artisan::call($command);
                    $this->log('Command output: ' . Artisan::output(), 'info');
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
     * Backup a file before overwriting it.
     *
     * @param string $relativePath
     * @return bool
     */
    private function backup($relativePath)
    {
        if (!$this->backupDir) {
            $this->backupDir = base_path() . '/backup_' . date('Ymd_His');
        }

        $sourcePath = base_path() . '/' . $relativePath;
        $backupPath = $this->backupDir . '/' . $relativePath;
        $backupDir = dirname($backupPath);

        if (!is_dir($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        try {
            File::copy($sourcePath, $backupPath);
            return true;
        } catch (Exception $e) {
            $this->log('Failed to backup file: ' . $relativePath . ' - ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Recovery from backup if update fails.
     *
     * @return bool
     */
    public function recovery()
    {
        if (!$this->backupDir || !is_dir($this->backupDir)) {
            $this->log('No backup directory found for recovery', 'error');
            return false;
        }

        $this->log('Starting recovery process from backup: ' . $this->backupDir, 'info');

        try {
            $files = File::allFiles($this->backupDir);

            foreach ($files as $file) {
                $relativePath = str_replace($this->backupDir . '/', '', $file->getPathname());
                $targetPath = base_path() . '/' . $relativePath;

                // Ensure target directory exists
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    File::makeDirectory($targetDir, 0755, true);
                }

                // Restore from backup
                File::copy($file->getPathname(), $targetPath);
                $this->log('Restored file: ' . $relativePath, 'info');
            }

            $this->log('Recovery completed successfully', 'info');
            return true;
        } catch (Exception $e) {
            $this->log('Recovery failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Update the system with the latest version.
     *
     * @return bool
     */
    public function update()
    {
        $this->log('Starting update process. Current version: ' . $this->getCurrentVersion(), 'info');

        if (!$this->checkPermission()) {
            $this->log('Permission denied', 'error');
            return false;
        }

        // Check for updates
        $updateInfo = $this->checkForUpdates();

        if (!$updateInfo) {
            $this->log('No updates available or failed to check', 'info');
            return false;
        }

        // Download the update
        $zipFile = $this->download($updateInfo['archive']);

        if (!$zipFile) {
            $this->log('Failed to download update package', 'error');
            return false;
        }

        // Install the update
        $result = $this->installFromZip($zipFile);

        return $result;
    }

    /**
     * Install a package to vendor directory.
     * 
     * @param string $packageName
     * @param string $zipFile Path to the zip file containing the package
     * @return bool
     */
    public function installPackage($packageName, $zipFile)
    {
        if (!File::exists($zipFile)) {
            $this->log("Package file not found: $zipFile", 'error');
            return false;
        }

        $vendorDir = base_path('vendor');
        $packageDir = $vendorDir . '/' . $packageName;
        $extractDir = dirname($zipFile) . '/package_extract';

        // Create extraction directory
        if (!is_dir($extractDir)) {
            File::makeDirectory($extractDir, 0755, true);
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                $this->log('Could not open the package zip file', 'error');
                return false;
            }

            // Extract zip
            $zip->extractTo($extractDir);
            $zip->close();

            // Backup existing package if it exists
            if (is_dir($packageDir)) {
                $backupDir = $vendorDir . '/backup_' . str_replace('/', '_', $packageName) . '_' . date('Ymd_His');
                File::moveDirectory($packageDir, $backupDir);
                $this->log("Backed up existing package to: $backupDir", 'info');
            }

            // Find the package root in the extracted files
            $packageRoot = $extractDir;
            $subdirs = File::directories($extractDir);

            // If there's only one directory at the root and it's name contains the package name
            // use that as the source (handles cases where zip contains a root folder)
            if (count($subdirs) === 1) {
                $dirName = basename($subdirs[0]);
                if (strpos($dirName, basename($packageName)) !== false) {
                    $packageRoot = $subdirs[0];
                }
            }

            // Move the package to vendor directory
            File::makeDirectory($packageDir, 0755, true, true);
            $this->log("Installing package to: $packageDir", 'info');

            // Copy all files from the package root to the vendor directory
            $allFiles = File::allFiles($packageRoot);
            foreach ($allFiles as $file) {
                $relativePath = str_replace($packageRoot . '/', '', $file->getPathname());
                $targetPath = $packageDir . '/' . $relativePath;
                $targetDir = dirname($targetPath);

                if (!is_dir($targetDir)) {
                    File::makeDirectory($targetDir, 0755, true);
                }

                File::copy($file->getPathname(), $targetPath);
            }

            // Copy all directories
            $allDirs = File::directories($packageRoot);
            foreach ($allDirs as $dir) {
                $relativePath = str_replace($packageRoot . '/', '', $dir);
                $targetPath = $packageDir . '/' . $relativePath;

                if (!is_dir($targetPath)) {
                    File::makeDirectory($targetPath, 0755, true);
                }

                File::copyDirectory($dir, $targetPath);
            }

            // Clean up
            File::deleteDirectory($extractDir);
            File::delete($zipFile);

            $this->log("Package $packageName installed successfully", 'info');
            return true;
        } catch (Exception $e) {
            $this->log("Failed to install package: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Check if a package exists in the vendor directory.
     * 
     * @param string $packageName
     * @return bool
     */
    public function packageExists($packageName)
    {
        $packageDir = base_path('vendor/' . $packageName);
        return is_dir($packageDir);
    }

    /**
     * Clear application cache.
     * 
     * @return bool
     */
    public function clearCache()
    {
        try {
            $this->log('Clearing application cache', 'info');
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('view:clear');
            Artisan::call('route:clear');

            $this->log('Cache cleared successfully', 'info');
            return true;
        } catch (Exception $e) {
            $this->log('Failed to clear cache: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Verify license with the update server.
     * 
     * @param array $licenseData
     * @return array|false
     */
    public function verifyLicense($licenseData)
    {
        if (!isset($licenseData['key']) || !isset($licenseData['email'])) {
            $this->log('Invalid license data', 'error');
            return false;
        }

        try {
            $verifyUrl = rtrim(config('updater.update_baseurl'), '/') . '/verify-license';

            $response = Http::post($verifyUrl, [
                'license_key' => $licenseData['key'],
                'email' => $licenseData['email'],
                'product' => config('updater.product_name', 'manga-cms'),
                'domain' => request()->getHost()
            ]);

            if ($response->successful()) {
                $result = $response->json();

                if (isset($result['valid']) && $result['valid']) {
                    $this->license = $licenseData;
                    $this->log('License verification successful', 'info');
                    return $result;
                } else {
                    $this->log('License verification failed: ' . ($result['message'] ?? 'Unknown error'), 'error');
                    return false;
                }
            } else {
                $this->log('License verification request failed: ' . $response->status(), 'error');
                return false;
            }
        } catch (Exception $e) {
            $this->log('Exception during license verification: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}
