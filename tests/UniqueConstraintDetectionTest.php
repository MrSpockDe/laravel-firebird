<?php

namespace HarryGulliford\Firebird\Tests;

use HarryGulliford\Firebird\FirebirdConnection;
use HarryGulliford\Firebird\FirebirdConnector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

class UniqueConstraintDetectionTest extends TestCase
{
    #[Test]
    #[DataProvider('raceMethods')]
    public function it_recovers_from_a_competing_insert(string $method): void
    {
        $b = null;
        try {
            Schema::create('unique_race_test', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('value');
            });
            $config = DB::connection()->getConfig();
            $config['options'][PDO::ATTR_PERSISTENT] = false;
            $b = new FirebirdConnection((new FirebirdConnector)->connect($config), $config['database'], '', $config);
            $attachment = 'SELECT CURRENT_CONNECTION AS "id" FROM RDB$DATABASE';
            $this->assertNotSame(DB::selectOne($attachment)->id, $b->selectOne($attachment)->id);
            $createdId = null;
            $calls = 0;
            UniqueRaceModel::creating(function () use ($b, &$createdId, &$calls) {
                $calls++;
                // Eloquent has already performed its lookup when this event fires.
                $this->assertSame(0, $b->table('unique_race_test')->count());
                $b->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
                $b->beginTransaction();
                $createdId = $b->table('unique_race_test')->insertGetId(['name' => 'same', 'value' => 'competitor']);
                $b->commit();
            });
            $model = UniqueRaceModel::$method(['name' => 'same'], ['value' => 'requested']);
            $this->assertSame(1, $calls);
            $this->assertSame($createdId, $model->getKey());
            $this->assertFalse($model->wasRecentlyCreated);
            $expected = $method === 'firstOrCreate' ? 'competitor' : 'requested';
            $this->assertSame($expected, $model->fresh()->value);
            $this->assertSame(1, DB::table('unique_race_test')->count());
        } finally {
            UniqueRaceModel::flushEventListeners();
            if ($b !== null && $b->transactionLevel() > 0) {
                $b->rollBack();
            }
            $b?->disconnect();
            Schema::dropIfExists('unique_race_test');
        }
    }

    public static function raceMethods(): iterable
    {
        yield ['firstOrCreate'];
        yield ['updateOrCreate'];
    }

    #[Test]
    #[DataProvider('constraintKinds')]
    public function it_classifies_only_duplicate_key_violations(string $kind, array $row, bool $unique): void
    {
        try {
            Schema::create('unique_detection_parent', fn (Blueprint $b) => $b->integer('id')->primary());
            Schema::create('unique_detection_child', function (Blueprint $b) {
                $b->integer('id')->primary();
                $b->string('name')->unique();
                $b->integer('parent_id');
                $b->foreign('parent_id')->references('id')->on('unique_detection_parent');
            });
            DB::table('unique_detection_parent')->insert(['id' => 1]);
            DB::table('unique_detection_child')->insert(['id' => 1, 'name' => 'existing', 'parent_id' => 1]);
            try {
                DB::table('unique_detection_child')->insert($row);
                $this->fail('Constraint violation accepted: '.$kind);
            } catch (QueryException $e) {
                $this->assertSame($unique, $e instanceof UniqueConstraintViolationException);
                $this->assertInstanceOf(PDOException::class, $e->getPrevious());
                $this->assertSame($unique ? -803 : ($kind === 'foreign' ? -530 : -625), $e->getPrevious()->errorInfo[1]);
            }
            $this->assertSame(1, DB::table('unique_detection_child')->count());
        } finally {
            Schema::dropIfExists('unique_detection_child');
            Schema::dropIfExists('unique_detection_parent');
        }
    }

    public static function constraintKinds(): iterable
    {
        yield 'unique' => ['unique', ['id' => 2, 'name' => 'existing', 'parent_id' => 1], true];
        yield 'primary' => ['primary', ['id' => 1, 'name' => 'new', 'parent_id' => 1], true];
        yield 'foreign' => ['foreign', ['id' => 2, 'name' => 'new', 'parent_id' => 99], false];
        yield 'not null' => ['not null', ['id' => 2, 'name' => null, 'parent_id' => 1], false];
    }

    #[Test]
    #[DataProvider('errorSignals')]
    public function it_uses_sqlcode_instead_of_sqlstate_or_message(?array $info, string $message, bool $expected): void
    {
        $exception = new PDOException($message);
        $exception->errorInfo = $info;
        $method = new ReflectionMethod(FirebirdConnection::class, 'isUniqueConstraintError');
        $this->assertSame($expected, $method->invoke(DB::connection(), $exception));
    }

    public static function errorSignals(): iterable
    {
        yield '23000' => [['23000', -803, 'unknown ISC error'], 'unknown ISC error', true];
        yield 'HY000' => [['HY000', -803, 'unknown ISC error'], 'unknown ISC error', true];
        yield 'string SQLCODE' => [['HY000', '-803', ''], '', true];
        yield 'foreign' => [['23000', -530, ''], 'UNIQUE', false];
        yield 'not null' => [['23000', -625, ''], 'UNIQUE', false];
        yield 'missing SQLCODE' => [['23000'], 'duplicate value in unique index', false];
        yield 'missing errorInfo' => [null, 'duplicate value in unique index', false];
    }
}

class UniqueRaceModel extends Model
{
    protected $table = 'unique_race_test';
    protected $guarded = [];
    public $timestamps = false;
}
