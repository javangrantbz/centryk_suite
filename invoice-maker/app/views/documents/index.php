<?php
$companyId = current_company_id();

$customersStmt = $pdo->prepare("SELECT id, name FROM customers WHERE company_id = ? ORDER BY name ASC");
$customersStmt->execute([$companyId]);
$customers = $customersStmt->fetchAll();

$quotesStmt = $pdo->prepare("SELECT id, quote_number FROM quotes WHERE company_id = ? ORDER BY created_at DESC");
$quotesStmt->execute([$companyId]);
$quotes = $quotesStmt->fetchAll();

$invoicesStmt = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE company_id = ? ORDER BY created_at DESC");
$invoicesStmt->execute([$companyId]);
$invoices = $invoicesStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete'])) {
        $id = $_POST['document_id'];

        $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $companyId]);
        $document = $stmt->fetch();

        if ($document) {
            $fullPath = __DIR__ . '/../../../' . $document['file_path'];

            if (file_exists($fullPath)) {
                unlink($fullPath);
            }

            $delete = $pdo->prepare("DELETE FROM documents WHERE id = ? AND company_id = ?");
            $delete->execute([$id, $companyId]);
        }

        redirect_response(BASE_URL . '/?page=documents');
    }

    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $title = $_POST['title'];
        $customerId = $_POST['customer_id'] ?: null;
        $quoteId = $_POST['quote_id'] ?: null;
        $invoiceId = $_POST['invoice_id'] ?: null;

        $originalName = $_FILES['document']['name'];
        $tmpName = $_FILES['document']['tmp_name'];
        $fileType = $_FILES['document']['type'];
        $fileSize = $_FILES['document']['size'];

        $maxSize = 10 * 1024 * 1024;

        if ($fileSize > $maxSize) {
            $uploadError = 'File is too large. Maximum size is 10MB.';
        } else {
            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'txt'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions)) {
                $uploadError = 'File type not allowed.';
            } else {
                $safeName = uniqid('doc_', true) . '.' . $extension;
                $relativePath = 'storage/documents/' . $safeName;
                $destination = __DIR__ . '/../../../' . $relativePath;

                if (move_uploaded_file($tmpName, $destination)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO documents
                        (company_id, customer_id, invoice_id, quote_id, title, file_name, file_path, file_type)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $companyId,
                        $customerId,
                        $invoiceId,
                        $quoteId,
                        $title,
                        $originalName,
                        $relativePath,
                        $fileType
                    ]);

                    redirect_response(BASE_URL . '/?page=documents');
                } else {
                    $uploadError = 'Upload failed.';
                }
            }
        }
    }
}

$stmt = $pdo->prepare("
    SELECT 
        documents.*,
        customers.name AS customer_name,
        quotes.quote_number,
        invoices.invoice_number
    FROM documents
    LEFT JOIN customers ON customers.id = documents.customer_id
    LEFT JOIN quotes ON quotes.id = documents.quote_id
    LEFT JOIN invoices ON invoices.id = documents.invoice_id
    WHERE documents.company_id = ?
    ORDER BY documents.created_at DESC
");
$stmt->execute([$companyId]);
$documents = $stmt->fetchAll();

function getFileIcon($type) {
    if (str_contains($type, 'pdf')) return 'file-text';
    if (str_contains($type, 'image')) return 'file-image';
    if (str_contains($type, 'sheet') || str_contains($type, 'excel') || str_contains($type, 'xls')) return 'file-spreadsheet';
    return 'file';
}
?>

<div class="biz">
    <p class="biz-kicker">Invoice engine</p>
    <h1 class="mt-0.5 mb-3">Files</h1>

    <?php if (!empty($uploadError)): ?>
        <div class="biz-notice biz-notice-red mb-3"><?= e($uploadError) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-[300px_minmax(0,1fr)] gap-3 items-start">
        <!-- Upload -->
        <div class="biz-panel lg:sticky lg:top-2">
            <div class="biz-panel-head"><span>Upload a file</span></div>
            <form method="POST" enctype="multipart/form-data" class="biz-panel-body space-y-2">
                <label class="block"><span class="biz-label">Title</span>
                    <input name="title" required class="biz-input" placeholder="e.g. Contract v1"></label>
                <label class="block"><span class="biz-label">File</span>
                    <input type="file" name="document" required class="biz-input" style="height:auto;padding:4px 6px"></label>
                <label class="block"><span class="biz-label">Link to client</span>
                    <select name="customer_id" class="biz-select">
                        <option value="">—</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= $customer['id'] ?>"><?= e($customer['name']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="block"><span class="biz-label">Quote</span>
                        <select name="quote_id" class="biz-select">
                            <option value="">—</option>
                            <?php foreach ($quotes as $quote): ?>
                                <option value="<?= $quote['id'] ?>"><?= e($quote['quote_number']) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                    <label class="block"><span class="biz-label">Invoice</span>
                        <select name="invoice_id" class="biz-select">
                            <option value="">—</option>
                            <?php foreach ($invoices as $invoice): ?>
                                <option value="<?= $invoice['id'] ?>"><?= e($invoice['invoice_number']) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                </div>
                <div class="pt-1"><button class="biz-btn biz-btn-primary" style="width:100%"><i data-lucide="upload" class="w-3.5 h-3.5"></i> Upload</button></div>
            </form>
        </div>

        <!-- List -->
        <div class="biz-panel min-w-0 overflow-hidden">
            <div class="biz-panel-head"><span><?= count($documents) ?> file(s)</span></div>
            <?php if (empty($documents)): ?>
                <div class="biz-panel-empty">No files stored yet.</div>
            <?php else: ?>
            <div class="biz-list">
                <?php foreach ($documents as $document): ?>
                <div class="biz-row" style="align-items:flex-start">
                    <i data-lucide="<?= getFileIcon($document['file_type']) ?>" class="w-4 h-4 shrink-0" style="color:var(--bz-muted);margin-top:2px"></i>
                    <span class="min-w-0 flex-1">
                        <span class="block font-bold truncate"><?= e($document['title']) ?></span>
                        <span class="block biz-muted truncate" style="font-size:11px;font-family:ui-monospace,monospace"><?= e($document['file_name']) ?></span>
                        <span class="block biz-muted" style="font-size:11px">
                            <?= e(date('j M Y', strtotime($document['created_at']))) ?>
                            <?php
                            $link = $document['customer_name'] ?: ($document['quote_number'] ?: ($document['invoice_number'] ?: ''));
                            if ($link): ?> · <?= e($link) ?><?php else: ?> · general file<?php endif; ?>
                        </span>
                    </span>
                    <span class="shrink-0 flex items-center gap-1">
                        <a href="<?= BASE_URL ?>/download.php?id=<?= $document['id'] ?>" class="biz-t-blue" style="font-size:11px" title="Download"><i data-lucide="download" class="w-3.5 h-3.5"></i></a>
                        <form method="POST" onsubmit="return confirm('Permanently delete this file?')" class="inline">
                            <input type="hidden" name="document_id" value="<?= $document['id'] ?>">
                            <button name="delete" value="1" class="biz-t-red" style="font-size:11px"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
                        </form>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
