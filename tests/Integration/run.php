<?php
/** Run with wp eval-file, only in isolated WP_ENVIRONMENT_TYPE=local. */
if (!defined("ABSPATH") || wp_get_environment_type() !== "local") {
    throw new RuntimeException("Local test environment required");
}
define("VSP_TEST_HARNESS", true);
require dirname(__DIR__) . "/bootstrap.php";
use Viratel\SmartPricing\Infrastructure\{
    Repository,
    MappingService,
    Settings,
    ProposalService,
    PriceApplier,
    ProductState,
    Lock,
    Installer,
};
use Viratel\SmartPricing\ImportExport\CsvService;
use Viratel\SmartPricing\Queue\{QueueService, BudgetService};
use Viratel\SmartPricing\Providers\{FetchClient, TorobProvider};
$repo = new Repository();
$maps = new MappingService();
$applier = new PriceApplier();
$defaults = Settings::get();
check(
    $defaults["mode"] === "monitor" && !$defaults["source_configured"],
    "installation is passive monitoring",
);
$s = array_merge($defaults, [
    "mode" => "suggest",
    "paused" => false,
    "currency" => "IRT",
    "own_domains" => ["viratel.example"],
    "daily_budget" => 10000,
    "monthly_budget" => 30000,
]);
update_option("vsp_settings", $s, false);
update_option("vsp_active", true, false);
update_option("woocommerce_currency", "IRT");
$rule = [
    "currency" => "IRT",
    "floor" => "450000",
    "ceiling" => "600000",
    "type" => "fixed_below",
    "delta" => "1000",
    "round_step" => "1",
    "min_competitors" => 2,
    "max_change_bps" => 10000,
    "max_daily_change" => "2000000",
    "cooldown" => 0,
];
$mapping = [
    "source_url" =>
        "https://torob.com/p/1189b812-9de1-4016-b72d-fa7d3292ff9b/battery/",
    "attributes" => ["model" => "Mi10T", "quality" => "OEM"],
    "match_status" => "confirmed",
    "mode" => "suggest",
    "schedule_group" => "normal",
];
$newProduct = function ($regular = "500000", $sale = "") use (
    $maps,
    $mapping,
    $rule,
) {
    $p = new WC_Product_Simple();
    $p->set_name("VSP TEST ONLY");
    $p->set_sku("vsp-test-" . wp_generate_uuid4());
    $p->set_regular_price($regular);
    if ($sale !== "") {
        $p->set_sale_price($sale);
    }
    $p->set_stock_status("instock");
    $p->save();
    $maps->save($p->get_id(), $mapping, $rule);
    return $p->get_id();
};
$makeProposal = function ($id, $price = "500000", $test = true) use ($repo) {
    $now = time();
    $data = [
        "provider" => "fixture",
        "canonical_product_id" => "fixture-" . wp_generate_uuid4(),
        "source_url" => "https://example.invalid/test",
        "status" => "ok",
        "freshness" => "known",
        "source_observed_at" => gmdate("c", $now),
        "fetched_at" => gmdate("c", $now),
        "coverage" => "complete",
        "currency" => "IRT",
        "is_test" => $test,
        "offers" => [],
    ];
    foreach (["a", "b"] as $seller) {
        $data["offers"][] = [
            "offer_id" => $seller,
            "store_id" => $seller,
            "seller_url" => "https://seller-" . $seller . ".example",
            "price" => $price,
            "currency" => "IRT",
            "in_stock" => true,
            "observed_at" => gmdate("c", $now),
            "attributes" => ["model" => "Mi10T", "quality" => "OEM"],
        ];
    }
    $sid = wp_generate_uuid4();
    $repo->insert("snapshots", [
        "id" => $sid,
        "source_key" => wp_generate_uuid4(),
        "window_id" => $now,
        "data" => $data,
        "created_at" => $now,
    ]);
    return (new ProposalService())->create($id, $sid);
};
$id = $newProduct();
$proposal = $makeProposal($id);
check(
    $repo->get("proposals", $proposal)["status"] === "ready",
    "fresh local fixture proposal ready",
);
$change = $applier->apply($proposal, true);
check(
    ProductState::read($id)["effective"] === "499000",
    "real Woo CRUD regular write",
);
check($repo->get("changes", $change)["status"] === "applied", "durable audit");
check(
    $applier->apply($proposal, true) === $change,
    "duplicate proposal idempotent",
);
$rollback = $applier->rollback($change);
check(
    ProductState::read($id)["effective"] === "500000",
    "conditional rollback",
);
check(
    $applier->rollback($change) === $rollback,
    "rollback duplicate idempotent",
);
$id2 = $newProduct();
$p2 = $makeProposal($id2);
$obj = wc_get_product($id2);
$obj->set_regular_price("510000");
$obj->save();
check(
    $repo->get("mappings", $id2)["data"]["manual_lock_until"] > time(),
    "manual price change sets 24h lock",
);
raises(
    fn() => $applier->apply($p2, true),
    "price_conflict",
    "manual edit prevents overwrite",
);
$maps->unlock($id2);
$id3 = $newProduct();
$p3 = $makeProposal($id3);
$maps->save($id3, $mapping, array_merge($rule, ["delta" => "2000"]));
raises(
    fn() => $applier->apply($p3, true),
    "version_conflict",
    "immutable rule version mismatch",
);
$id4 = $newProduct();
$p4 = $makeProposal($id4);
$s["paused"] = true;
update_option("vsp_settings", $s, false);
raises(
    fn() => $applier->apply($p4, true),
    "writes_disabled",
    "pause prevents writes",
);
$s["paused"] = false;
update_option("vsp_settings", $s, false);
$pause = function () use (&$s) {
    $s["paused"] = true;
    update_option("vsp_settings", $s, false);
};
add_action("vsp_test_after_intent", $pause);
raises(
    fn() => $applier->apply($p4, true),
    "paused",
    "pause between intent and write",
);
remove_action("vsp_test_after_intent", $pause);
check(
    ProductState::read($id4)["effective"] === "500000",
    "mid-job pause preserves price",
);
$s["paused"] = false;
update_option("vsp_settings", $s, false);
$id5 = $newProduct();
$p5 = $makeProposal($id5);
$crash = function () {
    throw new RuntimeException("simulated_crash_after_save");
};
add_action("vsp_test_after_save", $crash);
raises(
    fn() => $applier->apply($p5, true),
    "simulated_crash",
    "crash after save simulated",
);
remove_action("vsp_test_after_save", $crash);
check(
    ProductState::read($id5)["effective"] === "499000",
    "price saved before crash",
);
$s["paused"] = true;
update_option("vsp_settings", $s, false);
$c5 = $applier->reconcile($p5);
check(
    ProductState::read($id5)["effective"] === "499000",
    "paused reconciliation only updates audit and never saves price",
);
$s["paused"] = false;
update_option("vsp_settings", $s, false);
check(
    $repo->get("changes", $c5)["status"] === "applied",
    "crash recovery reconciles saved price",
);
check(
    $repo->dailyChange($id5, strtotime("today UTC")) === 1000000,
    "recovery daily counter once",
);
$id6 = $newProduct();
$p6 = $makeProposal($id6);
$crash = function () {
    throw new RuntimeException("simulated_crash_before_save");
};
add_action("vsp_test_after_intent", $crash);
raises(
    fn() => $applier->apply($p6, true),
    "simulated_crash",
    "crash before save simulated",
);
remove_action("vsp_test_after_intent", $crash);
raises(
    fn() => $applier->apply($p6, true),
    "recovery_recalculate_required",
    "before-save crash never replays",
);
check(
    ProductState::read($id6)["effective"] === "500000",
    "before-save crash preserves price",
);
$id7 = $newProduct("550000", "500000");
$p7 = $makeProposal($id7);
check($repo->get("proposals", $p7)["status"] === "hold", "active sale hold");
raises(
    fn() => $applier->apply($p7, true),
    "proposal_not_ready",
    "sale effective price protected",
);
$id8 = $newProduct();
$p8 = $makeProposal($id8);
$c8 = $applier->apply($p8, true);
$obj = wc_get_product($id8);
$obj->set_regular_price("520000");
$obj->save();
raises(
    fn() => $applier->rollback($c8),
    "rollback_price_conflict",
    "rollback refuses later manual edit",
);
$id9 = $newProduct();
$p9 = $makeProposal($id9);
$c9 = $applier->apply($p9, true);
$maps->save($id9, $mapping, array_merge($rule, ["floor" => "510000"]));
raises(
    fn() => $applier->rollback($c9),
    "bounds_conflict",
    "rollback respects new floor",
);
$id10 = $newProduct();
$p10 = $makeProposal($id10);
$s["mode"] = "monitor";
update_option("vsp_settings", $s, false);
raises(
    fn() => $applier->apply($p10, true),
    "writes_disabled",
    "monitor blocks even manual write",
);
raises(
    fn() => $applier->reconcile($p10),
    "intent_missing",
    "recovery cannot create a new price intent",
);
$s["mode"] = "suggest";
update_option("vsp_settings", $s, false);
$parent = new WC_Product_Variable();
$parent->set_name("VSP TEST PARENT");
$parent->save();
$v = new WC_Product_Variation();
$v->set_parent_id($parent->get_id());
$v->set_regular_price("500000");
$v->set_stock_status("instock");
$v->save();
$maps->save($v->get_id(), $mapping, $rule);
$vp = $makeProposal($v->get_id());
$vc = $applier->apply($vp, true);
check(
    ProductState::read($v->get_id())["effective"] === "499000",
    "variation independent CRUD",
);
$parent = wc_get_product($parent->get_id());
check(
    Viratel\SmartPricing\Domain\Money::parse(
        $parent->get_variation_price("min", false),
        "IRT",
    ) === 499000000,
    "parent price synchronized",
);
raises(
    fn() => $maps->save($parent->get_id(), $mapping, $rule),
    "simple_or_variation",
    "variable parent refused",
);
$lease1 = new Lock();
$lease2 = new Lock();
check($lease1->acquire("test-lock", 60), "first worker claims");
check(!$lease2->acquire("test-lock", 60), "second worker cannot claim");
$lease2->release("test-lock");
check($lease1->held("test-lock"), "other owner cannot release");
$lease1->release("test-lock");
check($lease2->acquire("test-lock", 60), "released claim available");
$lease2->release("test-lock");
check($lease1->acquire("test-expiry", 0), "zero lease inserted");
check($lease2->acquire("test-expiry", 60), "expired lease reclaimed");
$lease1->release("test-expiry");
check(
    $lease2->held("test-expiry"),
    "old worker cannot release reclaimed lease",
);
$lease2->release("test-expiry");
$csv = new CsvService();
$csvId = $newProduct();
$sku = wc_get_product($csvId)->get_sku();
$header =
    "product_id,sku,source_url,quality,model,match_status,mode,floor_toman,ceiling_toman,rule_type,delta_value,delta_unit,schedule_group";
