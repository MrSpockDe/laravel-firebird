<?php

namespace HarryGulliford\Firebird\Tests;

use HarryGulliford\Firebird\Tests\Support\MigrateDatabase;
use HarryGulliford\Firebird\Tests\Support\MigrationState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

class DropAllSchemaObjectsTest extends TestCase
{
    use MigrateDatabase;

    private array $objects = [];

    protected function setUp(): void
    {
        parent::setUp();
        // This suite exercises database-wide APIs on the disposable test database.
        // Remove only the known shared fixtures before each scenario; restore them below.
        $this->assertSame([], Schema::getViews());
        $this->assertSame([], array_values(array_diff(Schema::getTableListing(), ['users', 'orders'])));
        $this->dropTables();
        MigrationState::$migrated = false;
    }

    protected function tearDown(): void
    {
        try {
            $connection = DB::connection();
            if ($connection->transactionLevel()) {
                $connection->rollBack(0);
            }
            if ($connection->getPdo()->inTransaction()) {
                $connection->getPdo()->rollBack();
            }
            $connection->setTablePrefix('');
            $connection->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
            // Explicit fixture cleanup, independent of the API under test.
            foreach ($this->objects as [$kind, $name]) {
                if ($kind === 'TABLE') {
                    foreach (DB::select('SELECT TRIM(RDB$CONSTRAINT_NAME) AS "name" FROM RDB$RELATION_CONSTRAINTS '
                        ."WHERE RDB\$CONSTRAINT_TYPE = 'FOREIGN KEY' AND RDB\$RELATION_NAME = ?", [$name]) as $fk) {
                        DB::statement('ALTER TABLE '.$this->quote($name).' DROP CONSTRAINT '.$this->quote($fk->name));
                    }
                }
            }
            foreach (array_reverse($this->objects) as [$kind, $name]) {
                $catalog = $kind === 'PROCEDURE' ? 'RDB$PROCEDURES' : 'RDB$RELATIONS';
                $field = $kind === 'PROCEDURE' ? 'RDB$PROCEDURE_NAME' : 'RDB$RELATION_NAME';
                if (DB::select('SELECT 1 FROM '.$catalog.' WHERE '.$field.' = ?', [$name])) {
                    DB::statement('DROP '.$kind.' '.$this->quote($name));
                }
            }
            $this->dropTables();
            $this->createTables();
            MigrationState::$migrated = false;
        } finally {
            parent::tearDown();
        }
    }

    private function quote(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function table(string $name): void
    {
        Schema::create($name, function (Blueprint $table) {
            $table->id();
            $table->bigInteger('other_id')->nullable();
        });
        $this->objects[] = ['TABLE', $name];
    }

    private function createView(string $name, ?string $source = null): void
    {
        DB::statement('CREATE VIEW '.$this->quote($name).' AS '.($source === null
            ? 'SELECT 1 AS "id" FROM RDB$DATABASE'
            : 'SELECT "id" FROM '.$this->quote($source)));
        $this->objects[] = ['VIEW', $name];
    }

    private function procedure(string $source): void
    {
        DB::statement('CREATE PROCEDURE "wipe_blocker" RETURNS ("value" BIGINT) AS BEGIN '
            .'FOR SELECT "id" FROM '.$this->quote($source).' INTO :"value" DO SUSPEND; END');
        $this->objects[] = ['PROCEDURE', 'wipe_blocker'];
    }

    private function assertIdle(): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
    }

    #[Test]
    #[DataProvider('apis')]
    public function it_rolls_back_even_when_a_dependency_error_occurs_at_commit(string $api): void
    {
        $this->table('wipe_data');
        $id = DB::table('wipe_data')->insertGetId([]);
        if ($api === 'dropAllViews') {
            $this->createView('wipe_a');
            $this->createView('wipe_z', 'wipe_data');
            $this->procedure('wipe_z');
        } else {
            $this->table('wipe_z');
            $this->procedure('wipe_z');
        }
        $beforeTables = Schema::getTables();
        $beforeViews = Schema::getViews();
        $sql = [];
        DB::connection()->beforeExecuting(function ($query) use (&$sql) {
            $sql[] = $query;
        });
        $commits = 0;
        $this->app['events']->listen(TransactionCommitting::class, function () use (&$commits) {
            $commits++;
        });
        $exception = null;
        try {
            Schema::$api();
        } catch (Throwable $caught) {
            $exception = $caught;
        }
        $this->assertNotNull($exception);
        $this->assertSame(1, $commits, 'All DROP statements must finish before this dependency fails at commit.');
        $pdoException = $exception;
        while ($pdoException && ! ($pdoException instanceof PDOException)) {
            $pdoException = $pdoException->getPrevious();
        }
        $this->assertInstanceOf(PDOException::class, $pdoException);
        $this->assertSame(-607, (int) $pdoException->errorInfo[1]);
        $this->assertGreaterThanOrEqual(2, count(array_filter($sql, fn ($query) => str_starts_with(strtoupper($query), 'DROP '))));
        $this->assertIdle();
        $this->assertSame($beforeTables, Schema::getTables());
        $this->assertSame($beforeViews, Schema::getViews());
        $this->assertSame([$id], DB::table('wipe_data')->pluck('id')->all());
        $this->assertCount(1, DB::select('SELECT 1 FROM RDB$PROCEDURES WHERE RDB$PROCEDURE_NAME = ?', ['wipe_blocker']));
        DB::connection()->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
        DB::transaction(fn () => DB::table('wipe_data')->insert(['other_id' => 5]));
        $this->assertIdle();
        DB::connection()->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
        $this->assertSame(2, DB::table('wipe_data')->count());
    }

