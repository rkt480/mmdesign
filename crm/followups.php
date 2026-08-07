<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';

crm_require_sales_manager();

$canManageSettings = crm_current_user_is_admin();
$flows = crm_read_followup_flows();

function short_text(string $text, int $limit = 120): string
{
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function human_delay(int $minutes): string
{
    if ($minutes >= 1440 && $minutes % 1440 === 0) {
        $days = (int) ($minutes / 1440);
        return $days . ' ' . ($days === 1 ? 'dia' : 'dias');
    }

    if ($minutes >= 60 && $minutes % 60 === 0) {
        $hours = (int) ($minutes / 60);
        return $hours . ' ' . ($hours === 1 ? 'hora' : 'horas');
    }

    return $minutes . ' ' . ($minutes === 1 ? 'minuto' : 'minutos');
}
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?= htmlspecialchars(crm_csrf_token()) ?>" />
    <title>Fluxos de Follow-up | CRM</title>
    <link rel="stylesheet" href="./assets/crm.css?v=20260807-brand-logo" />
  </head>
  <body class="followups-page">
    <div class="app-shell">
      <aside class="sidebar" aria-label="Navegação do CRM">
        <a class="brand" href="index.php" aria-label="Início">
          <span class="brand-mark"><img src="./assets/mmdesign-mark.png" alt="MM DESIGN" /></span>
        </a>
        <nav class="sidebar-tabs" aria-label="Atalhos do CRM">
          <a href="whatsapp.php" title="Conversas do WhatsApp" aria-label="Conversas do WhatsApp">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M7.4 14.8H6.2a4 4 0 0 1-4-4V7.2a4 4 0 0 1 4-4h7.1a4 4 0 0 1 4 4v.6" />
              <path d="M10.7 8.2h6.2a4 4 0 0 1 4 4v2.7a4 4 0 0 1-4 4h-2.5L11 21v-2.1h-.3a4 4 0 0 1-4-4v-2.7a4 4 0 0 1 4-4Z" />
            </svg>
          </a>
          <?php if ($canManageSettings): ?>
            <a href="whatsapp-templates.php" title="Templates WhatsApp" aria-label="Templates WhatsApp">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4.5h14v15H5z" /><path d="M8 8h8M8 12h8M8 16h5" /></svg>
            </a>
          <?php endif; ?>
          <a href="dashboard.php" title="Dashboard do gestor" aria-label="Dashboard do gestor">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M4 19V5" />
              <path d="M4 19h16" />
              <path d="M8 16V9" />
              <path d="M12 16V7" />
              <path d="M16 16v-5" />
            </svg>
          </a>
          <?php if ($canManageSettings): ?>
            <a href="commercial.php" title="Área comercial" aria-label="Área comercial">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M8 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
                <path d="M16 11a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4Z" />
                <path d="M3.5 19.5v-1.2A4.5 4.5 0 0 1 8 13.8a4.5 4.5 0 0 1 4.5 4.5v1.2" />
                <path d="M13.4 14.2c.8-.5 1.7-.8 2.6-.8a4.2 4.2 0 0 1 4.2 4.2v1.9" />
              </svg>
            </a>
          <?php endif; ?>
        </nav>
        <a class="sidebar-exit" href="logout.php" title="Sair">Sair</a>
      </aside>

      <div class="workspace">
        <header class="topbar">
          <nav class="topbar-nav" aria-label="Áreas do CRM">
            <a href="index.php">Contatos</a>
            <a class="active" href="followups.php">Follow-up</a>
            <a href="dashboard.php">Dashboard</a>
            <?php if ($canManageSettings): ?>
              <a href="commercial.php">Comercial</a>
              <a href="whatsapp-templates.php">Templates</a>
              <a href="settings.php">Configurações</a>
            <?php endif; ?>
          </nav>
        </header>

        <header class="app-header">
          <div>
            <p class="eyebrow">Automação</p>
            <h1>Fluxos de follow-up</h1>
          </div>
        </header>

    <main class="dashboard automation-layout">
      <section class="automation-card">
        <h2>Criar novo fluxo</h2>
        <p>Monte a sequência como você falaria no comercial. Use <code>{{name}}</code>, <code>{{company}}</code> e <code>{{segment}}</code>.</p>
        <form class="flow-form" method="post" action="save-followup.php" id="flowForm">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
          <input type="hidden" name="id" id="flowId" value="" />
          <input type="hidden" name="steps_json" id="stepsJson" value="" />
          <label>
            Nome do fluxo
            <input type="text" name="name" id="flowName" placeholder="Ex: Recuperação lead frio" required />
          </label>
          <label>
            Descrição
            <textarea name="description" id="flowDescription" rows="2" placeholder="Quando usar esse fluxo?"></textarea>
          </label>

          <div class="flow-steps" id="flowSteps">
            <article class="flow-step" data-step>
              <div class="flow-step-header">
                <strong>Mensagem 1</strong>
                <button class="secondary-action remove-step" type="button" data-remove-step>Remover</button>
              </div>
              <div class="delay-row">
                <label>
                  Enviar após
                  <input type="number" name="steps[0][delay_value]" min="0" value="0" />
                </label>
                <label>
                  Unidade
                  <select name="steps[0][delay_unit]">
                    <option value="minutes">minutos</option>
                    <option value="hours">horas</option>
                    <option value="days">dias</option>
                  </select>
                </label>
              </div>
              <label>
                Mensagem
                <div class="emoji-picker" data-emoji-picker>
                  <button class="emoji-picker-toggle" type="button" data-emoji-toggle title="Abrir emojis">😀</button>
                  <div class="emoji-picker-panel" data-emoji-panel hidden>
                    <input type="search" data-emoji-search placeholder="Buscar emoji" autocomplete="off" />
                    <div class="emoji-picker-grid" data-emoji-grid aria-label="Emojis"></div>
                  </div>
                </div>
                <textarea name="steps[0][message]" rows="4" placeholder="Ex: Oi {{name}}, vi que você pediu uma demonstração. Posso te mostrar como funciona?"></textarea>
              </label>
            </article>
          </div>

          <button class="secondary-action" type="button" id="addFlowStep">Adicionar mensagem</button>
          <div class="form-actions">
            <button type="submit" id="saveFlowButton">Salvar fluxo</button>
            <button class="secondary-action" type="button" id="cancelEditFlow" hidden>Cancelar edição</button>
          </div>
        </form>
      </section>

      <section class="automation-card">
        <h2>Fluxos cadastrados</h2>
        <?php if (count($flows) === 0): ?>
          <p>Nenhum fluxo cadastrado ainda.</p>
        <?php else: ?>
          <div class="flow-list">
            <?php foreach ($flows as $flow): ?>
              <?php $steps = crm_read_followup_steps((int) $flow['id']); ?>
              <article
                class="flow-item"
                data-flow='<?= htmlspecialchars(json_encode([
                  'id' => (int) $flow['id'],
                  'name' => (string) $flow['name'],
                  'description' => (string) ($flow['description'] ?? ''),
                  'steps' => array_map(fn(array $step): array => [
                    'delay_minutes' => (int) $step['delay_minutes'],
                    'message' => (string) $step['message'],
                  ], $steps),
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
              >
                <div>
                  <h3><?= htmlspecialchars((string) $flow['name']) ?></h3>
                  <p><?= htmlspecialchars((string) ($flow['description'] ?? '')) ?></p>
                </div>
                <p class="flow-messages-label">Mensagens</p>
                <ol>
                  <?php foreach ($steps as $step): ?>
                    <li>
                      <strong><?= human_delay((int) $step['delay_minutes']) ?></strong>
                      <?= htmlspecialchars(short_text((string) $step['message'])) ?>
                    </li>
                  <?php endforeach; ?>
                </ol>
                <div class="flow-item-actions">
                  <button class="secondary-action" type="button" data-edit-flow>Editar</button>
                  <form method="post" action="delete-followup.php" onsubmit="return confirm('Excluir este fluxo?');">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                    <input type="hidden" name="id" value="<?= (int) $flow['id'] ?>" />
                    <button class="danger" type="submit">Excluir fluxo</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
    <template id="flowStepTemplate">
      <article class="flow-step" data-step>
        <div class="flow-step-header">
          <strong>Mensagem</strong>
          <button class="secondary-action remove-step" type="button" data-remove-step>Remover</button>
        </div>
        <div class="delay-row">
          <label>
            Enviar após
            <input type="number" data-name="delay_value" min="0" value="1" />
          </label>
          <label>
            Unidade
            <select data-name="delay_unit">
              <option value="minutes">minutos</option>
              <option value="hours">horas</option>
              <option value="days" selected>dias</option>
            </select>
          </label>
        </div>
        <label>
          Mensagem
          <div class="emoji-picker" data-emoji-picker>
            <button class="emoji-picker-toggle" type="button" data-emoji-toggle title="Abrir emojis">😀</button>
            <div class="emoji-picker-panel" data-emoji-panel hidden>
              <input type="search" data-emoji-search placeholder="Buscar emoji" autocomplete="off" />
              <div class="emoji-picker-grid" data-emoji-grid aria-label="Emojis"></div>
            </div>
          </div>
          <textarea data-name="message" rows="4" placeholder="Digite a mensagem do follow-up"></textarea>
        </label>
      </article>
    </template>
      </div>
    </div>
    <script src="./assets/followups.js?v=20260701-emoji-picker"></script>
  </body>
</html>
