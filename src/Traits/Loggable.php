<?php

namespace Shah\LaravelUpdater\Traits;

use Illuminate\Support\Facades\Log;

trait Loggable
{
    private $messages;

    public function log($message, $type = 'info')
    {
        $header = config('app.name', 'Laravel') . 'Updater - ';

        $this->messages = [
            'message' => $message,
            'type' => $type
        ];

        if ($type == 'info') {
            Log::info($header . '[info] ' . $message);
        } elseif ($type == 'warn' || $type == 'warning') {
            Log::warning($header . '[warn] ' . $message);
        } elseif ($type == 'err' || $type == 'error') {
            Log::error($header . '[err] ' . $message);
        }
    }

    public function getMessages()
    {
        return $this->messages;
    }
}
