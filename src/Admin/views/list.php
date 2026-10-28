<?php use App\Admin\Html;
/** @var App\Admin\Resource $r */
$base = '/r/' . $r->table;
$state = ['q' => $q, 'f' => $filters, 'sort' => $sort, 'dir' => $dir];
?>
<div class="page-head">
  <div><div class="crumb"><?= Html::e($r->group) ?></div><h1><?= Html::e($r->label) ?> <span class="muted count"><?= number_format($result['total'], 0, ',', "\u{202F}") ?></span></h1></div>
  <div class="actions">
    <a class="btn" href="<?= Html::e(Html::url($base . '/export.csv', ['q' => $q, 'f' => $filters])) ?>">Exporter (CSV)</a>
    <?php if ($r->canCreate()): ?><a class="btn primary" href="<?= Html::e(Html::url($base . '/new')) ?>">+ Ajouter</a><?php endif; ?>
  </div>
</div>

<form class="filters card" method="get" action="<?= Html::e(Html::url($base)) ?>">
  <?php if ($r->searchColumns() !== []): ?>
    <input type="search" name="q" value="<?= Html::e($q) ?>" placeholder="Rechercher (<?= Html::e(implode(', ', $r->searchColumns())) ?>, ou un ID)">
  <?php endif; ?>
  <?php foreach ($filterOptions as $name => $options): ?>
    <select name="f[<?= Html::e($name) ?>]" data-autosubmit aria-label="<?= Html::e(Html::columnLabel($name)) ?>">
      <option value=""><?= Html::e(Html::columnLabel($name)) ?> : tous</option>
      <?php foreach ($options as $k => $label): ?>
        <option value="<?= Html::e($k) ?>"<?= (string) ($filters[$name] ?? '') === (string) $k ? ' selected' : '' ?>><?= Html::e($label) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endforeach; ?>
  <?php if ($sort): ?><input type="hidden" name="sort" value="<?= Html::e($sort) ?>"><input type="hidden" name="dir" value="<?= Html::e($dir) ?>"><?php endif; ?>
  <button class="btn">Filtrer</button>
  <?php if ($q !== '' || $filters): ?><a class="btn link" href="<?= Html::e(Html::url($base)) ?>">Effacer</a><?php endif; ?>
</form>

<div class="card table-wrap">
<table class="table">
  <thead><tr>
    <?php foreach ($columns as $c):
      $active = ($sort ?? $r->primary()[0]) === $c;
      $next = $active && $dir === 'desc' ? 'asc' : 'desc'; ?>
      <th><a href="<?= Html::e(Html::url($base, ['sort' => $c, 'dir' => $next] + $state)) ?>"><?= Html::e(Html::columnLabel($c)) ?><?= $active ? ($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
    <?php endforeach; ?>
    <th></th>
  </tr></thead>
  <tbody>
  <?php if (!$result['rows']): ?><tr><td colspan="<?= count($columns) + 1 ?>" class="empty">Aucun résultat.</td></tr><?php endif; ?>
  <?php foreach ($result['rows'] as $row): $editUrl = Html::url($base . '/edit', ['key' => $r->keyOf($row)]); ?>
    <tr data-href="<?= Html::e($editUrl) ?>">
      <?php foreach ($columns as $c): ?><td><?= Html::display($r->fields[$c], $row[$c] ?? null, $labels[$c] ?? []) ?></td><?php endforeach; ?>
      <td class="row-actions"><a href="<?= Html::e($editUrl) ?>"><?= $r->readonly() ? 'Voir' : 'Modifier' ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($result['pages'] > 1): ?>
<nav class="pager">
  <?php if ($result['page'] > 1): ?><a class="btn" href="<?= Html::e(Html::url($base, ['page' => $result['page'] - 1] + $state)) ?>">← Précédent</a><?php endif; ?>
  <span class="muted">Page <?= $result['page'] ?> / <?= $result['pages'] ?></span>
  <?php if ($result['page'] < $result['pages']): ?><a class="btn" href="<?= Html::e(Html::url($base, ['page' => $result['page'] + 1] + $state)) ?>">Suivant →</a><?php endif; ?>
</nav>
<?php endif; ?>
