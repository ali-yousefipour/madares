<?php
class Http {
  public static function body() {
    $max = 12 * 1024 * 1024;
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $ctype = strtolower($_SERVER['CONTENT_TYPE'] ?? '');

    // PHP درخواست‌های multipart/form-data و application/x-www-form-urlencoded را
    // در $_POST و $_FILES تجزیه می‌کند؛ خواندن php://input در این حالت باعث خالی
    // ماندن بدنه و گم شدن فیلدهایی مانند action می‌شود.
    if (strpos($ctype, 'multipart/form-data') !== false || strpos($ctype, 'application/x-www-form-urlencoded') !== false) {
      if ($len > $max) self::error('حجم درخواست بیش از حد مجاز است', 413);
      return is_array($_POST) ? $_POST : [];
    }

    if ($len > 1024 * 1024) self::error('حجم درخواست بیش از حد مجاز است', 413);
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    if ($ctype && strpos($ctype, 'application/json') === false && strpos($ctype, 'text/plain') === false) {
      self::error('Unsupported Media Type', 415);
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
  }
  public static function json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
  }
  public static function error($msg, $code = 400) { self::json(['error' => $msg], $code); }
  public static function bearer() {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!$h && function_exists('apache_request_headers')) {
      $hdrs = apache_request_headers();
      $h = $hdrs['Authorization'] ?? ($hdrs['authorization'] ?? '');
    }
    if (stripos($h, 'Bearer ') === 0) return substr($h, 7);
    // فقط برای خروجی‌های دانلودی، توکن query مجاز است؛ در سایر مسیرها ممنوع است تا توکن در URL نشت نکند.
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (!empty($_GET['token']) && preg_match('#/api/admin/.*/export|/api/admin/reports/full-export|/api/admin/companies/import-template|/api/admin/schools/import-template#', $uri)) return $_GET['token'];
    return null;
  }
}
