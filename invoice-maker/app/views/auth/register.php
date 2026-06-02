<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
        $stmt->execute([$name, $email, $password]);

        header('Location: ' . BASE_URL . '/?page=login');
        exit;
    } catch (Exception $e) {
        $error = 'Unable to register. Email may already exist.';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center">
    <form method="POST" class="bg-white p-8 rounded-2xl shadow w-full max-w-md">
        <h1 class="text-2xl font-bold mb-6">Create Account</h1>

        <?php if ($error): ?>
            <div class="bg-red-50 text-red-600 p-3 rounded mb-4"><?= e($error) ?></div>
        <?php endif; ?>

        <label class="block mb-2">Name</label>
        <input name="name" required class="w-full border rounded-lg p-3 mb-4">

        <label class="block mb-2">Email</label>
        <input name="email" type="email" required class="w-full border rounded-lg p-3 mb-4">

        <label class="block mb-2">Password</label>
        <input name="password" type="password" required class="w-full border rounded-lg p-3 mb-6">

        <button class="w-full bg-slate-900 text-white p-3 rounded-lg">Register</button>

        <p class="text-sm text-center mt-4">
            Already have an account?
            <a class="text-blue-600" href="<?= BASE_URL ?>/?page=login">Login</a>
        </p>
    </form>
</body>
</html>