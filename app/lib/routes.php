<?php
/* ==================== سامانهٔ مدیریت شرکت‌های سرویس مدارس مشهد — API ==================== */

function _cfg() { static $c; return $c ?: ($c = require __DIR__ . '/../config.php'); }
function _issue_admin_token($row) {
  return Jwt::sign(['scope' => 'admin', 'id' => $row['id'], 'username' => $row['username'], 'full_name' => $row['full_name'], 'role' => $row['role'] ?? 'super_admin'], _cfg()['jwt_secret'], _cfg()['access_ttl']);
}
function _issue_company_token($row) {
  return Jwt::sign(['scope' => 'company', 'id' => $row['id'], 'username' => $row['username'], 'company_id' => (int)$row['company_id'], 'full_name' => $row['full_name']], _cfg()['jwt_secret'], _cfg()['access_ttl']);
}
// اطمینان از وجود ستون role روی admin_users (سازگاری با دیتابیس‌های نصب‌شدهٔ قدیمی‌تر)
function _ensure_admin_role_column(){
  static $done = false; if ($done) return; $done = true;
  try { if (!Db::one("SHOW COLUMNS FROM admin_users WHERE Field='role'")) Db::run("ALTER TABLE admin_users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'super_admin'"); }
  catch (\Throwable $e) {}
}
// سطوح دسترسی: super_admin (کامل)، manager (مدیریت عملیاتی، بدون تنظیمات/کاربران مدیر)، viewer (فقط مشاهده)
function _require_role($u, array $roles) {
  if (!in_array($u['role'] ?? 'super_admin', $roles, true)) Http::error('دسترسی شما برای این عملیات کافی نیست.', 403);
}
function _block_viewer($u) { if (($u['role'] ?? '') === 'viewer') Http::error('حساب شما فقط دسترسی مشاهده دارد.', 403); }

// اطمینان از وجود ستون‌های جدید مدارس (سازگاری با نصب‌های قبلی‌تر این پروژه)
function _ensure_school_columns(){
  static $done = false; if ($done) return; $done = true;
  $cols = [
    'school_type' => "VARCHAR(20) NULL",
    'start_time' => "VARCHAR(10) NULL",
    'end_time' => "VARCHAR(10) NULL",
    'driver_count' => "INT NULL",
    'gps_accuracy' => "DOUBLE NULL",
  ];
  foreach ($cols as $name => $ddl) {
    try { if (!Db::one("SHOW COLUMNS FROM schools WHERE Field=?", [$name])) Db::run("ALTER TABLE schools ADD COLUMN `$name` $ddl"); }
    catch (\Throwable $e) {}
  }
}

/* ---------------- احراز هویت: مدیر سایت ---------------- */
route('POST', '/api/admin/login', function ($p, $b) {
  _ensure_admin_role_column();
  $u = trim($b['username'] ?? ''); $pw = (string)($b['password'] ?? '');
  if (!$u || !$pw) Http::error('نام کاربری و رمز عبور را وارد کنید', 400);
  $row = Db::one("SELECT * FROM admin_users WHERE username=? AND is_active=1", [$u]);
  if (!$row || !password_verify($pw, $row['password_hash'])) Http::error('نام کاربری یا رمز عبور اشتباه است', 401);
  return ['token' => _issue_admin_token($row), 'user' => ['id' => $row['id'], 'username' => $row['username'], 'full_name' => $row['full_name'], 'role' => $row['role'] ?? 'super_admin']];
}, true);

route('GET', '/api/admin/me', function ($p, $b, $u) {
  return ['id' => $u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role'] ?? 'super_admin'];
}, false, 'admin');

/* ---- مدیریت کاربران مدیر سایت و سطوح دسترسی (فقط super_admin) ---- */
route('GET', '/api/admin/admin-users', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  return Db::all("SELECT id,username,full_name,role,is_active,created_at FROM admin_users ORDER BY id");
}, false, 'admin');

route('POST', '/api/admin/admin-users', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  $un = trim($b['username'] ?? ''); $pw = (string)($b['password'] ?? ''); $fn = trim($b['full_name'] ?? '');
  $role = in_array($b['role'] ?? '', ['super_admin', 'manager', 'viewer'], true) ? $b['role'] : 'viewer';
  if (!$un || !$pw || !$fn) Http::error('نام کاربری، رمز عبور و نام کامل الزامی است', 400);
  if (strlen($pw) < 4) Http::error('رمز عبور باید حداقل ۴ کاراکتر باشد', 400);
  if (Db::one("SELECT id FROM admin_users WHERE username=?", [$un])) Http::error('این نام کاربری قبلاً استفاده شده است', 409);
  $id = Db::insert("INSERT INTO admin_users(username,password_hash,full_name,role) VALUES(?,?,?,?)", [$un, password_hash($pw, PASSWORD_BCRYPT), $fn, $role]);
  return ['id' => $id];
}, false, 'admin');

route('PUT', '/api/admin/admin-users/{id}', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  $sets = []; $args = [];
  if (isset($b['full_name']) && trim($b['full_name']) !== '') { $sets[] = 'full_name=?'; $args[] = trim($b['full_name']); }
  if (in_array($b['role'] ?? '', ['super_admin', 'manager', 'viewer'], true)) { $sets[] = 'role=?'; $args[] = $b['role']; }
  if (isset($b['is_active'])) {
    if ((int)$p['id'] === (int)$u['id'] && !$b['is_active']) Http::error('نمی‌توانید حساب خودتان را غیرفعال کنید.', 400);
    $sets[] = 'is_active=?'; $args[] = (int)!!$b['is_active'];
  }
  if (!empty($b['password'])) {
    if (strlen($b['password']) < 4) Http::error('رمز عبور باید حداقل ۴ کاراکتر باشد', 400);
    $sets[] = 'password_hash=?'; $args[] = password_hash($b['password'], PASSWORD_BCRYPT);
  }
  if (!$sets) Http::error('داده‌ای برای بروزرسانی ارسال نشده', 400);
  $args[] = (int)$p['id'];
  Db::run("UPDATE admin_users SET " . implode(',', $sets) . " WHERE id=?", $args);
  return ['ok' => true];
}, false, 'admin');

route('DELETE', '/api/admin/admin-users/{id}', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  if ((int)$p['id'] === (int)$u['id']) Http::error('نمی‌توانید حساب خودتان را حذف کنید.', 400);
  Db::run("DELETE FROM admin_users WHERE id=?", [(int)$p['id']]);
  return ['ok' => true];
}, false, 'admin');

/* ---------------- احراز هویت: نمایندهٔ شرکت (اپ اندروید) ---------------- */
route('POST', '/api/auth/login', function ($p, $b) {
  $u = trim($b['username'] ?? ''); $pw = (string)($b['password'] ?? '');
  if (!$u || !$pw) Http::error('نام کاربری و رمز عبور را وارد کنید', 400);
  $row = Db::one("SELECT cu.*, c.title company_title, c.is_active company_active FROM company_users cu JOIN companies c ON c.id=cu.company_id WHERE cu.username=?", [$u]);
  if (!$row || !password_verify($pw, $row['password_hash'])) Http::error('نام کاربری یا رمز عبور اشتباه است', 401);
  if (!$row['is_active']) Http::error('حساب کاربری شما غیرفعال شده است.', 403);
  if (!$row['company_active']) Http::error('شرکت شما غیرفعال شده است.', 403);
  Db::run("UPDATE company_users SET last_login_at=NOW(), device_id=? WHERE id=?", [$b['device_id'] ?? null, $row['id']]);
  try { Db::run("INSERT INTO company_user_logins(company_user_id,device_model,ip) VALUES(?,?,?)", [$row['id'], $b['device_model'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null]); } catch (\Throwable $e) {}
  return ['token' => _issue_company_token($row), 'user' => [
    'id' => $row['id'], 'username' => $row['username'], 'full_name' => $row['full_name'],
    'company_id' => (int)$row['company_id'], 'company_title' => $row['company_title'],
  ]];
}, true);

function _ensure_company_user_avatar_column(){
  static $done = false; if ($done) return; $done = true;
  try { if (!Db::one("SHOW COLUMNS FROM company_users WHERE Field='avatar_path'")) Db::run("ALTER TABLE company_users ADD COLUMN avatar_path VARCHAR(255) NULL"); }
  catch (\Throwable $e) {}
}

route('GET', '/api/auth/me', function ($p, $b, $u) {
  _ensure_company_user_avatar_column();
  $row = Db::one("SELECT cu.id,cu.username,cu.full_name,cu.phone,cu.avatar_path,c.id company_id,c.title company_title
    FROM company_users cu JOIN companies c ON c.id=cu.company_id WHERE cu.id=?", [$u['id']]);
  if (!$row) Http::error('کاربر یافت نشد', 404);
  if ($row['avatar_path']) $row['avatar_url'] = '/api/media?path=' . urlencode($row['avatar_path']);
  return $row;
}, false, 'company');

// ویرایش اطلاعات فردی نماینده (نام/تلفن)
route('PUT', '/api/auth/me', function ($p, $b, $u) {
  $fn = trim($b['full_name'] ?? ''); if (!$fn) Http::error('نام کامل الزامی است', 400);
  Db::run("UPDATE company_users SET full_name=?, phone=? WHERE id=?", [$fn, $b['phone'] ?? null, $u['id']]);
  return ['ok' => true];
}, false, 'company');

// آپلود/تعویض عکس پروفایل (به‌صورت فایل روی دیسک، نه base64 در دیتابیس)
route('POST', '/api/auth/avatar', function ($p, $b, $u) {
  _ensure_company_user_avatar_column();
  if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? 1) !== 0) Http::error('عکسی ارسال نشد', 400);
  $old = Db::one("SELECT avatar_path FROM company_users WHERE id=?", [$u['id']]);
  $path = Media::saveUploadedFile($_FILES['photo'], 'avatars', 500, 80);
  if (!$path) Http::error('ذخیرهٔ عکس ناموفق بود', 500);
  Db::run("UPDATE company_users SET avatar_path=? WHERE id=?", [$path, $u['id']]);
  if ($old && $old['avatar_path']) { try { Media::delete($old['avatar_path']); } catch (\Throwable $e) {} }
  return ['ok' => true, 'avatar_url' => '/api/media?path=' . urlencode($path)];
}, false, 'company');

