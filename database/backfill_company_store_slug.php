<?php
/**
 * One-off: give every active company a store_slug. Safe to re-run — it skips
 * companies that already have one. Run after add_company_store_slug.sql:
 *   C:/xampp/php/php.exe database/backfill_company_store_slug.php
 */
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/StoreLink.php';

$pdo  = DB::pdo();
$rows = $pdo->query('SELECT id, name FROM companies WHERE status = "active" AND (store_slug IS NULL OR store_slug = "") ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nothing to backfill.\n";
    exit;
}

foreach ($rows as $row) {
    $slug = StoreLink::ensure($pdo, (int)$row['id'], (string)$row['name']);
    printf("  #%d  %-40s -> %s\n", $row['id'], $row['name'], $slug);
}

echo count($rows) . " company slug(s) set.\n";
