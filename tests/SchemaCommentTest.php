<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class SchemaCommentTest extends TestCase
{
    #[Test]
    #[DataProvider('columnOperations')]
    public function it_supports_column_comment_lifecycle(string $operation)
    {
        $name = 'schema_comment_test';
        try {
            if ($operation !== 'create') {
                Schema::create($name, function (Blueprint $table) use ($operation) {
                    $table->id();
                    if ($operation === 'change') {
                        $table->string('value', 40)->nullable();
                    }
                });
            }
            $migration = function () use ($name, $operation) {
                $definition = function (Blueprint $table) use ($operation) {
                    if ($operation === 'create') {
                        $table->id();
                    }
                    $column = $table->string('value', 50)->nullable()->comment("Owner's value");
                    if ($operation === 'change') {
                        $column->change();
                    }
                };
                if ($operation === 'create') {
                    Schema::create($name, $definition);
                } else {
                    Schema::table($name, $definition);
                }
            };
            $sql = array_column(DB::pretend($migration), 'query');
            $migration();
            $this->assertSame("Owner's value", array_column(Schema::getColumns($name), null, 'name')['value']['comment']);
            $this->assertSame(
                'comment on column "schema_comment_test"."value" is \'Owner\'\'s value\'',
                end($sql)
            );
            $this->assertGreaterThanOrEqual(2, count($sql));

            Schema::table($name, fn (Blueprint $table) => $table->string('value', 60)->change());
            $this->assertSame("Owner's value", array_column(Schema::getColumns($name), null, 'name')['value']['comment']);
            Schema::table($name, fn (Blueprint $table) => $table->string('value', 60)->comment('Changed')->change());
            $this->assertSame('Changed', array_column(Schema::getColumns($name), null, 'name')['value']['comment']);
            Schema::table($name, fn (Blueprint $table) => $table->string('value', 60)->comment(null)->change());
            $this->assertNull(array_column(Schema::getColumns($name), null, 'name')['value']['comment']);
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function columnOperations(): iterable
    {
        yield 'create' => ['create'];
        yield 'add' => ['add'];
        yield 'change' => ['change'];
    }

    #[Test]
    public function it_supports_table_comment_lifecycle()
    {
        $name = 'schema_comment_test';
        try {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->comment("Owner's table");
            });
            $this->assertSame("Owner's table", array_column(Schema::getTables(), null, 'name')[$name]['comment']);
            Schema::table($name, fn (Blueprint $table) => $table->string('value')->nullable());
            $this->assertSame("Owner's table", array_column(Schema::getTables(), null, 'name')[$name]['comment']);
            Schema::table($name, fn (Blueprint $table) => $table->comment('Changed'));
            $this->assertSame('Changed', array_column(Schema::getTables(), null, 'name')[$name]['comment']);
            Schema::table($name, fn (Blueprint $table) => $table->comment(null));
            $this->assertNull(array_column(Schema::getTables(), null, 'name')[$name]['comment']);
        } finally {
            Schema::dropIfExists($name);
        }
    }
}
