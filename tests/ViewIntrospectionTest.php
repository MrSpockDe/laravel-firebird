<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class ViewIntrospectionTest extends TestCase
{
    #[Test]
    public function it_returns_no_views_for_an_ordinary_table(): void
    {
        try {
            Schema::create('view_base', fn (Blueprint $table) => $table->integer('value'));

            $this->assertSame([], Schema::getViews());
            $this->assertFalse(Schema::hasView('view_base'));
            $this->assertFalse(Schema::hasView('missing_view'));
        } finally {
            Schema::dropIfExists('view_base');
        }
    }

    #[Test]
    public function it_returns_a_view_definition_without_rewriting_it(): void
    {
        $created = false;
        $definition = 'SELECT "value" FROM "view_base" WHERE "value" > 10';
        try {
            Schema::create('view_base', fn (Blueprint $table) => $table->integer('value'));
            // Laravel has no portable CREATE VIEW API.
            DB::statement('CREATE VIEW "view_example" AS '.$definition);
            $created = true;

            $this->assertSame([[
                'name' => 'view_example',
                'schema' => null,
                'schema_qualified_name' => 'view_example',
                'definition' => $definition,
            ]], Schema::getViews());
            $this->assertTrue(Schema::hasView('view_example'));
            $this->assertFalse(Schema::hasView('missing_view'));
            $this->assertFalse(Schema::hasView('view_base'));
        } finally {
            if ($created) {
                DB::statement('DROP VIEW "view_example"');
            }
            Schema::dropIfExists('view_base');
        }
    }

    #[Test]
    public function it_preserves_view_identifier_case_and_lists_multiple_views(): void
    {
        $created = [];
        try {
            foreach (['"View_Mixed"', 'view_unquoted'] as $identifier) {
                DB::statement('CREATE VIEW '.$identifier.' AS SELECT 1 AS "value" FROM RDB$DATABASE');
                $created[] = $identifier;
            }

            $views = Schema::getViews();
            $this->assertCount(2, $views);
            $this->assertEqualsCanonicalizing(['View_Mixed', 'VIEW_UNQUOTED'], array_column($views, 'name'));
            foreach ($views as $view) {
                $this->assertNull($view['schema']);
                $this->assertSame($view['name'], $view['schema_qualified_name']);
                $this->assertSame('SELECT 1 AS "value" FROM RDB$DATABASE', $view['definition']);
            }
            $this->assertTrue(Schema::hasView('View_Mixed'));
            // Laravel's inherited hasView() compares names case-insensitively.
            $this->assertTrue(Schema::hasView('view_mixed'));
            $this->assertTrue(Schema::hasView('view_unquoted'));
            $this->assertFalse(Schema::hasView('missing_view'));
        } finally {
            foreach (array_reverse($created) as $identifier) {
                DB::statement('DROP VIEW '.$identifier);
            }
        }
    }
}
