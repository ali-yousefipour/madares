<?php
/**
 * مدیریت ذخیرهٔ فیزیکی تصاویر روی هاست (به‌جای base64 در دیتابیس).
 * - دریافت base64 یا فایل آپلودی، فشرده‌سازی، ذخیره در public/uploads/{type}/
 * - بازگرداندن مسیر نسبی برای ذخیره در ستون *_path
 * - سرو امن تصویر از طریق مسیر نسبی
 *
 * اگر افزونهٔ GD نباشد، تصویر بدون فشرده‌سازی ذخیره می‌شود (بدون خطا).
 */
class Media {
  // ریشهٔ ذخیرهٔ فایل‌ها: کنار همین پوشه، داخل public/uploads
  static function baseDir() {
    $d = __DIR__ . '/../public/uploads';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
  }

  /**
   * ذخیرهٔ یک تصویر base64 (data URI یا رشتهٔ خام base64) به‌صورت فایل فیزیکی.
   * $type: زیرپوشه (مثل reports, notices, checklists, visits, selfies, covert, users)
   * $maxW: حداکثر عرض برای فشرده‌سازی (px). 0 = بدون تغییر اندازه
   * $quality: کیفیت JPEG (0..100)
   * خروجی: مسیر نسبی مثل "uploads/reports/2026/06/abc123.jpg" یا null در صورت خطا
   */
  static function saveBase64($b64, $type, $maxW = 1280, $quality = 70) {
    if (!$b64 || !is_string($b64)) return null;
    // جدا کردن هدر data URI
    $data = $b64;
    if (strpos($b64, 'base64,') !== false) {
      $data = substr($b64, strpos($b64, 'base64,') + 7);
    }
    $raw = base64_decode($data, true);
    if ($raw === false || strlen($raw) < 32) return null;

    // مسیر بر اساس سال/ماه برای جلوگیری از انباشت در یک پوشه
    $sub = $type . '/' . date('Y') . '/' . date('m');
    $dir = self::baseDir() . '/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $name = bin2hex(random_bytes(12));
    $relBase = 'uploads/' . $sub . '/' . $name;

    // تلاش برای فشرده‌سازی با GD
    if (function_exists('imagecreatefromstring')) {
      $img = @imagecreatefromstring($raw);
      if ($img !== false) {
        $w = imagesx($img); $h = imagesy($img);
        if ($maxW > 0 && $w > $maxW) {
          $nw = $maxW; $nh = (int)round($h * ($maxW / $w));
          $resized = imagecreatetruecolor($nw, $nh);
          // پس‌زمینهٔ سفید برای تصاویر شفاف
          $white = imagecolorallocate($resized, 255, 255, 255);
          imagefilledrectangle($resized, 0, 0, $nw, $nh, $white);
          imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
          imagedestroy($img); $img = $resized;
        }
        $full = self::baseDir() . '/' . $sub . '/' . $name . '.jpg';
        imagejpeg($img, $full, max(10, min(95, (int)$quality)));
        imagedestroy($img);
        if (is_file($full)) return $relBase . '.jpg';
      }
    }

    // اگر GD نبود یا خطا داد: ذخیرهٔ خام با تشخیص پسوند
    $ext = self::extFromBytes($raw);
    $full = self::baseDir() . '/' . $sub . '/' . $name . '.' . $ext;
    if (@file_put_contents($full, $raw) !== false) return $relBase . '.' . $ext;
    return null;
  }

  // تشخیص پسوند از بایت‌های ابتدایی
  static function extFromBytes($raw) {
    $sig = substr($raw, 0, 4);
    if (strpos($raw, "\xFF\xD8\xFF") === 0) return 'jpg';
    if (substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n") return 'png';
    if (substr($raw, 0, 4) === 'RIFF' && substr($raw, 8, 4) === 'WEBP') return 'webp';
    if (substr($raw, 0, 3) === 'GIF') return 'gif';
    return 'jpg';
  }

  // مسیر کامل فایل از مسیر نسبی
  static function fullPath($rel) {
    if (!$rel) return null;
    $rel = ltrim($rel, '/');
    // فقط داخل uploads مجاز است (جلوگیری از path traversal)
    if (strpos($rel, '..') !== false) return null;
    if (strpos($rel, 'uploads/') !== 0) return null;
    return __DIR__ . '/../public/' . $rel;
  }

  // حذف فایل فیزیکی
  static function delete($rel) {
    $f = self::fullPath($rel);
    if ($f && is_file($f)) @unlink($f);
  }

  /**
   * ذخیرهٔ فایل آپلودشده (از $_FILES) — بدون تبدیل base64.
   * برای آپلود مستقیم از موبایل (multipart/form-data).
   * @param array $file  یک عنصر از $_FILES
   * @param string $type  نوع (notices, reports, ...)
   * @param int $maxW     حداکثر عرض (برای فشرده‌سازی با GD)
   * @param int $quality  کیفیت JPEG
   */
  static function saveUploadedFile($file, $type, $maxW = 1280, $quality = 80) {
    if (!$file || ($file['error'] ?? 1) !== 0 || empty($file['tmp_name'])) return null;
    $raw = @file_get_contents($file['tmp_name']);
    if ($raw === false || strlen($raw) < 32) return null;

    // مسیر بر اساس سال/ماه
    $sub = $type . '/' . date('Y') . '/' . date('m');
    $dir = self::baseDir() . '/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $name = bin2hex(random_bytes(12));
    $relBase = 'uploads/' . $sub . '/' . $name;

    // فشرده‌سازی با GD
    if (function_exists('imagecreatefromstring')) {
      $img = @imagecreatefromstring($raw);
      if ($img !== false) {
        $w = imagesx($img); $h = imagesy($img);
        if ($maxW > 0 && $w > $maxW) {
          $nw = $maxW; $nh = (int)round($h * ($maxW / $w));
          $resized = imagecreatetruecolor($nw, $nh);
          $white = imagecolorallocate($resized, 255, 255, 255);
          imagefilledrectangle($resized, 0, 0, $nw, $nh, $white);
          imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
          imagedestroy($img); $img = $resized;
        }
        $full = self::baseDir() . '/' . $sub . '/' . $name . '.jpg';
        imagejpeg($img, $full, max(10, min(95, (int)$quality)));
        imagedestroy($img);
        if (is_file($full)) return $relBase . '.jpg';
      }
    }

    // بدون GD: ذخیرهٔ خام
    $ext = self::extFromBytes($raw);
    $full = self::baseDir() . '/' . $sub . '/' . $name . '.' . $ext;
    if (@file_put_contents($full, $raw) !== false) return $relBase . '.' . $ext;
    return null;
  }

  /**
   * سرو امن تصویر: مسیر نسبی را گرفته، فایل را با هدر مناسب برمی‌گرداند.
   * برای استفاده در endpoint سرو تصویر.
   */
  static function serve($rel) {
    $f = self::fullPath($rel);
    if (!$f || !is_file($f)) { http_response_code(404); echo 'not found'; exit; }
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
  }
}
