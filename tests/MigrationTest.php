<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;

class MigrationTest extends TestCase
{
    #[Test]
    #[DataProvider('dropIfExistsPrefixCases')]
    public function it_drops_tables_with_consistent_prefix_names(string $prefix, string $name, bool $exists, bool $unprefixed, bool $conditional)
    {
        $connection = DB::connection();
        $originalPrefix = $connection->getTablePrefix();
        $physical = $prefix.$name;
        $physicalExists = fn (string $table) => (bool) $connection->selectOne(
            'SELECT 1 AS "present" FROM RDB$RELATIONS WHERE RDB$RELATION_NAME = ?', [$table]
        );

        try {
            $connection->setTablePrefix('');
            Schema::dropIfExists($physical);
            if ($unprefixed) {
                Schema::dropIfExists($name);
                Schema::create($name, fn (Blueprint $table) => $table->integer('id'));
                DB::table($name)->insert(['id' => 73]);
            }

            $connection->setTablePrefix($prefix);
            if ($exists) {
                Schema::create($name, fn (Blueprint $table) => $table->integer('id'));
                $this->assertTrue($physicalExists($physical));
            }

            $drop = fn () => $conditional ? Schema::dropIfExists($name) : Schema::drop($name);
            $sql = $connection->pretend($drop)[0]['query'];
            $drop();

            $this->assertFalse($physicalExists($physical));
            $identifier = '"'.str_replace('"', '""', $physical).'"';
            $this->assertStringContainsString('drop table '.$identifier, $sql);
            if ($conditional) {
                $this->assertStringContainsString("rdb\$relation_name = '".$physical."'", $sql);
            }
            if ($prefix !== '') {
                $this->assertStringNotContainsString($prefix.$prefix.$name, $sql);
            }

            if ($unprefixed) {
                $this->assertTrue($physicalExists($name));
                $connection->setTablePrefix('');
                $this->assertSame(73, DB::table($name)->value('id'));
            }
        } finally {
            $connection->setTablePrefix('');
            try {
                if ($physicalExists($physical)) {
                    Schema::drop($physical);
                }
                if ($unprefixed && $physicalExists($name)) {
                    Schema::drop($name);
                }
            } finally {
                $connection->setTablePrefix($originalPrefix);
            }
        }
    }

    public static function dropIfExistsPrefixCases(): iterable
    {
        yield 'existing prefixed' => ['fb_', 'prefix_drop_test', true, false, true];
        yield 'missing prefixed' => ['fb_', 'prefix_drop_test', false, false, true];
        yield 'both tables exist' => ['fb_', 'prefix_drop_test', true, true, true];
        yield 'only unprefixed exists' => ['fb_', 'prefix_drop_test', false, true, true];
        yield 'mixed case' => ['fb_', 'PrefixDropTest', true, false, true];
        yield 'embedded quote' => ['fb_', 'prefix"drop_test', true, false, true];
        yield 'without prefix' => ['', 'prefix_drop_test', true, false, true];
        yield 'unconditional drop' => ['fb_', 'prefix_drop_test', true, false, false];
    }

