<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class EnumDefaultTest extends TestCase
{
    #[Test]
    #[DataProvider('definitions')]
    public function it_places_defaults_before_enum_constraints(string $operation, string $nullable, bool $quoted): void
    {
        $name = 'enum_default_test';
        $default = $quoted ? "O'Reilly" : 'draft';
        $allowed = $quoted ? ["O'Reilly", 'Other'] : ['draft', 'published'];
        $literal = $quoted ? "'O''Reilly'" : "'draft'";
        $literals = $quoted ? "'O''Reilly', 'Other'" : "'draft', 'published'";
        try {
            if ($operation === 'add') {
                Schema::create($name, fn (Blueprint $table) => $table->integer('id'));
                DB::table($name)->insert(['id' => 1]);
            }
            $definition = function (Blueprint $table) use ($operation, $nullable, $allowed, $default) {
                if ($operation === 'create') {
                    $table->integer('id');
                }
                $column = $table->enum('status', $allowed);
                if ($nullable === 'before') {
                    $column->nullable();
                }
                $column->default($default);
                if ($nullable === 'after') {
                    $column->nullable();
                }
            };
            $migration = fn () => $operation === 'create' ? Schema::create($name, $definition) : Schema::table($name, $definition);
            $sql = DB::pretend($migration)[0]['query'];
            $migration();
            $this->assertStringContainsString('"status" VARCHAR(255) DEFAULT '.$literal.($nullable === 'no' ? ' NOT NULL' : '').' CHECK ("status" IN ('.$literals.'))', $sql);
            $column = collect(Schema::getColumns($name))->firstWhere('name', 'status');
            $this->assertSame('varchar(255)', $column['type']);
            $this->assertSame($literal, $column['default']);
            $this->assertSame($nullable !== 'no', $column['nullable']);
            if ($operation === 'add') {
                $this->assertSame($nullable === 'no' ? $default : null, DB::table($name)->where('id', 1)->value('status'));
            }
            DB::table($name)->insert(['id' => 2]);
            $this->assertSame($default, DB::table($name)->where('id', 2)->value('status'));
            if ($nullable !== 'no') {
                DB::table($name)->insert(['id' => 3, 'status' => null]);
                $this->assertNull(DB::table($name)->where('id', 3)->first()->status);
            }
            $before = DB::table($name)->orderBy('id')->get()->all();
            try {
                DB::table($name)->insert(['id' => 4, 'status' => 'invalid']);
                $this->fail('The enum CHECK must still reject invalid values.');
            } catch (QueryException $exception) {
                $this->assertSame(-297, (int) $exception->getPrevious()->errorInfo[1]);
            }
            $this->assertEquals($before, DB::table($name)->orderBy('id')->get()->all());
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function definitions(): iterable
    {
        yield 'create not null' => ['create', 'no', false];
        yield 'create nullable first' => ['create', 'before', false];
        yield 'create nullable last' => ['create', 'after', false];
        yield 'add not null' => ['add', 'no', false];
        yield 'add nullable' => ['add', 'before', false];
        yield 'create apostrophe' => ['create', 'no', true];
        yield 'add apostrophe' => ['add', 'before', true];
    }

    #[Test]
    public function it_preserves_plain_enum_and_string_defaults(): void
    {
        $name = 'enum_default_test';
        try {
            $migration = fn () => Schema::create($name, function (Blueprint $table) {
                $table->enum('status', ['draft', 'published']);
                $table->string('label')->default("O'Reilly");
            });
            $sql = DB::pretend($migration)[0]['query'];
            $migration();
            $this->assertStringContainsString('"label" VARCHAR(255) DEFAULT \'O\'\'Reilly\' NOT NULL', $sql);
            DB::table($name)->insert(['status' => 'draft']);
            $this->assertSame("O'Reilly", DB::table($name)->value('label'));
            $this->assertNull(collect(Schema::getColumns($name))->firstWhere('name', 'status')['default']);
        } finally {
            Schema::dropIfExists($name);
        }
    }

    #[Test]
    public function it_rejects_enum_change_before_any_schema_or_default_changes(): void
    {
        $name = 'enum_default_test';
        try {
            Schema::create($name, fn (Blueprint $table) => $table->enum('status', ['draft', 'published'])->nullable());
            // Establish a default independently so RED reaches the CHANGE guard.
            Schema::table($name, fn (Blueprint $table) => $table->string('status', 255)->default('draft')->change());
            DB::table($name)->insert(['status' => 'published']);
            $before = Schema::getColumns($name);
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                Schema::table($name, fn (Blueprint $table) => $table->enum('status', ['new'])->nullable(false)->default('new')->change());
                $this->fail('Changing enums must remain unsupported.');
            } catch (\LogicException $exception) {
                $this->assertSame('Firebird does not support changing enum columns.', $exception->getMessage());
            }
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame($before, Schema::getColumns($name));
            $this->assertSame(['published'], DB::table($name)->pluck('status')->all());
            DB::statement('INSERT INTO "enum_default_test" DEFAULT VALUES');
            $this->assertEqualsCanonicalizing(['published', 'draft'], DB::table($name)->pluck('status')->all());
            try {
                DB::table($name)->insert(['status' => 'new']);
                $this->fail('The original CHECK must be preserved.');
            } catch (QueryException $exception) {
                $this->assertSame(-297, (int) $exception->getPrevious()->errorInfo[1]);
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
            Schema::dropIfExists($name);
        }
    }
}
