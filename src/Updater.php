<?php

namespace Shah\LaravelUpdater;

use Illuminate\Support\Facades\Artisan;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Shah\LaravelUpdater\Classes\Downloader;
use Shah\LaravelUpdater\Classes\BackupManager;
use Shah\LaravelUpdater\Classes\Installer;
use Shah\LaravelUpdater\Classes\RecoveryManager;
use Shah\LaravelUpdater\Traits\Loggable;
use Shah\LaravelUpdater\Traits\Versionable;

class Updater
{
    use Loggable, Versionable;

    private bool $optimizeEnv = true;
    private int $requestTimeout = 60;

    private array $versionDeatils = [];

    public function __construct(
        protected Downloader $downloader,
        protected BackupManager $backupManager,
        protected Installer $installer,
        protected RecoveryManager $recoveryManager
    ) {
        $this->optimizeEnvironment();
    }

    /**
     * Disable the environment optimization
     */
    public function disableOptimization(): self
    {
        $this->optimizeEnv = false;

        return $this;
    }

    /**
     * Optimize environment for update process.
     */
    protected function optimizeEnvironment(): void
    {
        if ($this->optimizeEnv) {
            // unlimited max execution time
            set_time_limit(0);

            // increase memory_limit
            ini_set('memory_limit', '-1');

            // increase max_execution_time
            ini_set('max_execution_time', 3600);
        }
    }

    public function checkPermission(): bool
    {
        $allowedUsers = config('updater.allow_users_id');

        if ($allowedUsers === false) {
            return true;
        }

        return is_array($allowedUsers) && Auth::check() && in_array(Auth::id(), $allowedUsers);
    }

    /**
     * Set a Custom timeout for requests
     */
    public function setTimeOut(int $timeOut = 60): self
    {
        $this->requestTimeout = $timeOut;
        $this->downloader->setTimeOut($timeOut);
        return $this;
    }


