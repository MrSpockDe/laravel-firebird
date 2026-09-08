# Laravel migration support

This matrix describes the integration-tested migration support on `4.x`, reviewed at commit `065f24d` (Issues #1–#10). Evidence comes from [MigrationTest.php](../tests/MigrationTest.php), the schema grammar and processor, and the [successful CI run for that commit](https://github.com/MrSpockDe/laravel-firebird/actions/runs/34188122382).

The CI matrix covers Laravel 12/13, PHP 8.2–8.5 where supported by Laravel, Firebird 4/5, and lowest/stable dependency resolutions: all 28 matrix jobs and the aggregate check passed at this baseline. This is a dated verification, not a guarantee about later commits.

Legend:

- ✅ Integration-tested support for the scope stated in the notes.
- ⚠️ Partial support, emulation, unverified migration coverage, or unresolved semantics; not a claim of complete support.
- ❌ Not supported by this driver. An explicit exception is guaranteed only where stated.
- ➖ No meaningful native equivalent for the Laravel feature in this driver’s Firebird target.

A passing schema creation is not sufficient evidence by itself. Tests inspect metadata and, where relevant, data integrity, generated IDs, round-trips, and explicit drop operations. Cleanup alone is not a verified rollback. Features tested together through Blueprint helpers are identified below; broader variants are not implied.

## Tables and introspection

| Laravel feature | Status | Tested scope / limitations |
| --- | :---: | --- |
| `Schema::create()` / `drop()` / `dropIfExists()` | ✅ | Schema-API setup throughout the migration suite; users/posts smoke test explicitly drops the child and parent tables and verifies their absence. |
| `Schema::hasTable()` | ✅ | Smoke lifecycle verifies presence and absence. |
| `Schema::getTableListing()` | ⚠️ | Implemented, but not directly verified by `MigrationTest.php`; outside this matrix’s migration evidence. |
| `Schema::hasColumn()` | ✅ | Column drop/rename and FK lifecycle tests verify existence. |
| `Schema::getColumnListing()` | ⚠️ | Implemented, but no dedicated assertion in `MigrationTest.php`. |
| `Schema::getColumns()` / `getColumnType()` | ✅ | Laravel arrays and short/full types tested for representative identity, string, integer, nullable/default, text BLOB, timestamp, BOOLEAN and TZ columns. Boolean flags are normalized. |
| Computed-column introspection | ✅ | `generation` contains `type = virtual` and the expression. The fixture deliberately uses Firebird DDL: this proves introspection, not Blueprint computed-column creation. |
| `Schema::getIndexes()` / `hasIndex()` | ✅ | Single/composite, UNIQUE and PRIMARY entries; names, ordered columns, flags and positive/negative existence checks. `type` key presence is tested, not every index-type variant. |
| `Schema::getForeignKeys()` | ✅ | Simple/composite references and DELETE/UPDATE CASCADE metadata; local/referenced column order, names and actions. `foreign_schema` is `null`. |
| `Schema::hasForeignKey()` | ✅ | Positive/negative name and column checks on Laravel 13. Laravel 12 does not expose this API; only these existence tests are skipped there, not `getForeignKeys()`. |
| View introspection | ❌ | No driver implementation. |
| `Schema::dropAllTables()` | ❌ | No driver implementation. |

Column metadata exposes `name`, `type_name`, `type`, `collation`, `nullable`, `default`, `auto_increment`, `comment` and `generation`. Key presence does not imply tested comment/charset/collation DDL. Expression-index, descending-index and additional type variants are not comprehensively covered by the representative introspection tests.

## Identity and integer columns

| Laravel feature | Status | Tested scope / limitations |
| --- | :---: | --- |
| `id()` | ✅ | Identity metadata, primary key and generated IDs verified. |
| `increments()` / `bigIncrements()` | ✅ | INTEGER/BIGINT identity metadata verified. |
| `tinyIncrements()` / `smallIncrements()` / `mediumIncrements()` | ✅ | Tested Firebird mappings: SMALLINT / SMALLINT / INTEGER, with identity. No claim of MySQL-sized integer ranges. |
| Integer types / unsigned semantics | ⚠️ | INTEGER/BIGINT and widening are tested; Firebird mappings do not enforce Laravel’s unsigned integer range semantics. |
| `foreignId()` | ✅ | Tested as a non-identity, non-null BIGINT through `constrained()`. Does not imply unsigned range enforcement. |
| Eloquent-generated IDs | ✅ | `create()` and `save()` return positive integer IDs; persisted values and reload by ID are verified on Blueprint-created tables. |

Identity start/increment options and all integer boundary values are not covered.

## Column operations

| Laravel feature | Status | Tested scope / limitations |
| --- | :---: | --- |
| Add column | ✅ | Single and multiple additions in one `Schema::table()` callback; metadata, existing data, defaults on new inserts and subsequent drop verified. |
| `dropColumn()` | ✅ | Single/multiple columns. Dropping a column used by a regular index is rejected and leaves it intact. The tested UNIQUE-column case removes its constraint and backing index while preserving the unrelated primary key. Not automatic handling of every dependency. |
| `renameColumn()` | ✅ | Forward/backward rename preserves populated values, type, length, default and nullability. |
| `change()` | ✅ | INTEGER→BIGINT, VARCHAR widening, nullable/NOT NULL, default set/change/remove and preservation when attributes are omitted. Populated-column tests verify data, default effects and NOT NULL rejection; default/nullability restoration is tested. |
| `nullable()` / `default()` | ✅ | Metadata plus actual NULL acceptance/rejection and default application covered for representative columns. |
| `charset()` / `collation()` | ⚠️ | Grammar modifiers exist; no migration integration coverage establishing their semantics. |
| `comment()` | ❌ | No column-comment DDL support; metadata key presence does not change this. |
| `useCurrent()` | ⚠️ | Timestamp implementation exists; no dedicated migration test of its behavior. |
| `useCurrentOnUpdate()` | ❌ | No implementation of automatic update behavior. |

`change()` preserves nullability/default when those attributes are absent; explicitly passing `nullable(false)` sets NOT NULL, and `default(null)` removes a default. These are tested driver semantics. Arbitrary type conversions, narrowing and transactional rollback of multiple ALTER statements are not promised. BLOB→INTEGER rejection is tested with the original column/type preserved.

## Indexes and constraints

| Laravel feature | Status | Tested scope / limitations |
| --- | :---: | --- |
| Primary key / UNIQUE / regular index | ✅ | Creation and metadata verified, including composite primary/index definitions. The smoke test verifies duplicate-email rejection. |
| `dropPrimary()` | ✅ | Removes the actual Firebird-assigned primary constraint without requiring its internal name. |
| `dropUnique()` | ✅ | Removes a constraint, not a standalone index; generated and long generated names tested. |
| `dropIndex()` | ✅ | Generated, explicit and long generated names; composite indexes also removed through `dropMorphs()`. |
| `foreign()` / `dropForeign()` | ✅ | Simple/composite lifecycle and long generated names tested. Invalid references are rejected before drop and accepted afterwards. |
| `foreignId()->constrained()` | ✅ | Conventional and explicit parent-table references, BIGINT metadata and valid/invalid child inserts verified. |
| `cascadeOnDelete()` | ✅ | Actual parent deletion removes dependent children; an unrelated parent/child pair remains. Also exercised by the smoke migration. |
| UPDATE CASCADE | ⚠️ | `onUpdate('cascade')` creation and introspection are tested. No parent-key update/data-cascade test, and no direct `cascadeOnUpdate()` helper test. |
| `dropConstrainedForeignId()` | ⚠️ | Composes implemented drop-FK/drop-column commands, but the combined helper has no integration test. The previous claim that drop-column support is absent is obsolete. |
| Other FK actions | ⚠️ | No comprehensive data-behavior coverage for SET NULL, SET DEFAULT or explicit RESTRICT helpers. |

Create/drop currently retain a **31-character naming convention** for regular indexes, UNIQUE constraints and foreign keys. This is a driver convention, not Firebird 4/5’s maximum identifier length. Long-name lifecycle tests do not establish collision safety for names sharing the same prefix, or support for every quoted/mixed-case identifier.

The long-FK test verifies persisted rows using predicates rather than long result-property names. PDO result-name handling beyond 31 characters is not validated as supported by that test.

## Data types

| Laravel feature | Status | Tested scope / limitations |
| --- | :---: | --- |
| `string()` / `char()` | ✅ | VARCHAR metadata/length and round-trips; CHAR storage covered through UUID/ULID morph helpers, not every standalone CHAR variant. |
| `text()` | ✅ | Text BLOB introspection and smoke-test data round-trip. |
| `mediumText()` / `longText()` | ⚠️ | Mapped to text BLOBs in the grammar; separate helper semantics not integration-tested. |
| `decimal()` / `double()` / `float()` | ⚠️ | Grammar mappings exist; precision, scale and round-trip migration coverage is missing. |
| `boolean()` | ✅ | Native BOOLEAN (field type 23), TRUE/FALSE defaults, nullable values and Query Builder/Eloquent round-trips for `true`, `false`, `1`, `0`. No CHAR(1) emulation. |
| `enum()` | ⚠️ | VARCHAR + CHECK emulation; no dedicated migration lifecycle/data validation test. |
| `json()` / `jsonb()` | ⚠️ | Text-storage mappings, not native JSON/JSONB semantics; no dedicated migration tests. |
| `date()` / `time()` / `dateTime()` | ⚠️ | Plain-type grammar mappings exist, but no dedicated coverage of these Blueprint methods in `MigrationTest.php`. TZ tests do not establish their coverage. |
| `timestamp()` | ✅ | Metadata, nullable values and ordinary timestamp read/write through smoke/convenience tests. |
| `timeTz()` / `dateTimeTz()` / `timestampTz()` | ✅ | Native TIME WITH TIME ZONE / TIMESTAMP WITH TIME ZONE (field types 28/29); nullability, precision argument 4, introspection and offset-value round-trips tested. |
| `binary()` | ⚠️ | BLOB metadata and incompatible-change rejection tested; binary length/fixed and content round-trips not covered. |
| `uuid()` / `ulid()` | ✅ | CHAR(36)/CHAR(26) storage and valid-value round-trips exercised through morph helpers. Does not imply server-side UUID/ULID validation or generation. |
| `ipAddress()` / `macAddress()` | ⚠️ | Character mappings exist, but no migration integration coverage. |
| `year()` / `geometry()` | ❌ | No type implementation in this driver. |
| `vector()` | ➖ | No native Laravel vector/vector-index equivalent implemented for the Firebird 4/5 target. |
| Blueprint computed-column creation | ❌ | Not implemented; the computed introspection fixture uses explicit Firebird DDL. |

TZ precision arguments do not generate `TIME(4)`/`TIMESTAMP(4)` syntax; Firebird uses its fixed fractional resolution. The connector initializes each connection with `SET BIND OF TIME ZONE TO VARCHAR` for compatible client transport while leaving stored columns native. Tests compare UTC-equivalent values and stored fractions; they do not require byte-for-byte preservation of the original offset spelling or establish all named-zone/DST cases. The `softDeletesTz()` round-trip additionally checks returned timezone information.

## Convenience helpers

| Laravel feature | Status | Tested scope / limitations |
| --- | :---: | --- |
| `timestamps()` / `dropTimestamps()` | ✅ | Both columns, TIMESTAMP type, nullable metadata, data round-trip and removal. |
| `timestampsTz()` | ✅ | Both columns are nullable native TIMESTAMP WITH TIME ZONE; default precision and precision 4 tested. Dedicated `dropTimestampsTz()` coverage is still missing; current tests clean up the table. |
| `softDeletes()` / `dropSoftDeletes()` | ✅ | Nullable TIMESTAMP, populated/NULL values and column removal. This tests Blueprint storage, not Eloquent SoftDeletes delete/restore behavior. |
| `softDeletesTz()` / `dropSoftDeletesTz()` | ✅ | Native TZ metadata, timezone-aware round-trip, NULL values and removal. |
| `rememberToken()` / `dropRememberToken()` | ✅ | Nullable VARCHAR(100), full-length token read/write and removal. |
| `morphs()` / `nullableMorphs()` | ✅ | Numeric IDs, type column, nullability, ordered composite index and values including actual NULLs for the nullable variant. |
| `uuidMorphs()` / `nullableUuidMorphs()` | ✅ | CHAR(36), valid UUID round-trip, index and nullable behavior. |
| `ulidMorphs()` / `nullableUlidMorphs()` | ✅ | CHAR(26), valid ULID round-trip, index and nullable behavior. |
| `dropMorphs()` | ✅ | Removes both columns and the index for all six tested morph variants, preserving the other columns/data. |

Morph tests use the standard helper behavior and names. They do not cover all custom names, global morph-key configuration, `$after` positioning or Eloquent polymorphic relation behavior. No speculative version skips are needed for these helper APIs in the supported Laravel baseline.

## Explicitly unsupported operations

All four operations below are tested to throw `LogicException`, with existing schema and data unchanged. This describes driver support, not whether some alternative Firebird implementation could be developed.

| Laravel operation | Status | Exception message |
| --- | :---: | --- |
| Temporary tables (`temporary()`) | ❌ | `This database driver does not support temporary tables.` |
| Table rename (`Schema::rename()`) | ❌ | `This database driver does not support renaming tables.` |
| Index rename (`renameIndex()`) | ❌ | `This database driver does not support renaming indexes.` |
| Spatial index (`spatialIndex()`) | ❌ | `This database driver does not support creating spatial indexes.` |

Other unsupported modifiers/commands should not be assumed to have the same explicit-error guarantee. Fulltext and other specialized operations lack equivalent migration regression coverage in this matrix.

## End-to-end evidence and remaining scope

The representative `smoke_users` / `smoke_posts` migration uses only Blueprint DDL: identities, unique email, verification timestamp, password, token, timestamps, constrained user reference, cascade deletion, title index, text body, BOOLEAN default and soft-delete storage. It verifies generated IDs, reads/updates, UNIQUE/FK violations, actual cascade deletion and child-before-parent rollback with absence checks. A separate Eloquent smoke test checks `save()`, generated ID, reload and automatic timestamps using the existing test model.

Key evidence in [MigrationTest.php](../tests/MigrationTest.php):

- `it_runs_a_representative_users_and_posts_migration_lifecycle` and `it_saves_and_reloads_an_eloquent_model_on_the_smoke_schema`.
- `it_creates_and_drops_convenience_columns` and `it_creates_and_drops_morph_columns`, including their providers.
- FK helper/lifecycle/cascade/composite/long-name tests, plus `it_introspects_foreign_*`.
- Populated add/rename/change lifecycles, index/constraint drops, identity and `it_introspects_column_*` / `it_introspects_index*` tests.
- `it_uses_native_boolean_*`, `it_supports_time_zone_*` and the unsupported-operation provider.

Remaining uncertainties are called out in the rows rather than promoted to tested support. In particular, a passing smoke migration does not establish every Blueprint modifier, arbitrary ALTER conversion, transactional DDL rollback, identifier collision behavior, or every client’s result-name handling.
