<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class RowValueComparisonTest extends TestCase
{
    private function withRows(callable $test): void
    {
        try {
            Schema::create('row_comparison_test', function (Blueprint $table) {
                $table->integer('id');
                $table->integer('a')->nullable();
                $table->string('b', 10)->nullable();
                $table->integer('c')->nullable();
            });
            $rows = [[0, 'z', 9], [1, 'a', 1], [1, 'b', 2], [1, 'b', 3], [2, null, null], [null, 'b', 2], [1, null, 2], [1, 'b', null]];
            foreach ($rows as $i => $row) {
                DB::table('row_comparison_test')->insert(array_combine(['id', 'a', 'b', 'c'], [$i + 1, ...$row]));
            }
            $test($rows);
        } finally {
            Schema::dropIfExists('row_comparison_test');
        }
    }

    #[Test]
    #[DataProvider('operators')]
    public function it_compares_rows_with_sql_null_semantics(int $size, string $operator): void
    {
        $this->withRows(function ($rows) use ($size, $operator) {
            foreach ([[1, 'b', 2], [null, 'b', 2], [1, null, 2], [1, 'b', null]] as $right) {
                $right = array_slice($right, 0, $size);
                $query = DB::table('row_comparison_test')->whereRowValues(array_slice(['a', 'b', 'c'], 0, $size), $operator, $right);
                $expected = array_map(fn ($row) => $this->compare(array_slice($row, 0, $size), $right, $operator), $rows);
                $predicate = substr($query->getGrammar()->compileWheres($query), 6);
                $actual = DB::table('row_comparison_test')->selectRaw($predicate.' AS "result"', $query->getBindings())->orderBy('id')->get()->pluck('result')->all();
                $this->assertSame($expected, $actual);
                $ids = [];
                foreach ($expected as $i => $value) {
                    if ($value === true) {
                        $ids[] = $i + 1;
                    }
                }
                $this->assertSame($ids, $query->orderBy('id')->pluck('id')->all());
            }
        });
    }

    private function compare(array $left, array $right, string $operator): ?bool
    {
        $unknown = false;
        foreach ($left as $i => $value) {
            if ($value === null || $right[$i] === null) {
                if (! in_array($operator, ['=', '<>', '!='])) {
                    return null;
                }
                $unknown = true;
            } elseif ($value !== $right[$i]) {
                return match ($operator) {
                    '=' => false,
                    '<>', '!=' => true,
                    '<', '<=' => $value < $right[$i],
                    '>', '>=' => $value > $right[$i],
                };
            }
        }

        return $unknown ? null : in_array($operator, ['=', '<=', '>=']);
    }

    public static function operators(): iterable
    {
        foreach ([2, 3] as $size) {
            foreach (['=', '<>', '!=', '<', '<=', '>', '>='] as $operator) {
                yield "$size $operator" => [$size, $operator];
            }
        }
    }

    #[Test]
    public function it_preserves_grouping_bindings_and_compilation_state(): void
    {
        $this->withRows(function () {
            $query = DB::table('row_comparison_test')->where('id', '>', 1)
                ->where(fn ($q) => $q->whereRowValues(['a', 'b', 'c'], '<=', [1, 'b', 2])->orWhereRowValues(['a', 'b'], '=', [2, 'x']))
                ->where('id', '<', 8)->orderBy('id');
            $bindings = [1, 1, 1, 'b', 'b', 2, 2, 'x', 8];
            $this->assertSame($bindings, $query->getBindings());
            $state = $this->queryState($query);
            $sql = $query->toSql();
            $this->assertSame(count($bindings), substr_count($sql, '?'));
            $this->assertSame($sql, $query->toSql());
            $this->assertSame($state, $this->queryState($query));
            $this->assertSame([2, 3], $query->pluck('id')->all());
            $this->assertSame([1, 2, 5], DB::table('row_comparison_test')
                ->whereNot(fn ($q) => $q->whereRowValues(['a', 'b'], '=', [1, 'b']))->orderBy('id')->pluck('id')->all());
        });
    }

    private function queryState($query): array
    {
        $wheres = array_map(function ($where) {
            if (isset($where['query'])) {
                $where['query'] = $this->queryState($where['query']);
            }

            return $where;
        }, $query->wheres);

        return [$wheres, $query->getRawBindings(), $query->columns];
    }

    #[Test]
    public function it_supports_eloquent_and_equality_expressions(): void
    {
        $this->withRows(function () {
            $model = new class extends Model {
                protected $table = 'row_comparison_test';
                public $timestamps = false;
            };
            $this->assertSame([3, 4, 8], $model->newQuery()->whereRowValues(['a', 'b'], '=', [1, 'b'])->orderBy('id')->get()->modelKeys());
            $query = DB::table('row_comparison_test')->whereRowValues([DB::raw('"a" + 0'), 'b'], '=', [DB::raw('1'), 'b']);
            $this->assertSame(['b'], $query->getBindings());
            $this->assertSame([3, 4, 8], $query->orderBy('id')->pluck('id')->all());
        });
    }

    #[Test]
    #[DataProvider('invalidInputs')]
    public function it_rejects_invalid_inputs_before_changing_query_state(array $columns, string $operator, array $values, string $message): void
    {
        $query = DB::table('row_comparison_test')->where('id', 1);
        $state = serialize([$query->wheres, $query->getRawBindings()]);
        try {
            $query->whereRowValues($columns, $operator, $values);
            $this->fail('Expected an invalid row comparison to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
        $this->assertSame($state, serialize([$query->wheres, $query->getRawBindings()]));
    }

    public static function invalidInputs(): iterable
    {
        yield 'count' => [['a', 'b'], '=', [1], 'number of columns'];
        yield 'empty' => [[], '=', [], 'empty'];
        yield 'like' => [['a'], 'like', [1], 'operator'];
        yield 'arbitrary' => [['a'], 'bogus', [1], 'operator'];
        yield 'expression column' => [[new \Illuminate\Database\Query\Expression('RAND()'), 'b'], '<', [1, 2], 'expressions'];
        yield 'expression value' => [['a', 'b'], '>=', [new \Illuminate\Database\Query\Expression('RAND()'), 2], 'expressions'];
    }
}
