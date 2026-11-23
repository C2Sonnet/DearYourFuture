# Schema 9 · additive-only transition plan (development only)

`app/Core/SchemaUpgrade.php` owns the explicit v1–v9 table-addition inventory. The original v1–v8 `CREATE TABLE` definitions were checked against the archived Source Repository ZIPs and fingerprinted in `historical_schema.json`.

Checks: `python3 tools/sql_static_check.py` (structural MySQL DDL), `python3 tools/schema_history_check.py` (archived DDL unchanged), `php tests/run.php` (pure migration plan). With a **separate empty** disposable `kmm_test_*` database and `pdo_mysql`, run `php tests/mysql_upgrade_integration.php`; it preserves an old v7 user and upgrades to v9, without DROP/ALTER. This was not executed in the current environment.

Production upgrade is **not approved**: use offline pinned-key verification, full MySQL backup and maintenance lock before running `php tools/upgrade/migrate.php --apply --confirm`. Never manually overwrite a live installation or run CREATE TABLE IF NOT EXISTS as an ad hoc substitute for migration. Read `docs/UPDATE_AND_RECOVERY.md`.

## v0.1.11

Schema11＝45テーブル。v10から`seed_entity_states`、`seed_entity_overlays`、`seed_entity_conflicts`、`message_sender_states`の4件を追加し、既存41テーブルの定義は不変。実DBには未適用（MySQL/PDO不在）。
