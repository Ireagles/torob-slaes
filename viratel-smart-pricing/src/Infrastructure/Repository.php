<?php
namespace Viratel\SmartPricing\Infrastructure;
final class Repository
{
    private $db;
    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
    }
    public function table(string $name): string
    {
        if (
            !in_array(
                $name,
                [
                    "mappings",
                    "rules",
                    "groups",
                    "snapshots",
                    "proposals",
                    "changes",
                    "usage",
                    "locks",
                    "imports",
                ],
                true,
            )
        ) {
            throw new \InvalidArgumentException("table_invalid");
        }
        return $this->db->prefix . "vsp_" . $name;
    }
    public function get(string $table, $id): ?array
    {
        $key =
            $table === "mappings"
                ? "product_id"
                : ($table === "groups"
                    ? "group_key"
                    : "id");
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM " . $this->table($table) . " WHERE $key=%s",
                $id,
            ),
            ARRAY_A,
        );
        if (!$row) {
            return null;
        }
        if (isset($row["config"])) {
            $row["data"] = json_decode(
                $row["config"],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }
        return $row;
    }
    public function insert(
        string $table,
        array $row,
        bool $ignore = false,
    ): bool {
        if (isset($row["data"])) {
            $row["config"] = wp_json_encode($row["data"]);
            unset($row["data"]);
        }
        if ($ignore && isset($row["id"]) && $this->get($table, $row["id"])) {
            return false;
        }
        if ($ignore && isset($row["id"])) {
            $columns = array_keys($row);
            foreach ($columns as $column) {
                if (!preg_match('/^[a-z_]+$/D', $column)) {
                    throw new \InvalidArgumentException("column_invalid");
                }
            }
            $sql =
                "INSERT INTO " .
                $this->table($table) .
                " (`" .
                implode("`,`", $columns) .
                "`) VALUES (" .
                implode(",", array_fill(0, count($row), "%s")) .
                ") ON DUPLICATE KEY UPDATE id=id";
            $ok = $this->db->query(
                $this->db->prepare($sql, array_values($row)),
            );
        } else {
            $ok = $this->db->insert($this->table($table), $row);
        }
        if ($ok === false) {
            throw new \RuntimeException(
                preg_match(
                    "/deadlock|lock wait timeout/i",
                    $this->db->last_error,
                )
                    ? "database_retryable"
                    : "database_write_failed",
            );
        }
        return true;
    }
    public function update(string $table, $id, array $row): void
    {
        if (isset($row["data"])) {
            $row["config"] = wp_json_encode($row["data"]);
            unset($row["data"]);
        }
        $key =
            $table === "mappings"
                ? "product_id"
                : ($table === "groups"
                    ? "group_key"
                    : "id");
        if (
            $this->db->update($this->table($table), $row, [$key => $id]) ===
            false
        ) {
            throw new \RuntimeException(
                preg_match(
                    "/deadlock|lock wait timeout/i",
                    $this->db->last_error,
                )
                    ? "database_retryable"
                    : "database_write_failed",
            );
        }
    }
    public function page(
        string $table,
        int $page = 1,
        int $limit = 30,
        string $search = "",
    ): array {
        $limit = max(1, min(100, $limit));
        $offset = (max(1, $page) - 1) * $limit;
        $where = "";
        $args = [];
        if ($search !== "" && $table === "mappings") {
            $where = " WHERE config LIKE %s";
            $args[] = "%" . $this->db->esc_like($search) . "%";
        }
        $order =
            $table === "mappings"
                ? "product_id"
                : ($table === "groups"
                    ? "group_key"
                    : "created_at DESC");
        $args[] = $limit;
        $args[] = $offset;
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM " .
                    $this->table($table) .
                    $where .
                    " ORDER BY $order LIMIT %d OFFSET %d",
                $args,
            ),
            ARRAY_A,
        );
        foreach ($rows as &$r) {
            if (isset($r["config"])) {
                $r["data"] = json_decode($r["config"], true);
            }
        }
        return $rows;
    }
    public function count(string $table): int
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM " . $this->table($table),
        );
    }
    public function due(int $after, int $now): array
    {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT product_id,group_key FROM " .
                    $this->table("mappings") .
                    " WHERE product_id>%d AND next_due<=%d AND status<>%s ORDER BY product_id LIMIT 50",
                $after,
                $now,
                "disabled",
            ),
            ARRAY_A,
        );
    }
    public function sourceSnapshot(string $key, int $window): ?array
    {
        $id = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM " .
                    $this->table("snapshots") .
                    " WHERE source_key=%s AND window_id=%d",
                $key,
                $window,
            ),
        );
        return $id ? $this->get("snapshots", $id) : null;
    }
    public function usageSince(int $time): int
    {
        return (int) $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM " .
                    $this->table("usage") .
                    " WHERE created_at>=%d",
                $time,
            ),
        );
    }
    public function dailyChange(int $productId, int $since): int
    {
        $rows = $this->db->get_col(
            $this->db->prepare(
                "SELECT config FROM " .
                    $this->table("changes") .
                    " WHERE product_id=%d AND created_at>=%d AND status=%s LIMIT 1000",
                $productId,
                $since,
                "applied",
            ),
        );
        $total = 0;
        foreach ($rows as $r) {
            $total += (int) (json_decode($r, true)["delta_minor"] ?? 0);
        }
        return $total;
    }
    public function latestApplied(int $productId): int
    {
        return (int) $this->db->get_var(
            $this->db->prepare(
                "SELECT MAX(created_at) FROM " .
                    $this->table("changes") .
                    " WHERE product_id=%d AND status=%s",
                $productId,
                "applied",
            ),
        );
    }
    public function prune(): void
    {
        $now = time();
        $s = $this->table("snapshots");
        $p = $this->table("proposals");
        $this->db->query(
            $this->db->prepare(
                "UPDATE $p SET status='expired' WHERE created_at<%d AND status IN ('ready','hold') LIMIT 100",
                $now - 7 * 86400,
            ),
        );
        $ids = $this->db->get_col(
            $this->db->prepare(
                "SELECT s.id FROM $s s WHERE s.created_at<%d AND NOT EXISTS (SELECT 1 FROM $p p WHERE p.snapshot_id=s.id AND p.status IN ('ready','hold')) LIMIT 100",
                $now - 7 * 86400,
            ),
        );
        foreach ($ids as $id) {
            $this->db->delete($s, ["id" => $id]);
        }
        foreach (["usage"] as $t) {
            $this->db->query(
                $this->db->prepare(
                    "DELETE FROM " .
                        $this->table($t) .
                        " WHERE created_at<%d LIMIT 100",
                    $now - 90 * 86400,
                ),
            );
        }
        $this->db->query(
            $this->db->prepare(
                "DELETE FROM " .
                    $this->table("imports") .
                    " WHERE created_at<%d AND status IN ('applied','invalid') LIMIT 100",
                $now - 90 * 86400,
            ),
        );
        $this->db->query(
            $this->db->prepare(
                "DELETE FROM " .
                    $this->table("locks") .
                    " WHERE expires_at<%d LIMIT 100",
                $now - 3600,
            ),
        );
    }
}
