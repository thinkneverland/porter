<?php

namespace ThinkNeverland\Porter\Traits;

trait PorterConfigurable
{
    /**
     * Columns that should be randomized during the export process.
     *
     * @var array
     */
    public static $omittedFromPorter = [];

    /**
     * Rows that should not be randomized during the export process.
     *
     * @var array
     */
    public static $keepForPorter = [];

    /**
     * Whether the model should be ignored in the export process.
     *
     * @var bool
     */
    public static $ignoreFromPorter = false;
}
