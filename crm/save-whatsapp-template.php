<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/meta-whatsapp.php';
require_once __DIR__ . '/lib/whatsapp-templates.php';

crm_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: whatsapp-templates.php');
    exit;
}

crm_require_valid_csrf();

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$language = trim((string) ($_POST['language'] ?? 'pt_BR'));
$category = strtoupper(trim((string) ($_POST['category'] ?? 'UTILITY')));
$header = trim((string) ($_POST['header_text'] ?? ''));
$body = trim((string) ($_POST['body_text'] ?? ''));
$footer = trim((string) ($_POST['footer_text'] ?? ''));
$action = (string) ($_POST['action'] ?? 'save');

$redirect = 'whatsapp-templates.php?' . ($id > 0 ? 'id=' . $id : '');

if ($action === 'sync_meta') {
    $result = meta_whatsapp_list_templates();

    if (($result['ok'] ?? false) !== true) {
        header('Location: whatsapp-templates.php?error=' . rawurlencode((string) ($result['error'] ?? 'Não foi possível sincronizar os templates da Meta.')));
        exit;
    }

    $localTemplates = crm_read_whatsapp_templates();
    $localByName = [];

    foreach ($localTemplates as $localTemplate) {
        $localByName[(string) ($localTemplate['name'] ?? '')] = $localTemplate;
    }

    foreach (($result['response']['data'] ?? []) as $remoteTemplate) {
        if (!is_array($remoteTemplate)) {
            continue;
        }

        $remoteName = trim((string) ($remoteTemplate['name'] ?? ''));

        if ($remoteName === '' || !isset($localByName[$remoteName])) {
            continue;
        }

        crm_update_whatsapp_template_meta((int) $localByName[$remoteName]['id'], [
            'status' => strtolower((string) ($remoteTemplate['status'] ?? '')) === 'approved' ? 'approved' : 'pending',
            'meta_template_id' => (string) ($remoteTemplate['id'] ?? ''),
            'meta_status' => strtoupper((string) ($remoteTemplate['status'] ?? '')),
            'meta_rejection_reason' => '',
        ]);
    }

    header('Location: whatsapp-templates.php?synced=1');
    exit;
}

if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('O nome deve conter apenas letras minúsculas, números e sublinhado.'));
    exit;
}

if (!in_array($language, ['pt_BR', 'en_US', 'es_ES'], true)) {
    $language = 'pt_BR';
}

if (!in_array($category, ['UTILITY', 'MARKETING'], true)) {
    $category = 'UTILITY';
}

if ($body === '') {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Informe o corpo da mensagem.'));
    exit;
}

$templateVariables = crm_whatsapp_template_variables($body);

if (count($templateVariables) > 10) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Use no máximo 10 variáveis no corpo do template.'));
    exit;
}

if ($templateVariables !== [] && $templateVariables !== range(1, count($templateVariables))) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('As variáveis devem ser sequenciais: {{1}}, {{2}}, {{3}}...'));
    exit;
}

$current = $id > 0 ? crm_find_whatsapp_template($id) : null;

try {
    $templateId = crm_save_whatsapp_template([
        'id' => $id,
        'name' => $name,
        'language' => $language,
        'category' => $category,
        'header_text' => $header,
        'body_text' => $body,
        'footer_text' => $footer,
        'status' => 'draft',
        'created_by' => (int) (crm_current_user()['id'] ?? 0),
    ]);

    // Alterações no texto exigem uma nova aprovação antes do envio.
    crm_update_whatsapp_template_meta($templateId, [
        'status' => 'draft',
        'meta_template_id' => $action === 'submit_meta' ? (string) ($current['meta_template_id'] ?? '') : '',
        'meta_status' => '',
        'meta_rejection_reason' => '',
    ]);

    if ($action === 'submit_meta') {
        if (!crm_meta_whatsapp_is_configured() || trim((string) meta_whatsapp_settings()['business_account_id']) === '') {
            header('Location: whatsapp-templates.php?id=' . $templateId . '&error=' . rawurlencode('Configure a Meta Cloud API e o WABA ID antes de enviar o template para aprovação.'));
            exit;
        }

        $savedTemplate = crm_find_whatsapp_template($templateId) ?? [];
        $existingMetaId = trim((string) ($current['meta_template_id'] ?? ''));
        $result = $existingMetaId !== ''
            ? meta_whatsapp_update_template($existingMetaId, $savedTemplate)
            : meta_whatsapp_create_template($savedTemplate);

        if (($result['ok'] ?? false) !== true) {
            header('Location: whatsapp-templates.php?id=' . $templateId . '&error=' . rawurlencode((string) ($result['error'] ?? 'Não foi possível enviar o template para a Meta.')));
            exit;
        }

        crm_update_whatsapp_template_meta($templateId, [
            'status' => 'pending',
            'meta_template_id' => (string) ($result['response']['id'] ?? ''),
            'meta_status' => 'PENDING',
            'meta_rejection_reason' => '',
        ]);
    }
} catch (Throwable $error) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Não foi possível salvar o template. Verifique se o nome ainda não está em uso.'));
    exit;
}

header('Location: whatsapp-templates.php?id=' . $templateId . '&saved=1');
exit;
