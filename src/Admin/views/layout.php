<?php use App\Admin\Html; /** @var string $title @var string $content @var bool $bare @var ?array $admin @var array $menu @var array $flash */ ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= Html::e($title) ?> · myClermont admin</title>
<link rel="stylesheet" href="/admin-assets/admin.css">
<script src="/admin-assets/admin.js" defer></script>
</head>
<body class="<?= $bare ? 'bare' : '' ?>">
<?php if (!$bare): ?>
<header class="topbar">
  <button class="menu-toggle" type="button" aria-label="Menu" data-toggle-menu>☰</button>
  <a class="brand" href="/admin">myClermont <span>admin</span></a>
  <div class="who">
    <a href="/admin/account"><?= Html::e($admin['name']) ?></a> <span class="pill"><?= Html::e($admin['role']) ?></span>
    <form method="post" action="/admin/logout" class="inline"><input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>"><button class="link">Déconnexion</button></form>
  </div>
</header>
<nav class="sidebar" id="sidebar">
  <a class="nav-item<?= $path === '/admin' ? ' active' : '' ?>" href="/admin">Tableau de bord</a>
  <?php foreach ($menu as $group => $resources): ?>
    <div class="nav-group"><?= Html::e($group) ?></div>
    <?php foreach ($resources as $table => $r): ?>
      <a class="nav-item<?= str_starts_with($path, '/admin/r/' . $table) && ($path === '/admin/r/' . $table || str_starts_with($path, '/admin/r/' . $table . '/')) ? ' active' : '' ?>" href="/admin/r/<?= Html::e($table) ?>"><?= Html::e($r->label) ?></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<main class="main">
  <?php foreach ($flash as [$type, $message]): ?>
    <div class="flash flash-<?= Html::e($type) ?>"><?= Html::e($message) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
