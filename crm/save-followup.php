<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';

crm_require_sales_manager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $steps = [];
    $postedSteps = is_array($_POST['steps'] ?? null) ? $_POST['steps'] : [];
    $jsonSteps = json_decode((string) ($_POST['steps_json'] ?? ''), true);

    if (is_array($jsonSteps) && count($jsonSteps) > 0) {
        $postedSteps = $jsonSteps;
    }

    foreach ($postedSteps as $step) {
        $value = max(0, (int) ($step['delay_value'] ?? 0));
        $unit = (string) ($step['delay_unit'] ?? 'minutes');
        $multiplier = match ($unit) {
            'days' => 1440,
            'hours' => 60,
            default => 1,
        };

        $steps[] = [
            'delay_minutes' => $value * $multiplier,
            'message' => $step['message'] ?? '',
        ];
    }

    if (count($steps) === 0) {
        http_response_code(422);
        echo 'Nenhuma mensagem válida foi enviada no follow-up.';
        exit;
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($id > 0) {
        crm_update_followup_flow($id, $name, $description, $steps);
    } else {
        crm_create_followup_flow($name, $description, $steps);
    }
}

header('Location: followups.php');
exit;
