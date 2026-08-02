<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/forms.php';
require_once __DIR__ . '/lib/whatsapp.php';

crm_require_login();

$currentUser = crm_current_user();
$canManageSales = crm_current_user_can_manage_sales();
$canManageSettings = crm_current_user_is_admin();
$leads = crm_read_leads();
$provider = crm_whatsapp_provider();
$providerLabel = crm_whatsapp_provider_label($provider);
$metaConfigured = crm_meta_whatsapp_is_configured();
$pilotStatusConfigured = crm_pilot_status_is_configured();
$followupFlows = crm_read_followup_flows(true);
$googleCalendarConnected = crm_google_calendar_is_connected();
$sent = ($_GET['sent'] ?? '') === '1';
$sendError = trim((string) ($_GET['send_error'] ?? ''));
$scheduled = ($_GET['scheduled'] ?? '') === '1';
$calendarError = (string) ($_GET['calendar_error'] ?? '');
$calendarErrorMessages = [
    'not_connected' => 'Conecte o Google Agenda nas configurações antes de criar agendamentos.',
    'lead_not_found' => 'Lead não encontrado para criar agendamento.',
    'invalid_datetime' => 'Informe uma data e horário válidos para o agendamento.',
    'invalid_email' => 'Informe um e-mail de convidado válido.',
    'create_failed' => 'Não foi possível criar o evento no Google Agenda.',
];
$providerFilter = trim((string) ($_GET['provider'] ?? 'all'));

if (!in_array($providerFilter, ['all', 'meta_cloud', 'pilot_status'], true)) {
    $providerFilter = 'all';
}

$requestedLeadId = trim((string) ($_GET['lead'] ?? ''));
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/crm/whatsapp.php'))), '/');
$baseUrl = $host !== '' ? ($https ? 'https://' : 'http://') . $host . $scriptDir : '';
$metaWebhookUrl = $baseUrl !== '' ? $baseUrl . '/api/meta-whatsapp-webhook.php' : 'api/meta-whatsapp-webhook.php';
$pilotStatusWebhookUrl = $baseUrl !== '' ? $baseUrl . '/api/pilot-status-webhook.php' : 'api/pilot-status-webhook.php';

function whatsapp_page_provider_for_lead(array $lead): string
{
    $source = strtolower(implode(' ', [
        (string) ($lead['utm_source'] ?? ''),
        (string) ($lead['page'] ?? ''),
        (string) ($lead['landing_path'] ?? ''),
        (string) ($lead['notes'] ?? ''),
    ]));

    if (str_contains($source, 'meta_whatsapp') || str_contains($source, 'meta cloud')) {
        return 'meta_cloud';
    }

    if (str_contains($source, 'pilot_status') || str_contains($source, 'pilot status')) {
        return 'pilot_status';
    }

    return 'crm';
}

function whatsapp_page_provider_label(string $provider): string
{
    return match ($provider) {
        'meta_cloud' => 'API oficial',
        'pilot_status' => 'Pilot Status oficial',
        default => 'CRM',
    };
}

function whatsapp_page_provider_badge_class(string $provider): string
{
    return match ($provider) {
        'meta_cloud' => 'is-official',
        'pilot_status' => 'is-pilot',
        default => 'is-crm',
    };
}

function whatsapp_page_parse_br_datetime(string $value): string
{
    $date = DateTime::createFromFormat('d/m/Y H:i', trim($value));

    return $date instanceof DateTime ? $date->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
}

function whatsapp_page_time_label(string $date): string
{
    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return '';
    }

    return date('d/m H:i', $timestamp);
}

function whatsapp_page_short_text(string $text, int $limit = 92): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit - 3, 'UTF-8') . '...' : $text;
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function whatsapp_page_timestamp(string $date): int
{
    $timestamp = strtotime($date);

    return $timestamp === false ? 0 : $timestamp;
}

function whatsapp_page_latest_lead_date(array $lead): string
{
    $updatedAt = trim((string) ($lead['updated_at'] ?? ''));

    return $updatedAt !== '' ? $updatedAt : (string) ($lead['created_at'] ?? date('Y-m-d H:i:s'));
}

function whatsapp_page_origin_summary(array $lead): string
{
    $source = trim((string) ($lead['utm_source'] ?? ''));
    $medium = trim((string) ($lead['utm_medium'] ?? ''));
    $campaign = trim((string) ($lead['utm_campaign'] ?? ''));

    if ($source !== '' || $medium !== '' || $campaign !== '') {
        return implode(' / ', array_filter([$source, $medium, $campaign], static fn(string $part): bool => $part !== ''));
    }

    return trim((string) ($lead['page'] ?? '')) !== '' ? (string) $lead['page'] : 'CRM';
}

function whatsapp_page_whatsapp_status(array $lead): string
{
    $status = (string) ($lead['whatsapp_status'] ?? 'pendente');
    $labels = [
        'pendente' => 'Pendente',
        'notifica_enviada' => 'Notificação enviada',
        'notifica_falhou' => 'Notificação falhou',
        'notifica_sem_numero' => 'Número interno ausente',
        'nao_configurado' => 'WhatsApp não configurado',
        'falhou' => 'Falhou',
        'enviado' => 'Mensagem enviada',
    ];

    return $labels[$status] ?? $status;
}

function whatsapp_page_lead_answer(array $lead, string $field): string
{
    $value = trim((string) ($lead[$field] ?? ''));

    return $value !== '' ? $value : 'Não informado';
}

function whatsapp_page_money_input(array $lead, string $field): string
{
    $value = $lead[$field] ?? null;

    return $value !== null && $value !== '' ? 'R$ ' . number_format((float) $value, 2, ',', '.') : '';
}

