<?php

namespace Shah\LaravelUpdater\Classes;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Exception;
use Shah\LaravelUpdater\Traits\Loggable;

class Downloader
{
    use Loggable;

    private $requestTimeout = 60;

    public function setTimeOut(int $timeOut = 60)
    {
        $this->requestTimeout = $timeOut;
        return $this;
    }

    public function download(string $filename): string|false
    {
        $tmpDir = base_path(config('updater.tmp_directory', 'updater_tmp'));

        if (!is_dir($tmpDir)) {
            File::makeDirectory($tmpDir, 0755, true);
        }

        try {
            $localFile = $tmpDir . '/' . $filename;
            $remoteFileUrl = rtrim(config('updater.update_baseurl'), '/') . '/' . $filename;

            $this->log('Downloading update from: ' . $remoteFileUrl, 'info');

            $headers = [];
            $license = session('updater_license'); // Assuming you store license in session
            if (config('updater.requires_license', true) && $license) {
                $headers = [
                    'X-License-Key' => $license['key'] ?? null,
                    'X-License-Name' => $license['name'] ?? null,
                    'X-License-Email' => $license['email'] ?? null,
                    'X-Domain' => request()->getHost()
                ];
            }

            $response = Http::withOptions(['stream' => true])
                ->timeout($this->requestTimeout)
                ->withHeaders($headers)
                ->get($remoteFileUrl);

            if ($response->successful()) {
                $handle = fopen($localFile, 'w');
                foreach ($response->body()->getIterator() as $chunk) {
                    fwrite($handle, $chunk);
                }
                fclose($handle);
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
}
