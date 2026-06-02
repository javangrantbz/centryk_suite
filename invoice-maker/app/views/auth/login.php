<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email']
        ];

        header('Location: ' . BASE_URL . '/?page=dashboard');
        exit;
    }

    $error = 'Invalid login details.';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center">
    <form method="POST" class="bg-white p-8 rounded-2xl shadow w-full max-w-md">
        <h1 class="text-2xl font-bold mb-6">Login</h1>

        <?php if ($error): ?>
            <div class="bg-red-50 text-red-600 p-3 rounded mb-4"><?= e($error) ?></div>
        <?php endif; ?>

        <label class="block mb-2">Email</label>
        <input name="email" type="email" required class="w-full border rounded-lg p-3 mb-4">

        <label class="block mb-2">Password</label>
        <input name="password" type="password" required class="w-full border rounded-lg p-3 mb-6">

        <button class="w-full bg-slate-900 text-white p-3 rounded-lg">Login</button>

        <p class="text-sm text-center mt-4">
            No account?
            <a class="text-blue-600" href="<?= BASE_URL ?>/?page=register">Register</a>
        </p>
    </form>
</body>
</html>