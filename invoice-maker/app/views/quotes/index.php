<?php
$companyId = current_company_id();
$stmt = $pdo->prepare("
    SELECT quotes.*, customers.name AS customer_name
    FROM quotes
    JOIN customers ON customers.id = quotes.customer_id
    WHERE quotes.company_id = ?
    ORDER BY quotes.created_at DESC
");
$stmt->execute([$companyId]);
$quotes = $stmt->fetchAll();

$selectedId = $_GET['id'] ?? null;
$action = $_GET['action'] ?? ($selectedId ? 'view' : 'list');

$selectedQuote = null;
if ($selectedId) {
    foreach ($quotes as $q) {
        if ($q['id'] == $selectedId) {
            $selectedQuote = $q;
            break;
        }
    }
}

function getQuoteStatusBadgeCompact($status) {
    return match($status) {
        'accepted' => 'bg-emerald-100 text-emerald-700',
        'sent' => 'bg-blue-100 text-blue-700',
        'draft' => 'bg-gray-100 text-gray-600',
        'rejected' => 'bg-red-100 text-red-700',
        'expired' => 'bg-slate-100 text-slate-500',
        default => 'bg-gray-100 text-gray-600'
    };
}
?>

<div class="h-full flex flex-col">
    <div class="flex justify-between items-end mb-6 flex-shrink-0">
        <div>
            <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight italic">Quotes</h2>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mt-1">Estimates and Proposals</p>
        </div>
        
        <a href="<?= BASE_URL ?>/?page=quotes&action=create" class="bg-[#1a1a1a] hover:bg-emerald-600 text-white p-3.5 rounded-2xl shadow-xl transition-all transform hover:scale-105 active:scale-95 flex items-center justify-center group" title="New Quote">
            <i data-lucide="plus" class="w-5 h-5 group-hover:rotate-90 transition-transform"></i>
        </a>
    </div>

    <div class="flex-1 flex gap-6 min-h-0">
        <!-- Sidebar List (Left) -->
        <div class="w-full md:w-80 lg:w-96 flex flex-col flex-shrink-0 bg-white rounded-[2rem] border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-50 bg-gray-50/30">
                <div class="relative">
                    <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-gray-400"></i>
                    <input type="text" id="quote-search" placeholder="Search quotes..." class="w-full pl-9 pr-4 py-2 bg-white border-gray-200 rounded-xl text-sm focus:ring-emerald-500 focus:border-emerald-500 transition-all">
                </div>
            </div>
            
            <div class="flex-1 overflow-y-auto custom-scrollbar p-2 space-y-1">
                <?php if (empty($quotes)): ?>
                    <div class="py-12 text-center text-gray-400">
                        <i data-lucide="file-spreadsheet" class="w-8 h-8 mx-auto mb-2 opacity-20"></i>
                        <p class="text-xs font-medium">No quotes yet</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($quotes as $q): ?>
                    <a href="<?= BASE_URL ?>/?page=quotes&id=<?= $q['id'] ?>" class="flex flex-col p-4 rounded-2xl transition-all group <?= $selectedId == $q['id'] ? 'bg-emerald-50 border-emerald-100 ring-1 ring-emerald-500/20' : 'hover:bg-gray-50 border-transparent' ?> border">
                        <div class="flex justify-between items-start mb-2">
                            <span class="text-xs font-black text-slate-900 font-mono"><?= e($q['quote_number']) ?></span>
                            <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-tighter <?= getQuoteStatusBadgeCompact($q['status']) ?>">
                                <?= e($q['status']) ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-end">
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-slate-600 truncate"><?= e($q['customer_name']) ?></div>
                                <div class="text-[10px] text-gray-400 italic">Expires <?= date('M j', strtotime($q['expiry_date'])) ?></div>
                            </div>
                            <div class="text-sm font-black text-slate-900"><?= money($q['total']) ?></div>
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
                <?php elseif ($selectedQuote): ?>
                    <?php require __DIR__ . '/view.php'; ?>
                <?php else: ?>
                    <div class="h-full flex flex-col items-center justify-center text-center p-12 opacity-40">
                        <div class="w-24 h-24 bg-gray-50 rounded-full flex items-center justify-center mb-6 border border-gray-100 text-gray-300">
                            <i data-lucide="clipboard-list" class="w-10 h-10"></i>
                        </div>
                        <h3 class="text-xl font-black text-slate-900 italic">Quote Detail</h3>
                        <p class="text-gray-500 mt-2 max-w-xs text-sm">Select a quote from the sidebar to view, accept, or convert to a full invoice.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('quote-search')?.addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase();
    document.querySelectorAll('[href*="page=quotes&id="]').forEach(el => {
        const text = el.innerText.toLowerCase();
        el.style.display = text.includes(term) ? 'flex' : 'none';
    });
});
</script>
