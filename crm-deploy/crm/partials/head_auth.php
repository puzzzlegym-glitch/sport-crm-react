<?php
/**
 * partials/head_auth.php — <head> для login/register
 */

$pageTitle = $pageTitle ?? 'Sport CRM';
$pageCss   = $pageCss   ?? null;
$extraCss  = $extraCss  ?? null;
$fullTitle = $pageTitle . ' — Sport CRM';
$v = defined('APP_VERSION') ? APP_VERSION : '1';
?>
<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?= htmlspecialchars($fullTitle) ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $v ?>">
  <?php if ($pageCss): ?>
  <link rel="stylesheet" href="/assets/css/<?= htmlspecialchars($pageCss) ?>.css?v=<?= $v ?>">
  <?php endif; ?>
  <?php if ($extraCss): ?>
  <style><?= $extraCss ?></style>
  <?php endif; ?>
</head>