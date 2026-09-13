<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ForeignKeyNameQuotingTest extends TestCase
{
    private string $parent = 'fk_quoting_parent';
    private string $child = 'fk_quoting_children';

    private function tables(): void
    {
        Schema::create($this->parent, fn (Blueprint $b) => $b->integer('id')->primary());
        Schema::create($this->child, fn (Blueprint $b) => $b->integer('user_id'));
    }

    private function cleanup(): void
    {
        Schema::dropIfExists($this->child);
        Schema::dropIfExists($this->parent);
    }

    private function add(?string $name): void
    {
        Schema::table($this->child, fn (Blueprint $b) => $b->foreign('user_id', $name)->references('id')->on($this->parent));
    }

    private function names(): array
    {
        return array_column(Schema::getForeignKeys($this->child), 'name');
    }

    public static function namesToCreate(): iterable
    {
        yield 'lowercase' => ['users_user_fk'];
        yield 'mixed case' => ['Users_User_FK'];
        yield 'Unicode' => [str_repeat('ä', 63)];
        yield 'automatic' => [null];
    }

    #[Test]
    #[DataProvider('namesToCreate')]
    public function it_preserves_and_drops_quoted_foreign_names(?string $name): void
    {
        try {
            $this->tables();
            $expected = $name ?? $this->child.'_user_id_foreign';
            $sql = DB::pretend(fn () => $this->add($name))[0]['query'];
            $this->add($name);
            $this->assertStringContainsString('ADD CONSTRAINT "'.$expected.'"', $sql);
            $this->assertSame([$expected], $this->names());
            Schema::table($this->child, fn (Blueprint $b) => $b->dropForeign($name ?? ['user_id']));
            $this->assertSame([], $this->names());
        } finally {
            $this->cleanup();
        }
    }

    public static function oldNames(): iterable
    {
        yield 'custom' => ['Users_User_FK', 'Users_User_FK'];
        yield 'automatic' => ['fk_quoting_children_user_id_foreign', ['user_id']];
        yield 'legacy' => [substr('fk_quoting_children_user_id_foreign', 0, 31), ['user_id']];
    }

    #[Test]
    #[DataProvider('oldNames')]
    public function it_drops_existing_unquoted_foreign_names(string $name, $drop): void
    {
        try {
            $this->tables();
            // Reproduce the historical unquoted DDL, independently of the current compiler.
            DB::statement('ALTER TABLE "'.$this->child.'" ADD CONSTRAINT '.$name.' FOREIGN KEY ("user_id") REFERENCES "'.$this->parent.'" ("id")');
            $this->assertSame([strtoupper($name)], $this->names());
            Schema::table($this->child, fn (Blueprint $b) => $b->dropForeign($drop));
            $this->assertSame([], $this->names());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_prefers_the_exact_foreign_name(): void
    {
        try {
            $this->tables();
            foreach (['Users_User_FK', 'USERS_USER_FK'] as $name) {
                DB::statement('ALTER TABLE "'.$this->child.'" ADD CONSTRAINT "'.$name.'" FOREIGN KEY ("user_id") REFERENCES "'.$this->parent.'" ("id")');
            }
            Schema::table($this->child, fn (Blueprint $b) => $b->dropForeign('Users_User_FK'));
            $this->assertSame(['USERS_USER_FK'], $this->names());
            Schema::table($this->child, fn (Blueprint $b) => $b->dropForeign('Users_User_FK'));
            $this->assertSame([], $this->names());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_reports_a_missing_foreign_key_without_executing_a_drop(): void
    {
        try {
            $this->tables();
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                Schema::table($this->child, fn (Blueprint $b) => $b->dropForeign('missing_fk'));
                $this->fail('Missing foreign key accepted.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('missing_fk', $e->getMessage());
                $this->assertStringContainsString('not found', $e->getMessage());
            }
            foreach (DB::getQueryLog() as $query) {
                $this->assertStringNotContainsString('DROP CONSTRAINT', $query['query']);
                $this->assertStringNotContainsString('EXECUTE STATEMENT', $query['query']);
            }
        } finally {
            DB::disableQueryLog();
            $this->cleanup();
        }
    }

    #[Test]
    public function it_rejects_an_overlong_explicit_foreign_name(): void
    {
        try {
            $this->tables();
            try {
                $this->add(str_repeat('ä', 64));
                $this->fail('Overlong foreign name accepted.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('63', $e->getMessage());
            }
            $this->assertSame([], $this->names());
        } finally {
            $this->cleanup();
        }
    }
    #[Test]
    public function it_resolves_a_drop_after_creation_in_the_same_blueprint(): void
    {
        try {
            Schema::create($this->parent, fn (Blueprint $b) => $b->integer('id')->primary());
            $migration = function () {
                Schema::create($this->child, function (Blueprint $b) {
                    $b->integer('user_id');
                    $b->foreign('user_id')->references('id')->on($this->parent);
                    $b->dropForeign(['user_id']);
                });
            };
            $pretend = DB::pretend($migration);
            $this->assertStringContainsString('DROP CONSTRAINT "'.$this->child.'_user_id_foreign"', $pretend[2]['query']);
            $migration();
            $this->assertSame([], $this->names());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_does_not_drop_a_unique_constraint_with_the_requested_name(): void
    {
        try {
            $this->tables();
            Schema::table($this->child, fn (Blueprint $b) => $b->unique('user_id', 'Users_User_FK'));
            try {
                Schema::table($this->child, fn (Blueprint $b) => $b->dropForeign('Users_User_FK'));
                $this->fail('A unique constraint must not be dropped as a foreign key.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('not found', $e->getMessage());
            }
            $this->assertSame('Users_User_FK', Schema::getIndexes($this->child)[0]['name']);
            $this->assertTrue(Schema::getIndexes($this->child)[0]['unique']);
        } finally {
            $this->cleanup();
        }
    }

}
