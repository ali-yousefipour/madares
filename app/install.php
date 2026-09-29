<?php
// نصاب وب: https://دامنه‌شما/install.php
$ROOT = __DIR__ . '/..';
$LOCK = "$ROOT/.installed";
$msg = null; $done = false;

if (getenv('ALLOW_INSTALLER') !== '1') { http_response_code(403); echo 'Installer is disabled. Set ALLOW_INSTALLER=1 temporarily during first installation.'; exit; }

if (is_file($LOCK)) { $msg = ['s', 'سامانه قبلاً نصب شده است. برای نصب مجدد فایل .installed را از روی سرور حذف کنید.']; $done = true; }

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $f = $_POST;
  try {
    if (empty($f['admin_user']) || empty($f['admin_pass']) || strlen($f['admin_pass']) < 10) {
      throw new Exception('نام کاربری مدیر و رمز عبور (حداقل ۱۰ کاراکتر) الزامی است.');
    }
    $dsn = "mysql:host={$f['db_host']};dbname={$f['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $f['db_user'], $f['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // ۱) ساخت جداول
    $sql = file_get_contents("$ROOT/db/mysql_schema.sql");
    $pdo->exec($sql);

    // ۲) نوشتن .env قبل از هر کار دیگر (تا Db.php بتواند وصل شود)
    $secret = bin2hex(random_bytes(32));
    $env = "DB_HOST={$f['db_host']}\nDB_NAME={$f['db_name']}\nDB_USER={$f['db_user']}\nDB_PASS={$f['db_pass']}\n" .
           "JWT_SECRET=$secret\nPUBLIC_URL=" . ($f['public_url'] ?? '') . "\n";
    file_put_contents("$ROOT/.env", $env);

    // ۳) حساب مدیر سامانه
    $hash = password_hash($f['admin_pass'], PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO admin_users(username,password_hash,full_name) VALUES(?,?,?)
                   ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)")
        ->execute([$f['admin_user'], $hash, $f['admin_name'] ?: 'مدیر سامانه']);

    file_put_contents($LOCK, date('c'));
    $msg = ['s', 'نصب با موفقیت انجام شد! اکنون می‌توانید با نام کاربری و رمز واردشده وارد پنل مدیریت شوید (/panel.html). به‌دلایل امنیتی، فایل install.php را از سرور حذف کنید.'];
    $done = true;
  } catch (Throwable $e) {
    $msg = ['e', 'خطا: ' . $e->getMessage()];
  }
}
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<title>نصب سامانهٔ مدیریت سرویس حمل و نقل دانش آموزی</title>
<style>
body{font-family:Tahoma,sans-serif;background:#111;color:#eee;max-width:520px;margin:40px auto;padding:0 16px}
h1{color:#F5B301;font-size:20px}
label{display:block;margin-top:12px;font-size:13px;color:#ccc}
input{width:100%;padding:10px;border-radius:8px;border:1px solid #333;background:#1a1a1a;color:#fff;margin-top:4px;box-sizing:border-box}
button{margin-top:20px;width:100%;padding:12px;border-radius:8px;border:none;background:#F5B301;color:#111;font-weight:bold;font-size:15px;cursor:pointer}
.msg{padding:12px;border-radius:8px;margin-top:16px}
.s{background:#173a24;color:#7be3a0}
.e{background:#3a1717;color:#ff8080}
fieldset{border:1px solid #333;border-radius:8px;margin-top:18px;padding:10px 14px}
legend{color:#F5B301;padding:0 6px}
</style></head><body>
<h1>نصب سامانهٔ مدیریت شرکت‌های سرویس حمل و نقل دانش آموزی مشهد</h1>
<?php if ($msg): ?><div class="msg <?= $msg[0] ?>"><?= htmlspecialchars($msg[1]) ?></div><?php endif; ?>
<?php if (!$done): ?>
<form method="post">
  <fieldset><legend>اطلاعات دیتابیس (از cPanel)</legend>
    <label>DB Host<input name="db_host" value="localhost" required></label>
    <label>نام دیتابیس<input name="db_name" required></label>
    <label>یوزر دیتابیس<input name="db_user" required></label>
    <label>پسورد دیتابیس<input name="db_pass" type="password"></label>
  </fieldset>
  <fieldset><legend>حساب مدیر سامانه</legend>
    <label>نام کاربری<input name="admin_user" value="admin" required></label>
    <label>نام و نام‌خانوادگی<input name="admin_name" value="مدیر سامانه"></label>
    <label>رمز عبور<input name="admin_pass" type="password" required></label>
  </fieldset>
  <label>آدرس عمومی سایت (اختیاری، مثل https://app.taxiranico.ir)<input name="public_url" value="https://app.taxiranico.ir"></label>
  <button type="submit">شروع نصب</button>
</form>
<?php endif; ?>
</body></html>
