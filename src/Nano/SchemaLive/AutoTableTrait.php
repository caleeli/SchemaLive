<?php
namespace Nano\SchemaLive;

/**
 * Configure model from database schema
 *
 * @author David Callizaya <davidcallizaya@gmail.com>
 */
trait AutoTableTrait
{
    
    private static $attConfig = null;

    /**
     * Set the schema model configuration.
     *
     * @param array $configuration
     */
    protected static function setAttConfiguration(array $configuration)
    {
        self::$attConfig = $configuration;
    }

    /**
     * Return true if the configuration was not loaded.
     *
     * @return bool
     */
    protected static function isAttConfigurationEmpty()
    {
        return self::$attConfig === null;
    }

    /**
     * Get the array of guarded columns.
     *
     * @return array
     */
    public function getGuarded()
    {
        $table = $this->getTable();
        return array_merge(
            parent::getGuarded(),
            isset(self::$attConfig['fields'][$table])
                ? self::$attConfig['fields'][$table]['guarded'] : []
        );
    }

    /**
     * Get the array of casts of columns.
     *
     * @return array
     */
    public function getCasts()
    {
        $table = $this->getTable();
        return array_merge(
            parent::getCasts(),
            isset(self::$attConfig['fields'][$table])
                ? self::$attConfig['fields'][$table]['casts'] : []
        );
    }

    /**
     * Get validation rules.
     *
     * @return array
     */
    protected function getRules()
    {
        $table = $this->getTable();
        return isset(self::$attConfig['fields'][$table])
                ? self::$attConfig['fields'][$table]['rules'] : [];
    }

    /**
     * Get a relationship by table and name.
     *
     * @param string $table
     * @param string $name
     *
     * @return array
     */
    private function getAttRelationship($table, $name)
    {
        return isset(self::$attConfig['relationships'][$table])
            && isset(self::$attConfig['relationships'][$table][$name])
            ? self::$attConfig['relationships'][$table][$name] : null;
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
        $relationship = $this->getAttRelationship($this->getTable(), $method);
        if ($relationship) {
            $relationshipType = $relationship[0];
            $relationshipOptions = $relationship[1];
            return $this->$relationshipType(...$relationshipOptions);
        } else {
            return parent::__call($method, $parameters);
        }
    }
}
