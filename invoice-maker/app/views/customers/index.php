<?php
$companyId = current_company_id();
$stmt = $pdo->prepare("SELECT * FROM customers WHERE company_id = ? ORDER BY name ASC");
$stmt->execute([$companyId]);
$customers = $stmt->fetchAll();

$selectedId = $_GET['id'] ?? null;
$action = $_GET['action'] ?? ($selectedId ? 'edit' : 'list');

$selectedCustomer = null;
if ($selectedId) {
    foreach ($customers as $c) {
        if ($c['id'] == $selectedId) {
            $selectedCustomer = $c;
            break;
        }
    }
}

$customerDocuments = [];
if ($selectedCustomer) {
    $customerDocStmt = $pdo->prepare("
        SELECT 'invoice' AS doc_type, id, invoice_number AS number, status, total, issue_date, created_at
        FROM invoices
        WHERE company_id = ? AND customer_id = ?
        UNION ALL
        SELECT 'quote' AS doc_type, id, quote_number AS number, status, total, issue_date, created_at
        FROM quotes
        WHERE company_id = ? AND customer_id = ?
        ORDER BY created_at DESC
    ");
    $customerDocStmt->execute([$companyId, $selectedCustomer['id'], $companyId, $selectedCustomer['id']]);
    $customerDocuments = $customerDocStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="h-full flex flex-col">
    <div class="flex justify-between items-end mb-4 flex-shrink-0">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">Customers</h2>
            <p class="text-xs text-slate-500 mt-1">Manage your business contacts and client profiles.</p>
        </div>
        
        <a href="<?= BASE_URL ?>/?page=customers&action=create" class="bg-[#1a1a1a] hover:bg-emerald-600 text-white p-3 rounded-2xl shadow-lg shadow-gray-200 transition-all transform hover:scale-105 active:scale-95 flex items-center justify-center group" title="New Customer">
            <i data-lucide="plus" class="w-4 h-4 group-hover:rotate-90 transition-transform"></i>
        </a>
    </div>

    <div class="flex-1 flex gap-4 min-h-0">
        <!-- Master List (Left) -->
        <div class="w-full md:w-72 lg:w-80 flex flex-col flex-shrink-0 bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-3 border-b border-gray-50 bg-gray-50/30">
                <div class="relative">
                    <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-gray-400"></i>
                    <input type="text" id="customer-search" placeholder="Search clients..." class="w-full pl-9 pr-4 py-2 bg-white border-gray-200 rounded-xl text-sm focus:ring-emerald-500 focus:border-emerald-500 transition-all">
                </div>
            </div>
            
            <div class="flex-1 overflow-y-auto custom-scrollbar p-1.5 space-y-1">
                <?php if (empty($customers)): ?>
                    <div class="py-12 text-center text-gray-400">
                        <i data-lucide="users-round" class="w-8 h-8 mx-auto mb-2 opacity-20"></i>
                        <p class="text-xs font-medium">No customers yet</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($customers as $customer): ?>
                    <a href="<?= BASE_URL ?>/?page=customers&id=<?= $customer['id'] ?>" class="flex items-center p-2.5 rounded-2xl transition-all group <?= $selectedId == $customer['id'] ? 'bg-emerald-50 border-emerald-100 ring-1 ring-emerald-500/20' : 'hover:bg-gray-50 border-transparent' ?> border">
                        <div class="w-9 h-9 rounded-xl flex-shrink-0 flex items-center justify-center font-bold text-sm mr-3 transition-colors <?= $selectedId == $customer['id'] ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-200' : 'bg-gray-100 text-gray-400 group-hover:bg-emerald-100 group-hover:text-emerald-600' ?>">
                            <?= strtoupper(substr($customer['name'], 0, 1)) ?>
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-slate-900 truncate"><?= e($customer['name']) ?></div>
                            <div class="text-[11px] text-gray-400 truncate"><?= e($customer['company'] ?: 'Individual') ?></div>
                        </div>
                        <?php if ($selectedId == $customer['id']): ?>
                            <div class="ml-auto">
                                <i data-lucide="chevron-right" class="w-4 h-4 text-emerald-500"></i>
                            </div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Detail Area (Right) -->
        <div class="flex-1 bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">
            <div class="flex-1 overflow-y-auto custom-scrollbar p-5">
                <?php if ($action === 'create'): ?>
                    <?php require __DIR__ . '/create.php'; ?>
                <?php elseif ($selectedCustomer): ?>
                    <?php require __DIR__ . '/edit.php'; ?>
                <?php else: ?>
                    <div class="h-full flex flex-col items-center justify-center text-center p-12 opacity-40">
                        <div class="w-24 h-24 bg-gray-50 rounded-full flex items-center justify-center mb-6 border border-gray-100">
                            <i data-lucide="user-plus" class="w-10 h-10 text-gray-300"></i>
                        </div>
                        <h3 class="text-xl font-black text-slate-900">Select a Customer</h3>
                        <p class="text-gray-500 mt-2 max-w-xs">Pick a client from the list to view or edit their profile, or create a new one.</p>
                        <a href="<?= BASE_URL ?>/?page=customers&action=create" class="mt-8 bg-[#1a1a1a] text-white px-8 py-3 rounded-2xl font-bold hover:bg-emerald-600 transition-all">
                            New Customer
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="hidden xl:flex xl:w-[340px] flex-col flex-shrink-0 bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 bg-slate-50/60">
                <h3 class="text-sm font-black text-slate-900">Customer Activity</h3>
                <p class="text-[11px] text-slate-400">Quotes and invoices for the selected customer.</p>
            </div>

            <div class="flex-1 overflow-y-auto custom-scrollbar p-2">
                <?php if ($action === 'create' || !$selectedCustomer): ?>
                    <div class="h-full flex flex-col items-center justify-center text-center p-6 text-slate-400">
                        <div class="w-16 h-16 rounded-2xl bg-slate-50 border border-slate-200 flex items-center justify-center mb-4">
                            <i data-lucide="files" class="w-7 h-7"></i>
                        </div>
                        <p class="text-sm font-semibold text-slate-500">Select a customer</p>
                        <p class="text-xs mt-1">Their quote and invoice history will show here.</p>
                    </div>
                <?php elseif (empty($customerDocuments)): ?>
                    <div class="h-full flex flex-col items-center justify-center text-center p-6 text-slate-400">
                        <div class="w-16 h-16 rounded-2xl bg-slate-50 border border-slate-200 flex items-center justify-center mb-4">
                            <i data-lucide="folder-search" class="w-7 h-7"></i>
                        </div>
                        <p class="text-sm font-semibold text-slate-500">No documents yet</p>
                        <p class="text-xs mt-1">This customer has no saved quotes or invoices.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($customerDocuments as $doc):
                            $isInvoice = $doc['doc_type'] === 'invoice';
                            $href = BASE_URL . ($isInvoice ? '/?page=invoices-view&id=' : '/?page=quotes-view&id=') . (int)$doc['id'];
                        ?>
                            <a href="<?= $href ?>" class="flex items-start gap-3 rounded-2xl border border-transparent p-3 hover:bg-slate-50 hover:border-slate-200 transition">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl <?= $isInvoice ? 'bg-blue-50 text-blue-600' : 'bg-amber-50 text-amber-600' ?>">
                                    <i data-lucide="<?= $isInvoice ? 'receipt' : 'clipboard-list' ?>" class="w-4 h-4"></i>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="truncate text-sm font-bold text-slate-800"><?= e($doc['number']) ?></span>
                                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-wider <?= $isInvoice ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700' ?>">
                                            <?= $isInvoice ? 'Invoice' : 'Quote' ?>
                                        </span>
                                    </span>
                                    <span class="mt-1 block text-[11px] text-slate-400">
                                        <?= $doc['issue_date'] ? e(date('M j, Y', strtotime($doc['issue_date']))) : 'No date' ?> · <?= e($doc['status']) ?>
                                    </span>
                                    <span class="mt-1 block text-sm font-black text-slate-900"><?= money($doc['total']) ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('customer-search')?.addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase();
    document.querySelectorAll('[href*="page=customers&id="]').forEach(el => {
        const text = el.innerText.toLowerCase();
        el.style.display = text.includes(term) ? 'flex' : 'none';
    });
});
</script>