$row =
    $csvId .
    "," .
    $sku .
    "," .
    $mapping["source_url"] .
    ",OEM,Mi10T,confirmed,monitor,450000,600000,fixed_below,2000,toman,normal";
$import = $csv->stage($header . "\n" . $row . "\n");
raises(
    fn() => $csv->applyChunk($import),
    "dry_run_required",
    "CSV apply requires completed dry-run",
);
$job = $csv->validateChunk($import);
check($job["status"] === "validated", "CSV dry-run resolves ID and SKU");
$prior = ProductState::read($csvId);
$job = $csv->applyChunk($import);
check(
    $job["status"] === "applied" && ProductState::read($csvId) === $prior,
    "CSV applies settings only",
);
check(
    $repo->get("mappings", $csvId)["data"]["mode"] === "monitor",
    "CSV keeps monitoring mode",
);
$futureCostId = $newProduct();
$futureCostChange = $applier->apply($makeProposal($futureCostId), true);
$futureCostRule = $rule;
$futureCostRule["cost"] = "400000";
$futureCostRule["cost_observed_at"] = time() + 3600;
$maps->save($futureCostId, $mapping, $futureCostRule);
raises(
    fn() => $applier->rollback($futureCostChange),
    "rollback_cost_stale",
    "rollback rejects future or unknown cost freshness",
);
check(
    ProductState::read($futureCostId)["effective"] === "499000",
    "invalid cost rollback retains current price",
);
// A CSV upload date is never substituted for the real cost observation.
$costImport = $csv->stage(
    $header . ",cost_toman,cost_observed_at\n" . $row . ",400000,\n",
);
$costJob = $csv->validateChunk($costImport);
check(
    $costJob["data"]["rows"][0]["candidate"]["rule"]["cost_observed_at"] === 0,
    "CSV unknown cost timestamp stays unknown",
);
$costImport = $csv->stage(
    $header .
        ",cost_toman,cost_observed_at\n" .
        $row .
        ",400000," .
        time() .
        "\n",
);
$costJob = $csv->validateChunk($costImport);
check(
    $costJob["status"] === "validated" &&
        $costJob["data"]["rows"][0]["candidate"]["rule"]["cost_observed_at"] >
            0,
    "CSV explicit Unix cost timestamp preserved",
);
$costImport = $csv->stage(
    $header . ",cost_toman,cost_observed_at\n" . $row . ",400000,today\n",
);
check(
    $csv->validateChunk($costImport)["status"] === "invalid",
    "CSV relative cost timestamp rejected",
);
$duplicate = $csv->stage($header . "\n" . $row . "\n" . $row . "\n");
check(
    $csv->validateChunk($duplicate)["data"]["errors"][0]["reason"] ===
        "duplicate_product",
    "CSV duplicate reported",
);
$conflict = $csv->stage(
    $header . "\n" . str_replace($sku, "missing-sku", $row) . "\n",
);
check(
    $csv->validateChunk($conflict)["data"]["errors"][0]["reason"] ===
        "sku_id_conflict",
    "CSV mismatch reported",
);
$conflict = $csv->stage($header . "\n" . $row . "\n");
$csv->validateChunk($conflict);
$maps->save($csvId, $mapping, $rule);
raises(
    fn() => $csv->applyChunk($conflict),
    "mapping_conflict",
    "CSV optimistic configuration conflict",
);
$before = $repo->count("mappings");
delete_option("vsp_schema");
Installer::migrate();
check(
    $repo->count("mappings") === $before,
    "repeat migration preserves mappings",
);
Installer::deactivate();
check($repo->count("mappings") === $before, "deactivation retains data");
check(!get_option("vsp_active"), "deactivation stops jobs");
Installer::activate();
check($repo->count("mappings") === $before, "reactivation retains history");
$uid = wp_create_user(
    "vsp-subscriber-" . wp_generate_uuid4(),
    wp_generate_password(),
    "",
);
$user = new WP_User($uid);
$user->set_role("subscriber");
wp_set_current_user($uid);
$res = rest_do_request(new WP_REST_Request("GET", "/vsp/v1/status"));
check(
    $res->get_status() === 401 || $res->get_status() === 403,
    "REST read denied without capability",
);
$res = rest_do_request(new WP_REST_Request("POST", "/vsp/v1/pause"));
check(
    in_array($res->get_status(), [401, 403], true),
    "REST pause denied without capability",
);
$admin = get_users(["role" => "administrator", "number" => 1])[0];
wp_set_current_user($admin->ID);
$res = rest_do_request(new WP_REST_Request("POST", "/vsp/v1/pause"));
check(
    in_array($res->get_status(), [401, 403], true),
    "REST write requires nonce",
);
$request = new WP_REST_Request("POST", "/vsp/v1/pause");
$request->set_header("X-WP-Nonce", wp_create_nonce("wp_rest"));
$res = rest_do_request($request);
check(
    $res->get_status() === 200 && Settings::get()["paused"],
    "authorized REST pause succeeds",
);
$s["paused"] = false;
update_option("vsp_settings", $s, false);
// Simulated transport errors are separate from the real live probe.
$transport = function ($response, $args, $url) {
    $case = get_option("vsp_test_http_case", "http_403");
    if ($case === "timeout") {
        return new WP_Error("http_request_failed", "timeout");
    }
    if ($case === "redirect") {
        return [
            "response" => ["code" => 302],
            "headers" => ["location" => "http://169.254.169.254/latest"],
            "body" => "",
        ];
    }
    if ($case === "large") {
        return [
            "response" => ["code" => 200],
            "headers" => [],
            "body" => str_repeat("x", 1048577),
        ];
    }
    if ($case === "login") {
        return [
            "response" => ["code" => 200],
            "headers" => [],
            "body" => "<title>Captcha</title>",
        ];
    }
    return [
        "response" => ["code" => (int) substr($case, 5)],
        "headers" => ["retry-after" => "120"],
        "body" => "",
    ];
};
add_filter("pre_http_request", $transport, 10, 3);
foreach (
    ["http_403", "http_429", "timeout", "redirect", "large", "login"]
    as $case
) {
    update_option("vsp_last_request", 0, false);
    update_option("vsp_circuit_until", 0, false);
    update_option("vsp_test_http_case", $case, false);
    $data = (new TorobProvider())->fetch($mapping["source_url"]);
    $expected =
        [
            "timeout" => "network_error",
            "redirect" => "redirect_forbidden",
            "large" => "response_too_large",
            "login" => "challenge_or_login",
        ][$case] ?? $case;
    check($data["status"] === $expected, "provider error " . $case);
    check(
        count($data["offers"]) === 0,
        "error has no fabricated offers " . $case,
    );
}
// Same canonical redirect is delayed; no second request bypasses spacing.
update_option("vsp_last_request", 0, false);
update_option("vsp_circuit_until", 0, false);
$redirectFilter = function () use ($mapping) {
    return [
        "response" => ["code" => 302],
        "headers" => [
            "location" => str_replace(
                "/battery/",
                "/canonical/",
                $mapping["source_url"],
            ),
        ],
        "body" => "",
    ];
};
remove_filter("pre_http_request", $transport, 10);
add_filter("pre_http_request", $redirectFilter, 10, 3);
$redirect = (new TorobProvider())->fetch($mapping["source_url"]);
check(
    $redirect["status"] === "redirect_pending" &&
        $redirect["retry_after"] >= 10,
    "canonical redirect uses delayed separate request",
);
remove_filter("pre_http_request", $redirectFilter, 10);
// A transient HTTP error must not poison the canonical-window cache.
$queueId = $newProduct();
$queueMap = $mapping;
$queueMap["source_url"] =
    "https://torob.com/p/" . wp_generate_uuid4() . "/battery/";
