<?php
namespace Viratel\SmartPricing\CLI;
use Viratel\SmartPricing\Infrastructure\{Repository, Settings, PriceApplier};
use Viratel\SmartPricing\ImportExport\CsvService;
use Viratel\SmartPricing\Queue\QueueService;
final class Commands
{
    /** Show readiness and observed provider limitations. */
    public function status(): void
    {
        \WP_CLI::line(
            wp_json_encode(
                [
                    "settings" => Settings::get(),
                    "products" => (new Repository())->count("mappings"),
                    "providers" => (new \Viratel\SmartPricing\Providers\ProviderRegistry())->statuses(),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
    /** Enqueue bounded source monitoring jobs. */
    public function dispatch(): void
    {
        (new QueueService())->dispatch();
        \WP_CLI::success("Monitoring jobs queued.");
    }
    /** Validate CSV; never changes product prices.
     * ## OPTIONS
     * <file>
     */
    public function import($args, $assoc): void
    {
        $csv = new CsvService();
        $id = $csv->stage(file_get_contents($args[0]));
        do {
            $job = $csv->validateChunk($id);
        } while ($job["status"] === "validating");
        \WP_CLI::line(
            wp_json_encode(
                [
                    "import_id" => $id,
                    "status" => $job["status"],
                    "errors" => $job["data"]["errors"],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
    /** Apply only the settings of an explicitly reviewed CSV import.
     * ## OPTIONS
     * <import_id>
     * --reviewed
     */
    public function import_apply($args, $assoc): void
    {
        if (empty($assoc["reviewed"])) {
            \WP_CLI::error("Review the dry-run before --reviewed.");
        }
        $csv = new CsvService();
        do {
            $job = $csv->applyChunk($args[0]);
        } while ($job["status"] === "applying");
        \WP_CLI::success("Settings imported; prices unchanged.");
    }
    /** Export CSV to stdout. */
    public function export(): void
    {
        (new CsvService())->export();
    }
    /** Pause all price writes immediately. */
    public function pause(): void
    {
        $s = Settings::get();
        $s["paused"] = true;
        update_option("vsp_settings", $s, false);
        \WP_CLI::success("Price writes paused.");
    }
    /** Reconcile interrupted intents without replaying unverified writes.
     * ## OPTIONS
     * <proposal_id>
     */
    public function recover($args): void
    {
        try {
            \WP_CLI::line((new PriceApplier())->reconcile($args[0]));
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());
        }
    }
}
