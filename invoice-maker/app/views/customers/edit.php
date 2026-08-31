<?php
$id = $_GET['id'] ?? null;

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND company_id = ?");
$stmt->execute([$id, current_company_id()]);
$customer = $stmt->fetch();

if (!$customer) {
    echo '<div class="biz-notice biz-notice-red">Client not found.</div>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_customer'])) {
    if (isset($_POST['delete'])) {
        $delete = $pdo->prepare("DELETE FROM customers WHERE id = ? AND company_id = ?");
        $delete->execute([$id, current_company_id()]);

        redirect_response(BASE_URL . '/?page=customers');
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

    redirect_response(BASE_URL . '/?page=customers&id=' . $id);
}
?>

<div class="biz">
    <div class="flex items-center justify-between mb-3">
        <div>
            <p class="biz-kicker">Client</p>
            <h2 class="mt-0.5" style="font-size:15px"><?= e($customer['name']) ?></h2>
        </div>
        <form method="POST" onsubmit="return confirm('Permanently delete this client?')">
            <input type="hidden" name="update_customer" value="1">
            <button name="delete" value="1" class="biz-btn biz-btn-danger biz-btn-sm"><i data-lucide="trash-2" class="w-3 h-3"></i> Delete</button>
        </form>
    </div>

    <form method="POST" class="space-y-3">
        <input type="hidden" name="update_customer" value="1">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <label class="block"><span class="biz-label">Full name</span>
                <input name="name" required value="<?= e($customer['name']) ?>" class="biz-input"></label>
            <label class="block"><span class="biz-label">Company</span>
                <input name="company" value="<?= e($customer['company']) ?>" class="biz-input"></label>
            <label class="block"><span class="biz-label">Tax ID</span>
                <input name="tax_number" value="<?= e($customer['tax_number']) ?>" class="biz-input"></label>
            <label class="block"><span class="biz-label">Email</span>
                <input name="email" type="email" value="<?= e($customer['email']) ?>" class="biz-input"></label>
            <label class="block"><span class="biz-label">Phone</span>
                <input name="phone" value="<?= e($customer['phone']) ?>" class="biz-input"></label>
        </div>
        <label class="block"><span class="biz-label">Address</span>
            <textarea name="address" rows="3" class="biz-input"><?= e($customer['address']) ?></textarea></label>
        <div class="flex justify-end pt-1">
            <button class="biz-btn biz-btn-primary">Update client</button>
        </div>
    </form>
</div>
