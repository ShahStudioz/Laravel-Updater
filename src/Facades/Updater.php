<?php

namespace Shah\LaravelUpdater\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Laravel Updater Service Class
 * 
 * Class for handling Laravel application updates including version checking, 
 * downloading updates, installation, backups and recovery.
 * 
 * @package Shah\LaravelUpdater
 * 
 * @method bool disableOptimization() Disable environment optimization for update process
 * @method void optimizeEnvironment() Optimize environment settings for update process
 * @method bool checkPermission() Check if current user has permission to perform updates
 * @method self setTimeOut(int $timeOut = 60) Set custom timeout for update requests
 * @method array checkForUpdates(string $url = '', array $requestHeaders = [], string $requestMethod = 'post') Check for available updates
 * @method string|false download(string $filename) Download update zip file
 * @method bool installFromZip(string $zipFile) Install update from a zip file
 * @method bool recovery() Recover the application to previous state
 * @method bool update() Download and install the latest update
 * @method bool installPackage(string $packageName, string $zipFile) Install a package from zip file
 * @method bool packageExists(string $packageName) Check if package exists in vendor directory
 * @method bool clearCache() Clear application cache
 * @method void recursiveCopy($src, $dst) Recursively copy files from source to destination
 * 
 * @property Downloader $downloader Downloader instance for handling file downloads
 * @property BackupManager $backupManager BackupManager instance for handling backups
 * @property Installer $installer Installer instance for handling installations
 * @property RecoveryManager $recoveryManager RecoveryManager instance for handling recovery
 * @property bool $optimizeEnv Flag for environment optimization
 * @property int $requestTimeout Request timeout in seconds
 * @property array $versionDeatils Version details array
 * 
 * @uses Loggable, Versionable
 */

class Updater extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'updater';
    }
}
