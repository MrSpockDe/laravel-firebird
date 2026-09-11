<?php

namespace HarryGulliford\Firebird\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\JoinLateralClause;
use Illuminate\Support\Str;

class FirebirdGrammar extends Grammar
{
    /** {@inheritDoc} */
    public function compileTruncate(Builder $query)
    {
        throw new \LogicException('Firebird does not support truncate operations.');
    }

    /** {@inheritDoc} */
    protected function compileUpdateWithJoins(Builder $query, $table, $columns, $where)
    {
        throw new \LogicException('Firebird does not support update operations with joins.');
    }

    /** {@inheritDoc} */
    protected function compileDeleteWithJoins(Builder $query, $table, $where)
    {
        throw new \LogicException('Firebird does not support delete operations with joins.');
    }

    /**
     * Compile a LIKE clause using Firebird's case mapping.
     */
    protected function whereLike(Builder $query, $where)
    {
        if ($where['caseSensitive']) {
            return parent::whereLike($query, $where);
        }

        $operator = $where['not'] ? ' NOT LIKE ' : ' LIKE ';

        return 'UPPER('.$this->wrap($where['column']).')'.$operator
            .'UPPER('.$this->parameter($where['value']).')';
    }

    /**
     * The components that make up a select clause.
     *
     * @var string[]
     */
    protected $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'offset',
        'limit',
        'lock',
    ];

    /**
     * All of the available clause operators.
     *
     * @var string[]
     *
     * @link https://www.firebirdsql.org/file/documentation/html/en/refdocs/fblangref50/firebird-50-language-reference.html#fblangref50-commons-predicates
     */
    protected $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        '!<', '!>', '~<', '~>', '^<', '^>', '~=', '^=',
        'like', 'not like', 'between', 'not between',
        'containing', 'not containing', 'starting with', 'not starting with',
        'similar to', 'not similar to', 'is distinct from', 'is not distinct from',
    ];

    /**
     * Compile globally ordered unions through a derived table.
     */
    public function compileSelect(Builder $query)
    {
        if (! $query->unions || empty($query->unionOrders)) {
            return parent::compileSelect($query);
        }

        // Let Laravel place aggregates outside the ordered union without
        // allowing its aggregate compiler to mutate the original builder.
        if ($query->aggregate) {
            return parent::compileSelect(clone $query);
        }

        $inner = $query->cloneWithout(['unionOrders', 'unionLimit', 'unionOffset']);
        $sql = 'select * from ('.parent::compileSelect($inner).') as '.$this->wrapTable('firebird_union');
        $sql .= ' '.$this->compileOrders($query, $query->unionOrders);

        if (isset($query->unionOffset)) {
            $sql .= ' '.$this->compileOffset($query, $query->unionOffset);
        }

        if (isset($query->unionLimit)) {
            $sql .= ' '.$this->compileLimit($query, $query->unionLimit);
        }

        return $sql;
    }

    /** {@inheritDoc} */
    protected function compileLock(Builder $query, $value)
    {
        if ($value === false) {
            throw new \LogicException('This database driver does not support shared locks.');
        }

        return $value === true ? 'for update with lock' : $value;
    }

    /**
     * Compile the "limit" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  int  $limit
     * @return string
     */
    protected function compileLimit(Builder $query, $limit)
    {
        return 'fetch first '.(int) $limit.' rows only';
    }

    /**
     * Compile the "offset" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  int  $offset
     * @return string
     */
    protected function compileOffset(Builder $query, $offset)
    {
        return 'offset '.(int) $offset.' rows';
    }

    /**
     * Compile the random statement into SQL.
     *
     * @param  string  $seed
     * @return string
     */
    public function compileRandom($seed)
    {
        return 'rand()';
    }

    /**
     * Wrap a union subquery in parentheses.
     *
     * @param  string  $sql
     * @return string
     */
    protected function wrapUnion($sql)
    {
        return 'select * from ('.$sql.')';
    }

    /**
     * Compile the "union" queries attached to the main query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileUnions(Builder $query)
    {
        // This method is the same as the parent implementation, except that the
        // order of offset and limit for union queries is reversed: offset must
        // precede limit. This is due to Firebird's SQL syntax for union queries.

        $sql = '';

        foreach ($query->unions as $union) {
            $sql .= $this->compileUnion($union);
        }

        if (! empty($query->unionOrders)) {
            $sql .= ' '.$this->compileOrders($query, $query->unionOrders);
        }

        if (isset($query->unionOffset)) {
            $sql .= ' '.$this->compileOffset($query, $query->unionOffset);
        }

        if (isset($query->unionLimit)) {
            $sql .= ' '.$this->compileLimit($query, $query->unionLimit);
        }

        return ltrim($sql);
    }

    /**
     * Compile an exists statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public function compileExists(Builder $query)
    {
        return sprintf('select case when exists(%s) then 1 else 0 end as "exists" from rdb$database',
            $this->compileSelect($query));
    }

    /**
     * Compile a date based where clause.
     *
     * @param  string  $type
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function dateBasedWhere($type, Builder $query, $where)
    {
        $condition = ($type === 'date' || $type === 'time')
            ? sprintf('cast(%s as %s)', $this->wrap($where['column']), $type)
            : sprintf('extract(%s from %s)', $type, $this->wrap($where['column']));

        return $condition.' '.$where['operator'].' '.$this->parameter($where['value']);
    }

    /**
     * Compile the select clause for a stored procedure.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $procedure
     * @param  array  $values
     * @return string
     */
    public function compileProcedure(Builder $query, $procedure, array $values = [])
    {
        return $this->wrap($procedure).' ('.$this->parameterize($values).')';
    }

    /**
     * Compile an aggregated select clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $aggregate
     * @return string
     */
    protected function compileAggregate(Builder $query, $aggregate)
    {
        // Wrap `aggregate` in double quotes to ensure the resultset returns the
        // column name as a lowercase string. This resolves compatibility with
        // the framework's paginator.
        return Str::replaceLast(
            'as aggregate', 'as "aggregate"', parent::compileAggregate($query, $aggregate)
        );
    }

    /**
     * Compile a "lateral join" clause.
     *
     * @param  \Illuminate\Database\Query\JoinLateralClause  $join
     * @param  string  $expression
     * @return string
     */
    public function compileJoinLateral(JoinLateralClause $join, string $expression): string
    {
        return trim("{$join->type} join lateral {$expression} on true");
    }

    /** {@inheritDoc} */
    protected function compileUpdateWithoutJoins(Builder $query, $table, $columns, $where)
    {
        return parent::compileUpdateWithoutJoins($query, $table, $columns, $where)
            .$this->compileDmlOrdersAndRows($query);
    }

    /** {@inheritDoc} */
    protected function compileDeleteWithoutJoins(Builder $query, $table, $where)
    {
        return parent::compileDeleteWithoutJoins($query, $table, $where)
            .$this->compileDmlOrdersAndRows($query);
    }

    /**
     * Compile ordering and row bounds for UPDATE and DELETE, not SELECT.
     */
    protected function compileDmlOrdersAndRows(Builder $query)
    {
        $orders = $this->compileOrders($query, $query->orders);
        $sql = $orders === '' ? '' : ' '.$orders;

        if (isset($query->limit)) {
            if ($query->limit === 0 || ! $query->offset) {
                $sql .= ' rows '.(int) $query->limit;
            } else {
                $sql .= ' rows '.((int) $query->offset + 1)
                    .' to '.((int) $query->offset + (int) $query->limit);
            }
        }

        return $sql;
    }

    /** {@inheritDoc} */
    public function compileInsert(Builder $query, array $values)
    {
        $first = reset($values);
        if (count($values) <= 1 || ! is_array($first)) {
            return parent::compileInsert($query, $values);
        }

        $table = $this->wrapTable($query->from);
        $columns = array_keys($first);
        $selects = [];

        foreach ($values as $row) {
            if ($columns === [] || array_keys($row) !== $columns) {
                throw new \LogicException('Batch inserts require the same non-empty column list in every row.');
            }

            $parameters = [];
            foreach ($row as $column => $value) {
                if ($this->isExpression($value)) {
                    throw new \LogicException('Raw expressions are not supported in batch inserts.');
                }
                $parameters[] = 'cast('.$this->parameter($value).' as type of column '
                    .$table.'.'.$this->wrap($column).')';
            }
            $selects[] = 'select '.implode(', ', $parameters).' from rdb$database';
        }

        return 'insert into '.$table.' ('.$this->columnize($columns).') '.implode(' union all ', $selects);
    }

    /**
     * Compile an insert and get ID statement into SQL.
     *
     * @param  Builder  $query
     * @param  array  $values
     * @param  string|null  $sequence
     * @return string
     */
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        // The pdo_firebird driver does not support `lastInsertId()`. Perform
        // the insert operation in a way that returns the id.
        return $this->compileInsert($query, $values).' returning '.$this->wrap($sequence ?: 'id');
    }
}
