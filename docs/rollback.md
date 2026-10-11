# Rollback and recovery

Price changes and CSV mapping imports are separate histories. All production operations require an explicit deployment/pilot authorization; none occurred here.

For a price change, choose History -> conditional rollback. The current product state must match the original after hash; otherwise a conflict is returned. The restoration must satisfy current fixed/dynamic floor and ceiling and preserve sale dates. It uses WooCommerce CRUD, the same product lease and a separate deterministic rollback intent. The plugin cannot overwrite a later manual edit.

For a crash after CRUD but before the audit update, repeat the same proposal application or `wp vsp recover PROPOSAL_ID` in an authorized environment. Exact after state reconciles audit without another price write. Exact before state cancels intent and requires a new calculation. Any different state is a conflict. The CLI recover command only reconciles an existing intent, even while paused; it cannot create a price intent or save product prices. A ready proposal without an existing intent returns intent_missing. Paused/monitor mode will not perform price writes.

For CSV configuration rollback, use the import row action. It processes 50 product mappings per task and uses InnoDB transaction per row to commit mapping restoration and cursor together. A later mapping version/import marker refuses rollback. Previous manual lock is preserved if a current product price edit has established a stronger lock. New mappings created by the import are removed; existing previous settings restored. No WooCommerce price is restored by this operation.

Before staging upgrades, back up plugin tables and normal WordPress/WooCommerce data with the site's existing backup procedure. Do not confuse deactivation (stops tasks, retains data) with uninstall. Default uninstall retains all data; the explicit deletion option must be deliberately enabled for destructive deletion. Restoring a database backup is a host operation, not an automatic UI price rollback.

External writers remain a documented race limitation: the plugin coordinates its own workers and observes WooCommerce hooks, but cannot guarantee atomicity against unrelated direct database writes or commits after the final guard. Coordinate ownership of prices before a pilot.
