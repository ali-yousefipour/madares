-- ==================== نرم‌افزار مدیریت شرکت‌های سرویس مدارس مشهد ====================
SET NAMES utf8mb4;

-- کاربران مدیر سایت (پنل ادمین)
CREATE TABLE IF NOT EXISTS admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'super_admin', -- super_admin | manager | viewer
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- نواحی آموزش و پرورش
CREATE TABLE IF NOT EXISTS districts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_district_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- مقاطع تحصیلی (قابل تعریف از تنظیمات سامانه)
CREATE TABLE IF NOT EXISTS education_levels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_level_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- آیتم‌های سفارشیِ قابل‌تعریف برای «مشخصات مدرسه» (بدون نیاز به تغییر کد، از پنل مدیریت اضافه/حذف می‌شوند)
CREATE TABLE IF NOT EXISTS school_field_defs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  field_key VARCHAR(60) NOT NULL UNIQUE,          -- کلید یکتای انگلیسی (خودکار از عنوان ساخته می‌شود)
  label VARCHAR(150) NOT NULL,                    -- عنوان نمایشی فارسی
  field_type VARCHAR(20) NOT NULL DEFAULT 'text',  -- text | number | select | checkbox | date
  options TEXT NULL,                              -- برای select: گزینه‌ها با کاما جدا شده
  editable_by_rep TINYINT(1) NOT NULL DEFAULT 0,   -- آیا نمایندهٔ شرکت هم می‌تواند این مقدار را ویرایش کند
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- شرکت‌های سرویس‌دهندهٔ مدارس
CREATE TABLE IF NOT EXISTS companies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  manager_name VARCHAR(150) NULL,      -- نام و نام‌خانوادگی مدیرعامل
  ceo_mobile VARCHAR(20) NULL,         -- تلفن همراه مدیرعامل
  phone VARCHAR(20) NULL,              -- تلفن ثابت شرکت
  address VARCHAR(400) NULL,
  lat DOUBLE NULL,                     -- موقعیت مکانی دفتر شرکت
  lng DOUBLE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- کاربران نمایندهٔ شرکت (لاگین اپ اندروید)
CREATE TABLE IF NOT EXISTS company_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(20) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  device_id VARCHAR(120) NULL,
  avatar_path VARCHAR(255) NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cu_company (company_id),
  CONSTRAINT fk_cu_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- مدارس
CREATE TABLE IF NOT EXISTS schools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,           -- کد یکتای ۷ رقمی مدرسه
  name VARCHAR(250) NOT NULL,
  district_id INT NULL,
  company_id INT NULL,                        -- شرکت سرویس‌دهنده
  gender VARCHAR(20) NULL,                     -- پسرانه/دخترانه/مختلط
  shift VARCHAR(20) NULL,                       -- صبح/عصر/دو شیفته
  level VARCHAR(30) NULL,                       -- مقطع تحصیلی
  school_type VARCHAR(20) NULL,                 -- دولتی/غیردولتی
  start_time VARCHAR(10) NULL,                  -- ساعت شروع فعالیت مدرسه
  end_time VARCHAR(10) NULL,                    -- ساعت پایان فعالیت مدرسه
  driver_count INT NULL,                        -- تعداد رانندگان به‌کارگیری‌شده برای سرویس مدرسه
  custom_fields JSON NULL,                       -- مقادیر آیتم‌های سفارشیِ قابل‌تعریف (school_field_defs)
  address VARCHAR(400) NULL,
  phone VARCHAR(20) NULL,
  principal_name VARCHAR(150) NULL,
  student_count INT NULL,
  lat DOUBLE NULL,
  lng DOUBLE NULL,
  gps_accuracy DOUBLE NULL,
  photo_path VARCHAR(255) NULL,                -- عکس مدرسه (فقط دوربین)
  location_status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | done
  location_recorded_at DATETIME NULL,
  edited_by INT NULL,                          -- company_users.id آخرین ویرایش‌کننده
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sc_district (district_id),
  INDEX idx_sc_company (company_id),
  INDEX idx_sc_status (location_status),
  CONSTRAINT fk_sc_district FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE SET NULL,
  CONSTRAINT fk_sc_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- تاریخچهٔ بازدید/ویرایش هر مدرسه توسط نماینده (برای داشبورد اپ و گزارش‌گیری ادمین)
CREATE TABLE IF NOT EXISTS school_visit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  company_user_id INT NOT NULL,
  action VARCHAR(20) NOT NULL DEFAULT 'edit',  -- create | edit
  lat DOUBLE NULL,
  lng DOUBLE NULL,
  gps_accuracy DOUBLE NULL,
  photo_path VARCHAR(255) NULL,
  note VARCHAR(400) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_svl_school (school_id),
  INDEX idx_svl_user (company_user_id, created_at),
  CONSTRAINT fk_svl_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_svl_user FOREIGN KEY (company_user_id) REFERENCES company_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- لاگ ورود نمایندگان (برای گزارش عملکرد)
CREATE TABLE IF NOT EXISTS company_user_logins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_user_id INT NOT NULL,
  device_model VARCHAR(150) NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cul_user (company_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- تنظیمات کلی سامانه (شعاع مجاز خطای GPS، سیاست‌های امنیتی و ...)
CREATE TABLE IF NOT EXISTS app_settings (
  `key` VARCHAR(80) PRIMARY KEY,
  value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- لاگ پیامک‌های ارسالی (وب‌سرویس نگین)
CREATE TABLE IF NOT EXISTS sms_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  to_mobile VARCHAR(20) NOT NULL,
  body TEXT NULL,
  kind VARCHAR(40) NULL,
  status VARCHAR(20) NOT NULL,
  message_id VARCHAR(60) NULL,
  sent_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- کدهای یک‌بارمصرف بازیابی رمز عبور (پیامکی)
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_type VARCHAR(20) NOT NULL,   -- company | admin
  user_id INT NOT NULL,
  code VARCHAR(10) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- کاربر مدیر پیش‌فرض توسط اسکریپت نصب (public/install.php) با رمز واقعی ساخته می‌شود.
-- آمار بازدید عمومی سایت
CREATE TABLE IF NOT EXISTS site_visit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  visitor_hash CHAR(64) NOT NULL,
  page_path VARCHAR(255) NOT NULL DEFAULT '/',
  referrer VARCHAR(500) NULL,
  user_agent VARCHAR(500) NULL,
  visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_svl_visited_at (visited_at),
  INDEX idx_svl_visitor_date (visitor_hash, visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
