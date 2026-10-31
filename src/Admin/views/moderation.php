<?php use App\Admin\Html;
/** @var array<string, list<array>> $queue @var string $csrf */
$sections = [
    'post'    => ['💬 Publications retenues par le filtre', 'Le texte a déclenché le filtre (insulte, lien suspect). L\'auteur croit sa publication en ligne.'],
    'comment' => ['🗨️ Commentaires retenus', ''],
    'review'  => ['⭐ Avis retenus', 'La note compte déjà dans la moyenne ; seul le texte attend ta décision (souvent un lien).'],
    'event'   => ['🎉 Événements proposés par les utilisateurs', 'Vérifie la date, le lieu et qu\'il s\'agit bien d\'un événement public.'],
    'report'  => ['🚩 Signalements', 'Envoyés depuis l\'app par les utilisateurs.'],
];
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
        <?php elseif ($type === 'review'): ?>
          <strong><?= str_repeat('★', (int) $it['rating']) ?><?= str_repeat('☆', 5 - (int) $it['rating']) ?></strong> <span class="muted">sur <?= Html::e($it['place'] ?? '?') ?></span>
        <?php endif; ?>
        <?php $text = $type === 'report' ? ($it['details'] ?? '') : ($it['text'] ?? ''); if ($text !== ''): ?><blockquote><?= nl2br(Html::e($text)) ?></blockquote><?php endif; ?>
        <div class="muted small"><?= isset($it['author']) ? 'Par ' . Html::e($it['author']) . ' · ' : '' ?><?= Html::e(Html::localDate($it['created_at'], 'd/m/Y H:i')) ?></div>
      </div>
      <div class="mod-actions">
        <?php if ($type === 'report'): ?>
          <?= $button($type, $it['id'], 'remove', 'Retirer le contenu', 'danger') ?>
          <?= $button($type, $it['id'], 'dismiss', 'Laisser en ligne') ?>
          <?= $button($type, $it['id'], 'ban', 'Retirer et bannir l\'auteur', 'link danger', 'Bannir définitivement l\'auteur ?') ?>
        <?php else: ?>
          <?= $button($type, $it['id'], 'publish', 'Publier', 'primary') ?>
          <?= $button($type, $it['id'], 'refuse', 'Refuser') ?>
          <?php if (!empty($it['user_id'])): ?><?= $button($type, $it['id'], 'ban', 'Refuser et bannir', 'link danger', 'Bannir définitivement l\'auteur ?') ?><?php endif; ?>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
