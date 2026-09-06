<?php

namespace HarryGulliford\Firebird\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;

class FirebirdProcessor extends Processor
{
    /** @inheritDoc */
    public function processColumns($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'type_name' => $result->type_name,
                'type' => $result->type,
                'collation' => $result->collation,
                'nullable' => (bool) $result->nullable,
                'default' => $result->default,
                'auto_increment' => (bool) $result->auto_increment,
                'comment' => $result->comment,
                'generation' => $result->generation !== null ? [
                    'type' => 'virtual',
                    'expression' => $result->generation,
                ] : null,
            ];
        }, $results);
    }

    /** @inheritDoc */
    public function processIndexes($results)
    {
        $indexes = [];

        foreach ($results as $result) {
            $result = (object) $result;

            $indexes[$result->name] ??= [
                'name' => $result->name,
                'columns' => [],
                'type' => $result->type,
                'unique' => (bool) $result->unique,
                'primary' => (bool) $result->primary,
            ];

            if ($result->column_name !== null) {
                $indexes[$result->name]['columns'][] = $result->column_name;
            }
        }

        return array_values($indexes);
    }

    /**
     * Process an "insert get ID" query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $sql
     * @param  array  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        // The pdo_firebird driver does not support `lastInsertId()`. Perform
        // the insert operation in a way that returns the id.

        $result = $query->getConnection()->selectFromWriteConnection($sql, $values)[0];

        $sequence = $sequence ?: 'id';

        $id = is_object($result) ? $result->{$sequence} : $result[$sequence];

        return is_numeric($id) ? (int) $id : $id;
    }
}