    public static function apis(): array
    {
        return [['dropAllTables'], ['dropAllViews']];
    }

    #[Test]
    #[DataProvider('apis')]
    public function it_rejects_an_outer_transaction_without_touching_it(string $api): void
    {
        $this->table('wipe_data');
        DB::getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
        DB::beginTransaction();
        try {
            DB::table('wipe_data')->insert(['other_id' => 8]);
            try {
                Schema::$api();
                $this->fail('Wipe must not commit an outer transaction.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('active transaction', $e->getMessage());
            }
            $this->assertSame(1, DB::connection()->transactionLevel());
            $this->assertTrue(DB::getPdo()->inTransaction());
            $this->assertSame(1, DB::table('wipe_data')->count());
        } finally {
            DB::rollBack();
            DB::getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
        }
        $this->assertSame(0, DB::table('wipe_data')->count());
    }

    #[Test]
    public function it_handles_an_empty_database(): void
    {
        Schema::dropAllViews();
        Schema::dropAllTables();
        $this->assertIdle();
        $this->assertSame([], Schema::getViews());
        $this->assertSame([], Schema::getTables());
    }

    #[Test]
    #[DataProvider('viewCounts')]
    public function it_drops_views_in_dependency_order_and_preserves_tables(int $count): void
    {
        $this->table('wipe_data');
        DB::table('wipe_data')->insert(['other_id' => 9]);
        $this->createView('A_Base', 'wipe_data');
        if ($count > 1) {
            $this->createView('B_Äußere', 'A_Base');
            $this->createView('C_Independent');
        }
        Schema::dropAllViews();
        $this->assertSame([], Schema::getViews());
        $this->assertSame([9], DB::table('wipe_data')->pluck('other_id')->all());
    }

    public static function viewCounts(): array
    {
        return [[1], [3]];
    }

    #[Test]
    #[DataProvider('tableLayouts')]
    public function it_drops_tables_and_rebuilds_identity(string $layout): void
    {
        $this->table('wipe_a');
        if ($layout !== 'single') {
            $this->table('wipe_b');
        }
        if (in_array($layout, ['parent', 'cycle'])) {
            Schema::table('wipe_b', fn (Blueprint $table) => $table->foreign('other_id')->references('id')->on('wipe_a'));
        }
        if ($layout === 'cycle') {
            Schema::table('wipe_a', fn (Blueprint $table) => $table->foreign('other_id')->references('id')->on('wipe_b'));
        }
        if ($layout === 'self') {
            Schema::table('wipe_a', fn (Blueprint $table) => $table->foreign('other_id')->references('id')->on('wipe_a'));
        }
        Schema::dropAllTables();
        $this->assertSame([], Schema::getTables());
        Schema::create('wipe_a', fn (Blueprint $table) => $table->id());
        $this->assertSame(1, DB::table('wipe_a')->insertGetId([]));
    }

    public static function tableLayouts(): array
    {
        return [['single'], ['independent'], ['parent'], ['self'], ['cycle']];
    }

    #[Test]
    public function it_uses_physical_names_without_prefix_filtering_or_reapplication(): void
    {
        $this->table('Wipe_Ä');
        $this->table('wp_Mixed');
        DB::connection()->setTablePrefix('wp_');
        try {
            Schema::dropAllTables();
        } finally {
            DB::connection()->setTablePrefix('');
        }
        $this->assertSame([], Schema::getTables());
    }

    #[Test]
    public function it_preserves_every_table_when_a_view_blocks_the_wipe(): void
    {
        $this->table('wipe_data');
        $this->table('wipe_z');
        DB::table('wipe_data')->insert(['other_id' => 3]);
        $this->createView('wipe_view', 'wipe_z');
        try {
            Schema::dropAllTables();
            $this->fail('The dependent view must block the wipe.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('dropAllViews()', $e->getMessage());
        }
        $this->assertIdle();
        $this->assertCount(2, Schema::getTables());
        $this->assertTrue(Schema::hasView('wipe_view'));
        $this->assertSame([3], DB::table('wipe_data')->pluck('other_id')->all());
    }
    #[Test]
    #[DataProvider('apis')]
    public function it_rejects_a_direct_pdo_transaction(string $api): void
    {
        $this->table('wipe_data');
        $pdo = DB::getPdo();
        $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
        $pdo->beginTransaction();
        try {
            DB::table('wipe_data')->insert(['other_id' => 4]);
            try {
                Schema::$api();
                $this->fail('A PDO transaction must not be committed by the wipe.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('active transaction', $e->getMessage());
            }
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertSame(1, DB::table('wipe_data')->count());
        } finally {
            $pdo->rollBack();
            $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
        }
        $this->assertSame(0, DB::table('wipe_data')->count());
    }

    #[Test]
    public function it_drops_both_global_temporary_table_types(): void
    {
        foreach (['wipe_gtt_keep' => 'PRESERVE', 'wipe_gtt_delete' => 'DELETE'] as $name => $mode) {
            DB::statement('CREATE GLOBAL TEMPORARY TABLE '.$this->quote($name)
                .' ("id" INTEGER) ON COMMIT '.$mode.' ROWS');
            $this->objects[] = ['TABLE', $name];
        }
        $before = DB::select('SELECT RDB$RELATION_TYPE AS "type" FROM RDB$RELATIONS '
            .'WHERE RDB$RELATION_NAME IN (?, ?) ORDER BY RDB$RELATION_TYPE', ['wipe_gtt_keep', 'wipe_gtt_delete']);
        $this->assertSame([4, 5], array_map(fn ($row) => (int) $row->type, $before));
        Schema::dropAllTables();
        $this->assertSame([], DB::select('SELECT 1 FROM RDB$RELATIONS WHERE RDB$RELATION_NAME IN (?, ?)',
            ['wipe_gtt_keep', 'wipe_gtt_delete']));
    }

    #[Test]
    public function it_rejects_external_tables_before_dropping_anything(): void
    {
        $this->table('wipe_data');
        DB::table('wipe_data')->insert(['other_id' => 6]);
        // Defining metadata requires no external-file access; do not read or write the file.
        DB::statement('CREATE TABLE "wipe_external" EXTERNAL FILE \'wipe_external_test.txt\' ("id" INTEGER)');
        $this->objects[] = ['TABLE', 'wipe_external'];
        try {
            Schema::dropAllTables();
            $this->fail('External tables must be rejected explicitly.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('relation type 2', $e->getMessage());
        }
        $this->assertIdle();
        $this->assertSame([6], DB::table('wipe_data')->pluck('other_id')->all());
        $this->assertCount(1, DB::select('SELECT 1 FROM RDB$RELATIONS WHERE RDB$RELATION_NAME = ?', ['wipe_external']));
    }

    #[Test]
    public function it_rolls_back_a_failure_during_statement_execution(): void
    {
        $this->table('wipe_a');
        $this->table('wipe_b');
        $error = new \RuntimeException('Abort between DROP statements');
        $abort = true;
        $executed = [];
        DB::listen(function ($event) use (&$executed) {
            $executed[] = $event->sql;
        });
        DB::connection()->beforeExecuting(function ($sql) use (&$abort, $error) {
            if ($abort && $sql === 'DROP TABLE "wipe_b"') {
                $abort = false;
                throw $error;
            }
        });
        try {
            Schema::dropAllTables();
            $this->fail('The original exception must be rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame($error, $e);
        }
        $this->assertContains('DROP TABLE "wipe_a"', $executed);
        $this->assertIdle();
        $this->assertCount(2, Schema::getTables());
    }

    #[Test]
    public function it_runs_db_wipe_with_views(): void
    {
        $this->table('wipe_data');
        $this->createView('wipe_view', 'wipe_data');
        $this->artisan('db:wipe', ['--drop-views' => true, '--force' => true])->assertExitCode(0);
        $this->assertSame([], Schema::getViews());
        $this->assertSame([], Schema::getTables());
    }

    #[Test]
    public function it_runs_migrate_fresh_and_rebuilds_the_schema(): void
    {
        $this->artisan('migrate:install')->assertExitCode(0);
        $this->objects[] = ['TABLE', 'migrations'];
        $this->table('wipe_data');
        $this->createView('wipe_view', 'wipe_data');
        $this->objects[] = ['TABLE', 'wipe_fresh'];
        $this->artisan('migrate:fresh', [
            '--drop-views' => true,
            '--path' => __DIR__.'/fixtures/wipe-migrations',
            '--realpath' => true,
            '--force' => true,
        ])->assertExitCode(0);
        $this->assertSame([], Schema::getViews());
        $this->assertFalse(Schema::hasTable('wipe_data'));
        $this->assertTrue(Schema::hasTable('wipe_fresh'));
        $this->assertSame(1, DB::table('wipe_fresh')->insertGetId([]));
    }

}
