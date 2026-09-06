# Laravel Firebird roadmap

This fork extends the existing Firebird database driver with complete and explicitly tested Laravel schema and migration support where Firebird can provide an equivalent feature.

## Target matrix

- Laravel 12 and 13
- PHP 8.2 through 8.5
- Firebird 4 and 5

The existing query builder, Eloquent integration, pagination support and `INSERT ... RETURNING` implementation remain the base. The main development focus is the schema builder and migration grammar.

## Definition of migration support

A Laravel schema operation is considered supported only when:

1. Laravel generates valid Firebird SQL.
2. The operation is exercised by an integration test against a real Firebird database.
3. The resulting Firebird metadata matches the requested Laravel schema semantics.
4. Rollback/drop operations are tested where applicable.
5. Features that cannot be represented meaningfully in Firebird fail explicitly instead of silently generating incorrect SQL.

## Phase 1 — Core migration support

Priority: P0

- Identity columns for `id()`, `increments()` and `bigIncrements()`
- Create and drop tables
- Add, drop and rename columns
- Change column type, nullability and default values
- Primary, unique and regular indexes
- Drop primary, unique and regular indexes
- Foreign keys, including cascade actions
- `foreignId()->constrained()`
- `dropConstrainedForeignId()`
- Eloquent `Model::create()` with generated IDs through Firebird `RETURNING`

A representative smoke migration should cover normal Laravel application tables such as `users` and related tables.

## Phase 2 — Schema introspection

Priority: P1

- Complete `getColumns()` metadata
- `getColumnType()`
- `getIndexes()` / `hasIndex()`
- `getForeignKeys()` / `hasForeignKey()`
- Views where Firebird can expose equivalent metadata

Introspection should return the structures expected by Laravel 13, including type, nullability, defaults, auto-increment/identity status and relevant constraint metadata.

## Phase 3 — Laravel convenience features

Priority: P1/P2

- `timestamps()` / `timestampsTz()`
- `softDeletes()` / `softDeletesTz()`
- `rememberToken()`
- Morph columns and indexes
- UUID and ULID helpers
- Column comments where practical

## Phase 4 — Modern Firebird types and semantics

Priority: P2

- Native `BOOLEAN`
- `TIME WITH TIME ZONE`
- `TIMESTAMP WITH TIME ZONE`
- Computed/generated columns where Laravel semantics can be mapped safely
- Modern Firebird identifier-length handling
- Identity options where useful

## Phase 5 — Unsupported or specialized features

Priority: P3

Features such as vector indexes, database-specific full-text behavior, unsupported spatial types or other concepts without a reliable Firebird equivalent should be documented and should fail clearly rather than be approximated incorrectly.

## Test strategy

Tests should use Laravel schema APIs directly. Test database setup for migration-specific scenarios must not hide missing grammar support behind hand-written `CREATE TABLE` SQL.

The CI matrix should continue to cover Laravel 12/13, PHP 8.2–8.5 and Firebird 4/5 where the framework/runtime combination is valid.
