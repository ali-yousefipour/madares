<?php
// بارگذاری تنظیمات از فایل .env (در صورت وجود) یا متغیرهای محیطی
if (!function_exists('load_env')) {
  function load_env($path) {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      if ($line === '' || $line[0] === '#') continue;
      $p = strpos($line, '='); if ($p === false) continue;
      $k = trim(substr($line, 0, $p)); $v = trim(substr($line, $p + 1));
      if (getenv($k) === false) putenv("$k=$v");
      $_ENV[$k] = $v;
    }
  }
}

date_default_timezone_set('Asia/Tehran');
load_env(__DIR__ . '/.env');

return [
  'db_host' => getenv('DB_HOST') ?: 'localhost',
  'db_name' => getenv('DB_NAME') ?: 'madares_db',
  'db_user' => getenv('DB_USER') ?: 'madares_user',
  'db_pass' => getenv('DB_PASS') ?: '',
  'jwt_secret' => getenv('JWT_SECRET') ?: 'change-me',
  'access_ttl' => 60 * 24 * 3600,   // نشست نمایندگان (۶۰ روز)
  'refresh_ttl' => 90 * 24 * 3600,
  'public_url' => getenv('PUBLIC_URL') ?: '',
  'cors_origins' => getenv('CORS_ORIGINS') ?: (getenv('PUBLIC_URL') ?: ''),
  'debug' => filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN),
  'max_upload_bytes' => (int)(getenv('MAX_UPLOAD_BYTES') ?: 5 * 1024 * 1024),
];
