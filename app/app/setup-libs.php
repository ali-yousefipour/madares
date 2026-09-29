<?php
/**
 * دانلودگر کتابخانه‌ها برای حالت آفلاین.
 * یک‌بار این آدرس را در مرورگر باز کنید:  https://دامنه‌شما/setup-libs.php
 * کتابخانهٔ نقشه (Leaflet) و فونت وزیرمتن داخل پوشهٔ vendor/ دانلود می‌شوند تا پنل
 * (و به‌خصوص نقشهٔ داشبورد) بدون نیاز به اینترنت جهانی/دسترسی مستقیم به CDNهای خارجی کار کند.
 */
@set_time_limit(600);
@ini_set('memory_limit', '512M');
$AUTO = isset($_GET['auto']);
if ($AUTO) { ob_start(); } // در حالت خودکار، خروجی HTML نمایش داده نمی‌شود
header('Content-Type: text/html; charset=utf-8');

$BASE = __DIR__ . '/vendor';
@mkdir($BASE, 0775, true);

function fetch_url($url) {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 120,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_USERAGENT => 'Mozilla/5.0 vendor-fetch',
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($data !== false && $code >= 200 && $code < 400) return $data;
    return false;
  }
  if (ini_get('allow_url_fopen')) {
    $ctx = stream_context_create(['http'=>['timeout'=>120],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $data = @file_get_contents($url, false, $ctx);
    return $data === false ? false : $data;
  }
  return false;
}

function save_file($base, $rel, $data) {
  $path = $base . '/' . $rel;
  @mkdir(dirname($path), 0775, true);
  return file_put_contents($path, $data) !== false;
}

// نگاشت: آدرس اینترنتی → مسیر محلی (فقط کتابخانه‌هایی که این پروژه واقعاً استفاده می‌کند)
$files = [
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'                 => 'leaflet/leaflet.js',
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'                => 'leaflet/leaflet.css',
  'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png'     => 'leaflet/images/marker-icon.png',
  'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png'  => 'leaflet/images/marker-icon-2x.png',
  'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png'   => 'leaflet/images/marker-shadow.png',
  'https://unpkg.com/leaflet@1.9.4/dist/images/layers.png'          => 'leaflet/images/layers.png',
  'https://unpkg.com/leaflet@1.9.4/dist/images/layers-2x.png'       => 'leaflet/images/layers-2x.png',
];

echo '<html dir="rtl"><head><meta charset="utf-8"><title>دانلود کتابخانه‌ها</title>';
echo '<style>body{font-family:Tahoma,sans-serif;background:#f4f6fb;padding:24px;color:#0f1b2d}';
echo '.ok{color:#16a06a}.no{color:#e23b54}.row{padding:6px 0;border-bottom:1px solid #e4e9f2;font-size:13px}';
echo 'h2{color:#0a5f4a}</style></head><body>';
echo '<h2>دانلود کتابخانه‌های موردنیاز پنل</h2>';

$ok = 0; $fail = 0;
foreach ($files as $url => $rel) {
  $path = $BASE . '/' . $rel;
  if (file_exists($path) && filesize($path) > 0) { echo "<div class='row'>↪ از قبل موجود: <b>$rel</b></div>"; $ok++; continue; }
  $data = fetch_url($url);
  if ($data === false || strlen($data) < 100) { echo "<div class='row no'>✗ ناموفق: $rel <small>($url)</small></div>"; $fail++; continue; }
  if (save_file($BASE, $rel, $data)) { echo "<div class='row ok'>✓ دانلود شد: <b>$rel</b> (".number_format(strlen($data))." بایت)</div>"; $ok++; }
  else { echo "<div class='row no'>✗ ذخیره ناموفق (دسترسی نوشتن؟): $rel</div>"; $fail++; }
  echo str_pad('', 1024)."\n"; @ob_flush(); @flush();
}

// ---- فونت وزیرمتن: CSS + فایل‌های فونت با اصلاح مسیرها ----
echo '<h3>فونت وزیرمتن</h3>';
$cssUrl = 'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css';
$css = fetch_url($cssUrl);
if ($css === false) {
  echo "<div class='row no'>✗ دانلود CSS فونت ناموفق بود.</div>"; $fail++;
} else {
  // پیدا کردن url(...) ها و دانلود فایل‌های فونت به‌صورت محلی
  preg_match_all('/url\(([^)]+)\)/i', $css, $m);
  $fontBase = 'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/';
  $seen = [];
  foreach ($m[1] as $raw) {
    $u = trim($raw, " \t\"'");
    if (strpos($u, 'data:') === 0) continue;
    $clean = preg_replace('/[?#].*$/', '', $u);
    if (isset($seen[$clean])) continue; $seen[$clean] = true;
    $abs = (strpos($clean, 'http') === 0) ? $clean : $fontBase . ltrim($clean, './');
    $local = 'vazirmatn/' . ltrim($clean, './');
    if (file_exists($BASE.'/'.$local) && filesize($BASE.'/'.$local) > 0) { $ok++; continue; }
    $fd = fetch_url($abs);
    if ($fd !== false && strlen($fd) > 100) { save_file($BASE, $local, $fd); echo "<div class='row ok'>✓ فونت: $local</div>"; $ok++; }
    else { echo "<div class='row no'>✗ فونت: $local</div>"; $fail++; }
    echo str_pad('', 512)."\n"; @ob_flush(); @flush();
  }
  // ذخیرهٔ CSS (مسیرهای نسبی همان‌طور می‌مانند چون فایل‌ها را با همان ساختار ذخیره کردیم)
  save_file($BASE, 'vazirmatn/Vazirmatn-font-face.css', $css);
  echo "<div class='row ok'>✓ CSS فونت ذخیره شد.</div>";
}

echo "<h2 style='margin-top:20px'>".($fail===0 ? "<span class='ok'>✅ همه‌چیز آماده است</span>" : "<span class='no'>با $fail خطا</span>")." — موفق: $ok</h2>";
if ($fail === 0) {
  echo "<p>حالا پنل بدون اینترنت جهانی کار می‌کند. <b>این فایل (setup-libs.php) را پس از اطمینان حذف کنید.</b></p>";
} else {
  echo "<p>برخی فایل‌ها دانلود نشدند (احتمالاً به‌دلیل محدودیت اینترنت سرور). لطفاً صفحه را چند دقیقه بعد دوباره باز کنید؛ فایل‌های موجود دوباره دانلود نمی‌شوند.</p>";
}
echo '<p style="margin-top:10px"><a href="/">→ بازگشت به پنل</a></p>';
echo '</body></html>';

// ثبت پرچم آمادگی و خروجی کوتاه در حالت خودکار
if ($fail === 0) { @file_put_contents($BASE.'/.vendor_ready', date('c')); }
if ($AUTO) { @ob_end_clean(); header('Content-Type: text/plain; charset=utf-8'); echo ($fail===0?'READY':'PARTIAL')." ok=$ok fail=$fail"; }

