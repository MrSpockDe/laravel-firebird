# Laravel Firebird roadmap

The `4.x` branch has reached the technical hardening baseline for preparation of
`v4.0.0-rc.1`: `7a68ea3fafbc655a829cd92fefbe431a5e3db298`. RC1 is not yet published;
`v4.0.0-beta.1` remains the published version. A release candidate is not a stable
release. This roadmap describes achieved scope and remaining boundaries, not
complete Laravel API parity. The [support reference](migration-support.md) defines
the tested details and restrictions.

## Validated matrix

| Laravel | PHP | Firebird |
| --- | --- | --- |
| 12 (Illuminate >= 12.17) | 8.2–8.5 | 4, 5 |
| 13 | 8.3–8.5 | 4, 5 |

Laravel 13 / PHP 8.2 is excluded. Ubuntu CI covers `prefer-lowest` and
`prefer-stable`: [28 matrix jobs plus ci-success passed](https://github.com/MrSpockDe/laravel-firebird/actions/runs/36324558893).
The full local suite passed on each of Firebird 4 and 5 with 646 tests / 4563
assertions. These results concern the technical baseline, not installation of a
published RC1. Passing CI jobs do not imply that every test runs on every framework
version (for example, Laravel 12 lacks `hasForeignKey()`).

## Phase 1 / P0 — Core migrations: achieved in the tested scope

- Identity (`id`, increments variants), start options and generated IDs.
- Table create/drop; add/drop/rename columns; representative type, default and
  nullability changes with existing data preserved.
- Primary, unique and regular indexes, including composite definitions and drops.
- Foreign keys, actual DELETE/UPDATE CASCADE, SET NULL and NO ACTION behavior.
- `foreignId()->constrained()` and the full `dropConstrainedForeignId()` lifecycle.
- Eloquent generated IDs through RETURNING, create/save/reload and timestamps.
- A representative users/posts Laravel migration with constraints, CRUD,
  cascade deletion and explicit child-before-parent rollback.

These checks do not promise arbitrary type conversions, dependency removal or
transactional migration DDL. FK drops retain execution-time metadata resolution;
no speculative architecture refactoring is planned for 4.0.

## Phase 2 / P1 — Introspection: achieved in the tested scope

- Table/column listings and relevant existence helpers.
- Column types, defaults, nullability, identity, comments and virtual generation.
- Index metadata, ordered columns and existence checks.
- Foreign-key metadata/actions and Laravel 13 FK existence helpers.
- View names/definitions, case preservation and empty results.
- Dependency-aware `dropAllTables()` / `dropAllViews()` with dedicated failure
  safety; this is not a general migration transaction guarantee.

## Convenience and modern Firebird support: achieved in the tested scope

- Timestamps and TZ timestamps with their drop helpers; soft-delete storage,
  remember tokens, numeric/UUID/ULID morph columns and indexes.
- Table/column comments and tested CREATE/ADD charset/collation modifiers.
- Native BOOLEAN, TIME WITH TIME ZONE and TIMESTAMP WITH TIME ZONE.
- `virtualAs()` CREATE/ADD and calculated values, not persisted computed columns.
- 63-Unicode-character identifier handling, hashed long generated names and
  guarded legacy-drop resolution with collision tests.
- Identity start/mode options, current-timestamp defaults, binary storage variants,
  float/double precision mappings and VARCHAR+CHECK ENUM emulation.
- The five final audit gaps are closed: mediumText/longText, DECIMAL precision and
  scale, date/time, IP/MAC storage and dropTimestampsTz.

## Deliberate limits of the 4.0 support promise

Signed integer mappings do not enforce unsigned ranges. JSON/JSONB provide limited
text storage, not native JSON semantics. ENUM is emulation. TZ tests establish
native storage and representative offset/fraction behavior, not every named-zone
or DST case. IP/MAC helpers store strings; they do not validate addresses.

Blueprint temporary tables, table/index rename, spatial indexes,
`useCurrentOnUpdate()`, stored/persisted computed columns, computed CHANGE,
`year()`, `geometry()` and `vector()` are unsupported. Enum CHANGE and explicit
charset/collation CHANGE are rejected. SET DEFAULT FK behavior, specialized
fulltext/vector/spatial operations and universal Blueprint parity are outside
4.0's tested scope.

Firebird index-size limits depend on schema and page size. The validated unchanged
Laravel 13 standard migrations used 16384-byte pages and UTF8; this is not a
universal minimum. See the [configuration and Unix-time guidance](migration-support.md#stock-laravel-13-database-configuration),
including the optional BIGINT recommendation for application timestamps beyond
January 2038. Raw-expression CAST requirements and client-dependent empty-BLOB
fetch behavior also remain documented boundaries.

## Release preparation and post-4.0

The next release steps require separate review: final RC1 SHA/tag, GitHub
prerelease, Packagist availability and fresh installation/consumer validation with
Laravel 12, Laravel 13 and OweFlow. None is established by this documentation pass;
see the [draft release notes](../releases/v4.0.0-rc.1.md).

Post-4.0 work should be driven by reproducible consumer needs: additional ALTER
conversions, specialized features with a safe Firebird mapping, broader zone/client
coverage, and concrete compatibility cases involving direct `toSql()` consumers.
These are candidate investigations, not committed features or outstanding RC1 gaps.

Tests should continue to use Laravel schema APIs against real Firebird databases,
inspect metadata and data, and verify explicit drops where applicable. Keep the
valid Laravel/PHP/Firebird matrix and dependency-resolution variants; do not infer
support solely from an inherited Laravel API or successful SQL compilation.
