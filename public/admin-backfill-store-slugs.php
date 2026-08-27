<?php
/**
 * One-off maintenance page: give every active company a store_slug so its
 * /s/<slug> short link works immediately (rather than only after the first
 * store-page view generates it lazily).
 *
 * Browser-run equivalent of a CLI backfill, for hosts reachable only via
 * SFTP. Gated to Centryk platform admins. Safe to re-run — companies that
 * already have a slug are skipped.
 *
 * >>> DELETE THIS FILE once it has been run. <<<
 */

require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/StoreLink.php';

Auth::start();
$user = Auth::user();
if (!$user || empty($user['is_admin'])) {
    http_response_code(403);
    echo 'Forbidden. Log in as a Centryk platform admin first, then reload this page.';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$pdo = DB::pdo();

// The column must exist (database/add_company_store_slug.sql).
try {
    $pdo->query('SELECT store_slug FROM companies LIMIT 1');
} catch (Throwable $e) {
    echo "The companies.store_slug column is missing.\n";
    echo "Run database/add_company_store_slug.sql first, then reload this page.\n";
    exit;
}

$rows = $pdo->query('
    SELECT id, name
    FROM companies
    WHERE status = "active" AND (store_slug IS NULL OR store_slug = "")
    ORDER BY id
')->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nothing to backfill — every active company already has a store slug.\n";
    echo "\n>>> Delete this file (admin-backfill-store-slugs.php) now. <<<\n";
    exit;
}

echo "Backfilling " . count($rows) . " company slug(s)...\n\n";

foreach ($rows as $row) {
    try {
        $slug = StoreLink::ensure($pdo, (int)$row['id'], (string)$row['name']);
        printf("  #%-4d %-40s -> %s\n", $row['id'], $row['name'], $slug);
    } catch (Throwable $e) {
        printf("  #%-4d %-40s -> FAILED (%s)\n", $row['id'], $row['name'], $e->getMessage());
    }
}

echo "\nDone.\n";
echo "\n>>> Delete this file (admin-backfill-store-slugs.php) now that it's run. <<<\n";
