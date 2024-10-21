<?php

namespace ThinkNeverland\Porter\Traits;

use Faker\Factory as Faker;

trait PorterConfigurable
{
    /**
     * Randomize the given row's data based on the model's settings.
     *
     * This method iterates over each column in the provided row and checks if the column
     * is listed in the model's `omittedFromPorter` configuration. If it is, the column's
     * value is randomized using Faker based on the column name and data type.
     *
     * @param array $row The row of data.
     * @return array The modified row with randomized data.
     */
    public function porterRandomizeRow(array $row)
    {
        $faker = Faker::create();

        // Retrieve the list of columns to be randomized from the model's configuration
        $omittedColumns = $this->getPorterConfig('omittedFromPorter', []);

        // Iterate over each column in the row
        foreach ($row as $key => $value) {
            // Check if the column should be randomized
            if (in_array($key, $omittedColumns)) {
                // Randomize the column value based on common column names and data types
                if (stripos($key, 'email') !== false) {
                    $row[$key] = $faker->safeEmail;
                } elseif (stripos($key, 'name') !== false) {
                    $row[$key] = $faker->name;
                } elseif (stripos($key, 'user') !== false) {
                    $row[$key] = $faker->userName;
                } elseif (stripos($key, 'phone') !== false) {
                    $row[$key] = $faker->phoneNumber;
                } elseif (stripos($key, 'address') !== false) {
                    $row[$key] = $faker->address;
                } elseif (stripos($key, 'city') !== false) {
                    $row[$key] = $faker->city;
                } elseif (stripos($key, 'country') !== false) {
                    $row[$key] = $faker->country;
                } elseif (stripos($key, 'date') !== false) {
                    $row[$key] = $faker->date;
                } elseif (stripos($key, 'url') !== false || stripos($key, 'link') !== false) {
                    $row[$key] = $faker->url;
                } elseif (stripos($key, 'password') !== false) {
                    $row[$key] = $faker->regexify('[A-Za-z0-9]{16,}'); // At least 16 characters
                } elseif (stripos($key, 'token') !== false) {
                    $row[$key] = $faker->sha256; // Use a valid token length
                } elseif (is_int($value)) {
                    $row[$key] = $faker->randomNumber();
                } elseif (is_float($value)) {
                    $row[$key] = $faker->randomFloat(2, 0, 1000);
                } elseif (is_bool($value)) {
                    $row[$key] = $faker->boolean;
                } elseif (is_string($value)) {
                    $row[$key] = $faker->word;
                } else {
                    $row[$key] = $faker->text;
                }
            }
        }

        return $row;
    }

    /**
     * Check if a row should be skipped from randomization based on model settings.
     *
     * @param array $row The row of data.
     * @return bool True if the row should be kept as is, false otherwise.
     */
    public function porterShouldKeepRow(array $row)
    {
        // Retrieve the list of row IDs to keep from the model's configuration
        $keepRows = $this->getPorterConfig('keepForPorter', []);

        // Check if the row's ID is in the list of IDs to keep
        return isset($row['id']) && in_array($row['id'], $keepRows);
    }

    /**
     * Check if this model/table should be ignored during export.
     *
     * @return bool True if the model/table should be ignored, false otherwise.
     */
    public function porterShouldIgnoreModel()
    {
        // Retrieve the ignore flag from the model's configuration
        return $this->getPorterConfig('ignoreFromPorter', false);
    }

    /**
     * Dynamically access a Porter-specific configuration property from the model.
     *
     * This helper method checks if a specific configuration property exists on the model.
     * If it does, the property's value is returned; otherwise, a default value is returned.
     *
     * @param string $property The property name (e.g., 'omittedFromPorter').
     * @param mixed $default The default value to return if the property is not set.
     * @return mixed The property value or the default.
     */
    protected function getPorterConfig(string $property, $default = null)
    {
        // Check if the property exists in the model and return its value or the default
        return property_exists($this, $property) ? $this->{$property} : $default;
    }
}
