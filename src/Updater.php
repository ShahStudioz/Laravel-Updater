<?php

namespace Shah\LaravelUpdater\Facades;

use Illuminate\Support\Facades\Facade;

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
