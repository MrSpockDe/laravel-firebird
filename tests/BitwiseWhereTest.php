<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class BitwiseWhereTest extends TestCase
{
    private function withRows(callable $test): void
    {
        try {
            Schema::create('bitwise_where_test', function (Blueprint $table) {
                $table->smallInteger('s')->nullable();
                $table->integer('i')->nullable();
                $table->bigInteger('b')->nullable();
            });
            foreach ([0, 1, 2, 3, -1, -2, null] as $value) {
                DB::table('bitwise_where_test')->insert(['s' => $value, 'i' => $value, 'b' => $value]);
            }
            $test();
        } finally {
            Schema::dropIfExists('bitwise_where_test');
        }
    }

    #[Test]
    #[DataProvider('operators')]
    public function it_preserves_bitwise_truth_and_null_semantics(string $column, string $operator, string $function): void
    {
        $this->withRows(function () use ($column, $operator, $function) {
            foreach ([0, 1, -1] as $mask) {
                $query = DB::table('bitwise_where_test')->where($column, $operator, $mask);
                $predicate = substr($query->getGrammar()->compileWheres($query), 6);
                $expectedSql = $operator === '&~'
                    ? 'BIN_AND("'.$column.'", BIN_NOT(?)) <> 0'
                    : $function.'("'.$column.'", ?) <> 0';
                $this->assertSame($expectedSql, $predicate);
                $this->assertSame([$mask], $query->getBindings());
                $results = DB::table('bitwise_where_test')->select($column)->selectRaw($predicate.' AS "result"', [$mask])->get();
                $matching = [];
                foreach ($results as $row) {
                    $value = $row->$column;
                    $expected = $value === null ? null : (match ($operator) {
                        '&' => $value & $mask,
                        '|' => $value | $mask,
                        '^' => $value ^ $mask,
                        '&~' => $value & ~$mask,
                    }) !== 0;
                    $this->assertSame($expected, $row->result);
                    if ($expected === true) {
                        $matching[] = $value;
                    }
                }
                sort($matching);
                $this->assertSame($matching, $query->orderBy($column)->pluck($column)->all());
            }
            $query = DB::table('bitwise_where_test')->where($column, $operator, DB::raw('NULL'));
            $predicate = substr($query->getGrammar()->compileWheres($query), 6);
            $this->assertSame([], $query->getBindings());
            $this->assertSame(array_fill(0, 7, null), DB::table('bitwise_where_test')->selectRaw($predicate.' AS "result"')->get()->pluck('result')->all());
        });
    }

    public static function operators(): iterable
    {
        foreach (['s', 'i', 'b'] as $column) {
            foreach (['&' => 'BIN_AND', '|' => 'BIN_OR', '^' => 'BIN_XOR', '&~' => 'BIN_AND'] as $operator => $function) {
                yield "$column $operator" => [$column, $operator, $function];
            }
        }
    }

    #[Test]
    public function it_preserves_grouping_bindings_expressions_and_eloquent(): void
    {
        $this->withRows(function () {
            $query = DB::table('bitwise_where_test')->where('i', '>', -3)
                ->where(fn ($q) => $q->where('i', '&', 1)->orWhere('i', '^', 3))
                ->whereNot(fn ($q) => $q->where('i', '|', 0));
            $bindings = [-3, 1, 3, 0];
            $this->assertSame($bindings, $query->getBindings());
            $sql = $query->toSql();
            $this->assertSame($sql, $query->toSql());
            $this->assertSame($bindings, $query->getBindings());
            $this->assertSame(4, substr_count($sql, '?'));
            $this->assertSame([0], $query->pluck('i')->all());
            $expression = DB::table('bitwise_where_test')->where(DB::raw('"i" + 0'), '&', DB::raw('1'));
            $this->assertSame([], $expression->getBindings());
            $this->assertStringContainsString('BIN_AND("i" + 0, 1) <> 0', $expression->toSql());
            $this->assertSame([-1, 1, 3], $expression->orderBy('i')->pluck('i')->all());
            $model = new class extends Model {
                protected $table = 'bitwise_where_test';
                public $timestamps = false;
            };
            $this->assertSame([-1, 1, 3], $model->newQuery()->where('i', '&', 1)->orderBy('i')->get()->pluck('i')->all());
            DB::table('bitwise_where_test')->insert(['b' => 4294967296]);
            $this->assertSame([-2, -1, 4294967296], DB::table('bitwise_where_test')->where('b', '&', 4294967296)->orderBy('b')->pluck('b')->all());
        });
    }

    #[Test]
    #[DataProvider('shifts')]
    public function it_rejects_shifts_without_executing_sql(string $operator): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            try {
                DB::table('bitwise_where_test')->where('i', $operator, 1)->toSql();
                $this->fail('Expected shift compilation to fail.');
            } catch (\LogicException $exception) {
                $this->assertSame('Firebird bitwise WHERE shifts (<< and >>) are not supported.', $exception->getMessage());
            }
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public static function shifts(): iterable
    {
        yield ['<<'];
        yield ['>>'];
    }

    #[Test]
    public function it_preserves_laravels_rejection_of_php_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DB::table('bitwise_where_test')->where('i', '&', null);
    }
}