    #[Test]
    #[DataProvider('unsupportedChangeCharsets')]
    public function it_rejects_explicit_change_charset(string $initial, ?string $target, int $length, bool $nullable, string $value)
    {
        $name = 'change_charset_test';
        Schema::dropIfExists($name);

        try {
            Schema::create($name, function (Blueprint $table) use ($initial, $nullable) {
                $table->id();
                $table->string('value', 40)->charset($initial)
                    ->collation($initial === 'UTF8' ? 'UNICODE' : 'ASCII')
                    ->nullable($nullable)->default('Hello');
            });
            $id = DB::table($name)->insertGetId(['value' => $value]);
            $before = Schema::getColumns($name);
            $this->assertSame($initial, $this->readChangeCollationCharset($name));
            $exception = null;

            try {
                Schema::table($name, fn (Blueprint $table) => $table->string('value', $length)->charset($target)->change());
            } catch (\LogicException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(\LogicException::class, $exception);
            $this->assertSame('Firebird does not support changing column character sets.', $exception->getMessage());
            $this->assertSame($before, Schema::getColumns($name));
            $this->assertSame($initial, $this->readChangeCollationCharset($name));
            $this->assertSame($value, DB::table($name)->where('id', $id)->value('value'));
            $defaultId = DB::table($name)->insertGetId([]);
            $this->assertSame('Hello', DB::table($name)->where('id', $defaultId)->value('value'));
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function unsupportedChangeCharsets(): iterable
    {
        yield 'different charset' => ['ASCII', 'UTF8', 40, true, 'Alpha'];
        yield 'different charset and length' => ['ASCII', 'UTF8', 100, true, 'Alpha'];
        yield 'same charset' => ['UTF8', 'UTF8', 40, true, 'Grüße'];
        yield 'explicit null' => ['UTF8', null, 100, true, 'Grüße'];
        yield 'nullable default' => ['UTF8', 'ASCII', 100, true, 'Alpha'];
        yield 'not null default' => ['UTF8', 'ASCII', 100, false, 'Alpha'];
        yield 'unicode data' => ['UTF8', 'ASCII', 100, true, 'Grüße 😀'];
    }

    #[Test]
    #[DataProvider('unsupportedChangeCollations')]
    public function it_rejects_explicit_change_collation(?string $collation, bool $charset, int $length, bool $nullable)
    {
        $name = 'change_collation_test';
        Schema::dropIfExists($name);

        try {
            Schema::create($name, function (Blueprint $table) use ($nullable) {
                $table->id();
                $table->string('value', 40)->charset('UTF8')->collation('UNICODE')
                    ->nullable($nullable)->default('Hello');
            });
            $id = DB::table($name)->insertGetId(['value' => 'Alpha']);
            $before = Schema::getColumns($name);
            $beforeCharset = $this->readChangeCollationCharset($name);
            $exception = null;

            try {
                Schema::table($name, function (Blueprint $table) use ($collation, $charset, $length) {
                    $column = $table->string('value', $length)->collation($collation)->change();
                    if ($charset) {
                        $column->charset('UTF8');
                    }
                });
            } catch (\LogicException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(\LogicException::class, $exception);
            $this->assertSame('Firebird does not support changing column collations.', $exception->getMessage());
            $this->assertSame($before, Schema::getColumns($name));
            $this->assertSame($beforeCharset, $this->readChangeCollationCharset($name));
            $this->assertSame('Alpha', DB::table($name)->where('id', $id)->value('value'));
            $defaultId = DB::table($name)->insertGetId([]);
            $this->assertSame('Hello', DB::table($name)->where('id', $defaultId)->value('value'));
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function unsupportedChangeCollations(): iterable
    {
        yield 'without charset' => ['UNICODE_CI', false, 40, true];
        yield 'with charset' => ['UNICODE_CI', true, 40, true];
        yield 'length change nullable default' => ['UNICODE_CI', false, 100, true];
        yield 'length change not null default' => ['UNICODE_CI', false, 100, false];
        yield 'incompatible collation' => ['ASCII', true, 100, true];
        yield 'explicit null' => [null, false, 100, true];
        yield 'same collation' => ['UNICODE', false, 100, true];
    }

    #[Test]
    public function it_changes_length_preserving_collation_attributes()
    {
        $name = 'change_collation_test';
        Schema::dropIfExists($name);

        try {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('value', 40)->charset('UTF8')->collation('UNICODE')
                    ->nullable()->default('Hello');
            });
            $id = DB::table($name)->insertGetId(['value' => 'Alpha']);
            $expected = array_column(Schema::getColumns($name), null, 'name')['value'];
            $expected['type'] = 'varchar(100)';

            Schema::table($name, fn (Blueprint $table) => $table->string('value', 100)->change());

            $this->assertSame($expected, array_column(Schema::getColumns($name), null, 'name')['value']);
            $this->assertSame('UTF8', $this->readChangeCollationCharset($name));
            $this->assertSame('Alpha', DB::table($name)->where('id', $id)->value('value'));
            $defaultId = DB::table($name)->insertGetId([]);
            $this->assertSame('Hello', DB::table($name)->where('id', $defaultId)->value('value'));
            $nullId = DB::table($name)->insertGetId(['value' => null]);
            $this->assertNull(DB::table($name)->where('id', $nullId)->value('value'));
        } finally {
            Schema::dropIfExists($name);
        }
    }

    private function readChangeCollationCharset(string $table): string
    {
        return DB::selectOne(<<<'SQL'
            SELECT TRIM(cs.RDB$CHARACTER_SET_NAME) AS "charset"
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            JOIN RDB$CHARACTER_SETS cs ON cs.RDB$CHARACTER_SET_ID = f.RDB$CHARACTER_SET_ID
            WHERE rf.RDB$RELATION_NAME = ? AND rf.RDB$FIELD_NAME = 'value'
        SQL, [$table])->charset;
    }

    #[Test]
    #[DataProvider('collationColumnDefinitions')]
    public function it_applies_collation_modifiers(string $operation, bool $nullable, bool $hasDefault)
    {
        $name = 'collation_modifier_test';
        Schema::dropIfExists($name);

        try {
            $define = function (Blueprint $table) use ($nullable, $hasDefault) {
                $column = $table->string('value', 40)->charset('UTF8')->collation('UNICODE_CI');
                if ($nullable) {
                    $column->nullable();
                }
                if ($hasDefault) {
                    $column->default('hello');
                }
            };

            Schema::create($name, function (Blueprint $table) use ($operation, $define) {
                $table->id();
                if ($operation === 'create') {
                    $define($table);
                }
            });
            if ($operation === 'add') {
                Schema::table($name, $define);
            }

            $column = array_column(Schema::getColumns($name), null, 'name')['value'];
            $this->assertSame('varchar', $column['type_name']);
            $this->assertSame('varchar(40)', $column['type']);
            $this->assertSame('UNICODE_CI', $column['collation']);
            $this->assertSame($nullable, $column['nullable']);
            $this->assertSame($hasDefault ? "'hello'" : null, $column['default']);

            $id = DB::table($name)->insertGetId(['value' => 'written']);
            $this->assertSame('written', DB::table($name)->where('id', $id)->value('value'));
            if ($hasDefault) {
                $id = DB::table($name)->insertGetId([]);
                $this->assertSame('hello', DB::table($name)->where('id', $id)->value('value'));
            }
            if ($nullable) {
                $id = DB::table($name)->insertGetId(['value' => null]);
                $this->assertNull(DB::table($name)->where('id', $id)->value('value'));
            }
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function collationColumnDefinitions(): iterable
    {
        foreach (['create', 'add'] as $operation) {
            yield "$operation not null" => [$operation, false, false];
            yield "$operation nullable" => [$operation, true, false];
            yield "$operation default not null" => [$operation, false, true];
            yield "$operation default nullable" => [$operation, true, true];
        }
    }

    #[Test]
    public function it_preserves_modifiers_without_collation()
    {
        $name = 'no_collation_modifier_test';
        Schema::dropIfExists($name);

        try {
            $migration = function () use ($name) {
                Schema::create($name, function (Blueprint $table) {
                    $table->string('value', 40)->charset('UTF8')->default('hello');
                });
            };
            $this->assertSame(
                'create table "no_collation_modifier_test" ("value" VARCHAR(40) CHARACTER SET UTF8 DEFAULT \'hello\' NOT NULL)',
                DB::pretend($migration)[0]['query']
            );
            $migration();
            $column = array_column(Schema::getColumns($name), null, 'name')['value'];
            $this->assertSame('varchar(40)', $column['type']);
            $this->assertFalse($column['nullable']);
            $this->assertSame("'hello'", $column['default']);
            DB::table($name)->insert(['value' => DB::raw('DEFAULT')]);
            $this->assertSame('hello', DB::table($name)->value('value'));
        } finally {
            Schema::dropIfExists($name);
        }
    }

    #[Test]
    #[DataProvider('booleanChangeDefaults')]
    public function it_changes_boolean_defaults(?bool $initial, ?bool $default, bool $specified)
    {
        $name = 'boolean_change_default_test';
        Schema::dropIfExists($name);
        try {
            Schema::create($name, function (Blueprint $table) use ($initial) {
                $table->id();
                $column = $table->boolean('flag')->nullable();
                if ($initial !== null) {
                    $column->default($initial);
                }
            });
            $existingId = DB::table($name)->insertGetId([]);
            $migration = function () use ($name, $default, $specified) {
                Schema::table($name, function (Blueprint $table) use ($default, $specified) {
                    $column = $table->boolean('flag')->nullable()->change();
                    if ($specified) {
                        $column->default($default);
                    }
                });
            };
            if (! $specified) {
                $sql = implode(' ', array_column(DB::pretend($migration), 'query'));
                $this->assertStringNotContainsString(' DEFAULT', strtoupper($sql));
            }
            $migration();
            $expected = $specified ? $default : $initial;
            $id = DB::table($name)->insertGetId([]);
            $columns = array_column(Schema::getColumns($name), null, 'name');
            $this->assertSame($expected === null ? null : ($expected ? 'TRUE' : 'FALSE'), $columns['flag']['default']);
            $this->assertSame($expected, DB::table($name)->where('id', $id)->value('flag'));
            $this->assertSame($initial, DB::table($name)->where('id', $existingId)->value('flag'));
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function booleanChangeDefaults(): iterable
    {
        yield 'set true' => [null, true, true];
        yield 'set false' => [null, false, true];
        yield 'true to false' => [true, false, true];
        yield 'false to true' => [false, true, true];
        yield 'preserve true' => [true, null, false];
        yield 'preserve false' => [false, null, false];
        yield 'remove true' => [true, null, true];
        yield 'remove false' => [false, null, true];
    }

    #[Test]
    #[DataProvider('booleanCreateAndAddDefaults')]
    public function it_preserves_boolean_defaults_on_create_and_add(string $operation, bool $default)
    {
        $name = 'boolean_create_add_default_test';
        Schema::dropIfExists($name);
        try {
            Schema::create($name, function (Blueprint $table) use ($operation, $default) {
                $table->id();
                if ($operation === 'create') {
                    $table->boolean('flag')->default($default);
                }
            });
            if ($operation === 'add') {
                Schema::table($name, fn (Blueprint $table) => $table->boolean('flag')->default($default));
            }
            $columns = array_column(Schema::getColumns($name), null, 'name');
            $this->assertSame($default ? 'TRUE' : 'FALSE', $columns['flag']['default']);
            $this->assertFalse($columns['flag']['nullable']);
            $id = DB::table($name)->insertGetId([]);
            $this->assertSame($default, DB::table($name)->where('id', $id)->value('flag'));
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function booleanCreateAndAddDefaults(): iterable
    {
        foreach (['create', 'add'] as $operation) {
            yield $operation.' true' => [$operation, true];
            yield $operation.' false' => [$operation, false];
        }
    }

    #[Test]
    #[DataProvider('nonBooleanChangeDefaults')]
    public function it_preserves_non_boolean_change_defaults(string $type, $default, string $source, $expected)
    {
        $name = 'other_change_default_test';
        Schema::dropIfExists($name);
        try {
            Schema::create($name, function (Blueprint $table) use ($type) {
                $table->id();
                $type === 'decimal'
                    ? $table->decimal('value', 10, 2)->nullable()
                    : $table->{$type}('value')->nullable();
            });
            Schema::table($name, function (Blueprint $table) use ($type, $default) {
                $column = $type === 'decimal' ? $table->decimal('value', 10, 2) : $table->{$type}('value');
                $column->default($type === 'timestamp' ? DB::raw($default) : $default)->change();
            });
            $columns = array_column(Schema::getColumns($name), null, 'name');
            $this->assertSame($source, $columns['value']['default']);
            if ($type === 'timestamp') {
                $before = DB::selectOne('select cast(current_timestamp as timestamp) as "now" from rdb$database')->now;
            }
            $id = DB::table($name)->insertGetId([]);
            $actual = DB::table($name)->where('id', $id)->value('value');
            if ($type === 'timestamp') {
                $after = DB::selectOne('select cast(current_timestamp as timestamp) as "now" from rdb$database')->now;
                $this->assertNotNull($actual);
                $this->assertGreaterThanOrEqual($before, $actual);
                $this->assertLessThanOrEqual($after, $actual);
            } else {
                $this->assertSame($expected, $type === 'decimal' ? (string) $actual : $actual);
            }
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function nonBooleanChangeDefaults(): iterable
    {
        yield 'integer' => ['integer', 2, "'2'", 2];
        yield 'decimal' => ['decimal', '2.75', "'2.75'", '2.75'];
        yield 'apostrophe' => ['string', "it's fine", "'it''s fine'", "it's fine"];
        yield 'expression' => ['timestamp', 'CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP', null];
    }

    #[Test]
    public function it_runs_a_representative_users_and_posts_migration_lifecycle()
    {
        try {
            $this->createSmokeMigrationTables();
            $this->assertTrue(Schema::hasTable('smoke_users'));
            $this->assertTrue(Schema::hasTable('smoke_posts'));
            $users = array_column(Schema::getColumns('smoke_users'), null, 'name');
            $posts = array_column(Schema::getColumns('smoke_posts'), null, 'name');
            $this->assertSame(['id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at'], array_keys($users));
            $this->assertSame(['id', 'user_id', 'title', 'body', 'published', 'published_at', 'deleted_at', 'created_at', 'updated_at'], array_keys($posts));
            $this->assertTrue($users['id']['auto_increment']);
            $this->assertTrue($posts['id']['auto_increment']);
            $this->assertFalse($posts['user_id']['auto_increment']);
            $this->assertSame('bigint', $posts['user_id']['type_name']);
            $this->assertSame('boolean', $posts['published']['type_name']);
            $this->assertSame('blob', $posts['body']['type_name']);
            foreach (['email_verified_at', 'remember_token', 'created_at', 'updated_at'] as $column) {
                $this->assertTrue($users[$column]['nullable']);
            }
            foreach (['published_at', 'deleted_at', 'created_at', 'updated_at'] as $column) {
                $this->assertSame('timestamp', $posts[$column]['type_name']);
                $this->assertTrue($posts[$column]['nullable']);
            }
            $this->assertTrue(Schema::hasIndex('smoke_users', ['id'], 'primary'));
            $this->assertTrue(Schema::hasIndex('smoke_users', ['email'], 'unique'));
            $this->assertTrue(Schema::hasIndex('smoke_posts', ['id'], 'primary'));
            $this->assertTrue(Schema::hasIndex('smoke_posts', ['title']));
            $this->assertForeignKeyDefinition('smoke_posts', ['user_id'], 'smoke_users', ['id']);
            $this->assertSame('cascade', Schema::getForeignKeys('smoke_posts')[0]['on_delete']);

            $user = ['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'test-password-hash'];
            $userId = DB::table('smoke_users')->insertGetId($user);
            $this->assertIsInt($userId);
            $this->assertGreaterThan(0, $userId);
            $post = ['user_id' => $userId, 'title' => 'First post', 'body' => 'A complete migration lifecycle.'];
            $postId = DB::table('smoke_posts')->insertGetId($post);
            $this->assertIsInt($postId);
            $this->assertGreaterThan(0, $postId);
            $saved = DB::table('smoke_posts')->where('id', $postId)->first();
            $this->assertSame($userId, $saved->user_id);
            $this->assertSame($post['body'], $saved->body);
            $this->assertFalse($saved->published);
            $this->assertSame('Ada', DB::table('smoke_users')->where('id', $userId)->value('name'));
            $this->assertSame(1, DB::table('smoke_posts')->where('id', $postId)->update([
                'title' => 'Published post', 'published' => true, 'published_at' => '2026-01-15 12:34:56',
            ]));
            $saved = DB::table('smoke_posts')->where('id', $postId)->first();
            $this->assertSame('Published post', $saved->title);
            $this->assertTrue($saved->published);
            $this->assertSame('2026-01-15 12:34:56', $saved->published_at);

            try {
                DB::table('smoke_users')->insert($user);
                $this->fail('The email UNIQUE constraint must reject a duplicate user.');
            } catch (QueryException $exception) {
                $this->assertSame(-803, $exception->errorInfo[1]);
            }
            $this->assertSame(1, DB::table('smoke_users')->count());
            $this->assertRejectedForeignKeyInsert('smoke_posts', array_replace($post, ['user_id' => $userId + 1000]));
            $this->assertSame(1, DB::table('smoke_users')->where('id', $userId)->delete());
            $this->assertSame(0, DB::table('smoke_posts')->count());
            $this->assertSame(0, DB::table('smoke_users')->count());

            // Release cascade requests, then perform the migration's down order.
            DB::disconnect();
            Schema::drop('smoke_posts');
            $this->assertFalse(Schema::hasTable('smoke_posts'));
            $this->assertTrue(Schema::hasTable('smoke_users'));
            Schema::drop('smoke_users');
            $this->assertFalse(Schema::hasTable('smoke_users'));
        } finally {
            DB::disconnect();
            Schema::dropIfExists('smoke_posts');
            Schema::dropIfExists('smoke_users');
        }
    }

    #[Test]
    public function it_saves_and_reloads_an_eloquent_model_on_the_smoke_schema()
    {
        try {
            $this->createSmokeMigrationTables();
            $model = new IdentityTestModel;
            $model->setTable('smoke_users');
            $model->timestamps = true;
            $model->forceFill(['name' => 'Grace', 'email' => 'grace@example.test', 'password' => 'test-password-hash']);
            $this->assertTrue($model->save());
            $this->assertIsInt($model->getKey());
            $this->assertGreaterThan(0, $model->getKey());
            $reloaded = $model->newQuery()->findOrFail($model->getKey());
            $this->assertSame($model->getKey(), $reloaded->getKey());
            foreach (['name', 'email', 'password'] as $attribute) {
                $this->assertSame($model->{$attribute}, $reloaded->{$attribute});
            }
            $this->assertNotNull($reloaded->created_at);
            $this->assertNotNull($reloaded->updated_at);
            $this->assertSame(1, DB::table('smoke_users')->count());
        } finally {
            Schema::dropIfExists('smoke_posts');
            Schema::dropIfExists('smoke_users');
        }
    }

    private function createSmokeMigrationTables(): void
    {
        // Isolate these tables from the shared users/orders fixtures in other tests.
        Schema::dropIfExists('smoke_posts');
        Schema::dropIfExists('smoke_users');
        Schema::create('smoke_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('smoke_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('smoke_users')->cascadeOnDelete();
            $table->string('title')->index();
            $table->text('body');
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    #[Test]
    #[DataProvider('convenienceColumnHelpers')]
    public function it_creates_and_drops_convenience_columns(string $method, string $dropMethod, array $names, string $type, int $fieldType, ?int $length, string $value)
    {
        $tableName = 'helper_columns_test';
        try {
            Schema::dropIfExists($tableName);
            Schema::create($tableName, function (Blueprint $table) use ($method) {
                $table->integer('id');
                $table->{$method}();
            });
            $columns = array_column(Schema::getColumns($tableName), null, 'name');
            $metadata = array_column($this->readLifecycleColumnMetadata($tableName), null, 'name');
            $this->assertSame(array_merge(['id'], $names), array_keys($columns));
            foreach ($names as $name) {
                $this->assertSame($type, $columns[$name]['type_name']);
                $this->assertTrue($columns[$name]['nullable']);
                $this->assertSame($fieldType, $metadata[$name]['field_type']);
                $this->assertSame(0, $metadata[$name]['null_flag']);
                if ($length !== null) {
                    $this->assertSame($length, $metadata[$name]['length']);
                }
            }
            DB::table($tableName)->insert(['id' => 1] + array_fill_keys($names, $value));
            DB::table($tableName)->insert(['id' => 2]);
            $row = DB::table($tableName)->where('id', 1)->first();
            $nullRow = DB::table($tableName)->where('id', 2)->first();
            foreach ($names as $name) {
                $this->assertNull($nullRow->{$name});
                if ($method === 'softDeletesTz') {
                    $this->assertIsString($row->{$name});
                    $this->assertMatchesRegularExpression('/(?:[+-][0-9]{2}:[0-9]{2}|[A-Za-z]+(?:\/[A-Za-z_]+)*)$/', $row->{$name});
                    $this->assertSame(
                        (new \DateTimeImmutable($value))->getTimestamp(),
                        (new \DateTimeImmutable($row->{$name}))->getTimestamp(),
                    );
                } else {
                    $this->assertSame($value, $row->{$name});
                }
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->{$dropMethod}());
            $this->assertSame(['id'], array_column($this->readLifecycleColumnMetadata($tableName), 'name'));
            $this->assertSame([1, 2], DB::table($tableName)->orderBy('id')->pluck('id')->all());
        } finally {
            Schema::dropIfExists($tableName);
        }
    }

    public static function convenienceColumnHelpers(): array
    {
        return [
            'timestamps' => ['timestamps', 'dropTimestamps', ['created_at', 'updated_at'], 'timestamp', 35, null, '2026-01-15 12:34:56'],
            'remember token' => ['rememberToken', 'dropRememberToken', ['remember_token'], 'varchar', 37, 100, str_repeat('a', 100)],
            'soft deletes' => ['softDeletes', 'dropSoftDeletes', ['deleted_at'], 'timestamp', 35, null, '2026-01-15 12:34:56'],
            'soft deletes TZ' => ['softDeletesTz', 'dropSoftDeletesTz', ['deleted_at'], 'timestamp with time zone', 29, null, '2026-01-15 12:34:56 +02:00'],
        ];
    }

    #[Test]
    #[DataProvider('convenienceMorphHelpers')]
    public function it_creates_and_drops_morph_columns(string $method, bool $nullable, string $type, int $fieldType, ?int $length, int|string $value)
    {
        $tableName = 'helper_morph_test';
        try {
            Schema::dropIfExists($tableName);
            Schema::create($tableName, function (Blueprint $table) use ($method) {
                $table->integer('id');
                $table->{$method}('subject');
            });
            $columns = array_column(Schema::getColumns($tableName), null, 'name');
            $metadata = array_column($this->readLifecycleColumnMetadata($tableName), null, 'name');
            $this->assertSame(['id', 'subject_type', 'subject_id'], array_keys($columns));
            $this->assertSame('varchar', $columns['subject_type']['type_name']);
            $this->assertSame(37, $metadata['subject_type']['field_type']);
            $this->assertSame(255, $metadata['subject_type']['length']);
            $this->assertSame($type, $columns['subject_id']['type_name']);
            $this->assertSame($fieldType, $metadata['subject_id']['field_type']);
            if ($length !== null) {
                $this->assertSame($length, $metadata['subject_id']['length']);
            }
            foreach (['subject_type', 'subject_id'] as $name) {
                $this->assertSame($nullable, $columns[$name]['nullable']);
                $this->assertSame($nullable ? 0 : 1, $metadata[$name]['null_flag']);
                $this->assertFalse($columns[$name]['auto_increment']);
            }
            $indexes = Schema::getIndexes($tableName);
            $this->assertCount(1, $indexes);
            $this->assertSame(['subject_type', 'subject_id'], $indexes[0]['columns']);
            $this->assertFalse($indexes[0]['unique']);
            $this->assertFalse($indexes[0]['primary']);
            $this->assertSame(['subject_type', 'subject_id'], array_column($this->readIndexIntrospectionMetadata($tableName), 'column_name'));
            DB::table($tableName)->insert(['id' => 1, 'subject_type' => 'Article', 'subject_id' => $value]);
            $row = DB::table($tableName)->where('id', 1)->first();
            $this->assertSame('Article', $row->subject_type);
            $this->assertSame($value, $row->subject_id);
            if ($nullable) {
                DB::table($tableName)->insert(['id' => 2, 'subject_type' => null, 'subject_id' => null]);
                $row = DB::table($tableName)->where('id', 2)->first();
                $this->assertNull($row->subject_type);
                $this->assertNull($row->subject_id);
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->dropMorphs('subject'));
            $this->assertSame(['id'], array_column($this->readLifecycleColumnMetadata($tableName), 'name'));
            $this->assertSame([], Schema::getIndexes($tableName));
            $this->assertSame([], $this->readIndexIntrospectionMetadata($tableName));
            $this->assertSame($nullable ? [1, 2] : [1], DB::table($tableName)->orderBy('id')->pluck('id')->all());
        } finally {
            Schema::dropIfExists($tableName);
        }
    }

    public static function convenienceMorphHelpers(): array
    {
        return [
            'numeric' => ['morphs', false, 'bigint', 16, null, 42],
            'nullable numeric' => ['nullableMorphs', true, 'bigint', 16, null, 42],
            'UUID' => ['uuidMorphs', false, 'char', 14, 36, '550e8400-e29b-41d4-a716-446655440000'],
            'nullable UUID' => ['nullableUuidMorphs', true, 'char', 14, 36, '550e8400-e29b-41d4-a716-446655440000'],
            'ULID' => ['ulidMorphs', false, 'char', 14, 26, '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            'nullable ULID' => ['nullableUlidMorphs', true, 'char', 14, 26, '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
        ];
    }

    #[Test]
    public function it_enforces_foreign_id_constrained()
    {
        $parent = 'fk_helper_parents';
        $child = 'fk_helper_children';
        try {
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
            Schema::create($parent, fn (Blueprint $table) => $table->id());
            Schema::create($child, function (Blueprint $table) {
                $table->id();
                $table->foreignId('fk_helper_parent_id')->constrained();
            });
            $this->assertForeignKeyDefinition($child, ['fk_helper_parent_id'], $parent, ['id']);
            $columns = array_column(Schema::getColumns($child), null, 'name');
            $this->assertSame('bigint', $columns['fk_helper_parent_id']['type_name']);
            $this->assertFalse($columns['fk_helper_parent_id']['auto_increment']);
            $this->assertFalse($columns['fk_helper_parent_id']['nullable']);
            DB::table($parent)->insert(['id' => 1]);
            DB::table($child)->insert(['fk_helper_parent_id' => 1]);
            $this->assertRejectedForeignKeyInsert($child, ['fk_helper_parent_id' => 999]);
            $this->assertSame([1], DB::table($child)->pluck('fk_helper_parent_id')->all());
        } finally {
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
        }
    }

    #[Test]
    public function it_creates_and_drops_a_foreign_key_constraint()
    {
        $parent = 'fk_lifecycle_parents';
        $child = 'fk_lifecycle_children';
        try {
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
            Schema::create($parent, fn (Blueprint $table) => $table->id());
            Schema::create($child, function (Blueprint $table) use ($parent) {
                $table->bigInteger('parent_id');
                $table->foreign('parent_id', 'fk_lifecycle_reference')->references('id')->on($parent);
            });
            $this->assertForeignKeyDefinition($child, ['parent_id'], $parent, ['id']);
            DB::table($parent)->insert(['id' => 1]);
            DB::table($child)->insert(['parent_id' => 1]);
            $this->assertRejectedForeignKeyInsert($child, ['parent_id' => 999]);
            Schema::table($child, fn (Blueprint $table) => $table->dropForeign('fk_lifecycle_reference'));
            $this->assertSame([], Schema::getForeignKeys($child));
            $this->assertTrue(Schema::hasColumn($child, 'parent_id'));
            DB::table($child)->insert(['parent_id' => 999]);
            $this->assertSame([1, 999], DB::table($child)->orderBy('parent_id')->pluck('parent_id')->all());
        } finally {
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
        }
    }

    #[Test]
    public function it_cascades_parent_deletion_to_constrained_children()
    {
        $parent = 'fk_cascade_parents';
        $child = 'fk_cascade_children';
        try {
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
            Schema::create($parent, fn (Blueprint $table) => $table->id());
            Schema::create($child, function (Blueprint $table) use ($parent) {
                $table->foreignId('parent_id')->constrained($parent)->cascadeOnDelete();
            });
            $this->assertForeignKeyDefinition($child, ['parent_id'], $parent, ['id']);
            $this->assertSame('cascade', Schema::getForeignKeys($child)[0]['on_delete']);
            foreach ([1, 2] as $id) {
                DB::table($parent)->insert(['id' => $id]);
                DB::table($child)->insert(['parent_id' => $id]);
            }
            $this->assertSame(2, DB::table($child)->count());
            DB::table($parent)->where('id', 1)->delete();
            $this->assertSame([2], DB::table($child)->pluck('parent_id')->all());
            $this->assertSame([2], DB::table($parent)->pluck('id')->all());
        } finally {
            // Release attachment-held cascade requests before dropping their tables.
            DB::disconnect();
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
        }
    }

    #[Test]
    public function it_enforces_and_drops_a_composite_foreign_key()
    {
        $parent = 'fk_pair_parents';
        $child = 'fk_pair_children';
        try {
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
            Schema::create($parent, function (Blueprint $table) {
                $table->integer('key_a');
                $table->integer('key_b');
                $table->primary(['key_b', 'key_a']);
            });
            Schema::create($child, function (Blueprint $table) use ($parent) {
                $table->integer('ref_a');
                $table->integer('ref_b');
                $table->foreign(['ref_b', 'ref_a'], 'fk_pair_reference')
                    ->references(['key_b', 'key_a'])->on($parent);
            });
            $this->assertForeignKeyDefinition($child, ['ref_b', 'ref_a'], $parent, ['key_b', 'key_a']);
            $indexes = Schema::getIndexes($parent);
            $this->assertCount(1, $indexes);
            $this->assertTrue($indexes[0]['primary']);
            $this->assertSame(['key_b', 'key_a'], $indexes[0]['columns']);
            DB::table($parent)->insert(['key_a' => 1, 'key_b' => 10]);
            DB::table($parent)->insert(['key_a' => 2, 'key_b' => 20]);
            DB::table($child)->insert(['ref_a' => 1, 'ref_b' => 10]);
            // Both values exist individually, but their combination does not.
            $invalid = ['ref_a' => 1, 'ref_b' => 20];
            $this->assertRejectedForeignKeyInsert($child, $invalid);
            Schema::table($child, fn (Blueprint $table) => $table->dropForeign('fk_pair_reference'));
            $this->assertSame([], Schema::getForeignKeys($child));
            DB::table($child)->insert($invalid);
            $this->assertSame([10, 20], DB::table($child)->orderBy('ref_b')->pluck('ref_b')->all());
        } finally {
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
        }
    }

    #[Test]
    public function it_drops_a_foreign_key_with_a_long_generated_name()
    {
        $parent = 'fk_long_parents';
        $child = 'foreign_key_long_identifier_children';
        $column = 'parent_reference_identifier_value';
        $generatedName = null;
        try {
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
            Schema::create($parent, fn (Blueprint $table) => $table->id());
            Schema::create($child, function (Blueprint $table) use ($parent, $column, &$generatedName) {
                $table->bigInteger($column);
                $generatedName = $table->foreign($column)->references('id')->on($parent)->index;
            });
            $this->assertGreaterThan(63, strlen($generatedName));
            $this->assertForeignKeyDefinition($child, [$column], $parent, ['id']);
            $actualName = Schema::getForeignKeys($child)[0]['name'];
            $this->assertNotSame('', $actualName);
            $this->assertNotSame($generatedName, $actualName);
            DB::table($parent)->insert(['id' => 1]);
            DB::table($child)->insert([$column => 1]);
            $this->assertRejectedForeignKeyInsert($child, [$column => 999]);
            Schema::table($child, fn (Blueprint $table) => $table->dropForeign([$column]));
            $this->assertSame([], Schema::getForeignKeys($child));
            $this->assertNull(DB::selectOne(<<<'SQL'
                SELECT RDB$CONSTRAINT_NAME
                FROM RDB$RELATION_CONSTRAINTS
                WHERE RDB$RELATION_NAME = ? AND RDB$CONSTRAINT_NAME = ?
            SQL, [$child, $actualName]));
            DB::table($child)->insert([$column => 999]);
            // Check persistence without relying on PDO's length-limited result column names.
            $this->assertSame(2, DB::table($child)->count());
            $this->assertTrue(DB::table($child)->where($column, 1)->exists());
            $this->assertTrue(DB::table($child)->where($column, 999)->exists());
        } finally {
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
        }
    }

    private function assertForeignKeyDefinition(string $child, array $columns, string $parent, array $foreignColumns): void
    {
        $keys = Schema::getForeignKeys($child);
        $this->assertCount(1, $keys);
        $this->assertNotSame('', $keys[0]['name']);
        $this->assertSame($columns, $keys[0]['columns']);
        $this->assertSame($parent, $keys[0]['foreign_table']);
        $this->assertSame($foreignColumns, $keys[0]['foreign_columns']);
    }

    private function assertRejectedForeignKeyInsert(string $table, array $values): void
    {
        $before = DB::table($table)->count();
        try {
            DB::table($table)->insert($values);
            $this->fail('Firebird must reject a child row without a matching parent key.');
        } catch (QueryException $exception) {
            // Firebird SQLCODE -530 identifies a foreign-key violation;
            // PDO may report it with either SQLSTATE 23000 or HY000.
            $this->assertSame(-530, $exception->errorInfo[1]);
        }
        $this->assertSame($before, DB::table($table)->count());
    }

    #[Test]
    public function it_adds_and_drops_a_column_without_losing_existing_data()
    {
        $this->assertAddedColumnLifecycle(false);
    }

    #[Test]
    public function it_adds_and_drops_multiple_columns_without_losing_existing_data()
    {
        $this->assertAddedColumnLifecycle(true);
    }

    private function assertAddedColumnLifecycle(bool $multiple): void
    {
        $name = $multiple ? 'add_many_lifecycle_test' : 'add_one_lifecycle_test';

        try {
            Schema::dropIfExists($name);
            Schema::create($name, function (Blueprint $table) {
                $table->integer('id');
                $table->string('name', 40);
            });
            DB::table($name)->insert(['id' => 1, 'name' => 'Original']);
            $before = $this->readLifecycleColumnMetadata($name);

            Schema::table($name, function (Blueprint $table) use ($multiple) {
                $table->string('note', 60)->nullable()->default('pending');
                if ($multiple) {
                    $table->integer('quantity')->default(7);
                }
            });

            $columns = $this->readLifecycleColumnMetadata($name);
            $this->assertSame($multiple ? ['id', 'name', 'note', 'quantity'] : ['id', 'name', 'note'], array_column($columns, 'name'));
            $this->assertSame($before, array_slice($columns, 0, 2));
            $this->assertSame(37, $columns[2]['field_type']);
            $this->assertSame(60, $columns[2]['length']);
            $this->assertSame(0, $columns[2]['null_flag']);
            $this->assertSame("DEFAULT 'pending'", $columns[2]['default_source']);
            if ($multiple) {
                $this->assertSame(8, $columns[3]['field_type']);
                $this->assertSame(1, $columns[3]['null_flag']);
            }
            $this->assertSame('Original', DB::table($name)->where('id', 1)->value('name'));
            $this->assertSame(1, DB::table($name)->count());
            DB::table($name)->insert(['id' => 2, 'name' => 'New row']);
            $this->assertSame('pending', DB::table($name)->where('id', 2)->value('note'));
            if ($multiple) {
                $this->assertSame(7, DB::table($name)->where('id', 2)->value('quantity'));
            }
            DB::table($name)->where('id', 2)->delete();

            Schema::table($name, function (Blueprint $table) use ($multiple) {
                $table->dropColumn($multiple ? ['note', 'quantity'] : 'note');
            });
            $this->assertSame($before, $this->readLifecycleColumnMetadata($name));
            $this->assertEquals([(object) ['id' => 1, 'name' => 'Original']], DB::table($name)->get()->all());
        } finally {
            Schema::dropIfExists($name);
        }
    }

    #[Test]
    public function it_renames_a_populated_column_and_restores_it()
    {
        $name = 'rename_data_lifecycle_test';

        try {
            Schema::dropIfExists($name);
            Schema::create($name, function (Blueprint $table) {
                $table->integer('id');
                $table->string('old_value', 40)->nullable()->default('original');
            });
            DB::table($name)->insert(['id' => 1, 'old_value' => 'Saved value']);
            DB::table($name)->insert(['id' => 2, 'old_value' => null]);
            DB::table($name)->insert(['id' => 3]);
            $before = $this->readLifecycleColumnMetadata($name);
            $this->assertSame(37, $before[1]['field_type']);
            $this->assertSame(40, $before[1]['length']);
            $this->assertSame(0, $before[1]['null_flag']);
            $this->assertSame("DEFAULT 'original'", $before[1]['default_source']);
            $values = ['Saved value', null, 'original'];

            Schema::table($name, fn (Blueprint $table) => $table->renameColumn('old_value', 'new_value'));
            $renamed = $before;
            $renamed[1]['name'] = 'new_value';
            $this->assertSame($renamed, $this->readLifecycleColumnMetadata($name));
            $this->assertSame($values, DB::table($name)->orderBy('id')->pluck('new_value')->all());

            Schema::table($name, fn (Blueprint $table) => $table->renameColumn('new_value', 'old_value'));
            $this->assertSame($before, $this->readLifecycleColumnMetadata($name));
            $this->assertSame($values, DB::table($name)->orderBy('id')->pluck('old_value')->all());
        } finally {
            Schema::dropIfExists($name);
        }
    }

    #[Test]
    public function it_changes_a_populated_column_and_enforces_default_and_nullability()
    {
        $name = 'change_data_lifecycle_test';

        try {
            Schema::dropIfExists($name);
            Schema::create($name, function (Blueprint $table) {
                $table->integer('id');
                $table->string('value', 40)->nullable()->default('before');
            });
            DB::table($name)->insert(['id' => 1, 'value' => 'Saved value']);
            $before = $this->readLifecycleColumnMetadata($name);

            Schema::table($name, function (Blueprint $table) {
                $table->string('value', 100)->nullable(false)->default('after')->change();
            });
            $expected = $before;
            $expected[1]['length'] = 100;
            $expected[1]['null_flag'] = 1;
            $expected[1]['default_source'] = "DEFAULT 'after'";
            $this->assertSame($expected, $this->readLifecycleColumnMetadata($name));
            $this->assertSame('Saved value', DB::table($name)->where('id', 1)->value('value'));
            DB::table($name)->insert(['id' => 2]);
            $this->assertSame('after', DB::table($name)->where('id', 2)->value('value'));

            try {
                DB::table($name)->insert(['id' => 3, 'value' => null]);
                $this->fail('NOT NULL must reject an explicit NULL.');
            } catch (QueryException $exception) {
                $this->assertInstanceOf(QueryException::class, $exception);
            }
            $this->assertFalse(DB::table($name)->where('id', 3)->exists());

            // Restore default and nullability; retain the safely widened length.
            Schema::table($name, function (Blueprint $table) {
                $table->string('value', 100)->nullable()->default('before')->change();
            });
            $restored = $before;
            $restored[1]['length'] = 100;
            $this->assertSame($restored, $this->readLifecycleColumnMetadata($name));
            DB::table($name)->insert(['id' => 3, 'value' => null]);
            DB::table($name)->insert(['id' => 4]);
            $this->assertSame(['Saved value', 'after', null, 'before'], DB::table($name)->orderBy('id')->pluck('value')->all());
        } finally {
            Schema::dropIfExists($name);
        }
    }

    #[Test]
    #[DataProvider('unsupportedLifecycleOperations')]
    public function it_rejects_unsupported_schema_operations_without_changing_schema(string $operation)
    {
        $name = 'unsupported_lifecycle_test';
        $target = 'unsupported_target_test';

        try {
            Schema::dropIfExists($target);
            Schema::dropIfExists($name);
            Schema::create($name, function (Blueprint $table) {
                $table->integer('id');
                $table->string('value', 40)->nullable()->default('original');
                $table->index('value', 'unsupported_original_idx');
            });
            DB::table($name)->insert(['id' => 1, 'value' => 'Saved value']);
            $beforeColumns = $this->readLifecycleColumnMetadata($name);
            $beforeIndexes = $this->readIndexIntrospectionMetadata($name);
            $exception = null;

            try {
                match ($operation) {
                    'temporary' => Schema::create($target, function (Blueprint $table) {
                        $table->temporary();
                        $table->integer('id');
                    }),
                    'rename' => Schema::rename($name, $target),
                    'renameIndex' => Schema::table($name, fn (Blueprint $table) => $table->renameIndex('unsupported_original_idx', 'unsupported_renamed_idx')),
                    'spatialIndex' => Schema::table($name, fn (Blueprint $table) => $table->spatialIndex('value', 'unsupported_spatial_idx')),
                };
            } catch (\Exception $caught) {
                $exception = $caught;
            }

            // Verify preservation even when an unsupported command silently does nothing.
            $this->assertFalse(Schema::hasTable($target));
            $this->assertTrue(Schema::hasTable($name));
            $this->assertSame($beforeColumns, $this->readLifecycleColumnMetadata($name));
            $this->assertEquals($beforeIndexes, $this->readIndexIntrospectionMetadata($name));
            $this->assertEquals([(object) ['id' => 1, 'value' => 'Saved value']], DB::table($name)->get()->all());
            $this->assertNotNull($exception, $operation.' must throw an explicit exception instead of silently succeeding.');
        } finally {
            Schema::dropIfExists($target);
            Schema::dropIfExists($name);
        }
    }

    public static function unsupportedLifecycleOperations(): array
    {
        return [
            'temporary table' => ['temporary'],
            'rename table' => ['rename'],
            'rename index' => ['renameIndex'],
            'spatial index' => ['spatialIndex'],
        ];
    }

    private function readLifecycleColumnMetadata(string $table): array
    {
        return array_map(fn (object $column) => (array) $column, DB::select(<<<'SQL'
            SELECT TRIM(rf.RDB$FIELD_NAME) AS "name",
                   f.RDB$FIELD_TYPE AS "field_type",
                   f.RDB$CHARACTER_LENGTH AS "length",
                   COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag",
                   TRIM(CAST(rf.RDB$DEFAULT_SOURCE AS VARCHAR(255))) AS "default_source"
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = ?
            ORDER BY rf.RDB$FIELD_POSITION
        SQL, [$table]));
    }

    #[Test]
    public function it_drops_a_column()
    {
        Schema::dropIfExists('drop_column_test');

        Schema::create('drop_column_test', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('obsolete');
        });

        try {
            Schema::table('drop_column_test', function (Blueprint $table) {
                $table->dropColumn('obsolete');
            });

            $this->assertFalse(Schema::hasColumn('drop_column_test', 'obsolete'));
            $this->assertTrue(Schema::hasColumn('drop_column_test', 'id'));
            $this->assertTrue(Schema::hasColumn('drop_column_test', 'name'));
        } finally {
            Schema::drop('drop_column_test');
        }
    }

    #[Test]
    public function it_drops_multiple_columns()
    {
        Schema::dropIfExists('drop_multiple_columns_test');

        Schema::create('drop_multiple_columns_test', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('obsolete_one');
            $table->string('obsolete_two');
        });

        try {
            Schema::table('drop_multiple_columns_test', function (Blueprint $table) {
                $table->dropColumn(['obsolete_one', 'obsolete_two']);
            });

            $this->assertFalse(Schema::hasColumn('drop_multiple_columns_test', 'obsolete_one'));
            $this->assertFalse(Schema::hasColumn('drop_multiple_columns_test', 'obsolete_two'));
            $this->assertTrue(Schema::hasColumn('drop_multiple_columns_test', 'id'));
            $this->assertTrue(Schema::hasColumn('drop_multiple_columns_test', 'name'));
        } finally {
            Schema::drop('drop_multiple_columns_test');
        }
    }

    #[Test]
    public function it_fails_explicitly_when_dropping_a_column_used_by_an_index()
    {
        Schema::dropIfExists('drop_indexed_column_test');

        try {
            Schema::create('drop_indexed_column_test', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('indexed_value');
                $table->index('indexed_value');
            });

            try {
                Schema::table('drop_indexed_column_test', function (Blueprint $table) {
                    $table->dropColumn('indexed_value');
                });

                $this->fail('Dropping an indexed column should throw a QueryException.');
            } catch (QueryException $exception) {
                $this->assertInstanceOf(QueryException::class, $exception);
            }

            $this->assertTrue(Schema::hasColumn('drop_indexed_column_test', 'indexed_value'));

            $index = DB::selectOne(<<<'SQL'
                SELECT TRIM(i.RDB$INDEX_NAME) AS "index_name"
                FROM RDB$INDICES i
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
                WHERE i.RDB$RELATION_NAME = 'drop_indexed_column_test'
                  AND s.RDB$FIELD_NAME = 'indexed_value'
            SQL);

            $this->assertNotNull($index);
            $this->assertTrue(Schema::hasColumn('drop_indexed_column_test', 'id'));
            $this->assertTrue(Schema::hasColumn('drop_indexed_column_test', 'name'));
        } finally {
            Schema::dropIfExists('drop_indexed_column_test');
        }
    }

    #[Test]
    public function it_drops_a_column_and_its_unique_constraint()
    {
        Schema::dropIfExists('drop_unique_column_test');

        try {
            Schema::create('drop_unique_column_test', function (Blueprint $table) {
                $table->id();
                $table->string('unique_value');
                $table->unique('unique_value');
            });

            $constraint = DB::selectOne(<<<'SQL'
                SELECT TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name",
                       TRIM(rc.RDB$INDEX_NAME) AS "index_name"
                FROM RDB$RELATION_CONSTRAINTS rc
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
                WHERE rc.RDB$RELATION_NAME = 'drop_unique_column_test'
                  AND rc.RDB$CONSTRAINT_TYPE = 'UNIQUE'
                  AND s.RDB$FIELD_NAME = 'unique_value'
            SQL);

            $this->assertNotNull($constraint);
            $this->assertNotNull($constraint->index_name);

            Schema::table('drop_unique_column_test', function (Blueprint $table) {
                $table->dropColumn('unique_value');
            });

            $columns = DB::select(<<<'SQL'
                SELECT TRIM(RDB$FIELD_NAME) AS "name"
                FROM RDB$RELATION_FIELDS
                WHERE RDB$RELATION_NAME = 'drop_unique_column_test'
            SQL);

            $columnNames = array_column($columns, 'name');
            $this->assertNotContains('unique_value', $columnNames);
            $this->assertContains('id', $columnNames);

            $this->assertNull(DB::selectOne(<<<'SQL'
                SELECT RDB$CONSTRAINT_NAME
                FROM RDB$RELATION_CONSTRAINTS
                WHERE RDB$RELATION_NAME = 'drop_unique_column_test'
                  AND RDB$CONSTRAINT_NAME = ?
            SQL, [$constraint->constraint_name]));

            $this->assertNull(DB::selectOne(<<<'SQL'
                SELECT RDB$INDEX_NAME
                FROM RDB$INDICES
                WHERE RDB$RELATION_NAME = 'drop_unique_column_test'
                  AND RDB$INDEX_NAME = ?
            SQL, [$constraint->index_name]));

            $primaryKey = DB::selectOne(<<<'SQL'
                SELECT TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name"
                FROM RDB$RELATION_CONSTRAINTS rc
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
                WHERE rc.RDB$RELATION_NAME = 'drop_unique_column_test'
                  AND rc.RDB$CONSTRAINT_TYPE = 'PRIMARY KEY'
                  AND s.RDB$FIELD_NAME = 'id'
            SQL);

            $this->assertNotNull($primaryKey);
        } finally {
            Schema::dropIfExists('drop_unique_column_test');
        }
    }

    #[Test]
    public function it_renames_a_column()
    {
        Schema::dropIfExists('rename_column_test');

        Schema::create('rename_column_test', function (Blueprint $table) {
            $table->id();
            $table->string('old_name');
        });

        try {
            Schema::table('rename_column_test', function (Blueprint $table) {
                $table->renameColumn('old_name', 'new_name');
            });

            $this->assertFalse(Schema::hasColumn('rename_column_test', 'old_name'));
            $this->assertTrue(Schema::hasColumn('rename_column_test', 'new_name'));
        } finally {
            Schema::drop('rename_column_test');
        }
    }

    #[Test]
    public function it_creates_an_identity_primary_key_with_id()
    {
        Schema::dropIfExists('identity_test');

        Schema::create('identity_test', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        $column = DB::selectOne(<<<'SQL'
            SELECT
                TRIM(rf.RDB$FIELD_NAME) AS "name",
                rf.RDB$IDENTITY_TYPE AS "identity_type"
            FROM RDB$RELATION_FIELDS rf
            WHERE rf.RDB$RELATION_NAME = 'identity_test'
              AND rf.RDB$FIELD_NAME = 'id'
        SQL);

        $this->assertNotNull($column);
        $this->assertSame('id', $column->name);
        $this->assertNotNull($column->identity_type);

        $primaryKey = DB::selectOne(<<<'SQL'
            SELECT
                TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name"
            FROM RDB$RELATION_CONSTRAINTS rc
            JOIN RDB$INDEX_SEGMENTS seg
            ON seg.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
            WHERE rc.RDB$RELATION_NAME = 'identity_test'
            AND rc.RDB$CONSTRAINT_TYPE = 'PRIMARY KEY'
            AND seg.RDB$FIELD_NAME = 'id'
        SQL);

        $this->assertNotNull($primaryKey);

        Schema::drop('identity_test');
    }

    #[Test]
    public function it_creates_an_integer_identity_with_increments()
    {
        Schema::dropIfExists('increments_test');

        Schema::create('increments_test', function (Blueprint $table) {
            $table->increments('id');
        });

        $column = DB::selectOne(<<<'SQL'
            SELECT
                TRIM(rf.RDB$FIELD_NAME) AS "name",
                rf.RDB$IDENTITY_TYPE AS "identity_type",
                f.RDB$FIELD_TYPE AS "field_type"
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f
            ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = 'increments_test'
            AND rf.RDB$FIELD_NAME = 'id'
        SQL);

        $this->assertNotNull($column);
        $this->assertSame('id', $column->name);
        $this->assertNotNull($column->identity_type);

        // Firebird field type 8 = INTEGER
        $this->assertSame(8, $column->field_type);

        Schema::drop('increments_test');
    }

    #[Test]
    public function it_creates_a_bigint_identity_with_big_increments()
    {
        Schema::dropIfExists('big_increments_test');

        Schema::create('big_increments_test', function (Blueprint $table) {
            $table->bigIncrements('id');
        });

        $column = DB::selectOne(<<<'SQL'
            SELECT
                TRIM(rf.RDB$FIELD_NAME) AS "name",
                rf.RDB$IDENTITY_TYPE AS "identity_type",
                f.RDB$FIELD_TYPE AS "field_type"
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f
            ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = 'big_increments_test'
            AND rf.RDB$FIELD_NAME = 'id'
        SQL);

        $this->assertNotNull($column);
        $this->assertSame('id', $column->name);
        $this->assertNotNull($column->identity_type);

        // Firebird field type 16 = BIGINT
        $this->assertSame(16, $column->field_type);

        Schema::drop('big_increments_test');
    }

    #[Test]
    public function eloquent_receives_the_generated_identity()
    {
        Schema::dropIfExists('identity_model_test');

        Schema::create('identity_model_test', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        $model = IdentityTestModel::create([
            'name' => 'Firebird',
        ]);

        $this->assertNotNull($model->id);
        $this->assertIsInt($model->id);
        $this->assertGreaterThan(0, $model->id);

        $this->assertDatabaseHas('identity_model_test', [
            'id' => $model->id,
            'name' => 'Firebird',
        ]);

        Schema::drop('identity_model_test');
    }

    #[Test]
    #[DataProvider('incrementTypes')]
    public function it_creates_other_identity_integer_types(
        string $method,
        int $expectedFieldType
    ) {
        Schema::dropIfExists('increment_type_test');

        Schema::create('increment_type_test', function (Blueprint $table) use ($method) {
            $table->{$method}('id');
        });

        $column = DB::selectOne(<<<'SQL'
            SELECT
                rf.RDB$IDENTITY_TYPE AS "identity_type",
                f.RDB$FIELD_TYPE AS "field_type"
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f
            ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = 'increment_type_test'
            AND rf.RDB$FIELD_NAME = 'id'
        SQL);

        $this->assertNotNull($column);
        $this->assertNotNull($column->identity_type);
        $this->assertSame($expectedFieldType, $column->field_type);

        Schema::drop('increment_type_test');
    }

    #[Test]
    public function it_fails_explicitly_when_changing_a_blob_column_to_integer()
    {
        Schema::dropIfExists('change_incompatible_test');

        try {
            Schema::create('change_incompatible_test', function (Blueprint $table) {
                $table->id();
                $table->binary('value');
            });

            $metadataSql = <<<'SQL'
                SELECT f.RDB$FIELD_TYPE AS "field_type"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_incompatible_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            // Firebird field type 261 = BLOB.
            $this->assertSame(261, $column->field_type);

            try {
                Schema::table('change_incompatible_test', function (Blueprint $table) {
                    $table->integer('value')->change();
                });

                $this->fail('Changing a BLOB column to INTEGER should throw a QueryException.');
            } catch (QueryException $exception) {
                $this->assertInstanceOf(QueryException::class, $exception);
            }

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(261, $column->field_type);
        } finally {
            Schema::dropIfExists('change_incompatible_test');
        }
    }

    #[Test]
    public function it_changes_a_column_type()
    {
        Schema::dropIfExists('change_type_test');

        try {
            Schema::create('change_type_test', function (Blueprint $table) {
                $table->id();
                $table->integer('value');
            });

            $metadataSql = <<<'SQL'
                SELECT f.RDB$FIELD_TYPE AS "field_type"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_type_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(8, $column->field_type);

            Schema::table('change_type_test', function (Blueprint $table) {
                $table->bigInteger('value')->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(16, $column->field_type);
        } finally {
            Schema::dropIfExists('change_type_test');
        }
    }

    #[Test]
    public function it_changes_a_column_length()
    {
        Schema::dropIfExists('change_length_test');

        try {
            Schema::create('change_length_test', function (Blueprint $table) {
                $table->id();
                $table->string('value', 40);
            });

            $metadataSql = <<<'SQL'
                SELECT f.RDB$CHARACTER_LENGTH AS "character_length"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_length_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(40, $column->character_length);

            Schema::table('change_length_test', function (Blueprint $table) {
                $table->string('value', 100)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(100, $column->character_length);
        } finally {
            Schema::dropIfExists('change_length_test');
        }
    }

    #[Test]
    public function it_changes_a_column_length_without_removing_nullable()
    {
        Schema::dropIfExists('change_preserve_nullable_test');

        try {
            Schema::create('change_preserve_nullable_test', function (Blueprint $table) {
                $table->id();
                $table->string('value', 40)->nullable();
            });

            $metadataSql = <<<'SQL'
                SELECT f.RDB$CHARACTER_LENGTH AS "character_length",
                       COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_preserve_nullable_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(40, $column->character_length);
            $this->assertSame(0, $column->null_flag);

            Schema::table('change_preserve_nullable_test', function (Blueprint $table) {
                $table->string('value', 100)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(100, $column->character_length);
            $this->assertSame(0, $column->null_flag);
        } finally {
            Schema::dropIfExists('change_preserve_nullable_test');
        }
    }

    #[Test]
    public function it_changes_a_column_length_without_removing_its_default()
    {
        Schema::dropIfExists('change_preserve_default_test');

        try {
            Schema::create('change_preserve_default_test', function (Blueprint $table) {
                $table->id();
                $table->string('value', 40)->nullable()->default('original');
            });

            $metadataSql = <<<'SQL'
                SELECT f.RDB$CHARACTER_LENGTH AS "character_length",
                       TRIM(CAST(rf.RDB$DEFAULT_SOURCE AS VARCHAR(255))) AS "default_source"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_preserve_default_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(40, $column->character_length);
            $this->assertSame("DEFAULT 'original'", $column->default_source);

            Schema::table('change_preserve_default_test', function (Blueprint $table) {
                $table->string('value', 100)->nullable()->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(100, $column->character_length);
            $this->assertSame("DEFAULT 'original'", $column->default_source);
        } finally {
            Schema::dropIfExists('change_preserve_default_test');
        }
    }

    #[Test]
    public function it_changes_a_column_to_nullable()
    {
        Schema::dropIfExists('change_nullable_test');

        try {
            Schema::create('change_nullable_test', function (Blueprint $table) {
                $table->id();
                $table->string('value');
            });

            $metadataSql = <<<'SQL'
                SELECT COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_nullable_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(1, $column->null_flag);

            Schema::table('change_nullable_test', function (Blueprint $table) {
                $table->string('value')->nullable()->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(0, $column->null_flag);
        } finally {
            Schema::dropIfExists('change_nullable_test');
        }
    }

    #[Test]
    public function it_changes_a_column_to_not_nullable()
    {
        Schema::dropIfExists('change_not_nullable_test');

        try {
            Schema::create('change_not_nullable_test', function (Blueprint $table) {
                $table->id();
                $table->string('value')->nullable();
            });

            $metadataSql = <<<'SQL'
                SELECT COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_not_nullable_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(0, $column->null_flag);

            Schema::table('change_not_nullable_test', function (Blueprint $table) {
                $table->string('value')->nullable(false)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(1, $column->null_flag);
        } finally {
            Schema::dropIfExists('change_not_nullable_test');
        }
    }

    #[Test]
    public function it_changes_a_column_to_set_a_default()
    {
        Schema::dropIfExists('change_set_default_test');

        try {
            Schema::create('change_set_default_test', function (Blueprint $table) {
                $table->id();
                $table->integer('value')->nullable();
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(CAST(rf.RDB$DEFAULT_SOURCE AS VARCHAR(255))) AS "default_source"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_set_default_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(null, $column->default_source);

            Schema::table('change_set_default_test', function (Blueprint $table) {
                $table->integer('value')->nullable()->default(10)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame("DEFAULT '10'", $column->default_source);
        } finally {
            Schema::dropIfExists('change_set_default_test');
        }
    }

    #[Test]
    public function it_changes_a_column_to_update_a_default()
    {
        Schema::dropIfExists('change_update_default_test');

        try {
            Schema::create('change_update_default_test', function (Blueprint $table) {
                $table->id();
                $table->integer('value')->nullable()->default(10);
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(CAST(rf.RDB$DEFAULT_SOURCE AS VARCHAR(255))) AS "default_source"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_update_default_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame("DEFAULT '10'", $column->default_source);

            Schema::table('change_update_default_test', function (Blueprint $table) {
                $table->integer('value')->nullable()->default(20)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame("DEFAULT '20'", $column->default_source);
        } finally {
            Schema::dropIfExists('change_update_default_test');
        }
    }

    #[Test]
    public function it_changes_a_column_to_remove_a_default()
    {
        Schema::dropIfExists('change_remove_default_test');

        try {
            Schema::create('change_remove_default_test', function (Blueprint $table) {
                $table->id();
                $table->integer('value')->nullable()->default(10);
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(CAST(rf.RDB$DEFAULT_SOURCE AS VARCHAR(255))) AS "default_source"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_remove_default_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame("DEFAULT '10'", $column->default_source);

            Schema::table('change_remove_default_test', function (Blueprint $table) {
                $table->integer('value')->nullable()->default(null)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(null, $column->default_source);
        } finally {
            Schema::dropIfExists('change_remove_default_test');
        }
    }

    #[Test]
    public function it_drops_an_index_with_a_generated_name()
    {
        Schema::dropIfExists('drop_index_test');

        try {
            Schema::create('drop_index_test', function (Blueprint $table) {
                $table->id();
                $table->string('value');
                $table->index('value');
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(i.RDB$INDEX_NAME) AS "index_name"
                FROM RDB$INDICES i
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
                WHERE i.RDB$RELATION_NAME = 'drop_index_test'
                  AND i.RDB$INDEX_NAME = 'drop_index_test_value_index'
                  AND s.RDB$FIELD_NAME = 'value'
            SQL;

            $this->assertNotNull(DB::selectOne($metadataSql));

            Schema::table('drop_index_test', function (Blueprint $table) {
                $table->dropIndex(['value']);
            });

            $this->assertNull(DB::selectOne($metadataSql));
        } finally {
            Schema::dropIfExists('drop_index_test');
        }
    }

    #[Test]
    public function it_drops_an_index_with_an_explicit_name()
    {
        Schema::dropIfExists('drop_named_index_test');

        try {
            Schema::create('drop_named_index_test', function (Blueprint $table) {
                $table->id();
                $table->string('value');
                $table->index('value', 'explicit_value_index');
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(i.RDB$INDEX_NAME) AS "index_name"
                FROM RDB$INDICES i
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
                WHERE i.RDB$RELATION_NAME = 'drop_named_index_test'
                  AND i.RDB$INDEX_NAME = 'explicit_value_index'
                  AND s.RDB$FIELD_NAME = 'value'
            SQL;

            $this->assertNotNull(DB::selectOne($metadataSql));

            Schema::table('drop_named_index_test', function (Blueprint $table) {
                $table->dropIndex('explicit_value_index');
            });

            $this->assertNull(DB::selectOne($metadataSql));
        } finally {
            Schema::dropIfExists('drop_named_index_test');
        }
    }

    #[Test]
    public function it_drops_a_unique_constraint()
    {
        Schema::dropIfExists('drop_unique_test');

        try {
            Schema::create('drop_unique_test', function (Blueprint $table) {
                $table->id();
                $table->string('value');
                $table->unique('value');
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name"
                FROM RDB$RELATION_CONSTRAINTS rc
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
                WHERE rc.RDB$RELATION_NAME = 'drop_unique_test'
                  AND rc.RDB$CONSTRAINT_NAME = 'drop_unique_test_value_unique'
                  AND rc.RDB$CONSTRAINT_TYPE = 'UNIQUE'
                  AND s.RDB$FIELD_NAME = 'value'
            SQL;

            $this->assertNotNull(DB::selectOne($metadataSql));

            Schema::table('drop_unique_test', function (Blueprint $table) {
                $table->dropUnique(['value']);
            });

            $this->assertNull(DB::selectOne($metadataSql));
        } finally {
            Schema::dropIfExists('drop_unique_test');
        }
    }

    #[Test]
    public function it_drops_a_primary_key()
    {
        Schema::dropIfExists('drop_primary_test');

        try {
            Schema::create('drop_primary_test', function (Blueprint $table) {
                $table->integer('id');
                $table->primary('id');
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name"
                FROM RDB$RELATION_CONSTRAINTS rc
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
                WHERE rc.RDB$RELATION_NAME = 'drop_primary_test'
                  AND rc.RDB$CONSTRAINT_TYPE = 'PRIMARY KEY'
                  AND s.RDB$FIELD_NAME = 'id'
            SQL;

            $this->assertNotNull(DB::selectOne($metadataSql));

            Schema::table('drop_primary_test', function (Blueprint $table) {
                $table->dropPrimary();
            });

            $this->assertNull(DB::selectOne($metadataSql));
        } finally {
            Schema::dropIfExists('drop_primary_test');
        }
    }

    #[Test]
    public function it_drops_an_index_with_a_long_generated_name()
    {
        Schema::dropIfExists('drop_long_index_test');
        $generatedName = null;

        try {
            Schema::create('drop_long_index_test', function (Blueprint $table) use (&$generatedName) {
                $table->id();
                $table->string('long_identifier_value');
                $generatedName = $table->index('long_identifier_value')->index;
            });

            $this->assertGreaterThan(31, strlen($generatedName));

            $object = DB::selectOne(<<<'SQL'
                SELECT TRIM(o.RDB$INDEX_NAME) AS "object_name"
                FROM RDB$INDICES o
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = o.RDB$INDEX_NAME
                WHERE o.RDB$RELATION_NAME = 'drop_long_index_test'
                  AND s.RDB$FIELD_NAME = 'long_identifier_value'
            SQL);

            $this->assertNotNull($object);
            $this->assertSame($generatedName, $object->object_name);

            Schema::table('drop_long_index_test', function (Blueprint $table) {
                $table->dropIndex(['long_identifier_value']);
            });

            $this->assertNull(DB::selectOne(<<<'SQL'
                SELECT RDB$INDEX_NAME
                FROM RDB$INDICES
                WHERE RDB$RELATION_NAME = 'drop_long_index_test'
                  AND RDB$INDEX_NAME = ?
            SQL, [$object->object_name]));
        } finally {
            Schema::dropIfExists('drop_long_index_test');
        }
    }

    #[Test]
    public function it_drops_a_unique_constraint_with_a_long_generated_name()
    {
        Schema::dropIfExists('drop_long_unique_test');
        $generatedName = null;

        try {
            Schema::create('drop_long_unique_test', function (Blueprint $table) use (&$generatedName) {
                $table->id();
                $table->string('long_identifier_value');
                $generatedName = $table->unique('long_identifier_value')->index;
            });

            $this->assertGreaterThan(31, strlen($generatedName));

            $object = DB::selectOne(<<<'SQL'
                SELECT TRIM(o.RDB$CONSTRAINT_NAME) AS "object_name"
                FROM RDB$RELATION_CONSTRAINTS o
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = o.RDB$INDEX_NAME
                WHERE o.RDB$RELATION_NAME = 'drop_long_unique_test'
                  AND o.RDB$CONSTRAINT_TYPE = 'UNIQUE'
                  AND s.RDB$FIELD_NAME = 'long_identifier_value'
            SQL);

            $this->assertNotNull($object);
            $this->assertSame($generatedName, $object->object_name);

            Schema::table('drop_long_unique_test', function (Blueprint $table) {
                $table->dropUnique(['long_identifier_value']);
            });

            $this->assertNull(DB::selectOne(<<<'SQL'
                SELECT RDB$CONSTRAINT_NAME
                FROM RDB$RELATION_CONSTRAINTS
                WHERE RDB$RELATION_NAME = 'drop_long_unique_test'
                  AND RDB$CONSTRAINT_NAME = ?
            SQL, [$object->object_name]));
        } finally {
            Schema::dropIfExists('drop_long_unique_test');
        }
    }

    #[Test]
    public function it_introspects_a_computed_column()
    {
        Schema::dropIfExists('computed_column_test');

        try {
            DB::statement(<<<'SQL'
                CREATE TABLE "computed_column_test" (
                    "quantity" INTEGER NOT NULL,
                    "price" INTEGER NOT NULL,
                    "total" BIGINT COMPUTED BY ("quantity" * "price")
                )
            SQL);

            $columns = array_column(Schema::getColumns('computed_column_test'), null, 'name');
            $this->assertArrayHasKey('total', $columns);
            $column = $columns['total'];

            $this->assertSame('total', $column['name']);
            $this->assertSame('bigint', $column['type_name']);
            $this->assertSame('bigint', $column['type']);
            $this->assertTrue($column['nullable']);
            $this->assertFalse($column['auto_increment']);
            $this->assertNotNull($column['generation']);
            $this->assertIsArray($column['generation']);
            $this->assertSame('virtual', $column['generation']['type']);
            $this->assertIsString($column['generation']['expression']);
            $this->assertStringContainsString('"quantity" * "price"', $column['generation']['expression']);

            $metadata = DB::selectOne(<<<'SQL'
                SELECT f.RDB$COMPUTED_SOURCE AS "expression",
                       COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'computed_column_test'
                  AND rf.RDB$FIELD_NAME = 'total'
            SQL);

            $this->assertNotNull($metadata);
            $this->assertSame($metadata->expression, $column['generation']['expression']);
            $this->assertSame(0, $metadata->null_flag);
            $this->assertSame('bigint', Schema::getColumnType('computed_column_test', 'total'));
            $this->assertSame('bigint', Schema::getColumnType('computed_column_test', 'total', true));
        } finally {
            Schema::dropIfExists('computed_column_test');
        }
    }

    #[Test]
    public function it_introspects_column_metadata()
    {
        Schema::dropIfExists('column_introspection_test');

        try {
            $this->createColumnIntrospectionTable('column_introspection_test');

            $columns = Schema::getColumns('column_introspection_test');
            $this->assertSame(
                ['id', 'name', 'quantity', 'notes', 'status', 'body', 'recorded_at'],
                array_column($columns, 'name'),
            );

            foreach ($columns as $column) {
                $this->assertIsArray($column);
                foreach (['name', 'type_name', 'type', 'collation', 'nullable', 'default', 'auto_increment', 'comment', 'generation'] as $key) {
                    $this->assertArrayHasKey($key, $column, "Missing {$key} for {$column['name']}");
                }

                $this->assertIsString($column['type_name']);
                $this->assertNotSame('', $column['type_name']);
                $this->assertIsString($column['type']);
                $this->assertNotSame('', $column['type']);
                $this->assertSame(in_array($column['name'], ['notes', 'status'], true), $column['nullable']);
                $this->assertSame($column['name'] === 'id', $column['auto_increment']);
                // Identity is reported via auto_increment; none of these columns is computed.
                $this->assertNull($column['generation']);

                if ($column['name'] === 'status') {
                    $this->assertSame("'pending'", $column['default']);
                } else {
                    $this->assertNull($column['default']);
                }
            }

            $byName = array_column($columns, null, 'name');
            foreach (self::introspectionColumnTypes() as [$name, $type]) {
                $this->assertSame($type, strtolower($byName[$name]['type_name']));
            }
            $this->assertSame('varchar(40)', strtolower($byName['name']['type']));
        } finally {
            Schema::dropIfExists('column_introspection_test');
        }
    }

    #[Test]
    #[DataProvider('introspectionColumnTypes')]
    public function it_introspects_column_type(string $column, string $expectedType)
    {
        Schema::dropIfExists('column_type_test');

        try {
            $this->createColumnIntrospectionTable('column_type_test');

            $type = Schema::getColumnType('column_type_test', $column);
            $this->assertIsString($type);
            $this->assertSame($expectedType, strtolower($type));

            $fullType = Schema::getColumnType('column_type_test', $column, true);
            $this->assertIsString($fullType);
            $this->assertNotSame('', $fullType);
            if ($column === 'name') {
                $this->assertSame('varchar(40)', strtolower($fullType));
            }
        } finally {
            Schema::dropIfExists('column_type_test');
        }
    }

    public static function introspectionColumnTypes(): array
    {
        return [
            'identity' => ['id', 'bigint'],
            'string' => ['name', 'varchar'],
            'integer' => ['quantity', 'integer'],
            'nullable' => ['notes', 'varchar'],
            'default' => ['status', 'varchar'],
            'text blob' => ['body', 'blob'],
            'timestamp' => ['recorded_at', 'timestamp'],
        ];
    }

    private function createColumnIntrospectionTable(string $table): void
    {
        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->integer('quantity');
            $table->string('notes')->nullable();
            $table->string('status')->nullable()->default('pending');
            $table->text('body');
            $table->timestamp('recorded_at');
        });
    }

    #[Test]
    #[DataProvider('introspectionIndexKinds')]
    public function it_introspects_indexes(string $kind, array $expectedColumns, bool $unique, bool $primary)
    {
        Schema::dropIfExists('index_introspection_test');

        try {
            $this->createIndexIntrospectionTable('index_introspection_test', $kind);
            $metadata = $this->readIndexIntrospectionMetadata('index_introspection_test');
            $this->assertCount(count($expectedColumns), $metadata);
            $this->assertSame($expectedColumns, array_column($metadata, 'column_name'));

            $indexes = Schema::getIndexes('index_introspection_test');
            $this->assertCount(1, $indexes);
            $index = $indexes[0];
            $this->assertIsArray($index);
            foreach (['name', 'columns', 'type', 'unique', 'primary'] as $key) {
                $this->assertArrayHasKey($key, $index);
            }
            $this->assertSame($metadata[0]->index_name, $index['name']);
            $this->assertSame($expectedColumns, $index['columns']);
            $this->assertSame($unique, $index['unique']);
            $this->assertSame($primary, $index['primary']);
        } finally {
            Schema::dropIfExists('index_introspection_test');
        }
    }

    #[Test]
    #[DataProvider('introspectionIndexKinds')]
    public function it_introspects_index_existence(string $kind, array $expectedColumns, bool $unique, bool $primary)
    {
        Schema::dropIfExists('index_existence_test');

        try {
            $this->createIndexIntrospectionTable('index_existence_test', $kind);
            $metadata = $this->readIndexIntrospectionMetadata('index_existence_test');
            $this->assertNotEmpty($metadata);

            $this->assertTrue(Schema::hasIndex('index_existence_test', $metadata[0]->index_name));
            $this->assertTrue(Schema::hasIndex('index_existence_test', $expectedColumns));
            $this->assertSame($unique, Schema::hasIndex('index_existence_test', $expectedColumns, 'unique'));
            $this->assertSame($primary, Schema::hasIndex('index_existence_test', $expectedColumns, 'primary'));
            $this->assertFalse(Schema::hasIndex('index_existence_test', 'nonexistent_index'));
            $this->assertFalse(Schema::hasIndex('index_existence_test', ['nonexistent_column']));
            if (count($expectedColumns) > 1) {
                $this->assertFalse(Schema::hasIndex('index_existence_test', array_reverse($expectedColumns)));
            }
        } finally {
            Schema::dropIfExists('index_existence_test');
        }
    }

    public static function introspectionIndexKinds(): array
    {
        return [
            'single column' => ['single', ['name'], false, false],
            'composite' => ['composite', ['code', 'name'], false, false],
            'unique' => ['unique', ['code'], true, false],
            'primary key' => ['primary', ['id'], true, true],
        ];
    }

    private function createIndexIntrospectionTable(string $table, string $kind): void
    {
        Schema::create($table, function (Blueprint $table) use ($kind) {
            $table->integer('id');
            $table->string('name');
            $table->string('code');

            match ($kind) {
                'single' => $table->index('name', 'introspect_single_idx'),
                'composite' => $table->index(['code', 'name'], 'introspect_composite_idx'),
                'unique' => $table->unique('code', 'introspect_unique'),
                'primary' => $table->primary('id'),
            };
        });
    }

    private function readIndexIntrospectionMetadata(string $table): array
    {
        return DB::select(<<<'SQL'
            SELECT TRIM(i.RDB$INDEX_NAME) AS "index_name",
                   TRIM(s.RDB$FIELD_NAME) AS "column_name",
                   s.RDB$FIELD_POSITION AS "position",
                   i.RDB$UNIQUE_FLAG AS "unique_flag",
                   i.RDB$INDEX_TYPE AS "index_type",
                   TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name",
                   TRIM(rc.RDB$CONSTRAINT_TYPE) AS "constraint_type"
            FROM RDB$INDICES i
            JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
            LEFT JOIN RDB$RELATION_CONSTRAINTS rc
                ON rc.RDB$INDEX_NAME = i.RDB$INDEX_NAME
                AND rc.RDB$RELATION_NAME = i.RDB$RELATION_NAME
            WHERE i.RDB$RELATION_NAME = ?
            ORDER BY i.RDB$INDEX_NAME, s.RDB$FIELD_POSITION
        SQL, [$table]);
    }

    #[Test]
    #[DataProvider('introspectionForeignKeyKinds')]
    public function it_introspects_foreign_keys(string $kind, array $columns, array $foreignColumns)
    {
        try {
            $this->createForeignKeyIntrospectionTables($kind);
            $metadata = $this->readForeignKeyIntrospectionMetadata();
            $this->assertCount(count($columns), $metadata);
            $this->assertSame($columns, array_column($metadata, 'column_name'));
            $this->assertSame($foreignColumns, array_column($metadata, 'foreign_column'));

            $foreignKeys = Schema::getForeignKeys('fk_intro_child');
            $this->assertCount(1, $foreignKeys);
            $foreignKey = $foreignKeys[0];
            $this->assertIsArray($foreignKey);
            foreach (['name', 'columns', 'foreign_schema', 'foreign_table', 'foreign_columns', 'on_update', 'on_delete'] as $key) {
                $this->assertArrayHasKey($key, $foreignKey);
            }
            $this->assertSame($metadata[0]->constraint_name, $foreignKey['name']);
            $this->assertSame($columns, $foreignKey['columns']);
            $this->assertNull($foreignKey['foreign_schema']);
            $this->assertSame('fk_intro_parent', $foreignKey['foreign_table']);
            $this->assertSame($foreignColumns, $foreignKey['foreign_columns']);
            $this->assertSame(strtolower($metadata[0]->update_rule), $foreignKey['on_update']);
            $this->assertSame(strtolower($metadata[0]->delete_rule), $foreignKey['on_delete']);
            if ($kind === 'delete_cascade') {
                $this->assertSame('cascade', $foreignKey['on_delete']);
            }
            if ($kind === 'update_cascade') {
                $this->assertSame('cascade', $foreignKey['on_update']);
            }
        } finally {
            Schema::dropIfExists('fk_intro_child');
            Schema::dropIfExists('fk_intro_parent');
        }
    }

    #[Test]
    #[DataProvider('introspectionForeignKeyKinds')]
    public function it_introspects_foreign_key_existence(string $kind, array $columns, array $foreignColumns)
    {
        if (! method_exists(Schema::getFacadeRoot(), 'hasForeignKey')) {
            $this->markTestSkipped('This Laravel version does not support Schema::hasForeignKey().');
        }

        try {
            $this->createForeignKeyIntrospectionTables($kind);
            $metadata = $this->readForeignKeyIntrospectionMetadata();
            $this->assertNotEmpty($metadata);

            $this->assertTrue(Schema::hasForeignKey('fk_intro_child', $metadata[0]->constraint_name));
            $this->assertTrue(Schema::hasForeignKey('fk_intro_child', $columns));
            $this->assertFalse(Schema::hasForeignKey('fk_intro_child', 'nonexistent_foreign_key'));
            $this->assertFalse(Schema::hasForeignKey('fk_intro_child', ['nonexistent_column']));
            if (count($columns) > 1) {
                $this->assertFalse(Schema::hasForeignKey('fk_intro_child', array_reverse($columns)));
            }
        } finally {
            Schema::dropIfExists('fk_intro_child');
            Schema::dropIfExists('fk_intro_parent');
        }
    }

    public static function introspectionForeignKeyKinds(): array
    {
        return [
            'simple' => ['simple', ['ref_a'], ['key_a']],
            'delete cascade' => ['delete_cascade', ['ref_a'], ['key_a']],
            'update cascade' => ['update_cascade', ['ref_a'], ['key_a']],
            'composite' => ['composite', ['ref_b', 'ref_a'], ['key_b', 'key_a']],
        ];
    }

    private function createForeignKeyIntrospectionTables(string $kind): void
    {
        Schema::dropIfExists('fk_intro_child');
        Schema::dropIfExists('fk_intro_parent');

        Schema::create('fk_intro_parent', function (Blueprint $table) use ($kind) {
            $table->integer('key_a');
            $table->integer('key_b');
            $table->primary($kind === 'composite' ? ['key_b', 'key_a'] : ['key_a']);
        });
        Schema::create('fk_intro_child', function (Blueprint $table) use ($kind) {
            $table->integer('ref_a');
            $table->integer('ref_b');
            $foreignKey = $table->foreign(
                $kind === 'composite' ? ['ref_b', 'ref_a'] : ['ref_a'],
                'fk_intro_reference',
            )->references($kind === 'composite' ? ['key_b', 'key_a'] : ['key_a'])
                ->on('fk_intro_parent');

            if ($kind === 'delete_cascade') {
                $foreignKey->onDelete('cascade');
            }
            if ($kind === 'update_cascade') {
                $foreignKey->onUpdate('cascade');
            }
        });
    }

    private function readForeignKeyIntrospectionMetadata(): array
    {
        return DB::select(<<<'SQL'
            SELECT TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name",
                   TRIM(rc.RDB$CONSTRAINT_TYPE) AS "constraint_type",
                   TRIM(i.RDB$INDEX_NAME) AS "index_name",
                   TRIM(i.RDB$FOREIGN_KEY) AS "referenced_index",
                   TRIM(s.RDB$FIELD_NAME) AS "column_name",
                   s.RDB$FIELD_POSITION AS "position",
                   TRIM(parent.RDB$CONSTRAINT_NAME) AS "referenced_constraint",
                   TRIM(parent.RDB$RELATION_NAME) AS "foreign_table",
                   TRIM(fs.RDB$FIELD_NAME) AS "foreign_column",
                   fs.RDB$FIELD_POSITION AS "foreign_position",
                   TRIM(ref.RDB$UPDATE_RULE) AS "update_rule",
                   TRIM(ref.RDB$DELETE_RULE) AS "delete_rule"
            FROM RDB$RELATION_CONSTRAINTS rc
            JOIN RDB$REF_CONSTRAINTS ref ON ref.RDB$CONSTRAINT_NAME = rc.RDB$CONSTRAINT_NAME
            JOIN RDB$INDICES i ON i.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
            JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
            JOIN RDB$RELATION_CONSTRAINTS parent ON parent.RDB$CONSTRAINT_NAME = ref.RDB$CONST_NAME_UQ
            JOIN RDB$INDEX_SEGMENTS fs
                ON fs.RDB$INDEX_NAME = parent.RDB$INDEX_NAME
                AND fs.RDB$FIELD_POSITION = s.RDB$FIELD_POSITION
            WHERE rc.RDB$RELATION_NAME = 'fk_intro_child'
              AND rc.RDB$CONSTRAINT_TYPE = 'FOREIGN KEY'
            ORDER BY rc.RDB$CONSTRAINT_NAME, s.RDB$FIELD_POSITION
        SQL);
    }

    #[Test]
    #[DataProvider('timeZoneColumnDefinitions')]
    public function it_supports_time_zone_column_metadata(string $method, int $fieldType, string $type, bool $nullable, ?int $precision)
    {
        Schema::dropIfExists('tz_column_test');

        try {
            Schema::create('tz_column_test', function (Blueprint $table) use ($method, $nullable, $precision) {
                $table->{$method}('value', $precision)->nullable($nullable);
            });

            $metadata = $this->readTimeZoneColumnMetadata('tz_column_test');
            $this->assertCount(1, $metadata);
            $this->assertSame($nullable ? 0 : 1, $metadata[0]->null_flag);
            $this->assertSame($fieldType, $metadata[0]->field_type);

            $columns = Schema::getColumns('tz_column_test');
            $this->assertCount(1, $columns);
            $this->assertSame('value', $columns[0]['name']);
            $this->assertSame($type, $columns[0]['type_name']);
            $this->assertSame($type, $columns[0]['type']);
            $this->assertSame($nullable, $columns[0]['nullable']);
            $this->assertSame($type, Schema::getColumnType('tz_column_test', 'value'));
            $this->assertSame($type, Schema::getColumnType('tz_column_test', 'value', true));
        } finally {
            Schema::dropIfExists('tz_column_test');
        }
    }

    #[Test]
    #[DataProvider('timeZonePrecisions')]
    public function it_supports_time_zone_timestamps(?int $precision)
    {
        Schema::dropIfExists('tz_timestamps_test');

        try {
            Schema::create('tz_timestamps_test', function (Blueprint $table) use ($precision) {
                $table->timestampsTz($precision);
            });

            $metadata = $this->readTimeZoneColumnMetadata('tz_timestamps_test');
            $this->assertSame(['created_at', 'updated_at'], array_column($metadata, 'name'));
            foreach ($metadata as $column) {
                $this->assertSame(29, $column->field_type);
                $this->assertSame(0, $column->null_flag);
            }
            foreach (Schema::getColumns('tz_timestamps_test') as $column) {
                $this->assertTrue($column['nullable']);
                $this->assertSame('timestamp with time zone', $column['type_name']);
                $this->assertSame('timestamp with time zone', $column['type']);
                $this->assertSame('timestamp with time zone', Schema::getColumnType('tz_timestamps_test', $column['name']));
                $this->assertSame('timestamp with time zone', Schema::getColumnType('tz_timestamps_test', $column['name'], true));
            }
        } finally {
            Schema::dropIfExists('tz_timestamps_test');
        }
    }

    #[Test]
    #[DataProvider('timeZoneRoundTripValues')]
    public function it_supports_time_zone_query_builder_round_trip(string $method, string $sqlType, string $input, string $utcEquivalent)
    {
        Schema::dropIfExists('tz_round_trip_test');

        try {
            Schema::create('tz_round_trip_test', function (Blueprint $table) use ($method) {
                $table->integer('id');
                $table->{$method}('value', 4);
            });

            DB::table('tz_round_trip_test')->insert(['id' => 1, 'value' => $input]);
            $row = DB::table('tz_round_trip_test')->where('id', 1)->first();
            $this->assertNotNull($row);
            $this->assertNotNull($row->value);

            // Compare UTC semantics in Firebird, without requiring identical offset text from PDO.
            $comparison = DB::table('tz_round_trip_test')->where('id', 1)
                ->selectRaw('CASE WHEN "value" = CAST(? AS '.$sqlType.') THEN 1 ELSE 0 END AS "same_value"', [$utcEquivalent])
                ->selectRaw('EXTRACT(SECOND FROM "value") AS "seconds"')
                ->first();
            $this->assertSame(1, $comparison->same_value);
            // Firebird stores fractions at a fixed resolution of four decimal places.
            $this->assertEqualsWithDelta(56.1234, (float) $comparison->seconds, 0.00001);
            $this->assertSame(strtolower($sqlType), Schema::getColumnType('tz_round_trip_test', 'value'));
        } finally {
            Schema::dropIfExists('tz_round_trip_test');
        }
    }

    public static function timeZonePrecisions(): array
    {
        return ['default precision' => [null], 'four fractional digits' => [4]];
    }

    public static function timeZoneColumnDefinitions(): array
    {
        $cases = [];
        foreach (['timeTz' => [28, 'time with time zone'], 'timestampTz' => [29, 'timestamp with time zone'], 'dateTimeTz' => [29, 'timestamp with time zone']] as $method => [$fieldType, $type]) {
            foreach ([false, true] as $nullable) {
                foreach ([null, 4] as $precision) {
                    $label = $method.($nullable ? ' nullable' : ' not null').' precision '.($precision ?? 'default');
                    $cases[$label] = [$method, $fieldType, $type, $nullable, $precision];
                }
            }
        }

        return $cases;
    }

    public static function timeZoneRoundTripValues(): array
    {
        return [
            'timeTz' => ['timeTz', 'TIME WITH TIME ZONE', '12:34:56.1234 +02:00', '10:34:56.1234 +00:00'],
            'timestampTz' => ['timestampTz', 'TIMESTAMP WITH TIME ZONE', '2026-01-15 12:34:56.1234 +02:00', '2026-01-15 10:34:56.1234 +00:00'],
            'dateTimeTz' => ['dateTimeTz', 'TIMESTAMP WITH TIME ZONE', '2026-01-15 12:34:56.1234 +02:00', '2026-01-15 10:34:56.1234 +00:00'],
        ];
    }

    private function readTimeZoneColumnMetadata(string $table): array
    {
        return DB::select(<<<'SQL'
            SELECT TRIM(rf.RDB$FIELD_NAME) AS "name",
                   f.RDB$FIELD_TYPE AS "field_type",
                   f.RDB$FIELD_SUB_TYPE AS "field_sub_type",
                   f.RDB$FIELD_PRECISION AS "field_precision",
                   f.RDB$FIELD_SCALE AS "field_scale",
                   f.RDB$FIELD_LENGTH AS "field_length",
                   COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag"
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = ?
            ORDER BY rf.RDB$FIELD_POSITION
        SQL, [$table]);
    }

    #[Test]
    public function it_uses_native_boolean_metadata()
    {
        Schema::dropIfExists('native_boolean_test');

        try {
            Schema::create('native_boolean_test', function (Blueprint $table) {
                $table->id();
                $table->boolean('flag');
            });

            $metadata = $this->readBooleanMetadata('native_boolean_test');
            $this->assertNotNull($metadata);
            $this->assertSame(23, $metadata->field_type);

            $columns = array_column(Schema::getColumns('native_boolean_test'), null, 'name');
            $this->assertSame('boolean', $columns['flag']['type_name']);
            $this->assertSame('boolean', $columns['flag']['type']);
            $this->assertFalse($columns['flag']['nullable']);
            $this->assertSame('boolean', Schema::getColumnType('native_boolean_test', 'flag'));
            $this->assertSame('boolean', Schema::getColumnType('native_boolean_test', 'flag', true));
        } finally {
            Schema::dropIfExists('native_boolean_test');
        }
    }

    #[Test]
    #[DataProvider('nativeBooleanDefaults')]
    public function it_uses_native_boolean_defaults(bool $default)
    {
        Schema::dropIfExists('native_bool_default_test');

        try {
            Schema::create('native_bool_default_test', function (Blueprint $table) use ($default) {
                $table->integer('id');
                $table->boolean('flag')->default($default);
            });

            DB::table('native_bool_default_test')->insert(['id' => 1]);
            $this->assertSame($default, DB::table('native_bool_default_test')->where('id', 1)->value('flag'));
            $metadata = $this->readBooleanMetadata('native_bool_default_test');
            $this->assertNotNull($metadata);
            $this->assertSame(23, $metadata->field_type);
            $this->assertNotNull($metadata->default_source);
        } finally {
            Schema::dropIfExists('native_bool_default_test');
        }
    }

    #[Test]
    public function it_uses_native_boolean_nullable()
    {
        Schema::dropIfExists('native_bool_nullable_test');

        try {
            Schema::create('native_bool_nullable_test', function (Blueprint $table) {
                $table->integer('id');
                $table->boolean('flag')->nullable();
            });

            DB::table('native_bool_nullable_test')->insert(['id' => 1, 'flag' => null]);
            $this->assertNull(DB::table('native_bool_nullable_test')->where('id', 1)->value('flag'));
            $columns = array_column(Schema::getColumns('native_bool_nullable_test'), null, 'name');
            $this->assertTrue($columns['flag']['nullable']);
            $this->assertSame('boolean', $columns['flag']['type_name']);
            $this->assertSame(23, $this->readBooleanMetadata('native_bool_nullable_test')->field_type);
        } finally {
            Schema::dropIfExists('native_bool_nullable_test');
        }
    }

    #[Test]
    #[DataProvider('nativeBooleanInputs')]
    public function it_uses_native_boolean_query_builder_round_trip(bool|int $input, bool $expected)
    {
        Schema::dropIfExists('native_bool_query_test');

        try {
            Schema::create('native_bool_query_test', function (Blueprint $table) {
                $table->integer('id');
                $table->boolean('flag');
            });

            DB::table('native_bool_query_test')->insert(['id' => 1, 'flag' => $input]);
            $value = DB::table('native_bool_query_test')->where('id', 1)->value('flag');
            $this->assertSame($expected, $value);
        } finally {
            Schema::dropIfExists('native_bool_query_test');
        }
    }

    #[Test]
    #[DataProvider('nativeBooleanInputs')]
    public function it_uses_native_boolean_eloquent_round_trip(bool|int $input, bool $expected)
    {
        Schema::dropIfExists('native_bool_model_test');

        try {
            Schema::create('native_bool_model_test', function (Blueprint $table) {
                $table->id();
                $table->boolean('flag');
            });

            $model = NativeBooleanTestModel::create(['flag' => $input])->fresh();
            $this->assertNotNull($model);
            $this->assertSame($expected, $model->flag);
            $this->assertSame($expected, $model->getRawOriginal('flag'));
        } finally {
            Schema::dropIfExists('native_bool_model_test');
        }
    }

    public static function nativeBooleanDefaults(): array
    {
        return ['true' => [true], 'false' => [false]];
    }

    public static function nativeBooleanInputs(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            'one' => [1, true],
            'zero' => [0, false],
        ];
    }

    private function readBooleanMetadata(string $table): ?object
    {
        return DB::selectOne(<<<'SQL'
            SELECT TRIM(rf.RDB$FIELD_NAME) AS "name",
                   f.RDB$FIELD_TYPE AS "field_type",
                   f.RDB$FIELD_SUB_TYPE AS "field_sub_type",
                   f.RDB$FIELD_LENGTH AS "field_length",
                   f.RDB$CHARACTER_LENGTH AS "character_length",
                   COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag",
                   rf.RDB$DEFAULT_SOURCE AS "default_source"
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = ? AND rf.RDB$FIELD_NAME = 'flag'
        SQL, [$table]);
    }

    public static function incrementTypes(): array
    {
        return [
            'tiny increments' => ['tinyIncrements', 7],
            'small increments' => ['smallIncrements', 7],
            'medium increments' => ['mediumIncrements', 8],
        ];
    }
}

class IdentityTestModel extends Model
{
    protected $table = 'identity_model_test';

    public $timestamps = false;

    protected $fillable = ['name'];
}

class NativeBooleanTestModel extends Model
{
    protected $table = 'native_bool_model_test';

    public $timestamps = false;

    protected $fillable = ['flag'];

    protected $casts = ['flag' => 'boolean'];
}
