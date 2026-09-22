<?php
class Http {
  public static function body() {
    $raw = file_get_contents('php://input');
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
    // پشتیبانی از توکن در query (برای دانلود فایل‌ها مثل بکاپ/خروجی اکسل که با لینک مستقیم باز می‌شوند)
    if (!empty($_GET['token'])) return $_GET['token'];
    return null;
  }
}
