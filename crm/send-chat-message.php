<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/whatsapp.php';

crm_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: whatsapp.php');
    exit;
}

crm_require_valid_csrf();

$leadId = trim((string) ($_POST['lead_id'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$providerFilter = trim((string) ($_POST['provider_filter'] ?? 'all'));
$currentUser = crm_current_user();
$senderName = crm_user_label($currentUser);

if ($senderName === 'Sem vendedor') {
    $senderName = 'Usuário do CRM';
}

if (!in_array($providerFilter, ['all', 'meta_cloud', 'pilot_status'], true)) {
    $providerFilter = 'all';
}

$redirect = 'whatsapp.php?provider=' . rawurlencode($providerFilter);

if ($leadId !== '') {
    $redirect .= '&lead=' . rawurlencode($leadId);
}

$media = $_FILES['media'] ?? null;
$hasMedia = is_array($media) && (int) ($media['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($message === '' && !$hasMedia) {
    header('Location: ' . $redirect . '&send_error=' . rawurlencode('Digite uma mensagem ou selecione uma imagem/áudio.'));
    exit;
}

$lead = crm_find_lead($leadId);

if ($lead === null) {
    header('Location: whatsapp.php?send_error=' . rawurlencode('Contato não encontrado.'));
    exit;
}

$number = crm_normalize_lead_whatsapp((string) ($lead['whatsapp'] ?? ''));

if ($number === '') {
    header('Location: ' . $redirect . '&send_error=' . rawurlencode('Este contato não tem WhatsApp válido.'));
    exit;
}

$messageWithSender = $senderName . ', disse:' . ($message !== '' ? "\n" . $message : '');
$providerLabel = crm_whatsapp_provider_label();
$mediaType = '';
$mimeType = '';
$mediaPath = '';
$fileName = '';

if ($hasMedia) {
    $uploadError = (int) ($media['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($media['tmp_name'] ?? ''))) {
        header('Location: ' . $redirect . '&send_error=' . rawurlencode('Não foi possível ler o arquivo selecionado.'));
        exit;
    }

    $fileSize = (int) ($media['size'] ?? 0);

    if ($fileSize < 1 || $fileSize > 16 * 1024 * 1024) {
        header('Location: ' . $redirect . '&send_error=' . rawurlencode('O arquivo deve ter entre 1 byte e 16 MB.'));
        exit;
    }

    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo !== false ? (string) finfo_file($fileInfo, (string) $media['tmp_name']) : '';

    if ($fileInfo !== false) {
        finfo_close($fileInfo);
    }

    $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) ($media['name'] ?? ''))) ?? '';
    $fileName = trim($fileName, '._-') ?: 'documento';
    $imageTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $audioTypes = ['audio/aac', 'audio/amr', 'audio/m4a', 'audio/mp4', 'audio/mpeg', 'audio/ogg', 'audio/opus', 'audio/webm', 'video/webm', 'audio/wav', 'audio/x-wav'];
    $documentTypes = [
        'application/pdf',
        'application/msword',
        'application/rtf',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'text/csv',
        'text/plain',
    ];

    if (in_array($mimeType, $imageTypes, true)) {
        $mediaType = 'image';
    } elseif (in_array($mimeType, $audioTypes, true)) {
        $mediaType = 'audio';
    } elseif (in_array($mimeType, $documentTypes, true)) {
        $mediaType = 'document';
    } else {
        header('Location: ' . $redirect . '&send_error=' . rawurlencode('Envie uma imagem, áudio ou documento compatível, como PDF, DOCX, XLSX, TXT ou CSV.'));
        exit;
    }

    $mediaPath = (string) $media['tmp_name'];
}

$result = $hasMedia
    ? crm_whatsapp_send_media($number, $mediaPath, $mimeType, $mediaType, $messageWithSender, $fileName)
    : crm_whatsapp_send_text($number, $messageWithSender, false);

if (($result['ok'] ?? false) === true) {
    $sentDescription = $hasMedia
        ? ucfirst($mediaType) . " enviada com legenda:\n" . $messageWithSender
        : $messageWithSender;
    crm_append_lead_note(
        $leadId,
        ($hasMedia ? 'Mídia enviada via ' : 'Mensagem enviada via ') . $providerLabel . ' em ' . date('d/m/Y H:i') . ":\n" . $sentDescription
    );
    crm_update_whatsapp_status($leadId, 'enviado');
    header('Location: ' . $redirect . '&sent=1');
    exit;
}

$error = 'Falha ao enviar via ' . $providerLabel . ': ' . (string) ($result['error'] ?? 'Erro desconhecido.');
crm_append_lead_note(
    $leadId,
    'Falha ao enviar via ' . $providerLabel . ' em ' . date('d/m/Y H:i') . ":\n" . $error
);

header('Location: ' . $redirect . '&send_error=' . rawurlencode($error));
exit;
