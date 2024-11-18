<?php

namespace ThinkNeverland\Porter\Traits;

use Faker\Factory as Faker;

trait PorterConfigurable
{
    private static $fakerInstance = null;

    private $columnTypeCache = [];

    /**
     * Get or create Faker instance
     */
    protected function getFaker()
    {
        if (self::$fakerInstance === null) {
            self::$fakerInstance = Faker::create();
        }

        return self::$fakerInstance;
    }

    /**
     * Get column type with caching
     */
    protected function getColumnType($columnName)
    {
        if (!isset($this->columnTypeCache[$columnName])) {
            $type                               = $this->determineColumnType($columnName);
            $this->columnTypeCache[$columnName] = $type;
        }

        return $this->columnTypeCache[$columnName];
    }

    /**
     * Determine column type based on name
     */
    protected function determineColumnType($columnName)
    {
        $types = [
            'email'    => 'email',
            'name'     => 'name',
            'phone'    => 'phone',
            'address'  => 'address',
            'city'     => 'city',
            'country'  => 'country',
            'date'     => 'date',
            'url'      => 'url',
            'link'     => 'url',
            'password' => 'password',
            'token'    => 'token',
        ];

        foreach ($types as $key => $type) {
            if (stripos($columnName, $key) !== false) {
                return $type;
            }
        }

        return 'string';
    }

    /**
     * Optimized randomize row method
     */
    public function porterRandomizeRow(array $row)
    {
        $faker          = $this->getFaker();
        $omittedColumns = $this->getPorterConfig('omittedFromPorter', []);

        foreach ($row as $key => $value) {
            if (!in_array($key, $omittedColumns)) {
                continue;
            }

            $type      = $this->getColumnType($key);
            $row[$key] = $this->generateFakeValue($type, $value, $faker);
        }

        return $row;
    }

    /**
     * Generate fake value based on type
     */
    protected function generateFakeValue($type, $value, $faker)
    {
        switch ($type) {
            case 'email': return $faker->safeEmail;
            case 'name': return $faker->name;
            case 'phone': return $faker->phoneNumber;
            case 'address': return $faker->address;
            case 'city': return $faker->city;
            case 'country': return $faker->country;
            case 'date': return $faker->date;
            case 'url': return $faker->url;
            case 'password': return $faker->regexify('[A-Za-z0-9]{16,}');
            case 'token': return $faker->sha256;
            default: return $this->generateDefaultValue($value, $faker);
        }
    }

    /**
     * Generate default value based on type
     */
    protected function generateDefaultValue($value, $faker)
    {
        switch (gettype($value)) {
            case 'integer': return $faker->randomNumber();
            case 'double': return $faker->randomFloat(2, 0, 1000);
            case 'boolean': return $faker->boolean;
            case 'string': return $faker->word;
            default: return $faker->text;
        }
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
