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

## Credits
- [Harry Gulliford](https://github.com/harrygulliford)
- [Jacques van Zuydam](https://github.com/jacquestvanzuydam/laravel-firebird)
- [Simonov Denis](https://github.com/sim1984/laravel-firebird)
- [All Contributors](https://github.com/harrygulliford/laravel-firebird/graphs/contributors)

## License
Licensed under the [MIT](https://choosealicense.com/licenses/mit/) license.