    /**
     * Check for available updates by making a request to the update server
     *
     * @param string $url Optional custom URL to check for updates. If empty, uses configured base URL
     * @param array $requestHeaders Optional array of headers to include in the request
     * @param string $requestMethod HTTP method to use for request (post or get), defaults to post
     *
     * @return array Returns an array with:
     *               - On success with updates: Update data from server including version
     *               - On success no updates: ['status' => true, 'message' => 'No updates Available']
     *               - On failure: ['status' => false, 'message' => error message, 'error' => error details]
     */
    public function checkForUpdates(string $url = '', array $requestHeaders = [], string $requestMethod = 'post'): array
    {
        if (!config('updater.online_check', true)) {
            $this->log('Online update check is disabled', 'info');
            return [
                'status' => false,
                'message' => 'Online update check is disabled',
            ];
        }

        try {
            $currentVersion = $this->getCurrentVersion();
            $this->log('Checking for updates. Current version: ' . $currentVersion, 'info');

            if (!empty($url)) {
                $updateUrl = $url;
            } else {
                $updateUrl = rtrim(config('updater.update_baseurl'), '/') . '/updates.json';
            }

            $headers = [];
            if (!empty($requestHeaders)) {
                $headers = $requestHeaders;
            }

            $request = Http::timeout($this->requestTimeout)->withHeaders($headers);

            if ($requestMethod == 'post') {
                $response = $request->post($updateUrl);
            } else {
                $response = $request->get($updateUrl);
            }

            if ($response->successful()) {
                $updateData = $response->json();

                if (isset($updateData['version']) && version_compare($updateData['version'], $currentVersion, '>')) {
                    $this->log('New version available: ' . $updateData['version'], 'info');
                    return $updateData;
                } else {
                    $this->log('No updates available', 'info');
                    return [
                        'status' => true,
                        'message' => 'No updates Available',
                    ];
                }
            } else {
                $this->log('Failed to check for updates: ' . $response->status(), 'error');
                return [
                    'status' => false,
                    'message' => 'Failed to check for updates',
                    'error' => $response->status(),
                ];
            }
        } catch (Exception $e) {
            $this->log('Exception while checking for updates: ' . $e->getMessage(), 'error');
            return [
                'status' => false,
                'message' => 'Failed to check for updates',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Downlaod the update zip file
     */
    private function download(string $filename): string|false
    {
        return $this->downloader->download($filename);
    }

    public function installFromZip(string $zipFile, $versionUpdate): bool
    {
        if (!file_exists($zipFile)) {
            $this->log('Update file not found: ' . $zipFile, 'error');
            return false;
        }

        try {
            Artisan::call('down');
            $this->log('Maintenance mode enabled', 'info');

            $backupPath = $this->backupManager->createBackup($zipFile);
            if (!$backupPath) {
                $this->log('Backup failed, update aborted.', 'error');
                Artisan::call('up');
                $this->log('Maintenance mode disabled after error', 'info');
                return false;
            }

            $result = $this->installer->install($zipFile, $versionUpdate);

            Artisan::call('up');
            $this->log('Maintenance mode disabled', 'info');

            return $result;
        } catch (Exception $e) {
            $this->log('Exception during installation: ' . $e->getMessage(), 'error');
            $this->recoveryManager->recover();
            Artisan::call('up');
            $this->log('Maintenance mode disabled after error', 'info');
            return false;
        }
    }

    public function recovery(): bool
    {
        return $this->recoveryManager->recover();
    }

    /**
     * Download install the latest update from Zip Url
     * 
     * @param string $zipUrl Url of the zip to download
     * @param string $versionUpdate update the version (a zip could be used to simply replace directories without version change)
     */
    public function update(string $zipUrl, bool $versionUpdate = false): bool
    {
        $this->log('Starting update process. Current version: ' . $this->getCurrentVersion(), 'info');

        if (!$this->checkPermission()) {
            $this->log('Permission denied', 'error');
            return false;
        }

        $zipFile = $this->download($zipUrl);

        if (!$zipFile) {
            $this->log('Failed to download update package', 'error');
            return false;
        }

        return $this->installFromZip($zipFile, $versionUpdate);
    }

    public function installPackage(string $packageName, string $zipFile): bool
    {
        // You can keep this method here or move it to a dedicated PackageManager class if needed.
        if (!file_exists($zipFile)) {
            $this->log("Package file not found: $zipFile", 'error');
            return false;
        }

        $vendorDir = base_path('vendor');
        $packageDir = $vendorDir . '/' . $packageName;
        $extractDir = dirname($zipFile) . '/package_extract';

        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipFile) !== true) {
                $this->log('Could not open the package zip file', 'error');
                return false;
            }

            $zip->extractTo($extractDir);
            $zip->close();

            if (is_dir($packageDir)) {
                $backupDir = $vendorDir . '/backup_' . str_replace('/', '_', $packageName) . '_' . date('Ymd_His');
                rename($packageDir, $backupDir);
                $this->log("Backed up existing package to: $backupDir", 'info');
            }

            $packageRoot = $extractDir;
            $subdirs = glob($extractDir . '/*', GLOB_ONLYDIR);

            if (count($subdirs) === 1) {
                $dirName = basename($subdirs[0]);
                if (strpos($dirName, basename($packageName)) !== false) {
                    $packageRoot = $subdirs[0];
                }
            }

            if (!is_dir($packageDir)) {
                mkdir($packageDir, 0755, true, true);
            }
            $this->log("Installing package to: $packageDir", 'info');

            $this->recursiveCopy($packageRoot, $packageDir);

            File::deleteDirectory($extractDir);
            File::delete($zipFile);

            $this->log("Package $packageName installed successfully", 'info');
            return true;
        } catch (Exception $e) {
            $this->log("Failed to install package: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private function recursiveCopy($src, $dst)
    {
        $dir = opendir($src);
        @mkdir($dst);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . '/' . $file)) {
                    $this->recursiveCopy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    public function packageExists(string $packageName): bool
    {
        $packageDir = base_path('vendor/' . $packageName);
        return is_dir($packageDir);
    }

    public function clearCache(): bool
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
}
