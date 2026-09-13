<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class IdentifierNormalizationTest extends TestCase
{
    public static function kinds(): iterable
    {
        foreach (['index', 'unique', 'foreign'] as $kind) {
            yield $kind => [$kind];
        }
    }

    private function createTables(string $table): void
    {
        Schema::create('identifier_parent', fn (Blueprint $b) => $b->integer('id')->primary());
        Schema::create($table, function (Blueprint $b) {
            $b->integer('first_column_with_long_suffix');
            $b->integer('second_column_with_long_suffix');
        });
    }

    private function add(string $table, string $kind, array $columns, ?string $name = null): void
    {
        Schema::table($table, function (Blueprint $b) use ($kind, $columns, $name) {
            $command = $b->$kind($columns, $name);
            if ($kind === 'foreign') {
                $command->references('id')->on('identifier_parent');
            }
        });
    }

    private function names(string $table, string $kind): array
    {
        return array_column($kind === 'foreign' ? Schema::getForeignKeys($table) : Schema::getIndexes($table), 'name');
    }

    private function drop(string $table, string $kind, $name): void
    {
        $method = 'drop'.ucfirst($kind);
        Schema::table($table, fn (Blueprint $b) => $b->$method($name));
    }

    private function cleanup(string $table): void
    {
        Schema::dropIfExists($table);
        Schema::dropIfExists('identifier_parent');
    }

    #[Test]
    #[DataProvider('kinds')]
    public function it_normalizes_long_generated_identifiers(string $kind): void
    {
        $table = 'identifier_normalization_common_prefix_table';
        try {
            $this->createTables($table);
            $expected = [];
            foreach (['first_column_with_long_suffix', 'second_column_with_long_suffix'] as $column) {
                $original = $table.'_'.$column.'_'.$kind;
                $name = mb_substr($original, 0, 46, 'UTF-8').'_'.substr(hash('sha256', $original), 0, 16);
                $expected[] = $name;
                $this->add($table, $kind, [$column]);
            }
            $this->assertEqualsCanonicalizing($expected, $this->names($table, $kind));
            foreach ($expected as $name) {
                $this->assertSame(63, mb_strlen($name, 'UTF-8'));
            }
            foreach (['first_column_with_long_suffix', 'second_column_with_long_suffix'] as $column) {
                $this->drop($table, $kind, [$column]);
            }
            $this->assertSame([], $this->names($table, $kind));
        } finally {
            $this->cleanup($table);
        }
    }

    #[Test]
    #[DataProvider('kinds')]
    public function it_drops_only_matching_legacy_identifiers(string $kind): void
    {
        $table = 'identifier_legacy_common_prefix_table';
        $column = 'first_column_with_long_suffix';
        // Historical FK identifiers were stored uppercase because CREATE was unquoted.
        $legacy = substr($table.'_'.$column.'_'.$kind, 0, 31);
        if ($kind === 'foreign') {
            $legacy = strtoupper($legacy);
        }
        try {
            $this->createTables($table);
            $this->add($table, $kind, [$column], $legacy);
            try {
                $this->drop($table, $kind, ['second_column_with_long_suffix']);
                $this->fail('A colliding legacy prefix must not delete another column’s object.');
            } catch (\LogicException|QueryException $e) {
                $this->assertInstanceOf($kind === 'foreign' ? \LogicException::class : QueryException::class, $e);
                $this->assertCount(1, $this->names($table, $kind));
            }
            $this->drop($table, $kind, [$column]);
            $this->assertSame([], $this->names($table, $kind));
        } finally {
            $this->cleanup($table);
        }
    }

    #[Test]
    #[DataProvider('kinds')]
    public function it_does_not_guess_legacy_names_for_string_drops(string $kind): void
    {
        $table = 'identifier_string_legacy';
        $original = $table.'_first_column_with_long_suffix_'.$kind;
        $legacy = substr($original, 0, 31);
        try {
            $this->assertGreaterThan(31, strlen($original));
            $this->assertLessThanOrEqual(63, strlen($original));
            $this->createTables($table);
            $this->add($table, $kind, ['first_column_with_long_suffix'], $legacy);
            try {
                $this->drop($table, $kind, $original);
                $this->fail('Explicit names must not silently resolve to legacy prefixes.');
            } catch (\LogicException|QueryException $e) {
                $this->assertCount(1, $this->names($table, $kind));
            }
            $this->drop($table, $kind, $legacy);
            $this->assertSame([], $this->names($table, $kind));
        } finally {
            $this->cleanup($table);
        }
    }

    public static function explicitNames(): iterable
    {
        foreach (['index', 'unique', 'foreign'] as $kind) {
            yield $kind.' ASCII' => [$kind, str_repeat('a', 63)];
        }
        yield 'index Unicode' => ['index', str_repeat('ä', 63)];
        yield 'unique Unicode' => ['unique', str_repeat('ä', 63)];
    }

    #[Test]
    #[DataProvider('explicitNames')]
    public function it_preserves_explicit_identifiers(string $kind, string $name): void
    {
        $table = 'identifier_explicit_test';
        try {
            $this->createTables($table);
            $this->add($table, $kind, ['first_column_with_long_suffix'], $name);
            $this->assertSame([$name], $this->names($table, $kind));
            $this->drop($table, $kind, $name);
            $this->assertSame([], $this->names($table, $kind));
        } finally {
            $this->cleanup($table);
        }
    }

    #[Test]
    #[DataProvider('kinds')]
    public function it_rejects_overlong_explicit_identifiers(string $kind): void
    {
        $table = 'identifier_explicit_test';
        try {
            $this->createTables($table);
            try {
                $this->add($table, $kind, ['first_column_with_long_suffix'], str_repeat('a', 64));
                $this->fail('Overlong explicit identifier accepted.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('63', $e->getMessage());
                $this->assertSame([], $this->names($table, $kind));
            }
        } finally {
            $this->cleanup($table);
        }
    }
    #[Test]
    #[DataProvider('kinds')]
    public function it_preserves_short_generated_names(string $kind): void
    {
        $table = 'identifier_short';
        try {
            Schema::create('identifier_parent', fn (Blueprint $b) => $b->integer('id')->primary());
            Schema::create($table, fn (Blueprint $b) => $b->integer('id'));
            $this->add($table, $kind, ['id']);
            $name = $table.'_id_'.$kind;
            $this->assertSame([$name], $this->names($table, $kind));
            $this->drop($table, $kind, ['id']);
            $this->assertSame([], $this->names($table, $kind));
        } finally {
            $this->cleanup($table);
        }
    }

    #[Test]
    public function it_preserves_utf8_and_discards_invalid_legacy_candidates(): void
    {
        $table = str_repeat('ä', 16).'_identifier_long_unicode_table';
        $column = 'first_column_with_long_suffix';
        try {
            $this->createTables($table);
            $original = $table.'_'.$column.'_index';
            $this->assertFalse(mb_check_encoding(substr($original, 0, 31), 'UTF-8'));
            $this->add($table, 'index', [$column]);
            $name = $this->names($table, 'index')[0];
            $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
            $this->assertSame(63, mb_strlen($name, 'UTF-8'));
            $this->assertSame(mb_substr($original, 0, 46, 'UTF-8').'_'.substr(hash('sha256', $original), 0, 16), $name);
            $sql = DB::pretend(fn () => $this->drop($table, 'index', [$column]))[0]['query'];
            $this->assertTrue(mb_check_encoding($sql, 'UTF-8'));
            $this->drop($table, 'index', [$column]);
            try {
                $this->drop($table, 'index', [$column]);
                $this->fail('Missing objects must not become a silent no-op.');
            } catch (QueryException $e) {
                $this->assertNotSame(-802, $e->errorInfo[1]);
                $this->assertTrue(mb_check_encoding($e->getSql(), 'UTF-8'));
            }
            $this->assertSame([], $this->names($table, 'index'));
        } finally {
            $this->cleanup($table);
        }
    }

    #[Test]
    #[DataProvider('kinds')]
    public function it_rejects_invalid_utf8_names(string $kind): void
    {
        $table = 'identifier_invalid_utf8';
        try {
            $this->createTables($table);
            try {
                $this->add($table, $kind, ['first_column_with_long_suffix'], "broken\xC3");
                $this->fail('Invalid UTF-8 accepted.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('UTF-8', $e->getMessage());
                $this->assertSame([], $this->names($table, $kind));
            }
        } finally {
            $this->cleanup($table);
        }
    }

    #[Test]
    public function it_checks_legacy_column_order_and_prefers_the_modern_name(): void
    {
        $table = 'identifier_legacy_common_prefix_table';
        $columns = ['first_column_with_long_suffix', 'second_column_with_long_suffix'];
        $legacy = substr($table.'_'.implode('_', $columns).'_index', 0, 31);
        try {
            $this->createTables($table);
            $this->add($table, 'index', array_reverse($columns), $legacy);
            try {
                $this->drop($table, 'index', $columns);
                $this->fail('Reversed columns must not match a legacy object.');
            } catch (QueryException $e) {
                $this->assertSame([$legacy], $this->names($table, 'index'));
            }
            $this->drop($table, 'index', $legacy);
            $this->add($table, 'index', $columns, $legacy);
            $this->add($table, 'index', $columns);
            $this->drop($table, 'index', $columns);
            $this->assertSame([$legacy], $this->names($table, 'index'));
            $this->drop($table, 'index', $columns);
            $this->assertSame([], $this->names($table, 'index'));
        } finally {
            $this->cleanup($table);
        }
    }

    #[Test]
    public function it_does_not_drop_constraint_indexes_as_regular_indexes(): void
    {
        $table = 'identifier_legacy_common_prefix_table';
        $column = 'first_column_with_long_suffix';
        $legacy = substr($table.'_'.$column.'_index', 0, 31);
        try {
            $this->createTables($table);
            $this->add($table, 'unique', [$column], $legacy);
            foreach ([[$column], $legacy] as $argument) {
                try {
                    $this->drop($table, 'index', $argument);
                    $this->fail('Constraint index must not be selected.');
                } catch (QueryException $e) {
                    $this->assertSame([$legacy], $this->names($table, 'unique'));
                }
            }
            $this->drop($table, 'unique', [$column]);
            $this->assertSame([], $this->names($table, 'unique'));
        } finally {
            $this->cleanup($table);
        }
    }

    #[Test]
    public function it_resolves_drops_after_create_in_the_same_blueprint(): void
    {
        $table = 'identifier_in_one_blueprint';
        try {
            Schema::create($table, function (Blueprint $b) {
                $b->integer('value');
                $b->index('value');
                $b->dropIndex(['value']);
            });
            $this->assertSame([], Schema::getIndexes($table));
        } finally {
            Schema::dropIfExists($table);
        }
    }

    #[Test]
    #[DataProvider('kinds')]
    public function it_records_name_origin_without_changing_the_original(string $kind): void
    {
        $connection = DB::connection();
        $connection->getSchemaBuilder();
        $blueprint = new \HarryGulliford\Firebird\Schema\Blueprint($connection, 'origin');
        $automatic = $blueprint->$kind('id');
        $explicit = $blueprint->$kind('id', 'origin_id_'.$kind);
        $this->assertTrue($automatic->firebirdGeneratedName);
        $this->assertFalse($explicit->firebirdGeneratedName);
        $this->assertSame('origin_id_'.$kind, $automatic->firebirdOriginalName);
        $this->assertSame($automatic->firebirdOriginalName, $explicit->firebirdOriginalName);
        $method = 'drop'.ucfirst($kind);
        $this->assertTrue($blueprint->$method(['id'])->firebirdGeneratedName);
        $this->assertFalse($blueprint->$method('origin_id_'.$kind)->firebirdGeneratedName);
    }

    #[Test]
    public function it_keeps_legacy_objects_on_other_tables(): void
    {
        $table = 'identifier_legacy_common_prefix_table';
        $other = 'identifier_other_table';
        $column = 'first_column_with_long_suffix';
        $legacy = substr($table.'_'.$column.'_index', 0, 31);
        try {
            $this->createTables($table);
            Schema::create($other, fn (Blueprint $b) => $b->integer($column));
            $this->add($other, 'index', [$column], $legacy);
            try {
                $this->drop($table, 'index', [$column]);
                $this->fail('An index on another table must not match.');
            } catch (QueryException $e) {
                $this->assertSame([$legacy], $this->names($other, 'index'));
            }
        } finally {
            Schema::dropIfExists($other);
            $this->cleanup($table);
        }
    }

    #[Test]
    public function it_does_not_confuse_legacy_constraint_types(): void
    {
        $table = 'identifier_legacy_common_prefix_table';
        $column = 'first_column_with_long_suffix';
        // Uppercase also matches the historical unquoted FK name.
        $legacy = strtoupper(substr($table.'_'.$column.'_foreign', 0, 31));
        try {
            $this->createTables($table);
            $this->add($table, 'unique', [$column], $legacy);
            try {
                $this->drop($table, 'foreign', [$column]);
                $this->fail('A UNIQUE constraint must not be treated as a foreign key.');
            } catch (\LogicException $e) {
                $this->assertSame([$legacy], $this->names($table, 'unique'));
            }
        } finally {
            $this->cleanup($table);
        }
    }

}
