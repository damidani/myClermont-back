<?php use App\Admin\Html; use App\Modules\Premium\PlusGifts;
/** @var list<array> $rows @var string $q @var string $filter @var array $stats @var string $csrf */
?>
<div class="page-head"><div><div class="crumb">Utilisateurs de l'app</div><h1>Offrir My Clermont+</h1></div></div>
<p class="muted"><strong><?= (int) $stats['members'] ?></strong> membre(s) actif(s), dont <strong><?= (int) $stats['gifts'] ?></strong> offert(s).
  My Clermont+ offert : Clermont IA illimitée et badge Membre. L'utilisateur reçoit une notification ; à l'échéance, il repasse en compte gratuit (sauf s'il s'abonne sur l'App Store).</p>
<form class="filters card" method="get" action="/admin/plus">
  <input name="q" placeholder="Pseudo, e-mail ou prénom" value="<?= Html::e($q) ?>" autofocus>
  <select name="filter"><option value="">Tous les comptes</option>
    <option value="member"<?= $filter === 'member' ? ' selected' : '' ?>>Membres actifs</option>
    <option value="gift"<?= $filter === 'gift' ? ' selected' : '' ?>>My Clermont+ offert</option></select>
  <button class="btn">Chercher</button>
</form>
<section class="card table-wrap">
<?php if ($q === '' && $filter === ''): ?><p class="muted">Cherche un utilisateur par pseudo, e-mail ou prénom, ou affiche les membres.</p>
<?php elseif (!$rows): ?><p class="muted">Aucun utilisateur trouvé.</p><?php else: ?>
<table class="table"><thead><tr><th>Utilisateur</th><th>My Clermont+</th><th>Offrir / prolonger</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $u):
  $member = $u['premium_until'] !== null && strtotime($u['premium_until'] . ' UTC') > time();
  $gift = $u['plus_gift_until'] !== null && strtotime($u['plus_gift_until'] . ' UTC') > time(); ?>
  <tr>
    <td><strong><?= $u['handle'] ? '@' . Html::e($u['handle']) : Html::e($u['first_name'] ?: '—') ?></strong><br>
      <span class="muted"><?= Html::e($u['email'] ?? '') ?><?= $u['city'] ? ' · ' . Html::e($u['city']) : '' ?> · inscrit le <?= Html::e(Html::localDate($u['created_at'], 'd/m/Y')) ?></span></td>
    <td><?php if ($member): ?><span class="pill pill-active">Membre</span><br>
        <span class="muted"><?= PlusGifts::forever($u['premium_until']) ? 'sans limite' : 'jusqu\'au ' . Html::e(Html::localDate($u['premium_until'], 'd/m/Y')) ?>
        · <?= $u['premium_source'] === 'gift' ? 'offert' : ($u['premium_source'] === 'apple' ? 'abonnement App Store' : Html::e((string) $u['premium_source'])) ?>
        <?= $gift && $u['plus_gift_by'] ? '<br>offert par ' . Html::e($u['plus_gift_by']) : '' ?><?= $gift && $u['plus_gift_note'] ? '<br>« ' . Html::e($u['plus_gift_note']) . ' »' : '' ?></span>
      <?php else: ?><span class="pill">Gratuit</span><?php endif; ?></td>
    <td><form method="post" action="/admin/plus" class="inline"><input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
        <input type="hidden" name="id" value="<?= Html::e($u['uid']) ?>"><input type="hidden" name="q" value="<?= Html::e($q) ?>"><input type="hidden" name="filter" value="<?= Html::e($filter) ?>">
        <input type="hidden" name="action" value="offer">
        <select name="months"><?php foreach (PlusGifts::DURATIONS as $m => $l): ?><option value="<?= $m ?>"><?= Html::e($l) ?></option><?php endforeach; ?></select>
        <input name="note" maxlength="300" placeholder="Note interne (facultatif)">
        <button class="btn small primary"><?= $gift ? 'Prolonger' : 'Offrir' ?></button></form></td>
    <td><?php if ($gift): ?><form method="post" action="/admin/plus" class="inline" data-confirm="Retirer My Clermont+ offert ?">
        <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>"><input type="hidden" name="id" value="<?= Html::e($u['uid']) ?>">
        <input type="hidden" name="q" value="<?= Html::e($q) ?>"><input type="hidden" name="filter" value="<?= Html::e($filter) ?>">
        <button class="btn small danger" name="action" value="revoke">Retirer</button></form><?php endif; ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table><?php endif; ?>
</section>
