<?php use App\Admin\Html;
/** @var array $form @var array $preview @var string $csrf @var list<array> $neighborhoods @var list<string> $cities @var list<array> $history */
$a = $form['audience'];
$has = static fn (string $k, $v) => in_array($v, (array) ($a[$k] ?? []), true) ? ' checked' : '';
$val = static fn (string $k) => Html::e(is_array($a[$k] ?? null) ? implode(', ', $a[$k]) : (string) ($a[$k] ?? ''));
$categories = ['restaurant' => 'Restaurants', 'bar' => 'Bars', 'cafe' => 'Cafés', 'culture' => 'Culture', 'nature' => 'Nature', 'sport' => 'Sport', 'shopping' => 'Shopping',
               'courses' => 'Courses', 'sante' => 'Santé', 'services' => 'Services', 'hebergement' => 'Hébergement', 'etudes' => 'Études', 'mobilite' => 'Mobilité'];
$genders = ['woman' => 'Femmes', 'man' => 'Hommes', 'nonBinary' => 'Non binaires', 'undisclosed' => 'Non précisé'];
$status = ['brouillon' => 'Brouillon', 'programme' => 'Programmé', 'envoi' => 'En cours', 'envoye' => 'Envoyé', 'annule' => 'Annulé'];
$pill = ['brouillon' => 'draft', 'programme' => 'queued', 'envoi' => 'running', 'envoye' => 'sent', 'annule' => 'disabled'];
?>
<div class="page-head"><div><div class="crumb">Communication</div><h1>Notifications aux utilisateurs</h1></div></div>

