# Cron and CLI

On the actual staging host, use its documented PHP/WP-CLI paths and checkout directory. This cloud task already has an isolated checkout; do not create a Git worktree unless explicitly requested.

For normal WordPress hosts, server cron can run due WordPress events every minute:

```sh
wp --path=/path/to/wordpress cron event run --due-now
```

For a bounded plugin-specific CLI worker, use the tested hook filter:

```sh
wp --path=/path/to/wordpress action-scheduler run --hooks=vsp_dispatch,vsp_poll,vsp_retention,vsp_import_validate,vsp_import_apply,vsp_import_rollback --batch-size=25 --batches=4 --free-memory-on=25
```

Keep ordinary WooCommerce cron/queue runners active separately. The plugin registers group `viratel-smart-pricing`; it does not replace WooCommerce's scheduler or increase its global concurrency. Standard `--group=viratel-smart-pricing` is supported by Action Scheduler, but the tested AS 4.1.0 CLI exhibited a group-resolution/cache issue; hook filtering was the verified path, documented in the benchmark report. Do not patch WooCommerce/Action Scheduler vendor code for this.

In this local cloud environment:

```sh
bash /workspace/torob-slaes/tools/setup-runtime.sh
bash /workspace/torob-slaes/tools/start-runtime.sh
/workspace/vsp-runtime/bin/wp vsp status
/workspace/vsp-runtime/bin/wp vsp dispatch
/workspace/vsp-runtime/bin/wp vsp import /path/to/file.csv
/workspace/vsp-runtime/bin/wp vsp import-apply IMPORT_ID --reviewed
/workspace/vsp-runtime/bin/wp vsp pause
```

The local runtime blocks background async runners during benchmarks with an external mu-plugin. It is **not included in the install ZIP** and should not be copied to production. Benchmarks use a separately loaded local-only fixture callback. Required services after a snapshot restoration: the Unix-socket MariaDB daemon and, for UI tests only, the loopback PHP dev server. Local SELECT, WP installation status and functional CLI status verify readiness. Do not expose database ports; no daemon or credential is needed on the real shop beyond its existing WordPress services.

A paused source monitor or unconfigured source does not request Torob. Daily/monthly request ceilings, spacing, circuits and Retry-After constrain polls; queue jobs do not override these policies. Configure source budget before any large batch. Setting DISABLE_WP_CRON without a real cron/CLI runner prevents reliable processing; the UI reports heartbeat.
