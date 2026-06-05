<?php
/**
 * Alfred — Web Push API
 *
 * Endpoints (all via POST unless noted):
 *   GET/POST  action=vapid_public  — Return VAPID public key (no auth required)
 *   POST      action=subscribe     — Register a push subscription for a phone device
 *   POST      action=pending       — Fetch pending notifications (service worker, auth via token)
 *   POST      action=read          — Mark pending notifications as read (auth via token)
 *
 * Auth for subscribe: Jeedom session OR user_hash in request body.
 * Auth for pending/read: fetch_token returned at subscribe time, stored by the service worker.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
require_once __DIR__ . '/../core/class/alfred.class.php';
require_once __DIR__ . '/../core/class/alfredPush.class.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse request body (JSON preferred, fall back to form params)
$rawBody  = (string) file_get_contents('php://input');
$jsonBody = ($rawBody !== '') ? (json_decode($rawBody, true) ?: []) : [];

function param(string $key, string $default = ''): string
{
    global $jsonBody;
    if (isset($jsonBody[$key])) return (string) $jsonBody[$key];
    if (isset($_POST[$key]))    return (string) $_POST[$key];
    if (isset($_GET[$key]))     return (string) $_GET[$key];
    return $default;
}

$action = param('action');

// ── VAPID public key ─────────────────────────────────────────────────────────
if ($action === 'vapid_public') {
    echo json_encode(['public_key' => alfredPush::getPublicKey()]);
    exit;
}

// ── Pending notifications (service worker, auth via fetch_token) ──────────────
if ($action === 'pending') {
    $token = param('token');
    if ($token === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing token']);
        exit;
    }
    require_once __DIR__ . '/../core/class/alfredMigration.class.php';
    alfredMigration::runPending();
    echo json_encode(alfredPush::getPendingByToken($token));
    exit;
}

// ── Mark as read (service worker, auth via fetch_token) ───────────────────────
if ($action === 'read') {
    $token = param('token');
    if ($token !== '') {
        alfredPush::markAllReadByToken($token);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Subscribe (requires authenticated Jeedom user) ────────────────────────────
if ($action === 'subscribe') {
    // Auth: active PHP session, or user_hash in request body/header
    $userLogin = null;
    if (isConnect()) {
        $u = $_SESSION['user'] ?? null;
        $userLogin = $u ? $u->getLogin() : null;
    }
    if (!$userLogin) {
        $apiKey = trim(
            $_SERVER['HTTP_X_API_KEY'] ?? param('user_hash')
        );
        if ($apiKey !== '' && jeedom::apiAccess($apiKey, 'core')) {
            global $_USER_GLOBAL;
            $userLogin = is_object($_USER_GLOBAL) ? $_USER_GLOBAL->getLogin() : 'api';
        }
    }
    if (!$userLogin) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $endpoint = param('endpoint');
    $p256dh   = param('p256dh');
    $authKey  = param('auth');

    if ($endpoint === '' || $p256dh === '' || $authKey === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing endpoint, p256dh, or auth']);
        exit;
    }

    require_once __DIR__ . '/../core/class/alfredMigration.class.php';
    require_once __DIR__ . '/../core/class/alfredCmd.class.php';
    alfredMigration::runPending();

    try {
        // Find or create Phone equipment for this subscription endpoint
        $existingSub = alfredPush::getSubscriptionByEndpoint($endpoint);
        if ($existingSub) {
            $eqLogicId = (int) $existingSub['eqLogic_id'];
        } else {
            $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $name = _deviceNameFromUA($ua, $userLogin);

            $phone = new alfred();
            $phone->setEqType_name('alfred');
            $phone->setName($name);
            $phone->setConfiguration('alfred_type', 'phone');
            $phone->setConfiguration('owner_login', $userLogin);
            $phone->setIsEnable(1);
            $phone->setIsVisible(1);
            $phone->save();
            $eqLogicId = (int) $phone->getId();
            if ($eqLogicId <= 0) {
                throw new Exception('Phone equipment save returned invalid ID');
            }
        }

        $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $token = alfredPush::saveSubscription($eqLogicId, $endpoint, $p256dh, $authKey, $ua);

        echo json_encode([
            'ok'          => true,
            'eqLogic_id'  => $eqLogicId,
            'fetch_token' => $token,
        ]);
    } catch (Exception $e) {
        log::add('alfred', 'error', 'push.php subscribe failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Unknown action: ' . $action]);

// ─────────────────────────────────────────────────────────────────────────────

function _deviceNameFromUA(string $ua, string $login): string
{
    $os = 'Appareil';
    if (stripos($ua, 'iPhone') !== false)  $os = 'iPhone';
    elseif (stripos($ua, 'iPad') !== false) $os = 'iPad';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS X') !== false) $os = 'Mac';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';
    return $os . ' ' . ucfirst($login);
}
