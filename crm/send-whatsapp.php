<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/whatsapp.php';

crm_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();

    $lead = crm_find_lead((string) ($_POST['id'] ?? ''));

    if ($lead !== null) {
        crm_whatsapp_send_lead_notification($lead);
    }
}

header('Location: index.php');
exit;
