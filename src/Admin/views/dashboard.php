<?php use App\Admin\Html; ?>
<div class="page-head"><h1>Tableau de bord</h1>
<?php if ($role === 'admin'): ?>
  <form method="post" action="/admin/cache/clear" class="inline"><input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>"><button class="btn">Vider les caches de l'app</button></form>
<?php endif; ?>
</div>
<?php if ($stats): ?>
<div class="stats">
  <?php foreach ($stats as [$label, $n, $table]): ?>
    <a class="stat card" href="/admin/r/<?= Html::e($table) ?>"><span class="n"><?= $n === null ? '—' : number_format($n, 0, ',', "\u{202F}") ?></span><span><?= Html::e($label) ?></span></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="grid2">
  <section class="card">
    <h2>À traiter</h2>
    <?php if (!$todo): ?><p class="muted">Rien en attente. 🎉</p><?php endif; ?>
    <ul class="todo">
      <?php foreach ($todo as [$label, $n, $table, $filter]): ?>
        <li><a href="<?= Html::e(Html::url('/r/' . $table, ['f' => $filter])) ?>"><?= Html::e($label) ?></a> <span class="badge"><?= $n ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php if ($imports): ?>
  <section class="card">
    <h2>Derniers imports</h2>
    <table class="table compact"><tbody>
      <?php foreach ($imports as $i): ?>
        <tr><td><?= Html::e($i['name']) ?> → <?= Html::e($i['target']) ?></td><td><span class="pill pill-<?= Html::e($i['status']) ?>"><?= Html::e($i['status']) ?></span></td>
            <td class="muted">+<?= (int) $i['created'] ?> / ~<?= (int) $i['updated'] ?><?= $i['errors'] ? ' / ' . (int) $i['errors'] . ' err.' : '' ?></td>
            <td class="muted"><?= Html::e(Html::localDate($i['started_at'], 'd/m H:i')) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </section>
  <?php endif; ?>
  <?php if ($audit): ?>
  <section class="card">
    <h2>Dernières actions</h2>
    <table class="table compact"><tbody>
      <?php foreach ($audit as $a): ?>
        <tr><td><?= Html::e($a['name'] ?? '—') ?></td><td><span class="pill"><?= Html::e($a['action']) ?></span></td>
            <td><?php if ($a['resource'] && $a['record_key'] && $a['action'] !== 'delete'): ?><a href="<?= Html::e(Html::url('/r/' . $a['resource'] . '/edit', ['key' => $a['record_key']])) ?>"><?= Html::e($a['resource'] . ' #' . $a['record_key']) ?></a><?php else: ?><?= Html::e(trim(($a['resource'] ?? '') . ' ' . ($a['record_key'] ?? ''))) ?><?php endif; ?></td>
            <td class="muted"><?= Html::e(Html::localDate($a['created_at'], 'd/m H:i')) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </section>
  <?php endif; ?>
</div>
