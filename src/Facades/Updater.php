<?php

namespace Shah\LaravelUpdater\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Laravel Auto Updater Facade
 *
 * @method static bool checkPermission() Check if current user has permission to perform updates
 * @method static void log(string $message, string $type = 'info') Log a message
 * @method static array getMessages() Get all logged messages
 * @method static string getCurrentVersion() Get the current version
 * @method static bool setCurrentVersion(string $version) Set the current version
 * @method static array|null checkForUpdates() Check for updates from the remote server
 * @method static self setLicense(array $license) Set license information
 * @method static self setTimeOut(int $timeOut = 60) Set a custom timeout for request
 * @method static string|false download(string $filename) Download an update package
 * @method static bool installFromZip(string $zipFile) Install an update from a local zip file
 * @method static bool update() Update the system with the latest version
 * @method static bool installPackage(string $packageName, string $zipFile) Install a package to vendor directory
 * @method static bool packageExists(string $packageName) Check if a package exists in the vendor directory
 * @method static bool clearCache() Clear application cache
 * @method static array|false verifyLicense(array $licenseData) Verify license with the update server
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
