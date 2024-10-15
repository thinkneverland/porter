<?php

namespace ThinkNeverland\Porter\Facades;

use Illuminate\Support\Facades\Facade;

class Porter extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'porter';
    }
}
