# Changelog

## 2.2.0 — 2026-10-20

- Add `DB_EMULATE_PREPARES` (bool, default off) for MySQL/MariaDB. When on, PDO
  emulates prepared statements, so each query is one round trip instead of
  three. The adapter binds parameters by PHP type in that mode so `LIMIT`/`OFFSET`
  placeholders keep working. The default is unchanged.