$queueMap["mode"] = "monitor";
$maps->save($queueId, $queueMap, $rule);
$s["source_configured"] = true;
update_option("vsp_settings", $s, false);
$calls = 0;
$retryFilter = function ($pre, $args, $url) use (&$calls) {
    $calls++;
    if ($calls === 1) {
        return ["response" => ["code" => 500], "headers" => [], "body" => ""];
    }
    $html = file_get_contents(
        dirname(__DIR__) . "/Fixtures/torob-public-jsonld.html",
    );
    $canonical = \Viratel\SmartPricing\Security\SourceUrl::validate($url);
    $html = str_replace(
        "1189b812-9de1-4016-b72d-fa7d3292ff9b",
        $canonical,
        $html,
    );
    return ["response" => ["code" => 200], "headers" => [], "body" => $html];
};
add_filter("pre_http_request", $retryFilter, 10, 3);
update_option("vsp_last_request", 0, false);
update_option("vsp_provider_failures", 0, false);
(new QueueService())->poll($queueId);
check(
    $repo->get("mappings", $queueId)["status"] === "http_500",
    "queue records failed real attempt",
);
update_option("vsp_last_request", 0, false); // Test-only clock reset; runtime preserves spacing.
(new QueueService())->poll($queueId, 1);
check(
    $calls === 2 &&
        $repo->get("mappings", $queueId)["status"] === "source_time_unknown",
    "retry refetches and records public snapshot without inventing freshness",
);
check(
    ProductState::read($queueId)["effective"] === "500000",
    "monitor retry never writes price",
);
remove_filter("pre_http_request", $retryFilter, 10);
as_unschedule_all_actions("vsp_poll", [$queueId, 1], QueueService::GROUP);
$s["source_configured"] = false;
update_option("vsp_settings", $s, false);
remove_filter("pre_http_request", $transport, 10);
update_option("vsp_last_request", 0, false);
$s["daily_budget"] = 0;
update_option("vsp_settings", $s, false);
raises(
    fn() => (new BudgetService())->reserve(),
    "budget_exhausted",
    "budget exhausted before network",
);
$s["daily_budget"] = 10000;
update_option("vsp_settings", $s, false);
update_option("vsp_circuit_until", time() + 60, false);
raises(
    fn() => (new BudgetService())->reserve(),
    "circuit_open",
    "circuit breaker",
);
update_option("vsp_circuit_until", 0, false);
update_option("vsp_last_request", time(), false);
raises(
    fn() => (new BudgetService())->reserve(),
    "rate_limited",
    "request spacing",
);
update_option("vsp_last_request", 0, false);
$s["source_configured"] = false;
update_option("vsp_settings", $s, false);
$usage = $repo->count("usage");
(new QueueService())->poll($id);
check(
    $repo->count("usage") === $usage,
    "unconfigured source never requests automatically",
);
$s["mode"] = "suggest";
update_option("vsp_settings", $s, false);
$beforeCrashSnapshot = $repo->get("proposals", $p6)["snapshot_id"];
$newAfterCrash = (new ProposalService())->create($id6, $beforeCrashSnapshot);
check(
    $newAfterCrash !== $p6,
    "new calculation replaces cancelled pre-save intent",
);
$applier->apply($newAfterCrash, true);
check(
    ProductState::read($id6)["effective"] === "499000",
    "recalculated proposal can execute after pre-save crash",
);
// Configuration rollback is independent of product price rollback.
$rollId = $newProduct();
$rollSku = wc_get_product($rollId)->get_sku();
$rollRow =
    $rollId .
    "," .
    $rollSku .
    "," .
    $mapping["source_url"] .
    ",OEM,Mi10T,confirmed,monitor,450000,600000,fixed_below,2000,toman,normal";
