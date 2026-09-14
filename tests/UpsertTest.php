<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class UpsertTest extends TestCase
{
    private function withTable(callable $test): void
    {
        try {
            Schema::create('upsert_items', function (Blueprint $t) {
                $t->id();
                $t->integer('a')->nullable();
                $t->integer('b')->nullable();
                $t->unique(['a', 'b']);
                $t->string('value', 100)->nullable();
                $t->string('kept')->default('original');
                $t->boolean('flag')->nullable();
                $t->decimal('price', 12, 2)->nullable();
                $t->timestamp('moment')->nullable();
            });
            $test();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
            Schema::dropIfExists('upsert_items');
        }
    }

    #[Test]
    public function it_upserts_single_multiple_and_mixed_rows_with_native_counts(): void
    {
        $this->withTable(function () {
            $q = DB::table('upsert_items');
            $this->assertSame(1, $q->upsert(['a' => 1, 'b' => 1, 'value' => 'first'], ['a', 'b'], ['value']));
            $id = (clone $q)->value('id');
            $this->assertGreaterThan(0, $id);
            $this->assertSame(1, $q->upsert(['a' => 1, 'b' => 1, 'value' => 'updated'], ['b', 'a'], ['a', 'value']));
            $this->assertSame(1, $q->upsert(['a' => 1, 'b' => 1, 'value' => 'updated'], ['a', 'b'], ['value']));
            $this->assertSame(2, $q->upsert([['a' => 2, 'b' => 2, 'value' => 'two'], ['value' => 'three', 'b' => 3, 'a' => 3]], ['a', 'b']));
            $this->assertSame(2, $q->upsert([['a' => 1, 'b' => 1, 'value' => 'one'], ['a' => 2, 'b' => 2, 'value' => 'TWO']], ['a', 'b'], ['value']));
            $this->assertSame(2, $q->upsert([['a' => 3, 'b' => 3, 'value' => 'THREE', 'kept' => 'ignored'], ['a' => 4, 'b' => 4, 'value' => 'four', 'kept' => 'new']], ['a', 'b'], ['value']));
            $rows = $q->orderBy('a')->get();
            $this->assertSame(['one', 'TWO', 'THREE', 'four'], $rows->pluck('value')->all());
            $this->assertSame(['original', 'original', 'original', 'new'], $rows->pluck('kept')->all());
            $this->assertSame($id, $rows[0]->id);
            $this->assertCount(4, $rows->pluck('id')->unique());
        });
    }

    #[Test]
    public function it_matches_partial_null_keys_but_not_all_null_keys(): void
    {
        $this->withTable(function () {
            $q = DB::table('upsert_items');
            foreach ([[5, null], [null, 5], [null, null]] as [$a, $b]) {
                foreach (['first', 'second'] as $value) {
                    $this->assertSame(1, $q->upsert(compact('a', 'b', 'value'), ['a', 'b'], ['value']));
                }
            }
            $this->assertSame('second', (clone $q)->where('a', 5)->value('value'));
            $this->assertSame('second', (clone $q)->where('b', 5)->value('value'));
            $this->assertSame(['first', 'second'], (clone $q)->whereNull('a')->whereNull('b')->orderBy('id')->pluck('value')->all());
            $this->assertSame(4, $q->count());
        });
    }

    #[Test]
    public function it_preserves_binding_order_and_native_types(): void
    {
        $this->withTable(function () {
            DB::table('upsert_items')->insert(['a' => 1, 'b' => 1, 'value' => 'old']);
            DB::enableQueryLog();
            $this->assertSame(2, DB::table('upsert_items')->upsert([
                ['value' => 'ä longer', 'b' => 1, 'a' => 1, 'flag' => true, 'price' => '12.34', 'moment' => '2026-01-15 12:34:56'],
                ['a' => 2, 'b' => 2, 'flag' => false, 'moment' => null, 'price' => '-0.25', 'value' => null],
            ], ['a', 'b'], ['flag', 'moment', 'price', 'value', 'kept' => "O'Reilly"]));
            $log = DB::getQueryLog()[0];
            $this->assertSame([1, 1, true, '2026-01-15 12:34:56', '12.34', 'ä longer', 2, 2, false, null, '-0.25', null, "O'Reilly"], $log['bindings']);
            $this->assertSame(13, substr_count($log['query'], '?'));
            $this->assertSame(12, substr_count(strtolower($log['query']), 'as type of column'));
            $this->assertSame(1, substr_count(strtolower($log['query']), 'union all'));
            $rows = DB::table('upsert_items')->orderBy('a')->get();
            $this->assertSame([true, false], $rows->pluck('flag')->all());
            $this->assertSame(['12.34', '-0.25'], $rows->pluck('price')->map(fn ($v) => (string) $v)->all());
            $this->assertSame(['2026-01-15 12:34:56', null], $rows->pluck('moment')->all());
            $this->assertSame(['ä longer', null], $rows->pluck('value')->all());
            $this->assertSame(["O'Reilly", 'original'], $rows->pluck('kept')->all());
        });
    }

    #[Test]
    #[DataProvider('duplicateTargets')]
    public function it_does_not_leave_partial_changes_after_duplicate_source_keys(bool $existing, int $code): void
    {
        $this->withTable(function () use ($existing, $code) {
            $q = DB::table('upsert_items');
            if ($existing) {
                $q->insert(['a' => 1, 'b' => 1, 'value' => 'original']);
            }
            try {
                $q->upsert([['a' => 9, 'b' => 9, 'value' => 'new'], ['a' => 1, 'b' => 1, 'value' => 'first'], ['a' => 1, 'b' => 1, 'value' => 'second']], ['a', 'b'], ['value']);
                $this->fail('Duplicate source keys must fail.');
            } catch (QueryException $e) {
                $previous = $e->getPrevious();
                $this->assertInstanceOf(\PDOException::class, $previous);
                if ($existing) {
                    $sqlcode = (int) $previous->errorInfo[1];
                    $message = ($previous->errorInfo[2] ?? '').' '.$previous->getMessage();
                    $this->assertTrue(
                        $sqlcode === -811 || ($sqlcode === -999
                            && preg_match('/\b335545269\b/', $message) === 1),
                        'Expected the specific Firebird merge_dup_update error.'
                    );
                } else {
                    $this->assertSame($code, (int) $e->getPrevious()->errorInfo[1]);
                }
            }
            $this->assertSame($existing ? ['original'] : [], $q->pluck('value')->all());
        });
    }

    public static function duplicateTargets(): iterable
    {
        yield 'existing' => [true, -811];
        yield 'absent' => [false, -803];
    }

    #[Test]
    public function it_supports_nullable_single_unique_keys(): void
    {
        $this->withTable(function () {
            Schema::table('upsert_items', fn (Blueprint $t) => $t->unique('a'));
            $q = DB::table('upsert_items');
            foreach ([['a' => null, 'value' => 'n1'], ['a' => null, 'value' => 'n2'], ['a' => 5, 'value' => 'first'], ['a' => 5, 'value' => 'updated']] as $row) {
                $this->assertSame(1, $q->upsert($row, 'a', ['value']));
            }
            $this->assertSame(['n1', 'n2', 'updated'], $q->orderBy('id')->pluck('value')->all());
        });
    }

    #[Test]
    public function it_propagates_unknown_database_columns_without_modifying_data(): void
    {
        $this->withTable(function () {
            DB::table('upsert_items')->insert(['a' => 1, 'b' => 1, 'value' => 'original']);
            try {
                DB::table('upsert_items')->upsert(['missing' => 1, 'value' => 'changed'], 'missing', ['value']);
                $this->fail('Unknown physical columns must fail.');
            } catch (QueryException $e) {
                $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
            }
            $this->assertSame(['original'], DB::table('upsert_items')->pluck('value')->all());
        });
    }

    #[Test]
    public function it_compiles_repeatably_without_mutating_query_or_bindings(): void
    {
        $q = DB::table('upsert_items')->select('id')->where('a', 7);
        $before = serialize($q->getBindings());
        $columns = $q->columns;
        $values = [['a' => 1, 'b' => 2, 'value' => 'x']];
        $grammar = $q->getGrammar();
        $sql = $grammar->compileUpsert($q, $values, ['a', 'b'], ['value']);
        $this->assertSame($sql, $grammar->compileUpsert($q, $values, ['a', 'b'], ['value']));
        $this->assertSame($before, serialize($q->getBindings()));
        $this->assertSame($columns, $q->columns);
        $this->assertSame([['a' => 1, 'b' => 2, 'value' => 'x']], $values);
        $this->assertStringNotContainsString('union', strtolower($sql));
        $this->assertStringContainsString('is not distinct from', strtolower($sql));
    }

    #[Test]
    #[DataProvider('invalidForms')]
    public function it_rejects_unsupported_or_invalid_merge_inputs(string $case): void
    {
        $this->withTable(function () use ($case) {
            $q = DB::table('upsert_items');
            $values = [['a' => 1, 'b' => 1, 'value' => 'x']];
            $keys = ['a', 'b'];
            $update = ['value'];
            switch ($case) {
                case 'source expression': $values[0]['value'] = DB::raw('CURRENT_TIMESTAMP'); break;
                case 'update expression': $update = ['value' => DB::raw('DEFAULT')]; break;
                case 'different columns': $values[] = ['a' => 2, 'b' => 2]; break;
                case 'missing match column': $keys = ['missing']; break;
                case 'empty keys': $keys = []; break;
                case 'alias': $q->from('upsert_items as u'); break;
                case 'array value': $values[0]['value'] = ['unsafe']; break;
                case 'array update': $update = ['value' => ['unsafe']]; break;
            }
            DB::enableQueryLog();
            try {
                // Direct compiler call also covers Laravel 12's missing empty-key guard.
                $q->getGrammar()->compileUpsert($q, $values, $keys, $update);
                $this->fail('Unsafe input must be rejected before SQL execution.');
            } catch (LogicException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
            $this->assertSame([], DB::getQueryLog());
            $this->assertSame(0, DB::table('upsert_items')->count());
        });
    }

    public static function invalidForms(): iterable
    {
        foreach (['source expression', 'update expression', 'different columns', 'missing match column', 'empty keys', 'alias', 'array value', 'array update'] as $case) {
            yield $case => [$case];
        }
    }

    #[Test]
    public function it_preserves_laravels_empty_values_and_empty_update_behavior(): void
    {
        $this->withTable(function () {
            $q = DB::table('upsert_items');
            $this->assertSame(0, $q->upsert([], ['a', 'b']));
            $this->assertSame(1, $q->upsert([['a' => 1, 'b' => 1], ['a' => 2, 'b' => 2]], ['a', 'b'], []));
            $this->assertSame(2, $q->count());
        });
    }

    #[Test]
    public function it_upserts_prefixed_mixed_case_identifiers_with_a_string_key(): void
    {
        $db = DB::connection();
        $prefix = $db->getTablePrefix();
        $db->setTablePrefix('um_');
        try {
            $db->getSchemaBuilder()->create('Mixed', function (Blueprint $t) {
                $t->id();
                $t->string('Code')->unique();
                $t->string('Value');
            });
            $q = $db->table('Mixed');
            $this->assertSame(1, $q->upsert(['Code' => 'A', 'Value' => 'first'], 'Code', ['Value']));
            $this->assertSame(1, $q->upsert(['Code' => 'A', 'Value' => 'second'], 'Code', ['Value']));
            $this->assertSame('second', $q->value('Value'));
            $sql = $q->getGrammar()->compileUpsert($q, [['Code' => 'A', 'Value' => 'x']], ['Code'], ['Value']);
            $this->assertStringContainsString('"um_Mixed"."Code"', $sql);
            $this->assertStringNotContainsString('um_um_', $sql);
        } finally {
            $db->getSchemaBuilder()->dropIfExists('Mixed');
            $db->setTablePrefix($prefix);
        }
    }

    #[Test]
    public function it_upserts_eloquent_timestamps_and_generated_unique_ids(): void
    {
        try {
            Schema::create('upsert_models', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('code')->unique();
                $t->string('value');
                $t->timestamps();
            });
            $this->assertSame(2, UpsertModel::upsert([['code' => 'a', 'value' => 'first'], ['code' => 'b', 'value' => 'second']], 'code', ['value']));
            $a = UpsertModel::where('code', 'a')->firstOrFail();
            $this->assertTrue(\Illuminate\Support\Str::isUuid($a->id));
            $this->assertNotNull($a->created_at);
            $this->assertNotNull($a->updated_at);
            $id = $a->id;
            $created = $a->getRawOriginal('created_at');
            DB::table('upsert_models')->where('id', $id)->update(['updated_at' => '2000-01-01 00:00:00']);
            $this->assertSame(1, UpsertModel::upsert([['code' => 'a', 'value' => 'changed']], 'code', ['value']));
            $a->refresh();
            $this->assertSame($id, $a->id);
            $this->assertSame($created, $a->getRawOriginal('created_at'));
            $this->assertSame('changed', $a->value);
            $this->assertNotSame('2000-01-01 00:00:00', $a->getRawOriginal('updated_at'));
            $this->assertSame(2, UpsertModel::count());
            $this->assertSame(1, UpsertModel::upsert([['code' => 'a', 'value' => 'ignored', 'updated_at' => '2026-01-01 00:00:00']], 'code', []));
            $a->refresh();
            $this->assertSame('changed', $a->value);
            $this->assertSame('2026-01-01 00:00:00', $a->getRawOriginal('updated_at'));
        } finally {
            Schema::dropIfExists('upsert_models');
        }
    }
}

class UpsertModel extends Model
{
    use HasUuids;

    protected $table = 'upsert_models';
}