// تغییر رمز عبور شخصی (نیازمند رمز فعلی)
route('PUT', '/api/auth/password', function ($p, $b, $u) {
  $old = (string)($b['old_password'] ?? ''); $new = (string)($b['new_password'] ?? '');
  if (!$old || !$new) Http::error('رمز فعلی و رمز جدید را وارد کنید', 400);
  if (strlen($new) < 4) Http::error('رمز جدید باید حداقل ۴ کاراکتر باشد', 400);
  $row = Db::one("SELECT password_hash FROM company_users WHERE id=?", [$u['id']]);
  if (!$row || !password_verify($old, $row['password_hash'])) Http::error('رمز فعلی اشتباه است', 401);
  Db::run("UPDATE company_users SET password_hash=? WHERE id=?", [password_hash($new, PASSWORD_BCRYPT), $u['id']]);
  return ['ok' => true];
}, false, 'company');

/* ---------------- پروفایل شرکت (توسط نمایندهٔ همان شرکت) ---------------- */
route('GET', '/api/company/profile', function ($p, $b, $u) {
  _ensure_company_columns();
  $row = Db::one("SELECT * FROM companies WHERE id=?", [$u['company_id']]);
  if (!$row) Http::error('شرکت یافت نشد', 404);
  return $row;
}, false, 'company');

route('PUT', '/api/company/profile', function ($p, $b, $u) {
  _ensure_company_columns();
  $sets = []; $args = [];
  foreach (['manager_name', 'ceo_mobile', 'phone', 'address'] as $f) {
    if (array_key_exists($f, $b)) { $sets[] = "$f=?"; $args[] = trim($b[$f]) ?: null; }
  }
  if (isset($b['lat']) && isset($b['lng']) && is_numeric($b['lat']) && is_numeric($b['lng'])) {
    $sets[] = 'lat=?'; $args[] = $b['lat']; $sets[] = 'lng=?'; $args[] = $b['lng'];
  }
  if (!$sets) Http::error('داده‌ای برای بروزرسانی ارسال نشده', 400);
  $args[] = $u['company_id'];
  Db::run("UPDATE companies SET " . implode(',', $sets) . " WHERE id=?", $args);
  return ['ok' => true];
}, false, 'company');

