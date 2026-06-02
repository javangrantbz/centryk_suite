<?php
$companyId = current_company_id();
$stmt = $pdo->prepare("
    SELECT invoices.*, customers.name AS customer_name
    FROM invoices
    JOIN customers ON customers.id = invoices.customer_id
    WHERE invoices.company_id = ?
    ORDER BY invoices.created_at DESC
");
$stmt->execute([$companyId]);
$invoices = $stmt->fetchAll();

$selectedId = $_GET['id'] ?? null;
$action = $_GET['action'] ?? ($selectedId ? 'view' : 'list');

$selectedInvoice = null;
if ($selectedId) {
    foreach ($invoices as $inv) {
        if ($inv['id'] == $selectedId) {
            $selectedInvoice = $inv;
            break;
        }
    }
}

function getInvStatusBadge($status) {
    return match($status) {
        'paid' => 'bg-emerald-100 text-emerald-700',
        'sent' => 'bg-blue-100 text-blue-700',
        'draft' => 'bg-gray-100 text-gray-600',
        'overdue' => 'bg-red-100 text-red-700',
        default => 'bg-gray-100 text-gray-600'
    };
}
?>

<div class="h-full flex flex-col">
    <div class="flex justify-between items-end mb-6 flex-shrink-0">
        <div>
            <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight italic">Invoices</h2>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mt-1">Manage billing and revenue</p>
        </div>
        
        <a href="<?= BASE_URL ?>/?page=invoices&action=create" class="bg-[#1a1a1a] hover:bg-emerald-600 text-white p-3.5 rounded-2xl shadow-xl transition-all transform hover:scale-105 active:scale-95 flex items-center justify-center group" title="New Invoice">
            <i data-lucide="plus" class="w-5 h-5 group-hover:rotate-90 transition-transform"></i>
        </a>
    </div>

    <div class="flex-1 flex gap-6 min-h-0">
        <!-- Sidebar List (Left) -->
        <div class="w-full md:w-80 lg:w-96 flex flex-col flex-shrink-0 bg-white rounded-[2rem] border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-50 bg-gray-50/30">
                <div class="relative">
                    <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-gray-400"></i>
                    <input type="text" id="invoice-search" placeholder="Search invoices..." class="w-full pl-9 pr-4 py-2 bg-white border-gray-200 rounded-xl text-sm focus:ring-emerald-500 focus:border-emerald-500 transition-all">
                </div>
            </div>
            
            <div class="flex-1 overflow-y-auto custom-scrollbar p-2 space-y-1">
                <?php if (empty($invoices)): ?>
                    <div class="py-12 text-center text-gray-400">
                        <i data-lucide="receipt" class="w-8 h-8 mx-auto mb-2 opacity-20"></i>
                        <p class="text-xs font-medium">No invoices found</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($invoices as $inv): ?>
                    <a href="<?= BASE_URL ?>/?page=invoices&id=<?= $inv['id'] ?>" class="flex flex-col p-4 rounded-2xl transition-all group <?= $selectedId == $inv['id'] ? 'bg-emerald-50 border-emerald-100 ring-1 ring-emerald-500/20' : 'hover:bg-gray-50 border-transparent' ?> border">
                        <div class="flex justify-between items-start mb-2">
                            <span class="text-xs font-black text-slate-900 font-mono"><?= e($inv['invoice_number']) ?></span>
                            <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-tighter <?= getInvStatusBadge($inv['status']) ?>">
                                <?= e($inv['status']) ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-end">
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-slate-600 truncate"><?= e($inv['customer_name']) ?></div>
                                <div class="text-[10px] text-gray-400"><?= date('M j, Y', strtotime($inv['issue_date'])) ?></div>
                            </div>
                            <div class="text-sm font-black text-slate-900"><?= money($inv['total']) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Detail Area (Right) -->
        <div class="flex-1 bg-white rounded-[2rem] border border-gray-100 shadow-sm overflow-hidden flex flex-col">
            <div class="flex-1 overflow-y-auto custom-scrollbar p-8">
                <?php if ($action === 'create'): ?>
                    <?php require __DIR__ . '/create.php'; ?>
                <?php elseif ($selectedInvoice): ?>
                    <?php require __DIR__ . '/view.php'; ?>
                <?php else: ?>
                    <div class="h-full flex flex-col items-center justify-center text-center p-12 opacity-40">
                        <div class="w-24 h-24 bg-gray-50 rounded-full flex items-center justify-center mb-6 border border-gray-100 text-gray-300">
                            <i data-lucide="receipt" class="w-10 h-10"></i>
                        </div>
                        <h3 class="text-xl font-black text-slate-900 italic">Invoice Viewer</h3>
                        <p class="text-gray-500 mt-2 max-w-xs text-sm">Select an invoice to preview details, mark as paid, or generate a PDF.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('invoice-search')?.addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase();
    document.querySelectorAll('[href*="page=invoices&id="]').forEach(el => {
        const text = el.innerText.toLowerCase();
        el.style.display = text.includes(term) ? 'flex' : 'none';
    });
});
</script>
