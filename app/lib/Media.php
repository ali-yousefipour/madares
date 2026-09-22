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
    if (!in_array($ext, ['jpg','png','webp'], true)) return null;
    if (@file_put_contents($full, $raw) !== false) { @chmod($full, 0644); return $relBase . '.' . $ext; }
    return null;
  }

  // تشخیص پسوند از بایت‌های ابتدایی
  static function extFromBytes($raw) {
    $sig = substr($raw, 0, 4);
    if (strpos($raw, "\xFF\xD8\xFF") === 0) return 'jpg';
    if (substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n") return 'png';
    if (substr($raw, 0, 4) === 'RIFF' && substr($raw, 8, 4) === 'WEBP') return 'webp';
    if (substr($raw, 0, 3) === 'GIF') return 'gif';
    if (substr($raw, 0, 4) === "\x00\x00\x01\x00") return 'ico';
    $trim = ltrim($raw);
    if (stripos($trim, '<svg') === 0 || stripos(substr($trim,0,256), '<svg') !== false) return 'svg';
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

  // بررسی وجود فایل برای جلوگیری از تولید URL شکسته
  static function exists($rel) {
    $f = self::fullPath($rel);
    return $f && is_file($f) && is_readable($f);
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
    $cfg = require __DIR__ . '/../config.php';
    $maxBytes = (int)($cfg['max_upload_bytes'] ?? (5 * 1024 * 1024));
    if (($file['size'] ?? 0) > $maxBytes) return null;
    if (!is_uploaded_file($file['tmp_name'])) return null;
    $mime = function_exists('finfo_open') ? (function() use ($file) { $fi=finfo_open(FILEINFO_MIME_TYPE); $m=finfo_file($fi,$file['tmp_name']); finfo_close($fi); return $m; })() : '';
    if ($mime && !in_array($mime, ['image/jpeg','image/png','image/webp'], true)) return null;
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
    if (!in_array($ext, ['jpg','png','webp'], true)) return null;
    if (@file_put_contents($full, $raw) !== false) { @chmod($full, 0644); return $relBase . '.' . $ext; }
    return null;
  }

  /**
   * ذخیرهٔ آیکون نقشه با حفظ فرمت اصلی.
   * فرمت‌های مجاز: SVG, PNG, ICO, WEBP
   */
  static function saveMapIcon($file, $type = 'map-icons') {
    if (!$file || ($file['error'] ?? 1) !== 0 || empty($file['tmp_name'])) return null;
    if (!is_uploaded_file($file['tmp_name'])) return null;
    $size = (int)($file['size'] ?? 0);
    if ($size < 16 || $size > 2 * 1024 * 1024) return null;
    $raw = @file_get_contents($file['tmp_name']);
    if ($raw === false) return null;
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $detected = self::extFromBytes($raw);
    $mime = '';
    if (function_exists('finfo_open')) {
      $fi = finfo_open(FILEINFO_MIME_TYPE);
      $mime = (string)finfo_file($fi, $file['tmp_name']);
      finfo_close($fi);
    }
    $allowed = ['svg','png','ico','webp'];
    if (!in_array($ext, $allowed, true)) $ext = $detected;
    if (!in_array($ext, $allowed, true)) return null;
    $validMime = [
      'svg' => ['image/svg+xml','text/plain','application/xml','text/xml','application/octet-stream'],
      'png' => ['image/png','application/octet-stream'],
      'ico' => ['image/x-icon','image/vnd.microsoft.icon','application/octet-stream'],
      'webp'=> ['image/webp','application/octet-stream'],
    ];
    if ($mime && !in_array($mime, $validMime[$ext], true)) return null;
    if ($ext === 'svg') {
      $txt = trim($raw);
      if (stripos($txt, '<svg') === false) return null;
      // جلوگیری از اجرای اسکریپت، event handler، iframe و منابع خارجی
      if (preg_match('/<\s*(script|iframe|object|embed|foreignObject)\b/i', $txt)) return null;
      if (preg_match('/\son[a-z]+\s*=/i', $txt)) return null;
      if (preg_match('/(?:href|xlink:href)\s*=\s*["\']\s*(?:https?:|javascript:|data:text\/html)/i', $txt)) return null;
      $raw = $txt;
    } elseif ($ext === 'png' && substr($raw,0,8) !== "\x89PNG\r\n\x1a\n") return null;
    elseif ($ext === 'webp' && !(substr($raw,0,4)==='RIFF' && substr($raw,8,4)==='WEBP')) return null;
    elseif ($ext === 'ico' && substr($raw,0,4) !== "\x00\x00\x01\x00") return null;

    $sub = preg_replace('/[^a-zA-Z0-9_\-\/]/','', $type) . '/' . date('Y') . '/' . date('m');
    $dir = self::baseDir() . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return null;
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $full = $dir . '/' . $name;
    if (@file_put_contents($full, $raw) === false) return null;
    @chmod($full, 0644);
    return 'uploads/' . $sub . '/' . $name;
  }

  /**
   * سرو امن تصویر: مسیر نسبی را گرفته، فایل را با هدر مناسب برمی‌گرداند.
   * برای استفاده در endpoint سرو تصویر.
   */
  static function serve($rel) {
    $f = self::fullPath($rel);
    if (!$f || !is_file($f)) { http_response_code(404); echo 'not found'; exit; }
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','svg'=>'image/svg+xml','ico'=>'image/x-icon'][$ext] ?? 'application/octet-stream';
    // بافرهای خروجی/فشرده‌سازی احتمالی (مثلاً zlib.output_compression) را غیرفعال می‌کنیم؛ اگر خروجی
    // بعد از تعیین صریح Content-Length فشرده شود، طول واقعی بدنه با هدر مغایرت پیدا می‌کند و همین
    // ناهماهنگی زیر HTTP/2 باعث قطع اتصال با خطای ERR_HTTP2_PROTOCOL_ERROR در مرورگر می‌شود.
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
  }
}
