<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class EnumEscapingTest extends TestCase
{
    #[Test]
    #[DataProvider('enumValues')]
    public function it_escapes_enum_check_literals(array $values, string $literals)
    {
        $name = 'enum_escaping_test';
        try {
            $migration = fn () => Schema::create($name, fn (Blueprint $table) => $table->enum('value', $values));
            $sql = DB::pretend($migration)[0]['query'];
            $migration();
            $this->assertStringContainsString('CHECK ("value" IN ('.$literals.'))', $sql);
            foreach ($values as $value) {
                DB::table($name)->insert(['value' => $value]);
            }
            $this->assertEqualsCanonicalizing($values, DB::table($name)->pluck('value')->all());

            $exception = null;
            try {
                DB::table($name)->insert(['value' => 'invalid_enum_value']);
            } catch (QueryException $caught) {
                $exception = $caught;
            }
            $this->assertInstanceOf(QueryException::class, $exception);
            $this->assertStringContainsString('check constraint', strtolower($exception->getMessage()));
            $this->assertEqualsCanonicalizing($values, DB::table($name)->pluck('value')->all());
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function enumValues(): iterable
    {
        yield 'normal' => [['draft', 'published'], "'draft', 'published'"];
        yield 'apostrophe' => [["O'Reilly", 'Other'], "'O''Reilly', 'Other'"];
        yield 'multiple apostrophes' => [["it's 'quoted'", 'Other'], "'it''s ''quoted''', 'Other'"];
        yield 'empty' => [['', 'Other'], "'', 'Other'"];
        yield 'unicode' => [['Grüße', '日本語'], "'Grüße', '日本語'"];
    }
}
