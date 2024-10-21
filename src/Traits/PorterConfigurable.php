<?php

namespace ThinkNeverland\Porter\Traits;

use Faker\Factory as Faker;

trait PorterConfigurable
{
    /**
     * Randomize the given row's data based on the model's settings.
     *
     * @param array $row The row of data.
     * @return array The modified row.
     */
    public function porterRandomizeRow(array $row)
    {
        $faker = Faker::create();

        // Dynamically access omitted columns from the model, default to an empty array
        $omittedColumns = $this->getPorterConfig('omittedFromPorter', []);

        foreach ($row as $key => $value) {
            if (in_array($key, $omittedColumns)) {
                // Randomize the column value using Faker
                $row[$key] = $faker->word;
            }
        }

        return $row;
    }

    /**
     * Check if a row should be skipped from randomization based on model settings.
     *
     * @param array $row The row of data.
     * @return bool True if the row should be kept as is.
     */
    public function porterShouldKeepRow(array $row)
    {
        // Dynamically access rows to keep from the model, default to an empty array
        $keepRows = $this->getPorterConfig('keepForPorter', []);

        return isset($row['id']) && in_array($row['id'], $keepRows);
    }

    /**
     * Check if this model/table should be ignored during export.
     *
     * @return bool True if the model/table should be ignored.
     */
    public function porterShouldIgnoreModel()
    {
        // Dynamically access the ignore flag from the model, default to false
        return $this->getPorterConfig('ignoreFromPorter', false);
    }

    /**
     * Dynamically access a Porter-specific configuration property from the model.
     *
     * @param string $property The property name (e.g., 'omittedFromPorter').
     * @param mixed $default The default value to return if the property is not set.
     * @return mixed The property value or the default.
     */
    protected function getPorterConfig(string $property, $default = null)
    {
        // Check if the property exists in the model
        return property_exists($this, $property) ? $this->{$property} : $default;
    }
}
