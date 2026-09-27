# Firebird Database Driver for Laravel

[![Latest release (including prereleases)](https://img.shields.io/github/v/release/MrSpockDe/laravel-firebird?include_prereleases&label=release)](https://github.com/MrSpockDe/laravel-firebird/releases)
[![Total Downloads](https://poser.pugx.org/mrspockde/laravel-firebird/downloads)](https://packagist.org/packages/mrspockde/laravel-firebird)
[![Tests](https://github.com/MrSpockDe/laravel-firebird/actions/workflows/tests.yml/badge.svg)](https://github.com/MrSpockDe/laravel-firebird/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

`mrspockde/laravel-firebird` is an independently maintained Composer package
in [MrSpockDe/laravel-firebird](https://github.com/MrSpockDe/laravel-firebird).
It adds Firebird PDO support, schema operations and Eloquent integration to Laravel.
It derives from the original [harrygulliford/laravel-firebird project](https://github.com/harrygulliford/laravel-firebird);
upstream ownership and contributor credits are preserved below.

> **Published beta:** `v4.0.0-beta.1` is available as a
> [GitHub prerelease](https://github.com/MrSpockDe/laravel-firebird/releases/tag/v4.0.0-beta.1)
> and on [Packagist](https://packagist.org/packages/mrspockde/laravel-firebird).
> See the [release notes](releases/v4.0.0-beta.1.md). This is a beta, not a stable
> or production-ready release. The upstream package's identically numbered
> release is a different artifact.

> **RC1 preparation:** The `4.x` development branch has completed technical migration
> hardening for the planned `v4.0.0-rc.1`. RC1 is **not yet published**; the beta
> above remains the available release. The [current support reference](docs/migration-support.md)
> describes integration-tested operations and deliberate Firebird limitations.
> See the [roadmap](docs/roadmap.md) and [draft RC1 notes](releases/v4.0.0-rc.1.md).
> A release candidate is not a stable release or a production-readiness guarantee.

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

Install the exact published beta from Packagist; no custom VCS repository is needed:

```bash
composer require "mrspockde/laravel-firebird:4.0.0-beta.1"
```

When switching from the earlier fork development setup, remove the custom
`firebird` VCS repository entry from `composer.json` as part of the switch.
The `4.x-dev` constraint follows ongoing development and is not the pinned beta.
See the [validation evidence](releases/v4.0.0-beta.1.md#release-and-validation-evidence)
for the separate development-commit and published-beta checks.

The explicit beta constraint permits this package while retaining the application's
`minimum-stability: stable`. Do not install it alongside
`harrygulliford/laravel-firebird`; Composer declares a conflict with that package.
For an existing consumer, remove the old requirement and resolve the new one in
one reviewed Composer change, then inspect the generated lockfile.

Laravel automatically discovers `HarryGulliford\Firebird\FirebirdServiceProvider`.
The existing `HarryGulliford\Firebird\` PHP namespace and PSR-4 mappings remain
unchanged; Composer package identity is independent of PHP class names.

### Database creation for stock Laravel 13 migrations

A fresh installation using `laravel/laravel v13.10.1`, Laravel Framework
`v13.33.0` and the published `mrspockde/laravel-firebird v4.0.0-beta.1`
successfully ran all three unchanged Laravel standard migrations with
`php artisan migrate --no-interaction` on Firebird `5.0.4`, using **16384-byte
pages**, character set **UTF8** and collation **UTF8**. The second run reported
`Nothing to migrate`.

In a separate reproduction with 8192-byte pages, the original composite
`failed_jobs` index on `connection`, `queue` and `failed_at` failed with
`key size exceeds implementation restriction`. This is a demonstrated Firebird
index-size restriction for the tested schema; incorrect SQL generation by the
driver was not demonstrated. See [issue #112](https://github.com/MrSpockDe/laravel-firebird/issues/112).

For new databases using this specific combination, use the successfully tested
16384-byte page size. This is not a general minimum requirement for all Laravel
or Firebird applications.

For the official `firebirdsql/firebird` Docker image, add these environment
variables when creating the database:

```yaml
FIREBIRD_DATABASE_PAGE_SIZE: "16384"
FIREBIRD_DATABASE_DEFAULT_CHARSET: "UTF8"
```

These settings apply only when the image creates a new database. Changing the
Compose environment or Laravel connection configuration does not change an
existing database's page size. Laravel's connection `charset` setting controls
the connection character set, not the database's default character set.

Verify the actual database path and page size with this read-only query:

```sql
SELECT MON$DATABASE_NAME, MON$PAGE_SIZE
FROM MON$DATABASE;
```

### Laravel connection configuration

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

This independent fork provides integration-tested schema and migration support
beyond the upstream package's migration scope. The current `4.x` branch covers
core table/column lifecycles, constraints, introspection, comments, virtual computed
columns and representative Laravel helpers. Support is limited to the operations
and semantics documented in the [current migration support reference](docs/migration-support.md);
it is not a promise of full Laravel API parity.

At technical baseline `7a68ea3`, the full local suite passed on Firebird 4 and 5,
each with 646 tests / 4563 assertions. [CI](https://github.com/MrSpockDe/laravel-firebird/actions/runs/36324558893)
passed all 28 matrix jobs plus `ci-success` for the version matrix above.
These are development-branch results, not validation of an installed RC1.
The [published beta notes](releases/v4.0.0-beta.1.md) retain their historical scope;
[RC1 notes](releases/v4.0.0-rc.1.md) are preparation only.

Important boundaries include signed integer mappings without unsigned range
semantics, JSON text storage, ENUM as VARCHAR+CHECK, restricted ALTER operations
and no automatic migration transactions. TZ storage is native, with the tested
client-transport limits described in the support reference. Empty BLOB fetches may
be `''` or `null` depending on PHP/PDO; the test permits both, while database NULL
and a non-NULL zero-length BLOB remain distinct.

### Application Unix timestamps beyond 2038

For long-lived application schemas, consider BIGINT for Unix-second columns that
currently use `integer()` / `unsignedInteger()` (signed Firebird INTEGER), keeping
their existing nullability. See the [seven-column Laravel 13 / OweFlow recommendation](docs/migration-support.md#unix-timestamps-and-the-2038-horizon).
This is an application-schema choice, not a driver mapping change or a claim that
unchanged Laravel standard migrations fail.

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
