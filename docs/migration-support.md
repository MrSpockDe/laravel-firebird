# Laravel migration support

This document tracks Laravel schema and migration compatibility for this fork.

Legend:

- ✅ supported
- ⚠️ partially supported or emulated
- ❌ not yet supported
- ➖ no meaningful/native Firebird equivalent

The status below describes the current baseline inherited from the upstream `4.x` branch. Items will be changed to ✅ only after integration tests verify the resulting Firebird metadata.

## Tables and introspection

| Laravel feature | Status | Notes |
| --- | :---: | --- |
| `Schema::create()` | ✅ | Basic create grammar exists |
| `Schema::drop()` | ✅ | |
| `Schema::dropIfExists()` | ✅ | Uses a Firebird execute block |
| `Schema::hasTable()` | ✅ | |
| `Schema::getTableListing()` | ✅ | |
| `Schema::hasColumn()` | ✅ | |
| `Schema::getColumnListing()` | ✅ | |
| `Schema::getColumns()` | ⚠️ | Metadata is incomplete |
| `Schema::getColumnType()` | ⚠️ | Depends on complete column metadata |
| `Schema::getIndexes()` / `hasIndex()` | ❌ | |
| `Schema::getForeignKeys()` / `hasForeignKey()` | ❌ | |
| View introspection | ❌ | |
| `Schema::dropAllTables()` | ❌ | |

## Identity and integer columns

| Laravel feature | Status | Notes |
| --- | :---: | --- |
| `$table->id()` | ❌ | Firebird identity generation is not compiled yet |
| `$table->increments()` | ❌ | |
| `$table->bigIncrements()` | ❌ | |
| Integer types | ⚠️ | Basic types exist; Firebird has no unsigned integer semantics |
| `foreignId()` | ⚠️ | BIGINT column works; identity/unsigned semantics require review |

## Column operations

| Laravel feature | Status | Notes |
| --- | :---: | --- |
| Add column | ✅ | |
| `dropColumn()` | ❌ | |
| `renameColumn()` | ❌ | Requires Firebird `ALTER COLUMN old TO new` syntax |
| `change()` | ❌ | Type/nullability/default changes require Firebird-specific grammar |
| `nullable()` | ✅ | |
| `default()` | ✅ | |
| `charset()` | ✅ | |
| `collation()` | ✅ | |
| `comment()` | ❌ | |
| `useCurrent()` | ⚠️ | Timestamp-specific implementation exists |
| `useCurrentOnUpdate()` | ❌ | |

## Indexes and constraints

| Laravel feature | Status | Notes |
| --- | :---: | --- |
| Primary key | ✅ | |
| Unique constraint | ✅ | |
| Regular index | ✅ | |
| `dropPrimary()` | ❌ | |
| `dropUnique()` | ❌ | |
| `dropIndex()` | ❌ | |
| Rename index | ❌ | May require drop/recreate |
| Foreign key | ✅ | |
| `cascadeOnDelete()` | ✅ | |
| `cascadeOnUpdate()` | ✅ | |
| `dropForeign()` | ✅ | |
| `foreignId()->constrained()` | ⚠️ | Core FK grammar exists; integration coverage required |
| `dropConstrainedForeignId()` | ❌ | Depends on drop-column support |

## Data types

| Laravel feature | Status | Notes |
| --- | :---: | --- |
| `string()` / `char()` | ✅ | |
| Text variants | ✅ | Mapped to text BLOBs |
| `decimal()` | ✅ | |
| `float()` | ⚠️ | Laravel precision semantics need review |
| `double()` | ✅ | |
| `boolean()` | ⚠️ | Currently emulated as `CHAR(1)`; Firebird 4/5 support native BOOLEAN |
| `enum()` | ⚠️ | Emulated using VARCHAR + CHECK |
| `json()` | ⚠️ | Emulated storage; not native JSON semantics |
| `jsonb()` | ⚠️ | Emulated storage |
| `date()` | ✅ | |
| `time()` | ✅ | |
| `dateTime()` / `timestamp()` | ✅ | |
| `timeTz()` | ⚠️ | Currently loses timezone semantics |
| `dateTimeTz()` / `timestampTz()` | ⚠️ | Currently loses timezone semantics |
| `binary()` | ⚠️ | BLOB mapping; length/fixed semantics need review |
| `uuid()` | ✅ | Character representation |
| `ulid()` | ✅ | Character representation |
| `ipAddress()` | ✅ | |
| `macAddress()` | ✅ | |
| `year()` | ❌ | |
| `geometry()` | ❌ | |
| `vector()` | ➖ | No equivalent Laravel vector-index implementation |

## Convenience helpers

| Laravel feature | Status | Notes |
| --- | :---: | --- |
| `timestamps()` | ⚠️ | Expected to work; needs migration integration tests |
| `timestampsTz()` | ⚠️ | Timezone semantics incomplete |
| `softDeletes()` | ⚠️ | Expected to work; needs integration tests |
| `softDeletesTz()` | ⚠️ | Timezone semantics incomplete |
| `rememberToken()` | ⚠️ | Needs integration test |
| `morphs()` / `nullableMorphs()` | ⚠️ | Creation likely works; drop path incomplete |
| `dropMorphs()` | ❌ | Depends on drop-index/drop-column support |

## Acceptance criteria

A feature is marked fully supported only when tests verify both the Laravel operation and the resulting Firebird schema metadata. Unsupported features should throw a clear exception when a safe mapping is not possible.
