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
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

class ConcurrencyDetectionTest extends TestCase
{
    #[Test]
    #[DataProvider('errorSignals')]
    public function it_classifies_structured_concurrency_errors(string $state, ?array $info, string $wrapper, bool $expected): void
    {
        $exception = new PDOException('Opaque driver error');
        (new ReflectionProperty(\Exception::class, 'code'))->setValue($exception, $state);
        $exception->errorInfo = $info;
        if ($wrapper === 'query') {
            $exception = new QueryException('firebird', 'update "example" set "value" = ?', [1], $exception);
        } elseif ($wrapper === 'nested') {
            $exception = new RuntimeException('Outer exception', 0,
                new RuntimeException('Inner exception', 0, $exception));
        }

        $this->assertSame($expected, $this->detect($exception));
    }

    public static function errorSignals(): iterable
    {
        yield 'direct old PDO conflict' => ['HY000', ['HY000', -913, ''], 'direct', true];
        yield 'wrapped old PDO conflict' => ['HY000', ['HY000', -913, ''], 'query', true];
        yield 'nested PDO conflict' => ['HY000', ['HY000', -913, ''], 'nested', true];
        yield 'string SQLCODE' => ['HY000', ['HY000', '-913', ''], 'direct', true];
        yield 'Laravel SQLSTATE fallback' => ['40001', ['40001'], 'direct', true];
        yield 'wrapped Laravel fallback' => ['40001', ['40001'], 'query', true];
        yield 'unique' => ['HY000', ['HY000', -803, ''], 'direct', false];
        yield 'foreign key' => ['23000', ['23000', -530, ''], 'query', false];
        yield 'not null' => ['23000', ['23000', -625, ''], 'direct', false];
        yield 'old PDO binding error' => ['HY105', ['HY105', -999, ''], 'direct', false];
        yield 'other general error' => ['HY000', ['HY000', -204, ''], 'nested', false];
        yield 'missing SQLCODE' => ['HY000', ['HY000'], 'direct', false];
        yield 'missing errorInfo' => ['HY000', null, 'query', false];
    }

    #[Test]
    public function it_recognizes_a_real_no_wait_lock_conflict(): void
    {
        $a = $b = null;
        $bTransactionOpen = false;
        try {
            Schema::create('concurrency_detection_test', function (Blueprint $table) {
                $table->integer('id')->primary();
                $table->integer('value');
            });
            DB::table('concurrency_detection_test')->insert(['id' => 1, 'value' => 0]);
            $config = DB::connection()->getConfig();
            $config['options'][PDO::ATTR_PERSISTENT] = false;
            $connector = new FirebirdConnector;
            $a = new FirebirdConnection($connector->connect($config), $config['database'], '', $config);
            $b = new FirebirdConnection($connector->connect($config), $config['database'], '', $config);
            $a->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
            $a->beginTransaction();
            $b->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
            $b->statement('SET TRANSACTION READ WRITE ISOLATION LEVEL READ COMMITTED NO WAIT');
            $bTransactionOpen = true;
            $this->assertSame(0, (int) $b->selectOne(
                'SELECT MON$LOCK_TIMEOUT AS "timeout" FROM MON$TRANSACTIONS '
                .'WHERE MON$TRANSACTION_ID = CURRENT_TRANSACTION')->timeout);
            $attachment = 'SELECT CURRENT_CONNECTION AS "id" FROM RDB$DATABASE';
            $this->assertNotSame($a->selectOne($attachment)->id, $b->selectOne($attachment)->id);
            $this->assertSame(0, $a->table('concurrency_detection_test')->where('id', 1)
                ->lockForUpdate()->first()->value);
            try {
                $b->table('concurrency_detection_test')->where('id', 1)->update(['value' => 1]);
                $this->fail('The competing write did not encounter the lock.');
            } catch (QueryException $exception) {
                $this->assertInstanceOf(PDOException::class, $exception->getPrevious());
                $this->assertSame(-913, (int) $exception->getPrevious()->errorInfo[1]);
                $this->assertTrue($this->detect($exception));
                $this->assertTrue($this->detect($exception->getPrevious()));
            }
            $this->assertSame(0, $b->table('concurrency_detection_test')->where('id', 1)->value('value'));
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
                        $a->rollBack(0);
                    }
                } finally {
                    $a?->disconnect();
                    Schema::dropIfExists('concurrency_detection_test');
                }
            }
        }
    }

    private function detect(Throwable $exception): bool
    {
        return (new ReflectionMethod(FirebirdConnection::class, 'causedByConcurrencyError'))
            ->invoke(DB::connection(), $exception);
    }
}
