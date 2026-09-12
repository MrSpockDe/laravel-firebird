<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class IdentityOptionsTest extends TestCase
{
    #[Test]
    #[DataProvider('definitions')]
    public function it_applies_new_identity_options(string $method, array $options, int $start, bool $always)
    {
        $name = 'identity_options_test';
        try {
            $migration = fn () => Schema::create($name, function (Blueprint $table) use ($method, $options) {
                $column = $table->$method('id');
                foreach ($options as $option => $value) {
                    $column->$option($value);
                }
            });
            $sql = DB::pretend($migration)[0]['query'];
            $migration();
            $mode = $always ? 'ALWAYS' : 'BY DEFAULT';
            $this->assertStringContainsString('GENERATED '.$mode.' AS IDENTITY', $sql);
            if ($start === 42) {
                $this->assertStringContainsString('AS IDENTITY (START WITH 42) PRIMARY KEY', $sql);
            }
            $metadata = DB::selectOne(<<<'SQL'
                SELECT rf.RDB$IDENTITY_TYPE AS "identity_type",
                       g.RDB$INITIAL_VALUE AS "initial_value"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$GENERATORS g ON g.RDB$GENERATOR_NAME = rf.RDB$GENERATOR_NAME
                WHERE rf.RDB$RELATION_NAME = ? AND rf.RDB$FIELD_NAME = 'id'
            SQL, [$name]);
            $this->assertSame($always ? 0 : 1, $metadata->identity_type);
            $this->assertSame($start, $metadata->initial_value);
            $this->assertSame($start, DB::table($name)->insertGetId([]));
            $exception = null;
            try {
                DB::table($name)->insert(['id' => 99]);
            } catch (QueryException $caught) {
                $exception = $caught;
            }
            if ($always) {
                $this->assertInstanceOf(QueryException::class, $exception);
                $this->assertFalse(DB::table($name)->where('id', 99)->exists());
            } else {
                $this->assertNull($exception);
                $this->assertTrue(DB::table($name)->where('id', 99)->exists());
            }
            $this->assertSame($start + 1, DB::table($name)->insertGetId([]));
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function definitions(): iterable
    {
        yield 'id' => ['id', [], 1, false];
        yield 'increments' => ['increments', [], 1, false];
        yield 'bigIncrements' => ['bigIncrements', [], 1, false];
        yield 'from' => ['id', ['from' => 42], 42, false];
        yield 'startingValue' => ['id', ['startingValue' => 42], 42, false];
        yield 'priority' => ['id', ['from' => 10, 'startingValue' => 42], 42, false];
        yield 'always' => ['id', ['generatedAs' => true, 'always' => true], 1, true];
        yield 'generated default' => ['id', ['generatedAs' => true], 1, false];
        yield 'always false' => ['id', ['generatedAs' => true, 'always' => false], 1, false];
        yield 'always with start' => ['id', ['generatedAs' => true, 'always' => true, 'startingValue' => 42], 42, true];
    }
}
