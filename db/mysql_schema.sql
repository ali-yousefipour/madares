-- ==================== نرم‌افزار مدیریت شرکت‌های سرویس حمل و نقل دانش آموزی مشهد ====================
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


-- انواع مدرسه (قابل تعریف از تنظیمات سامانه و قابل تکمیل خودکار هنگام ایمپورت)
CREATE TABLE IF NOT EXISTS school_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_school_type_title (title)
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
  ceo_mobile VARCHAR(20) NULL,
  profile_completed TINYINT(1) NOT NULL DEFAULT 0,
  custom_fields JSON NULL,         -- تلفن همراه مدیرعامل
  phone VARCHAR(20) NULL,              -- تلفن ثابت شرکت
  address VARCHAR(400) NULL,
  lat DOUBLE NULL,                     -- موقعیت مکانی دفتر شرکت
  lng DOUBLE NULL,
  capacity_students INT NULL,                   -- ظرفیت مجاز سرویس‌دهی دانش‌آموزان
  allowed_min_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
  allowed_max_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
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
  start_time VARCHAR(10) NULL,                  -- شروع شیفت صبح / مقدار سازگار قدیمی
  end_time VARCHAR(10) NULL,                    -- پایان شیفت صبح یا عصر / مقدار سازگار قدیمی
  shift1_start_time VARCHAR(10) NULL,           -- شروع شیفت صبح
  shift1_end_time VARCHAR(10) NULL,             -- پایان شیفت صبح
  shift2_start_time VARCHAR(10) NULL,           -- شروع شیفت عصر
  shift2_end_time VARCHAR(10) NULL,             -- پایان شیفت عصر
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

