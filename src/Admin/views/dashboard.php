<?php use App\Admin\Html; ?>
<div class="page-head"><div><div class="crumb">myClermont</div><h1>Bonjour 👋</h1></div>
<?php if ($role === 'admin'): ?>
  <form method="post" action="/admin/cache/clear" class="inline" title="À faire si une modification n'apparaît pas tout de suite dans l'app"><input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>"><button class="btn">Rafraîchir l'app</button></form>
<?php endif; ?>
</div>

<a class="card callout<?= $pending > 0 ? ' callout-warn' : ' callout-ok' ?>" href="/admin/moderation">
  <span class="big"><?= $pending > 0 ? '✋' : '✅' ?></span>
  <span><strong><?= $pending > 0 ? $pending . ' élément' . ($pending > 1 ? 's' : '') . ' à modérer' : 'Rien à modérer' ?></strong><br>
  <span class="muted"><?= $pending > 0 ? 'Publications, avis, événements proposés et signalements qui attendent ta décision.' : 'Tout ce que les utilisateurs ont publié est en ligne.' ?></span></span>
  <?php if ($pending > 0): ?><span class="btn primary">Ouvrir →</span><?php endif; ?>
</a>

<?php if ($quick): ?>
<section class="quick">
  <span class="muted">Ajouter :</span>
  <?php foreach ($quick as [$label, $table]): ?><a class="btn" href="/admin/r/<?= Html::e($table) ?>/new">+ <?= Html::e($label) ?></a><?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($stats): ?>
<h2 class="section-title">Dans l'app en ce moment</h2>
<div class="stats">
  <?php foreach ($stats as [$label, $n, $table]): ?>
    <a class="stat card" href="/admin/r/<?= Html::e($table) ?>"><span class="n"><?= $n === null ? '—' : number_format($n, 0, ',', "\u{202F}") ?></span><span><?= Html::e($label) ?></span></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid2">
  <?php if ($todo): ?>
  <section class="card">
    <h2>À vérifier</h2>
    <ul class="todo">
      <?php foreach ($todo as [$label, $n, $table, $filter]): ?>
        <li><a href="<?= Html::e(Html::url('/r/' . $table, ['f' => $filter])) ?>"><?= Html::e($label) ?></a> <span class="badge"><?= $n ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
  <?php if ($services): ?>
  <section class="card">
    <h2>Services branchés</h2>
    <ul class="todo">
      <?php foreach ($services as [$label, $ok]): ?>
        <li><span><?= Html::e($label) ?></span> <?= $ok ? '<span class="pill pill-published">Actif</span>' : '<span class="pill pill-failed" title="Clé à installer sur le serveur : la fonction répond « indisponible » dans l\'app">Non configuré</span>' ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
  <?php if ($agents): ?>
  <section class="card">
    <h2>🤖 Canal agents</h2>
    <table class="table compact"><tbody>
      <?php foreach ($agents as $a): ?>
        <tr><td><strong><?= Html::e(Html::valueLabel($a['agent']) === $a['agent'] ? ucfirst($a['agent']) : Html::valueLabel($a['agent'])) ?></strong><br><span class="muted"><?= Html::e($a['app_version'] ?? '') ?></span></td>
            <td><?= Html::e($a['last_report'] ?? '—') ?></td>
            <td class="muted nowrap"><?= Html::e(Html::localDate($a['last_seen_at'], 'd/m H:i')) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <p><a href="/admin/r/agent_messages">Voir les messages →</a></p>
  </section>
  <?php endif; ?>
  <?php if ($imports): ?>
  <section class="card">
    <h2>Derniers imports automatiques</h2>
    <table class="table compact"><tbody>
      <?php foreach ($imports as $i): ?>
        <tr><td><?= Html::e($i['name']) ?> → <?= Html::e($i['target']) ?></td><td><span class="pill pill-<?= Html::e($i['status']) ?>"><?= Html::e(Html::valueLabel($i['status'])) ?></span></td>
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
