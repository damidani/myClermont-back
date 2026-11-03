<?php use App\Admin\Html;
/** @var array<string, list<array>> $queue @var string $csrf */
$sections = [
    'post'    => ['💬 Publications retenues par le filtre', 'Le texte a déclenché le filtre (insulte, lien suspect). L\'auteur croit sa publication en ligne.'],
    'comment' => ['🗨️ Commentaires retenus', ''],
    'review'  => ['⭐ Avis retenus', 'La note compte déjà dans la moyenne ; seul le texte attend ta décision (souvent un lien).'],
    'event'   => ['🎉 Événements proposés par les utilisateurs', 'Vérifie la date, le lieu et qu\'il s\'agit bien d\'un événement public.'],
    'report'  => ['🚩 Signalements', 'Envoyés depuis l\'app par les utilisateurs.'],
    'pro_place'  => ['🏪 Fiches de commerçants à valider', 'Nouvelle fiche, ou changement de nom, d\'adresse ou de catégorie d\'une fiche en ligne (l\'ancienne version reste visible en attendant).'],
    'pro_review' => ['⭐ Avis signalés par un commerçant', 'L\'avis reste visible mais sort de la moyenne jusqu\'à ta décision.'],
    'pro_story'  => ['📸 Stories des commerçants', 'Déjà en ligne (ou programmées) pour 24 h : retire celles qui ne respectent pas les règles des photos de fiche.'],
    'visitor_photo' => ['📷 Photos des visiteurs', 'Prises depuis un pin sans commerçant vérifié. Validée, la photo rejoint la fiche ; refusée, l\'auteur reçoit le motif (ex. « Photo floue »).'],
    'pro_siret'  => ['🧾 SIRET à vérifier', 'L\'annuaire des entreprises n\'a pas confirmé ce SIRET à l\'inscription.'],
];
// Refus avec motif, transmis au commerçant
$refuse = static fn (string $type, string $id, string $action = 'refuse', string $label = 'Refuser') =>
    '<form method="post" action="/admin/moderation" class="inline">'
    . '<input type="hidden" name="_csrf" value="' . Html::e($csrf) . '"><input type="hidden" name="type" value="' . Html::e($type) . '">'
    . '<input type="hidden" name="id" value="' . Html::e($id) . '"><input type="hidden" name="action" value="' . Html::e($action) . '">'
    . '<input type="text" name="reason" maxlength="300" placeholder="Motif (envoyé au commerçant)"> <button class="btn small">' . Html::e($label) . '</button></form>';
$total = array_sum(array_map('count', $queue));
$button = static fn (string $type, string $id, string $action, string $label, string $class = '', string $confirm = '') =>
    '<form method="post" action="/admin/moderation" class="inline"' . ($confirm ? ' data-confirm="' . Html::e($confirm) . '"' : '') . '>'
    . '<input type="hidden" name="_csrf" value="' . Html::e($csrf) . '"><input type="hidden" name="type" value="' . Html::e($type) . '">'
    . '<input type="hidden" name="id" value="' . Html::e($id) . '"><input type="hidden" name="action" value="' . Html::e($action) . '">'
    . '<button class="btn small ' . $class . '">' . Html::e($label) . '</button></form>';
?>
<div class="page-head"><div><div class="crumb">Modération</div><h1>À modérer <span class="muted count"><?= $total ?></span></h1></div></div>
<?php if ($total === 0): ?>
  <div class="card empty-state"><p class="big">🎉</p><p>Rien en attente. Tout ce que les utilisateurs ont publié est en ligne.</p></div>
