<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class JoinDmlTest extends TestCase
{
    #[Test]
    #[DataProvider('joinOperations')]
    public function it_rejects_join_dml_without_changing_state(string $join, string $operation)
    {
        $users = 'join_dml_users';
        $accounts = 'join_dml_accounts';

        try {
            Schema::create($users, function (Blueprint $table) {
                $table->integer('id');
                $table->string('status');
            });
            Schema::create($accounts, function (Blueprint $table) {
                $table->integer('user_id');
                $table->boolean('active');
            });
            DB::table($users)->insert(['id' => 1, 'status' => 'active']);
            DB::table($accounts)->insert(['user_id' => 1, 'active' => false]);

            $query = DB::table($users)->$join($accounts, function ($clause) use ($users, $accounts) {
                $clause->on("$accounts.user_id", '=', "$users.id")->where("$accounts.active", false);
            })->where("$users.status", 'active')->orderBy("$users.id")->limit(1);
            $beforeSql = $query->toSql();
            $beforeBindings = $query->getRawBindings();
            $beforeWheres = $query->wheres;
            $beforeJoins = array_map(fn ($clause) => [$clause->type, $clause->table, $clause->wheres, $clause->getRawBindings()], $query->joins);
            $connection = DB::connection();
            $logging = $connection->logging();
            $connection->enableQueryLog();
            $beforeLog = $connection->getQueryLog();
            $exception = null;

            try {
                if ($operation === 'update') {
                    $query->update(["$users.status" => 'inactive']);
                } else {
                    $query->delete();
                }
            } catch (\LogicException $caught) {
                $exception = $caught;
            } finally {
                if (! $logging) {
                    $connection->disableQueryLog();
                }
            }

            $this->assertInstanceOf(\LogicException::class, $exception);
            $this->assertSame("Firebird does not support $operation operations with joins.", $exception->getMessage());
            $this->assertSame($beforeLog, $connection->getQueryLog());
            $this->assertSame($beforeSql, $query->toSql());
            $this->assertSame($beforeBindings, $query->getRawBindings());
            $this->assertSame($beforeWheres, $query->wheres);
            $this->assertSame($beforeJoins, array_map(fn ($clause) => [$clause->type, $clause->table, $clause->wheres, $clause->getRawBindings()], $query->joins));
            $this->assertSame(1, $query->limit);
            $this->assertSame(['active'], DB::table($users)->pluck('status')->all());
            $this->assertSame([false], DB::table($accounts)->pluck('active')->all());
        } finally {
            Schema::dropIfExists($accounts);
            Schema::dropIfExists($users);
        }
    }

    public static function joinOperations(): iterable
    {
        yield 'inner update' => ['join', 'update'];
        yield 'left update' => ['leftJoin', 'update'];
        yield 'inner delete' => ['join', 'delete'];
        yield 'left delete' => ['leftJoin', 'delete'];
    }
}