function whatsapp_page_messages_for_lead(array $lead): array
{
    $provider = whatsapp_page_provider_for_lead($lead);
    $messages = [];
    $initialMessage = trim((string) ($lead['message'] ?? ''));

    if ($initialMessage !== '') {
        $messages[] = [
            'direction' => 'incoming',
            'provider' => $provider,
            'at' => (string) ($lead['created_at'] ?? date('Y-m-d H:i:s')),
            'text' => $initialMessage,
            'label' => 'Mensagem recebida',
        ];
    }

    $notes = trim((string) ($lead['notes'] ?? ''));

    if ($notes !== '') {
        foreach (preg_split("/\n{2,}/", $notes) ?: [] as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            if (preg_match('/^Mensagem recebida pelo provedor anterior em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}):\n(.+)$/s', $block, $match) === 1) {
                $messages[] = [
                    'direction' => 'incoming',
                    'provider' => 'pilot_status',
                    'at' => whatsapp_page_parse_br_datetime((string) $match[1]),
                    'text' => trim((string) $match[2]),
                    'label' => 'Recebida',
                ];
                continue;
            }

            if (preg_match('/^Mensagem recebida pela Meta Cloud API em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}):\n(.+)$/s', $block, $match) === 1) {
                $messages[] = [
                    'direction' => 'incoming',
                    'provider' => 'meta_cloud',
                    'at' => whatsapp_page_parse_br_datetime((string) $match[1]),
                    'text' => trim((string) $match[2]),
                    'label' => 'Recebida',
                ];
                continue;
            }

            if (preg_match('/^Mensagem recebida pela Pilot Status em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}):\n(.+)$/s', $block, $match) === 1) {
                $messages[] = [
                    'direction' => 'incoming',
                    'provider' => 'pilot_status',
                    'at' => whatsapp_page_parse_br_datetime((string) $match[1]),
                    'text' => trim((string) $match[2]),
                    'label' => 'Recebida',
                ];
                continue;
            }

            if (preg_match('/^Mensagem enviada via (.+) em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}):\n(.+)$/s', $block, $match) === 1) {
                $sentProviderLabel = strtolower((string) $match[1]);
                $messages[] = [
                    'direction' => 'outgoing',
                    'provider' => str_contains($sentProviderLabel, 'meta') ? 'meta_cloud' : 'pilot_status',
                    'at' => whatsapp_page_parse_br_datetime((string) $match[2]),
                    'text' => trim((string) $match[3]),
                    'label' => 'Enviada',
                ];
                continue;
            }

            if (preg_match('/^Falha ao enviar via (.+?) em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}):\n(.+)$/s', $block, $match) === 1) {
                $displayFailureText = preg_replace('/\bPilot Status\b|\bMeta Cloud API\b/iu', 'WhatsApp', $block) ?? $block;
                $displayFailureText = preg_replace('/Falha ao enviar via WhatsApp/iu', 'Falha ao enviar mensagem', $displayFailureText) ?? $displayFailureText;
                $messages[] = [
                    'direction' => 'note',
                    'provider' => str_contains(strtolower((string) $match[1]), 'meta') ? 'meta_cloud' : 'pilot_status',
                    'at' => whatsapp_page_parse_br_datetime((string) $match[2]),
                    'text' => $displayFailureText,
                    'label' => 'Falha no envio',
                ];
                continue;
            }

            $messages[] = [
                'direction' => 'note',
                'provider' => $provider,
                'at' => (string) ($lead['updated_at'] ?? $lead['created_at'] ?? date('Y-m-d H:i:s')),
                'text' => $block,
                'label' => 'Observação do CRM',
            ];
        }
    }

    usort($messages, static function (array $a, array $b): int {
        $timestampComparison = strtotime((string) $a['at']) <=> strtotime((string) $b['at']);

        if ($timestampComparison !== 0) {
            return $timestampComparison;
        }

        $priority = ['note' => 0, 'incoming' => 1, 'outgoing' => 2];

        return ($priority[(string) ($a['direction'] ?? '')] ?? 1)
            <=> ($priority[(string) ($b['direction'] ?? '')] ?? 1);
    });

    return $messages;
}

$conversations = [];
$conversationGroups = [];
$providerCounts = [
    'all' => 0,
    'meta_cloud' => 0,
    'pilot_status' => 0,
];

foreach ($leads as $lead) {
    $whatsapp = crm_normalize_lead_whatsapp((string) ($lead['whatsapp'] ?? ''));

    if ($whatsapp === '') {
        continue;
    }

    $conversationKey = crm_whatsapp_number_variants($whatsapp)[0] ?? $whatsapp;

    $leadProvider = whatsapp_page_provider_for_lead($lead);
    $messages = whatsapp_page_messages_for_lead($lead);
    $leadDate = whatsapp_page_latest_lead_date($lead);

    if (!isset($conversationGroups[$conversationKey])) {
        $conversationGroups[$conversationKey] = [
            'lead' => $lead,
            'lead_ids' => [],
            'provider' => $leadProvider,
            'messages' => [],
            'last_at' => $leadDate,
            'preview' => 'Sem mensagens registradas ainda.',
        ];
    }

    $conversationGroups[$conversationKey]['lead_ids'][] = (string) ($lead['id'] ?? '');
    $conversationGroups[$conversationKey]['messages'] = array_merge($conversationGroups[$conversationKey]['messages'], $messages);

    if (
        whatsapp_page_timestamp($leadDate) > whatsapp_page_timestamp((string) $conversationGroups[$conversationKey]['last_at'])
    ) {
        $conversationGroups[$conversationKey]['lead'] = $lead;
        $conversationGroups[$conversationKey]['last_at'] = $leadDate;
    }

    if (
        $leadProvider !== 'crm'
        && (
            (string) $conversationGroups[$conversationKey]['provider'] === 'crm'
            || whatsapp_page_timestamp($leadDate) >= whatsapp_page_timestamp((string) $conversationGroups[$conversationKey]['last_at'])
        )
    ) {
        $conversationGroups[$conversationKey]['provider'] = $leadProvider;
    }
}

