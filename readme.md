# Firebird for Laravel

[![Latest Stable Version](https://poser.pugx.org/harrygulliford/laravel-firebird/v/stable)](https://packagist.org/packages/harrygulliford/laravel-firebird)
[![Total Downloads](https://poser.pugx.org/harrygulliford/laravel-firebird/downloads)](https://packagist.org/packages/harrygulliford/laravel-firebird)
[![Tests](https://github.com/harrygulliford/laravel-firebird/actions/workflows/tests.yml/badge.svg)](https://github.com/harrygulliford/laravel-firebird/actions/workflows/tests.yml)
[![License](https://poser.pugx.org/harrygulliford/laravel-firebird/license)](https://packagist.org/packages/harrygulliford/laravel-firebird)

This package adds support for the Firebird PDO Database Driver in Laravel applications.

> **Fork development note:** This fork is extending the upstream driver with comprehensive, tested Laravel schema builder and migration support for operations that can be represented safely in Firebird. This work is in progress and should not yet be considered production-ready migration support. See [the roadmap](docs/roadmap.md) and [migration support matrix](docs/migration-support.md).

## Version Support

- **PHP:** 8.2+
- **Laravel:** 12, 13
- **Firebird:** 4, 5

## Installation

You can install the upstream package via composer:

```bash
composer require harrygulliford/laravel-firebird
```

_The package will automatically register itself._

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
        'password' => env('DB_PASSWORD', 'masterkey'),
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
- [Laravel migration support matrix](docs/migration-support.md)

Until the relevant items in the support matrix are marked supported, do not rely on this fork for production migration workflows.

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
- [Harry Gulliford](https://github.com/harrygulliford)
- [Jacques van Zuydam](https://github.com/jacquestvanzuydam/laravel-firebird)
- [Simonov Denis](https://github.com/sim1984/laravel-firebird)
- [All Contributors](https://github.com/harrygulliford/laravel-firebird/graphs/contributors)

## License
Licensed under the [MIT](https://choosealicense.com/licenses/mit/) license.
