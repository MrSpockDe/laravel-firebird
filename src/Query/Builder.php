<?php

namespace HarryGulliford\Firebird\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;

class Builder extends BaseBuilder
{
    /** {@inheritDoc} */
    public function whereRowValues($columns, $operator, $values, $boolean = 'and')
    {
        if (count($columns) !== count($values)) {
            throw new \InvalidArgumentException('The number of columns must match the number of values');
        }

        if (count($columns) === 0) {
            throw new \InvalidArgumentException('Firebird row comparisons cannot use empty tuples.');
        }

        if (! in_array($operator, ['=', '<>', '!=', '<', '<=', '>', '>='], true)) {
            throw new \InvalidArgumentException('Unsupported Firebird row comparison operator.');
        }

        $lexicographic = in_array($operator, ['<', '<=', '>', '>='], true);

        if ($lexicographic) {
            foreach (array_merge($columns, $values) as $operand) {
                if ($this->grammar->isExpression($operand)) {
                    throw new \InvalidArgumentException('Firebird lexicographic row comparisons do not support expressions.');
                }
            }
        }

        $columns = array_values($columns);
        $values = array_values($values);

        return $this->whereNested(function ($query) use ($columns, $operator, $values, $lexicographic) {
            if ($lexicographic) {
                $query->addLexicographicRowComparison($columns, $operator, $values, 0);
            } else {
                foreach ($columns as $i => $column) {
                    $query->addScalarRowComparison($column, $operator, $values[$i], $operator === '=' ? 'and' : 'or');
                }
            }
        }, $boolean);
    }

    /** Use a scalar RowValues predicate so NULL remains a bound comparison value. */
    protected function addScalarRowComparison($column, $operator, $value, $boolean = 'and')
    {
        return parent::whereRowValues([$column], $operator, [$value], $boolean);
    }

    /** Build a linear comparison tree and its bindings together. */
    protected function addLexicographicRowComparison(array $columns, string $operator, array $values, int $position): void
    {
        if ($position === count($columns) - 1) {
            $this->addScalarRowComparison($columns[$position], $operator, $values[$position]);

            return;
        }

        $this->addScalarRowComparison($columns[$position], $operator[0], $values[$position]);
        $this->whereNested(function ($query) use ($columns, $operator, $values, $position) {
            $query->addScalarRowComparison($columns[$position], '=', $values[$position]);
            $query->whereNested(function ($query) use ($columns, $operator, $values, $position) {
                $query->addLexicographicRowComparison($columns, $operator, $values, $position + 1);
            });
        }, 'or');
    }

    /**
     * Set the stored procedure which the query is targeting.
     *
     * @param  string  $procedure
     * @param  array  $bindings
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function procedure(string $procedure, array $bindings = [])
    {
        $expression = $this->grammar->compileProcedure($this, $procedure, $bindings);

        $this->fromRaw($expression, $this->cleanBindings($bindings));

        return $this;
    }

    /**
     * Alias to set the stored procedure which the query is targeting.
     *
     * @param  string  $procedure
     * @param  array  $bindings
     * @return \Illuminate\Database\Query\Builder|static
     *
     * @deprecated This method is deprecated and will be removed in a future
     * release. Use the `procedure` method instead.
     */
    public function fromProcedure(string $procedure, array $bindings = [])
    {
        return $this->procedure($procedure, $bindings);
    }
}
