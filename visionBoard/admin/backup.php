<?php
$active = 'backup';
$pageTitle = 'Backup';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$companyId = vb_cid();

function zip_crc(string $data): int
{
    $crc = crc32($data);
    return $crc < 0 ? $crc + 4294967296 : $crc;
}

function zip_add_stored_entry(string &$body, array &$central, string $name, string $data): void
{
    $offset = strlen($body);
    $crc = zip_crc($data);
    $size = strlen($data);
    $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0)
        . $name . $data;
    $central[] = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset)
        . $name;
}

/** Build a zip containing backup.sql plus the given media files (basenames). */
function create_backup_zip(string $path, string $sql, array $mediaFiles): bool
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $zip->addFromString('backup.sql', $sql);
        foreach ($mediaFiles as $base) {
            $file = UPLOAD_DIR . '/' . $base;
            if (is_file($file)) {
                $zip->addFile($file, 'uploads/' . $base);
            }
        }
        return $zip->close();
    }

    $body = '';
    $central = [];
    zip_add_stored_entry($body, $central, 'backup.sql', $sql);
    foreach ($mediaFiles as $base) {
        $file = UPLOAD_DIR . '/' . $base;
        if (is_file($file)) {
            zip_add_stored_entry($body, $central, 'uploads/' . $base, file_get_contents($file));
        }
    }
    $centralStart = strlen($body);
    $centralBody = implode('', $central);
    $zip = $body . $centralBody . pack('VvvvvVVv', 0x06054b50, 0, 0, count($central), count($central), strlen($centralBody), $centralStart, 0);
    return file_put_contents($path, $zip) !== false;
}

/**
 * Company-scoped data dump. Emits INSERT statements for this company's rows
 * across the vb_* tables only — never DDL, never other tenants' data, never
 * Centryk's own tables. (Restore of a shared database is intentionally not
 * offered here; this export is a portable content snapshot.)
 */
function sql_dump_for_company(PDO $pdo, int $companyId): string
{
    // table => SQL selecting this company's rows (child tables filter via parent).
    $queries = [
        'vb_media'                 => 'SELECT * FROM vb_media WHERE company_id = :cid',
        'vb_content_items'         => 'SELECT * FROM vb_content_items WHERE company_id = :cid',
        'vb_playlists'             => 'SELECT * FROM vb_playlists WHERE company_id = :cid',
        'vb_playlist_items'        => 'SELECT pi.* FROM vb_playlist_items pi JOIN vb_playlists p ON p.id = pi.playlist_id WHERE p.company_id = :cid',
        'vb_schedules'             => 'SELECT * FROM vb_schedules WHERE company_id = :cid',
        'vb_settings'              => 'SELECT * FROM vb_settings WHERE company_id = :cid',
        'vb_qr_codes'              => 'SELECT * FROM vb_qr_codes WHERE company_id = :cid',
        'vb_marquee_messages'      => 'SELECT * FROM vb_marquee_messages WHERE company_id = :cid',
        'vb_display_announcements' => 'SELECT * FROM vb_display_announcements WHERE company_id = :cid',
        'vb_screens'               => 'SELECT * FROM vb_screens WHERE company_id = :cid',
        'vb_activity_logs'         => 'SELECT * FROM vb_activity_logs WHERE company_id = :cid',
    ];
    $out = "-- Centryk Signage backup (company #$companyId)\n-- Created " . date('c') . "\n\n";
    foreach ($queries as $table => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cid' => $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            continue;
        }
        $out .= "-- $table (" . count($rows) . " rows)\n";
        foreach ($rows as $row) {
            $cols = array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($row));
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row));
            $out .= 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
        }
        $out .= "\n";
    }
    return $out;
}

/** Basenames of this company's media (files + thumbnails). */
function company_media_files(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare('SELECT filename, thumbnail_filename FROM vb_media WHERE company_id = ?');
    $stmt->execute([$companyId]);
    $files = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if (!empty($m['filename'])) $files[] = $m['filename'];
        if (!empty($m['thumbnail_filename'])) $files[] = $m['thumbnail_filename'];
    }
    return array_values(array_unique($files));
}

if (isset($_GET['export'])) {
    $filename = 'signage-backup-' . date('Ymd-His') . '.zip';
    $path = UPLOAD_DIR . '/' . $filename;
    $sql = sql_dump_for_company($pdo = db(), $companyId);
    if (!create_backup_zip($path, $sql, company_media_files($pdo, $companyId))) {
        flash('Could not create backup file.', 'error');
        redirect('backup.php');
    }
    log_activity('exported', 'backup', null, $filename);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);
    exit;
}

require __DIR__ . '/../includes/header.php';
?>
<h1 class="text-2xl font-bold mb-2">Backup</h1>
<p class="text-slate-500 mb-6">Export this company's signage content (settings, media, playlists, schedules) as a zip.</p>

<div class="grid md:grid-cols-2 gap-6">
  <div class="bg-white rounded-xl shadow-sm p-6">
    <h2 class="font-semibold mb-3">Export backup</h2>
    <p class="text-sm text-slate-500 mb-4">Downloads a zip containing <code>backup.sql</code> (your data only) and your uploaded media.</p>
    <a href="backup.php?export=1" class="inline-block bg-green-700 hover:bg-green-800 text-white font-medium rounded-lg px-5 py-2">Download backup</a>
  </div>

  <div class="bg-white rounded-xl shadow-sm p-6">
    <h2 class="font-semibold mb-3">Restore</h2>
    <p class="text-sm text-slate-500">Restores are handled by an administrator to protect the shared platform. Contact Centryk support with your backup file if you need to restore content.</p>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
