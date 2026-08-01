<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

crm_send_security_headers();

if (crm_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['user'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!crm_verify_csrf_token(crm_request_csrf_token())) {
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif (crm_login_is_limited($user)) {
        $error = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';
    } elseif (crm_attempt_login($user, $password)) {
        crm_clear_login_failures($user);
        header('Location: index.php');
        exit;
    } else {
        crm_record_login_failure($user);
        $error = 'Usuário ou senha inválidos.';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login | CRM Publi AI Soluções</title>
    <link rel="stylesheet" href="./assets/crm.css" />
  </head>
  <body class="auth-page">
    <main class="auth-card">
      <p class="eyebrow">Painel privado</p>
      <h1>CRM de leads</h1>
      <p>Acesse os contatos recebidos pela landing page.</p>

      <?php if ($error !== ''): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
        <label>
          Usuário
          <input type="text" name="user" autocomplete="username" required />
        </label>
        <label>
          Senha
          <input type="password" name="password" autocomplete="current-password" required />
        </label>
        <button type="submit">Entrar no painel</button>
      </form>
    </main>
  </body>
</html>