<?php endif; ?>
<?php foreach ($queue as $type => $items): if (!$items) continue; [$title, $help] = $sections[$type]; ?>
<section class="card">
  <h2><?= Html::e($title) ?> <span class="badge"><?= count($items) ?></span></h2>
  <?php if ($help): ?><p class="muted"><?= Html::e($help) ?></p><?php endif; ?>
  <div class="mod-list">
  <?php foreach ($items as $it): ?>
    <article class="mod-item">
      <div class="mod-body">
        <?php if ($type === 'event'): ?>
          <strong><?= Html::e($it['title']) ?></strong>
          <div class="muted"><?= Html::e(Html::localDate($it['starts_at'], 'd/m/Y H:i')) ?> · <?= Html::e($it['venue_name'] ?? '') ?> <?= Html::e($it['address'] ?? '') ?></div>
        <?php elseif ($type === 'report'): ?>
          <strong><?= Html::e(Html::valueLabel($it['target_type'])) ?> signalé·e : <?= Html::e(Html::valueLabel($it['reason'])) ?></strong>
          <?php if ((int) $it['reports_on_target'] > 1): ?> <span class="pill pill-failed"><?= (int) $it['reports_on_target'] ?> signalements</span><?php endif; ?>
          <div class="muted">Identifiant : <code><?= Html::e($it['target_id']) ?></code></div>
        <?php elseif ($type === 'pro_place'): $pro = json_decode((string) $it['pro'], true) ?: []; $pend = json_decode((string) $it['pro_pending'], true); ?>
          <strong><?= Html::e($pro['name'] ?? $it['title']) ?></strong> <span class="muted"><?= $it['live'] === 'published' ? '· modification d\'une fiche en ligne' : '· nouvelle fiche' ?></span>
          <div class="muted"><?= Html::e(trim(($pro['address'] ?? '') . ', ' . ($pro['postal_code'] ?? '') . ' ' . ($pro['city'] ?? ''), ', ')) ?> · <?= Html::e($pro['category'] ?? '') ?> · <?= count($pro['photos'] ?? []) ?> photo(s)</div>
          <?php if ($pend): ?><div class="muted">En ligne : <?= Html::e($it['title']) ?> → demandé : <?= Html::e(($pend['name'] ?? '') . ' · ' . ($pend['address'] ?? '') . ' · ' . ($pend['category'] ?? '')) ?></div><?php endif; ?>
          <?php $it['text'] = $pro['description'] ?? ''; ?>
        <?php elseif ($type === 'pro_review'): ?>
          <strong><?= str_repeat('★', (int) $it['rating']) ?><?= str_repeat('☆', 5 - (int) $it['rating']) ?></strong> <span class="muted">sur <?= Html::e($it['place'] ?? '?') ?> · motif : <?= Html::e($it['reported_reason']) ?></span>
          <?php if ($it['reported_details']): ?><div class="muted">Précision du commerçant : <?= Html::e($it['reported_details']) ?></div><?php endif; ?>
        <?php elseif ($type === 'pro_story'): ?>
          <strong><?= Html::e($it['author']) ?></strong> <span class="muted">· diffusion le <?= Html::e(Html::localDate($it['starts_at'], 'd/m/Y H:i')) ?></span>
          <div><?php if ($it['media_kind'] === 'video'): ?><video src="<?= Html::e($it['media_url']) ?>" controls muted style="max-height:240px"></video><?php else: ?><img src="<?= Html::e($it['media_url']) ?>" alt="" style="max-height:240px"><?php endif; ?></div>
        <?php elseif ($type === 'visitor_photo'): ?>
          <strong><?= Html::e($it['place']) ?></strong> <span class="muted">· <code><?= Html::e($it['place_key']) ?></code></span>
          <div><img src="<?= Html::e($it['media_url']) ?>" alt="" style="max-height:240px"></div>
        <?php elseif ($type === 'pro_siret'): ?>
          <strong><?= Html::e($it['title']) ?></strong> <span class="muted"><?= Html::e($it['legal_name']) ?></span>
          <div class="muted">SIRET <code><?= Html::e($it['siret']) ?></code> · NAF <?= Html::e((string) $it['naf_code']) ?> · <a href="https://annuaire-entreprises.data.gouv.fr/etablissement/<?= Html::e($it['siret']) ?>" target="_blank" rel="noopener">Vérifier dans l'annuaire</a></div>
        <?php elseif ($type === 'review'): ?>
          <strong><?= str_repeat('★', (int) $it['rating']) ?><?= str_repeat('☆', 5 - (int) $it['rating']) ?></strong> <span class="muted">sur <?= Html::e($it['place'] ?? '?') ?></span>
        <?php endif; ?>
        <?php $text = $type === 'report' ? ($it['details'] ?? '') : ($it['text'] ?? ''); if ($text !== ''): ?><blockquote><?= nl2br(Html::e($text)) ?></blockquote><?php endif; ?>
        <div class="muted small"><?= isset($it['author']) ? 'Par ' . Html::e($it['author']) . ' · ' : '' ?><?= Html::e(Html::localDate($it['created_at'], 'd/m/Y H:i')) ?></div>
      </div>
      <div class="mod-actions">
        <?php if ($type === 'pro_story'): ?>
          <?= $button($type, $it['id'], 'dismiss', 'OK', 'primary') ?>
          <?= $refuse($type, $it['id'], 'remove', 'Retirer') ?>
        <?php elseif ($type === 'pro_review'): ?>
          <?= $button($type, $it['id'], 'remove', 'Retirer l\'avis', 'danger') ?>
          <?= $refuse($type, $it['id'], 'dismiss', 'Maintenir') ?>
        <?php elseif ($type === 'visitor_photo'): ?>
          <?= $button($type, $it['id'], 'publish', 'Valider', 'primary') ?>
          <?= $refuse($type, $it['id']) ?>
        <?php elseif ($type === 'pro_place' || $type === 'pro_siret'): ?>
          <?= $button($type, $it['id'], 'publish', $type === 'pro_siret' ? 'SIRET vérifié' : 'Publier', 'primary') ?>
          <?= $refuse($type, $it['id']) ?>
        <?php elseif ($type === 'report'): ?>
          <?= $button($type, $it['id'], 'remove', 'Retirer le contenu', 'danger') ?>
          <?= $button($type, $it['id'], 'dismiss', 'Laisser en ligne') ?>
          <?= $button($type, $it['id'], 'ban', 'Retirer et bannir l\'auteur', 'link danger', 'Bannir définitivement l\'auteur ?') ?>
        <?php else: ?>
          <?= $button($type, $it['id'], 'publish', 'Publier', 'primary') ?>
          <?= $type === 'event' ? $refuse($type, $it['id']) : $button($type, $it['id'], 'refuse', 'Refuser') ?>
          <?php if (!empty($it['user_id'])): ?><?= $button($type, $it['id'], 'ban', 'Refuser et bannir', 'link danger', 'Bannir définitivement l\'auteur ?') ?><?php endif; ?>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
