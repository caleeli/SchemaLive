<?php
namespace Nano\SchemaLive;

use Doctrine\Common\Inflector\Inflector;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Types\DateTimeType;

/**
 * Builder, reads the schema and prepares the configuration files
 *
 * @author David Callizaya <davidcallizaya@gmail.com>
 */
class Builder
{

    private $attIndexes = [];
    private $attRelationships = [];
    private $attColumns = [];
    private $modelNamespace= '';

    public function __construct(AbstractSchemaManager $schema, $modelNamespace)
    {
        $this->modelNamespace = $modelNamespace;
        if (!$schema->getDatabasePlatform()->hasDoctrineTypeMappingFor('enum')) {
            $schema->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');
        }
        $tables = $schema->listTableNames();
        foreach ($tables as $table) {
            foreach ($schema->listTableIndexes($table) as $index) {
                $this->readAttIndex($index, $table);
            }
        }
        foreach ($tables as $table) {
            foreach ($schema->listTableForeignKeys($table) as $fk) {
                $this->registerAttFK($fk, $table);
            }
        }
        foreach ($tables as $table) {
            $this->attColumns[$table]['attributes'] = [];
            $this->attColumns[$table]['fillable'] = [];
            $this->attColumns[$table]['guarded'] = [];
            $this->attColumns[$table]['casts'] = [];
            foreach ($schema->listTableColumns($table) as $column) {
                $this->readAttColumn($column, $table);
            }
        }
        $this->findAttManytoManyRelationships();
    }

    /**
     * Read the columns from schema.
     *
     * @param Column $column
     * @param string $table
     */
    private function readAttColumn(Column $column, $table)
    {
        $key = $column->getName();
        $this->attColumns[$table]['attributes'][$key] = $column->getDefault();
        $this->attColumns[$table]['fillable'][] = $key;
        if ($column->getNotnull() && $column->getDefault() === null && !$column->getAutoincrement()) {
            $this->attColumns[$table]['rules'][$key][] = 'required';
        }
        if ($column->getType() === 'string') {
            $this->attColumns[$table]['rules'][$key][] = 'max:' . $column->getLength();
        }
        if ($column->getType() instanceof DateTimeType) {
            $this->attColumns[$table]['rules'][$key][] = 'date';
            $this->attColumns[$table]['casts'][$key] = 'datetime';
        }
        $this->customAttComment($column, $table);
    }

    private function customAttComment(Column $column, $table)
    {
        $colOption = new ColumnOptions($column, $this->attColumns[$table]);
        foreach (explode(';', $column->getComment()) as $comment) {
            $array = explode(':', $comment, 2);
            $method = trim($array[0]);
            $params = isset($array[1]) ? explode(',', trim($array[1])) : [];
            if (is_callable([$colOption, $method])) {
                $colOption->$method(...$params);
            } elseif ($method) {
                $this->attColumns[$table]['rules'][$column->getName()][] = $comment;
            }
        }
    }

    /**
     * Load an index.
     *
     * @param Index $index
     * @param string $table
     */
    private function readAttIndex(Index $index, $table)
    {
        $columns = $index->getColumns();
        sort($columns);
        $key = $table . '.' . json_encode($columns);
        $this->attIndexes[$key] = [
            'name' => $index->getName(),
            'multiplicity' => $index->isUnique() || $index->isPrimary() ? 1 : 'n',
            'isPrimary' => $index->isPrimary(),
        ];
    }

    /**
     * Get and index by table and columns.
     *
     * @param array $columns
     * @param string $table
     *
     * @return array
     */
    private function getAttIndex(array $columns, $table)
    {
        sort($columns);
        $jcolumns = json_encode($columns);
        $key = $table . '.' . $jcolumns;
        return isset($this->attIndexes[$key]) ? $this->attIndexes[$key] : null;
    }

    /**
     * Register a foreign key.
     *
     * @param ForeignKeyConstraint $fk
     * @param string $table
     */
    private function registerAttFK(ForeignKeyConstraint $fk, $table)
    {
        $foreignTable = $fk->getForeignTableName();
        $foreign = self::guess_model($this->modelNamespace, $foreignTable);
        $local = self::guess_model($this->modelNamespace, $table);

        $localColumns = $fk->getColumns();
        $foreignColumns = $fk->getForeignColumns();
        $foreignIndex = $this->getAttIndex($foreignColumns, $foreignTable);
        $localIndex = $this->getAttIndex($localColumns, $table);

        $this->addAttRelationship(
            [
            'table' => $table,
            'class' => $local,
            'index' => $localIndex,
            'columns' => count($localColumns) === 1 ? $localColumns[0] : $localColumns,
            ], [
            'table' => $foreignTable,
            'class' => $foreign,
            'index' => $foreignIndex,
            'columns' => count($foreignColumns) === 1 ? $foreignColumns[0] : $foreignColumns,
            ]
        );
    }

