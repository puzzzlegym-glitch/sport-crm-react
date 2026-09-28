<?php
/**
 * partials/head.php — Спільний <head> для всіх сторінок
 *
 * Змінні ДО підключення:
 *   $pageTitle (string)      — напр. 'Дашборд'
 *   $pageCss   (string|null) — slug CSS, напр. 'dashboard'
 */

$pageTitle = $pageTitle ?? 'Sport CRM';
$pageCss   = $pageCss   ?? null;
$fullTitle = $pageTitle . ' — Sport CRM';
// APP_VERSION визначена в bootstrap.php → використовується як ?v= для cache-busting
$v = defined('APP_VERSION') ? APP_VERSION : '1';
?>
<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?= htmlspecialchars($fullTitle) ?></title>

  <!-- Шрифти -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <!-- Стилі з ?v= — при зміні APP_VERSION браузер завантажить новий файл -->
  <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $v ?>">
  <link rel="stylesheet" href="/assets/css/mobile-cards.css?v=<?= $v ?>">
  <?php if ($pageCss): ?>
  <link rel="stylesheet" href="/assets/css/<?= htmlspecialchars($pageCss) ?>.css?v=<?= $v ?>">
  <?php endif; ?>

  <!-- PWA -->
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#4f9cf9">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="SportCRM">
  <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
</head>