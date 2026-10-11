# Schema v1

Actual WordPress prefix is always used. Plugin tables use InnoDB, UTC Unix timestamps, explicit indexed keys and JSON stored in longtext for portability. There are no product price UPDATE statements; product prices use WooCommerce CRUD.

| table suffix | role | primary/indexes |
|---|---|---|
| vsp_mappings | one simple product or independent variation mapping | product_id PK; due(next_due, product_id); source_key; group/status |
| vsp_rules | immutable content-hashed rule configurations | id PK |
| vsp_groups | schedule and freshness policy | group_key PK |
| vsp_snapshots | immutable sanitized provider result/embedded offers | id PK; UNIQUE(source_key,window_id); retention(created_at) |
| vsp_proposals | immutable decision, before hash, resolved rule/match versions | id PK; product/status; created_at |
| vsp_changes | before/after, intent/applied/conflict, actor, evidence and rollback link | deterministic id PK; product/time; status |
| vsp_usage | actual HTTP attempts, result, latency, nullable billable units | auto id PK; provider/time |
| vsp_locks | atomic owner-specific lease | lock_key PK; expires_at |
| vsp_imports | dry-run rows/errors, optimistic baseline, cursors and backups | id PK; created_at |

Offers are embedded in snapshots instead of an additional table: the current public parser reads six offers, with a maximum 500. This avoids unused tables but retains all exclusion reasons in the decision record. Full raw responses, cookies and tracking parameters are never stored.

Migration is versioned and repeatable using dbDelta; activation/update/deactivation preserve rows. Missing WooCommerce produces a dependency error, not a fatal exception. Uninstall preserves data unless the explicit deletion setting is enabled.

Snapshot retention defaults to 7 days; pending proposals expire after 7 days and protect snapshots until expired. Usage and terminal imports are pruned after 90 days in batches of 100. Price-change audit and rule/proposal history are retained in this release; **configurable audit retention and compacting large histories are still pending**. Monitor database size during a pilot. Benchmark reports measured growth, not a guessed production database size.

All tables hold bounded individual jobs; admin lists have pagination (20/page). Search in mapping JSON is escaped LIKE and intentionally conservative; it is not a full-text indexed SKU query. Large catalogs with complex filtering need indexed attribute columns in a later migration. Import manifests are bounded by 2MB upload/10,000 rows and are processed in 100/50-row chunks; the manifest itself is loaded to update its cursor, so its memory/serialization cost is included in the benchmark.