    /**
     * Guess the name of a relationship.
     *
     * @param array $from
     * @param array $to
     *
     * @return string
     */
    private function guessAttRelationName(array $from, array $to)
    {
        return lcfirst(self::studly($from['index'] && $from['index']['name'] !== 'PRIMARY' ? $from['index']['name'] : $to['table']));
    }

    /**
     * Register a relationship.
     *
     * @param array $from
     * @param array $to
     *
     * @return void
     */
    private function addAttRelationship(array $from, array $to)
    {
        $table = $from['table'];
        $key = $this->guessAttRelationName($from, $to);
        if (isset($this->attRelationships[$table][$key])) {
            return;
        }
        //n:m
        $isPrimary = $from['index'] ? $from['index']['isPrimary'] : false;
        //$n = $from['index'] ? $from['index']['multiplicity'] : 'n';
        $m = $to['index'] ? $to['index']['multiplicity'] : 'n';

        $relationship = null;
        if ($isPrimary && $m == 1 && $to['table'] != $table) {
            $relationship = ['hasOne', [$to['class'], $to['columns'], $from['columns']]];
        } elseif (!$isPrimary && $m == 1) {
            $relationship = ['belongsTo', [$to['class'], $from['columns'], $to['columns'], $key]];
        } elseif ($m == 'n') {
            $relationship = ['hasMany', [$to['class'], $to['columns'], $from['columns']]];
        }
        if ($relationship) {
            $relationship[2] = [$from, $to];
            $this->attRelationships[$table][$key] = $relationship;
        }
        $this->addAttRelationship($to, $from);
    }

    /**
     * Find many to many relationships.
     *
     */
    private function findAttManytoManyRelationships()
    {
        foreach ($this->attRelationships as $relationships) {
            foreach ($relationships as $relationship) {
                if ($relationship[0] === 'hasMany') {
                    $this->matchAttManytoManyRelationship($relationship);
                }
            }
        }
    }

    /**
     * Match to hasMany relationships and make a ManyToMany relationship.
     *
     * @param array $refRelationship
     */
    private function matchAttManytoManyRelationship(array $refRelationship)
    {
        foreach ($this->attRelationships as $table => $relationships) {
            foreach ($relationships as $key => $relationship) {
                if ($relationship[0] === 'hasMany' && $relationship[2][1]['table'] === $refRelationship[2][1]['table'] && $relationship !== $refRelationship) {
                    $model1 = $refRelationship[2][0];
                    $model2 = $refRelationship[2][1];
                    $model2b = $relationship[2][0];
                    $model3 = $relationship[2][1];
                    $key = $this->guessAttRelationName($model2b, $model3)
                        . (isset($this->attRelationships[$table][$key]) ? '2' : '');
                    $this->attRelationships[$table][$key] = [
                        'belongsToMany',
                        [$model3['class'], $model2['table'], $model1['columns'], $model3['columns'], $model2['columns'], $model2b['columns'], $key],
                        [$model1, $model2, $model2b, $model3]
                    ];
                }
            }
        }
    }

    /**
     * Convert a table name into model name.
     *
     * @param string $value
     *
     * @return string
     */
    private static function studly($value)
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    /**
     * Guess an existing Model class from a $namespace and a $baseName.
     *
     * @param string $namespace
     * @param string $baseName
     *
     * @return string
     */
    private static function guess_model($namespace, $baseName)
    {
        $name = self::studly($baseName);
        return class_exists($class = "$namespace\\$name") ? $class : (class_exists($class = "$namespace\\" . Inflector::singularize($name)) ? $class : (class_exists($class = "$namespace\\" . Inflector::pluralize($name)) ? $class : null));
    }

    /**
     * Get the namespace of an object or class.
     *
     * @param mixed $object
     *
     * @return string
     */
    private function get_namespace($object)
    {
        $class = explode('\\', is_object($object) ? get_class($object) : $object);
        array_pop($class);
        return implode('\\', $class);
    }

    /**
     * Returns the configuration of the models.
     *
     * @return array
     */
    public function getConfiguration()
    {
        return [
            'fields' => $this->attColumns,
            'relationships' => $this->attRelationships,
        ];
    }
}
