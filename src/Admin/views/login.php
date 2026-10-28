<?php use App\Admin\Html; ?>
<div class="login card">
  <h1>myClermont <span class="muted">admin</span></h1>
  <?php if ($error): ?><div class="flash flash-error"><?= Html::e($error) ?></div><?php endif; ?>
  <form method="post" action="/admin/login">
    <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
    <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" value="<?= Html::e($email) ?>" required autofocus autocomplete="username"></div>
    <div class="field"><label for="password">Mot de passe</label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn primary block">Se connecter</button>
  </form>
</div>
