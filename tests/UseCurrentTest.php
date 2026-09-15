<?php

namespace HarryGulliford\Firebird\Tests;

use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class UseCurrentTest extends TestCase
{
    #[Test]
    #[DataProvider('columnLifecycles')]
    public function it_applies_use_current_on_create_add_and_change(string $type, string $operation): void
    {
        $table = 'use_current_test';
        try {
            if ($operation !== 'create') {
                Schema::create($table, function (Blueprint $t) use ($type, $operation) {
                    $t->id();
                    if ($operation === 'change') {
                        $t->$type('value')->default('2000-01-01 00:00:00');
                    }
                });
            }
            $definition = function (Blueprint $t) use ($type, $operation) {
                if ($operation === 'create') {
                    $t->id();
                }
                $column = $t->$type('value')->useCurrent();
                if ($operation === 'change') {
                    $column->change();
                }
            };
            DB::enableQueryLog();
            DB::flushQueryLog();
            if ($operation === 'create') {
                Schema::create($table, $definition);
            } else {
                Schema::table($table, $definition);
            }
            $sql = array_column(DB::getQueryLog(), 'query');
            $column = $this->column($table);
            $this->assertSame('CURRENT_TIMESTAMP', $column['default']);
            $this->assertSame(str_ends_with($type, 'Tz') ? 'timestamp with time zone' : 'timestamp', $column['type_name']);
            $this->assertFalse($column['nullable']);
            if ($operation === 'change') {
                $nativeType = str_ends_with($type, 'Tz') ? 'TIMESTAMP WITH TIME ZONE' : 'TIMESTAMP';
                $this->assertSame([
                    'ALTER TABLE "'.$table.'" ALTER COLUMN "value" TYPE '.$nativeType,
                    'ALTER TABLE "'.$table.'" ALTER COLUMN "value" SET DEFAULT CURRENT_TIMESTAMP',
                ], $sql);
            } else {
                $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP NOT NULL', implode(' ', $sql));
            }
            $this->assertCurrentDefaultIsUsed($table);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
            Schema::dropIfExists($table);
        }
    }

    public static function columnLifecycles(): iterable
    {
        foreach (['timestamp', 'dateTime', 'timestampTz', 'dateTimeTz'] as $type) {
            foreach (['create', 'add', 'change'] as $operation) {
                yield "$type $operation" => [$type, $operation];
            }
        }
    }

    #[Test]
    #[DataProvider('timestampTypes')]
    public function it_preserves_replaces_and_drops_defaults_when_changing(string $type): void
    {
        $table = 'current_preserve_test';
        try {
            Schema::create($table, function (Blueprint $t) use ($type) {
                $t->id();
                $t->$type('value')->nullable()->default('2000-01-01 00:00:00');
            });
            $original = $this->column($table);
            $id = DB::table($table)->insertGetId([]);
            $stored = DB::table($table)->where('id', $id)->value('value');

            Schema::table($table, fn (Blueprint $t) => $t->$type('value')->change());
            $this->assertSame($original, $this->column($table));
            $preservedId = DB::table($table)->insertGetId([]);
            $this->assertSame($stored, DB::table($table)->where('id', $preservedId)->value('value'));

            Schema::table($table, fn (Blueprint $t) => $t->$type('value')->useCurrent()->change());
            $this->assertSame('CURRENT_TIMESTAMP', $this->column($table)['default']);
            $this->assertCurrentDefaultIsUsed($table);

            Schema::table($table, fn (Blueprint $t) => $t->$type('value')->change());
            $this->assertSame('CURRENT_TIMESTAMP', $this->column($table)['default']);
            $this->assertCurrentDefaultIsUsed($table);

            Schema::table($table, fn (Blueprint $t) => $t->$type('value')->default(null)->change());
            $column = $this->column($table);
            $this->assertNull($column['default']);
            $this->assertTrue($column['nullable']);
            $nullId = DB::table($table)->insertGetId([]);
            $this->assertNull(DB::table($table)->where('id', $nullId)->value('value'));
            $this->assertSame($stored, DB::table($table)->where('id', $id)->value('value'));
        } finally {
            Schema::dropIfExists($table);
        }
    }

    public static function timestampTypes(): iterable
    {
        foreach (['timestamp', 'dateTime', 'timestampTz', 'dateTimeTz'] as $type) {
            yield $type => [$type];
        }
    }

    private function column(string $table): array
    {
        return array_column(Schema::getColumns($table), null, 'name')['value'];
    }

    private function assertCurrentDefaultIsUsed(string $table): void
    {
        // Use database time rather than assuming the PHP and Firebird clocks agree.
        $before = $this->databaseTime();
        $id = DB::table($table)->insertGetId([]);
        $after = $this->databaseTime();
        $value = DB::table($table)->where('id', $id)->value('value');
        $this->assertIsString($value);
        // A timezone-free TIMESTAMP is expressed in the Firebird session timezone.
        $timestamp = (new DateTimeImmutable($value, $before->getTimezone()))->getTimestamp();
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $timestamp);
        $this->assertLessThanOrEqual($after->getTimestamp(), $timestamp);
    }

    private function databaseTime(): DateTimeImmutable
    {
        return new DateTimeImmutable(DB::selectOne('SELECT CURRENT_TIMESTAMP AS "value" FROM RDB$DATABASE')->value);
    }
}
