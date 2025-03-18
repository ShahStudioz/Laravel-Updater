<?php

namespace Shah\LaravelUpdater\Classes;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Exception;
use Shah\LaravelUpdater\Traits\Loggable;

class VendorUpdater
{
    use Loggable;

    protected $updater;

    public function __construct($updater)
    {
        $this->updater = $updater;
    }

    public function update(string $extractDir): bool
    {
        $this->log('Attempting to update vendor directory.', 'info');
        $vendorDirInZip = $extractDir . '/vendor';
        $composerJsonInZip = $extractDir . '/composer.json';
        $composerLockInZip = $extractDir . '/composer.lock';
        $baseVendorDir = base_path('vendor');
        $baseComposerJson = base_path('composer.json');
        $baseComposerLock = base_path('composer.lock');

        if (File::isDirectory($vendorDirInZip) && File::exists($composerJsonInZip) && File::exists($composerLockInZip)) {
            $this->log('Replacing the entire vendor directory.', 'warning');

            // Backup current vendor, composer.json, and composer.lock
            $backupDir = base_path('backup_vendor_' . date('Ymd_His'));
            File::makeDirectory($backupDir . '/vendor', 0755, true);
            File::copyDirectory($baseVendorDir, $backupDir . '/vendor');
            if (File::exists($baseComposerJson)) {
                File::copy($baseComposerJson, $backupDir . '/composer.json');
            }
            if (File::exists($baseComposerLock)) {
                File::copy($baseComposerLock, $backupDir . '/composer.lock');
            }
            $this->updater->log('Backed up vendor directory and composer files to: ' . $backupDir, 'info');

            // Delete current vendor directory
            File::deleteDirectory($baseVendorDir);

            // Copy new vendor directory
            File::copyDirectory($vendorDirInZip, $baseVendorDir);
            $this->updater->log('Copied new vendor directory.', 'info');

            // Replace composer.json and composer.lock
            File::copy($composerJsonInZip, $baseComposerJson);
            File::copy($composerLockInZip, $baseComposerLock);
            $this->updater->log('Updated composer.json and composer.lock.', 'info');

            // Run composer install
            $this->updater->log('Running composer install.', 'info');
            $process = new Process(['composer', 'install', '--no-dev', '--optimize-autoloader'], base_path());
            $process->setTimeout(3600); // Increased timeout for composer
            $process->run();

            if (!$process->isSuccessful()) {
                $this->updater->log('Composer install failed: ' . $process->getErrorOutput(), 'error');
                return false;
            }
            $this->updater->log('Composer install completed successfully.', 'info');
            return true;
        } else {
            $this->updater->log('Vendor directory update skipped. Ensure vendor directory and composer files are in the update zip.', 'info');
            return true;
        }
    }
}
