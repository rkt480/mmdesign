<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

return [
    'admin_user' => 'admin',
    'admin_password_hash' => 'cole-aqui-o-hash-gerado-com-password_hash',
    'company_name' => 'Publi AI Soluções',
    'auto_migrate' => false,
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'publi_ai_crm',
        'user' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'whatsapp' => [
        'internal_notification_message' => "Novo lead recebido:\n\nData/Hora: {{created_at_br}}\nNome: {{name}}\nWhatsApp: {{whatsapp}}\nEmpresa: {{company}}\nSite/Landing: {{segment}}\nControle dos leads: {{advertises}}\nNecessidade: {{message}}",
    ],
    'meta_whatsapp' => [
        'access_token' => '',
        'verify_token' => '',
        'app_secret' => '',
    ],
    'pilot_status' => [
        'base_url' => 'https://pilotstatus.com.br/v1',
        'api_key' => '',
        'webhook_secret' => '',
    ],
];
