<?php use App\Admin\Html; ?>
<div class="page-head"><h1>Mon compte</h1></div>
<div class="grid2">
<section class="card">
  <h2><?= Html::e($admin['name']) ?></h2>
  <p class="muted"><?= Html::e($admin['email']) ?> · rôle <strong><?= Html::e($admin['role']) ?></strong></p>
  <h3>Changer de mot de passe</h3>
  <form method="post" action="/admin/account/password">
    <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
    <div class="field"><label for="cp">Mot de passe actuel</label><input id="cp" type="password" name="current_password" required autocomplete="current-password"></div>
    <div class="field"><label for="np">Nouveau (12 caractères min.)</label><input id="np" type="password" name="new_password" minlength="12" required autocomplete="new-password"></div>
    <div class="field"><label for="cf">Confirmation</label><input id="cf" type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></div>
    <button class="btn primary">Modifier</button>
  </form>
</section>
<section class="card">
  <h2>Double authentification</h2>
  <?php if ($admin['has_2fa']): ?>
    <p><span class="pill pill-active">activée</span> Un code de votre application vous est demandé à chaque connexion.</p>
    <?php if (!$required): ?>
    <form method="post" action="/admin/account/2fa">
      <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>"><input type="hidden" name="action" value="disable">
      <div class="field"><label for="dc">Code actuel pour désactiver</label><input id="dc" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code"></div>
      <button class="btn danger">Désactiver</button>
    </form>
    <?php endif; ?>
  <?php elseif ($setup): ?>
    <ol>
      <li>Dans votre application d'authentification (Google Authenticator, 1Password, Authy…), ajoutez un compte avec cette clé :
        <pre class="secret"><?= Html::e(trim(chunk_split($setup, 4, ' '))) ?></pre>
        ou ouvrez ce lien sur le téléphone : <code class="wrap"><?= Html::e($setupUri) ?></code></li>
      <li>Saisissez le code affiché :</li>
    </ol>
    <form method="post" action="/admin/account/2fa">
      <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>"><input type="hidden" name="action" value="confirm">
      <div class="field"><input name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code" aria-label="Code"></div>
      <button class="btn primary">Activer</button>
    </form>
  <?php else: ?>
    <?php if ($required): ?><div class="flash flash-error">La double authentification est obligatoire : activez-la pour accéder au back-office.</div><?php endif; ?>
    <p class="muted">Protège le back-office même si votre mot de passe fuite.</p>
    <form method="post" action="/admin/account/2fa"><input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>"><input type="hidden" name="action" value="start"><button class="btn primary">Configurer</button></form>
  <?php endif; ?>
</section>
<section class="card">
  <h2>Sessions ouvertes</h2>
  <table class="table compact"><tbody>
  <?php foreach ($sessions as $s): ?>
    <tr><td><?= Html::e($s['ip']) ?><?= $s['id_hash'] === $current ? ' <span class="pill pill-active">cette session</span>' : '' ?></td>
        <td class="muted"><?= Html::e(mb_strimwidth((string) $s['user_agent'], 0, 50, '…')) ?></td>
        <td class="muted nowrap"><?= Html::e(Html::localDate($s['last_seen_at'], 'd/m H:i')) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php if (count($sessions) > 1): ?>
  <form method="post" action="/admin/account/sessions"><input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>"><button class="btn">Fermer les autres sessions</button></form>
  <?php endif; ?>
</section>
</div>
