<?php

/**
 * CRM Luxom - API Entry Point
 * All requests are routed through this file via .htaccess
 */

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Timezone Argentina
date_default_timezone_set("America/Argentina/Buenos_Aires");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Autoloader
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load environment
use App\Core\Env;
use App\Core\License;
use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\ContactsController;
use App\Controllers\DashboardController;
use App\Controllers\InstancesController;
use App\Controllers\CampaignsController;
use App\Controllers\MessagesController;
use App\Controllers\CRMController;
use App\Controllers\StatesController;
use App\Controllers\BroadcastsController;
use App\Controllers\AutoReplyController;
use App\Controllers\ShortcutsController;
use App\Controllers\UsersController;
use App\Controllers\ConfigController;
use App\Controllers\TrackingController;
use App\Controllers\BibliotecaController;
use App\Controllers\MonitorController;
use App\Controllers\LicenseController;
use App\Controllers\SinContestarController;
use App\Controllers\EstadisticasController;
use App\Controllers\WebhookController;
use App\Controllers\SyncController;
use App\Controllers\CampaignMultiController;
use App\Controllers\MasivoController;
use App\Controllers\RecordatoriosController;
use App\Controllers\ExportController;
use App\Middleware\Auth;

// Load base env (JWT_SECRET, APP_ENV, etc. — sin credenciales de DB)
Env::load(__DIR__ . '/.env');

// Validate license + inject DB credentials from ControlCRM
License::boot();

