<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';

function staff_panel_access_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_panel_access_locked(): void
{
    staff_panel_access_json([
        'success' => false,
        'staff_panel_available' => false,
        'code' => 'staff_panel_requires_pro',
        'feature' => 'staff_module',
        'upgrade_required' => true,
        'error' => 'Panel pracownika jest dostępny dla kont z aktywnym planem Pro. To konto działa obecnie w planie Free albo abonament Pro wygasł. Opłać abonament Pro, aby odzyskać dostęp do panelu pracownika.',
    ], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    staff_panel_access_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.',
    ], 405);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_panel_access_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.',
    ], 500);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    staff_panel_access_json([
        'success' => false,
        'error' => 'Nie udało się ustalić firmy dla tej domeny.',
    ], 400);
}

if (!tenant_has_feature((string) $tenantId, 'staff_module')) {
    staff_panel_access_locked();
}

staff_panel_access_json([
    'success' => true,
    'staff_panel_available' => true,
]);
