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
<style>
  /* ── Centryk Business — dense tool surface ─────────────────────────── */
  .biz {
    --bz-accent:    #4f46e5;
    --bz-accent-d:  #4338ca;
    --bz-line:      #e2e8f0;
    --bz-line-soft: #eef2f6;
    --bz-head:      #f8fafc;
    --bz-fg:        #1e293b;
    --bz-muted:     #64748b;
    --bz-faint:     #94a3b8;
    font-family: -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 13px;
    line-height: 1.45;
    color: var(--bz-fg);
  }
  .biz h1 { font-size: 17px; font-weight: 700; letter-spacing: -0.01em; color: #0f172a; }
  .biz h2 { font-size: 13px; font-weight: 700; }
  .biz a  { color: inherit; }
  .biz ::placeholder { color: var(--bz-faint); }

  .biz-kicker { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.09em; color: var(--bz-muted); }
  .biz-muted  { color: var(--bz-muted); }
  .biz-num    { font-variant-numeric: tabular-nums; }

  /* Panels — bordered boxes, minimal chrome */
  .biz-panel { border: 1px solid var(--bz-line); border-radius: 4px; background: #fff; }
  .biz-panel-head {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 5px 10px; border-bottom: 1px solid var(--bz-line); background: var(--bz-head);
    font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--bz-muted);
  }
  .biz-panel-body  { padding: 10px; }
  .biz-panel-empty { padding: 22px 12px; text-align: center; color: var(--bz-faint); font-size: 12px; }

  /* Rows — bordered list, no gaps, hover highlight */
  .biz-row {
    display: flex; align-items: center; gap: 10px; width: 100%;
    padding: 5px 10px; text-align: left; background: #fff;
    border-top: 1px solid var(--bz-line-soft);
  }
  .biz-list > .biz-row:first-child { border-top: 0; }
  button.biz-row, a.biz-row { cursor: pointer; }
  button.biz-row:hover, a.biz-row:hover { background: var(--bz-head); }
  .biz-row.is-active { background: #eef2ff; }

  /* Inputs — text sits tight in the box, small radius, thin border */
  .biz-input, .biz-select {
    height: 28px; width: 100%; border: 1px solid #cbd5e1; border-radius: 3px;
    padding: 0 7px; font: inherit; font-size: 13px; background: #fff; color: var(--bz-fg);
  }
  textarea.biz-input { height: auto; min-height: 60px; padding: 5px 7px; line-height: 1.4; resize: vertical; }
  .biz-input:focus, .biz-select:focus { outline: none; border-color: var(--bz-accent); box-shadow: 0 0 0 1px var(--bz-accent); }
  .biz-input:disabled, .biz-select:disabled { background: #f1f5f9; color: var(--bz-faint); }
  .biz-label { display: block; font-size: 11px; font-weight: 600; color: var(--bz-muted); margin-bottom: 3px; }

  /* Buttons — compact, quiet weight */
  .biz-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 5px;
    height: 28px; padding: 0 11px; border-radius: 3px; font: inherit; font-size: 12px; font-weight: 600;
    border: 1px solid transparent; cursor: pointer; white-space: nowrap;
  }
  .biz-btn:disabled { opacity: 0.5; cursor: default; }
  .biz-btn-primary { background: var(--bz-accent); color: #fff; }
  .biz-btn-primary:hover:not(:disabled) { background: var(--bz-accent-d); }
  .biz-btn-ghost { background: #fff; border-color: #cbd5e1; color: #334155; }
  .biz-btn-ghost:hover:not(:disabled) { background: var(--bz-head); }
  .biz-btn-danger { background: #fff; border-color: #fecaca; color: #dc2626; }
  .biz-btn-danger:hover:not(:disabled) { background: #fef2f2; }
  .biz-btn-sm { height: 22px; padding: 0 8px; font-size: 11px; border-radius: 2px; }

  /* Stat tiles */
  .biz-tile { border: 1px solid var(--bz-line); border-radius: 4px; padding: 7px 9px; background: #fff; }
  .biz-tile-l { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--bz-muted); }
  .biz-tile-v { font-size: 15px; font-weight: 700; margin-top: 1px; font-variant-numeric: tabular-nums; }

  /* Chips / status */
  .biz-chip {
    display: inline-block; padding: 0 5px; border-radius: 2px; line-height: 15px;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em;
  }
  .biz-c-red   { background: #fef2f2; color: #b91c1c; }
  .biz-c-green { background: #ecfdf5; color: #047857; }
  .biz-c-amber { background: #fffbeb; color: #b45309; }
  .biz-c-blue  { background: #eff6ff; color: #1d4ed8; }
  .biz-c-slate { background: #f1f5f9; color: #475569; }
  .biz-t-red   { color: #dc2626; }
  .biz-t-green { color: #059669; }
  .biz-t-blue  { color: #2563eb; }
  .biz-t-amber { color: #b45309; }

  /* Segmented control (e.g. company / status switch) */
  .biz-seg { display: inline-flex; border: 1px solid var(--bz-line); border-radius: 3px; overflow: hidden; }
  .biz-seg > * { padding: 3px 9px; font-size: 11px; font-weight: 600; color: var(--bz-muted); background: #fff; border-left: 1px solid var(--bz-line); }
  .biz-seg > *:first-child { border-left: 0; }
  .biz-seg > .is-active { background: #eef2ff; color: var(--bz-accent-d); }

  /* Inline notice */
  .biz-notice { border: 1px solid var(--bz-line); border-radius: 3px; padding: 6px 10px; font-size: 12px; font-weight: 500; }
  .biz-notice-amber { border-color: #fcd9a5; background: #fffbeb; color: #92400e; }
  .biz-notice-red   { border-color: #fecaca; background: #fef2f2; color: #b91c1c; }
  .biz-notice-green { border-color: #bbf7d0; background: #f0fdf4; color: #15803d; }
</style>
