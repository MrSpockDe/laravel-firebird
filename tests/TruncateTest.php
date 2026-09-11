<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class TruncateTest extends TestCase
{
    #[Test]
    public function it_rejects_truncate_before_executing_sql()
    {
        $name = 'truncate_rejection_test';
        try {
            Schema::create($name, fn (Blueprint $table) => $table->integer('id'));
            DB::table($name)->insert(['id' => 1]);
            DB::table($name)->insert(['id' => 2]);
            $queries = [];
            DB::connection()->beforeExecuting(function ($sql) use (&$queries) {
                $queries[] = $sql;
            });
            $exception = null;
            try {
                DB::table($name)->truncate();
            } catch (\LogicException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(\LogicException::class, $exception);
            $this->assertSame('Firebird does not support truncate operations.', $exception->getMessage());
            $this->assertSame([], $queries);
            $this->assertSame([1, 2], DB::table($name)->orderBy('id')->pluck('id')->all());
            $this->assertSame(1, DB::table($name)->where('id', 1)->delete());
            $this->assertSame([2], DB::table($name)->pluck('id')->all());
            $this->assertSame(1, DB::table($name)->delete());
            $this->assertSame(0, DB::table($name)->count());
        } finally {
            Schema::dropIfExists($name);
        }
    }
}
