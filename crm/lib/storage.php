<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

function crm_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require dirname(__DIR__) . '/config.php';
    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['database'],
        $db['charset']
    );

    $pdo = new PDO($dsn, (string) $db['user'], (string) $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    crm_ensure_crm_schema($pdo);

    return $pdo;
}

function crm_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function crm_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function crm_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND INDEX_NAME = :index_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'index_name' => $index,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function crm_column_character_length(PDO $pdo, string $table, string $column): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(CHARACTER_MAXIMUM_LENGTH, 0)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $stmt->fetchColumn();
}

function crm_ensure_crm_schema(PDO $pdo): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS kanban_columns (
            status VARCHAR(80) PRIMARY KEY,
            label VARCHAR(120) NOT NULL,
            position INT NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS lead_forms (
            id VARCHAR(32) PRIMARY KEY,
            slug VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(160) NOT NULL,
            draft_config LONGTEXT NOT NULL,
            published_config LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "draft",
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS crm_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            username VARCHAR(120) NOT NULL UNIQUE,
            email VARCHAR(180) NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(30) NOT NULL DEFAULT "vendedor",
            active TINYINT(1) NOT NULL DEFAULT 1,
            participates_in_rotation TINYINT(1) NOT NULL DEFAULT 0,
            rotation_weight INT NOT NULL DEFAULT 1,
            last_assigned_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_crm_users_role_active (role, active),
            INDEX idx_crm_users_rotation (participates_in_rotation, active, last_assigned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS lead_assignment_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id VARCHAR(32) NOT NULL,
            from_user_id INT NULL,
            to_user_id INT NULL,
            action VARCHAR(40) NOT NULL,
            reason VARCHAR(255) NULL,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_lead_assignment_logs_lead (lead_id, created_at),
            INDEX idx_lead_assignment_logs_to_user (to_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    crm_ensure_user_columns($pdo);
    crm_seed_default_admin_user($pdo);
    crm_seed_default_kanban_columns($pdo);

    if (crm_table_exists($pdo, 'leads')) {
        crm_ensure_lead_columns($pdo);
        crm_ensure_flexible_lead_status($pdo);
    }

    $checked = true;
}

function crm_ensure_user_columns(PDO $pdo): void
{
    $columns = [
        'email' => 'VARCHAR(180) NULL AFTER username',
        'role' => 'VARCHAR(30) NOT NULL DEFAULT "vendedor" AFTER password_hash',
        'active' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER role',
        'participates_in_rotation' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER active',
        'rotation_weight' => 'INT NOT NULL DEFAULT 1 AFTER participates_in_rotation',
        'last_assigned_at' => 'DATETIME NULL AFTER rotation_weight',
    ];

    foreach ($columns as $column => $definition) {
        if (!crm_column_exists($pdo, 'crm_users', $column)) {
            $pdo->exec(sprintf('ALTER TABLE crm_users ADD COLUMN %s %s', $column, $definition));
        }
    }

    if (!crm_index_exists($pdo, 'crm_users', 'idx_crm_users_rotation')) {
        $pdo->exec('ALTER TABLE crm_users ADD INDEX idx_crm_users_rotation (participates_in_rotation, active, last_assigned_at)');
    }
}

function crm_seed_default_admin_user(PDO $pdo): void
{
    $config = require dirname(__DIR__) . '/config.php';
    $username = trim((string) ($config['admin_user'] ?? 'admin'));
    $passwordHash = trim((string) ($config['admin_password_hash'] ?? ''));

    if ($username === '' || $passwordHash === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO crm_users
        (name, username, email, password_hash, role, active, participates_in_rotation, rotation_weight, created_at, updated_at)
        VALUES
        (:name, :username, NULL, :password_hash, "admin", 1, 0, 1, :created_at, :updated_at)
        ON DUPLICATE KEY UPDATE
            role = "admin",
            active = 1,
            updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        'name' => 'Administrador',
        'username' => $username,
        'password_hash' => $passwordHash,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function crm_ensure_lead_columns(PDO $pdo): void
{
    $columns = [
        'cpf' => 'VARCHAR(14) NULL AFTER whatsapp',
        'utm_source' => 'VARCHAR(120) NULL AFTER page',
        'utm_medium' => 'VARCHAR(120) NULL AFTER utm_source',
        'utm_campaign' => 'VARCHAR(180) NULL AFTER utm_medium',
        'utm_content' => 'VARCHAR(180) NULL AFTER utm_campaign',
        'utm_term' => 'VARCHAR(180) NULL AFTER utm_content',
        'referrer' => 'TEXT NULL AFTER utm_term',
        'landing_path' => 'TEXT NULL AFTER referrer',
        'tags' => 'TEXT NULL AFTER notes',
        'kanban_position' => 'INT NOT NULL DEFAULT 0 AFTER status',
        'form_id' => 'VARCHAR(32) NULL AFTER landing_path',
        'form_answers' => 'LONGTEXT NULL AFTER form_id',
        'lead_score' => 'INT NULL AFTER form_answers',
        'lead_temperature' => 'VARCHAR(20) NULL AFTER lead_score',
        'score_reasons' => 'TEXT NULL AFTER lead_temperature',
        'assigned_user_id' => 'INT NULL AFTER tags',
        'assigned_at' => 'DATETIME NULL AFTER assigned_user_id',
        'last_activity_at' => 'DATETIME NULL AFTER assigned_at',
        'last_activity_type' => 'VARCHAR(60) NULL AFTER last_activity_at',
        'sla_last_checked_at' => 'DATETIME NULL AFTER last_activity_type',
        'estimated_value' => 'DECIMAL(12,2) NULL AFTER sla_last_checked_at',
        'proposal_value' => 'DECIMAL(12,2) NULL AFTER estimated_value',
        'expected_close_date' => 'DATE NULL AFTER proposal_value',
        'lost_reason' => 'VARCHAR(180) NULL AFTER expected_close_date',
        'first_contact_at' => 'DATETIME NULL AFTER lost_reason',
        'closed_at' => 'DATETIME NULL AFTER first_contact_at',
        'lost_at' => 'DATETIME NULL AFTER closed_at',
    ];

    foreach ($columns as $column => $definition) {
        if (!crm_column_exists($pdo, 'leads', $column)) {
            $pdo->exec(sprintf('ALTER TABLE leads ADD COLUMN %s %s', $column, $definition));
        }
    }

    foreach (['segment', 'advertises'] as $column) {
        if (crm_column_character_length($pdo, 'leads', $column) < 180) {
            $pdo->exec(sprintf('ALTER TABLE leads MODIFY COLUMN %s VARCHAR(180) NULL', $column));
        }
    }

    if (!crm_index_exists($pdo, 'leads', 'idx_leads_assigned_user')) {
        $pdo->exec('ALTER TABLE leads ADD INDEX idx_leads_assigned_user (assigned_user_id, status, updated_at)');
    }

    if (!crm_index_exists($pdo, 'leads', 'idx_leads_activity')) {
        $pdo->exec('ALTER TABLE leads ADD INDEX idx_leads_activity (last_activity_at, status)');
    }
}

function crm_ensure_flexible_lead_status(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'SELECT DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = "leads"
          AND COLUMN_NAME = "status"
        LIMIT 1'
    );
    $stmt->execute();

    if ((string) $stmt->fetchColumn() !== 'varchar') {
        $pdo->exec('ALTER TABLE leads MODIFY COLUMN status VARCHAR(80) NOT NULL DEFAULT "novo"');
    }
}

function crm_default_kanban_columns(): array
{
    return [
        ['status' => 'novo', 'label' => 'Novo', 'position' => 10],
        ['status' => 'contatado', 'label' => 'Contatado', 'position' => 20],
        ['status' => 'followup', 'label' => 'Follow-up', 'position' => 30],
        ['status' => 'proposta', 'label' => 'Proposta enviada', 'position' => 40],
        ['status' => 'fechado', 'label' => 'Fechado', 'position' => 50],
        ['status' => 'perdido', 'label' => 'Perdido', 'position' => 60],
    ];
}

function crm_seed_default_kanban_columns(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO kanban_columns (status, label, position, is_system, active, created_at, updated_at)
        VALUES (:status, :label, :position, 1, 1, :created_at, :updated_at)
        ON DUPLICATE KEY UPDATE
            label = IF(label = "", VALUES(label), label),
            position = IF(position = 0, VALUES(position), position),
            active = 1,
            updated_at = VALUES(updated_at)'
    );

    foreach (crm_default_kanban_columns() as $column) {
        $stmt->execute([
            'status' => $column['status'],
            'label' => $column['label'],
            'position' => $column['position'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

function crm_read_kanban_columns(bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM kanban_columns';

    if ($onlyActive) {
        $sql .= ' WHERE active = 1';
    }

    $sql .= ' ORDER BY position ASC, created_at ASC';

    return crm_db()->query($sql)->fetchAll();
}

function crm_kanban_status_exists(string $status): bool
{
    $stmt = crm_db()->prepare('SELECT COUNT(*) FROM kanban_columns WHERE status = :status AND active = 1');
    $stmt->execute(['status' => $status]);

    return (int) $stmt->fetchColumn() > 0;
}

function crm_slugify_status(string $label): string
{
    $label = trim($label);
    $ascii = function_exists('iconv') ? (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) : $label;
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii));
    $slug = trim($slug, '-');

    return $slug !== '' ? substr($slug, 0, 56) : 'coluna';
}

function crm_create_kanban_column(string $label): ?string
{
    $label = trim($label);

    if ($label === '') {
        return null;
    }

    $baseStatus = crm_slugify_status($label);
    $status = $baseStatus;
    $suffix = 2;

    $exists = crm_db()->prepare('SELECT COUNT(*) FROM kanban_columns WHERE status = :status');

    while (true) {
        $exists->execute(['status' => $status]);

        if ((int) $exists->fetchColumn() === 0) {
            break;
        }

        $status = substr($baseStatus, 0, 52) . '-' . $suffix;
        $suffix++;
    }

    $position = (int) crm_db()->query('SELECT COALESCE(MAX(position), 0) + 10 FROM kanban_columns')->fetchColumn();
    $stmt = crm_db()->prepare(
        'INSERT INTO kanban_columns (status, label, position, is_system, active, created_at, updated_at)
        VALUES (:status, :label, :position, 0, 1, :created_at, :updated_at)'
    );
    $stmt->execute([
        'status' => $status,
        'label' => $label,
        'position' => $position,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return $status;
}

function crm_update_kanban_columns(array $columns, array $removeStatuses = []): void
{
    $db = crm_db();
    $update = $db->prepare(
        'UPDATE kanban_columns
        SET label = :label,
            position = :position,
            updated_at = :updated_at
        WHERE status = :status'
    );
    $position = 10;

    foreach ($columns as $column) {
        $status = trim((string) ($column['status'] ?? ''));
        $label = trim((string) ($column['label'] ?? ''));

        if ($status === '' || $label === '') {
            continue;
        }

        $update->execute([
            'status' => $status,
            'label' => $label,
            'position' => $position,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $position += 10;
    }

    foreach ($removeStatuses as $status) {
        crm_archive_kanban_column((string) $status);
    }
}

function crm_archive_kanban_column(string $status): bool
{
    $status = trim($status);

    if ($status === '' || $status === 'novo') {
        return false;
    }

    $stmt = crm_db()->prepare('SELECT is_system FROM kanban_columns WHERE status = :status LIMIT 1');
    $stmt->execute(['status' => $status]);
    $column = $stmt->fetch();

    if (!is_array($column) || (int) ($column['is_system'] ?? 0) === 1) {
        return false;
    }

    $moveLeads = crm_db()->prepare('UPDATE leads SET status = "novo", updated_at = :updated_at WHERE status = :status');
    $moveLeads->execute([
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $archive = crm_db()->prepare('UPDATE kanban_columns SET active = 0, updated_at = :updated_at WHERE status = :status');
    $archive->execute([
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return $archive->rowCount() > 0;
}

function crm_decode_lead_tags(array $lead): array
{
    $raw = trim((string) ($lead['tags'] ?? ''));

    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded), static fn(string $tag): bool => trim($tag) !== ''));
    }

    return crm_parse_tags($raw);
}

function crm_parse_tags(string $tags): array
{
    $parts = preg_split('/[,;\n]+/', $tags) ?: [];
    $normalized = [];

    foreach ($parts as $part) {
        $tag = trim((string) $part);

        if ($tag === '') {
            continue;
        }

        $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
        $normalized[$key] = substr($tag, 0, 40);
    }

    return array_values($normalized);
}

function crm_encode_tags(string $tags): string
{
    return json_encode(crm_parse_tags($tags), JSON_UNESCAPED_UNICODE) ?: '[]';
}

function crm_normalize_money($value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $value = preg_replace('/[^\d,.\-]/', '', $value) ?? '';

    if ($value === '' || $value === '-') {
        return null;
    }

    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (str_contains($value, ',')) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
}

function crm_normalize_date($value): ?string
{
    $value = trim((string) $value);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));

    return checkdate($month, $day, $year) ? $value : null;
}

function crm_read_available_tags(array $leads): array
{
    $tags = [];

    foreach ($leads as $lead) {
        foreach (crm_decode_lead_tags($lead) as $tag) {
            $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
            $tags[$key] = $tag;
        }
    }

    natcasesort($tags);

    return array_values($tags);
}

function crm_user_roles(): array
{
    return [
        'admin' => 'Administrador',
        'gestor' => 'Gestor comercial',
        'vendedor' => 'Vendedor',
    ];
}

function crm_normalize_user_role(string $role): string
{
    return array_key_exists($role, crm_user_roles()) ? $role : 'vendedor';
}

function crm_find_user_by_username(string $username): ?array
{
    $username = trim($username);

    if ($username === '') {
        return null;
    }

    $stmt = crm_db()->prepare('SELECT * FROM crm_users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function crm_find_user_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = crm_db()->prepare('SELECT * FROM crm_users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function crm_read_users(bool $includeInactive = true): array
{
    $sql = 'SELECT * FROM crm_users';

    if (!$includeInactive) {
        $sql .= ' WHERE active = 1';
    }

    $sql .= ' ORDER BY active DESC, role ASC, name ASC, username ASC';

    return crm_db()->query($sql)->fetchAll();
}

function crm_read_assignable_users(bool $rotationOnly = false, ?int $excludeUserId = null): array
{
    $sql = 'SELECT * FROM crm_users
        WHERE active = 1
          AND role IN ("gestor", "vendedor")';
    $params = [];

    if ($rotationOnly) {
        $sql .= ' AND participates_in_rotation = 1';
    }

    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id <> :exclude_user_id';
        $params['exclude_user_id'] = $excludeUserId;
    }

    if ($rotationOnly) {
        $sql .= ' ORDER BY (TIMESTAMPDIFF(SECOND, COALESCE(last_assigned_at, "1970-01-01 00:00:00"), NOW()) * GREATEST(rotation_weight, 1)) DESC, name ASC, id ASC';
    } else {
        $sql .= ' ORDER BY name ASC, username ASC, id ASC';
    }

    $stmt = crm_db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function crm_user_label(?array $user): string
{
    if (!is_array($user)) {
        return 'Sem vendedor';
    }

    $name = trim((string) ($user['name'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    return trim((string) ($user['username'] ?? 'Usuário'));
}

function crm_current_storage_user(): ?array
{
    if (!function_exists('crm_current_user')) {
        return null;
    }

    $user = crm_current_user();

    return is_array($user) ? $user : null;
}

function crm_user_can_manage_lead_scope(?array $user): bool
{
    $role = (string) ($user['role'] ?? '');
    return in_array($role, ['admin', 'gestor'], true);
}

function crm_lead_access_sql(string $alias = 'leads', bool $applyAccess = true): array
{
    if (!$applyAccess) {
        return ['', []];
    }

    $user = crm_current_storage_user();

    if ($user === null) {
        if (function_exists('crm_is_logged_in') && crm_is_logged_in()) {
            return [' AND 1 = 0', []];
        }

        return ['', []];
    }

    if (crm_user_can_manage_lead_scope($user)) {
        return ['', []];
    }

    $userId = (int) ($user['id'] ?? 0);

    if ($userId <= 0) {
        return [' AND 1 = 0', []];
    }

    return [' AND ' . $alias . '.assigned_user_id = :viewer_user_id', ['viewer_user_id' => $userId]];
}

function crm_user_can_access_lead(array $lead): bool
{
    $user = crm_current_storage_user();

    if ($user === null) {
        return !(function_exists('crm_is_logged_in') && crm_is_logged_in());
    }

    if (crm_user_can_manage_lead_scope($user)) {
        return true;
    }

    return (int) ($lead['assigned_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
}

function crm_validate_assignable_user_id(?int $userId): ?int
{
    if ($userId === null || $userId <= 0) {
        return null;
    }

    $user = crm_find_user_by_id($userId);

    if (!is_array($user) || (int) ($user['active'] ?? 0) !== 1) {
        return null;
    }

    return in_array((string) ($user['role'] ?? ''), ['gestor', 'vendedor'], true) ? (int) $user['id'] : null;
}

function crm_pick_rotation_user(?int $excludeUserId = null): ?array
{
    $users = crm_read_assignable_users(true, $excludeUserId);

    if (count($users) > 0) {
        return $users[0];
    }

    if ($excludeUserId !== null) {
        $fallback = crm_read_assignable_users(true);
        return $fallback[0] ?? null;
    }

    return null;
}

function crm_pick_manager_user(?int $excludeUserId = null): ?array
{
    $sql = 'SELECT * FROM crm_users WHERE active = 1 AND role = "gestor"';
    $params = [];

    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id <> :exclude_user_id';
        $params['exclude_user_id'] = $excludeUserId;
    }

    $sql .= ' ORDER BY name ASC, username ASC, id ASC LIMIT 1';
    $stmt = crm_db()->prepare($sql);
    $stmt->execute($params);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function crm_record_lead_assignment(string $leadId, ?int $fromUserId, ?int $toUserId, string $action, string $reason = '', ?int $createdByUserId = null): void
{
    $stmt = crm_db()->prepare(
        'INSERT INTO lead_assignment_logs
        (lead_id, from_user_id, to_user_id, action, reason, created_by_user_id, created_at)
        VALUES
        (:lead_id, :from_user_id, :to_user_id, :action, :reason, :created_by_user_id, :created_at)'
    );
    $stmt->execute([
        'lead_id' => $leadId,
        'from_user_id' => $fromUserId,
        'to_user_id' => $toUserId,
        'action' => substr(trim($action), 0, 40),
        'reason' => substr(trim($reason), 0, 255),
        'created_by_user_id' => $createdByUserId,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function crm_touch_user_assigned_at(?int $userId): void
{
    if ($userId === null || $userId <= 0) {
        return;
    }

    $stmt = crm_db()->prepare('UPDATE crm_users SET last_assigned_at = :assigned_at, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        'id' => $userId,
        'assigned_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function crm_assign_lead_to_user(string $leadId, ?int $toUserId, string $action, string $reason = '', ?int $createdByUserId = null, bool $applyAccess = true): bool
{
    $lead = crm_find_lead($leadId, $applyAccess);

    if ($lead === null) {
        return false;
    }

    $toUserId = crm_validate_assignable_user_id($toUserId);
    $fromUserId = isset($lead['assigned_user_id']) && $lead['assigned_user_id'] !== null ? (int) $lead['assigned_user_id'] : null;

    if ($fromUserId === $toUserId) {
        return true;
    }

    [$accessSql, $accessParams] = crm_lead_access_sql('leads', $applyAccess);
    $stmt = crm_db()->prepare(
        'UPDATE leads
        SET assigned_user_id = :assigned_user_id,
            assigned_at = :assigned_at,
            last_activity_at = :last_activity_at,
            last_activity_type = :last_activity_type,
            updated_at = :updated_at
        WHERE id = :id' . $accessSql
    );
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        'id' => $leadId,
        'assigned_user_id' => $toUserId,
        'assigned_at' => $toUserId !== null ? $now : null,
        'last_activity_at' => $now,
        'last_activity_type' => $action,
        'updated_at' => $now,
    ] + $accessParams);

    if ($stmt->rowCount() === 0) {
        return false;
    }

    crm_record_lead_assignment($leadId, $fromUserId, $toUserId, $action, $reason, $createdByUserId);
    crm_touch_user_assigned_at($toUserId);

    return true;
}

function crm_save_user(array $payload): array
{
    $id = max(0, (int) ($payload['id'] ?? 0));
    $name = trim((string) ($payload['name'] ?? ''));
    $username = trim((string) ($payload['username'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $role = crm_normalize_user_role((string) ($payload['role'] ?? 'vendedor'));
    $active = !empty($payload['active']) ? 1 : 0;
    $participates = !empty($payload['participates_in_rotation']) ? 1 : 0;
    $weight = max(1, min(10, (int) ($payload['rotation_weight'] ?? 1)));

    if ($name === '' || $username === '') {
        return ['ok' => false, 'error' => 'Informe nome e usuário.'];
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'error' => 'Informe um e-mail válido.'];
    }

    if ($id === 0 && trim($password) === '') {
        return ['ok' => false, 'error' => 'Informe uma senha para criar o usuário.'];
    }

    if (trim($password) !== '' && strlen($password) < 6) {
        return ['ok' => false, 'error' => 'Use uma senha com pelo menos 6 caracteres.'];
    }

    try {
        if ($id > 0) {
            $existing = crm_find_user_by_id($id);

            if ($existing === null) {
                return ['ok' => false, 'error' => 'Usuário não encontrado.'];
            }

            $currentUser = crm_current_storage_user();

            if (is_array($currentUser) && (int) ($currentUser['id'] ?? 0) === $id && ($active !== 1 || $role !== 'admin')) {
                return ['ok' => false, 'error' => 'Você não pode remover seu próprio acesso de administrador.'];
            }

            $fields = [
                'name = :name',
                'username = :username',
                'email = :email',
                'role = :role',
                'active = :active',
                'participates_in_rotation = :participates_in_rotation',
                'rotation_weight = :rotation_weight',
                'updated_at = :updated_at',
            ];
            $params = [
                'id' => $id,
                'name' => $name,
                'username' => $username,
                'email' => $email !== '' ? $email : null,
                'role' => $role,
                'active' => $active,
                'participates_in_rotation' => $participates,
                'rotation_weight' => $weight,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if (trim($password) !== '') {
                $fields[] = 'password_hash = :password_hash';
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $stmt = crm_db()->prepare('UPDATE crm_users SET ' . implode(', ', $fields) . ' WHERE id = :id');
            $stmt->execute($params);

            return ['ok' => true, 'id' => $id];
        }

        $stmt = crm_db()->prepare(
            'INSERT INTO crm_users
            (name, username, email, password_hash, role, active, participates_in_rotation, rotation_weight, created_at, updated_at)
            VALUES
            (:name, :username, :email, :password_hash, :role, :active, :participates_in_rotation, :rotation_weight, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => $name,
            'username' => $username,
            'email' => $email !== '' ? $email : null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'active' => $active,
            'participates_in_rotation' => $participates,
            'rotation_weight' => $weight,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'id' => (int) crm_db()->lastInsertId()];
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            return ['ok' => false, 'error' => 'Este usuário já está em uso.'];
        }

        throw $error;
    }
}

function crm_count_active_admins(): int
{
    $stmt = crm_db()->query('SELECT COUNT(*) FROM crm_users WHERE role = "admin" AND active = 1');
    return (int) $stmt->fetchColumn();
}

function crm_delete_user(int $id): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Usuário inválido.'];
    }

    $user = crm_find_user_by_id($id);

    if ($user === null) {
        return ['ok' => false, 'error' => 'Usuário não encontrado.'];
    }

    $currentUser = crm_current_storage_user();

    if (is_array($currentUser) && (int) ($currentUser['id'] ?? 0) === $id) {
        return ['ok' => false, 'error' => 'Você não pode excluir o próprio acesso.'];
    }

    if ((string) ($user['role'] ?? '') === 'admin' && crm_count_active_admins() <= 1) {
        return ['ok' => false, 'error' => 'Mantenha pelo menos um administrador ativo no CRM.'];
    }

    $db = crm_db();
    $db->beginTransaction();

    try {
        $assignedLeads = $db->prepare('SELECT id FROM leads WHERE assigned_user_id = :user_id');
        $assignedLeads->execute(['user_id' => $id]);
        $leads = $assignedLeads->fetchAll();

        foreach ($leads as $lead) {
            crm_record_lead_assignment(
                (string) ($lead['id'] ?? ''),
                $id,
                null,
                'user_deleted',
                'Usuário excluído da área comercial.',
                is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) ?: null : null
            );
        }

        $clearLeads = $db->prepare(
            'UPDATE leads
            SET assigned_user_id = NULL,
                assigned_at = NULL,
                last_activity_at = :last_activity_at,
                last_activity_type = "user_deleted",
                updated_at = :updated_at
            WHERE assigned_user_id = :user_id'
        );
        $now = date('Y-m-d H:i:s');
        $clearLeads->execute([
            'user_id' => $id,
            'last_activity_at' => $now,
            'updated_at' => $now,
        ]);

        $delete = $db->prepare('DELETE FROM crm_users WHERE id = :id');
        $delete->execute(['id' => $id]);

        $db->commit();

        return ['ok' => true];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $error;
    }
}

function crm_read_leads(): array
{
    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $stmt = crm_db()->prepare(
        'SELECT leads.*, crm_users.name AS assigned_user_name, crm_users.username AS assigned_username
        FROM leads
        LEFT JOIN crm_users ON crm_users.id = leads.assigned_user_id
        WHERE 1 = 1' . $accessSql . '
        ORDER BY leads.status ASC, leads.kanban_position ASC, leads.created_at DESC'
    );
    $stmt->execute($accessParams);

    return $stmt->fetchAll();
}

function crm_normalize_lead_whatsapp(string $phone): string
{
    return crm_whatsapp_number_variants($phone)[0] ?? '';
}

function crm_normalize_cpf(string $cpf): string
{
    $digits = preg_replace('/\D+/', '', $cpf) ?? '';

    return substr($digits, 0, 11);
}

function crm_format_cpf(string $cpf): string
{
    $digits = crm_normalize_cpf($cpf);

    if (strlen($digits) !== 11) {
        return $digits;
    }

    return substr($digits, 0, 3) . '.'
        . substr($digits, 3, 3) . '.'
        . substr($digits, 6, 3) . '-'
        . substr($digits, 9, 2);
}

function crm_find_lead_by_whatsapp(string $whatsapp): ?array
{
    $variants = crm_whatsapp_number_variants($whatsapp);

    if ($variants === []) {
        return null;
    }

    $stmt = crm_db()->query('SELECT * FROM leads ORDER BY created_at DESC');

    foreach ($stmt->fetchAll() as $lead) {
        $leadVariants = crm_whatsapp_number_variants((string) ($lead['whatsapp'] ?? ''));

        if (array_intersect($variants, $leadVariants) !== []) {
            return $lead;
        }
    }

    return null;
}

function crm_create_lead(array $payload): array
{
    $settings = crm_sales_distribution_settings();
    $assignedUserId = crm_validate_assignable_user_id(isset($payload['assigned_user_id']) ? (int) $payload['assigned_user_id'] : null);
    $assignmentAction = $assignedUserId !== null ? 'manual_assignment' : '';
    $assignmentReason = $assignedUserId !== null ? 'Atribuição informada na criação do lead.' : '';

    if ($assignedUserId === null && $settings['rotation_enabled']) {
        $rotationUser = crm_pick_rotation_user();

        if (is_array($rotationUser)) {
            $assignedUserId = (int) $rotationUser['id'];
            $assignmentAction = 'rotation_assignment';
            $assignmentReason = 'Lead atribuído automaticamente pela roleta.';
        }
    }

    $now = date('Y-m-d H:i:s');
    $lead = [
        'id' => bin2hex(random_bytes(8)),
        'name' => trim((string) ($payload['name'] ?? '')),
        'whatsapp' => crm_normalize_lead_whatsapp((string) ($payload['whatsapp'] ?? '')),
        'cpf' => crm_normalize_cpf((string) ($payload['cpf'] ?? '')),
        'company' => trim((string) ($payload['company'] ?? '')),
        'segment' => trim((string) ($payload['segment'] ?? '')),
        'advertises' => trim((string) ($payload['advertises'] ?? '')),
        'message' => trim((string) ($payload['message'] ?? '')),
        'page' => trim((string) ($payload['page'] ?? '')),
        'utm_source' => trim((string) ($payload['utm_source'] ?? '')),
        'utm_medium' => trim((string) ($payload['utm_medium'] ?? '')),
        'utm_campaign' => trim((string) ($payload['utm_campaign'] ?? '')),
        'utm_content' => trim((string) ($payload['utm_content'] ?? '')),
        'utm_term' => trim((string) ($payload['utm_term'] ?? '')),
        'referrer' => trim((string) ($payload['referrer'] ?? '')),
        'landing_path' => trim((string) ($payload['landing_path'] ?? '')),
        'form_id' => trim((string) ($payload['form_id'] ?? '')) ?: null,
        'form_answers' => json_encode($payload['form_answers'] ?? [], JSON_UNESCAPED_UNICODE) ?: '[]',
        'lead_score' => isset($payload['lead_score']) ? max(0, min(100, (int) $payload['lead_score'])) : null,
        'lead_temperature' => trim((string) ($payload['lead_temperature'] ?? '')) ?: null,
        'score_reasons' => json_encode($payload['score_reasons'] ?? [], JSON_UNESCAPED_UNICODE) ?: '[]',
        'status' => crm_kanban_status_exists(trim((string) ($payload['status'] ?? '')))
            ? trim((string) ($payload['status'] ?? ''))
            : 'novo',
        'notes' => '',
        'tags' => crm_encode_tags((string) ($payload['tags'] ?? '')),
        'assigned_user_id' => $assignedUserId,
        'assigned_at' => $assignedUserId !== null ? $now : null,
        'last_activity_at' => $now,
        'last_activity_type' => 'created',
        'sla_last_checked_at' => null,
        'estimated_value' => crm_normalize_money($payload['estimated_value'] ?? ''),
        'proposal_value' => crm_normalize_money($payload['proposal_value'] ?? ''),
        'expected_close_date' => crm_normalize_date($payload['expected_close_date'] ?? ''),
        'lost_reason' => trim((string) ($payload['lost_reason'] ?? '')) ?: null,
        'first_contact_at' => null,
        'closed_at' => null,
        'lost_at' => null,
        'whatsapp_status' => 'pendente',
        'whatsapp_sent_at' => null,
        'whatsapp_error' => null,
        'followup_flow_id' => null,
        'followup_started_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $stmt = crm_db()->prepare(
        'INSERT INTO leads
        (id, name, whatsapp, cpf, company, segment, advertises, message, page, utm_source, utm_medium, utm_campaign, utm_content, utm_term, referrer, landing_path, form_id, form_answers, lead_score, lead_temperature, score_reasons, status, notes, tags, assigned_user_id, assigned_at, last_activity_at, last_activity_type, sla_last_checked_at, estimated_value, proposal_value, expected_close_date, lost_reason, first_contact_at, closed_at, lost_at, whatsapp_status, whatsapp_sent_at, whatsapp_error, followup_flow_id, followup_started_at, created_at, updated_at)
        VALUES
        (:id, :name, :whatsapp, :cpf, :company, :segment, :advertises, :message, :page, :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term, :referrer, :landing_path, :form_id, :form_answers, :lead_score, :lead_temperature, :score_reasons, :status, :notes, :tags, :assigned_user_id, :assigned_at, :last_activity_at, :last_activity_type, :sla_last_checked_at, :estimated_value, :proposal_value, :expected_close_date, :lost_reason, :first_contact_at, :closed_at, :lost_at, :whatsapp_status, :whatsapp_sent_at, :whatsapp_error, :followup_flow_id, :followup_started_at, :created_at, :updated_at)'
    );
    $stmt->execute($lead);

    if ($assignedUserId !== null) {
        crm_record_lead_assignment((string) $lead['id'], null, $assignedUserId, $assignmentAction, $assignmentReason, null);
        crm_touch_user_assigned_at($assignedUserId);
    }

    return $lead;
}

function crm_create_lead_once(array $payload): array
{
    $existingLead = crm_find_lead_by_whatsapp((string) ($payload['whatsapp'] ?? ''));

    if ($existingLead !== null) {
        return [
            'lead' => $existingLead,
            'created' => false,
        ];
    }

    return [
        'lead' => crm_create_lead($payload),
        'created' => true,
    ];
}

function crm_append_lead_note(string $id, string $note): bool
{
    $note = trim($note);

    if ($note === '') {
        return false;
    }

    $lead = crm_find_lead($id);

    if ($lead === null) {
        return false;
    }

    $existingNotes = trim((string) ($lead['notes'] ?? ''));
    $notes = $existingNotes === '' ? $note : $existingNotes . "\n\n" . $note;
    $firstContactSql = '';
    $firstContactParams = [];

    if (
        (string) ($lead['first_contact_at'] ?? '') === ''
        && (
            str_starts_with($note, 'Mensagem enviada')
            || str_starts_with($note, 'Agendamento criado')
        )
    ) {
        $firstContactSql = ', first_contact_at = :first_contact_at';
        $firstContactParams['first_contact_at'] = date('Y-m-d H:i:s');
    }

    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $stmt = crm_db()->prepare(
        'UPDATE leads
        SET notes = :notes,
            last_activity_at = :last_activity_at,
            last_activity_type = :last_activity_type,
            updated_at = :updated_at' . $firstContactSql . '
        WHERE id = :id' . $accessSql
    );
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        'id' => $id,
        'notes' => $notes,
        'last_activity_at' => $now,
        'last_activity_type' => 'note',
        'updated_at' => $now,
    ] + $firstContactParams + $accessParams);

    return $stmt->rowCount() > 0;
}

function crm_update_lead(string $id, array $updates): bool
{
    $lead = crm_find_lead($id);

    if ($lead === null) {
        return false;
    }

    $status = trim((string) ($updates['status'] ?? 'novo'));

    if (!crm_kanban_status_exists($status)) {
        $status = 'novo';
    }

    $now = date('Y-m-d H:i:s');
    $fields = [
        'status = :status',
        'last_activity_at = :last_activity_at',
        'last_activity_type = :last_activity_type',
        'updated_at = :updated_at',
    ];
    $params = [
        'id' => $id,
        'status' => $status,
        'last_activity_at' => $now,
        'last_activity_type' => (string) ($lead['status'] ?? '') !== $status ? 'status_update' : 'lead_update',
        'updated_at' => $now,
    ];

    if (array_key_exists('notes', $updates)) {
        $fields[] = 'notes = :notes';
        $params['notes'] = trim((string) ($updates['notes'] ?? ''));
    }

    if (array_key_exists('name', $updates)) {
        $name = trim((string) ($updates['name'] ?? ''));

        if ($name !== '') {
            $fields[] = 'name = :name';
            $params['name'] = $name;
        }
    }

    if (array_key_exists('whatsapp', $updates)) {
        $fields[] = 'whatsapp = :whatsapp';
        $params['whatsapp'] = crm_normalize_lead_whatsapp((string) ($updates['whatsapp'] ?? ''));
    }

    if (array_key_exists('cpf', $updates)) {
        $fields[] = 'cpf = :cpf';
        $params['cpf'] = crm_normalize_cpf((string) ($updates['cpf'] ?? ''));
    }

    if (array_key_exists('tags', $updates)) {
        $fields[] = 'tags = :tags';
        $params['tags'] = crm_encode_tags((string) ($updates['tags'] ?? ''));
    }

    if (array_key_exists('estimated_value', $updates)) {
        $fields[] = 'estimated_value = :estimated_value';
        $params['estimated_value'] = crm_normalize_money($updates['estimated_value']);
    }

    if (array_key_exists('proposal_value', $updates)) {
        $fields[] = 'proposal_value = :proposal_value';
        $params['proposal_value'] = crm_normalize_money($updates['proposal_value']);
    }

    if (array_key_exists('expected_close_date', $updates)) {
        $fields[] = 'expected_close_date = :expected_close_date';
        $params['expected_close_date'] = crm_normalize_date($updates['expected_close_date']);
    }

    if (array_key_exists('lost_reason', $updates)) {
        $fields[] = 'lost_reason = :lost_reason';
        $params['lost_reason'] = trim((string) ($updates['lost_reason'] ?? '')) ?: null;
    }

    if ((string) ($lead['first_contact_at'] ?? '') === '' && ($status !== 'novo' || array_key_exists('notes', $updates))) {
        $fields[] = 'first_contact_at = :first_contact_at';
        $params['first_contact_at'] = $now;
    }

    if ($status === 'fechado' && (string) ($lead['closed_at'] ?? '') === '') {
        $fields[] = 'closed_at = :closed_at';
        $params['closed_at'] = $now;
    }

    if ($status === 'perdido' && (string) ($lead['lost_at'] ?? '') === '') {
        $fields[] = 'lost_at = :lost_at';
        $params['lost_at'] = $now;
    }

    $assignedUserChanged = false;
    $assignedUserId = null;

    if (array_key_exists('assigned_user_id', $updates) && function_exists('crm_current_user_can_manage_sales') && crm_current_user_can_manage_sales()) {
        $assignedUserId = crm_validate_assignable_user_id((int) ($updates['assigned_user_id'] ?? 0));
        $currentAssignedUserId = isset($lead['assigned_user_id']) && $lead['assigned_user_id'] !== null ? (int) $lead['assigned_user_id'] : null;
        $assignedUserChanged = $currentAssignedUserId !== $assignedUserId;
        $fields[] = 'assigned_user_id = :assigned_user_id';
        $fields[] = 'assigned_at = :assigned_at';
        $params['assigned_user_id'] = $assignedUserId;
        $params['assigned_at'] = $assignedUserId !== null ? $now : null;
        $params['last_activity_type'] = 'manual_assignment';
    }

    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $stmt = crm_db()->prepare('UPDATE leads SET ' . implode(', ', $fields) . ' WHERE id = :id' . $accessSql);
    $stmt->execute($params + $accessParams);
    $changed = $stmt->rowCount() > 0;

    if ($assignedUserChanged && $changed) {
        $currentUser = crm_current_storage_user();
        crm_record_lead_assignment(
            $id,
            isset($lead['assigned_user_id']) && $lead['assigned_user_id'] !== null ? (int) $lead['assigned_user_id'] : null,
            $assignedUserId,
            'manual_assignment',
            'Atribuição alterada manualmente no CRM.',
            is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) ?: null : null
        );
        crm_touch_user_assigned_at($assignedUserId);
    }

    return $changed;
}

function crm_move_lead(string $id, string $status, array $orders): bool
{
    if (!crm_kanban_status_exists($status)) {
        return false;
    }

    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $pdo = crm_db();
    $pdo->beginTransaction();

    try {
        $find = $pdo->prepare('SELECT id, status, first_contact_at, closed_at, lost_at FROM leads WHERE id = :id' . $accessSql . ' FOR UPDATE');
        $find->execute(['id' => $id] + $accessParams);
        $lead = $find->fetch();

        if (!is_array($lead)) {
            $pdo->rollBack();
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $dateFields = '';
        $dateParams = [];

        if ((string) ($lead['first_contact_at'] ?? '') === '' && $status !== 'novo') {
            $dateFields .= ', first_contact_at = :first_contact_at';
            $dateParams['first_contact_at'] = $now;
        }

        if ($status === 'fechado' && (string) ($lead['closed_at'] ?? '') === '') {
            $dateFields .= ', closed_at = :closed_at';
            $dateParams['closed_at'] = $now;
        }

        if ($status === 'perdido' && (string) ($lead['lost_at'] ?? '') === '') {
            $dateFields .= ', lost_at = :lost_at';
            $dateParams['lost_at'] = $now;
        }

        $updateLead = $pdo->prepare(
            'UPDATE leads
            SET status = :status,
                last_activity_at = :last_activity_at,
                last_activity_type = :last_activity_type,
                updated_at = :updated_at' . $dateFields . '
            WHERE id = :id' . $accessSql
        );
        $updateLead->execute([
            'id' => $id,
            'status' => $status,
            'last_activity_at' => $now,
            'last_activity_type' => 'kanban_move',
            'updated_at' => $now,
        ] + $dateParams + $accessParams);

        $updatePosition = $pdo->prepare(
            'UPDATE leads
            SET kanban_position = :position
            WHERE id = :id AND status = :status' . $accessSql
        );

        foreach ($orders as $orderStatus => $leadIds) {
            $orderStatus = trim((string) $orderStatus);

            if (!is_array($leadIds) || !crm_kanban_status_exists($orderStatus)) {
                continue;
            }

            $position = 10;

            foreach (array_unique(array_map('strval', $leadIds)) as $orderedLeadId) {
                $updatePosition->execute([
                    'id' => trim($orderedLeadId),
                    'status' => $orderStatus,
                    'position' => $position,
                ] + $accessParams);
                $position += 10;
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function crm_read_followup_flows(bool $onlyActive = false): array
{
    $sql = 'SELECT * FROM followup_flows';

    if ($onlyActive) {
        $sql .= ' WHERE active = 1';
    }

    $sql .= ' ORDER BY created_at DESC';

    return crm_db()->query($sql)->fetchAll();
}

function crm_find_followup_flow(int $id): ?array
{
    $stmt = crm_db()->prepare('SELECT * FROM followup_flows WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $flow = $stmt->fetch();

    return is_array($flow) ? $flow : null;
}

function crm_read_followup_steps(int $flowId): array
{
    $stmt = crm_db()->prepare('SELECT * FROM followup_steps WHERE flow_id = :flow_id ORDER BY step_order ASC');
    $stmt->execute(['flow_id' => $flowId]);

    return $stmt->fetchAll();
}

function crm_create_followup_flow(string $name, string $description, array $steps): int
{
    $db = crm_db();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare(
            'INSERT INTO followup_flows (name, description, active, created_at, updated_at)
            VALUES (:name, :description, 1, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $flowId = (int) $db->lastInsertId();
        crm_replace_followup_steps($flowId, $steps);
        $db->commit();

        return $flowId;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function crm_update_followup_flow(int $id, string $name, string $description, array $steps): bool
{
    $db = crm_db();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare(
            'UPDATE followup_flows
            SET name = :name, description = :description, updated_at = :updated_at
            WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        crm_replace_followup_steps($id, $steps);
        crm_reschedule_followup_flow($id);
        $db->commit();

        return true;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function crm_replace_followup_steps(int $flowId, array $steps): void
{
    $db = crm_db();
    $delete = $db->prepare('DELETE FROM followup_steps WHERE flow_id = :flow_id');
    $delete->execute(['flow_id' => $flowId]);

    $insert = $db->prepare(
        'INSERT INTO followup_steps (flow_id, step_order, delay_minutes, message, created_at)
        VALUES (:flow_id, :step_order, :delay_minutes, :message, :created_at)'
    );

    $order = 1;

    foreach ($steps as $step) {
        $message = trim((string) ($step['message'] ?? ''));

        if ($message === '') {
            continue;
        }

        $insert->execute([
            'flow_id' => $flowId,
            'step_order' => $order,
            'delay_minutes' => max(0, (int) ($step['delay_minutes'] ?? 0)),
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $order++;
    }
}

function crm_reschedule_followup_flow(int $flowId): void
{
    $db = crm_db();
    $leads = $db->prepare(
        'SELECT id FROM leads WHERE status = "followup" AND followup_flow_id = :flow_id'
    );
    $leads->execute(['flow_id' => $flowId]);
    $activeLeads = $leads->fetchAll();

    if (count($activeLeads) === 0) {
        return;
    }

    $steps = crm_read_followup_steps($flowId);
    $insert = $db->prepare(
        'INSERT INTO followup_queue (lead_id, flow_id, step_id, step_order, scheduled_at, status, created_at)
        VALUES (:lead_id, :flow_id, :step_id, :step_order, :scheduled_at, "pendente", :created_at)'
    );
    $alreadyHandled = $db->prepare(
        'SELECT COUNT(*) FROM followup_queue
        WHERE lead_id = :lead_id
          AND flow_id = :flow_id
          AND step_order = :step_order
          AND status IN ("pendente", "enviado")'
    );
    $alreadySent = $db->prepare(
        'SELECT COUNT(*) FROM followup_step_history
        WHERE lead_id = :lead_id
          AND flow_id = :flow_id
          AND step_order = :step_order
          AND status = "enviado"'
    );

    foreach ($activeLeads as $lead) {
        $elapsedMinutes = 0;

        foreach ($steps as $step) {
            $elapsedMinutes += max(0, (int) $step['delay_minutes']);

            $alreadySent->execute([
                'lead_id' => $lead['id'],
                'flow_id' => $flowId,
                'step_order' => $step['step_order'],
            ]);

            if ((int) $alreadySent->fetchColumn() > 0) {
                continue;
            }

            $alreadyHandled->execute([
                'lead_id' => $lead['id'],
                'flow_id' => $flowId,
                'step_order' => $step['step_order'],
            ]);

            if ((int) $alreadyHandled->fetchColumn() > 0) {
                continue;
            }

            $insert->execute([
                'lead_id' => $lead['id'],
                'flow_id' => $flowId,
                'step_id' => $step['id'],
                'step_order' => $step['step_order'],
                'scheduled_at' => date('Y-m-d H:i:s', time() + ($elapsedMinutes * 60)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}

function crm_delete_followup_flow(int $id): bool
{
    $stmt = crm_db()->prepare('DELETE FROM followup_flows WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return $stmt->rowCount() > 0;
}

function crm_assign_followup_flow(string $leadId, int $flowId): bool
{
    $flow = crm_find_followup_flow($flowId);

    if ($flow === null) {
        return false;
    }

    $lead = crm_find_lead($leadId);

    if ($lead === null) {
        return false;
    }

    $db = crm_db();
    $db->beginTransaction();

    try {
        [$accessSql, $accessParams] = crm_lead_access_sql('leads');
        $firstContactSql = (string) ($lead['first_contact_at'] ?? '') === '' ? ', first_contact_at = :first_contact_at' : '';
        $update = $db->prepare(
            'UPDATE leads
            SET status = "followup",
                followup_flow_id = :flow_id,
                followup_started_at = :started_at,
                last_activity_at = :last_activity_at,
                last_activity_type = :last_activity_type,
                updated_at = :updated_at' . $firstContactSql . '
            WHERE id = :id' . $accessSql
        );
        $now = date('Y-m-d H:i:s');
        $updateParams = [
            'id' => $leadId,
            'flow_id' => $flowId,
            'started_at' => $now,
            'last_activity_at' => $now,
            'last_activity_type' => 'followup_assignment',
            'updated_at' => $now,
        ];

        if ($firstContactSql !== '') {
            $updateParams['first_contact_at'] = $now;
        }

        $update->execute($updateParams + $accessParams);

        $clear = $db->prepare('DELETE FROM followup_queue WHERE lead_id = :lead_id AND status = "pendente"');
        $clear->execute(['lead_id' => $leadId]);

        $steps = crm_read_followup_steps($flowId);
        $insert = $db->prepare(
            'INSERT INTO followup_queue (lead_id, flow_id, step_id, step_order, scheduled_at, status, created_at)
            VALUES (:lead_id, :flow_id, :step_id, :step_order, :scheduled_at, "pendente", :created_at)'
        );

        $elapsedMinutes = 0;

        foreach ($steps as $step) {
            $elapsedMinutes += max(0, (int) $step['delay_minutes']);
            $scheduledAt = date('Y-m-d H:i:s', time() + ($elapsedMinutes * 60));

            $insert->execute([
                'lead_id' => $leadId,
                'flow_id' => $flowId,
                'step_id' => $step['id'],
                'step_order' => $step['step_order'],
                'scheduled_at' => $scheduledAt,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $db->commit();
        return $update->rowCount() > 0;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function crm_read_due_followups(int $limit = 20): array
{
    $stmt = crm_db()->prepare(
        'SELECT q.*, s.message, l.name, l.whatsapp, l.company, l.segment
        FROM followup_queue q
        JOIN followup_steps s ON s.id = q.step_id
        JOIN leads l ON l.id = q.lead_id
        WHERE q.status = "pendente"
          AND q.scheduled_at <= :now_due
          AND NOT EXISTS (
            SELECT 1
            FROM followup_queue q2
            WHERE q2.lead_id = q.lead_id
              AND q2.flow_id = q.flow_id
              AND q2.status = "pendente"
              AND q2.scheduled_at <= :now_previous
              AND q2.step_order < q.step_order
          )
          AND NOT EXISTS (
            SELECT 1
            FROM followup_step_history h
            WHERE h.lead_id = q.lead_id
              AND h.flow_id = q.flow_id
              AND h.status = "enviado"
              AND h.sent_at >= :recent_sent_at
          )
        ORDER BY q.scheduled_at ASC
        LIMIT ' . max(1, $limit)
    );
    $now = time();
    $stmt->execute([
        'now_due' => date('Y-m-d H:i:s', $now),
        'now_previous' => date('Y-m-d H:i:s', $now),
        'recent_sent_at' => date('Y-m-d H:i:s', $now - 55),
    ]);

    return $stmt->fetchAll();
}

function crm_update_followup_queue_item(int $id, string $status, ?string $error = null): bool
{
    $itemStmt = crm_db()->prepare('SELECT * FROM followup_queue WHERE id = :id LIMIT 1');
    $itemStmt->execute(['id' => $id]);
    $item = $itemStmt->fetch();

    $stmt = crm_db()->prepare(
        'UPDATE followup_queue
        SET status = :status,
            sent_at = :sent_at,
            error = :error
        WHERE id = :id'
    );
    $stmt->execute([
        'id' => $id,
        'status' => $status,
        'sent_at' => $status === 'enviado' ? date('Y-m-d H:i:s') : null,
        'error' => $error,
    ]);

    if (is_array($item) && in_array($status, ['enviado', 'falhou'], true)) {
        crm_record_followup_step_history($item, $status, $error);
    }

    return $stmt->rowCount() > 0;
}

function crm_record_followup_step_history(array $queueItem, string $status, ?string $error = null): void
{
    $stmt = crm_db()->prepare(
        'INSERT INTO followup_step_history
        (lead_id, flow_id, step_order, status, sent_at, error, created_at, updated_at)
        VALUES
        (:lead_id, :flow_id, :step_order, :status, :sent_at, :error, :created_at, :updated_at)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            sent_at = VALUES(sent_at),
            error = VALUES(error),
            updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        'lead_id' => $queueItem['lead_id'],
        'flow_id' => $queueItem['flow_id'],
        'step_order' => $queueItem['step_order'],
        'status' => $status,
        'sent_at' => $status === 'enviado' ? date('Y-m-d H:i:s') : null,
        'error' => $error,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function crm_delete_lead(string $id): bool
{
    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $stmt = crm_db()->prepare('DELETE FROM leads WHERE id = :id' . $accessSql);
    $stmt->execute(['id' => $id] + $accessParams);

    return $stmt->rowCount() > 0;
}

function crm_find_lead(string $id, bool $applyAccess = true): ?array
{
    [$accessSql, $accessParams] = crm_lead_access_sql('leads', $applyAccess);
    $stmt = crm_db()->prepare(
        'SELECT leads.*, crm_users.name AS assigned_user_name, crm_users.username AS assigned_username
        FROM leads
        LEFT JOIN crm_users ON crm_users.id = leads.assigned_user_id
        WHERE leads.id = :id' . $accessSql . '
        LIMIT 1'
    );
    $stmt->execute(['id' => $id] + $accessParams);
    $lead = $stmt->fetch();

    return is_array($lead) ? $lead : null;
}

function crm_update_whatsapp_status(string $id, string $status, ?string $error = null): bool
{
    $stmt = crm_db()->prepare(
        'UPDATE leads
        SET whatsapp_status = :status,
            whatsapp_sent_at = :sent_at,
            whatsapp_error = :error,
            updated_at = :updated_at
        WHERE id = :id'
    );
    $stmt->execute([
        'id' => $id,
        'status' => $status,
        'sent_at' => in_array($status, ['enviado', 'notifica_enviada'], true) ? date('Y-m-d H:i:s') : null,
        'error' => $error,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return $stmt->rowCount() > 0;
}

function crm_read_sla_overdue_leads(int $limit = 50): array
{
    $settings = crm_sales_distribution_settings();

    if (!$settings['sla_enabled']) {
        return [];
    }

    $statuses = array_values(array_filter($settings['sla_statuses'], static fn(string $status): bool => crm_kanban_status_exists($status)));

    if (count($statuses) === 0) {
        return [];
    }

    $placeholders = [];
    $params = [
        'cutoff' => date('Y-m-d H:i:s', time() - ((int) $settings['sla_inactivity_minutes'] * 60)),
    ];

    foreach ($statuses as $index => $status) {
        $key = 'status_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $status;
    }

    $stmt = crm_db()->prepare(
        'SELECT leads.*, crm_users.name AS assigned_user_name, crm_users.username AS assigned_username
        FROM leads
        LEFT JOIN crm_users ON crm_users.id = leads.assigned_user_id
        WHERE leads.assigned_user_id IS NOT NULL
          AND COALESCE(leads.last_activity_at, leads.updated_at, leads.created_at) <= :cutoff
          AND leads.status IN (' . implode(', ', $placeholders) . ')
        ORDER BY COALESCE(leads.last_activity_at, leads.updated_at, leads.created_at) ASC
        LIMIT ' . max(1, $limit)
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function crm_process_sla_reassignments(int $limit = 50): array
{
    $settings = crm_sales_distribution_settings();
    $processed = 0;
    $reassigned = 0;
    $skipped = 0;
    $details = [];

    if (!$settings['sla_enabled']) {
        return [
            'processed' => 0,
            'reassigned' => 0,
            'skipped' => 0,
            'message' => 'SLA de inatividade está desativado.',
            'details' => [],
        ];
    }

    foreach (crm_read_sla_overdue_leads($limit) as $lead) {
        $processed++;
        $leadId = (string) ($lead['id'] ?? '');
        $currentUserId = isset($lead['assigned_user_id']) ? (int) $lead['assigned_user_id'] : null;

        $nextUser = $settings['sla_action'] === 'manager_review'
            ? crm_pick_manager_user($currentUserId)
            : crm_pick_rotation_user($currentUserId);

        if (!is_array($nextUser) || (int) ($nextUser['id'] ?? 0) <= 0 || (int) $nextUser['id'] === (int) $currentUserId) {
            $skipped++;
            $details[] = [
                'lead_id' => $leadId,
                'lead' => (string) ($lead['name'] ?? ''),
                'status' => 'sem_vendedor',
                'reason' => $settings['sla_action'] === 'manager_review'
                    ? 'Nenhum gestor ativo disponível.'
                    : 'Nenhum outro vendedor ativo na roleta.',
            ];
            continue;
        }

        $reason = 'Redistribuição automática por inatividade acima de ' . (int) $settings['sla_inactivity_minutes'] . ' minutos.';
        $action = $settings['sla_action'] === 'manager_review' ? 'sla_manager_review' : 'sla_reassignment';

        if (crm_assign_lead_to_user($leadId, (int) $nextUser['id'], $action, $reason, null, false)) {
            crm_append_lead_note(
                $leadId,
                'Lead redistribuído automaticamente em ' . date('d/m/Y H:i') . ".\nMotivo: " . $reason . "\nNovo vendedor: " . crm_user_label($nextUser)
            );
            $reassigned++;
            $details[] = [
                'lead_id' => $leadId,
                'lead' => (string) ($lead['name'] ?? ''),
                'status' => 'redistribuido',
                'to_user' => crm_user_label($nextUser),
            ];
            continue;
        }

        $skipped++;
        $details[] = [
            'lead_id' => $leadId,
            'lead' => (string) ($lead['name'] ?? ''),
            'status' => 'falhou',
            'reason' => 'Não foi possível alterar o responsável.',
        ];
    }

    return [
        'processed' => $processed,
        'reassigned' => $reassigned,
        'skipped' => $skipped,
        'details' => $details,
    ];
}
