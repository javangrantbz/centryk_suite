<?php
/**
 * Shared <head> assets + design tokens for the Centryk Business module pages
 * (receivables, reconciliation, routes, groups, the admin console).
 *
 * These pages get a denser, "desktop line-of-business application" treatment
 * than the rest of the hub — system UI font, tight padding, small radius,
 * borders rather than whitespace to divide regions, restrained colour. The
 * Centryk account header rendered above the .biz wrapper keeps its own styling,
 * so moving between the hub and a Business tool stays a smooth step, not a jump.
 *
 * Usage:
 *   <head><?php $bizTitle = 'Receivables'; include __DIR__ . '/partials/business_head.php'; ?></head>
 *   ...
 *   <div class="biz mx-auto max-w-6xl px-4 py-4"> ...tool content... </div>
 */
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<title><?= htmlspecialchars(($bizTitle ?? 'Centryk Business') . ' — Centryk') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
<script src="https://unpkg.com/lucide@latest"></script>
<?php include __DIR__ . '/biz-system.php'; ?>
