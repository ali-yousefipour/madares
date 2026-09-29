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

// CORS
$allowed = array_filter([$CONFIG['public_url'] ?: '*']);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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
    if ($r['scope'] !== 'any' && ($payload['scope'] ?? null) !== $r['scope']) Http::error('دسترسی مجاز نیست.', 403);
    $user = $payload;
  }
  try {
    $result = $r['fn']($params, $body, $user);
    Http::json($result === null ? ['ok' => true] : $result);
  } catch (Throwable $e) {
    error_log('API error [' . $path . ']: ' . $e->getMessage());
    Http::error('خطای داخلی سرور: ' . $e->getMessage(), 500);
  }
  exit;
}
Http::error('مسیر یافت نشد', 404);
