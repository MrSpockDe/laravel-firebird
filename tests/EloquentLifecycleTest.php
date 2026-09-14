<?php

namespace HarryGulliford\Firebird\Tests;

use HarryGulliford\Firebird\Tests\Support\MigrateDatabase;
use HarryGulliford\Firebird\Tests\Support\Models\Order;
use HarryGulliford\Firebird\Tests\Support\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class EloquentLifecycleTest extends TestCase
{
    use MigrateDatabase;

    #[Test]
    public function it_updates_and_deletes_an_existing_model_with_deterministic_timestamps(): void
    {
        $previousTime = Date::getTestNow();
        try {
            Date::setTestNow('2026-01-15 12:00:00');
            $user = LifecyclePlainUser::create(['name' => 'Original', 'email' => 'original@example.test']);
            Date::setTestNow('2026-01-15 12:05:00');
            $user->name = 'Updated';
            $this->assertTrue($user->save());
            $persisted = LifecyclePlainUser::findOrFail($user->id);
            $this->assertSame('Updated', $persisted->name);
            $this->assertSame('original@example.test', $persisted->email);
            $this->assertSame('2026-01-15 12:00:00', $persisted->created_at->toDateTimeString());
            $this->assertSame('2026-01-15 12:05:00', $persisted->updated_at->toDateTimeString());
            $this->assertTrue($user->delete());
            $this->assertFalse($user->exists);
            $this->assertFalse(DB::table('users')->where('id', $user->id)->exists());
        } finally {
            Date::setTestNow($previousTime);
        }
    }

    #[Test]
    public function it_refreshes_attributes_from_another_model_instance(): void
    {
        $user = $this->user('Original');
        $other = LifecycleUser::findOrFail($user->id);
        $other->name = 'Changed elsewhere';
        $other->save();
        $fresh = $user->fresh();
        $this->assertNotSame($user, $fresh);
        $this->assertTrue($user->is($fresh));
        $this->assertSame('Original', $user->name);
        $this->assertSame('Changed elsewhere', $fresh->name);
        $this->assertSame($user, $user->refresh());
        $this->assertSame($fresh->getAttributes(), $user->getAttributes());
        $this->assertFalse($user->isDirty());
    }

    #[Test]
    public function it_loads_standard_relations_lazily_and_with_nested_eager_loading(): void
    {
        $user = $this->user('Owner');
        $other = $this->user('Other');
        $first = $user->orders()->create(['name' => 'First', 'price' => 10, 'quantity' => 1]);
        $second = $user->orders()->create(['name' => 'Second', 'price' => 20, 'quantity' => 2]);
        $other->orders()->create(['name' => 'Unrelated', 'price' => 30, 'quantity' => 1]);

        $lazy = LifecycleUser::findOrFail($user->id);
        $this->assertFalse($lazy->relationLoaded('orders'));
        $this->assertSame([$first->id, $second->id], $lazy->orders->modelKeys());
        $this->assertTrue($lazy->relationLoaded('orders'));
        $this->assertSame($first->id, $lazy->firstOrder->id);
        $this->assertTrue($lazy->relationLoaded('firstOrder'));
        $order = Order::findOrFail($second->id);
        $this->assertFalse($order->relationLoaded('user'));
        $this->assertTrue($order->user->is($user));
        $this->assertTrue($order->relationLoaded('user'));

        $eager = LifecycleUser::with(['firstOrder', 'orders.user'])->findOrFail($user->id);
        $this->assertTrue($eager->relationLoaded('firstOrder'));
        $this->assertSame($first->id, $eager->firstOrder->id);
        $this->assertTrue($eager->relationLoaded('orders'));
        $this->assertSame([$first->id, $second->id], $eager->orders->modelKeys());
        foreach ($eager->orders as $related) {
            $this->assertInstanceOf(Order::class, $related);
            $this->assertTrue($related->relationLoaded('user'));
            $this->assertTrue($related->user->is($user));
        }
    }

    #[Test]
    public function it_detaches_and_syncs_pivots_with_timestamps(): void
    {
        $previousTime = Date::getTestNow();
        try {
            Schema::create('elo_owners', function (Blueprint $table) {
                $table->id();
                $table->string('name');
            });
            Schema::create('elo_labels', function (Blueprint $table) {
                $table->id();
                $table->string('name');
            });
            Schema::create('elo_user_label', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('label_id');
                $table->string('note');
                $table->timestamps();
                $table->primary(['user_id', 'label_id']);
                $table->foreign('user_id')->references('id')->on('elo_owners');
                $table->foreign('label_id')->references('id')->on('elo_labels');
            });
            $user = LifecyclePivotOwner::create(['name' => 'Pivot owner']);
            $ids = [];
            foreach (['One', 'Two', 'Three'] as $name) {
                $ids[] = DB::table('elo_labels')->insertGetId(['name' => $name]);
            }
            $initialTime = '2026-01-15 12:00:00';
            // attach() has separate coverage; seed only the starting pivot state here.
            foreach (array_slice($ids, 0, 2) as $id) {
                DB::table('elo_user_label')->insert(['user_id' => $user->id, 'label_id' => $id,
                    'note' => 'initial', 'created_at' => $initialTime, 'updated_at' => $initialTime]);
            }
            $this->assertSame(1, $user->labels()->detach($ids[0]));
            $this->assertSame([$ids[1]], DB::table('elo_user_label')->pluck('label_id')->all());
            Date::setTestNow('2026-01-15 12:05:00');
            $result = $user->labels()->sync([
                $ids[1] => ['note' => 'retained'], $ids[2] => ['note' => 'added'],
            ]);
            $this->assertSame([$ids[2]], $result['attached']);
            $this->assertSame([$ids[1]], $result['updated']);
            $this->assertSame([], $result['detached']);
            $labels = $user->labels()->orderBy('elo_labels.id')->get();
            $this->assertSame([$ids[1], $ids[2]], $labels->modelKeys());
            $this->assertSame('retained', $labels[0]->pivot->note);
            $this->assertSame($initialTime, $labels[0]->pivot->created_at->toDateTimeString());
            $this->assertSame('2026-01-15 12:05:00', $labels[0]->pivot->updated_at->toDateTimeString());
            $this->assertSame('added', $labels[1]->pivot->note);
            $this->assertSame('2026-01-15 12:05:00', $labels[1]->pivot->created_at->toDateTimeString());
            $this->assertSame('2026-01-15 12:05:00', $labels[1]->pivot->updated_at->toDateTimeString());
            $result = $user->labels()->sync([$ids[2]]);
            $this->assertSame([$ids[1]], $result['detached']);
            $this->assertSame([$ids[2]], DB::table('elo_user_label')->pluck('label_id')->all());
            $this->assertSame(3, DB::table('elo_labels')->count());
        } finally {
            Date::setTestNow($previousTime);
            Schema::dropIfExists('elo_user_label');
            Schema::dropIfExists('elo_labels');
            Schema::dropIfExists('elo_owners');
        }
    }

    #[Test]
    public function it_soft_deletes_restores_and_force_deletes_a_model(): void
    {
        $user = $this->user('Soft deleted');
        $active = $this->user('Active');
        $this->assertTrue($user->delete());
        $this->assertTrue($user->trashed());
        $this->assertNull(LifecycleUser::find($user->id));
        $this->assertSame([$active->id], LifecycleUser::orderBy('id')->pluck('id')->all());
        $this->assertSame([$user->id, $active->id], LifecycleUser::withTrashed()->orderBy('id')->pluck('id')->all());
        $trashed = LifecycleUser::onlyTrashed()->sole();
        $this->assertTrue($trashed->is($user));
        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('deleted_at'));
        $this->assertTrue($trashed->restore());
        $this->assertFalse($trashed->trashed());
        $this->assertTrue(LifecycleUser::findOrFail($user->id)->is($user));
        $this->assertSame(0, LifecycleUser::onlyTrashed()->count());
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('deleted_at'));
        $trashed->delete();
        $this->assertTrue($trashed->forceDelete());
        $this->assertNull(LifecycleUser::withTrashed()->find($user->id));
        $this->assertFalse(DB::table('users')->where('id', $user->id)->exists());
        $this->assertSame(1, LifecycleUser::count());
    }

    #[Test]
    #[DataProvider('iterationMethods')]
    public function it_hydrates_models_through_iteration_and_pagination(string $method): void
    {
        $expected = [];
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $name) {
            $user = $this->user($name);
            $expected[$user->id] = $name;
        }
        $query = LifecycleUser::orderBy('id');
        $models = [];
        if ($method === 'paginate') {
            $page = $query->paginate(2, ['*'], 'page', 2);
            $this->assertSame(5, $page->total());
            $this->assertSame(2, $page->currentPage());
            $this->assertSame(3, $page->lastPage());
            $models = $page->items();
            $expected = array_slice($expected, 2, 2, true);
        } elseif ($method === 'chunk') {
            $sizes = [];
            $this->assertTrue($query->chunk(2, function ($chunk) use (&$models, &$sizes) {
                $sizes[] = $chunk->count();
                foreach ($chunk as $model) {
                    $models[] = $model;
                }
            }));
            $this->assertSame([2, 2, 1], $sizes);
        } else {
            foreach ($method === 'lazy' ? $query->lazy(2) : $query->cursor() as $model) {
                $models[] = $model;
            }
        }
        $actual = [];
        foreach ($models as $model) {
            $this->assertInstanceOf(LifecycleUser::class, $model);
            $this->assertTrue($model->exists);
            $actual[$model->id] = $model->name;
        }
        $this->assertCount(count($expected), $models);
        $this->assertSame($expected, $actual);
    }

    public static function iterationMethods(): iterable
    {
        foreach (['paginate', 'chunk', 'lazy', 'cursor'] as $method) {
            yield $method => [$method];
        }
    }

    private function user(string $name): LifecycleUser
    {
        return LifecycleUser::create(['name' => $name, 'email' => str_replace(' ', '-', strtolower($name)).'@example.test']);
    }
}

class LifecyclePlainUser extends Model
{
    protected $table = 'users';
    protected $guarded = [];
}

class LifecycleUser extends User
{
    protected $table = 'users';

    public function orders()
    {
        return $this->hasMany(Order::class, 'user_id')->orderBy('id');
    }

    public function firstOrder()
    {
        return $this->hasOne(Order::class, 'user_id')->orderBy('id');
    }
}

class LifecyclePivotOwner extends Model
{
    protected $table = 'elo_owners';
    protected $guarded = [];
    public $timestamps = false;

    public function labels()
    {
        return $this->belongsToMany(LifecycleLabel::class, 'elo_user_label', 'user_id', 'label_id')
            ->withPivot('note')->withTimestamps();
    }
}

class LifecycleLabel extends Model
{
    protected $table = 'elo_labels';
    public $timestamps = false;
}
