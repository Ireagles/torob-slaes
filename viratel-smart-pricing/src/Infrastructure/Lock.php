<?php
namespace Viratel\SmartPricing\Infrastructure;
/** DB lease; owner-specific release prevents deleting a replacement worker's lock. */
final class Lock
{
    private $db;
    private string $table;
    private string $owner;
    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->table = $wpdb->prefix . "vsp_locks";
        $this->owner = wp_generate_uuid4();
    }
    public function acquire(string $key, int $ttl = 60): bool
    {
        $now = time();
        $sql = $this->db->prepare(
            "INSERT INTO {$this->table} (lock_key,owner,expires_at) VALUES (%s,%s,%d) ON DUPLICATE KEY UPDATE owner=IF(expires_at<=%d,VALUES(owner),owner),expires_at=IF(expires_at<=%d,VALUES(expires_at),expires_at)",
            $key,
            $this->owner,
            $now + $ttl,
            $now,
            $now,
        );
        if ($this->db->query($sql) === false) {
            throw new \RuntimeException("lock_database_failed");
        }
        return $this->db->get_var(
            $this->db->prepare(
                "SELECT owner FROM {$this->table} WHERE lock_key=%s",
                $key,
            ),
        ) === $this->owner;
    }
    public function held(string $key): bool
    {
        return (bool) $this->db->get_var(
            $this->db->prepare(
                "SELECT 1 FROM {$this->table} WHERE lock_key=%s AND owner=%s AND expires_at>%d",
                $key,
                $this->owner,
                time(),
            ),
        );
    }
    public function release(string $key): void
    {
        $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->table} WHERE lock_key=%s AND owner=%s",
                $key,
                $this->owner,
            ),
        );
    }
}
