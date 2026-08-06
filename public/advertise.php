<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: sell.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
