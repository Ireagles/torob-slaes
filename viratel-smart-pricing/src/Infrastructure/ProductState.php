<?php
namespace Viratel\SmartPricing\Infrastructure;
final class ProductState
{
    public static function read(int $id): array
    {
        clean_post_cache($id);
        wp_cache_delete("product-" . $id, "products");
        $p = wc_get_product($id);
        if (!$p || !in_array($p->get_type(), ["simple", "variation"], true)) {
            throw new \RuntimeException("product_invalid");
        }
        return [
            "regular" => $p->get_regular_price("edit"),
            "sale" => $p->get_sale_price("edit"),
            "effective" => $p->get_price("edit"),
            "sale_from" => $p->get_date_on_sale_from("edit")
                ? $p->get_date_on_sale_from("edit")->getTimestamp()
                : null,
            "sale_to" => $p->get_date_on_sale_to("edit")
                ? $p->get_date_on_sale_to("edit")->getTimestamp()
                : null,
            "stock_status" => $p->get_stock_status("edit"),
            "type" => $p->get_type(),
            "parent_id" => $p->get_parent_id(),
        ];
    }
    public static function hash(array $state): string
    {
        return hash("sha256", wp_json_encode($state));
    }
    public static function saleConflict(array $state, array $rule): bool
    {
        if (($rule["write_mode"] ?? "regular") === "sale") {
            return $state["sale"] === "" ||
                !$state["effective"] ||
                $state["effective"] !== $state["sale"];
        }
        return $state["sale"] !== "" ||
            $state["sale_from"] !== null ||
            $state["sale_to"] !== null;
    }
}
