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
  <a class="nav-item<?= $path === '/admin' ? ' active' : '' ?>" href="/admin"><span class="ico">🏠</span>Accueil</a>
  <a class="nav-item<?= $path === '/admin/moderation' ? ' active' : '' ?>" href="/admin/moderation"><span class="ico">✅</span>À modérer<?php if ($pending > 0): ?> <span class="badge"><?= $pending ?></span><?php endif; ?></a>
  <?php foreach ($menu as $group => $resources):
    $isActive = false;
    foreach ($resources as $table => $r) { $isActive = $isActive || $path === '/admin/r/' . $table || str_starts_with($path, '/admin/r/' . $table . '/'); }
    $collapsible = $group === 'Réglages'; ?>
    <?php if ($collapsible): ?><details class="nav-details"<?= $isActive ? ' open' : '' ?>><summary class="nav-group">⚙️ <?= Html::e($group) ?></summary><?php else: ?><div class="nav-group"><?= Html::e($group) ?></div><?php endif; ?>
    <?php foreach ($resources as $table => $r): ?>
      <a class="nav-item<?= $path === '/admin/r/' . $table || str_starts_with($path, '/admin/r/' . $table . '/') ? ' active' : '' ?>" href="/admin/r/<?= Html::e($table) ?>"><?php if (!empty($r->config['icon'])): ?><span class="ico"><?= Html::e($r->config['icon']) ?></span><?php endif; ?><?= Html::e($r->label) ?></a>
    <?php endforeach; ?>
    <?php if ($collapsible): ?></details><?php endif; ?>
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
