<?php

namespace HarryGulliford\Firebird\Tests;

use HarryGulliford\Firebird\FirebirdConnection;
use HarryGulliford\Firebird\FirebirdConnector;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class TransactionLifecycleTest extends TestCase
{
    private const TABLE = 'transaction_lifecycle_test';

    #[Test]
    public function it_commits_an_explicit_transaction(): void
    {
        $this->withTable(function ($a) {
            $this->assertIdle($a);
            $a->beginTransaction();
            $this->assertSame(1, $a->transactionLevel());
            $this->assertTrue($a->getPdo()->inTransaction());
            $a->table(self::TABLE)->insert(['id' => 1, 'value' => 'committed']);
            $a->commit();
            $this->assertIdle($a);
            $this->assertSame([1 => 'committed'], $this->committedRows());
        });
    }

    #[Test]
    public function it_rolls_back_an_explicit_transaction(): void
    {
        $this->withTable(function ($a) {
            $this->assertIdle($a);
            $a->beginTransaction();
            $this->assertSame(1, $a->transactionLevel());
            $a->table(self::TABLE)->insert(['id' => 1, 'value' => 'discarded']);
            $a->rollBack();
            $this->assertIdle($a);
            $this->assertSame([], $this->committedRows());
        });
    }

    #[Test]
    public function it_commits_a_transaction_closure_and_returns_its_result(): void
    {
        $this->withTable(function ($a) {
            $result = DB::transaction(function ($connection) use ($a) {
                $this->assertSame($a, $connection);
                $this->assertSame(1, $connection->transactionLevel());
                $connection->table(self::TABLE)->insert(['id' => 1, 'value' => 'closure']);

                return ['result' => 42];
            });
            $this->assertSame(['result' => 42], $result);
            $this->assertIdle($a);
            $this->assertSame([1 => 'closure'], $this->committedRows());
        });
    }

    #[Test]
    public function it_rolls_back_and_rethrows_a_transaction_closure_exception(): void
    {
        $this->withTable(function ($a) {
            $exception = new RuntimeException('Abort the transaction');
            try {
                DB::transaction(function ($connection) use ($exception) {
                    $connection->table(self::TABLE)->insert(['id' => 1, 'value' => 'first']);
                    $connection->table(self::TABLE)->insert(['id' => 2, 'value' => 'second']);
                    throw $exception;
                });
                $this->fail('The closure exception was swallowed.');
            } catch (RuntimeException $caught) {
                $this->assertSame($exception, $caught);
            }
            $this->assertIdle($a);
            $this->assertSame([], $this->committedRows());
            DB::transaction(fn ($connection) => $connection->table(self::TABLE)
                ->insert(['id' => 3, 'value' => 'reused']));
            $this->assertIdle($a);
            $this->assertSame([3 => 'reused'], $this->committedRows());
        });
    }

    #[Test]
    public function it_rolls_back_a_savepoint_without_losing_outer_changes(): void
    {
        $this->withTable(function ($a) {
            $this->assertIdle($a);
            $a->beginTransaction();
            $this->assertSame(1, $a->transactionLevel());
            $a->table(self::TABLE)->insert(['id' => 1, 'value' => 'outer']);
            $a->beginTransaction();
            $this->assertSame(2, $a->transactionLevel());
            $a->table(self::TABLE)->insert(['id' => 2, 'value' => 'inner']);
            $a->rollBack();
            $this->assertSame(1, $a->transactionLevel());
            $this->assertSame([1 => 'outer'], $a->table(self::TABLE)->pluck('value', 'id')->all());
            $a->commit();
            $this->assertIdle($a);
            $this->assertSame([1 => 'outer'], $this->committedRows());
        });
    }

    #[Test]
    public function it_rolls_back_inner_commits_with_the_outer_transaction(): void
    {
        $this->withTable(function ($a) {
            $this->assertIdle($a);
            $a->beginTransaction();
            $this->assertSame(1, $a->transactionLevel());
            $a->table(self::TABLE)->insert(['id' => 1, 'value' => 'outer']);
            $a->beginTransaction();
            $this->assertSame(2, $a->transactionLevel());
            $a->table(self::TABLE)->insert(['id' => 2, 'value' => 'inner']);
            $a->commit();
            $this->assertSame(1, $a->transactionLevel());
            $this->assertSame([1 => 'outer', 2 => 'inner'],
                $a->table(self::TABLE)->orderBy('id')->pluck('value', 'id')->all());
            $a->rollBack();
            $this->assertIdle($a);
            $this->assertSame([], $this->committedRows());
        });
    }

    #[Test]
    public function it_exposes_changes_to_an_independent_connection_only_after_commit(): void
    {
        $this->withTable(function ($a) {
            $b = $this->independentConnection();
            $bTransactionOpen = false;
            try {
                $a->beginTransaction();
                $a->table(self::TABLE)->insert(['id' => 1, 'value' => 'visible after commit']);
                $b->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
                $b->statement('SET TRANSACTION READ WRITE ISOLATION LEVEL READ COMMITTED NO WAIT');
                $bTransactionOpen = true;
                $metadata = $b->selectOne('SELECT MON$ISOLATION_MODE AS "isolation", '
                    .'MON$LOCK_TIMEOUT AS "timeout" FROM MON$TRANSACTIONS '
                    .'WHERE MON$TRANSACTION_ID = CURRENT_TRANSACTION');
                $this->assertContains((int) $metadata->isolation, [2, 3, 4]);
                $this->assertSame(0, (int) $metadata->timeout);
                $this->assertNotSame($this->attachmentId($a), $this->attachmentId($b));
                $this->assertSame([], $b->table(self::TABLE)->pluck('value', 'id')->all());
                $a->commit();
                $this->assertIdle($a);
                $this->assertSame([1 => 'visible after commit'],
                    $b->table(self::TABLE)->pluck('value', 'id')->all());
            } finally {
                try {
                    if ($bTransactionOpen) {
                        $b->statement('ROLLBACK WORK');
                        $bTransactionOpen = false;
                    }
                } finally {
                    $b->disconnect();
                }
            }
        });
    }

    #[Test]
    public function it_retries_a_snapshot_write_conflict_with_a_fresh_transaction(): void
    {
        $this->withTable(function ($a) {
            DB::transaction(fn ($connection) => $connection->table(self::TABLE)
                ->insert(['id' => 1, 'value' => 'initial']));
            // PHP 8.4+ exposes this setting. Older PDO versions use SNAPSHOT
            // for explicit transactions; verify the actual mode on every attempt.
            if (defined('Pdo\\Firebird::TRANSACTION_ISOLATION_LEVEL')) {
                $a->getPdo()->setAttribute(constant('Pdo\\Firebird::TRANSACTION_ISOLATION_LEVEL'),
                    constant('Pdo\\Firebird::REPEATABLE_READ'));
            }
            $b = $this->independentConnection();
            $b->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
            $attempts = 0;
            $conflicts = [];
            try {
                DB::transaction(function ($connection) use ($b, &$attempts, &$conflicts) {
                    $attempts++;
                    $this->assertSame(1, $connection->transactionLevel());
                    $this->assertSame(1, (int) $connection->selectOne(
                        'SELECT MON$ISOLATION_MODE AS "mode" FROM MON$TRANSACTIONS '
                        .'WHERE MON$TRANSACTION_ID = CURRENT_TRANSACTION')->mode);
                    $this->assertSame($attempts === 1 ? 'initial' : 'competitor',
                        $connection->table(self::TABLE)->where('id', 1)->value('value'));
                    if ($attempts === 1) {
                        $b->beginTransaction();
                        $this->assertNotSame($this->attachmentId($connection), $this->attachmentId($b));
                        $b->table(self::TABLE)->where('id', 1)->update(['value' => 'competitor']);
                        $b->commit();
                        $this->assertIdle($b);
                    }
                    try {
                        $connection->table(self::TABLE)->where('id', 1)->update(['value' => 'retried']);
                    } catch (QueryException $exception) {
                        $conflicts[] = $exception;
                        throw $exception;
                    }
                }, 3);
                $this->assertSame(2, $attempts);
                $this->assertCount(1, $conflicts);
                $this->assertSame(-913, (int) $conflicts[0]->errorInfo[1]);
                $this->assertIdle($a);
                $this->assertSame([1 => 'retried'], $this->committedRows());
                DB::transaction(fn ($connection) => $connection->table(self::TABLE)
                    ->insert(['id' => 2, 'value' => 'reused']));
                $this->assertIdle($a);
                $this->assertSame([1 => 'retried', 2 => 'reused'], $this->committedRows());
            } finally {
                try {
                    if ($b->transactionLevel() > 0) {
                        $b->rollBack(0);
                    }
                } finally {
                    $b->disconnect();
                }
            }
        });
    }

    #[Test]
    public function it_discards_an_open_transaction_on_disconnect_and_can_reconnect(): void
    {
        $this->withTable(function ($a) {
            $a->beginTransaction();
            $oldAttachment = $this->attachmentId($a);
            $a->table(self::TABLE)->insert(['id' => 1, 'value' => 'uncommitted']);
            $this->assertSame(1, $a->transactionLevel());
            $this->assertTrue($a->getPdo()->inTransaction());
            $a->disconnect();
            $this->assertSame(0, $a->transactionLevel());
            $this->assertNull($a->getRawPdo());
            $this->assertSame([], $this->committedRows());
            $a->reconnect();
            // End the connector's implicit autocommit transaction on older PDO
            // before checking the explicit transaction lifecycle again.
            $a->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
            $this->assertIdle($a);
            $a->beginTransaction();
            $this->assertNotSame($oldAttachment, $this->attachmentId($a));
            $this->assertSame([], $a->table(self::TABLE)->pluck('value', 'id')->all());
            $a->table(self::TABLE)->insert(['id' => 2, 'value' => 'after reconnect']);
            $a->commit();
            $this->assertIdle($a);
            $this->assertSame([2 => 'after reconnect'], $this->committedRows());
        });
    }

    private function withTable(callable $test): void
    {
        $a = DB::connection();
        try {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->integer('id')->primary();
                $table->string('value');
            });
            // Portable transition from the connector's implicit transaction to
            // Laravel-managed explicit transactions (including PHP 8.2/8.3).
            $a->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
            $test($a);
        } finally {
            try {
                if ($a->transactionLevel() > 0) {
                    $a->rollBack(0);
                }
            } finally {
                $a->disconnect();
                Schema::dropIfExists(self::TABLE);
            }
        }
    }

    private function independentConnection(): FirebirdConnection
    {
        $config = DB::connection()->getConfig();
        $config['options'][PDO::ATTR_PERSISTENT] = false;

        return new FirebirdConnection((new FirebirdConnector)->connect($config), $config['database'], '', $config);
    }

    private function committedRows(): array
    {
        // A fresh attachment avoids a retained snapshot on older autocommit PDO.
        $reader = $this->independentConnection();
        try {
            return $reader->table(self::TABLE)->orderBy('id')->pluck('value', 'id')->all();
        } finally {
            $reader->disconnect();
        }
    }

    private function assertIdle(FirebirdConnection $connection): void
    {
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function attachmentId(FirebirdConnection $connection): int
    {
        return (int) $connection->selectOne('SELECT CURRENT_CONNECTION AS "id" FROM RDB$DATABASE')->id;
    }
}
