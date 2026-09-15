<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class UnsupportedSchemaModifierTest extends TestCase
{
    #[Test]
    #[DataProvider('modifiers')]
    public function it_rejects_unsupported_modifiers_without_changing_schema(string $operation, string $modifier, string $message): void
    {
        $name = 'unsupported_modifier_test';
        try {
            if ($operation !== 'create') {
                Schema::create($name, function (Blueprint $table) use ($modifier) {
                    if ($modifier === 'useCurrentOnUpdate') {
                        $table->timestamp('value')->nullable();
                    } else {
                        $table->integer('value');
                    }
                });
                $rows = $operation === 'add' && str_starts_with($modifier, 'generated') ? [] : [
                    $modifier === 'useCurrentOnUpdate' ? '2000-01-01 00:00:00' : 7,
                ];
                foreach ($rows as $value) {
                    DB::table($name)->insert(['value' => $value]);
                }
                $rows = DB::table($name)->pluck('value')->all();
                $before = Schema::getColumns($name);
            }
            $definition = function (Blueprint $table) use ($operation, $modifier) {
                if ($operation === 'create') {
                    $table->integer('base')->nullable();
                }
                $columnName = $operation === 'add' ? 'added' : 'value';
                $column = $modifier === 'useCurrentOnUpdate'
                    ? $table->timestamp($columnName)->nullable()
                    : $table->bigInteger($columnName);
                if ($modifier === 'persisted') {
                    $column->virtualAs('1 + 1')->persisted();
                } elseif ($modifier === 'generatedAs' || $modifier === 'generatedExpression') {
                    if ($operation !== 'change') {
                        $column->autoIncrement();
                    }
                    $column->generatedAs($modifier === 'generatedAs' ? 'START WITH 42' : new Expression('START WITH 42'));
                } elseif ($modifier === 'storedAs') {
                    $column->nullable()->storedAs('1 + 1');
                } else {
                    $column->useCurrentOnUpdate();
                }
                if ($operation === 'change') {
                    $column->change();
                }
            };
            $exception = null;
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                if ($operation === 'create') {
                    Schema::create($name, $definition);
                } else {
                    Schema::table($name, $definition);
                }
            } catch (LogicException $caught) {
                $exception = $caught;
            }
            $this->assertInstanceOf(LogicException::class, $exception);
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame([], DB::getQueryLog());
            if ($operation === 'create') {
                $this->assertFalse(Schema::hasTable($name));
            } else {
                $this->assertSame($before, Schema::getColumns($name));
                $this->assertSame($rows, DB::table($name)->pluck('value')->all());
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
            Schema::dropIfExists($name);
        }
    }

    public static function modifiers(): iterable
    {
        foreach (['create', 'add', 'change'] as $operation) {
            foreach ([
                'storedAs' => 'Firebird does not support the storedAs() modifier.',
                'persisted' => 'Firebird does not support persisted() on virtualAs() columns.',
                'generatedAs' => 'Firebird does not support custom generatedAs() options or expressions.',
                'generatedExpression' => 'Firebird does not support custom generatedAs() options or expressions.',
                'useCurrentOnUpdate' => 'Firebird does not support the useCurrentOnUpdate() modifier.',
            ] as $modifier => $message) {
                yield "$operation $modifier" => [$operation, $modifier, $message];
            }
        }
    }
}
