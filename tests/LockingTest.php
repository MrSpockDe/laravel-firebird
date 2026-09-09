<?php

namespace HarryGulliford\Firebird\Tests;

use HarryGulliford\Firebird\FirebirdConnection;
use HarryGulliford\Firebird\FirebirdConnector;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class LockingTest extends TestCase
{
    #[Test]
    #[DataProvider('rowLockCases')]
    public function it_checks_row_locking_between_independent_connections(string $mode, string $operation, bool $shouldLock)
    {
        $table = 'row_lock_test';
        $a = $b = null;
        $bTransactionOpen = false;
        Schema::dropIfExists($table);

        try {
            Schema::create($table, function (Blueprint $table) {
                $table->integer('id')->primary();
                $table->string('value');
            });
            DB::table($table)->insert(['id' => 1, 'value' => 'Original']);
            DB::table($table)->insert(['id' => 2, 'value' => 'Outside']);

            $config = DB::connection()->getConfig();
            $config['options'][PDO::ATTR_PERSISTENT] = false;
            $connector = new FirebirdConnector;
            $a = new FirebirdConnection($connector->connect($config), $config['database'], '', $config);
            $b = new FirebirdConnection($connector->connect($config), $config['database'], '', $config);

            $a->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
            $a->beginTransaction();
            // PDO has no NO WAIT attribute. Use an explicit Firebird transaction
            // with autocommit disabled, and explicitly roll it back before disconnecting.
            $b->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
            $b->statement('SET TRANSACTION READ WRITE ISOLATION LEVEL READ COMMITTED NO WAIT');
            $bTransactionOpen = true;

            $attachmentSql = 'select CURRENT_CONNECTION as "id" from RDB$DATABASE';
            $this->assertNotSame($a->selectOne($attachmentSql)->id, $b->selectOne($attachmentSql)->id);
            $this->assertSame(0, (int) $b->selectOne(
                'select MON$LOCK_TIMEOUT as "timeout" from MON$TRANSACTIONS '
                .'where MON$TRANSACTION_ID = CURRENT_TRANSACTION'
            )->timeout, 'Never attempt the competing write with an unbounded WAIT transaction.');

            $query = $a->table($table)->where('id', 1);
            if ($mode === 'laravel') {
                $query->lockForUpdate();
            } elseif ($mode === 'true') {
                $query->lock(true);
            } elseif ($mode !== 'plain') {
                $query->lock($mode);
            }
            $this->assertSame('Original', $query->first()->value);

            // A row outside the selection must remain writable while A is open.
            $this->assertSame(1, $b->table($table)->where('id', 2)->update(['value' => 'Writable']));
            $conflict = null;
            $affected = null;
            try {
                $target = $b->table($table)->where('id', 1);
                $affected = $operation === 'update'
                    ? $target->update(['value' => 'Changed'])
                    : $target->delete();
            } catch (QueryException $e) {
                $conflict = $e;
                $this->assertSame(-913, (int) ($e->errorInfo[1] ?? 0));
                $this->assertSame('Original', $b->table($table)->where('id', 1)->value('value'));
            }

            $a->rollBack();
            if ($conflict !== null) {
                // Closing A's transaction must release the lock for the same B.
                $target = $b->table($table)->where('id', 1);
                $affected = $operation === 'update'
                    ? $target->update(['value' => 'Changed'])
                    : $target->delete();
            }
            $this->assertSame(1, $affected);
            $this->assertSame(
                $operation === 'update' ? 'Changed' : null,
                $b->table($table)->where('id', 1)->value('value')
            );

            $b->statement('ROLLBACK WORK');
            $bTransactionOpen = false;
            $b->disconnect();
            $this->assertSame([1 => 'Original', 2 => 'Outside'],
                DB::table($table)->orderBy('id')->pluck('value', 'id')->all());
            $this->assertSame($shouldLock, $conflict !== null,
                'Connection B must encounter a lock conflict exactly when A requested a row lock.');
        } finally {
            try {
                if ($bTransactionOpen) {
                    $b->statement('ROLLBACK WORK');
                    $bTransactionOpen = false;
                }
            } finally {
                $b?->disconnect();
                try {
                    if ($a !== null && $a->transactionLevel() > 0) {
                        $a->rollBack();
                    }
                } finally {
                    $a?->disconnect();
                    Schema::dropIfExists($table);
                }
            }
        }
    }

    public static function rowLockCases(): iterable
    {
        foreach (['update', 'delete'] as $operation) {
            foreach (['plain' => false, 'for update' => false, 'with lock' => true,
                'for update with lock' => true, 'laravel' => true, 'true' => true] as $mode => $shouldLock) {
                yield $mode.' / '.$operation => [$mode, $operation, $shouldLock];
            }
        }
    }

    #[Test]
    public function it_compiles_explicit_locks_without_changing_bindings()
    {
        $base = DB::table('users')->where('id', 42)->limit(1);
        $sql = 'select * from "users" where "id" = ? fetch first 1 rows only';
        foreach ([true, 'WITH LOCK', 'for update', 'for update with lock', ''] as $lock) {
            $query = (clone $base)->lock($lock);
            $this->assertSame(rtrim($sql.' '.($lock === true ? 'for update with lock' : $lock)), $query->toSql());
            $this->assertSame([42], $query->getBindings());
        }
        $this->assertSame((clone $base)->lock(true)->toSql(), (clone $base)->lockForUpdate()->toSql());
    }

    #[Test]
    #[DataProvider('sharedLockMethods')]
    public function it_rejects_shared_locks(string $method)
    {
        $query = DB::table('users')->where('id', 42);
        $method === 'sharedLock' ? $query->sharedLock() : $query->lock(false);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This database driver does not support shared locks.');
        $query->toSql();
    }

    public static function sharedLockMethods(): iterable
    {
        yield ['sharedLock'];
        yield ['lock'];
    }

    #[Test]
    #[DataProvider('unsupportedLockQueries')]
    public function it_rejects_unsupported_locked_selects(string $form)
    {
        $table = 'unsupported_lock_test';
        Schema::dropIfExists($table);
        try {
            Schema::create($table, function (Blueprint $table) {
                $table->integer('id')->primary();
            });
            DB::table($table)->insert(['id' => 1]);
            $query = DB::table($table)->select($table.'.id')->lockForUpdate();
            match ($form) {
                'join' => $query->join($table.' as other', $table.'.id', '=', 'other.id'),
                'union' => $query->union(DB::table($table)->select('id')),
                'ordered_union' => $query->union(DB::table($table)->select('id'))->orderBy('id'),
                'aggregate' => $query->select(DB::raw('count(*) as "total"')),
            };
            $this->assertStringContainsString('for update with lock', $query->toSql());
            try {
                $query->get();
                $this->fail('Firebird must reject this SELECT rather than silently omit the lock.');
            } catch (QueryException $e) {
                $this->assertSame(-104, (int) ($e->errorInfo[1] ?? 0));
                $this->assertStringContainsString('for update with lock', $e->getSql());
            }
        } finally {
            Schema::dropIfExists($table);
        }
    }

    public static function unsupportedLockQueries(): iterable
    {
        foreach (['join', 'union', 'ordered_union', 'aggregate'] as $form) {
            yield $form => [$form];
        }
    }
}