foreach ($conversationGroups as $whatsapp => $conversation) {
    $uniqueMessages = [];

    foreach ($conversation['messages'] as $message) {
        $messageTimestamp = whatsapp_page_timestamp((string) ($message['at'] ?? ''));
        $key = implode('|', [
            (string) ($message['direction'] ?? ''),
            (string) ($message['provider'] ?? ''),
            $messageTimestamp > 0 ? date('Y-m-d H:i', $messageTimestamp) : '',
            trim((string) ($message['text'] ?? '')),
        ]);

        $uniqueMessages[$key] = $message;
    }

    $messages = array_values($uniqueMessages);
    usort($messages, static fn(array $a, array $b): int => whatsapp_page_timestamp((string) $a['at']) <=> whatsapp_page_timestamp((string) $b['at']));
    $lastMessage = end($messages);
    $lastAt = is_array($lastMessage) ? (string) $lastMessage['at'] : (string) $conversation['last_at'];
    $preview = is_array($lastMessage) ? (string) $lastMessage['text'] : 'Sem mensagens registradas ainda.';
    $lastDirection = is_array($lastMessage) ? (string) ($lastMessage['direction'] ?? '') : '';
    $lastMessageKey = is_array($lastMessage)
        ? implode('|', [$lastDirection, $lastAt, $preview])
        : '';
    $conversation['messages'] = $messages;
    $conversation['last_at'] = $lastAt;
    $conversation['preview'] = $preview;
    $conversation['last_direction'] = $lastDirection;
    $conversation['last_message_key'] = $lastMessageKey;

    $providerCounts['all']++;

    if (isset($providerCounts[(string) $conversation['provider']])) {
        $providerCounts[(string) $conversation['provider']]++;
    }

    if ($providerFilter !== 'all' && (string) $conversation['provider'] !== $providerFilter) {
        continue;
    }

    $conversations[] = $conversation;
}

usort($conversations, static fn(array $a, array $b): int => whatsapp_page_timestamp((string) $b['last_at']) <=> whatsapp_page_timestamp((string) $a['last_at']));

$activeConversation = $conversations[0] ?? null;
$requestedLead = null;

if ($requestedLeadId !== '') {
    foreach ($leads as $lead) {
        if ((string) ($lead['id'] ?? '') === $requestedLeadId) {
            $requestedLead = $lead;
            break;
        }
    }

    foreach ($conversations as $conversation) {
        if (in_array($requestedLeadId, $conversation['lead_ids'] ?? [], true)) {
            $activeConversation = $conversation;
            break;
        }
    }
}