-- نشست‌های احراز هویت برای کنترل خروج خودکار بر اساس عدم فعالیت
CREATE TABLE IF NOT EXISTS auth_sessions (
  session_id CHAR(64) PRIMARY KEY,
  scope VARCHAR(20) NOT NULL,
  user_id INT NOT NULL,
  last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auth_session_user(scope,user_id),
  INDEX idx_auth_session_activity(last_activity),
  INDEX idx_auth_session_expiry(expires_at)
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

-- ==================== سامانه ثبت و رسیدگی به شکایات ====================
CREATE TABLE IF NOT EXISTS complainants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mobile VARCHAR(20) NOT NULL UNIQUE,
  full_name VARCHAR(160) NULL,
  national_code VARCHAR(20) NULL,
  child_full_name VARCHAR(160) NULL,
  child_school_id INT NULL,
  is_profile_complete TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_complainant_school (child_school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS complaint_otps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mobile VARCHAR(20) NOT NULL,
  code VARCHAR(10) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_otp_mobile (mobile,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS complaints (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tracking_code VARCHAR(24) NOT NULL UNIQUE,
  complainant_id INT NOT NULL,
  school_id INT NOT NULL,
  company_id INT NULL,
  subject VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'new',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  assigned_admin_id INT NULL,
  referred_at DATETIME NULL,
  company_due_at DATETIME NULL,
  company_replied_at DATETIME NULL,
  closed_at DATETIME NULL,
  last_note_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_complaints_status (status),
  INDEX idx_complaints_company (company_id,status),
  INDEX idx_complaints_school (school_id),
  CONSTRAINT fk_complaint_person FOREIGN KEY (complainant_id) REFERENCES complainants(id) ON DELETE CASCADE,
  CONSTRAINT fk_complaint_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_complaint_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS complaint_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  complaint_id INT NOT NULL,
  author_type VARCHAR(20) NOT NULL,
  author_id INT NULL,
  note TEXT NOT NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cn_complaint (complaint_id,created_at),
  CONSTRAINT fk_cn_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS complaint_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  complaint_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  mime_type VARCHAR(100) NULL,
  file_size INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ca_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS complainant_messages (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  complainant_id INT NOT NULL,
  complaint_id INT NULL,
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  message_type VARCHAR(30) NOT NULL DEFAULT 'system',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cm_user_read (complainant_id,is_read,created_at),
  INDEX idx_cm_complaint (complaint_id),
  CONSTRAINT fk_cm_user FOREIGN KEY (complainant_id) REFERENCES complainants(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ==================== فیلدهای پویا برای مدارس و شرکت‌ها ====================
CREATE TABLE IF NOT EXISTS school_field_defs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  field_key VARCHAR(60) NOT NULL UNIQUE,
  label VARCHAR(150) NOT NULL,
  field_type VARCHAR(20) NOT NULL DEFAULT 'text',
  options TEXT NULL,
  editable_by_rep TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_field_defs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  field_key VARCHAR(60) NOT NULL UNIQUE,
  label VARCHAR(150) NOT NULL,
  field_type VARCHAR(20) NOT NULL DEFAULT 'text',
  options TEXT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- رزرو مدارس شرکت‌ها برای سال تحصیلی بعد
CREATE TABLE IF NOT EXISTS company_school_reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  school_id INT NOT NULL,
  academic_year VARCHAR(20) NOT NULL DEFAULT 'next',
  reserved_student_count INT NULL,
  created_by_company_user_id INT NULL,
  created_by_admin_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_company_school_year(company_id,school_id,academic_year),
  INDEX idx_csr_school(school_id,academic_year),
  CONSTRAINT fk_csr_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_csr_school FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE IF NOT EXISTS company_school_current_assignments (
  company_id INT NOT NULL,
  school_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(company_id, school_id),
  INDEX idx_csca_school(school_id),
  CONSTRAINT fk_csca_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_csca_school FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS company_school_reservation_status (
  company_id INT NOT NULL,
  academic_year VARCHAR(20) NOT NULL DEFAULT 'next',
  initialized_from_current TINYINT(1) NOT NULL DEFAULT 0,
  initialized_at DATETIME NULL,
  updated_by_company_user_id INT NULL,
  updated_by_admin_id INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(company_id, academic_year),
  CONSTRAINT fk_csrs_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- قابلیت‌های قابل روشن/خاموش شدن از تنظیمات سامانه
INSERT IGNORE INTO app_settings(`key`, value) VALUES
('feature_school_reservations','1'),
('feature_company_reservation_view','1'),
('feature_company_reservation_edit','1'),
('feature_company_capacity_view','1'),
('feature_company_capacity_warning','1'),
('feature_bulk_reservation_fill','1'),
('feature_complaints','1'),
('feature_public_cost_calculator','1'),
('feature_public_school_map','1'),
('feature_public_company_map','1'),
('feature_school_photo_upload','1'),
('feature_school_location_capture','1'),
('feature_sms_notifications','1'),
('feature_excel_export','1'),
('feature_pdf_export','1'),
('show_school_photos','0'),
('school_photo_as_map_marker','0'),
('session_idle_enabled','1'),
('session_idle_timeout_minutes','10'),
('session_idle_warning','1');

-- نقش‌های جدید admin_users.role: complaint_agent, complaint_manager
-- جداول تکمیلی شکایات در اجرای API به‌صورت idempotent ایجاد/ارتقا می‌شوند.

-- تعرفهٔ محاسبهٔ هزینهٔ سرویس مدارس بر اساس فایل مرجع تاکسیرانی (ردهٔ خودرو + بازهٔ مسافت + ضریب ترافیک ناحیه)
-- این جداول هم در نصب تازه (همین فایل) و هم به‌صورت idempotent هنگام اجرای API ایجاد می‌شوند.
CREATE TABLE IF NOT EXISTS service_tariff_vehicle_classes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(50) NOT NULL UNIQUE,
  title VARCHAR(250) NOT NULL,
  sheet_name VARCHAR(250) NULL,
  notes TEXT NULL,
  certificate_fee DECIMAL(16,2) NOT NULL DEFAULT 0,
  management_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
  info_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
  overhead_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
  insurance_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
  extra_driver_cost_per_km DECIMAL(16,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_tariff_bands (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_class_id INT NOT NULL,
  distance_from DECIMAL(7,2) NOT NULL DEFAULT 0,
  distance_to DECIMAL(7,2) NOT NULL,
  driver_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
  INDEX idx_tariff_band_vc (vehicle_class_id, distance_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_district_traffic (
  district_id INT PRIMARY KEY,
  traffic_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
