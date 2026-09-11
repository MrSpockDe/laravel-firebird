<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class VirtualColumnTest extends TestCase
{
    #[Test]
    #[DataProvider('operations')]
    public function it_supports_virtual_column_lifecycle(string $operation)
    {
        $name = 'virtual_column_test';
        try {
            Schema::create($name, function (Blueprint $table) use ($operation) {
                $table->integer('quantity');
                $table->integer('price');
                if ($operation === 'create') {
                    $table->integer('total')->virtualAs('"quantity" * "price"');
                }
            });
            if ($operation === 'add') {
                Schema::table($name, fn (Blueprint $table) => $table->integer('total')->virtualAs('"quantity" * "price"'));
            }
            DB::table($name)->insert(['quantity' => 2, 'price' => 3]);
            $this->assertSame(6, DB::table($name)->value('total'));
            DB::table($name)->update(['price' => 4]);
            $this->assertSame(8, DB::table($name)->value('total'));
            $column = array_column(Schema::getColumns($name), null, 'name')['total'];
            $this->assertSame('virtual', $column['generation']['type']);
            $this->assertStringContainsString('"quantity" * "price"', $column['generation']['expression']);
            $this->assertSame('integer', Schema::getColumnType($name, 'total'));
            $this->assertTrue($column['nullable']);
            $this->assertFalse($column['auto_increment']);
            $exception = null;
            try {
                DB::table($name)->update(['total' => 99]);
            } catch (QueryException $caught) {
                $exception = $caught;
            }
            $this->assertInstanceOf(QueryException::class, $exception);
            $this->assertSame(8, DB::table($name)->value('total'));
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function operations(): iterable
    {
        yield ['create'];
        yield ['add'];
    }

    #[Test]
    #[DataProvider('invalidModifiers')]
    public function it_rejects_invalid_virtual_modifiers(string $modifier, mixed $value)
    {
        $name = 'virtual_column_test';
        try {
            $exception = null;
            try {
                Schema::create($name, function (Blueprint $table) use ($modifier, $value) {
                    $table->integer('quantity');
                    $table->integer('total')->virtualAs('"quantity" * 2')->$modifier($value);
                });
            } catch (\LogicException $caught) {
                $exception = $caught;
            }
            $this->assertInstanceOf(\LogicException::class, $exception);
            $this->assertFalse(Schema::hasTable($name));
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function invalidModifiers(): iterable
    {
        yield ['default', 1];
        yield ['default', null];
        yield ['autoIncrement', true];
        yield ['nullable', false];
        yield ['collation', 'UNICODE'];
        yield ['storedAs', '"quantity"'];
    }

    #[Test]
    public function it_rejects_virtual_change_without_altering_data()
    {
        $name = 'virtual_column_test';
        try {
            Schema::create($name, fn (Blueprint $table) => $table->integer('value'));
            DB::table($name)->insert(['value' => 7]);
            $before = Schema::getColumns($name);
            $exception = null;
            try {
                Schema::table($name, fn (Blueprint $table) => $table->bigInteger('value')->virtualAs('1 + 1')->change());
            } catch (\LogicException $caught) {
                $exception = $caught;
            }
            $this->assertInstanceOf(\LogicException::class, $exception);
            $this->assertSame($before, Schema::getColumns($name));
            $this->assertSame(7, DB::table($name)->value('value'));
        } finally {
            Schema::dropIfExists($name);
        }
    }
}
