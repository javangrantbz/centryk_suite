<?php
$id = $_GET['id'] ?? null;

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND company_id = ?");
$stmt->execute([$id, current_company_id()]);
$customer = $stmt->fetch();

if (!$customer) {
    echo '<div class="bg-red-50 text-red-600 p-8 rounded-3xl text-center">
            <i data-lucide="user-x" class="w-12 h-12 mx-auto mb-4"></i>
            <p class="font-bold text-xl">Customer not found.</p>
          </div>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_customer'])) {
    if (isset($_POST['delete'])) {
        $delete = $pdo->prepare("DELETE FROM customers WHERE id = ? AND company_id = ?");
        $delete->execute([$id, current_company_id()]);

        header('Location: ' . BASE_URL . '/?page=customers');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE customers
        SET name = ?, company = ?, email = ?, phone = ?, address = ?, tax_number = ?
        WHERE id = ? AND company_id = ?
    ");

    $stmt->execute([
        $_POST['name'],
        $_POST['company'],
        $_POST['email'],
        $_POST['phone'],
        $_POST['address'],
        $_POST['tax_number'],
        $id,
        current_company_id()
    ]);

    header('Location: ' . BASE_URL . '/?page=customers&id=' . $id);
    exit;
}
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-3 text-emerald-600">
            <i data-lucide="user-cog" class="w-6 h-6"></i>
            <h3 class="text-xl font-black text-slate-900">Customer Profile</h3>
        </div>
        
        <form method="POST" onsubmit="return confirm('Permanently delete this customer?')" class="inline">
            <input type="hidden" name="update_customer" value="1">
            <button name="delete" value="1" class="text-red-400 hover:text-red-600 transition-colors p-2 rounded-xl hover:bg-red-50">
                <i data-lucide="trash-2" class="w-5 h-5"></i>
            </button>
        </form>
    </div>

    <form method="POST" class="space-y-6">
        <input type="hidden" name="update_customer" value="1">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="md:col-span-2">
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Full Name</label>
                <input name="name" required value="<?= e($customer['name']) ?>" class="w-full border-gray-100 bg-gray-50/50 rounded-2xl p-4 focus:ring-emerald-500 focus:bg-white transition-all text-sm font-semibold">
            </div>

            <div>
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Company</label>
                <input name="company" value="<?= e($customer['company']) ?>" class="w-full border-gray-100 bg-gray-50/50 rounded-2xl p-4 focus:ring-emerald-500 focus:bg-white transition-all text-sm font-semibold">
            </div>

            <div>
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Tax ID</label>
                <input name="tax_number" value="<?= e($customer['tax_number']) ?>" class="w-full border-gray-100 bg-gray-50/50 rounded-2xl p-4 focus:ring-emerald-500 focus:bg-white transition-all text-sm font-semibold">
            </div>

            <div>
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Email</label>
                <input name="email" type="email" value="<?= e($customer['email']) ?>" class="w-full border-gray-100 bg-gray-50/50 rounded-2xl p-4 focus:ring-emerald-500 focus:bg-white transition-all text-sm font-semibold">
            </div>

            <div>
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Phone</label>
                <input name="phone" value="<?= e($customer['phone']) ?>" class="w-full border-gray-100 bg-gray-50/50 rounded-2xl p-4 focus:ring-emerald-500 focus:bg-white transition-all text-sm font-semibold">
            </div>

            <div class="md:col-span-2">
                <label class="block mb-2 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Address</label>
                <textarea name="address" rows="3" class="w-full border-gray-100 bg-gray-50/50 rounded-2xl p-4 focus:ring-emerald-500 focus:bg-white transition-all text-sm font-semibold"><?= e($customer['address']) ?></textarea>
            </div>
        </div>

        <div class="flex items-center justify-end pt-4 border-t border-gray-50">
            <button class="bg-[#1a1a1a] hover:bg-emerald-600 text-white px-10 py-4 rounded-2xl font-black shadow-lg shadow-gray-200 transition-all hover:scale-105 active:scale-95 uppercase tracking-widest text-[10px]">
                Update Profile
            </button>
        </div>
    </form>
</div>