// Run pending DB migrations (silencioso si todo está al día)
try {
    $migrationsDb = new PDO(
        'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4',
        $_ENV['DB_USER'], $_ENV['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $migrationsDb->exec("SET time_zone = '-03:00'");
    (new \App\Core\Migrations($migrationsDb))->run();
} catch (\Throwable $e) {
    error_log('[Migrations boot] ' . $e->getMessage());
}

// Procesar recordatorios pendientes en cada request (con lock de 60s)
try { RecordatoriosController::processPending($migrationsDb); } catch (\Throwable $e) {}

// Procesar estados programados pendientes (con lock de 60s)
try { \App\Controllers\StatesController::processScheduled($migrationsDb); } catch (\Throwable $e) {}

// NOTA: BroadcastsController::processPending() ya NO se llama acá.
// Las difusiones programadas las dispara el CRON dedicado (cron_broadcasts.php).
// Así corren en horario programado aunque nadie esté usando la web, y pueden
// tener delays reales entre mensajes sin bloquear requests web.

// Build router
$router = new Router();

// Public routes
$router->post('/api/auth/login', [AuthController::class, 'login']);

// Protected routes
$auth = [Auth::class, 'handle'];
$admin = [Auth::class, 'requireAdmin'];

$router->get('/api/auth/me', [AuthController::class, 'me'], [$auth]);
$router->get('/api/dashboard', [DashboardController::class, 'index'], [$auth]);

$router->get('/api/contacts', [ContactsController::class, 'index'], [$auth]);
$router->get('/api/contacts/:id', [ContactsController::class, 'show'], [$auth]);
$router->post('/api/contacts', [ContactsController::class, 'store'], [$auth]);
$router->delete('/api/contacts/:id', [ContactsController::class, 'destroy'], [$auth]);

$router->get('/api/instances/user-available',   [InstancesController::class, 'userAvailable'], [$auth]);
$router->get('/api/instances',                  [InstancesController::class, 'index'],          [$auth]);
$router->post('/api/instances/create',          [InstancesController::class, 'create'],         [$auth]);
$router->post('/api/instances',                 [InstancesController::class, 'store'],          [$admin]);
$router->get('/api/instances/:id/qr',           [InstancesController::class, 'qr'],             [$auth]);
$router->get('/api/instances/:id/status',       [InstancesController::class, 'status'],         [$auth]);
$router->post('/api/instances/:id/disconnect',  [InstancesController::class, 'disconnect'],     [$auth]);
$router->post('/api/instances/:id/restart',     [InstancesController::class, 'restart'],        [$auth]);
$router->put('/api/instances/:id',              [InstancesController::class, 'update'],         [$admin]);
$router->post('/api/instances/:id/default',     [InstancesController::class, 'setDefault'],     [$admin]);
$router->get('/api/instances/:id/users',        [InstancesController::class, 'getUsers'],       [$auth]);
$router->post('/api/instances/:id/users',       [InstancesController::class, 'setUsers'],       [$admin]);
$router->delete('/api/instances/:id',           [InstancesController::class, 'destroy'],        [$admin]);
$router->post('/api/instances/:id/setup-webhook', [InstancesController::class, 'setupWebhook'], [$auth]);

$router->get('/api/campaigns', [CampaignsController::class, 'index'], [$auth]);
$router->get('/api/campaigns/recent', [CampaignsController::class, 'recent'], [$auth]);
$router->get('/api/campaigns/instances', [CampaignsController::class, 'instances'], [$auth]);
$router->get('/api/campaigns/:id', [CampaignsController::class, 'show'], [$auth]);
$router->post('/api/campaigns', [CampaignsController::class, 'store'], [$auth]);
$router->post('/api/campaigns/:id/status', [CampaignsController::class, 'updateStatus'], [$auth]);
$router->delete('/api/campaigns/:id', [CampaignsController::class, 'destroy'], [$auth]);
$router->get('/api/messages/recent', [MessagesController::class, 'recent'], [$auth]);
$router->post('/api/messages/send', [MessagesController::class, 'send'], [$auth]);

$router->get('/api/crm/leads', [CRMController::class, 'leads'], [$auth]);
$router->get('/api/crm/leads/:id/messages', [CRMController::class, 'messages'], [$auth]);
$router->get("/api/crm/media", [CRMController::class, "media"], [$auth]);
$router->get('/api/crm/leads/:id/tags', [CRMController::class, 'leadTags'], [$auth]);
$router->post('/api/crm/leads/:id/tags', [CRMController::class, 'toggleTag'], [$auth]);
$router->post('/api/crm/leads/:id/status', [CRMController::class, 'updateStatus'], [$auth]);
$router->post('/api/crm/leads/:id/transfer', [CRMController::class, 'transfer'], [$auth]);
$router->get('/api/crm/users', [CRMController::class, 'users'], [$auth]);
$router->get('/api/crm/tags', [CRMController::class, 'tags'], [$auth]);
$router->get('/api/crm/stats', [CRMController::class, 'stats'], [$auth]);
$router->post('/api/crm/leads/:id/attach', [CRMController::class, 'attachFile'], [$auth]);
$router->post('/api/crm/leads/:id/pin', [CRMController::class, 'togglePin'], [$auth]);
$router->post('/api/crm/leads/:id/unread', [CRMController::class, 'markUnread'], [$auth]);
$router->post('/api/crm/leads/:id/read', [CRMController::class, 'markRead'], [$auth]);
$router->post('/api/crm/tags', [CRMController::class, 'createTag'], [$admin]);
$router->delete('/api/crm/tags/:id', [CRMController::class, 'deleteTag'], [$admin]);
$router->get('/api/crm/shortcuts', [CRMController::class, 'shortcuts'], [$auth]);
$router->post('/api/crm/toggle-online', [CRMController::class, 'toggleOnline'], [$auth]);
$router->get('/api/crm/gestion', [CRMController::class, 'gestion'], [$auth]);

// States
$router->get('/api/states', [StatesController::class, 'index'], [$auth]);
$router->post('/api/states', [StatesController::class, 'store'], [$auth]);
$router->post('/api/states/publish', [StatesController::class, 'publish'], [$auth]);
$router->post('/api/states/:id/cancel', [StatesController::class, 'cancel'], [$auth]);
$router->delete('/api/states/:id', [StatesController::class, 'destroy'], [$auth]);

// Estados programados
$router->get('/api/states/scheduled',         [StatesController::class, 'scheduled'],      [$auth]);
$router->post('/api/states/schedule',         [StatesController::class, 'schedule'],       [$auth]);
$router->post('/api/states/send-now',          [StatesController::class, 'sendNow'],        [$auth]);
$router->post('/api/states/test-send',         [StatesController::class, 'testSend'],       [$auth]);
$router->delete('/api/states/scheduled/:id',  [StatesController::class, 'deleteScheduled'],[$auth]);

// Broadcasts (Difusiones)
$router->get('/api/broadcasts/lists',              [BroadcastsController::class, 'lists'],               [$auth]);
$router->get('/api/broadcasts/lists/:id',          [BroadcastsController::class, 'showList'],            [$auth]);
$router->post('/api/broadcasts/lists',             [BroadcastsController::class, 'createList'],          [$auth]);
$router->post('/api/broadcasts/lists/:id/destinos',[BroadcastsController::class, 'addDestinos'],         [$auth]);
$router->post('/api/broadcasts/lists/:id/send',    [BroadcastsController::class, 'sendToList'],          [$auth]);
$router->delete('/api/broadcasts/lists/:id',       [BroadcastsController::class, 'deleteList'],          [$auth]);
$router->get('/api/broadcasts/contacts',           [BroadcastsController::class, 'contactsForInstance'], [$auth]);
$router->post('/api/broadcasts/validate-numbers',  [BroadcastsController::class, 'validateNumbers'],     [$auth]);
$router->get('/api/broadcasts/scheduled',          [BroadcastsController::class, 'scheduled'],           [$auth]);
$router->delete('/api/broadcasts/scheduled/:id',   [BroadcastsController::class, 'cancelScheduled'],     [$auth]);
$router->post('/api/broadcasts/scheduled/:id/run-now', [BroadcastsController::class, 'runNow'],          [$auth]);
$router->get('/api/broadcasts/envios/:id/detalles',[BroadcastsController::class, 'envioDetalles'],       [$auth]);
$router->get('/api/broadcasts/envios/:id/progress',[BroadcastsController::class, 'envioProgress'],       [$auth]);
$router->post('/api/broadcasts/upload-attachment', [BroadcastsController::class, 'uploadAttachment'],    [$auth]);
$router->post('/api/broadcasts/refresh-group-names',[BroadcastsController::class, 'refreshGroupNames'], [$auth]);
$router->get('/api/broadcasts/self-test',          [BroadcastsController::class, 'selfTest'],           [$auth]);

// Auto-Reply
$router->get('/api/auto-reply', [AutoReplyController::class, 'index'], [$auth]);
$router->post('/api/auto-reply', [AutoReplyController::class, 'store'], [$auth]);
$router->put('/api/auto-reply/:id', [AutoReplyController::class, 'update'], [$auth]);
$router->delete('/api/auto-reply/:id', [AutoReplyController::class, 'destroy'], [$auth]);

// Shortcuts (Respuestas Rápidas)
$router->get('/api/shortcuts', [ShortcutsController::class, 'index'], [$auth]);
$router->post('/api/shortcuts', [ShortcutsController::class, 'store'], [$auth]);
$router->put('/api/shortcuts/:id', [ShortcutsController::class, 'update'], [$auth]);
$router->delete('/api/shortcuts/:id', [ShortcutsController::class, 'destroy'], [$auth]);

// Users (admin)
$router->get('/api/users', [UsersController::class, 'index'], [$admin]);
$router->post('/api/users', [UsersController::class, 'store'], [$admin]);
$router->put('/api/users/:id', [UsersController::class, 'update'], [$admin]);
$router->delete('/api/users/:id', [UsersController::class, 'destroy'], [$admin]);

// Config
$router->get('/api/config/crm', [ConfigController::class, 'crmConfig'], [$admin]);
$router->put('/api/config/crm', [ConfigController::class, 'updateCrmConfig'], [$admin]);
$router->get('/api/config/crm/users-stats', [ConfigController::class, 'crmUsersStats'], [$admin]);
$router->get('/api/config/campaigns', [ConfigController::class, 'campaignsConfig'], [$admin]);
$router->post('/api/config/campaigns', [ConfigController::class, 'updateCampaignsConfig'], [$admin]);

// Monitor (admin)
$router->get('/api/monitor', [MonitorController::class, 'dashboard'], [$admin]);

// Biblioteca
$router->get('/api/biblioteca/categories', [BibliotecaController::class, 'categories'], [$auth]);
$router->post('/api/biblioteca/categories', [BibliotecaController::class, 'createCategory'], [$admin]);
$router->put('/api/biblioteca/categories/:id', [BibliotecaController::class, 'updateCategory'], [$admin]);
$router->delete('/api/biblioteca/categories/:id', [BibliotecaController::class, 'deleteCategory'], [$admin]);
$router->get('/api/biblioteca/files', [BibliotecaController::class, 'files'], [$auth]);
$router->post('/api/biblioteca/files', [BibliotecaController::class, 'uploadFile'], [$admin]);
$router->delete('/api/biblioteca/files/:id', [BibliotecaController::class, 'deleteFile'], [$admin]);

// Tracking (Seguimiento)
$router->get('/api/tracking/leads', [TrackingController::class, 'leads'], [$auth]);
$router->post('/api/tracking/send', [TrackingController::class, 'send'], [$auth]);

// Sin Contestar (admin)
$router->get('/api/sin-contestar',          [SinContestarController::class, 'list'], [$admin]);
$router->post('/api/sin-contestar/enviar',  [SinContestarController::class, 'send'], [$admin]);

// Estadisticas (admin)
$router->get('/api/estadisticas', [EstadisticasController::class, 'index'], [$admin]);

// License info (admin only)
$router->get('/api/my-license', [LicenseController::class, 'info']);
$router->get('/api/migrations/status',[LicenseController::class, 'migrations'], [$admin]);

// Sync (admin only)
$router->post('/api/sync/contacts',  [SyncController::class, 'contacts'],  [$admin]);
$router->post('/api/sync/messages',  [SyncController::class, 'messages'],  [$admin]);
$router->post("/api/sync/groups",      [SyncController::class, "groups"],      [$admin]);
$router->post("/api/sync/group-participants", [SyncController::class, "groupParticipantsSync"], [$auth]);
$router->get("/api/groups/participants", [SyncController::class, "groupParticipants"], [$auth]);
$router->get("/api/groups/export",       [SyncController::class, "exportGroupNumbers"], [$auth]);

// Campaigns Multi (admin only)
$router->get('/api/campaigns-multi',               [CampaignMultiController::class, 'index'],    [$admin]);
$router->post('/api/campaigns-multi/create',       [CampaignMultiController::class, 'create'],   [$admin]);
$router->get('/api/campaigns-multi/:id/progress',  [CampaignMultiController::class, 'progress'], [$admin]);

// Masivo (admin only)
$router->post('/api/masivo/send',    [MasivoController::class, 'send'],    [$admin]);
$router->get('/api/masivo/status',   [MasivoController::class, 'status'],  [$admin]);

// Recordatorios
$router->get('/api/recordatorios',          [RecordatoriosController::class, 'list'],    [$auth]);
$router->post('/api/recordatorios',         [RecordatoriosController::class, 'create'],  [$auth]);
$router->delete('/api/recordatorios/{id}',  [RecordatoriosController::class, 'delete'],  [$auth]);
$router->post('/api/recordatorios/process', [RecordatoriosController::class, 'process']);

// Export — contactos, chats y grupos desde Evolution API
$router->get('/api/export/instances',     [ExportController::class, 'instances'],    [$auth]);
$router->get('/api/export/contacts',      [ExportController::class, 'contacts'],     [$auth]);
$router->get('/api/export/chats',         [ExportController::class, 'chats'],        [$auth]);
$router->get('/api/export/groups',        [ExportController::class, 'groups'],       [$auth]);
$router->get('/api/export/contacts/csv',  [ExportController::class, 'contactsCsv'], [$auth]);
$router->get('/api/export/groups/csv',    [ExportController::class, 'groupsCsv'],   [$auth]);
$router->post('/api/export/groups/rename',       [ExportController::class, 'groupRename'],       [$auth]);
$router->post('/api/export/groups/scan-all',       [ExportController::class, 'groupsScanAll'],       [$auth]);
$router->get('/api/export/groups/scan-page',       [ExportController::class, 'groupsScanPage'],      [$auth]);
$router->post('/api/export/groups/clear',          [ExportController::class, 'groupsClear'],          [$auth]);
$router->get('/api/export/search-groups-by-number',[ExportController::class, 'searchGroupsByNumber'],[$auth]);
$router->post('/api/export/groups/sync-members', [ExportController::class, 'groupsSyncMembers'], [$auth]);
$router->get('/api/export/group-history',     [ExportController::class, 'groupHistory'],   [$auth]);
$router->get('/api/export/group-history/csv', [ExportController::class, 'groupHistoryCsv'],[$auth]);

// Webhook Evolution API — público, sin auth (Evolution llama directo)
$router->post('/api/webhook', [WebhookController::class, 'handle']);

// Health check
$router->get('/api/health', fn() => ['status' => 'ok', 'time' => date('c')]);

// Dispatch
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);