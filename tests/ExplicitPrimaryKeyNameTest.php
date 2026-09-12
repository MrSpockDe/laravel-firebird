<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ExplicitPrimaryKeyNameTest extends TestCase
{
    public static function names(): iterable
    {
        yield 'short' => ['custom_primary', ['id']];
        yield 'mixed case' => ['Custom_Primary', ['id']];
        yield '63 Unicode codepoints' => [str_repeat('ä', 63), ['id']];
        yield 'composite' => ['composite_primary', ['other', 'id']];
    }

    #[Test]
    #[DataProvider('names')]
    public function it_creates_and_drops_an_explicitly_named_primary_key(string $name, array $columns): void
    {
        $table = 'explicit_primary_test';
        try {
            $migration = fn () => Schema::create($table, function (Blueprint $b) use ($name, $columns) {
                $b->integer('id');
                $b->integer('other');
                $b->primary($columns, $name);
            });
            $sql = array_column(DB::pretend($migration), 'query');
            $migration();
            $this->assertStringContainsString('ADD CONSTRAINT "'.$name.'" PRIMARY KEY', implode("\n", $sql));
            $metadata = $this->primaryMetadata($table);
            $this->assertSame(array_fill(0, count($columns), $name), array_column($metadata, 'name'));
            $this->assertSame($columns, array_column($metadata, 'column'));
            DB::table($table)->insert(['id' => 1, 'other' => 2]);
            Schema::table($table, fn (Blueprint $b) => $b->dropPrimary());
            $this->assertSame([], $this->primaryMetadata($table));
            $this->assertSame([], Schema::getIndexes($table));
            $this->assertSame(1, DB::table($table)->count());
        } finally {
            Schema::dropIfExists($table);
        }
    }

    public static function invalidNames(): iterable
    {
        yield 'overlong' => [str_repeat('ä', 64), '63'];
        yield 'invalid UTF-8' => ["invalid\xC3", 'UTF-8'];
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function it_rejects_invalid_explicit_primary_names(string $name, string $message): void
    {
        $table = 'invalid_primary_test';
        try {
            Schema::create($table, fn (Blueprint $b) => $b->integer('id'));
            DB::table($table)->insert(['id' => 7]);
            try {
                Schema::table($table, fn (Blueprint $b) => $b->primary('id', $name));
                $this->fail('An invalid explicit primary-key name must be rejected.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
            $this->assertSame([], $this->primaryMetadata($table));
            $this->assertSame([7], DB::table($table)->pluck('id')->all());
        } finally {
            Schema::dropIfExists($table);
        }
    }

    #[Test]
    public function it_keeps_firebird_assigned_names_for_automatic_primary_keys(): void
    {
        $table = 'automatic_primary_test';
        try {
            Schema::create($table, fn (Blueprint $b) => $b->integer('id'));
            $migration = fn () => Schema::table($table, fn (Blueprint $b) => $b->primary('id'));
            $this->assertSame('ALTER TABLE "'.$table.'" ADD PRIMARY KEY ("id")', DB::pretend($migration)[0]['query']);
            $migration();
            $metadata = $this->primaryMetadata($table);
            $this->assertCount(1, $metadata);
            $this->assertStringStartsWith('INTEG_', $metadata[0]->name);
            Schema::table($table, fn (Blueprint $b) => $b->dropPrimary());
            $this->assertSame([], $this->primaryMetadata($table));
        } finally {
            Schema::dropIfExists($table);
        }
    }

    private function primaryMetadata(string $table): array
    {
        return DB::select(<<<'SQL'
            SELECT TRIM(rc.RDB$CONSTRAINT_NAME) AS "name",
                   TRIM(s.RDB$FIELD_NAME) AS "column"
            FROM RDB$RELATION_CONSTRAINTS rc
            JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
            WHERE rc.RDB$RELATION_NAME = ? AND rc.RDB$CONSTRAINT_TYPE = 'PRIMARY KEY'
            ORDER BY s.RDB$FIELD_POSITION
        SQL, [$table]);
    }
}