$previous = $repo->get("mappings", $rollId);
$manifest = $csv->stage($header . "\n" . $rollRow . "\n");
$csv->validateChunk($manifest);
$csv->applyChunk($manifest);
$csv->rollbackChunk($manifest);
check(
    $repo->get("mappings", $rollId)["rule_version"] ===
        $previous["rule_version"],
    "CSV conditional configuration rollback",
);
check(
    $csv->rollbackChunk($manifest)["status"] === "rolled_back",
    "CSV rollback idempotent",
);
check(
    ProductState::read($rollId)["effective"] === "500000",
    "CSV rollback never changes price",
);
$manifest = $csv->stage($header . "\n" . $rollRow . "\n");
$csv->validateChunk($manifest);
$csv->applyChunk($manifest);
$maps->save($rollId, $mapping, $rule);
raises(
    fn() => $csv->rollbackChunk($manifest),
    "rollback_conflict",
    "CSV rollback refuses subsequent mapping",
);
$manifest = $csv->stage($header . "\n" . $rollRow . "\n");
$csv->validateChunk($manifest);
$previous = $repo->get("mappings", $rollId);
$crashImport = function () {
    throw new RuntimeException("simulated_import_crash");
};
add_action("vsp_test_import_after_mapping", $crashImport);
raises(
    fn() => $csv->applyChunk($manifest),
    "simulated_import_crash",
    "CSV crash between mapping and cursor",
);
remove_action("vsp_test_import_after_mapping", $crashImport);
check(
    $repo->get("mappings", $rollId)["config"] === $previous["config"],
    "CSV transaction rolls back incomplete row",
);
check(
    $csv->applyChunk($manifest)["status"] === "applied",
    "CSV retry after transaction rollback",
);
// A policy change after intent must hold a write even when its amount is unchanged.
$s["mode"] = "suggest";
update_option("vsp_settings", $s, false);
$policyId = $newProduct();
$policyProposal = $makeProposal($policyId);
$policyMutation = function () use ($repo) {
    $g = $repo->get("groups", "normal")["data"];
    $g["max_source_age"] = 1;
    $repo->update("groups", "normal", ["data" => $g]);
};
add_action("vsp_test_after_intent", $policyMutation);
raises(
    fn() => $applier->apply($policyProposal, true),
    "pre_save_policy_conflict",
    "group policy mutation revalidated before save",
);
remove_action("vsp_test_after_intent", $policyMutation);
$repo->update("groups", "normal", [
    "data" => ["interval" => 21600, "max_source_age" => 21600],
]);
check(
    ProductState::read($policyId)["effective"] === "500000",
    "changed policy never writes",
);
// The same snapshot can produce a new decision when pause is lifted.
$decisionId = $newProduct();
$decisionProposal = $makeProposal($decisionId);
$sid = $repo->get("proposals", $decisionProposal)["snapshot_id"];
$s["paused"] = true;
update_option("vsp_settings", $s, false);
$held = (new ProposalService())->create($decisionId, $sid);
$s["paused"] = false;
update_option("vsp_settings", $s, false);
$ready = (new ProposalService())->create($decisionId, $sid);
check(
    $held !== $ready && $repo->get("proposals", $ready)["status"] === "ready",
    "decision ID accounts for context changes",
);
// A manager query cannot turn arbitrary input into SQL.
$beforeCount = $repo->count("mappings");
$repo->page("mappings", 1, 20, "%' OR 1=1; DROP TABLE vsp_mappings; --");
check(
    $repo->count("mappings") === $beforeCount,
    "prepared search preserves schema",
);
$s["mode"] = "suggest";
$s["currency"] = "IRT";
update_option("vsp_settings", $s, false);
$saleId = $newProduct("550000", "500000");
$maps->save($saleId, $mapping, array_merge($rule, ["write_mode" => "sale"]));
$saleProposal = $makeProposal($saleId);
$saleChange = $applier->apply($saleProposal, true);
$saleState = ProductState::read($saleId);
check(
    $saleState["regular"] === "550000" &&
        $saleState["sale"] === "499000" &&
        $saleState["effective"] === "499000",
    "explicit existing-sale policy preserves regular price",
);
$applier->rollback($saleChange);
check(
    ProductState::read($saleId)["sale"] === "500000",
    "existing sale rollback",
);
$mutateId = $newProduct();
$mutateProposal = $makeProposal($mutateId);
$mutator = function ($object) use ($mutateId) {
    if ($object->get_id() === $mutateId) {
        $object->set_regular_price("700000");
    }
};
add_action("woocommerce_before_product_object_save", $mutator, 50, 1);
raises(
    fn() => $applier->apply($mutateProposal, true),
    "save_mutated_by_other_plugin",
    "another plugin mutating save is refused",
);
remove_action("woocommerce_before_product_object_save", $mutator, 50);
check(
    ProductState::read($mutateId)["effective"] === "500000",
    "mutated save preserves previous price",
);
$s["currency"] = "IRR";
update_option("vsp_settings", $s, false);
update_option("woocommerce_currency", "IRR");
$rialId = $newProduct("5000000");
$rialProposal = $makeProposal($rialId);
$rialChange = $applier->apply($rialProposal, true);
check(
    ProductState::read($rialId)["effective"] === "4990000",
    "Woo IRR conversion applied exactly once",
);
$applier->rollback($rialChange);
check(
    ProductState::read($rialId)["effective"] === "5000000",
    "Woo IRR rollback",
);
$s["currency"] = "IRT";
update_option("vsp_settings", $s, false);
update_option("woocommerce_currency", "IRT");
$hashId = $newProduct();
$hashRow = $repo->get("mappings", $hashId);
$maps->save(
    $hashId,
    array_merge($mapping, [
        "attributes" => ["quality" => "copy", "model" => "Mi10T"],
    ]),
    $rule,
);
raises(
    fn() => $maps->save(
        $hashId,
        $mapping,
        $rule,
        $hashRow["rule_version"],
        hash("sha256", $hashRow["config"]),
    ),
    "mapping_conflict",
    "same-rule concurrent mapping edit cannot be overwritten",
);
// Restore safe local defaults after tests.
$s["mode"] = "monitor";
$s["paused"] = false;
$s["source_configured"] = false;
update_option("vsp_settings", $s, false);
echo "Assertions: " . $GLOBALS["vsp_assertions"] . "\n";
