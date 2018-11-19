<?php

namespace Nano\SchemaLive;

use ReflectionClass;

/**
 * Configure model from database schema
 *
 * @author David Callizaya <davidcallizaya@gmail.com>
 */
trait AutoTableTrait
{
    
    private static $attConfig = [];

    /**
     * Set the schema model configuration.
     *
     * @param string $connection
     * @param array $configuration
     */
    protected static function setAttConfiguration($connection, array $configuration)
    {
        self::$attConfig[$connection] = $configuration;
    }

    /**
     * Get the schema model configuration.
     *
     * @param string $connection
     *
     * @return array
     */
    protected static function getAttConfiguration($connection)
    {
        return self::$attConfig[$connection];
    }

    /**
     * Return true if the configuration was not loaded.
     *
     * @return bool
     */
    protected static function isAttConfigurationEmpty($connection)
    {
        return !isset(self::$attConfig[$connection]);
    }

    /**
     * Get the array of guarded columns.
     *
     * @return array
     */
    public function getGuarded()
    {
        $connection = $this->getConnection()->getName();
        $table = $this->getTable();
        return array_merge(
            parent::getGuarded(),
            isset(self::$attConfig[$connection]['fields'][$table])
                ? self::$attConfig[$connection]['fields'][$table]['guarded'] : []
        );
    }

    /**
     * Get the array of casts of columns.
     *
     * @return array
     */
    public function getCasts()
    {
        $connection = $this->getConnection()->getName();
        $table = $this->getTable();
        return array_merge(
            parent::getCasts(),
            isset(self::$attConfig[$connection]['fields'][$table])
                ? self::$attConfig[$connection]['fields'][$table]['casts'] : []
        );
    }

    /**
     * Get validation rules.
     *
     * @return array
     */
    protected function getRules()
    {
        $connection = $this->getConnection()->getName();
        $table = $this->getTable();
        return isset(self::$attConfig[$connection]['fields'][$table])
                ? self::$attConfig[$connection]['fields'][$table]['rules'] : [];
    }

    /**
     * Get a relationship by table and name.
     *
     * @param string $table
     * @param string $name
     *
     * @return array
     */
    private function getAttRelationship($name)
    {
        $connection = $this->getConnection()->getName();
        $table = $this->getTable();
        return isset(self::$attConfig[$connection]['relationships'][$table])
            && isset(self::$attConfig[$connection]['relationships'][$table][$name])
            ? self::$attConfig[$connection]['relationships'][$table][$name] : null;
    }

    /**
     * Add relationships from DB.
     *
     * @param string $method
     * @param array $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        $relationship = $this->getAttRelationship($method);
        if ($relationship) {
            $relationshipType = $relationship[0];
            $relationshipOptions = $relationship[1];
            return $this->$relationshipType(...$relationshipOptions);
        } else {
            return parent::__call($method, $parameters);
        }
    }

    /**
     * Sync the original attributes with the current.
     *
     * @return $this
     */
    public function syncOriginal()
    {
        $connection = $this->getConnection()->getName();
        $table = $this->getTable();
        // Add the attributes from the table
        $this->attributes = array_merge(
            isset(self::$attConfig[$connection]['fields'][$table])
                ? self::$attConfig[$connection]['fields'][$table]['attributes'] : [],
            $this->attributes
        );
        return parent::syncOriginal();
    }

    /**
     * Get the static connection name for the model.
     *
     * @return string
     */
    protected static function getStaticConnectionName()
    {
        $reflection = new ReflectionClass(static::class);
        $properties = $reflection->getDefaultProperties();
        $connection = isset($properties['connection'])
            ? $properties['connection']
            : config('database.default');
        return $connection;
    }
}