$activeLead = is_array($requestedLead) ? $requestedLead : (is_array($activeConversation) ? $activeConversation['lead'] : null);
$activeMessages = is_array($activeConversation) ? $activeConversation['messages'] : [];
$activeProvider = is_array($activeConversation) ? (string) $activeConversation['provider'] : $provider;
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?= htmlspecialchars(crm_csrf_token()) ?>" />
    <title>WhatsApp | Publi CRM</title>
    <link rel="stylesheet" href="./assets/crm.css?v=20260802-wa-clean-ui" />
  </head>
  <body class="whatsapp-page whatsapp-crm-page">
    <main class="wa-web-shell" aria-label="Atendimento WhatsApp do CRM">
      <aside class="sidebar" aria-label="Navegação do CRM">
        <a class="brand" href="index.php" aria-label="Início">
          <span class="brand-mark">P</span>
          <span>Publi CRM</span>
        </a>
        <nav class="sidebar-tabs" aria-label="Atalhos do CRM">
          <a class="active" href="whatsapp.php" title="Conversas do WhatsApp" aria-label="Conversas do WhatsApp">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M7.4 14.8H6.2a4 4 0 0 1-4-4V7.2a4 4 0 0 1 4-4h7.1a4 4 0 0 1 4 4v.6" />
              <path d="M10.7 8.2h6.2a4 4 0 0 1 4 4v2.7a4 4 0 0 1-4 4h-2.5L11 21v-2.1h-.3a4 4 0 0 1-4-4v-2.7a4 4 0 0 1 4-4Z" />
            </svg>
          </a>
          <?php if ($canManageSales): ?>
            <a href="dashboard.php" title="Dashboard do gestor" aria-label="Dashboard do gestor">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 19V5" />
                <path d="M4 19h16" />
                <path d="M8 16V9" />
                <path d="M12 16V7" />
                <path d="M16 16v-5" />
              </svg>
            </a>
          <?php endif; ?>
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

      <aside class="wa-inbox" aria-label="Lista de conversas">
        <header class="wa-inbox-header">
          <h1>WhatsApp</h1>
        </header>

        <?php if ($sent): ?>
          <div class="wa-toast success">Mensagem enviada.</div>
        <?php endif; ?>

        <?php if ($sendError !== ''): ?>
          <div class="wa-toast"><?= htmlspecialchars($sendError) ?></div>
        <?php endif; ?>

        <?php if ($scheduled): ?>
          <div class="wa-toast success">Agendamento criado no Google Agenda.</div>
        <?php endif; ?>

        <?php if ($calendarError !== ''): ?>
          <div class="wa-toast"><?= htmlspecialchars($calendarErrorMessages[$calendarError] ?? 'Erro ao criar agendamento.') ?></div>
        <?php endif; ?>

        <label class="wa-search">
          <span>⌕</span>
          <input type="search" placeholder="Pesquisar ou começar uma nova conversa" data-wa-search />
        </label>

        <div class="wa-chat-list">
          <?php if (count($conversations) === 0): ?>
            <div class="wa-empty-list">
              <strong>Nenhuma conversa</strong>
              <span>As mensagens recebidas pelo WhatsApp aparecem aqui.</span>
            </div>
          <?php endif; ?>

          <?php foreach ($conversations as $conversation): ?>
            <?php $lead = $conversation['lead']; ?>
            <?php $leadId = (string) ($lead['id'] ?? ''); ?>
            <?php $isActive = is_array($activeLead) && in_array((string) ($activeLead['id'] ?? ''), $conversation['lead_ids'] ?? [], true); ?>
            <?php $leadName = (string) ($lead['name'] ?? 'Contato WhatsApp'); ?>
            <a
              class="wa-chat-item <?= $isActive ? 'active' : '' ?>"
              href="whatsapp.php?provider=<?= htmlspecialchars($providerFilter) ?>&lead=<?= htmlspecialchars($leadId) ?>"
              data-wa-chat
              data-wa-lead-id="<?= htmlspecialchars($leadId) ?>"
              data-wa-last-key="<?= htmlspecialchars(hash('sha256', (string) ($conversation['last_message_key'] ?? ''))) ?>"
              data-wa-last-direction="<?= htmlspecialchars((string) ($conversation['last_direction'] ?? '')) ?>"
              data-search="<?= htmlspecialchars(strtolower($leadName . ' ' . (string) ($lead['whatsapp'] ?? '') . ' ' . (string) $conversation['preview'])) ?>"
            >
              <span class="wa-avatar"><?= htmlspecialchars(strtoupper(substr(trim($leadName) ?: 'C', 0, 1))) ?></span>
              <span class="wa-chat-summary">
                <strong><?= htmlspecialchars($leadName) ?></strong>
                <small><?= htmlspecialchars(whatsapp_page_short_text((string) $conversation['preview'])) ?></small>
              </span>
              <span class="wa-chat-meta">
                <time><?= htmlspecialchars(whatsapp_page_time_label((string) $conversation['last_at'])) ?></time>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </aside>

      <section class="wa-thread" aria-label="Conversa selecionada">
        <?php if (!is_array($activeLead)): ?>
          <div class="wa-no-thread">
            <h2>Selecione uma conversa</h2>
            <p>Quando uma mensagem chegar pelo webhook, a conversa aparece nesta tela.</p>
          </div>
        <?php else: ?>
          <header class="wa-thread-header">
            <span class="wa-avatar large"><?= htmlspecialchars(strtoupper(substr(trim((string) ($activeLead['name'] ?? 'C')) ?: 'C', 0, 1))) ?></span>
            <div>
              <h2><?= htmlspecialchars((string) ($activeLead['name'] ?? 'Contato WhatsApp')) ?></h2>
              <p><?= htmlspecialchars(crm_normalize_lead_whatsapp((string) ($activeLead['whatsapp'] ?? ''))) ?></p>
            </div>
          </header>

          <div class="wa-message-surface">
            <div class="wa-day-chip">Histórico do CRM</div>

            <?php if (count($activeMessages) === 0): ?>
              <div class="wa-message wa-message-note">
                <p>Este contato ainda não tem mensagens registradas. Envie uma mensagem para iniciar o histórico.</p>
              </div>
            <?php endif; ?>

            <?php foreach ($activeMessages as $message): ?>
              <article class="wa-message wa-message-<?= htmlspecialchars((string) $message['direction']) ?>">
                <p><?= nl2br(htmlspecialchars((string) $message['text'])) ?></p>
                <footer>
                  <span><?= htmlspecialchars((string) $message['label']) ?></span>
                  <time><?= htmlspecialchars(whatsapp_page_time_label((string) $message['at'])) ?></time>
                </footer>
              </article>
            <?php endforeach; ?>
          </div>

          <form class="wa-composer" method="post" action="send-chat-message.php" enctype="multipart/form-data" data-wa-composer>
            <div class="wa-composer-tools">
              <button class="wa-tool-button" type="button" title="Anexar imagem, áudio ou documento" data-wa-attach aria-label="Anexar imagem, áudio ou documento">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M12 5v14M5 12h14" />
                </svg>
              </button>
              <button class="wa-tool-button" type="button" title="Inserir emoji" data-wa-emoji aria-label="Inserir emoji">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <circle cx="12" cy="12" r="8.5" />
                  <path d="M8.5 14.5a4.5 4.5 0 0 0 7 0M9 9.5h.01M15 9.5h.01" />
                </svg>
              </button>
              <div class="wa-emoji-menu" data-wa-emoji-menu hidden>
                <button type="button" data-wa-emoji-value="😀">😀</button>
                <button type="button" data-wa-emoji-value="😂">😂</button>
                <button type="button" data-wa-emoji-value="😍">😍</button>
                <button type="button" data-wa-emoji-value="👍">👍</button>
                <button type="button" data-wa-emoji-value="❤️">❤️</button>
                <button type="button" data-wa-emoji-value="🙏">🙏</button>
              </div>
              <input class="wa-media-input" type="file" name="media" accept="image/jpeg,image/png,image/webp,image/gif,audio/*,application/pdf,application/msword,application/rtf,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" data-wa-media hidden />
            </div>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
            <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
            <input type="hidden" name="provider_filter" value="<?= htmlspecialchars($providerFilter) ?>" />
            <div class="wa-composer-main">
              <div class="wa-media-preview" data-wa-preview hidden></div>
              <textarea name="message" rows="1" maxlength="2000" placeholder="Digite uma mensagem" data-wa-message></textarea>
            </div>
            <button class="wa-record-button" type="button" title="Gravar áudio" data-wa-record aria-label="Gravar áudio">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 14.5a3.5 3.5 0 0 0 3.5-3.5V6a3.5 3.5 0 0 0-7 0v5a3.5 3.5 0 0 0 3.5 3.5Z" />
                <path d="M19 11a7 7 0 0 1-14 0M12 18v3M8.5 21h7" />
              </svg>
            </button>
            <button class="wa-send-button" type="submit" title="Enviar mensagem" aria-label="Enviar mensagem">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="m4 4 16 8-16 8 3-8-3-8Z" />
                <path d="M7 12h13" />
              </svg>
            </button>
          </form>
        <?php endif; ?>
      </section>

      <aside class="wa-lead-panel" aria-label="Informações e recursos do lead">
        <?php if (!is_array($activeLead)): ?>
          <section class="wa-lead-panel-empty">
            <h2>Lead</h2>
            <p>Selecione uma conversa para visualizar os dados comerciais.</p>
          </section>
        <?php else: ?>
          <?php $activeLeadTags = crm_decode_lead_tags($activeLead); ?>
          <?php $activeLeadReturnUrl = 'whatsapp.php?provider=' . rawurlencode($providerFilter) . '&lead=' . rawurlencode((string) ($activeLead['id'] ?? '')); ?>
          <section class="wa-lead-profile">
            <span class="wa-avatar large"><?= htmlspecialchars(strtoupper(substr(trim((string) ($activeLead['name'] ?? 'C')) ?: 'C', 0, 1))) ?></span>
            <div>
              <p class="eyebrow">Lead em atendimento</p>
              <h2><?= htmlspecialchars((string) ($activeLead['name'] ?? 'Contato WhatsApp')) ?></h2>
            </div>
          </section>

          <section class="wa-lead-block">
            <h3>Informações</h3>
            <dl class="wa-lead-details">
              <div>
                <dt>WhatsApp</dt>
                <dd><?= htmlspecialchars(crm_normalize_lead_whatsapp((string) ($activeLead['whatsapp'] ?? ''))) ?></dd>
              </div>
              <div>
                <dt>CPF</dt>
                <dd><?= htmlspecialchars(crm_format_cpf((string) ($activeLead['cpf'] ?? '')) ?: 'Não informado') ?></dd>
              </div>
              <div>
                <dt>Vendedor</dt>
                <dd><?= htmlspecialchars(trim((string) ($activeLead['assigned_user_name'] ?? '')) !== '' ? (string) $activeLead['assigned_user_name'] : 'Sem vendedor') ?></dd>
              </div>
              <?php if ($canManageSettings): ?>
                <div>
                  <dt>Origem</dt>
                  <dd><?= htmlspecialchars(whatsapp_page_origin_summary($activeLead)) ?></dd>
                </div>
              <?php endif; ?>
              <div>
                <dt>Recebido em</dt>
                <dd><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($activeLead['created_at'] ?? 'now')))) ?></dd>
              </div>
              <div>
                <dt>Status WhatsApp</dt>
                <dd><?= htmlspecialchars(whatsapp_page_whatsapp_status($activeLead)) ?></dd>
              </div>
            </dl>
          </section>

          <section class="wa-lead-block">
            <h3>Dados do contato</h3>
            <form class="wa-side-form" method="post" action="update.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
              <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($activeLead['status'] ?? 'novo')) ?>" />
              <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
              <label>
                Nome do contato
                <input type="text" name="name" value="<?= htmlspecialchars((string) ($activeLead['name'] ?? '')) ?>" autocomplete="name" required />
              </label>
              <label>
                WhatsApp
                <input type="tel" name="whatsapp" value="<?= htmlspecialchars((string) ($activeLead['whatsapp'] ?? '')) ?>" autocomplete="tel" placeholder="55DDDNUMERO" />
              </label>
              <label>
                CPF do cliente
                <input type="text" name="cpf" value="<?= htmlspecialchars(crm_format_cpf((string) ($activeLead['cpf'] ?? ''))) ?>" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" maxlength="14" data-cpf-input />
              </label>
              <button type="submit">Salvar contato</button>
            </form>
          </section>

          <section class="wa-lead-block">
            <h3>Valores e previsão</h3>
            <form class="wa-side-form" method="post" action="update.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
              <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($activeLead['status'] ?? 'novo')) ?>" />
              <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
              <label>
                Valor da proposta
                <input type="text" name="proposal_value" value="<?= htmlspecialchars(whatsapp_page_money_input($activeLead, 'proposal_value')) ?>" inputmode="numeric" autocomplete="off" placeholder="R$ 0,00" data-currency-input />
              </label>
              <label>
                Previsão de fechamento
                <input type="date" name="expected_close_date" value="<?= htmlspecialchars((string) ($activeLead['expected_close_date'] ?? '')) ?>" />
              </label>
              <button type="submit">Salvar proposta</button>
            </form>
          </section>

          <section class="wa-lead-block">
            <h3>Tags e observações</h3>
            <?php if (count($activeLeadTags) > 0): ?>
              <div class="wa-lead-tags">
                <?php foreach ($activeLeadTags as $tag): ?>
                  <span><?= htmlspecialchars($tag) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <form class="wa-side-form" method="post" action="update.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
              <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($activeLead['status'] ?? 'novo')) ?>" />
              <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
              <label>
                Tags
                <input type="text" name="tags" value="<?= htmlspecialchars(implode(', ', $activeLeadTags)) ?>" placeholder="quente, proposta, retorno" />
              </label>
              <label>
                Observações comerciais
                <textarea name="notes" rows="4" placeholder="Resumo, objeções, próximos passos..."><?= htmlspecialchars((string) ($activeLead['notes'] ?? '')) ?></textarea>
              </label>
              <button type="submit">Salvar</button>
            </form>
          </section>

          <section class="wa-lead-block">
            <h3>Follow-up</h3>
            <form class="wa-side-form" method="post" action="assign-followup.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
              <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
              <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
              <label>
                Fluxo de follow-up
                <select name="flow_id" required>
                  <option value="">Selecione</option>
                  <?php foreach ($followupFlows as $flow): ?>
                    <option value="<?= (int) $flow['id'] ?>" <?= ((int) ($activeLead['followup_flow_id'] ?? 0) === (int) $flow['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars((string) $flow['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button type="submit">Aplicar</button>
            </form>
          </section>

          <section class="wa-lead-block">
            <h3>Agendamento</h3>
            <?php if (!$googleCalendarConnected): ?>
              <p class="wa-side-note">Google Agenda ainda não conectado.</p>
              <?php if ($canManageSettings): ?>
                <a class="secondary-action" href="settings.php">Configurar agenda</a>
              <?php endif; ?>
            <?php else: ?>
              <form class="wa-side-form" method="post" action="schedule-lead.php">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
                <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
                <label>
                  Título
                  <input type="text" name="title" value="<?= htmlspecialchars('Reunião com ' . (string) ($activeLead['name'] ?? 'lead')) ?>" />
                </label>
                <label>
                  Data
                  <input type="date" name="event_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required />
                </label>
                <label>
                  Horário
                  <input type="time" name="event_time" value="<?= htmlspecialchars(date('H:i', strtotime('+1 hour'))) ?>" required />
                </label>
                <label>
                  Duração
                  <select name="duration_minutes">
                    <option value="30">30 minutos</option>
                    <option value="45">45 minutos</option>
                    <option value="60" selected>1 hora</option>
                    <option value="90">1h30</option>
                  </select>
                </label>
                <label>
                  E-mail do convidado
                  <input type="email" name="attendee_email" placeholder="cliente@email.com" autocomplete="email" />
                </label>
                <label>
                  Observações
                  <textarea name="notes" rows="3" placeholder="Assunto da reunião, proposta, próximos passos..."></textarea>
                </label>
                <label class="wa-checkbox-field">
                  <input type="checkbox" name="send_updates" value="1" checked />
                  Enviar convite pelo Google Agenda
                </label>
                <button type="submit">Agendar</button>
              </form>
            <?php endif; ?>
          </section>

          <section class="wa-lead-actions">
            <a class="secondary-action" href="index.php?q=<?= urlencode((string) ($activeLead['whatsapp'] ?? '')) ?>">Abrir no kanban</a>
            <?php if ($canManageSettings): ?>
              <a class="secondary-action" href="settings.php">Configurar WhatsApp</a>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </aside>
    </main>
    <script>
      document.querySelectorAll(".wa-message-surface").forEach((surface) => {
        surface.scrollTop = surface.scrollHeight;
      });

      const bindConversationSearch = () => {
        const searchInput = document.querySelector("[data-wa-search]");

        if (!searchInput) {
          return;
        }

        searchInput.addEventListener("input", () => {
          const query = searchInput.value.trim().toLocaleLowerCase("pt-BR");

          document.querySelectorAll("[data-wa-chat]").forEach((chat) => {
            chat.hidden = query !== "" && !chat.dataset.search.includes(query);
          });
        });
      };

      bindConversationSearch();

      // O webhook grava a mensagem no servidor, mas esta página é renderizada
      // no PHP. Consulte-a periodicamente para exibir novas mensagens sem que
      // o atendente precise clicar novamente no contato.
      let refreshInFlight = false;
      const conversationHasUnsavedContent = () => {
        const composer = document.querySelector("[data-wa-composer]");
        const messageInput = composer?.querySelector("[data-wa-message]");
        const mediaInput = composer?.querySelector("[data-wa-media]");

        return Boolean(messageInput?.value.trim() || mediaInput?.files?.length);
      };

      const updateUnreadTitle = () => {
        const unreadCount = document.querySelectorAll(".wa-chat-item.has-unread").length;
        document.title = unreadCount > 0 ? `(${unreadCount}) Nova mensagem | Publi CRM` : "WhatsApp | Publi CRM";
      };

      const decorateUnreadConversations = (currentList, refreshedList) => {
        const previousChats = new Map();

        currentList?.querySelectorAll("[data-wa-chat]").forEach((chat) => {
          previousChats.set(chat.dataset.waLeadId || chat.href, {
            lastKey: chat.dataset.waLastKey || "",
            unread: chat.classList.contains("has-unread"),
          });
        });

        refreshedList?.querySelectorAll("[data-wa-chat]").forEach((chat) => {
          const previous = previousChats.get(chat.dataset.waLeadId || chat.href);
          const lastKeyChanged = !previous || previous.lastKey !== (chat.dataset.waLastKey || "");
          const isIncoming = chat.dataset.waLastDirection === "incoming";
          const isActive = chat.classList.contains("active");
          const shouldShowUnread = !isActive && (previous?.unread || (lastKeyChanged && isIncoming));
          chat.classList.toggle("has-unread", shouldShowUnread);
        });
      };

      const refreshConversation = async () => {
        if (refreshInFlight || document.visibilityState !== "visible" || conversationHasUnsavedContent()) {
          return;
        }

        refreshInFlight = true;

        try {
          const url = new URL(window.location.href);
          url.searchParams.set("_wa_refresh", String(Date.now()));
          const response = await fetch(url, {
            headers: { "X-Requested-With": "XMLHttpRequest", Accept: "text/html" },
            cache: "no-store",
          });

          if (!response.ok) {
            return;
          }

          const html = await response.text();
          const refreshedDocument = new DOMParser().parseFromString(html, "text/html");
          const currentList = document.querySelector(".wa-chat-list");
          const refreshedList = refreshedDocument.querySelector(".wa-chat-list");

          if (currentList && refreshedList && currentList.innerHTML !== refreshedList.innerHTML) {
            decorateUnreadConversations(currentList, refreshedList);
            currentList.replaceWith(refreshedList);
            bindConversationSearch();
            updateUnreadTitle();
          }

          const currentSurface = document.querySelector(".wa-message-surface");
          const refreshedSurface = refreshedDocument.querySelector(".wa-message-surface");

          if (currentSurface && refreshedSurface && currentSurface.innerHTML !== refreshedSurface.innerHTML) {
            const wasAtBottom = currentSurface.scrollHeight - currentSurface.scrollTop - currentSurface.clientHeight < 80;
            const currentIncomingMessages = new Set(
              Array.from(currentSurface.querySelectorAll(".wa-message-incoming")).map((message) => message.innerHTML),
            );

            refreshedSurface.querySelectorAll(".wa-message-incoming").forEach((message) => {
              if (!currentIncomingMessages.has(message.innerHTML)) {
                message.classList.add("is-new");
              }
            });

            currentSurface.replaceWith(refreshedSurface);

            if (wasAtBottom) {
              refreshedSurface.scrollTop = refreshedSurface.scrollHeight;
            }
          } else if (!currentSurface && refreshedSurface) {
            // Se a primeira mensagem chegou enquanto a tela estava sem conversa,
            // a seleção inicial precisa ser reconstruída pelo servidor.
            window.location.reload();
          }
        } catch (error) {
          // Uma falha momentânea de rede não deve interromper o atendimento.
        } finally {
          refreshInFlight = false;
        }
      };

      window.setInterval(refreshConversation, 2000);

      document.querySelectorAll(".wa-composer").forEach((form) => {
        const attachButton = form.querySelector("[data-wa-attach]");
        const emojiButton = form.querySelector("[data-wa-emoji]");
        const emojiMenu = form.querySelector("[data-wa-emoji-menu]");
        const recordButton = form.querySelector("[data-wa-record]");
        const mediaInput = form.querySelector("[data-wa-media]");
        const preview = form.querySelector("[data-wa-preview]");
        const messageInput = form.querySelector("[data-wa-message]");
        const sendButton = form.querySelector("[data-wa-send], .wa-send-button");
        let previewUrl = "";
        let recorder = null;
        let recorderStream = null;

        const setRecordButton = (recording) => {
          recordButton.innerHTML = recording
            ? '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="7" width="10" height="10" rx="2" /></svg>'
            : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 14.5a3.5 3.5 0 0 0 3.5-3.5V6a3.5 3.5 0 0 0-7 0v5a3.5 3.5 0 0 0 3.5 3.5Z" /><path d="M19 11a7 7 0 0 1-14 0M12 18v3M8.5 21h7" /></svg>';
          recordButton.title = recording ? "Parar gravação" : "Gravar áudio";
        };

        const syncComposerAction = () => {
          const hasContent = Boolean(messageInput.value.trim() || mediaInput.files?.length);
          recordButton.hidden = hasContent;
          sendButton.hidden = !hasContent;
          if (!recorder || recorder.state !== "recording") {
            setRecordButton(false);
          }
        };

        const renderMediaPreview = (file) => {
          preview.replaceChildren();
          preview.hidden = !file;

          if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = "";
          }

          if (!file) {
            syncComposerAction();
            return;
          }

          previewUrl = URL.createObjectURL(file);
          const isImage = file.type.startsWith("image/");
          const isAudio = file.type.startsWith("audio/");
          const label = document.createElement("span");
          label.textContent = isImage ? "Imagem selecionada" : (isAudio ? "Áudio selecionado" : "Documento selecionado");
          preview.appendChild(label);

          if (isImage || isAudio) {
            const media = document.createElement(isImage ? "img" : "audio");
            media.src = previewUrl;
            media.controls = isAudio;
            media.alt = file.name;
            preview.appendChild(media);
          } else {
            const fileName = document.createElement("strong");
            fileName.textContent = file.name;
            preview.appendChild(fileName);
          }

          const removeButton = document.createElement("button");
          removeButton.type = "button";
          removeButton.className = "wa-media-remove";
          removeButton.textContent = "Remover";
          removeButton.addEventListener("click", () => {
            mediaInput.value = "";
            renderMediaPreview(null);
          });
          preview.appendChild(removeButton);
          syncComposerAction();
        };

        attachButton?.addEventListener("click", () => mediaInput?.click());
        emojiButton?.addEventListener("click", () => {
          emojiMenu.hidden = !emojiMenu.hidden;
        });
        emojiMenu?.querySelectorAll("[data-wa-emoji-value]").forEach((button) => {
          button.addEventListener("click", () => {
            const start = messageInput.selectionStart;
            const end = messageInput.selectionEnd;
            const emoji = button.dataset.waEmojiValue || "";
            messageInput.value = messageInput.value.slice(0, start) + emoji + messageInput.value.slice(end);
            messageInput.focus();
            messageInput.selectionStart = messageInput.selectionEnd = start + emoji.length;
            emojiMenu.hidden = true;
            syncComposerAction();
          });
        });
        mediaInput?.addEventListener("change", () => renderMediaPreview(mediaInput.files?.[0] || null));
        messageInput?.addEventListener("input", syncComposerAction);
        syncComposerAction();

        recordButton?.addEventListener("click", async () => {
          if (recorder && recorder.state === "recording") {
            recorder.stop();
            return;
          }

          if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
            window.alert("Seu navegador não permite gravar áudio. Selecione um arquivo de áudio.");
            return;
          }

          try {
            recorderStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const preferredType = MediaRecorder.isTypeSupported("audio/ogg;codecs=opus")
              ? "audio/ogg;codecs=opus"
              : (MediaRecorder.isTypeSupported("audio/webm;codecs=opus") ? "audio/webm;codecs=opus" : "audio/webm");
            const chunks = [];
            recorder = new MediaRecorder(recorderStream, { mimeType: preferredType });
            recorder.addEventListener("dataavailable", (event) => {
              if (event.data.size > 0) chunks.push(event.data);
            });
            recorder.addEventListener("stop", () => {
              const extension = preferredType.startsWith("audio/ogg") ? "ogg" : "webm";
              const file = new File(chunks, `audio-whatsapp.${extension}`, { type: preferredType.split(";")[0] });
              const transfer = new DataTransfer();
              transfer.items.add(file);
              mediaInput.files = transfer.files;
              renderMediaPreview(file);
              recorderStream?.getTracks().forEach((track) => track.stop());
              recorderStream = null;
            });
            recorder.start();
            setRecordButton(true);
          } catch (error) {
            recorderStream?.getTracks().forEach((track) => track.stop());
            recorderStream = null;
            setRecordButton(false);
            window.alert("Não foi possível acessar o microfone.");
          }
        });

        form.addEventListener("submit", (event) => {
          if (!messageInput.value.trim() && !mediaInput.files?.length) {
            event.preventDefault();
            window.alert("Digite uma mensagem ou selecione uma imagem/áudio.");
            return;
          }

          const button = form.querySelector("button[type='submit']");

          if (button) {
            button.disabled = true;
            button.textContent = "…";
          }
        });
      });

      function parseCurrencyDigits(value) {
        const normalized = String(value || "").replace(/[^\d,]/g, "");

        if (!normalized) {
          return { reais: "", cents: "00" };
        }

        const parts = normalized.split(",");
        const reais = (parts[0] || "").replace(/\D/g, "");
        const cents = (parts[1] || "").replace(/\D/g, "").slice(0, 2).padEnd(2, "0");

        return { reais, cents };
      }

      function formatCurrencyBRL(reaisDigits, centsDigits = "00") {
        const reais = String(reaisDigits || "").replace(/\D/g, "").replace(/^0+(?=\d)/, "");

        if (!reais) {
          return "";
        }

        const cents = String(centsDigits || "00").replace(/\D/g, "").slice(0, 2).padEnd(2, "0");
        const amount = Number(`${reais}.${cents}`);

        return amount.toLocaleString("pt-BR", {
          style: "currency",
          currency: "BRL",
        });
      }

      function syncCurrencyInput(input, reais, cents = "00") {
        input.dataset.currencyReais = String(reais || "").replace(/\D/g, "").replace(/^0+(?=\d)/, "");
        input.dataset.currencyCents = String(cents || "00").replace(/\D/g, "").slice(0, 2).padEnd(2, "0");
        input.value = formatCurrencyBRL(input.dataset.currencyReais, input.dataset.currencyCents);
        requestAnimationFrame(() => {
          const end = input.value.length;
          input.setSelectionRange(end, end);
        });
      }

      function formatCpf(value) {
        const digits = String(value || "").replace(/\D/g, "").slice(0, 11);
        let formatted = digits.slice(0, 3);
        if (digits.length > 3) formatted += `.${digits.slice(3, 6)}`;
        if (digits.length > 6) formatted += `.${digits.slice(6, 9)}`;
        if (digits.length > 9) formatted += `-${digits.slice(9, 11)}`;
        return formatted;
      }

      document.querySelectorAll("[data-cpf-input]").forEach((input) => {
        input.value = formatCpf(input.value);
        input.addEventListener("input", () => {
          input.value = formatCpf(input.value);
        });
      });

      document.querySelectorAll("[data-currency-input]").forEach((input) => {
        const initial = parseCurrencyDigits(input.value);
        syncCurrencyInput(input, initial.reais, initial.cents);

        input.addEventListener("beforeinput", (event) => {
          const type = event.inputType || "";
          let reais = input.dataset.currencyReais || "";
          let cents = input.dataset.currencyCents || "00";

          if (type === "insertText" && /^\d$/.test(event.data || "")) {
            event.preventDefault();
            reais += event.data;
          } else if (type === "deleteContentBackward") {
            event.preventDefault();
            reais = reais.slice(0, -1);
          } else if (type === "deleteContentForward") {
            event.preventDefault();
            reais = "";
            cents = "00";
          } else if (type === "insertFromPaste") {
            return;
          } else {
            event.preventDefault();
            return;
          }

          syncCurrencyInput(input, reais, cents);
        });

        input.addEventListener("paste", (event) => {
          event.preventDefault();
          const parsed = parseCurrencyDigits(event.clipboardData?.getData("text") || "");
          syncCurrencyInput(input, parsed.reais, parsed.cents);
        });

        input.addEventListener("input", () => {
          const parsed = parseCurrencyDigits(input.value);
          syncCurrencyInput(input, parsed.reais, parsed.cents);
        });
      });
    </script>
  </body>
</html>
