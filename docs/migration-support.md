# Laravel migration support

Current support reference for published `v4.0.0-rc.1`, based on technical hardening
baseline `7a68ea3fafbc655a829cd92fefbe431a5e3db298`. The final release commit is
`6eb93babee699c3b25dbc6481252d44d6277a971`. RC1 is a GitHub prerelease available
through Packagist, not a stable release or a guarantee of Laravel API parity.

After publication, fresh consumers installed the RC1 Packagist Dist artifact and
passed all four Laravel 12/13 × Firebird 4/5 combinations. OweFlow's upgrade to
the published RC1 passed 88 tests / 493 assertions. See the
[release and consumer evidence](../releases/v4.0.0-rc.1.md#published-consumer-validation)
for exact versions and scope; these checks are separate from the hardening results below.

At this baseline the full local suite passed on Firebird 4 and 5, each with
646 tests / 4563 assertions. [CI](https://github.com/MrSpockDe/laravel-firebird/actions/runs/36324558893)
passed all 28 matrix jobs plus `ci-success`: Laravel 12 / PHP 8.2–8.5 and
Laravel 13 / PHP 8.3–8.5, Firebird 4/5, `prefer-lowest` and `prefer-stable`.
Laravel 13 / PHP 8.2 is excluded. Passing jobs do not imply zero individual test skips.

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
| `Schema::getTableListing()` | ✅ | Table listing is directly verified by `SchemaTest.php`. |
| `Schema::hasColumn()` | ✅ | Column drop/rename and FK lifecycle tests verify existence. |
| `Schema::getColumnListing()` | ✅ | Column listing is directly verified by `SchemaTest.php`. |
| `Schema::getColumns()` / `getColumnType()` | ✅ | Laravel arrays and short/full types tested for representative identity, string, integer, nullable/default, text BLOB, timestamp, BOOLEAN and TZ columns. Boolean flags are normalized. |
| Computed-column introspection | ✅ | `generation` contains `type = virtual` and the expression. Blueprint CREATE/ADD and calculated values are also tested in `VirtualColumnTest.php`. |
| `Schema::getIndexes()` / `hasIndex()` | ✅ | Single/composite, UNIQUE and PRIMARY entries; names, ordered columns, flags and positive/negative existence checks. `type` key presence is tested, not every index-type variant. |
| `Schema::getForeignKeys()` | ✅ | Simple/composite references and DELETE/UPDATE CASCADE metadata; local/referenced column order, names and actions. `foreign_schema` is `null`. |
| `Schema::hasForeignKey()` | ✅ | Positive/negative name and column checks on Laravel 13. Laravel 12 does not expose this API; only these existence tests are skipped there, not `getForeignKeys()`. |
| View introspection (`getViews()`) | ✅ | Definitions, identifier case, multiple views and empty results tested in `ViewIntrospectionTest.php`; not a Blueprint view-creation API. |
| `Schema::dropAllTables()` / `dropAllViews()` | ✅ | Dependency-aware wipes, empty database, FK layouts, view ordering, identity rebuild and failure safety tested in `DropAllSchemaObjectsTest.php`. Physical names are used without prefix filtering; these are database-wide operations. |

Column metadata exposes `name`, `type_name`, `type`, `collation`, `nullable`, `default`, `auto_increment`, `comment` and `generation`. Comment DDL and representative CREATE/ADD charset/collation semantics have separate integration coverage. Expression-index, descending-index and additional type variants are not comprehensively covered by the representative introspection tests.

## Identity and integer columns

| Laravel feature | Status | Tested scope / limitations |
| --- | :---: | --- |
| `id()` | ✅ | Identity metadata, primary key and generated IDs verified. |
| `increments()` / `bigIncrements()` | ✅ | INTEGER/BIGINT identity metadata verified. |
| `tinyIncrements()` / `smallIncrements()` / `mediumIncrements()` | ✅ | Tested Firebird mappings: SMALLINT / SMALLINT / INTEGER, with identity. No claim of MySQL-sized integer ranges. |
| Integer types / unsigned semantics | ⚠️ | INTEGER/BIGINT and widening are tested; Firebird mappings do not enforce Laravel’s unsigned integer range semantics. |
| `foreignId()` | ✅ | Tested as a non-identity, non-null BIGINT through `constrained()`. Does not imply unsigned range enforcement. |
| Eloquent-generated IDs | ✅ | `create()` and `save()` return positive integer IDs; persisted values and reload by ID are verified on Blueprint-created tables. |

`IdentityOptionsTest.php` verifies `from()` / `startingValue()` (including precedence), generated BY DEFAULT / ALWAYS options, explicit-ID acceptance/rejection and the next generated value. Arbitrary increment options and all integer boundary values are not promised.

## Column operations

| Laravel feature | Status | Tested scope / limitations |
| --- | :---: | --- |
| Add column | ✅ | Single and multiple additions in one `Schema::table()` callback; metadata, existing data, defaults on new inserts and subsequent drop verified. |
| `dropColumn()` | ✅ | Single/multiple columns. Dropping a column used by a regular index is rejected and leaves it intact. The tested UNIQUE-column case removes its constraint and backing index while preserving the unrelated primary key. Not automatic handling of every dependency. |
| `renameColumn()` | ✅ | Forward/backward rename preserves populated values, type, length, default and nullability. |
| `change()` | ✅ | INTEGER→BIGINT, VARCHAR widening, nullable/NOT NULL, default set/change/remove and preservation when attributes are omitted. Populated-column tests verify data, default effects and NOT NULL rejection; default/nullability restoration is tested. |
| `nullable()` / `default()` | ✅ | Metadata plus actual NULL acceptance/rejection and default application covered for representative columns. |
| `charset()` / `collation()` | ✅ | CREATE/ADD with UTF8 and UNICODE_CI, defaults, nullability and stored values tested. Explicit charset/collation CHANGE is rejected, even for the same value; widening without these modifiers preserves attributes. |
| Column / table `comment()` | ✅ | CREATE/ADD/CHANGE column comments and table-comment lifecycle, including replacement/removal and metadata, tested in `SchemaCommentTest.php`. |
| `useCurrent()` | ✅ | `UseCurrentTest.php` verifies CREATE/ADD/CHANGE, CURRENT_TIMESTAMP metadata and actual defaults for timestamp/dateTime and TZ counterparts, plus preservation/replacement/removal. |
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
| UPDATE CASCADE / `cascadeOnUpdate()` | ✅ | Parent-key update cascades to matching children and preserves unrelated rows; metadata and helper tested in `ForeignKeyActionTest.php`. |
| `dropConstrainedForeignId()` | ✅ | One `Schema::table()` call removes FK and column after proving enforcement; other child columns/data and parent rows survive, and new child inserts without the FK column succeed. |
| SET NULL / NO ACTION | ✅ | DELETE and UPDATE helpers have metadata and data-behavior coverage in `ForeignKeyActionTest.php`. Omitted actions also block referenced parent changes. |
| Explicit RESTRICT | ❌ | Rejected with `LogicException`; use NO ACTION or omit the action. |
| SET DEFAULT | ⚠️ | No integration-tested data-behavior guarantee; outside the 4.0 tested action scope. |

Generated index/constraint identifiers use up to **63 Unicode characters**. Longer
names retain a prefix plus a hash; overlong explicit names are rejected.
`IdentifierNormalizationTest.php` covers collisions, Unicode and guarded legacy
resolution. The old 31-byte truncation is only a fallback for generated-name drops:
valid UTF8, matching table/object type and ordered columns are required, with the
modern name preferred. Explicit names are not silently shortened to legacy names.
These tests do not establish every client's long result-property-name behavior.

Foreign-key drops resolve the actual constraint from current metadata at execution
time, preserving Blueprint command order. Internally this path uses a
`ForeignKeyDrop` object, so direct `toSql()` consumers must not assume every entry
is a SQL string. No concrete consumer incompatibility was established in the review;
the mechanism remains unchanged for 4.0.

## Data types

| Laravel feature | Status | Tested scope / limitations |
| --- | :---: | --- |
| `string()` / `char()` | ✅ | VARCHAR metadata/length and round-trips; CHAR storage covered through UUID/ULID morph helpers, not every standalone CHAR variant. |
| `text()` | ✅ | Text BLOB introspection and smoke-test data round-trip. |
| `mediumText()` / `longText()` | ✅ | Text BLOB subtype 1, UTF8 round-trip, NULL and column drop preserving other columns/data. No MySQL size-class emulation. |
| `decimal()` | ✅ | Explicit DECIMAL(12,2) precision/scale/subtype and positive/negative exact round-trips, plus existing default coverage. Not every precision/boundary combination. |
| `float()` / `double()` | ✅ | `FloatPrecisionTest.php` verifies FLOAT precision mappings, single/double storage and representative round-trips; invalid precision is rejected by Firebird, not clamped. |
| `boolean()` | ✅ | Native BOOLEAN (field type 23), TRUE/FALSE defaults, nullable values and Query Builder/Eloquent round-trips for `true`, `false`, `1`, `0`. No CHAR(1) emulation. |
| `enum()` | ✅ | Tested VARCHAR + CHECK emulation: CREATE/ADD, defaults, NULL, valid/invalid values and escaped literals (`EnumDefaultTest.php`, `EnumEscapingTest.php`). Not a native ENUM; enum CHANGE is rejected. |
| `json()` / `jsonb()` | ⚠️ | Text-storage mappings, not native JSON/JSONB semantics; no dedicated migration tests. |
| `date()` / `time()` | ✅ | Native DATE/TIME metadata, representative value round-trips, NULL and column removal preserving other data. |
| `dateTime()` | ✅ | Native TIMESTAMP metadata and actual default/read behavior on CREATE/ADD/CHANGE in `UseCurrentTest.php`. |
| `timestamp()` | ✅ | Metadata, nullable values and ordinary timestamp read/write through smoke/convenience tests. |
| `timeTz()` / `dateTimeTz()` / `timestampTz()` | ✅ | Native TIME WITH TIME ZONE / TIMESTAMP WITH TIME ZONE (field types 28/29); nullability, precision argument 4, introspection and offset-value round-trips tested. |
| `binary()` | ✅ | `BinaryTypeTest.php`: unbounded binary BLOB; explicit length maps to VARBINARY or fixed BINARY (OCTETS). CREATE/ADD metadata, byte round-trips, fixed padding, overlength rejection and VARBINARY widening tested. BLOB conversion remains restricted by Firebird. |
| `uuid()` / `ulid()` | ✅ | CHAR(36)/CHAR(26) storage and valid-value round-trips exercised through morph helpers. Does not imply server-side UUID/ULID validation or generation. |
| `ipAddress()` / `macAddress()` | ✅ | VARCHAR(45)/(17), representative round-trips, NULL and column drop preserving other data. Storage only, no address validation. |
| `year()` / `geometry()` | ❌ | No type implementation in this driver. |
| `vector()` | ➖ | No native Laravel vector/vector-index equivalent implemented for the Firebird 4/5 target. |
| `virtualAs()` | ✅ | Blueprint CREATE/ADD as COMPUTED BY, calculated values after writes, generation metadata and rejection of direct writes tested. Not persisted storage; incompatible modifiers and computed CHANGE are rejected. |

TZ precision arguments do not generate `TIME(4)`/`TIMESTAMP(4)` syntax; Firebird uses its fixed fractional resolution. The connector initializes each connection with `SET BIND OF TIME ZONE TO VARCHAR` for compatible client transport while leaving stored columns native. Tests compare UTC-equivalent values and stored fractions; they do not require byte-for-byte preservation of the original offset spelling or establish all named-zone/DST cases. The `softDeletesTz()` round-trip additionally checks returned timezone information.

## Convenience helpers

| Laravel feature | Status | Tested scope / limitations |
| --- | :---: | --- |
| `timestamps()` / `dropTimestamps()` | ✅ | Both columns, TIMESTAMP type, nullable metadata, data round-trip and removal. |
| `timestampsTz()` / `dropTimestampsTz()` | ✅ | Nullable native TIMESTAMP WITH TIME ZONE, default/explicit precision, removal of both columns while preserving other columns/data, and successful subsequent writes. |
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

`storedAs()`, persisted `virtualAs()`, enabled `useCurrentOnUpdate()`, computed
CHANGE, enum CHANGE and explicit charset/collation CHANGE are also explicitly
rejected; see `UnsupportedSchemaModifierTest.php`, `VirtualColumnTest.php` and
`MigrationTest.php`. `year()`, `geometry()` and `vector()` have no supported driver
mapping. Other unsupported modifiers/commands should not be assumed to have the
same explicit-error guarantee. Fulltext and other specialized operations lack equivalent migration regression coverage in this matrix.

## End-to-end evidence and remaining scope

The representative `smoke_users` / `smoke_posts` migration uses only Blueprint DDL: identities, unique email, verification timestamp, password, token, timestamps, constrained user reference, cascade deletion, title index, text body, BOOLEAN default and soft-delete storage. It verifies generated IDs, reads/updates, UNIQUE/FK violations, actual cascade deletion and child-before-parent rollback with absence checks. A separate Eloquent smoke test checks `save()`, generated ID, reload and automatic timestamps using the existing test model.

Key evidence in [MigrationTest.php](../tests/MigrationTest.php):

- `it_runs_a_representative_users_and_posts_migration_lifecycle` and `it_saves_and_reloads_an_eloquent_model_on_the_smoke_schema`.
- `it_creates_and_drops_convenience_columns` and `it_creates_and_drops_morph_columns`, including their providers.
- FK helper/lifecycle/cascade/composite/long-name tests, plus `it_introspects_foreign_*`.
- Populated add/rename/change lifecycles, index/constraint drops, identity and `it_introspects_column_*` / `it_introspects_index*` tests.
- `it_uses_native_boolean_*`, `it_supports_time_zone_*` and the unsupported-operation provider.

Remaining uncertainties are called out in the rows rather than promoted to tested support. In particular, a passing smoke migration does not establish every Blueprint modifier, arbitrary ALTER conversion, transactional DDL rollback, every identifier/client result-name combination, or behavior outside the tested cases.

## Platform boundaries

- Integer/unsigned helpers use signed Firebird types; no unsigned range guarantee.
- JSON/JSONB are limited text-storage mappings, not native JSON operators or a
  fully integration-tested JSON migration contract. ENUM is the tested CHECK
  emulation described above, not a native ENUM type.
- Some bound parameters in raw expressions require explicit CASTs. The driver
  does not infer their types or rewrite raw SQL; see the [README](../readme.md#parameters-in-raw-sql-expressions).
- `EmptyBlobBehaviorTest.php` allows either `''` or `null` when PHP/PDO fetches an
  empty BLOB. Stored SQL NULL and a zero-length non-NULL BLOB are distinct in the
  database; a uniform fetched PHP value across clients is not promised.
- The grammar reports `supportsSchemaTransactions() = false`; Laravel migrations
  are not automatically transactional. Schema-wipe transaction guards and tested
  failure rollback apply specifically to those wipe APIs, not all migration DDL.

## Stock Laravel 13 database configuration

All three unchanged Laravel 13 standard migrations succeeded with the published
beta on Firebird 5.0.4, **16384-byte pages**, **UTF8** charset/collation; a second
migration run had nothing to do. The tested consumer used `laravel/laravel v13.10.1`
and Laravel Framework `v13.33.0`. At 8192-byte pages the original `failed_jobs`
composite index (`connection`, `queue`, `failed_at`) reproduced Firebird's
`key size exceeds implementation restriction`. This is a platform index-size
limit for that schema, not evidence of incorrect driver SQL. It does not establish
a universal 16-KiB minimum. See the [creation guidance](../readme.md#database-creation-for-stock-laravel-13-migrations)
and [issue #112](https://github.com/MrSpockDe/laravel-firebird/issues/112).

## Unix timestamps and the 2038 horizon

`integer()` and `unsignedInteger()` currently map to signed Firebird INTEGER.
For Unix seconds this covers positive dates through January 2038. Applications
with a longer horizon can deliberately use BIGINT in their own schema, retaining
each column's nullability. This is an application-schema recommendation, not a
change to the driver's general type mapping or a claim that unchanged Laravel
standard migrations currently fail.

For the validated Laravel 13 / OweFlow schema, the seven relevant standard columns are:

| Column | Preserve nullability |
| --- | --- |
| `sessions.last_activity` | NOT NULL |
| `jobs.reserved_at` | NULL |
| `jobs.available_at` | NOT NULL |
| `jobs.created_at` | NOT NULL |
| `job_batches.cancelled_at` | NULL |
| `job_batches.created_at` | NOT NULL |
| `job_batches.finished_at` | NULL |

`cache.expiration` and `cache_locks.expiration` were already BIGINT in the inspected
consumer. `failed_jobs.failed_at` is a native TIMESTAMP, not an INTEGER Unix-time
column; none of these three belongs in the conversion list.

## Evidence map

The five RC1 audit gaps were closed by `7a68ea3` in `MigrationTest.php`:
`rc1StorageHelpers`, `it_preserves_decimal_precision_scale_and_signed_values`
and `it_drops_timezone_timestamps_without_losing_existing_data`. Together they
passed 8 tests / 58 assertions per Firebird version; the full migration file
passed 164 tests / 1365 assertions per version. These are no longer open RC1 gaps.

Additional focused evidence: [SchemaTest](../tests/SchemaTest.php),
[ViewIntrospectionTest](../tests/ViewIntrospectionTest.php),
[DropAllSchemaObjectsTest](../tests/DropAllSchemaObjectsTest.php),
[SchemaCommentTest](../tests/SchemaCommentTest.php),
[VirtualColumnTest](../tests/VirtualColumnTest.php),
[IdentityOptionsTest](../tests/IdentityOptionsTest.php),
[IdentifierNormalizationTest](../tests/IdentifierNormalizationTest.php),
[ForeignKeyActionTest](../tests/ForeignKeyActionTest.php),
[UseCurrentTest](../tests/UseCurrentTest.php),
[BinaryTypeTest](../tests/BinaryTypeTest.php),
[FloatPrecisionTest](../tests/FloatPrecisionTest.php),
[EnumDefaultTest](../tests/EnumDefaultTest.php) and
[EnumEscapingTest](../tests/EnumEscapingTest.php).
