<?php

namespace HarryGulliford\Firebird\Tests;

use HarryGulliford\Firebird\FirebirdConnection;
use HarryGulliford\Firebird\FirebirdConnector;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class InsertOrIgnoreTest extends TestCase
{
    private function withTables(callable $test): void
    {
        try {
            Schema::create('ignore_parent', fn (Blueprint $table) => $table->integer('id')->primary());
            DB::table('ignore_parent')->insert(['id' => 1]);
            Schema::create('ignore_items', function (Blueprint $table) {
                $table->id();
                $table->string('name', 30)->unique();
                $table->integer('parent_id')->nullable();
                $table->foreign('parent_id')->references('id')->on('ignore_parent');
                $table->string('kind')->default('ok');
                $table->smallInteger('score')->default(7);
                $table->boolean('flag')->default(true);
                $table->timestamp('stamp')->useCurrent();
            });
            DB::statement('ALTER TABLE "ignore_items" ADD CHECK ("kind" IN (\'ok\'))');
            $test();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::disconnect();
            Schema::dropIfExists('ignore_items');
            Schema::dropIfExists('ignore_parent');
        }
    }

    #[Test]
    public function it_counts_single_inserts_and_primary_and_unique_duplicates(): void
    {
        $this->withTables(function () {
            $query = DB::table('ignore_items');
            $this->assertSame(1, $query->insertOrIgnore(['id' => 42, 'name' => 'first']));
            $this->assertSame(0, $query->insertOrIgnore(['id' => 42, 'name' => 'other']));
            $this->assertSame(0, $query->insertOrIgnore(['id' => 43, 'name' => 'first']));
            $this->assertSame(0, $query->insertOrIgnore([]));
            $this->assertSame(['first'], $query->pluck('name')->all());
            $this->assertSame([42], $query->pluck('id')->all());
        });
    }

    #[Test]
    public function it_counts_batches_without_losing_rows_after_duplicates(): void
    {
        $this->withTables(function () {
            $query = DB::table('ignore_items');
            $this->assertSame(2, $query->insertOrIgnore([['name' => 'a'], ['name' => 'b']]));
            $this->assertSame(2, $query->insertOrIgnore([['name' => 'c'], ['name' => 'a'], ['name' => 'c'], ['name' => 'd'], ['name' => 'b']]));
            $this->assertSame(0, $query->insertOrIgnore([['name' => 'a'], ['name' => 'b'], ['name' => 'a']]));
            $this->assertSame(['a', 'b', 'c', 'd'], $query->orderBy('id')->pluck('name')->all());
        });
    }

    #[Test]
    public function it_preserves_types_defaults_identity_and_binding_order(): void
    {
        $this->withTables(function () {
            $rows = [
                ['stamp' => '2026-01-15 12:34:56', 'name' => 'typed', 'flag' => false, 'parent_id' => null],
                ['parent_id' => 1, 'flag' => true, 'name' => 'second', 'stamp' => '2026-01-16 12:34:56'],
            ];
            $query = DB::table('ignore_items');
            $before = $query->getRawBindings();
            $log = DB::pretend(fn () => $query->insertOrIgnore($rows));
            $this->assertSame([false, 'typed', null, '2026-01-15 12:34:56', true, 'second', 1, '2026-01-16 12:34:56'], $log[0]['bindings']);
            $normalized = $rows;
            foreach ($normalized as &$row) {
                ksort($row);
            }
            unset($row);
            $sql = $query->getGrammar()->compileInsertOrIgnore($query, $normalized);
            $this->assertSame(8, substr_count($sql, '?'));
            $this->assertSame($sql, $query->getGrammar()->compileInsertOrIgnore($query, $normalized));
            $this->assertSame($log[0]['bindings'], DB::pretend(fn () => $query->insertOrIgnore($rows))[0]['bindings']);
            $this->assertSame($before, $query->getRawBindings());
            $this->assertStringNotContainsString('SUSPEND', $log[0]['query']);
            $this->assertStringNotContainsString('RETURNS', $log[0]['query']);
            $this->assertStringContainsString('TYPE OF COLUMN "ignore_items"."flag"', $log[0]['query']);
            $this->assertSame(2, $query->insertOrIgnore($rows));
            $actual = $query->orderBy('id')->get();
            $this->assertGreaterThan(0, $actual[0]->id);
            $this->assertGreaterThan($actual[0]->id, $actual[1]->id);
            $this->assertFalse($actual[0]->flag);
            $this->assertTrue($actual[1]->flag);
            $this->assertNull($actual[0]->parent_id);
            $this->assertSame(1, $actual[1]->parent_id);
            $this->assertSame('2026-01-15 12:34:56', $actual[0]->stamp);
            $this->assertSame(7, $actual[0]->score);
            $this->assertSame('ok', $actual[0]->kind);
            $this->assertSame(1, $query->insertOrIgnore(['name' => 'defaults']));
            $default = DB::table('ignore_items')->where('name', 'defaults')->first();
            $this->assertTrue($default->flag);
            $this->assertNotNull($default->stamp);
        });
    }

    #[Test]
    #[DataProvider('invalidRows')]
    public function it_propagates_other_errors_and_rolls_back_the_entire_batch(array $bad): void
    {
        $this->withTables(function () use ($bad) {
            DB::table('ignore_items')->insert(['name' => 'existing']);
            $good = ['name' => 'first', 'parent_id' => 1, 'kind' => 'ok', 'score' => 7];
            try {
                DB::table('ignore_items')->insertOrIgnore([$good, array_replace($good, ['name' => 'bad'], $bad), array_replace($good, ['name' => 'last'])]);
                $this->fail('Only SQLCODE -803 may be ignored.');
            } catch (QueryException $exception) {
                $this->assertInstanceOf(PDOException::class, $exception->getPrevious());
                $this->assertNotSame(-803, (int) $exception->getPrevious()->errorInfo[1]);
            }
            $this->assertSame(['existing'], DB::table('ignore_items')->pluck('name')->all());
        });
    }

    public static function invalidRows(): iterable
    {
        yield 'not null' => [['name' => null]];
        yield 'foreign key' => [['parent_id' => 999]];
        yield 'check' => [['kind' => 'invalid']];
        yield 'range' => [['score' => 999999]];
        yield 'type' => [['score' => 'not-a-number']];
    }

    #[Test]
    public function it_respects_outer_rollback_and_nested_savepoints(): void
    {
        $this->withTables(function () {
            DB::connection()->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
            DB::beginTransaction();
            $this->assertSame(1, DB::table('ignore_items')->insertOrIgnore(['name' => 'outer']));
            DB::beginTransaction();
            $this->assertSame(1, DB::table('ignore_items')->insertOrIgnore(['name' => 'inner']));
            DB::rollBack();
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame(['outer'], DB::table('ignore_items')->pluck('name')->all());
            DB::rollBack();
            $this->assertSame(0, DB::transactionLevel());
            DB::beginTransaction();
            $this->assertSame(0, DB::table('ignore_items')->count());
            DB::rollBack();
        });
    }

    #[Test]
    public function it_preserves_single_row_expressions(): void
    {
        $this->withTables(function () {
            $values = ['name' => 'expression', 'score' => DB::raw('2 + 3'), 'stamp' => DB::raw('CURRENT_TIMESTAMP')];
            $log = DB::pretend(fn () => DB::table('ignore_items')->insertOrIgnore($values));
            $this->assertSame(['expression'], $log[0]['bindings']);
            $this->assertSame(1, DB::table('ignore_items')->insertOrIgnore($values));
            $this->assertSame(5, DB::table('ignore_items')->value('score'));
            $this->assertSame(1, DB::table('ignore_items')->insertOrIgnore(['name' => DB::raw("'literal'")]));
            $this->assertSame(['expression', 'literal'], DB::table('ignore_items')->orderBy('id')->pluck('name')->all());
        });
    }

    #[Test]
    public function it_keeps_batch_expressions_and_other_ignore_apis_unsupported(): void
    {
        $this->withTables(function () {
            foreach ([
                fn () => DB::table('ignore_items')->insertOrIgnore([['name' => DB::raw("'raw'")], ['name' => 'bound']]),
                fn () => DB::table('ignore_items')->insertOrIgnoreUsing(['name'], DB::table('ignore_items')->select('name')),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('This input must remain unsupported.');
                } catch (\LogicException|\RuntimeException $exception) {
                    $this->assertStringContainsString('not support', $exception->getMessage());
                }
            }
            $query = DB::table('ignore_items');
            if (method_exists($query, 'insertOrIgnoreReturning')) {
                try {
                    $query->insertOrIgnoreReturning(['name' => 'returning']);
                    $this->fail('Returning must remain unsupported.');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString('does not support', $exception->getMessage());
                }
            }
            $this->assertSame(0, DB::table('ignore_items')->count());
        });
    }

    #[Test]
    public function it_documents_unique_errors_from_triggers_are_also_ignored(): void
    {
        $this->withTables(function () {
            try {
                Schema::create('ignore_log', fn (Blueprint $table) => $table->integer('value')->unique());
                DB::statement('CREATE TRIGGER "ignore_log_insert" FOR "ignore_items" ACTIVE AFTER INSERT POSITION 0 AS BEGIN INSERT INTO "ignore_log" ("value") VALUES (1); END');
                $this->assertSame(1, DB::table('ignore_items')->insertOrIgnore([['name' => 'first'], ['name' => 'second']]));
                $this->assertSame(['first'], DB::table('ignore_items')->pluck('name')->all());
                $this->assertSame([1], DB::table('ignore_log')->pluck('value')->all());
            } finally {
                // Drop the trigger with its table before dropping its dependency.
                Schema::dropIfExists('ignore_items');
                Schema::dropIfExists('ignore_log');
            }
        });
    }

    #[Test]
    public function it_documents_no_wait_can_ignore_an_uncommitted_key(): void
    {
        $this->withTables(function () {
            $a = DB::connection();
            $b = null;
            $open = false;
            try {
                $a->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
                $a->beginTransaction();
                $a->table('ignore_items')->insert(['name' => 'race']);
                $config = $a->getConfig();
                $config['options'][PDO::ATTR_PERSISTENT] = false;
                $b = new FirebirdConnection((new FirebirdConnector)->connect($config), $config['database'], '', $config);
                $b->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
                $b->statement('SET TRANSACTION READ WRITE ISOLATION LEVEL READ COMMITTED NO WAIT');
                $open = true;
                $this->assertNotSame($a->selectOne('SELECT CURRENT_CONNECTION AS "id" FROM RDB$DATABASE')->id, $b->selectOne('SELECT CURRENT_CONNECTION AS "id" FROM RDB$DATABASE')->id);
                $this->assertSame(0, (int) $b->selectOne('SELECT MON$LOCK_TIMEOUT AS "timeout" FROM MON$TRANSACTIONS WHERE MON$TRANSACTION_ID = CURRENT_TRANSACTION')->timeout);
                $this->assertSame(0, $b->table('ignore_items')->insertOrIgnore(['name' => 'race']));
                $a->rollBack();
                $this->assertSame(0, $b->table('ignore_items')->count());
            } finally {
                try {
                    if ($open) {
                        $b->statement('ROLLBACK WORK');
                    }
                } finally {
                    $b?->disconnect();
                }
            }
        });
    }
}
