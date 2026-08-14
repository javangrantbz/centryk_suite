<?php
/**
 * Escape hatch from the mobile-device redirect in index.php - sets a
 * long-lived cookie so this visitor always gets the full desktop site
 * even on a phone, then sends them there.
 */
setcookie('centryk_view', 'desktop', time() + (86400 * 365), '/', '', false, true);
header('Location: index.php');
exit;
