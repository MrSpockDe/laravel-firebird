<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class WhereLikeTest extends TestCase
{
    #[Test]
    #[DataProvider('columnDefinitions')]
    public function it_filters_like_patterns(string $definition)
    {
        $name = 'where_like_test';
        Schema::dropIfExists($name);

        try {
            Schema::create($name, function (Blueprint $table) use ($definition) {
                $table->integer('id');
                $column = $table->string('value', 40)->nullable();
                if ($definition !== 'default') {
                    $column->charset('UTF8');
                }
                if ($definition === 'ci') {
                    $column->collation('UNICODE_CI');
                }
            });
            foreach (['Foo', 'foo', 'FOO', 'Foobar', 'Bar', null] as $id => $value) {
                DB::table($name)->insert(['id' => $id, 'value' => $value]);
            }

            foreach (['%foo%' => [0, 1, 2, 3], 'foo%' => [0, 1, 2, 3], 'f_o' => [0, 1, 2]] as $pattern => $matching) {
                foreach (['whereLike', 'whereNotLike', 'orWhereLike', 'orWhereNotLike'] as $method) {
                    $negative = str_contains($method, 'Not');
                    $or = str_starts_with($method, 'or');
                    $expected = $negative ? array_values(array_diff([0, 1, 2, 3, 4], $matching)) : $matching;
                    $query = DB::table($name);
                    if ($or) {
                        $query->where('id', 5);
                        $expected[] = 5;
                    }
                    $query->$method('value', $pattern)->orderBy('id');
                    $bindings = $or ? [5, $pattern] : [$pattern];
                    $this->assertSame($bindings, $query->getBindings());
                    $sql = $query->toSql();
                    $this->assertSame($sql, $query->toSql());
                    $this->assertSame($bindings, $query->getBindings());
                    $this->assertSame($expected, $query->pluck('id')->all(), "$definition $method $pattern");
                }
            }

            $ordinary = DB::table($name)->where('value', 'like', '%foo%')->orderBy('id');
            $this->assertStringContainsString('"value" like ?', $ordinary->toSql());
            $this->assertSame($definition === 'ci' ? [0, 1, 2, 3] : [1], $ordinary->pluck('id')->all());

            foreach (['whereLike', 'whereNotLike', 'orWhereLike', 'orWhereNotLike'] as $method) {
                $exception = null;
                try {
                    DB::table($name)->$method('value', '%foo%', caseSensitive: true)->get();
                } catch (\RuntimeException $caught) {
                    $exception = $caught;
                }
                $this->assertSame(\RuntimeException::class, $exception ? get_class($exception) : null);
                $this->assertSame('This database engine does not support case sensitive like operations.', $exception->getMessage());
            }
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function columnDefinitions(): iterable
    {
        yield 'default VARCHAR' => ['default'];
        yield 'UTF8' => ['utf8'];
        yield 'UNICODE_CI' => ['ci'];
    }

    #[Test]
    #[DataProvider('unicodePairs')]
    public function it_filters_unicode_with_firebird_case_mapping(string $first, string $second, bool $equivalent)
    {
        $name = 'where_like_unicode_test';
        Schema::dropIfExists($name);

        try {
            Schema::create($name, function (Blueprint $table) {
                $table->integer('id');
                $table->string('utf', 40)->charset('UTF8');
                $table->string('ci', 40)->charset('UTF8')->collation('UNICODE_CI');
            });
            foreach ([$first, $second] as $id => $value) {
                DB::table($name)->insert(['id' => $id, 'utf' => $value, 'ci' => $value]);
            }
            foreach (['utf', 'ci'] as $column) {
                foreach ([$first, $second] as $id => $value) {
                    foreach (['%'.$value.'%', $value.'%'] as $pattern) {
                        $this->assertSame(
                            $equivalent ? [0, 1] : [$id],
                            DB::table($name)->whereLike($column, $pattern)->orderBy('id')->pluck('id')->all()
                        );
                    }
                }
            }
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function unicodePairs(): iterable
    {
        yield 'a umlaut' => ['ä', 'Ä', true];
        yield 'o umlaut' => ['ö', 'Ö', true];
        yield 'u umlaut' => ['ü', 'Ü', true];
        yield 'accent' => ['é', 'É', true];
        yield 'street' => ['Straße', 'straße', true];
        // Firebird UPPER does not equate sharp S with capital sharp S or SS.
        yield 'sharp S limitation' => ['ß', 'ẞ', false];
        yield 'SS limitation' => ['straße', 'STRASSE', false];
    }
}