<form method="post" action="/admin/broadcasts">
<input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
<div class="grid2">
  <section class="card">
    <h2>✍️ Message</h2>
    <div class="field"><label>Canaux</label>
      <label><input type="checkbox" name="channels[]" value="push"<?= in_array('push', $form['channels'], true) ? ' checked' : '' ?>> Notification push (et boîte de l'app)</label><br>
      <label><input type="checkbox" name="channels[]" value="email"<?= in_array('email', $form['channels'], true) ? ' checked' : '' ?>> E-mail (seulement les comptes qui l'ont accepté)</label>
    </div>
    <div class="field"><label for="title">Titre <span class="muted">(120 max)</span></label><input id="title" name="title" maxlength="120" required value="<?= Html::e($form['title']) ?>"></div>
    <div class="field"><label for="body">Texte <span class="muted">(500 max)</span></label><textarea id="body" name="body" maxlength="500" rows="3" required><?= Html::e($form['body']) ?></textarea></div>
    <div class="field"><label for="target_type">Ouvre dans l'app <span class="muted">(facultatif)</span></label>
      <select id="target_type" name="target_type">
        <?php foreach (['' => 'L\'accueil de l\'app', 'place' => 'Un lieu', 'event' => 'Un événement', 'deal' => 'Un bon plan', 'post' => 'Une publication', 'url' => 'Un lien https'] as $k => $l): ?>
          <option value="<?= $k ?>"<?= $form['target_type'] === $k ? ' selected' : '' ?>><?= Html::e($l) ?></option>
        <?php endforeach; ?>
      </select>
      <input name="target_id" placeholder="Identifiant ou lien" value="<?= Html::e($form['target_id']) ?>">
    </div>
    <details<?= $form['email_subject'] !== '' || $form['email_body'] !== '' ? ' open' : '' ?>><summary>E-mail : objet et texte différents</summary>
      <div class="field"><label for="es">Objet</label><input id="es" name="email_subject" maxlength="160" value="<?= Html::e($form['email_subject']) ?>"></div>
      <div class="field"><label for="eb">Texte de l'e-mail <span class="muted">({prenom} est remplacé)</span></label><textarea id="eb" name="email_body" rows="6"><?= Html::e($form['email_body']) ?></textarea></div>
    </details>
    <div class="field"><label for="send_at">Programmer <span class="muted">(heure de Paris, vide = maintenant)</span></label><input type="datetime-local" id="send_at" name="send_at" value="<?= Html::e($form['send_at']) ?>"></div>
  </section>

  <section class="card">
    <h2>🎯 Audience</h2>
    <div class="callout callout-ok"><div><strong><?= $preview['total'] ?></strong> utilisateur(s) ·
      <strong><?= $preview['push'] ?></strong> joignable(s) en push · <strong><?= $preview['email'] ?></strong> par e-mail</div>
      <button class="btn small" name="action" value="preview">Recalculer</button></div>
    <div class="fields">
      <div class="field"><label>Téléphone</label>
        <label><input type="checkbox" name="platforms[]" value="ios"<?= $has('platforms', 'ios') ?>> iPhone</label>
        <label><input type="checkbox" name="platforms[]" value="android"<?= $has('platforms', 'android') ?>> Android</label></div>
      <div class="field"><label>My Clermont+</label>
        <select name="plus"><option value="">Tous</option>
          <option value="1"<?= ($a['plus'] ?? null) === true ? ' selected' : '' ?>>Membres seulement</option>
          <option value="0"<?= ($a['plus'] ?? null) === false ? ' selected' : '' ?>>Non-membres seulement</option></select></div>
      <div class="field"><label for="cities">Villes <span class="muted">(séparées par des virgules)</span></label>
        <input id="cities" name="cities" list="city-list" value="<?= $val('cities') ?>">
        <datalist id="city-list"><?php foreach ($cities as $c): ?><option value="<?= Html::e($c) ?>"><?php endforeach; ?></datalist></div>
      <div class="field"><label for="pc">Codes postaux</label><input id="pc" name="postal_codes" placeholder="63000, 63100" value="<?= $val('postal_codes') ?>"></div>
      <div class="field"><label for="nb">Quartiers</label>
        <select id="nb" name="neighborhoods[]" multiple size="5">
          <?php foreach ($neighborhoods as $n): ?><option value="<?= (int) $n['id'] ?>"<?= in_array((int) $n['id'], (array) ($a['neighborhoods'] ?? []), true) ? ' selected' : '' ?>><?= Html::e($n['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label>Genre</label>
        <?php foreach ($genders as $k => $l): ?><label><input type="checkbox" name="genders[]" value="<?= $k ?>"<?= $has('genders', $k) ?>> <?= Html::e($l) ?></label> <?php endforeach; ?></div>
      <div class="field"><label>A des favoris dans</label>
        <?php foreach ($categories as $k => $l): ?><label><input type="checkbox" name="favorite_categories[]" value="<?= $k ?>"<?= $has('favorite_categories', $k) ?>> <?= Html::e($l) ?></label> <?php endforeach; ?></div>
      <div class="field"><label for="act">Actif dans les … derniers jours</label><input type="number" min="1" id="act" name="active_within_days" value="<?= $val('active_within_days') ?>"></div>
      <div class="field"><label for="inact">Inactif depuis … jours <span class="muted">(relance)</span></label><input type="number" min="1" id="inact" name="inactive_for_days" value="<?= $val('inactive_for_days') ?>"></div>
      <div class="field"><label for="cr">Inscrit dans les … derniers jours</label><input type="number" min="1" id="cr" name="created_within_days" value="<?= $val('created_within_days') ?>"></div>
      <div class="field"><label for="ca">A publié ou commenté dans les … derniers jours</label><input type="number" min="1" id="ca" name="community_active_days" value="<?= $val('community_active_days') ?>"></div>
      <div class="field"><label for="pts">Points minimum</label><input type="number" min="1" id="pts" name="min_points" value="<?= $val('min_points') ?>"></div>
      <div class="field"><label>Autres</label>
        <label><input type="checkbox" name="push_only" value="1"<?= !empty($a['push_only']) ? ' checked' : '' ?>> Seulement les comptes joignables en push</label><br>
        <label><input type="checkbox" name="staff_only" value="1"<?= !empty($a['staff_only']) ? ' checked' : '' ?>> Équipe seulement</label></div>
    </div>
  </section>
</div>
<div class="card">
  <button class="btn" name="action" value="test">Tester (envoi à l'équipe)</button>
  <button class="btn primary" name="action" value="send" data-confirm="Envoyer à <?= $preview['total'] ?> utilisateur(s) ? Vérifie le nombre après « Recalculer ».">Envoyer / programmer</button>
  <span class="muted">Le nombre affiché correspond aux filtres au dernier « Recalculer ».</span>
</div>
</form>

<section class="card table-wrap">
  <h2>Historique</h2>
  <?php if (!$history): ?><p class="muted">Aucun envoi pour l'instant.</p><?php else: ?>
  <table class="table"><thead><tr><th>Titre</th><th>Canaux</th><th>Statut</th><th>Date</th><th>Destinataires</th><th>Push</th><th>E-mails</th><th>Par</th><th></th></tr></thead><tbody>
  <?php foreach ($history as $b): ?>
    <tr><td><strong><?= Html::e($b['title']) ?></strong><br><span class="muted"><?= Html::e(mb_strimwidth($b['body'], 0, 90, '…')) ?></span></td>
      <td><?= Html::e(str_replace(['push', 'email', ','], ['Push', 'E-mail', ' + '], $b['channels'])) ?></td>
      <td><span class="pill pill-<?= $pill[$b['status']] ?>"><?= $status[$b['status']] ?></span></td>
      <td><?= Html::e(Html::localDate((string) ($b['sent_at'] ?? $b['send_at'] ?? $b['created_at']), 'd/m/Y H:i')) ?></td>
      <td><?= (int) $b['recipients'] ?></td><td><?= (int) $b['pushed'] ?></td><td><?= (int) $b['emailed'] ?></td><td><?= Html::e($b['created_by']) ?></td>
      <td><?php if (in_array($b['status'], ['brouillon', 'programme'], true)): ?>
        <form method="post" action="/admin/broadcasts" class="inline" data-confirm="Annuler cet envoi ?"><input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
          <input type="hidden" name="id" value="<?= Html::e($b['uid']) ?>"><button class="btn small danger" name="action" value="cancel">Annuler</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>
