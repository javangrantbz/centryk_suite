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

<div class="biz h-full flex flex-col">
    <div class="flex justify-between items-center mb-3 flex-shrink-0">
        <div>
            <p class="biz-kicker">Invoice engine</p>
            <h1 class="mt-0.5">Clients</h1>
        </div>
        <a href="<?= BASE_URL ?>/?page=customers&action=create" class="biz-btn biz-btn-primary"><i data-lucide="plus" class="w-3.5 h-3.5"></i> New client</a>
    </div>

    <div class="flex-1 flex gap-3 min-h-0">
        <!-- Master List -->
        <div class="biz-panel w-full md:w-64 lg:w-72 flex flex-col flex-shrink-0 overflow-hidden">
            <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line)">
                <input type="text" id="customer-search" class="biz-input" placeholder="Search clients…">
            </div>
            <div class="flex-1 overflow-y-auto custom-scrollbar biz-list">
                <?php if (empty($customers)): ?>
                    <div class="biz-panel-empty">No clients yet.</div>
                <?php endif; ?>
                <?php foreach ($customers as $customer): ?>
                    <a href="<?= BASE_URL ?>/?page=customers&id=<?= $customer['id'] ?>" class="biz-row <?= $selectedId == $customer['id'] ? 'is-active' : '' ?>" style="align-items:flex-start">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded font-bold" style="font-size:11px;background:var(--bz-line-soft);color:var(--bz-muted)">
                            <?= strtoupper(substr($customer['name'], 0, 1)) ?>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-bold truncate"><?= e($customer['name']) ?></span>
                            <span class="block biz-muted truncate" style="font-size:11px"><?= e($customer['company'] ?: 'Individual') ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Detail -->
        <div class="biz-panel flex-1 overflow-hidden flex flex-col">
            <div class="flex-1 overflow-y-auto custom-scrollbar biz-panel-body">
                <?php if ($action === 'create'): ?>
                    <?php require __DIR__ . '/create.php'; ?>
                <?php elseif ($selectedCustomer): ?>
                    <?php require __DIR__ . '/edit.php'; ?>
                <?php else: ?>
                    <div class="biz-panel-empty" style="padding-top:60px">
                        Pick a client from the list to view or edit their profile, or
                        <a class="biz-t-green font-semibold" href="<?= BASE_URL ?>/?page=customers&action=create">create a new one</a>.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="biz-panel hidden xl:flex xl:w-[320px] flex-col flex-shrink-0 overflow-hidden">
            <div class="biz-panel-head"><span>Client activity</span></div>
            <div class="flex-1 overflow-y-auto custom-scrollbar biz-list">
                <?php if ($action === 'create' || !$selectedCustomer): ?>
                    <div class="biz-panel-empty">Select a client to see their quotes and invoices.</div>
                <?php elseif (empty($customerDocuments)): ?>
                    <div class="biz-panel-empty">No quotes or invoices for this client yet.</div>
                <?php else: ?>
                    <?php foreach ($customerDocuments as $doc):
                        $isInvoice = $doc['doc_type'] === 'invoice';
                        $href = BASE_URL . ($isInvoice ? '/?page=invoices-view&id=' : '/?page=quotes-view&id=') . (int)$doc['id'];
                    ?>
                        <a href="<?= $href ?>" class="biz-row" style="align-items:flex-start">
                            <span class="biz-chip <?= $isInvoice ? 'biz-c-blue' : 'biz-c-amber' ?>"><?= $isInvoice ? 'Invoice' : 'Quote' ?></span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-bold" style="font-family:ui-monospace,monospace"><?= e($doc['number']) ?></span>
                                <span class="block biz-muted" style="font-size:11px">
                                    <?= $doc['issue_date'] ? e(date('j M Y', strtotime($doc['issue_date']))) : 'No date' ?> · <?= e($doc['status']) ?>
                                </span>
                            </span>
                            <span class="shrink-0 font-bold biz-num"><?= money($doc['total']) ?></span>
                        </a>
                    <?php endforeach; ?>
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
