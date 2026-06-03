<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_customer'])) {
    $stmt = $pdo->prepare("
        INSERT INTO customers 
        (company_id, name, company, email, phone, address, tax_number)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        current_company_id(),
        $_POST['name'],
        $_POST['company'],
        $_POST['email'],
        $_POST['phone'],
        $_POST['address'],
        $_POST['tax_number']
    ]);

    redirect_response(BASE_URL . '/?page=customers&id=' . $pdo->lastInsertId());
}
?>

<div class="space-y-4">
    <div class="flex items-center space-x-3 text-emerald-600 mb-2">
        <i data-lucide="user-plus" class="w-5 h-5"></i>
        <h3 class="text-lg font-black text-slate-900">New Customer Profile</h3>
    </div>

    <form method="POST" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Full Name</label>
                <input name="name" required class="w-full rounded-2xl border border-slate-300 bg-white px-3 py-2.5 focus:ring-2 focus:ring-emerald-100 focus:border-emerald-500 transition-all text-sm font-semibold text-slate-900 shadow-sm outline-none" placeholder="e.g. John Doe">
            </div>

            <div>
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Company</label>
                <input name="company" class="w-full rounded-2xl border border-slate-300 bg-white px-3 py-2.5 focus:ring-2 focus:ring-emerald-100 focus:border-emerald-500 transition-all text-sm font-semibold text-slate-900 shadow-sm outline-none" placeholder="Business name">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:col-span-2">
                <div>
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Tax ID</label>
                <input name="tax_number" class="w-full rounded-2xl border border-slate-300 bg-white px-3 py-2.5 focus:ring-2 focus:ring-emerald-100 focus:border-emerald-500 transition-all text-sm font-semibold text-slate-900 shadow-sm outline-none" placeholder="Tax ID">
                </div>

                <div>
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Email</label>
                <input name="email" type="email" class="w-full rounded-2xl border border-slate-300 bg-white px-3 py-2.5 focus:ring-2 focus:ring-emerald-100 focus:border-emerald-500 transition-all text-sm font-semibold text-slate-900 shadow-sm outline-none" placeholder="email@client.com">
                </div>

                <div>
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Phone</label>
                <input name="phone" class="w-full rounded-2xl border border-slate-300 bg-white px-3 py-2.5 focus:ring-2 focus:ring-emerald-100 focus:border-emerald-500 transition-all text-sm font-semibold text-slate-900 shadow-sm outline-none" placeholder="+1 (000) 000-0000">
                </div>
            </div>

            <div class="md:col-span-2">
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Address</label>
                <textarea name="address" rows="3" class="w-full rounded-2xl border border-slate-300 bg-white px-3 py-2.5 focus:ring-2 focus:ring-emerald-100 focus:border-emerald-500 transition-all text-sm font-semibold text-slate-900 shadow-sm outline-none" placeholder="Billing address details"></textarea>
            </div>
        </div>

        <div class="flex items-center justify-end space-x-4 pt-2">
            <a href="<?= BASE_URL ?>/?page=customers" class="text-xs font-bold text-gray-400 hover:text-slate-900 px-4 transition-colors uppercase tracking-widest">Cancel</a>
            <button name="save_customer" value="1" class="bg-emerald-600 hover:bg-emerald-700 text-white px-7 py-3 rounded-2xl font-black shadow-lg shadow-emerald-100 transition-all hover:scale-105 active:scale-95 uppercase tracking-widest text-[10px]">
                Create Customer
            </button>
        </div>
    </form>
</div>
