<?php
// ============ فرانت‌کنترلر و روتر API (PHP/MySQL) ============
$ROOT = __DIR__ . '/..';
require "$ROOT/lib/Db.php";
require "$ROOT/lib/Jwt.php";
require "$ROOT/lib/Http.php";
require "$ROOT/lib/Media.php";
require "$ROOT/lib/Xlsx.php";
require "$ROOT/lib/Sms.php";
$CONFIG = require "$ROOT/config.php";

// Security headers + strict CORS
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
  header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}
header("Content-Security-Policy: default-src 'self' https: data: blob:; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https:; connect-src 'self' https:; font-src 'self' data: https:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = array_filter(array_map('trim', explode(',', (string)($CONFIG['cors_origins'] ?? $CONFIG['public_url'] ?? ''))));
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Access-Control-Allow-Credentials: true');
}
header('Vary: Origin');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Max-Age: 600');
if (strpos($path ?? '', '/api') === 0) header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/'); if ($path === '') $path = '/';

// ---- سلامت عمومی ----
if ($path === '/health' || $path === '/api/health') {
  $db_ok = false;
  try { Db::pdo()->query('SELECT 1'); $db_ok = true; } catch (Throwable $e) {}
  Http::json(['ok' => true, 'installed' => is_file("$ROOT/.installed"), 'db' => $db_ok]);
}

// ---- سرو پنل وب برای مسیرهای غیر-API ----
if (strpos($path, '/api') !== 0) {
  if ($path === '/' || $path === '/index.php' || $path === '/panel.html') { readfile(__DIR__ . '/panel.html'); exit; }
  http_response_code(404); echo 'Not Found'; exit;
}

// ---- روتر ----
$routes = [];
// scope: 'admin' | 'company' | 'any'  — تعیین می‌کند توکن چه نوع کاربری مجاز است
function route($m, $p, $fn, $public = false, $scope = 'any') {
  global $routes; $routes[] = compact('m', 'p', 'fn', 'public', 'scope');
}

require "$ROOT/lib/routes.php";

$body = Http::body();

// از میان همهٔ مسیرهایی که با درخواست مطابقت دارند، مسیر «دقیق‌تر» (با کمترین پارامتر {..})
// انتخاب می‌شود؛ این کار مانع می‌شود مسیرهای ثابت مثل /schools/map یا /schools/export
// به‌اشتباه توسط مسیر پارامتری /schools/{id} بلعیده شوند (مستقل از ترتیب ثبت آن‌ها در routes.php).
$candidates = [];
foreach ($routes as $r) {
  if ($r['m'] !== $method) continue;
  $pattern = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $r['p']) . '$#u';
  if (!preg_match($pattern, $path, $mm)) continue;
  $params = array_filter($mm, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
  $specificity = substr_count($r['p'], '{');
  $candidates[] = ['route' => $r, 'params' => $params, 'specificity' => $specificity];
}
usort($candidates, fn($a, $b) => $a['specificity'] <=> $b['specificity']);

foreach ($candidates as $c) {
  $r = $c['route']; $params = $c['params'];
  $user = null;
  if (!$r['public']) {
    $token = Http::bearer();
    $payload = $token ? Jwt::verify($token, $CONFIG['jwt_secret']) : null;
    if (!$payload) Http::error('احراز هویت نامعتبر است. دوباره وارد شوید.', 401);
    if (!function_exists('_auth_session_touch') || !_auth_session_touch($payload)) Http::error('نشست شما به دلیل عدم فعالیت منقضی شده است. دوباره وارد شوید.', 401);
    if ($r['scope'] !== 'any' && ($payload['scope'] ?? null) !== $r['scope']) Http::error('دسترسی مجاز نیست.', 403);
    // محدودسازی مرکزی نقش‌های رسیدگی به شکایات؛ حتی با فراخوانی مستقیم API
    if (($payload['scope'] ?? null) === 'admin' && in_array(($payload['role'] ?? ''), ['complaint_agent','complaint_manager'], true)) {
      $allowedComplaintPaths = [
        '/api/admin/me',
        '/api/app-config',
        '/api/session/ping',
        '/api/session/logout',
      ];
      $isComplaintApi = strpos($path, '/api/admin/complaints') === 0;
      if (!$isComplaintApi && !in_array($path, $allowedComplaintPaths, true)) {
        Http::error('این حساب فقط به ماژول رسیدگی به شکایات دسترسی دارد.', 403);
      }
    }
    $user = $payload;
  }
  try {
    $result = $r['fn']($params, $body, $user);
    Http::json($result === null ? ['ok' => true] : $result);
  } catch (Throwable $e) {
    error_log('API error [' . $path . ']: ' . $e->getMessage());
    Http::error(!empty($CONFIG['debug']) ? ('خطای داخلی سرور: ' . $e->getMessage()) : 'خطای داخلی سرور', 500);
  }
  exit;
}
Http::error('مسیر یافت نشد', 404);
