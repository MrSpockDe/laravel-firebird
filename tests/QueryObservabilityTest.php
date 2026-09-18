<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class QueryObservabilityTest extends TestCase
{
    #[Test]
    public function it_pretends_a_firebird_update_without_changing_data()
    {
        $connection = DB::connection();
        $logging = $connection->logging();
        try {
            Schema::create('observe_query', fn (Blueprint $table) => $table->integer('value'));
            DB::table('observe_query')->insert(['value' => 1]);
            $connection->disableQueryLog();

            $log = DB::pretend(fn () => DB::table('observe_query')
                ->where('value', 1)->orderBy('value')->limit(1)->update(['value' => 7]));

            $this->assertCount(1, $log);
            $this->assertSame('update "observe_query" set "value" = 7 where "value" = 1 order by "value" asc rows 1', $log[0]['query']);
            $this->assertSame([7, 1], $log[0]['bindings']);
            $this->assertIsNumeric($log[0]['time']);
            $this->assertFalse($connection->pretending());
            $this->assertFalse($connection->logging());
            $this->assertSame([1], DB::table('observe_query')->pluck('value')->all());

            $this->assertSame(1, DB::table('observe_query')->where('value', 1)->update(['value' => 7]));
            $this->assertSame([7], DB::table('observe_query')->pluck('value')->all());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
            Schema::dropIfExists('observe_query');
            if ($logging) {
                $connection->enableQueryLog();
            }
        }
    }

    #[Test]
    public function it_logs_and_dispatches_an_executed_firebird_query()
    {
        $connection = DB::connection();
        $logging = $connection->logging();
        $dispatcher = $connection->getEventDispatcher();
        $events = [];
        try {
            Schema::create('observe_query', fn (Blueprint $table) => $table->integer('value'));
            DB::table('observe_query')->insert([['value' => 1], ['value' => 2]]);

            // Isolate this listener without removing listeners owned by the application.
            $connection->setEventDispatcher(new Dispatcher);
            $connection->listen(function (QueryExecuted $event) use (&$events) {
                $events[] = $event;
            });
            $connection->flushQueryLog();
            $connection->enableQueryLog();
            $query = DB::table('observe_query')->select('value')
                ->where('value', '&', 1)->where('value', '>', 0)->orderBy('value');
            $sql = $query->toSql();
            $bindings = $query->getBindings();
            $this->assertStringContainsString('BIN_AND(', strtoupper($sql));
            $this->assertSame([1, 0], $bindings);
            $this->assertSame([1], $query->get()->pluck('value')->all());

            $log = $connection->getQueryLog();
            $this->assertCount(1, $log);
            $this->assertSame($sql, $log[0]['query']);
            $this->assertSame($bindings, $log[0]['bindings']);
            $this->assertIsNumeric($log[0]['time']);
            $this->assertGreaterThanOrEqual(0, $log[0]['time']);
            $this->assertCount(1, $events);
            $this->assertSame($connection, $events[0]->connection);
            $this->assertSame($connection->getName(), $events[0]->connectionName);
            $this->assertSame($sql, $events[0]->sql);
            $this->assertSame($bindings, $events[0]->bindings);
            $this->assertSame($log[0]['time'], $events[0]->time);
            $this->assertSame($sql, $query->toSql());
            $this->assertSame($bindings, $query->getBindings());
        } finally {
            if ($dispatcher !== null) {
                $connection->setEventDispatcher($dispatcher);
            } else {
                $connection->unsetEventDispatcher();
            }
            $connection->disableQueryLog();
            $connection->flushQueryLog();
            Schema::dropIfExists('observe_query');
            if ($logging) {
                $connection->enableQueryLog();
            }
        }
    }
}
