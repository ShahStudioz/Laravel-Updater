<?php

namespace Shah\LaravelUpdater\Traits;

use Illuminate\Support\Facades\File;
use Exception;

trait Versionable
{
    protected $metadataFile = 'version.json';

    public function getCurrentVersion(): string
    {
        $metadataPath = base_path($this->metadataFile);

        if (!File::exists($metadataPath)) {
            $this->log('Metadata file not found. Creating with default version 1.0.0', 'info');
            $this->saveMetadata(['version' => '1.0.0', 'last_update' => null, 'logs' => '', 'recovery_path' => null]);
            return '1.0.0';
        }

        $metadata = json_decode(File::get($metadataPath), true);
        return $metadata['version'] ?? '1.0.0';
    }

    public function setCurrentVersion($version)
    {
        $metadata = $this->getMetadata();
        $metadata['version'] = $version;
        return $this->saveMetadata($metadata);
    }

    protected function getMetadata()
    {
        $metadataPath = base_path($this->metadataFile);
        if (!File::exists($metadataPath)) {
            return ['version' => '1.0.0', 'last_update' => null, 'logs' => '', 'recovery_path' => null];
        }
        return json_decode(File::get($metadataPath), true);
    }

    protected function saveMetadata(array $metadata)
    {
        $metadataPath = base_path($this->metadataFile);
        try {
            File::put($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT));
            return true;
        } catch (Exception $e) {
            $this->log('Failed to update metadata file: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    protected function addUpdateLog($message, $type = 'info')
    {
        $metadata = $this->getMetadata();
        $metadata['logs'] = [
            'timestamp' => now()->toDateTimeString(),
            'message' => $message,
            'type' => $type
        ];
        return $this->saveMetadata($metadata);
    }

    protected function setRecoveryPath($path)
    {
        $metadata = $this->getMetadata();
        $metadata['recovery_path'] = $path;
        return $this->saveMetadata($metadata);
    }

    protected function getRecoveryPath()
    {
        $metadata = $this->getMetadata();
        return $metadata['recovery_path'];
    }
}
