# Firebird for Laravel — independent mrspockde package

[![Latest Version](https://poser.pugx.org/mrspockde/laravel-firebird/v)](https://packagist.org/packages/mrspockde/laravel-firebird)
[![Total Downloads](https://poser.pugx.org/mrspockde/laravel-firebird/downloads)](https://packagist.org/packages/mrspockde/laravel-firebird)
[![Tests](https://github.com/MrSpockDe/laravel-firebird/actions/workflows/tests.yml/badge.svg)](https://github.com/MrSpockDe/laravel-firebird/actions/workflows/tests.yml)
[![License](https://poser.pugx.org/mrspockde/laravel-firebird/license)](https://packagist.org/packages/mrspockde/laravel-firebird)

`mrspockde/laravel-firebird` is an independently maintained Composer package
in [MrSpockDe/laravel-firebird](https://github.com/MrSpockDe/laravel-firebird).
It adds Firebird PDO support, schema operations and Eloquent integration to Laravel.
It derives from the original [harrygulliford/laravel-firebird project](https://github.com/harrygulliford/laravel-firebird);
upstream ownership and contributor credits are preserved below.

> **Release preparation:** `v4.0.0-beta.1` is not yet published for this independent
> package. See the [draft release notes](releases/v4.0.0-beta.1.md).
> Packagist badges become available after registration and publication. The
> upstream package's identically numbered release is a different artifact.

> **Fork development note:** This fork is extending the upstream driver with comprehensive, tested Laravel schema builder and migration support for operations that can be represented safely in Firebird. This work is in progress and should not yet be considered production-ready migration support. See [the roadmap](docs/roadmap.md) and [historical migration support matrix at `065f24d`](docs/migration-support.md).

## Version Support

| Laravel | PHP versions in the CI matrix | Firebird |
| --- | --- | --- |
| 12 (Illuminate >= 12.17) | 8.2, 8.3, 8.4, 8.5 | 4, 5 |
| 13 | 8.3, 8.4, 8.5 | 4, 5 |

PHP 8.2 with Laravel 13 is not supported. Composer declares PHP `^8.2` and
Illuminate `^12.17|^13.0`; Laravel's own requirements further constrain valid
combinations. The CLI and application runtime require `pdo_firebird` and its
Firebird client library. CI uses Ubuntu and lowest/stable dependency resolutions;
other platforms and future PHP versions are not established by this matrix.

## Installation

The new package identity is not yet available on the published `4.x` branch.
The commands below describe future availability, not an installation available now.

After the package rename has been pushed to the fork's `4.x` branch, development
installation via VCS will be possible:

```bash
composer config repositories.firebird vcs https://github.com/MrSpockDe/laravel-firebird.git
composer require "mrspockde/laravel-firebird:4.x-dev"
```

Installing `4.0.0-beta.1` requires the corresponding published `v4.0.0-beta.1`
tag containing the new package identity. The development branch alone does not
provide that release. Normal installation through Packagist, without the VCS
override, becomes available only after package registration and version indexing.
Once those steps are complete, install the exact beta version:

```bash
composer require "mrspockde/laravel-firebird:4.0.0-beta.1"
```

The explicit beta constraint permits this package while retaining the application's
`minimum-stability: stable`. Do not install it alongside
`harrygulliford/laravel-firebird`; Composer declares a conflict with that package.
For an existing consumer, remove the old requirement and resolve the new one in
one reviewed Composer change, then inspect the generated lockfile.

Laravel automatically discovers `HarryGulliford\Firebird\FirebirdServiceProvider`.
The existing `HarryGulliford\Firebird\` PHP namespace and PSR-4 mappings remain
unchanged; Composer package identity is independent of PHP class names.

Declare the connection within your `config/database.php` file by using `firebird` as the
driver:
```php
'connections' => [

    'firebird' => [
        'driver'   => 'firebird',
        'host'     => env('DB_HOST', 'localhost'),
        'port'     => env('DB_PORT', '3050'),
        'database' => env('DB_DATABASE', '/path_to/database.fdb'),
        'username' => env('DB_USERNAME', 'sysdba'),
        'password' => env('DB_PASSWORD'),
        'charset'  => env('DB_CHARSET', 'UTF8'),
        'role'     => null,
    ],

],
```

## Migration support

The upstream package explicitly does not target database migrations. This fork is developing that support incrementally and test-first.

A schema feature is considered supported only when Laravel can execute it against Firebird and integration tests verify the resulting Firebird metadata. Features without a safe Firebird equivalent should fail explicitly rather than silently generate incorrect SQL.

See:

- [Development roadmap](docs/roadmap.md)
- [Laravel migration support matrix](docs/migration-support.md): historical snapshot at commit `065f24d`.

The [current release notes](releases/v4.0.0-beta.1.md) and current implementation
supersede outdated statements in that snapshot. It is not an exhaustive description
of the beta candidate. This beta does not promise production-ready migration workflows.

## Parameters in raw SQL expressions

Firebird cannot infer a bound parameter's SQL type in some raw expressions during
statement preparation. For example, this SELECT fails with SQLCODE `-804`:

```php
DB::table('items')->selectRaw('"value" + ?', [1])->get();
```

Provide the intended type explicitly:

```php
DB::table('items')->selectRaw('"value" + CAST(? AS INTEGER)', [1])->get();
```

When the parameter should use an existing column's type, you can also use:

```sql
CAST(? AS TYPE OF COLUMN "items"."value")
```

`TYPE OF COLUMN` requires the physical table/view name, not a query alias.
Table prefixes are not automatically added inside raw SQL; include the actual
physical name and quote identifiers appropriately.

Not all raw bindings need a cast. Direct comparisons such as
`whereRaw('"value" > ?', [10])` and other sufficiently typed SQL contexts work
without one. Raw SQL and its type choices remain the caller's responsibility;
the driver does not automatically infer types or rewrite raw expressions.

## Insert while ignoring duplicate keys

`insertOrIgnore($values)` supports single rows and batches. It returns the number
of rows inserted and ignores Firebird SQLCODE `-803` (PRIMARY KEY / UNIQUE
violations). Other errors propagate; a non-ignored error rolls back the statement's
changes. Identity sequences may still advance for ignored or rolled-back inserts.

The handler also catches `-803` raised by triggers, including constraints on other
tables. Under `NO WAIT`, Firebird can report an uncommitted competing UNIQUE key
as `-803`: the row may be ignored even if the competing transaction later rolls
back. A WAIT timeout can likewise surface as `-803` during a uniqueness check.
The driver does not change transaction settings or retry these conflicts.

Single-row expressions use Laravel's normal expression handling. As with batch
`insert()`, raw expressions in multi-row input are explicitly unsupported.
`insertOrIgnoreUsing()` and `insertOrIgnoreReturning()` remain unsupported.

## Credits

Original upstream attribution (retained):

- [Harry Gulliford](https://github.com/harrygulliford)
- [Jacques van Zuydam](https://github.com/jacquestvanzuydam/laravel-firebird)
- [Simonov Denis](https://github.com/sim1984/laravel-firebird)
- [All Contributors](https://github.com/harrygulliford/laravel-firebird/graphs/contributors)

Independent package development: [MrSpockDe/laravel-firebird contributors](https://github.com/MrSpockDe/laravel-firebird/graphs/contributors).

## License
Licensed under the [MIT](https://choosealicense.com/licenses/mit/) license.

The complete license terms are included in [LICENSE](LICENSE). The original
README declares MIT but supplies no dated copyright notice; none has been invented.
