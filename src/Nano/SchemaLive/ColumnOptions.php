<?php
namespace Nano\SchemaLive;

use Doctrine\DBAL\Schema\Column;

/**
 * Evaluates the comments of a column to configure it
 *
 * @author David Callizaya <davidcallizaya@gmail.com>
 */
class ColumnOptions
{

    public $key;
    public $tableDefinition;

    public function __construct(Column $column, array &$tableDefinition)
    {
        $this->key = $column->getName();
        $this->tableDefinition = &$tableDefinition;
    }

    public function hidden()
    {
        $this->tableDefinition['hidden'][] = $this->key;
    }

    public function guarded()
    {
        $this->tableDefinition['guarded'][] = $this->key;
    }

    public function json()
    {
        $this->tableDefinition['casts'][$this->key] = 'array';
    }
}
