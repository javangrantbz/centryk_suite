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

<?php if (!empty($uploadError)): ?>
    <div class="bg-red-50 text-red-600 p-4 rounded-2xl mb-8 flex items-center">
        <i data-lucide="alert-circle" class="w-5 h-5 mr-3"></i>
        <?= e($uploadError) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-[320px_minmax(0,1fr)] gap-5 items-start">
    <!-- Upload Section -->
    <div>
        <div class="bg-white p-4 rounded-3xl shadow-sm border border-gray-100 sticky top-4">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h3 class="text-base font-bold flex items-center">
                    <i data-lucide="upload-cloud" class="w-4 h-4 mr-2 text-emerald-600"></i>
                    Upload Document
                </h3>
                <button class="shrink-0 bg-[#1a1a1a] hover:bg-[#2a2a2a] text-white px-4 py-2 rounded-xl font-bold transition-all flex items-center justify-center text-xs">
                    <i data-lucide="arrow-up" class="w-3.5 h-3.5 mr-1.5"></i>
                    Upload
                </button>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-3">
                <div>
                    <label class="block mb-2 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Document Title</label>
                    <input name="title" required class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100" placeholder="e.g. Contract v1">
                </div>

                <div>
                    <label class="block mb-2 text-[11px] font-bold text-gray-500 uppercase tracking-wider">File Selection</label>
                    <div class="border-2 border-dashed border-gray-100 rounded-2xl p-3 text-center hover:border-emerald-200 transition-colors bg-gray-50/30">
                        <input type="file" name="document" required id="file-upload" class="hidden">
                        <label for="file-upload" class="cursor-pointer">
                            <i data-lucide="paperclip" class="w-5 h-5 mx-auto text-gray-300 mb-1.5"></i>
                            <span class="text-sm text-gray-400">Choose file</span>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block mb-2 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Reference</label>
                    <div class="grid grid-cols-1 gap-2">
                        <select name="customer_id" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
                            <option value="">Customer: None</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?= $customer['id'] ?>"><?= e($customer['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="quote_id" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
                            <option value="">Quote: None</option>
                            <?php foreach ($quotes as $quote): ?>
                                <option value="<?= $quote['id'] ?>"><?= e($quote['quote_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="invoice_id" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
                            <option value="">Invoice: None</option>
                            <?php foreach ($invoices as $invoice): ?>
                                <option value="<?= $invoice['id'] ?>"><?= e($invoice['invoice_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Documents List -->
    <div class="min-w-0">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 bg-slate-50/60">
                <p class="text-sm font-semibold text-slate-500">Manage files, agreements, and attachments.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50/50 border-b border-gray-100">
                            <th class="px-5 py-3 text-[11px] font-bold text-gray-400 uppercase tracking-widest">Document</th>
                            <th class="px-5 py-3 text-[11px] font-bold text-gray-400 uppercase tracking-widest">Linked To</th>
                            <th class="px-5 py-3 text-[11px] font-bold text-gray-400 uppercase tracking-widest text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php if (empty($documents)): ?>
                            <tr>
                                <td colspan="3" class="px-6 py-12 text-center text-gray-400">
                                    <i data-lucide="archive" class="w-10 h-10 mx-auto mb-4 opacity-20"></i>
                                    <p>No documents stored yet.</p>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($documents as $document): ?>
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-5 py-4">
                                    <div class="flex items-start">
                                        <div class="p-2.5 bg-gray-50 rounded-xl text-emerald-600 mr-3">
                                            <i data-lucide="<?= getFileIcon($document['file_type']) ?>" class="w-4 h-4"></i>
                                        </div>
                                        <div>
                                            <div class="text-sm font-bold text-slate-900"><?= e($document['title']) ?></div>
                                            <div class="text-xs text-gray-400 font-mono"><?= e($document['file_name']) ?></div>
                                            <div class="text-[10px] text-gray-300 mt-1 uppercase font-bold tracking-tighter">
                                                <?= e(date('M d, Y', strtotime($document['created_at']))) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="space-y-1">
                                        <?php if ($document['customer_name']): ?>
                                            <div class="flex items-center text-xs text-slate-600">
                                                <i data-lucide="user" class="w-3 h-3 mr-2 text-gray-300"></i>
                                                <?= e($document['customer_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($document['quote_number']): ?>
                                            <div class="flex items-center text-xs text-slate-600 font-mono">
                                                <i data-lucide="file-spreadsheet" class="w-3 h-3 mr-2 text-gray-300"></i>
                                                <?= e($document['quote_number']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($document['invoice_number']): ?>
                                            <div class="flex items-center text-xs text-slate-600 font-mono">
                                                <i data-lucide="receipt" class="w-3 h-3 mr-2 text-gray-300"></i>
                                                <?= e($document['invoice_number']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!$document['customer_id'] && !$document['quote_id'] && !$document['invoice_id']): ?>
                                            <span class="text-xs text-gray-300 italic">General file</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <div class="flex justify-end space-x-1">
                                        <a href="<?= BASE_URL ?>/download.php?id=<?= $document['id'] ?>" class="p-2 text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-all" title="Download">
                                            <i data-lucide="download" class="w-4 h-4"></i>
                                        </a>
                                        <form method="POST" onsubmit="return confirm('Permanently delete this document?')" class="inline">
                                            <input type="hidden" name="document_id" value="<?= $document['id'] ?>">
                                            <button name="delete" value="1" class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
