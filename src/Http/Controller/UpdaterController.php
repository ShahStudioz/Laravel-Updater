<?php

namespace Shah\LaravelUpdater\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shah\LaravelUpdater\Facades\Updater;
use Illuminate\Support\Facades\File;

class UpdaterController extends Controller
{
    /**
     * Show the updater dashboard.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        if (!Updater::checkPermission()) {
            return abort(403, 'You do not have permission to access the updater');
        }

        $currentVersion = Updater::getCurrentVersion();
        $updateAvailable = null;

        if (config('updater.online_check', true)) {
            $updateInfo = Updater::checkForUpdates();
            if ($updateInfo) {
                $updateAvailable = $updateInfo;
            }
        }

        return view('updater::index', [
            'currentVersion' => $currentVersion,
            'updateAvailable' => $updateAvailable,
            'requiresLicense' => config('updater.requires_license', false)
        ]);
    }

    /**
     * Check for updates.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function check(Request $request)
    {
        if (!Updater::checkPermission()) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        // Set license if provided
        if ($request->has('license_key') && $request->has('license_email')) {
            Updater::setLicense([
                'key' => $request->input('license_key'),
                'email' => $request->input('license_email'),
                'name' => $request->input('license_name')
            ]);
        }

        $updateInfo = Updater::checkForUpdates();

        if ($updateInfo) {
            return response()->json([
                'update_available' => true,
                'version' => $updateInfo['version'],
                'description' => $updateInfo['description'] ?? 'No description available'
            ]);
        }

        return response()->json([
            'update_available' => false,
            'current_version' => Updater::getCurrentVersion()
        ]);
    }

    /**
     * Run the update process.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        if (!Updater::checkPermission()) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        // Set license if provided
        if ($request->has('license_key') && $request->has('license_email')) {
            Updater::setLicense([
                'key' => $request->input('license_key'),
                'email' => $request->input('license_email'),
                'name' => $request->input('license_name')
            ]);
        }

        $result = Updater::update();
        $messages = Updater::getMessages();

        if ($result) {
            return response()->json([
                'success' => true,
                'message' => 'Update completed successfully',
                'version' => Updater::getCurrentVersion(),
                'log' => $messages
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Update failed',
            'log' => $messages
        ]);
    }

    /**
     * Upload and install an update package manually.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function uploadAndInstall(Request $request)
    {
        if (!Updater::checkPermission()) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $request->validate([
            'update_file' => 'required|file|mimes:zip'
        ]);

        $file = $request->file('update_file');
        $tmpDir = base_path() . '/' . config('updater.tmp_directory', 'updater_tmp');

        if (!is_dir($tmpDir)) {
            File::makeDirectory($tmpDir, 0755, true);
        }

        $zipPath = $tmpDir . '/' . $file->getClientOriginalName();
        $file->move($tmpDir, $file->getClientOriginalName());

        $result = Updater::installFromZip($zipPath);
        $messages = Updater::getMessages();

        if ($result) {
            return response()->json([
                'success' => true,
                'message' => 'Manual update completed successfully',
                'version' => Updater::getCurrentVersion(),
                'log' => $messages
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Manual update failed',
            'log' => $messages
        ]);
    }

    /**
     * Install a package to the vendor directory.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function installPackage(Request $request)
    {
        if (!Updater::checkPermission()) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $request->validate([
            'package_name' => 'required|string',
            'package_file' => 'required|file|mimes:zip'
        ]);

        $packageName = $request->input('package_name');
        $file = $request->file('package_file');
        $tmpDir = base_path() . '/' . config('updater.tmp_directory', 'updater_tmp');

        if (!is_dir($tmpDir)) {
            File::makeDirectory($tmpDir, 0755, true);
        }

        $zipPath = $tmpDir . '/package_' . $file->getClientOriginalName();
        $file->move($tmpDir, 'package_' . $file->getClientOriginalName());

        $result = Updater::installPackage($packageName, $zipPath);
        $messages = Updater::getMessages();

        if ($result) {
            return response()->json([
                'success' => true,
                'message' => "Package $packageName installed successfully",
                'log' => $messages
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => "Failed to install package $packageName",
            'log' => $messages
        ]);
    }

    /**
     * Verify license.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function verifyLicense(Request $request)
    {
        if (!Updater::checkPermission()) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $request->validate([
            'license_key' => 'required|string',
            'license_email' => 'required|email'
        ]);

        $licenseData = [
            'key' => $request->input('license_key'),
            'email' => $request->input('license_email'),
            'name' => $request->input('license_name')
        ];

        $result = Updater::verifyLicense($licenseData);

        if ($result) {
            return response()->json([
                'success' => true,
                'message' => 'License verified successfully',
                'license_info' => $result
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'License verification failed'
        ]);
    }
}
