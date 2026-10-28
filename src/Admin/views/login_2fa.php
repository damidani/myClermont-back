<?php use App\Admin\Html; ?>
<div class="login card">
  <h1>Double authentification</h1>
  <p class="muted">Saisissez le code à 6 chiffres affiché par votre application d'authentification.</p>
  <?php if ($error): ?><div class="flash flash-error"><?= Html::e($error) ?></div><?php endif; ?>
  <form method="post" action="/admin/login/2fa">
    <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
    <input type="hidden" name="pending" value="<?= Html::e($pending) ?>">
    <div class="field"><label for="code">Code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus autocomplete="one-time-code"></div>
    <button class="btn primary block">Valider</button>
  </form>
</div>
