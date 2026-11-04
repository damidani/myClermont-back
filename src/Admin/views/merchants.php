<?php use App\Admin\Html; use App\Modules\Pro\OfferedPlans;
/** @var list<array> $rows @var string $q @var string $plan @var string $csrf */
?>
<div class="page-head"><div><div class="crumb">Commerçants</div><h1>Offrir la formule Partenaire <span class="muted count"><?= count($rows) ?></span></h1></div></div>
<form class="filters card" method="get" action="/admin/merchants">
  <input name="q" placeholder="Nom, SIRET ou e-mail" value="<?= Html::e($q) ?>">
  <select name="plan"><option value="">Toutes les formules</option>
    <option value="gratuit"<?= $plan === 'gratuit' ? ' selected' : '' ?>>Gratuit</option>
    <option value="partenaire"<?= $plan === 'partenaire' ? ' selected' : '' ?>>Partenaire</option></select>
  <button class="btn">Filtrer</button>
</form>
<p class="muted">La formule offerte donne tous les avantages Partenaire (badge sur la carte, bons plans et événements Flash illimités). À l'échéance, le compte repasse automatiquement en gratuit et le commerçant est prévenu.</p>
<section class="card table-wrap">
<?php if (!$rows): ?><p class="muted">Aucun commerçant.</p><?php else: ?>
<table class="table"><thead><tr><th>Commerçant</th><th>Vérification</th><th>Formule</th><th>Offrir / prolonger</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $b): $partner = $b['plan'] === 'partenaire'; ?>
  <tr>
    <td><strong><?= Html::e($b['trade_name']) ?></strong><br><span class="muted"><?= Html::e($b['owner_email'] ?? '') ?><?= $b['siret'] ? ' · SIRET ' . Html::e($b['siret']) : '' ?></span></td>
    <td><span class="pill<?= $b['verification'] === 'verifie' ? ' pill-success' : ' pill-pending' ?>"><?= Html::e(str_replace('_', ' ', $b['verification'])) ?></span></td>
    <td><?php if ($partner): ?><span class="pill pill-active">Partenaire</span><br>
        <span class="muted"><?= $b['plan_until'] ? 'jusqu\'au ' . Html::e(Html::localDate($b['plan_until'], 'd/m/Y')) : ($b['plan_offered_by'] ? 'offert, sans limite' : 'payant') ?>
        <?= $b['plan_offered_by'] ? '<br>offert par ' . Html::e($b['plan_offered_by']) : '' ?><?= $b['plan_note'] ? '<br>« ' . Html::e($b['plan_note']) . ' »' : '' ?></span>
      <?php else: ?><span class="pill">Gratuit</span><?php endif; ?></td>
    <td><form method="post" action="/admin/merchants" class="inline"><input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
        <input type="hidden" name="id" value="<?= Html::e($b['uid']) ?>"><input type="hidden" name="q" value="<?= Html::e($q) ?>"><input type="hidden" name="action" value="offer">
        <select name="months"><?php foreach (OfferedPlans::DURATIONS as $m => $l): ?><option value="<?= $m ?>"><?= Html::e($l) ?></option><?php endforeach; ?></select>
        <input name="note" maxlength="300" placeholder="Note interne (facultatif)">
        <button class="btn small primary"><?= $partner ? 'Prolonger' : 'Offrir' ?></button></form></td>
    <td><?php if ($partner): ?><form method="post" action="/admin/merchants" class="inline" data-confirm="Repasser <?= Html::e($b['trade_name']) ?> en formule gratuite ?">
        <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>"><input type="hidden" name="id" value="<?= Html::e($b['uid']) ?>"><input type="hidden" name="q" value="<?= Html::e($q) ?>">
        <button class="btn small danger" name="action" value="revoke">Retirer</button></form><?php endif; ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table><?php endif; ?>
</section>