/* ==================== نواحی آموزش و پرورش ==================== */
route('GET', '/api/admin/districts', fn($p, $b, $u) => Db::all(
  "SELECT d.*, (SELECT COUNT(*) FROM schools s WHERE s.district_id=d.id) schools_count
   FROM districts d ORDER BY d.title"), false, 'admin');

route('GET', '/api/districts', fn($p, $b) => Db::all("SELECT id,title FROM districts WHERE is_active=1 ORDER BY title"), true);

route('POST', '/api/admin/districts', function ($p, $b, $u) {
_block_viewer($u);
    $t = trim($b['title'] ?? ''); if (!$t) Http::error('عنوان ناحیه الزامی است', 400);
  $id = Db::insert("INSERT INTO districts(title) VALUES(?)", [$t]);
  return ['id' => $id];
}, false, 'admin');

route('PUT', '/api/admin/districts/{id}', function ($p, $b, $u) {
_block_viewer($u);
    $t = trim($b['title'] ?? ''); if (!$t) Http::error('عنوان ناحیه الزامی است', 400);
  Db::run("UPDATE districts SET title=?, is_active=? WHERE id=?", [$t, isset($b['is_active']) ? (int)!!$b['is_active'] : 1, (int)$p['id']]);
  return ['ok' => true];
}, false, 'admin');

route('DELETE', '/api/admin/districts/{id}', function ($p, $b, $u) {
_block_viewer($u);
    Db::run("DELETE FROM districts WHERE id=?", [(int)$p['id']]);
  return ['ok' => true];
}, false, 'admin');

/* ==================== مقاطع تحصیلی (قابل تعریف) ==================== */
function _ensure_education_levels_table(){
  static $done = false; if ($done) return; $done = true;
  try {
    Db::run("CREATE TABLE IF NOT EXISTS education_levels (
      id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100) NOT NULL, sort_order INT NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_level_title (title)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // اگر جدول تازه ساخته شده، مقاطع رایج را به‌صورت پیش‌فرض اضافه کن
    $cnt = Db::one("SELECT COUNT(*) n FROM education_levels")['n'] ?? 0;
    if ($cnt == 0) {
      foreach (['پیش‌دبستانی', 'ابتدایی', 'متوسطهٔ اول', 'متوسطهٔ دوم'] as $i => $t) {
        Db::run("INSERT IGNORE INTO education_levels(title,sort_order) VALUES(?,?)", [$t, $i]);
      }
    }
  } catch (\Throwable $e) {}
}
// این مسیر عمومی است چون هم پنل و هم اپ اندروید برای پرکردن لیست مقاطع به آن نیاز دارند
route('GET', '/api/education-levels', function ($p, $b) {
  _ensure_education_levels_table();
  return Db::all("SELECT id,title FROM education_levels WHERE is_active=1 ORDER BY sort_order, title");
}, true);

route('GET', '/api/admin/education-levels', function ($p, $b, $u) {
  _ensure_education_levels_table();
  return Db::all("SELECT * FROM education_levels ORDER BY sort_order, title");
}, false, 'admin');

route('POST', '/api/admin/education-levels', function ($p, $b, $u) {
  _block_viewer($u); _ensure_education_levels_table();
  $t = trim($b['title'] ?? ''); if (!$t) Http::error('عنوان مقطع الزامی است', 400);
  if (Db::one("SELECT id FROM education_levels WHERE title=?", [$t])) Http::error('این مقطع قبلاً ثبت شده است', 409);
  $id = Db::insert("INSERT INTO education_levels(title,sort_order) VALUES(?,?)", [$t, (int)($b['sort_order'] ?? 0)]);
  return ['id' => $id];
}, false, 'admin');

route('PUT', '/api/admin/education-levels/{id}', function ($p, $b, $u) {
  _block_viewer($u); _ensure_education_levels_table();
  $t = trim($b['title'] ?? ''); if (!$t) Http::error('عنوان مقطع الزامی است', 400);
  Db::run("UPDATE education_levels SET title=?, sort_order=?, is_active=? WHERE id=?",
    [$t, (int)($b['sort_order'] ?? 0), isset($b['is_active']) ? (int)!!$b['is_active'] : 1, (int)$p['id']]);
  return ['ok' => true];
}, false, 'admin');

route('DELETE', '/api/admin/education-levels/{id}', function ($p, $b, $u) {
  _block_viewer($u); _ensure_education_levels_table();
  Db::run("DELETE FROM education_levels WHERE id=?", [(int)$p['id']]);
  return ['ok' => true];
}, false, 'admin');

/* ==================== شرکت‌های سرویس‌دهنده ==================== */
function _ensure_company_columns(){
  static $done = false; if ($done) return; $done = true;
  foreach (['ceo_mobile' => "VARCHAR(20) NULL", 'lat' => "DOUBLE NULL", 'lng' => "DOUBLE NULL"] as $col => $ddl) {
    try { if (!Db::one("SHOW COLUMNS FROM companies WHERE Field=?", [$col])) Db::run("ALTER TABLE companies ADD COLUMN `$col` $ddl"); }
    catch (\Throwable $e) {}
  }
}

route('GET', '/api/admin/companies', function($p, $b, $u) {
  _ensure_company_columns();
  return Db::all(
  "SELECT c.*, 
      (SELECT COUNT(*) FROM schools s WHERE s.company_id=c.id) schools_count,
      (SELECT COUNT(*) FROM schools s WHERE s.company_id=c.id AND s.location_status='done') done_count,
      (SELECT COUNT(*) FROM company_users cu WHERE cu.company_id=c.id) users_count
   FROM companies c ORDER BY c.title");
}, false, 'admin');

route('GET', '/api/admin/companies/{id}', function ($p, $b, $u) {
  _ensure_company_columns();
  $row = Db::one("SELECT * FROM companies WHERE id=?", [(int)$p['id']]);
  if (!$row) Http::error('یافت نشد', 404);
  $row['users'] = Db::all("SELECT id,username,full_name,phone,is_active,last_login_at,created_at FROM company_users WHERE company_id=? ORDER BY id DESC", [(int)$p['id']]);
  return $row;
}, false, 'admin');

route('POST', '/api/admin/companies', function ($p, $b, $u) {
_block_viewer($u);
    $t = trim($b['title'] ?? ''); if (!$t) Http::error('نام شرکت الزامی است', 400);
  $id = Db::insert("INSERT INTO companies(title,manager_name,phone,address) VALUES(?,?,?,?)",
    [$t, $b['manager_name'] ?? null, $b['phone'] ?? null, $b['address'] ?? null]);
  return ['id' => $id];
}, false, 'admin');

route('PUT', '/api/admin/companies/{id}', function ($p, $b, $u) {
_block_viewer($u);
    $t = trim($b['title'] ?? ''); if (!$t) Http::error('نام شرکت الزامی است', 400);
  Db::run("UPDATE companies SET title=?, manager_name=?, phone=?, address=?, is_active=? WHERE id=?",
    [$t, $b['manager_name'] ?? null, $b['phone'] ?? null, $b['address'] ?? null, isset($b['is_active']) ? (int)!!$b['is_active'] : 1, (int)$p['id']]);
  return ['ok' => true];
}, false, 'admin');

route('DELETE', '/api/admin/companies/{id}', function ($p, $b, $u) {
_block_viewer($u);
    Db::run("DELETE FROM companies WHERE id=?", [(int)$p['id']]);
  return ['ok' => true];
}, false, 'admin');

/* ---- کاربران نمایندهٔ شرکت ---- */
route('POST', '/api/admin/companies/{id}/users', function ($p, $b, $u) {
_block_viewer($u);
    $un = trim($b['username'] ?? ''); $pw = (string)($b['password'] ?? ''); $fn = trim($b['full_name'] ?? '');
  if (!$un || !$pw || !$fn) Http::error('نام کاربری، رمز عبور و نام کامل الزامی است', 400);
  if (strlen($pw) < 4) Http::error('رمز عبور باید حداقل ۴ کاراکتر باشد', 400);
  $exists = Db::one("SELECT id FROM company_users WHERE username=?", [$un]);
  if ($exists) Http::error('این نام کاربری قبلاً استفاده شده است', 409);
  $id = Db::insert("INSERT INTO company_users(company_id,username,password_hash,full_name,phone) VALUES(?,?,?,?,?)",
    [(int)$p['id'], $un, password_hash($pw, PASSWORD_BCRYPT), $fn, $b['phone'] ?? null]);
  return ['id' => $id];
}, false, 'admin');

route('PUT', '/api/admin/company-users/{id}', function ($p, $b, $u) {
_block_viewer($u);
    $sets = []; $args = [];
  if (isset($b['full_name']) && trim($b['full_name']) !== '') { $sets[] = 'full_name=?'; $args[] = trim($b['full_name']); }
  if (array_key_exists('phone', $b)) { $sets[] = 'phone=?'; $args[] = $b['phone']; }
  if (isset($b['is_active'])) { $sets[] = 'is_active=?'; $args[] = (int)!!$b['is_active']; }
  if (!empty($b['password'])) {
    if (strlen($b['password']) < 4) Http::error('رمز عبور باید حداقل ۴ کاراکتر باشد', 400);
    $sets[] = 'password_hash=?'; $args[] = password_hash($b['password'], PASSWORD_BCRYPT);
  }
  if (!$sets) Http::error('داده‌ای برای بروزرسانی ارسال نشده', 400);
  $args[] = (int)$p['id'];
  Db::run("UPDATE company_users SET " . implode(',', $sets) . " WHERE id=?", $args);
  return ['ok' => true];
}, false, 'admin');

route('DELETE', '/api/admin/company-users/{id}', function ($p, $b, $u) {
_block_viewer($u);
    Db::run("DELETE FROM company_users WHERE id=?", [(int)$p['id']]);
  return ['ok' => true];
}, false, 'admin');

/* ==================== آیتم‌های سفارشیِ مشخصات مدرسه (قابل تعریف) ==================== */
// فیلدهای پیش‌فرض سیستم (ستون‌های واقعی جدول schools) — قابل حذف نیستند چون بخشی از ساختار
// اصلی سامانه‌اند، اما عنوان نمایشی و «قابل‌ویرایش توسط نماینده» بودنشان از همین‌جا قابل تنظیم است
const BUILTIN_SCHOOL_FIELDS = [
  ['name', 'نام مدرسه'], ['district_id', 'ناحیهٔ آموزش و پرورش'], ['company_id', 'شرکت سرویس‌دهنده'],
  ['gender', 'جنسیت دانش‌آموزان'], ['shift', 'شیفت'], ['level', 'مقطع تحصیلی'], ['school_type', 'نوع مدرسه'],
  ['start_time', 'ساعت شروع فعالیت'], ['end_time', 'ساعت پایان فعالیت'], ['driver_count', 'تعداد رانندگان'],
  ['student_count', 'تعداد دانش‌آموزان'], ['address', 'آدرس'], ['phone', 'تلفن'], ['principal_name', 'نام مدیر مدرسه'],
];
route('GET', '/api/school-builtin-fields', function ($p, $b) {
  $meta = json_decode(_setting_get('builtin_field_meta', '{}'), true) ?: [];
  return array_map(function ($f) use ($meta) {
    return ['field_key' => $f[0], 'label' => $meta[$f[0]]['label'] ?? $f[1], 'editable_by_rep' => (bool)($meta[$f[0]]['editable_by_rep'] ?? in_array($f[0], ['address', 'phone', 'principal_name', 'student_count'], true))];
  }, BUILTIN_SCHOOL_FIELDS);
}, true);

route('PUT', '/api/admin/school-builtin-fields', function ($p, $b, $u) {
  _block_viewer($u);
  $meta = json_decode(_setting_get('builtin_field_meta', '{}'), true) ?: [];
  $updates = $b['fields'] ?? [];
  foreach ($updates as $key => $conf) {
    if (!in_array($key, array_column(BUILTIN_SCHOOL_FIELDS, 0), true)) continue;
    $meta[$key] = ['label' => trim($conf['label'] ?? ''), 'editable_by_rep' => (bool)($conf['editable_by_rep'] ?? false)];
  }
  _setting_set('builtin_field_meta', json_encode($meta, JSON_UNESCAPED_UNICODE));
  return ['ok' => true];
}, false, 'admin');

function _ensure_school_field_defs_table(){
  static $done = false; if ($done) return; $done = true;
  try {

    Db::run("CREATE TABLE IF NOT EXISTS school_field_defs (
      id INT AUTO_INCREMENT PRIMARY KEY, field_key VARCHAR(60) NOT NULL UNIQUE, label VARCHAR(150) NOT NULL,
      field_type VARCHAR(20) NOT NULL DEFAULT 'text', options TEXT NULL, editable_by_rep TINYINT(1) NOT NULL DEFAULT 0,
      sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (\Throwable $e) {}
  try { if (!Db::one("SHOW COLUMNS FROM schools WHERE Field='custom_fields'")) Db::run("ALTER TABLE schools ADD COLUMN custom_fields JSON NULL"); }
  catch (\Throwable $e) {}
}
function _slugify_field_key($label) {
  $s = trim($label); $map = ['fa' => true];
  // برای کلید فقط از یک هش کوتاه ثابت‌شده از عنوان استفاده می‌کنیم (چون عنوان فارسی است)
  return 'f_' . substr(md5($s), 0, 10);
}

route('GET', '/api/school-field-defs', function ($p, $b) {
  _ensure_school_field_defs_table();
  return Db::all("SELECT field_key,label,field_type,options,editable_by_rep FROM school_field_defs WHERE is_active=1 ORDER BY sort_order,id");
}, true);

route('GET', '/api/admin/school-field-defs', function ($p, $b, $u) {
  _ensure_school_field_defs_table();
  return Db::all("SELECT * FROM school_field_defs ORDER BY sort_order,id");
}, false, 'admin');

route('POST', '/api/admin/school-field-defs', function ($p, $b, $u) {
  _block_viewer($u); _ensure_school_field_defs_table();
  $label = trim($b['label'] ?? ''); if (!$label) Http::error('عنوان آیتم الزامی است', 400);
  $type = in_array($b['field_type'] ?? '', ['text', 'number', 'select', 'checkbox', 'date'], true) ? $b['field_type'] : 'text';
  $key = _slugify_field_key($label);
  if (Db::one("SELECT id FROM school_field_defs WHERE field_key=?", [$key])) Http::error('آیتمی با این عنوان قبلاً ثبت شده است', 409);
  $id = Db::insert("INSERT INTO school_field_defs(field_key,label,field_type,options,editable_by_rep,sort_order) VALUES(?,?,?,?,?,?)",
    [$key, $label, $type, $b['options'] ?? null, (int)!!($b['editable_by_rep'] ?? 0), (int)($b['sort_order'] ?? 0)]);
  return ['id' => $id];
}, false, 'admin');

route('PUT', '/api/admin/school-field-defs/{id}', function ($p, $b, $u) {
  _block_viewer($u); _ensure_school_field_defs_table();
  $label = trim($b['label'] ?? ''); if (!$label) Http::error('عنوان آیتم الزامی است', 400);
  $type = in_array($b['field_type'] ?? '', ['text', 'number', 'select', 'checkbox', 'date'], true) ? $b['field_type'] : 'text';
  Db::run("UPDATE school_field_defs SET label=?, field_type=?, options=?, editable_by_rep=?, sort_order=?, is_active=? WHERE id=?", [
    $label, $type, $b['options'] ?? null, (int)!!($b['editable_by_rep'] ?? 0), (int)($b['sort_order'] ?? 0),
    isset($b['is_active']) ? (int)!!$b['is_active'] : 1, (int)$p['id'],
  ]);
  return ['ok' => true];
}, false, 'admin');

route('DELETE', '/api/admin/school-field-defs/{id}', function ($p, $b, $u) {
  _block_viewer($u); _ensure_school_field_defs_table();
  Db::run("DELETE FROM school_field_defs WHERE id=?", [(int)$p['id']]);
  return ['ok' => true];
}, false, 'admin');

/* ==================== مدارس (پنل ادمین) ==================== */

route('GET', '/api/admin/schools', function ($p, $b, $u) {
  $conds = []; $args = [];
  if (!empty($_GET['q'])) { $conds[] = "(s.name LIKE ? OR s.code LIKE ?)"; $args[] = '%' . $_GET['q'] . '%'; $args[] = '%' . $_GET['q'] . '%'; }
  if (!empty($_GET['district_id'])) { $conds[] = "s.district_id=?"; $args[] = (int)$_GET['district_id']; }
  if (!empty($_GET['company_id'])) { $conds[] = "s.company_id=?"; $args[] = (int)$_GET['company_id']; }
  if (!empty($_GET['status'])) { $conds[] = "s.location_status=?"; $args[] = $_GET['status']; }
  $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
  $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(10, (int)($_GET['per'] ?? 30))); $off = ($page - 1) * $per;
  $total = (int)(Db::one("SELECT COUNT(*) n FROM schools s $where", $args)['n'] ?? 0);
  $rows = Db::all("SELECT s.*, d.title district_title, c.title company_title, cu.full_name editor_name
    FROM schools s LEFT JOIN districts d ON d.id=s.district_id LEFT JOIN companies c ON c.id=s.company_id
    LEFT JOIN company_users cu ON cu.id=s.edited_by
    $where ORDER BY s.id DESC LIMIT $per OFFSET $off", $args);
  return ['items' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'pages' => (int)ceil($total / $per)];
}, false, 'admin');

route('GET', '/api/admin/schools/{id}', function ($p, $b, $u) {
  _ensure_school_columns();
  $row = Db::one("SELECT s.*, d.title district_title, c.title company_title FROM schools s
    LEFT JOIN districts d ON d.id=s.district_id LEFT JOIN companies c ON c.id=s.company_id WHERE s.id=?", [(int)$p['id']]);
  if (!$row) Http::error('یافت نشد', 404);
  $row['logs'] = Db::all("SELECT l.*, cu.full_name editor_name FROM school_visit_logs l
    LEFT JOIN company_users cu ON cu.id=l.company_user_id WHERE l.school_id=? ORDER BY l.id DESC", [(int)$p['id']]);
  if ($row['photo_path']) $row['photo_url'] = '/api/media?path=' . urlencode($row['photo_path']);
  return $row;
}, false, 'admin');

function _school_code_valid($code) { return preg_match('/^\d{4,10}$/', (string)$code); }

route('POST', '/api/admin/schools', function ($p, $b, $u) {
_block_viewer($u);
  _ensure_school_columns(); _ensure_school_field_defs_table();
  $code = trim($b['code'] ?? ''); $name = trim($b['name'] ?? '');
  if (!_school_code_valid($code)) Http::error('کد یکتای مدرسه باید عددی باشد', 400);
  if (!$name) Http::error('نام مدرسه الزامی است', 400);
  $exists = Db::one("SELECT id FROM schools WHERE code=?", [$code]);
  if ($exists) Http::error('مدرسه‌ای با این کد قبلاً ثبت شده است', 409);
  $customFields = isset($b['custom_fields']) && is_array($b['custom_fields']) ? json_encode($b['custom_fields'], JSON_UNESCAPED_UNICODE) : null;
  $id = Db::insert("INSERT INTO schools(code,name,district_id,company_id,gender,shift,level,school_type,start_time,end_time,driver_count,address,phone,principal_name,student_count,custom_fields)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
    $code, $name, $b['district_id'] ?: null, $b['company_id'] ?: null, $b['gender'] ?? null, $b['shift'] ?? null,
    $b['level'] ?? null, $b['school_type'] ?? null, $b['start_time'] ?? null, $b['end_time'] ?? null, $b['driver_count'] ?: null,
    $b['address'] ?? null, $b['phone'] ?? null, $b['principal_name'] ?? null, $b['student_count'] ?: null, $customFields,
  ]);
  return ['id' => $id];
}, false, 'admin');

route('PUT', '/api/admin/schools/{id}', function ($p, $b, $u) {
_block_viewer($u);
  _ensure_school_columns(); _ensure_school_field_defs_table();
  $name = trim($b['name'] ?? ''); if (!$name) Http::error('نام مدرسه الزامی است', 400);
  $sets = "name=?, district_id=?, company_id=?, gender=?, shift=?, level=?, school_type=?, start_time=?, end_time=?, driver_count=?, address=?, phone=?, principal_name=?, student_count=?";
  $args = [
    $name, $b['district_id'] ?: null, $b['company_id'] ?: null, $b['gender'] ?? null, $b['shift'] ?? null,
    $b['level'] ?? null, $b['school_type'] ?? null, $b['start_time'] ?? null, $b['end_time'] ?? null, $b['driver_count'] ?: null,
    $b['address'] ?? null, $b['phone'] ?? null, $b['principal_name'] ?? null, $b['student_count'] ?: null,
  ];
  if (isset($b['custom_fields']) && is_array($b['custom_fields'])) { $sets .= ", custom_fields=?"; $args[] = json_encode($b['custom_fields'], JSON_UNESCAPED_UNICODE); }
  $args[] = (int)$p['id'];
  Db::run("UPDATE schools SET $sets WHERE id=?", $args);
  // اجازهٔ اصلاح دستی موقعیت توسط ادمین
  if (isset($b['lat']) && isset($b['lng']) && is_numeric($b['lat']) && is_numeric($b['lng'])) {
    Db::run("UPDATE schools SET lat=?, lng=?, gps_accuracy=?, location_status='done', location_recorded_at=NOW() WHERE id=?", [$b['lat'], $b['lng'], $b['gps_accuracy'] ?? null, (int)$p['id']]);
  }
  return ['ok' => true];
}, false, 'admin');

route('DELETE', '/api/admin/schools/{id}', function ($p, $b, $u) {
_block_viewer($u);
    Db::run("DELETE FROM schools WHERE id=?", [(int)$p['id']]);
  return ['ok' => true];
}, false, 'admin');

// ایمپورت اکسل مدارس: ستون‌های ورودی (سرستون فارسی، ترتیب مهم نیست)
// کد مدرسه | نام مدرسه | ناحیه | شرکت سرویس‌دهنده | جنسیت | شیفت | مقطع | آدرس | تلفن | مدیر مدرسه | تعداد دانش‌آموز
route('POST', '/api/admin/schools/import', function ($p, $b, $u) {
_block_viewer($u);
  _ensure_school_columns();
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== 0) Http::error('فایلی ارسال نشد', 400);
  $tmp = $_FILES['file']['tmp_name'];
  $header = null; $inserted = 0; $updated = 0; $skipped = 0; $errors = [];
  $districtCache = []; $companyCache = [];
  $getDistrictId = function ($title) use (&$districtCache) {
    $title = trim((string)$title); if ($title === '') return null;
    if (isset($districtCache[$title])) return $districtCache[$title];
    $row = Db::one("SELECT id FROM districts WHERE title=?", [$title]);
    $id = $row ? $row['id'] : Db::insert("INSERT INTO districts(title) VALUES(?)", [$title]);
    return $districtCache[$title] = $id;
  };
  $getCompanyId = function ($title) use (&$companyCache) {
    $title = trim((string)$title); if ($title === '') return null;
    if (isset($companyCache[$title])) return $companyCache[$title];
    $row = Db::one("SELECT id FROM companies WHERE title=?", [$title]);
    $id = $row ? $row['id'] : Db::insert("INSERT INTO companies(title) VALUES(?)", [$title]);
    return $companyCache[$title] = $id;
  };
  Xlsx::eachRow($tmp, function ($cells, $idx) use (&$header, &$inserted, &$updated, &$skipped, &$errors, $getDistrictId, $getCompanyId) {
    if ($idx === 0) {
      $header = [];
      foreach ($cells as $i => $v) $header[trim($v)] = $i;
      return;
    }
    $get = function ($names) use ($cells, $header) {
      foreach ((array)$names as $nm) { $i = $header[$nm] ?? null; if ($i !== null && isset($cells[$i]) && $cells[$i] !== '') return trim($cells[$i]); }
      return null;
    };
    $code = preg_replace('/\D/', '', (string)$get(['کد مدرسه', 'کد یکتای مدرسه', 'کد']));
    $name = $get(['نام مدرسه', 'نام']);
    if (!$code || !$name) { $skipped++; return; }
    $districtId = $getDistrictId($get(['ناحیه', 'ناحیهٔ آموزش و پرورش', 'ناحیه آموزش و پرورش']));
    $companyId = $getCompanyId($get(['شرکت سرویس‌دهنده', 'شرکت', 'نام شرکت']));
    $gender = $get(['جنسیت']); $shift = $get(['شیفت']); $level = $get(['مقطع']);
    $schoolType = $get(['نوع مدرسه']); $startTime = $get(['ساعت شروع', 'ساعت شروع فعالیت']); $endTime = $get(['ساعت پایان', 'ساعت پایان فعالیت']);
    $driverCount = $get(['تعداد راننده', 'تعداد رانندگان']);
    $address = $get(['آدرس']); $phone = $get(['تلفن']); $principal = $get(['مدیر مدرسه', 'نام مدیر']);
    $studentCount = $get(['تعداد دانش‌آموز', 'تعداد دانش آموز']);
    try {
      $exists = Db::one("SELECT id FROM schools WHERE code=?", [$code]);
      if ($exists) {
        Db::run("UPDATE schools SET name=?,district_id=?,company_id=?,gender=?,shift=?,level=?,school_type=?,start_time=?,end_time=?,driver_count=?,address=?,phone=?,principal_name=?,student_count=? WHERE id=?",
          [$name, $districtId, $companyId, $gender, $shift, $level, $schoolType, $startTime, $endTime, $driverCount ?: null, $address, $phone, $principal, $studentCount ?: null, $exists['id']]);
        $updated++;
      } else {
        Db::run("INSERT INTO schools(code,name,district_id,company_id,gender,shift,level,school_type,start_time,end_time,driver_count,address,phone,principal_name,student_count) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$code, $name, $districtId, $companyId, $gender, $shift, $level, $schoolType, $startTime, $endTime, $driverCount ?: null, $address, $phone, $principal, $studentCount ?: null]);
        $inserted++;
      }
    } catch (\Throwable $e) { $errors[] = "ردیف $idx: " . $e->getMessage(); $skipped++; }
  });
  return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20)];
}, false, 'admin');

// خروجی اکسل (CSV با BOM) شامل مختصات
route('GET', '/api/admin/schools/export', function ($p, $b, $u) {
  _ensure_school_columns();
  $conds = []; $args = [];
  if (!empty($_GET['district_id'])) { $conds[] = "s.district_id=?"; $args[] = (int)$_GET['district_id']; }
  if (!empty($_GET['company_id'])) { $conds[] = "s.company_id=?"; $args[] = (int)$_GET['company_id']; }
  if (!empty($_GET['status'])) { $conds[] = "s.location_status=?"; $args[] = $_GET['status']; }
  $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
  $rows = Db::all("SELECT s.code,s.name,d.title district_title,c.title company_title,s.gender,s.shift,s.level,s.school_type,
      s.start_time,s.end_time,s.driver_count,s.student_count,s.address,s.phone,
      s.lat,s.lng,s.location_status,s.location_recorded_at
    FROM schools s LEFT JOIN districts d ON d.id=s.district_id LEFT JOIN companies c ON c.id=s.company_id
    $where ORDER BY s.id", $args);
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="schools.csv"');
  echo "\xEF\xBB\xBF"; $out = fopen('php://output', 'w');
  fputcsv($out, ['کد مدرسه', 'نام مدرسه', 'ناحیه', 'شرکت', 'جنسیت', 'شیفت', 'مقطع تحصیلی', 'نوع مدرسه', 'ساعت شروع', 'ساعت پایان',
    'تعداد رانندگان', 'تعداد دانش‌آموز', 'آدرس', 'تلفن', 'عرض جغرافیایی', 'طول جغرافیایی', 'وضعیت', 'تاریخ ثبت موقعیت']);
  foreach ($rows as $r) {
    fputcsv($out, [$r['code'], $r['name'], $r['district_title'], $r['company_title'], $r['gender'], $r['shift'], $r['level'], $r['school_type'],
      $r['start_time'], $r['end_time'], $r['driver_count'], $r['student_count'], $r['address'], $r['phone'],
      $r['lat'], $r['lng'], $r['location_status'] === 'done' ? 'ثبت‌شده' : 'باقی‌مانده', $r['location_recorded_at']]);
  }
  fclose($out); exit;
}, false, 'admin');

// نقاط مدارس برای نقشهٔ داشبورد
route('GET', '/api/admin/schools/map', fn($p, $b, $u) => Db::all(
  "SELECT s.id,s.code,s.name,s.lat,s.lng,s.location_status,s.level,s.student_count,s.driver_count,s.address,
      c.title company_title,d.title district_title
   FROM schools s LEFT JOIN companies c ON c.id=s.company_id LEFT JOIN districts d ON d.id=s.district_id
   WHERE s.lat IS NOT NULL AND s.lng IS NOT NULL"), false, 'admin');

// نقشه و جستجوی عمومی مدارس برای صفحهٔ اول سایت
route('GET', '/api/public/schools/map', function ($p, $b) {
  _ensure_school_columns(); _ensure_company_columns();
  $conds = ["s.lat IS NOT NULL", "s.lng IS NOT NULL"];
  $args = [];
  if (!empty($_GET['q'])) { $conds[] = "(s.name LIKE ? OR s.code LIKE ? OR s.address LIKE ?)"; $q = '%' . trim($_GET['q']) . '%'; array_push($args, $q, $q, $q); }
  if (!empty($_GET['district_id'])) { $conds[] = "s.district_id=?"; $args[] = (int)$_GET['district_id']; }
  if (!empty($_GET['level'])) { $conds[] = "s.level=?"; $args[] = trim($_GET['level']); }
  if (!empty($_GET['gender'])) { $conds[] = "s.gender=?"; $args[] = trim($_GET['gender']); }
  $where = 'WHERE ' . implode(' AND ', $conds);
  return Db::all("SELECT s.id,s.code,s.name,s.lat,s.lng,s.level,s.gender,s.student_count,s.driver_count,s.address,
      c.id company_id,c.title company_title,c.manager_name,c.ceo_mobile,c.phone company_phone,c.address company_address,c.lat company_lat,c.lng company_lng,
      d.title district_title
    FROM schools s LEFT JOIN companies c ON c.id=s.company_id LEFT JOIN districts d ON d.id=s.district_id
    $where ORDER BY s.name LIMIT 500", $args);
}, true);

route('GET', '/api/public/company/{id}', function ($p, $b) {
  _ensure_company_columns();
  $row = Db::one("SELECT id,title,manager_name,ceo_mobile,phone,address,lat,lng FROM companies WHERE id=? AND is_active=1", [(int)$p['id']]);
  if (!$row) Http::error('شرکت یافت نشد', 404);
  return $row;
}, true);

/* ==================== آمار و گزارش‌گیری ادمین ==================== */
route('GET', '/api/admin/stats', function ($p, $b, $u) {
  _ensure_school_columns();
  $total = (int)(Db::one("SELECT COUNT(*) n FROM schools")['n'] ?? 0);
  $done = (int)(Db::one("SELECT COUNT(*) n FROM schools WHERE location_status='done'")['n'] ?? 0);
  $companies = (int)(Db::one("SELECT COUNT(*) n FROM companies WHERE is_active=1")['n'] ?? 0);
  $districts = (int)(Db::one("SELECT COUNT(*) n FROM districts WHERE is_active=1")['n'] ?? 0);
  $reps = (int)(Db::one("SELECT COUNT(*) n FROM company_users WHERE is_active=1")['n'] ?? 0);
  $totalStudents = (int)(Db::one("SELECT COALESCE(SUM(student_count),0) n FROM schools")['n'] ?? 0);
  $totalDrivers = (int)(Db::one("SELECT COALESCE(SUM(driver_count),0) n FROM schools")['n'] ?? 0);
  $byCompany = Db::all("SELECT c.id,c.title,
      COUNT(s.id) total, SUM(s.location_status='done') done
    FROM companies c LEFT JOIN schools s ON s.company_id=c.id GROUP BY c.id, c.title ORDER BY total DESC");
  $recent = Db::all("SELECT l.id, l.created_at, s.name school_name, s.code school_code, cu.full_name editor_name, c.title company_title
    FROM school_visit_logs l JOIN schools s ON s.id=l.school_id JOIN company_users cu ON cu.id=l.company_user_id
    JOIN companies c ON c.id=cu.company_id ORDER BY l.id DESC LIMIT 20");
  return ['total_schools' => $total, 'done' => $done, 'pending' => $total - $done, 'companies' => $companies,
    'districts' => $districts, 'reps' => $reps, 'total_students' => $totalStudents, 'total_drivers' => $totalDrivers,
    'by_company' => $byCompany, 'recent_activity' => $recent];
}, false, 'admin');

route('GET', '/api/admin/reports/company/{id}', function ($p, $b, $u) {
  $cid = (int)$p['id'];
  $company = Db::one("SELECT * FROM companies WHERE id=?", [$cid]);
  if (!$company) Http::error('یافت نشد', 404);
  $perUser = Db::all("SELECT cu.id,cu.full_name,cu.username,cu.last_login_at,
      (SELECT COUNT(*) FROM school_visit_logs l WHERE l.company_user_id=cu.id) visits_count,
      (SELECT COUNT(DISTINCT l.school_id) FROM school_visit_logs l WHERE l.company_user_id=cu.id) schools_touched,
      (SELECT COUNT(*) FROM company_user_logins cl WHERE cl.company_user_id=cu.id) login_count
    FROM company_users cu WHERE cu.company_id=? ORDER BY visits_count DESC", [$cid]);
  $schools = Db::all("SELECT s.id,s.code,s.name,s.location_status,s.location_recorded_at,d.title district_title,cu.full_name editor_name
    FROM schools s LEFT JOIN districts d ON d.id=s.district_id LEFT JOIN company_users cu ON cu.id=s.edited_by
    WHERE s.company_id=? ORDER BY s.location_status, s.name", [$cid]);
  return ['company' => $company, 'per_user' => $perUser, 'schools' => $schools];
}, false, 'admin');

route('GET', '/api/admin/reports/company/{id}/export', function ($p, $b, $u) {
  $cid = (int)$p['id'];
  $company = Db::one("SELECT * FROM companies WHERE id=?", [$cid]);
  if (!$company) Http::error('یافت نشد', 404);
  $schools = Db::all("SELECT s.code,s.name,d.title district_title,s.location_status,s.lat,s.lng,s.location_recorded_at,cu.full_name editor_name
    FROM schools s LEFT JOIN districts d ON d.id=s.district_id LEFT JOIN company_users cu ON cu.id=s.edited_by
    WHERE s.company_id=? ORDER BY s.name", [$cid]);
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="report_' . $cid . '.csv"');
  echo "\xEF\xBB\xBF"; $out = fopen('php://output', 'w');
  fputcsv($out, ['گزارش عملکرد شرکت', $company['title']]); fputcsv($out, []);
  fputcsv($out, ['کد مدرسه', 'نام مدرسه', 'ناحیه', 'وضعیت', 'عرض جغرافیایی', 'طول جغرافیایی', 'تاریخ ثبت', 'ثبت‌کننده']);
  foreach ($schools as $r) fputcsv($out, [$r['code'], $r['name'], $r['district_title'], $r['location_status'] === 'done' ? 'ثبت‌شده' : 'باقی‌مانده', $r['lat'], $r['lng'], $r['location_recorded_at'], $r['editor_name']]);
  fclose($out); exit;
}, false, 'admin');

/* ==================== اپ اندروید — نماینده شرکت ==================== */

// جستجوی مدرسه با کد یکتا (فقط در محدودهٔ شرکت خودِ نماینده)
// لیست کامل مدارس شرکتِ نماینده (فقط اگر ادمین این قابلیت را از تنظیمات فعال کرده باشد)
route('GET', '/api/schools/list', function ($p, $b, $u) {
  if (_setting_get('rep_can_view_school_list', '1') !== '1') Http::error('این قابلیت توسط مدیر سامانه غیرفعال شده است.', 403);
  return Db::all("SELECT s.id,s.code,s.name,s.location_status,s.level,s.gender,s.student_count,s.driver_count,d.title district_title FROM schools s
    LEFT JOIN districts d ON d.id=s.district_id WHERE s.company_id=? ORDER BY s.name", [$u['company_id']]);
}, false, 'company');

route('GET', '/api/schools/search', function ($p, $b, $u) {
  $code = preg_replace('/\D/', '', (string)($_GET['code'] ?? ''));
  if (!$code) Http::error('کد مدرسه را وارد کنید', 400);
  $row = Db::one("SELECT s.*, d.title district_title FROM schools s LEFT JOIN districts d ON d.id=s.district_id
    WHERE s.code=? AND s.company_id=?", [$code, $u['company_id']]);
  if (!$row) Http::error('مدرسه‌ای با این کد در فهرست شرکت شما یافت نشد', 404);
  if ($row['photo_path']) $row['photo_url'] = '/api/media?path=' . urlencode($row['photo_path']);
  return $row;
}, false, 'company');

route('GET', '/api/schools/{id}', function ($p, $b, $u) {
  $row = Db::one("SELECT s.*, d.title district_title FROM schools s LEFT JOIN districts d ON d.id=s.district_id
    WHERE s.id=? AND s.company_id=?", [(int)$p['id'], $u['company_id']]);
  if (!$row) Http::error('یافت نشد', 404);
  if ($row['photo_path']) $row['photo_url'] = '/api/media?path=' . urlencode($row['photo_path']);
  return $row;
}, false, 'company');

// ثبت/ویرایش موقعیت مکانی مدرسه — چندبخشی (multipart)؛ عکس فقط از دوربین در اپ اجباری می‌شود
route('POST', '/api/schools/{id}/location', function ($p, $b, $u) {
  _ensure_school_field_defs_table();
  $id = (int)$p['id'];
  $school = Db::one("SELECT * FROM schools WHERE id=? AND company_id=?", [$id, $u['company_id']]);
  if (!$school) Http::error('این مدرسه در فهرست شرکت شما نیست', 404);
  $lat = $_POST['lat'] ?? null; $lng = $_POST['lng'] ?? null; $acc = $_POST['accuracy'] ?? null;
  if (!is_numeric($lat) || !is_numeric($lng)) Http::error('موقعیت مکانی نامعتبر است', 400);
  // در ثبت اولیه عکس الزامی است؛ در ویرایش مدرسه‌ای که قبلاً عکس دارد، نماینده می‌تواند همان عکس قبلی را نگه دارد.
  $photoPath = $school['photo_path'] ?? null;
  if (!empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? 1) === 0) {
    $photoPath = Media::saveUploadedFile($_FILES['photo'], 'schools', 1280, 75);
    if (!$photoPath) Http::error('ذخیرهٔ عکس ناموفق بود', 500);
  } elseif (!$photoPath) {
    Http::error('عکس مدرسه الزامی است', 400);
  }
  // نام/جنسیت/شیفت/مقطع فقط توسط ادمین قابل‌ویرایش‌اند و نمایندهٔ شرکت آن‌ها را فقط مشاهده می‌کند
  $builtinMeta = json_decode(_setting_get('builtin_field_meta', '{}'), true) ?: [];
  $fields = ['address', 'phone', 'principal_name', 'student_count'];
  foreach (BUILTIN_SCHOOL_FIELDS as $bf) {
    $key = $bf[0];
    if (!in_array($key, ['code','company_id','district_id'], true) && !in_array($key, $fields, true) && !empty($builtinMeta[$key]['editable_by_rep'])) $fields[] = $key;
  }
  $sets = ['lat=?', 'lng=?', 'gps_accuracy=?', 'photo_path=?', "location_status='done'", 'location_recorded_at=NOW()', 'edited_by=?'];
  $args = [$lat, $lng, $acc, $photoPath, $u['id']];
  foreach ($fields as $f) { if (isset($_POST[$f]) && $_POST[$f] !== '') { $sets[] = "$f=?"; $args[] = $_POST[$f]; } }
  // آیتم‌های سفارشیِ قابل‌ویرایش توسط نماینده (فقط آن‌هایی که editable_by_rep=1 هستند)
  if (!empty($_POST['custom_fields'])) {
    $submitted = json_decode($_POST['custom_fields'], true) ?: [];
    $editableKeys = array_column(Db::all("SELECT field_key FROM school_field_defs WHERE editable_by_rep=1 AND is_active=1"), 'field_key');
    $existing = json_decode($school['custom_fields'] ?? '{}', true) ?: [];
    foreach ($submitted as $k => $v) { if (in_array($k, $editableKeys, true)) $existing[$k] = $v; }
    $sets[] = 'custom_fields=?'; $args[] = json_encode($existing, JSON_UNESCAPED_UNICODE);
  }
  $args[] = $id;
  Db::run("UPDATE schools SET " . implode(',', $sets) . " WHERE id=?", $args);
  $action = $school['location_status'] === 'done' ? 'edit' : 'create';
  Db::run("INSERT INTO school_visit_logs(school_id,company_user_id,action,lat,lng,gps_accuracy,photo_path) VALUES(?,?,?,?,?,?,?)",
    [$id, $u['id'], $action, $lat, $lng, $acc, $photoPath]);
  return ['ok' => true, 'photo_url' => '/api/media?path=' . urlencode($photoPath)];
}, false, 'company');

// داشبورد نماینده: لیست مدارسی که بازدید/ویرایش کرده
route('GET', '/api/my/visits', function ($p, $b, $u) {
  return Db::all("SELECT s.id,s.code,s.name,s.location_status,s.photo_path,MAX(l.created_at) last_visit_at, COUNT(l.id) visits_count
    FROM school_visit_logs l JOIN schools s ON s.id=l.school_id
    WHERE l.company_user_id=? GROUP BY s.id ORDER BY last_visit_at DESC", [$u['id']]);
}, false, 'company');

route('GET', '/api/my/stats', function ($p, $b, $u) {
  $total = (int)(Db::one("SELECT COUNT(*) n FROM schools WHERE company_id=?", [$u['company_id']])['n'] ?? 0);
  $done = (int)(Db::one("SELECT COUNT(*) n FROM schools WHERE company_id=? AND location_status='done'", [$u['company_id']])['n'] ?? 0);
  $mine = (int)(Db::one("SELECT COUNT(DISTINCT school_id) n FROM school_visit_logs WHERE company_user_id=?", [$u['id']])['n'] ?? 0);
  return ['company_total' => $total, 'company_done' => $done, 'company_pending' => $total - $done, 'my_visited' => $mine];
}, false, 'company');

/* ==================== تنظیمات سامانه (دامنه / اتصال دیتابیس) ==================== */
route('GET', '/api/admin/settings', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  $c = _cfg();
  return [
    'public_url' => $c['public_url'],
    'db_host' => $c['db_host'], 'db_name' => $c['db_name'], 'db_user' => $c['db_user'],
    'db_pass_set' => $c['db_pass'] !== '',
  ];
}, false, 'admin');

route('PUT', '/api/admin/settings', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  $root = __DIR__ . '/..';
  $envPath = "$root/.env";
  $c = _cfg();
  $dbHost = trim($b['db_host'] ?? $c['db_host']);
  $dbName = trim($b['db_name'] ?? $c['db_name']);
  $dbUser = trim($b['db_user'] ?? $c['db_user']);
  // اگر پسورد جدید ارسال نشده، پسورد فعلی حفظ می‌شود
  $dbPass = array_key_exists('db_pass', $b) && $b['db_pass'] !== '' ? $b['db_pass'] : $c['db_pass'];
  $publicUrl = trim($b['public_url'] ?? $c['public_url']);

  // ابتدا اتصال با تنظیمات جدید تست می‌شود تا سایت با تنظیمات نادرست از دسترس خارج نشود
  try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_TIMEOUT => 5]);
  } catch (\Throwable $e) {
    Http::error('اتصال با اطلاعات جدید دیتابیس برقرار نشد: ' . $e->getMessage(), 400);
  }

  if (!is_writable($envPath) && is_file($envPath)) Http::error('فایل .env قابل‌نوشتن نیست. دسترسی نوشتن را در هاست بررسی کنید.', 500);
  $secret = $c['jwt_secret'];
  $env = "DB_HOST=$dbHost\nDB_NAME=$dbName\nDB_USER=$dbUser\nDB_PASS=$dbPass\nJWT_SECRET=$secret\nPUBLIC_URL=$publicUrl\n";
  if (file_put_contents($envPath, $env) === false) Http::error('نوشتن فایل تنظیمات ناموفق بود.', 500);
  return ['ok' => true, 'message' => 'تنظیمات ذخیره شد.'];
}, false, 'admin');

/* ==================== تنظیمات نقشه (Google / OpenStreetMap / نشان) ==================== */
function _setting_get($key, $default = null) {
  try { $r = Db::one("SELECT value FROM app_settings WHERE `key`=?", [$key]); return $r ? $r['value'] : $default; }
  catch (\Throwable $e) { return $default; }
}
function _setting_set($key, $value) {
  Db::run("INSERT INTO app_settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$key, $value]);
}

// این مسیر عمومی است (بدون نیاز به توکن) چون هم پنل و هم اپ اندروید برای رسم نقشه به آن نیاز دارند؛
// دقیقاً مثل کلید نقشهٔ گوگل/نشان که همیشه سمت کلاینت قرار می‌گیرد.
route('GET', '/api/map-config', function ($p, $b) {
  return [
    'provider' => _setting_get('map_provider', 'osm'),
    'neshan_api_key' => _setting_get('neshan_api_key', ''),
    'neshan_map_key' => _setting_get('neshan_map_key', ''),
    'google_maps_api_key' => _setting_get('google_maps_api_key', ''),
  ];
}, true);

route('PUT', '/api/admin/map-config', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  $provider = in_array($b['provider'] ?? '', ['osm', 'google', 'neshan'], true) ? $b['provider'] : 'osm';
  _setting_set('map_provider', $provider);
  if (isset($b['neshan_api_key'])) _setting_set('neshan_api_key', trim($b['neshan_api_key']));
  if (isset($b['neshan_map_key'])) _setting_set('neshan_map_key', trim($b['neshan_map_key']));
  if (isset($b['google_maps_api_key'])) _setting_set('google_maps_api_key', trim($b['google_maps_api_key']));
  return ['ok' => true];
}, false, 'admin');



/* ==================== تعرفه و محاسبه عمومی هزینه سرویس مدارس ==================== */
function _default_service_pricing() {
  return [
    'mode' => _setting_get('service_pricing_mode', 'excel'),
    'vehicle_classes' => [
      ['key' => 'sedan', 'title' => 'سواری'],
      ['key' => 'van', 'title' => 'ون'],
    ],
    'rates' => [],
    'excel_valuation' => _load_excel_service_valuation(),
  ];
}
function _load_excel_service_valuation() {
  $path = dirname(__DIR__) . '/data/service_valuation_1405.json';
  if (!is_file($path)) return ['classes'=>[], 'traffic_by_district'=>[], 'distance_basis'=>'رفت و برگشت', 'year'=>''];
  $raw = @file_get_contents($path); $d = $raw ? json_decode($raw, true) : null;
  return is_array($d) ? $d : ['classes'=>[], 'traffic_by_district'=>[], 'distance_basis'=>'رفت و برگشت', 'year'=>''];
}
function _service_pricing_get() {
  $raw = _setting_get('service_pricing', '');
  $d = $raw ? json_decode($raw, true) : null;
  if (!is_array($d)) $d = [];
  $base = _default_service_pricing();
  $d['mode'] = in_array(($d['mode'] ?? _setting_get('service_pricing_mode','excel')), ['map','excel'], true) ? ($d['mode'] ?? 'excel') : 'excel';
  $d['vehicle_classes'] = (!empty($d['vehicle_classes']) && is_array($d['vehicle_classes'])) ? $d['vehicle_classes'] : $base['vehicle_classes'];
  $d['rates'] = (isset($d['rates']) && is_array($d['rates'])) ? $d['rates'] : [];
  $d['excel_valuation'] = _load_excel_service_valuation();
  if ($d['mode'] === 'excel' && !empty($d['excel_valuation']['classes'])) {
    $d['vehicle_classes'] = array_map(function($c){ return ['key'=>$c['key'], 'title'=>$c['title']]; }, $d['excel_valuation']['classes']);
  }
  return $d;
}
function _service_pricing_set($data) {
  $mode = in_array(($data['mode'] ?? 'excel'), ['map','excel'], true) ? $data['mode'] : 'excel';
  $vehicles = [];
  foreach (($data['vehicle_classes'] ?? []) as $v) {
    $key = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($v['key'] ?? ''));
    $title = trim((string)($v['title'] ?? ''));
    if ($key && $title) $vehicles[] = ['key' => $key, 'title' => $title];
  }
  if (!$vehicles) $vehicles = _default_service_pricing()['vehicle_classes'];
  $rates = [];
  foreach (($data['rates'] ?? []) as $districtId => $row) {
    $did = (string)(int)$districtId;
    foreach ((array)$row as $vehicleKey => $value) {
      $vk = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$vehicleKey);
      $rates[$did][$vk] = max(0, (float)$value);
    }
  }
  $out = ['mode'=>$mode, 'vehicle_classes'=>$vehicles, 'rates'=>$rates];
  _setting_set('service_pricing_mode', $mode);
  _setting_set('service_pricing', json_encode($out, JSON_UNESCAPED_UNICODE));
  return _service_pricing_get();
}
function _neshan_route($originLat, $originLng, $destLat, $destLng) {
  $key = _setting_get('neshan_api_key', '') ?: _setting_get('neshan_map_key', '');
  if (!$key) Http::error('کلید وب‌سرویس نشان در تنظیمات نقشه ثبت نشده است.', 400);
  $url = 'https://api.neshan.org/v4/direction?type=car&origin=' . rawurlencode($originLat . ',' . $originLng) . '&destination=' . rawurlencode($destLat . ',' . $destLng);
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Api-Key: '.$key,'Accept: application/json']]);
  $body = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($body === false || $code >= 400) Http::error('محاسبه مسیر با نشان ناموفق بود: ' . ($err ?: ('HTTP ' . $code)), 502);
  $data = json_decode($body, true); if (!is_array($data)) Http::error('پاسخ نشان قابل خواندن نیست.', 502); return $data;
}
function _decode_polyline_points($encoded) {
  $points=[];$index=0;$lat=0;$lng=0;$len=strlen($encoded);
  while($index<$len){$b=0;$shift=0;$result=0;do{$b=ord($encoded[$index++])-63;$result|=($b&0x1f)<<$shift;$shift+=5;}while($b>=0x20&&$index<$len);$dlat=(($result&1)?~($result>>1):($result>>1));$lat+=$dlat;$shift=0;$result=0;do{$b=ord($encoded[$index++])-63;$result|=($b&0x1f)<<$shift;$shift+=5;}while($b>=0x20&&$index<$len);$dlng=(($result&1)?~($result>>1):($result>>1));$lng+=$dlng;$points[]=['lat'=>$lat/1e5,'lng'=>$lng/1e5];}
  return $points;
}
function _extract_route_summary($data) {
  $route=$data['routes'][0]??null; if(!$route) Http::error('مسیری بین منزل و مدرسه یافت نشد.',502);
  $distance=null;$points=[];
  foreach(($route['legs']??[]) as $leg){
    if(isset($leg['distance']['value']))$distance=($distance??0)+(float)$leg['distance']['value']; elseif(isset($leg['distance']))$distance=($distance??0)+(float)$leg['distance'];
    foreach(($leg['steps']??[]) as $step){$poly=$step['polyline']??($step['encoded_polyline']??null);if(is_array($poly))$poly=$poly['points']??($poly['encodedPolyline']??null);if(is_string($poly)&&$poly!=='')$points=array_merge($points,_decode_polyline_points($poly));}
  }
  if($distance===null&&isset($route['distance']))$distance=is_array($route['distance'])?(float)($route['distance']['value']??0):(float)$route['distance'];
  $overview=$route['overview_polyline']['points']??($route['polyline']??null);if(!$points&&is_string($overview)&&$overview!=='')$points=_decode_polyline_points($overview);
  $points=array_values(array_filter($points,fn($p)=>isset($p['lat'],$p['lng'])));if(!$distance||$distance<=0)Http::error('فاصله مسیر از نشان دریافت نشد.',502);
  return ['distance_meters'=>(int)round($distance),'route_points'=>$points];
}
function _excel_service_price($valuation, $vehicleKey, $distanceRoundTripKm, $districtTitle='') {
  $class=null; foreach(($valuation['classes']??[]) as $c) if(($c['key']??'')===$vehicleKey){$class=$c;break;}
  if(!$class) Http::error('کلاس خودرو در فایل ارزش‌گذاری یافت نشد.',400);
  $distance=max(3.0,(float)$distanceRoundTripKm); $kmCeil=(int)ceil($distance); $baseKm=min(20,$kmCeil);
  $brackets=$class['brackets']??[]; $selected=null; foreach($brackets as $b) if((float)$b['distance_km']===$baseKm){$selected=$b;break;}
  if(!$selected){$selected=end($brackets);}
  $driver=(float)($selected['driver_1405_riyal']??0); $parent=(float)($selected['parent_monthly_riyal']??0);
  if($kmCeil>20){$driver+=(($kmCeil-20)*(float)($class['extra_driver_riyal_per_km']??0));}
  preg_match('/(\d+)/u',(string)$districtTitle,$m); $districtNo=$m[1]??''; $traffic=(float)($valuation['traffic_by_district'][$districtNo]??0);
  if($traffic>0 || $kmCeil>20){
    $driverAdj=$driver*(1+$traffic);
    $certificate=115740.74074074074; $monthly=$driverAdj+$certificate+($driverAdj*0.03)+($driverAdj*0.01)+($driverAdj*0.07); $parent=$monthly*(1+0.078);
  }
  return ['distance_roundtrip_km'=>$distance,'distance_bracket_km'=>$kmCeil,'rate_method'=>'excel','traffic_percent'=>$traffic*100,'driver_1405_riyal'=>$driver,'parent_monthly_riyal'=>$parent,'parent_monthly_toman'=>$parent/10,'vehicle_title'=>$class['title'],'distance_basis'=>$valuation['distance_basis']??'رفت و برگشت','year'=>$valuation['year']??''];
}
route('GET','/api/public/service-pricing',function($p,$b){return _service_pricing_get();},true);
route('GET','/api/admin/service-pricing',function($p,$b,$u){_require_role($u,['super_admin']);return _service_pricing_get();},false,'admin');
route('PUT','/api/admin/service-pricing',function($p,$b,$u){_require_role($u,['super_admin']);return _service_pricing_set($b);},false,'admin');
route('GET','/api/public/service-cost',function($p,$b){
  $schoolId=(int)($_GET['school_id']??0);$vehicleClass=preg_replace('/[^A-Za-z0-9_\-]/','',(string)($_GET['vehicle_class']??''));$homeLat=(float)($_GET['home_lat']??0);$homeLng=(float)($_GET['home_lng']??0);
  if(!$schoolId||!$vehicleClass||!$homeLat||!$homeLng)Http::error('مدرسه، کلاس خودرو و مکان منزل الزامی است.',400);
  $school=Db::one("SELECT s.id,s.name,s.lat,s.lng,s.district_id,d.title district_title FROM schools s LEFT JOIN districts d ON d.id=s.district_id WHERE s.id=? AND s.lat IS NOT NULL AND s.lng IS NOT NULL",[$schoolId]);
  if(!$school)Http::error('مدرسه یا موقعیت مدرسه یافت نشد.',404);
  $pricing=_service_pricing_get();$route=_extract_route_summary(_neshan_route($homeLat,$homeLng,(float)$school['lat'],(float)$school['lng']));$oneWayKm=$route['distance_meters']/1000;
  if($pricing['mode']==='excel'){
    $calc=_excel_service_price($pricing['excel_valuation'],$vehicleClass,$oneWayKm*2,$school['district_title']??'');
    $cost=(float)$calc['parent_monthly_toman'];
    return array_merge(['school_id'=>$schoolId,'school_name'=>$school['name'],'district_id'=>$school['district_id'],'district_title'=>$school['district_title'],'vehicle_class'=>$vehicleClass,'distance_meters'=>$route['distance_meters'],'distance_km'=>round($oneWayKm,2),'roundtrip_distance_km'=>round($oneWayKm*2,2),'cost'=>$cost,'route_points'=>$route['route_points']],$calc);
  }
  $vehicle=null;foreach($pricing['vehicle_classes'] as $v)if($v['key']===$vehicleClass)$vehicle=$v;if(!$vehicle)Http::error('کلاس خودرو معتبر نیست.',400);
  $rate=(float)($pricing['rates'][(string)$school['district_id']][$vehicleClass]??0);if($rate<=0)Http::error('برای ناحیه این مدرسه و کلاس خودروی انتخابی تعرفه‌ای ثبت نشده است.',400);
  return ['school_id'=>$schoolId,'school_name'=>$school['name'],'district_id'=>$school['district_id'],'district_title'=>$school['district_title'],'vehicle_class'=>$vehicleClass,'vehicle_title'=>$vehicle['title'],'distance_meters'=>$route['distance_meters'],'distance_km'=>round($oneWayKm,2),'rate_per_km'=>$rate,'cost'=>round($oneWayKm*$rate),'route_points'=>$route['route_points'],'rate_method'=>'map'];
},true);

/* ==================== برندینگ سایت (لوگو/هدر/عنوان) و سیاست‌های عمومی ==================== */
// عمومی — پنل و اپ برای نمایش لوگو/عنوان و بررسی مجاز بودن «لیست مدارس نماینده» به این نیاز دارند
route('GET', '/api/app-config', function ($p, $b) {
  return [
    'site_title' => _setting_get('site_title', 'سامانهٔ سرویس مدارس مشهد'),
    'header_subtitle' => _setting_get('header_subtitle', 'سازمان مدیریت و نظارت بر تاکسیرانی مشهد'),
    'logo_url' => _setting_get('logo_url', ''),
    'rep_can_view_school_list' => _setting_get('rep_can_view_school_list', '1') === '1',
  ];
}, true);

route('PUT', '/api/admin/app-config', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  if (isset($b['site_title'])) _setting_set('site_title', trim($b['site_title']));
  if (isset($b['header_subtitle'])) _setting_set('header_subtitle', trim($b['header_subtitle']));
  if (isset($b['rep_can_view_school_list'])) _setting_set('rep_can_view_school_list', $b['rep_can_view_school_list'] ? '1' : '0');
  return ['ok' => true];
}, false, 'admin');

// آپلود لوگوی سفارشی (به‌صورت فایل، نه base64)
route('POST', '/api/admin/app-config/logo', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  if (empty($_FILES['logo']) || ($_FILES['logo']['error'] ?? 1) !== 0) Http::error('فایلی ارسال نشد', 400);
  $path = Media::saveUploadedFile($_FILES['logo'], 'branding', 400, 90);
  if (!$path) Http::error('ذخیرهٔ لوگو ناموفق بود', 500);
  $url = '/api/media?path=' . urlencode($path);
  _setting_set('logo_url', $url);
  return ['ok' => true, 'logo_url' => $url];
}, false, 'admin');

/* ==================== پیامک (نگین) و بازیابی رمز عبور ==================== */
route('GET', '/api/admin/sms-config', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  return [
    'enabled' => (bool)_setting_get_json('sms_enabled', false),
    'username' => _setting_get_json('sms_username', ''),
    'password_set' => (bool)_setting_get_json('sms_password', ''),
    'line' => _setting_get_json('sms_line', ''),
  ];
}, false, 'admin');

route('PUT', '/api/admin/sms-config', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  if (isset($b['enabled'])) _setting_set_json('sms_enabled', (bool)$b['enabled']);
  if (isset($b['username'])) _setting_set_json('sms_username', trim($b['username']));
  if (!empty($b['password'])) _setting_set_json('sms_password', $b['password']);
  if (isset($b['line'])) _setting_set_json('sms_line', trim($b['line']));
  return ['ok' => true];
}, false, 'admin');

function _setting_get_json($key, $default = null) {
  $v = _setting_get($key, null);
  if ($v === null) return $default;
  $d = json_decode($v, true);
  return $d === null && $v !== 'null' ? $default : $d;
}
function _setting_set_json($key, $value) { _setting_set($key, json_encode($value, JSON_UNESCAPED_UNICODE)); }

function _ensure_password_reset_tables(){
  static $done = false; if ($done) return; $done = true;
  try { Db::run("CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY, user_type VARCHAR(20) NOT NULL, user_id INT NOT NULL,
    code VARCHAR(10) NOT NULL, expires_at DATETIME NOT NULL, used TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }
  catch (\Throwable $e) {}
  try { Db::run("CREATE TABLE IF NOT EXISTS sms_log (
    id INT AUTO_INCREMENT PRIMARY KEY, to_mobile VARCHAR(20) NOT NULL, body TEXT NULL, kind VARCHAR(40) NULL,
    status VARCHAR(20) NOT NULL, message_id VARCHAR(60) NULL, sent_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }
  catch (\Throwable $e) {}
}
function _mask_phone($phone) {
  $p = (string)$phone; if (strlen($p) < 6) return $p;
  return substr($p, 0, 4) . str_repeat('*', strlen($p) - 6) . substr($p, -2);
}

// درخواست کد بازیابی رمز عبور (نمایندهٔ شرکت) — پیامک از طریق وب‌سرویس نگین
route('POST', '/api/auth/forgot-password', function ($p, $b) {
  _ensure_password_reset_tables();
  $username = trim($b['username'] ?? ''); if (!$username) Http::error('نام کاربری را وارد کنید', 400);
  $row = Db::one("SELECT id,phone,full_name FROM company_users WHERE username=? AND is_active=1", [$username]);
  if (!$row || empty($row['phone'])) Http::error('کاربری با این نام کاربری و شمارهٔ تلفن ثبت‌شده یافت نشد', 404);
  if (!Sms::isEnabled()) Http::error('سرویس پیامک روی سامانه فعال نیست. با مدیر سامانه تماس بگیرید.', 503);
  $code = (string)random_int(10000, 99999);
  Db::run("INSERT INTO password_resets(user_type,user_id,code,expires_at) VALUES('company',?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))", [$row['id'], $code]);
  $r = Sms::send($row['phone'], "کد بازیابی رمز عبور سامانهٔ سرویس مدارس مشهد: $code\nاین کد تا ۱۰ دقیقه معتبر است.", 'password_reset');
  if (!$r['ok']) Http::error('ارسال پیامک ناموفق بود: ' . ($r['error'] ?? ''), 502);
  return ['ok' => true, 'phone_hint' => _mask_phone($row['phone'])];
}, true);

route('POST', '/api/auth/reset-password', function ($p, $b) {
  _ensure_password_reset_tables();
  $username = trim($b['username'] ?? ''); $code = trim($b['code'] ?? ''); $newPass = (string)($b['new_password'] ?? '');
  if (!$username || !$code || !$newPass) Http::error('همهٔ فیلدها الزامی است', 400);
  if (strlen($newPass) < 4) Http::error('رمز عبور باید حداقل ۴ کاراکتر باشد', 400);
  $row = Db::one("SELECT id FROM company_users WHERE username=?", [$username]);
  if (!$row) Http::error('کاربر یافت نشد', 404);
  $reset = Db::one("SELECT * FROM password_resets WHERE user_type='company' AND user_id=? AND code=? AND used=0 AND expires_at>=NOW() ORDER BY id DESC LIMIT 1", [$row['id'], $code]);
  if (!$reset) Http::error('کد وارد‌شده نامعتبر یا منقضی شده است', 400);
  Db::run("UPDATE company_users SET password_hash=? WHERE id=?", [password_hash($newPass, PASSWORD_BCRYPT), $row['id']]);
  Db::run("UPDATE password_resets SET used=1 WHERE id=?", [$reset['id']]);
  return ['ok' => true];
}, true);


// خروجی جامع اکسل/CSV از تمام اطلاعات وارد‌شده (شرکت‌ها، نواحی، نمایندگان، مدارس با همهٔ آیتم‌ها)
route('GET', '/api/admin/reports/full-export', function ($p, $b, $u) {
  _ensure_school_columns(); _ensure_school_field_defs_table();
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="full-report.csv"');
  echo "\xEF\xBB\xBF"; $out = fopen('php://output', 'w');

  fputcsv($out, ['گزارش جامع سامانهٔ سرویس مدارس مشهد', date('Y-m-d H:i')]); fputcsv($out, []);

  fputcsv($out, ['--- شرکت‌ها ---']);
  fputcsv($out, ['نام شرکت', 'مدیرعامل', 'تلفن', 'آدرس', 'وضعیت', 'تعداد مدارس', 'تعداد ثبت‌شده', 'تعداد نمایندگان']);
  foreach (Db::all("SELECT c.*, (SELECT COUNT(*) FROM schools s WHERE s.company_id=c.id) sc,
      (SELECT COUNT(*) FROM schools s WHERE s.company_id=c.id AND s.location_status='done') dc,
      (SELECT COUNT(*) FROM company_users cu WHERE cu.company_id=c.id) uc FROM companies c ORDER BY c.title") as $c) {
    fputcsv($out, [$c['title'], $c['manager_name'], $c['phone'], $c['address'], $c['is_active'] ? 'فعال' : 'غیرفعال', $c['sc'], $c['dc'], $c['uc']]);
  }
  fputcsv($out, []);

  fputcsv($out, ['--- نمایندگان شرکت‌ها ---']);
  fputcsv($out, ['نام کامل', 'نام کاربری', 'تلفن', 'شرکت', 'وضعیت', 'آخرین ورود']);
  foreach (Db::all("SELECT cu.*, c.title company_title FROM company_users cu JOIN companies c ON c.id=cu.company_id ORDER BY c.title, cu.full_name") as $r) {
    fputcsv($out, [$r['full_name'], $r['username'], $r['phone'], $r['company_title'], $r['is_active'] ? 'فعال' : 'غیرفعال', $r['last_login_at']]);
  }
  fputcsv($out, []);

  fputcsv($out, ['--- نواحی آموزش و پرورش ---']);
  fputcsv($out, ['عنوان ناحیه', 'تعداد مدارس', 'وضعیت']);
  foreach (Db::all("SELECT d.*, (SELECT COUNT(*) FROM schools s WHERE s.district_id=d.id) sc FROM districts d ORDER BY d.title") as $d) {
    fputcsv($out, [$d['title'], $d['sc'], $d['is_active'] ? 'فعال' : 'غیرفعال']);
  }
  fputcsv($out, []);

  $customDefs = Db::all("SELECT field_key, label FROM school_field_defs WHERE is_active=1 ORDER BY sort_order");
  fputcsv($out, ['--- مدارس (کامل) ---']);
  $headerRow = ['کد', 'نام مدرسه', 'ناحیه', 'شرکت', 'جنسیت', 'شیفت', 'مقطع', 'نوع مدرسه', 'ساعت شروع', 'ساعت پایان',
    'تعداد رانندگان', 'تعداد دانش‌آموز', 'آدرس', 'تلفن', 'مدیر مدرسه', 'عرض جغرافیایی', 'طول جغرافیایی', 'وضعیت', 'تاریخ ثبت'];
  foreach ($customDefs as $cd) $headerRow[] = $cd['label'];
  fputcsv($out, $headerRow);
  foreach (Db::all("SELECT s.*, d.title district_title, c.title company_title FROM schools s
      LEFT JOIN districts d ON d.id=s.district_id LEFT JOIN companies c ON c.id=s.company_id ORDER BY s.name") as $s) {
    $row = [$s['code'], $s['name'], $s['district_title'], $s['company_title'], $s['gender'], $s['shift'], $s['level'], $s['school_type'],
      $s['start_time'], $s['end_time'], $s['driver_count'], $s['student_count'], $s['address'], $s['phone'], $s['principal_name'],
      $s['lat'], $s['lng'], $s['location_status'] === 'done' ? 'ثبت‌شده' : 'باقی‌مانده', $s['location_recorded_at']];
    $cf = json_decode($s['custom_fields'] ?? '{}', true) ?: [];
    foreach ($customDefs as $cd) $row[] = $cf[$cd['field_key']] ?? '';
    fputcsv($out, $row);
  }
  fclose($out); exit;
}, false, 'admin');


route('GET', '/api/media', function ($p, $b, $u) {
  $rel = $_GET['path'] ?? ''; if (!$rel) Http::error('نامعتبر', 400);
  Media::serve($rel); exit;
}, true);
