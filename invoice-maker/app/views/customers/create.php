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

<div class="biz">
    <p class="biz-kicker">New client</p>
    <h2 class="mt-0.5 mb-3" style="font-size:15px">Client profile</h2>

    <form method="POST" class="space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <label class="block"><span class="biz-label">Full name</span>
                <input name="name" required class="biz-input" placeholder="e.g. John Doe"></label>
            <label class="block"><span class="biz-label">Company</span>
                <input name="company" class="biz-input" placeholder="Business name"></label>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <label class="block"><span class="biz-label">Tax ID</span>
                <input name="tax_number" class="biz-input" placeholder="Tax ID"></label>
            <label class="block"><span class="biz-label">Email</span>
                <input name="email" type="email" class="biz-input" placeholder="email@client.com"></label>
            <label class="block"><span class="biz-label">Phone</span>
                <input name="phone" class="biz-input" placeholder="+501 …"></label>
        </div>
        <label class="block"><span class="biz-label">Address</span>
            <textarea name="address" rows="3" class="biz-input" placeholder="Billing address"></textarea></label>

        <div class="flex items-center justify-end gap-2 pt-1">
            <a href="<?= BASE_URL ?>/?page=customers" class="biz-btn biz-btn-ghost">Cancel</a>
            <button name="save_customer" value="1" class="biz-btn biz-btn-primary">Create client</button>
        </div>
    </form>
</div>
