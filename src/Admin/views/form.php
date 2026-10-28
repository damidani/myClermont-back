<?php use App\Admin\Html;
/** @var App\Admin\Resource $r */
$base = '/r/' . $r->table;
$action = $row === null ? Html::url($base . '/new') : Html::url($base . '/edit');
?>
<div class="page-head">
  <div><div class="crumb"><a href="<?= Html::e(Html::url($base)) ?>"><?= Html::e($r->label) ?></a></div><h1><?= Html::e(mb_strimwidth($title, 0, 80, '…')) ?></h1></div>
  <?php if ($row !== null && !$readonly): ?>
  <div class="actions">
    <form method="post" action="<?= Html::e(Html::url($base . '/delete')) ?>" class="inline" data-confirm="Supprimer définitivement cet élément ?">
      <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>"><input type="hidden" name="key" value="<?= Html::e($key) ?>">
      <button class="btn danger">Supprimer</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if (isset($errors['_'])): ?><div class="flash flash-error"><?= Html::e($errors['_']) ?></div><?php elseif ($errors): ?><div class="flash flash-error">Corrigez les champs signalés.</div><?php endif; ?>

<<?= $readonly ? 'div' : 'form method="post" action="' . Html::e($action) . '"' ?> class="card form" novalidate>
  <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
  <?php if ($key !== null): ?><input type="hidden" name="key" value="<?= Html::e($key) ?>"><?php endif; ?>
  <div class="fields">
  <?php foreach ($r->fields as $name => $f):
      if ($f['readonly'] && $row === null) continue;
      if ($readonly) $f['readonly'] = true; ?>
    <?= Html::field($f, $values[$name] ?? null, $errors[$name] ?? null, $options[$name] ?? null, $relationLabels[$name] ?? [], $errors !== [] && array_key_exists($name, $posted)) ?>
  <?php endforeach; ?>
  <?php if ($r->passwordColumn() !== null && !$readonly): ?>
    <div class="field<?= isset($errors['_password']) ? ' has-error' : '' ?>">
      <label for="f__password"><?= $row === null ? 'Mot de passe <span class="req">*</span>' : 'Nouveau mot de passe' ?></label>
      <input type="password" id="f__password" name="_password" autocomplete="new-password" minlength="10">
      <?php if (isset($errors['_password'])): ?><p class="error"><?= Html::e($errors['_password']) ?></p><?php endif; ?>
      <p class="hint"><?= $row === null ? '10 caractères minimum.' : 'Laisser vide pour ne pas le changer.' ?></p>
    </div>
  <?php endif; ?>
  </div>
  <?php if (!$readonly): ?>
  <div class="form-actions"><button class="btn primary"><?= $row === null ? 'Créer' : 'Enregistrer' ?></button> <a class="btn link" href="<?= Html::e(Html::url($base)) ?>">Annuler</a></div>
  <?php endif; ?>
</<?= $readonly ? 'div' : 'form' ?>>

<?php if ($history): ?>
<section class="card">
  <h2>Historique</h2>
  <table class="table compact"><tbody>
  <?php foreach ($history as $h): $changes = json_decode((string) $h['changes'], true) ?: []; ?>
    <tr><td class="muted nowrap"><?= Html::e(Html::localDate($h['created_at'], 'd/m/Y H:i')) ?></td><td><?= Html::e($h['admin_name'] ?? '—') ?></td><td><span class="pill"><?= Html::e($h['action']) ?></span></td>
      <td><?php if ($h['action'] === 'update'): foreach ($changes as $col => $c): ?>
        <div><code><?= Html::e($col) ?></code> : <span class="muted"><?= Html::e(mb_strimwidth(is_array($c) ? (string) json_encode($c[0] ?? null, JSON_UNESCAPED_UNICODE) : (string) $c, 0, 60, '…')) ?></span> → <?= Html::e(mb_strimwidth(is_array($c) ? (string) json_encode($c[1] ?? null, JSON_UNESCAPED_UNICODE) : '', 0, 60, '…')) ?></div>
      <?php endforeach; endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>
