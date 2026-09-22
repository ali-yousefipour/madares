<?php
/* ==================== سامانهٔ مدیریت شرکت‌های سرویس حمل و نقل دانش آموزی مشهد — API ==================== */


/* ==================== XLSX writer استاندارد و فارسی ==================== */
function _xlsx_xml_escape($v){
  $s=(string)$v;
  // Normalize malformed UTF-8 before writing XML. Excel is strict about XML 1.0 bytes.
  if (function_exists('iconv')) {
    $clean=@iconv('UTF-8','UTF-8//IGNORE',$s);
    if ($clean !== false) $s=$clean;
  }
  // XML 1.0 permits TAB/LF/CR and printable Unicode only.
  $s=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',$s) ?? '';
  return htmlspecialchars($s, ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
}
function _xlsx_col($n){ $s=''; $n++; while($n){ $r=($n-1)%26; $s=chr(65+$r).$s; $n=intdiv($n-1,26); } return $s; }
function _xlsx_zip_store(array $files){
  $data=''; $central=''; $offset=0; $now=getdate();
  $dosTime=(($now['hours']<<11)|($now['minutes']<<5)|intdiv($now['seconds'],2));
  $dosDate=max(0,((($now['year']-1980)&127)<<9)|($now['mon']<<5)|$now['mday']);
  foreach($files as $name=>$content){
    $name=(string)$name; $content=(string)$content; $crc=crc32($content); if($crc<0)$crc+=4294967296;
    $nl=strlen($name); $len=strlen($content); $flags=0x0800; // UTF-8 filename flag
    $data.="PK\x03\x04".pack('v',20).pack('v',$flags).pack('v',0).pack('v',$dosTime).pack('v',$dosDate).pack('V',$crc).pack('V',$len).pack('V',$len).pack('v',$nl).pack('v',0).$name.$content;
    $central.="PK\x01\x02".pack('v',20).pack('v',20).pack('v',$flags).pack('v',0).pack('v',$dosTime).pack('v',$dosDate).pack('V',$crc).pack('V',$len).pack('V',$len).pack('v',$nl).pack('v',0).pack('v',0).pack('v',0).pack('v',0).pack('V',0).pack('V',$offset).$name;
    $offset=strlen($data);
  }
  return $data.$central."PK\x05\x06".pack('v',0).pack('v',0).pack('v',count($files)).pack('v',count($files)).pack('V',strlen($central)).pack('V',strlen($data)).pack('v',0);
}
function _xlsx_package(array $files){
  if(class_exists('ZipArchive')){
    $tmp=tempnam(sys_get_temp_dir(),'xlsx_');
    $z=new ZipArchive();
    if($z->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new Exception('ساخت فایل Excel ناموفق بود');
    foreach($files as $name=>$content){ $z->addFromString($name,(string)$content); }
    $z->close(); $bin=file_get_contents($tmp); @unlink($tmp); return $bin;
  }
  return _xlsx_zip_store($files);
}
function _xlsx_download($filename,array $headers,array $rows,$sheet='گزارش',$title=null,$subtitle=null,$reportNote=null){
  $title=$title ?: $sheet; $subtitle=$subtitle ?: 'سامانه مدیریت سرویس حمل و نقل دانش‌آموزی مشهد';
  $colCount=max(1,count($headers)); $lastCol=_xlsx_col($colCount-1);
  $safeSheet=function($s){$s=(string)$s; if(function_exists('mb_substr'))$s=mb_substr($s,0,31,'UTF-8');else $s=substr($s,0,31); return preg_replace('~[\\/:?\*\[\]]~u','-', $s) ?: 'گزارش';};
  $sheetName=_xlsx_xml_escape($safeSheet($sheet));
  $xmlRows='';
  $writeCell=function($ref,$val,$style) use (&$xmlRows){
    if($val===null||$val==='') return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t></t></is></c>';
    $v=(string)$val;
    // Keep phone codes, school codes and IDs that begin with zero as text.
    $isNum=is_numeric($val) && !preg_match('/^0\d+$/',$v) && !preg_match('/^09\d{9}$/',$v);
    if($isNum) return '<c r="'.$ref.'" s="'.$style.'"><v>'.preg_replace('/[^0-9eE+\-.]/','',$v).'</v></c>';
    return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'._xlsx_xml_escape($v).'</t></is></c>';
  };
  $xmlRows.='<row r="1" ht="32" customHeight="1"><c r="A1" s="1" t="inlineStr"><is><t xml:space="preserve">'._xlsx_xml_escape($title).'</t></is></c></row>';
  $xmlRows.='<row r="2" ht="23" customHeight="1"><c r="A2" s="2" t="inlineStr"><is><t xml:space="preserve">'._xlsx_xml_escape($subtitle).'</t></is></c></row>';
  $xmlRows.='<row r="3" ht="20" customHeight="1"><c r="A3" s="3" t="inlineStr"><is><t xml:space="preserve">تاریخ تهیه گزارش: '._xlsx_xml_escape(date('Y/m/d H:i')).'</t></is></c></row>';
  $xmlRows.='<row r="4" ht="20" customHeight="1"><c r="A4" s="3" t="inlineStr"><is><t xml:space="preserve">تعداد رکورد: '._xlsx_xml_escape(number_format(count($rows))).($reportNote?' — '._xlsx_xml_escape($reportNote):'').'</t></is></c></row>';
  $xmlRows.='<row r="5" ht="30" customHeight="1">'; foreach($headers as $ci=>$h){$xmlRows.=$writeCell(_xlsx_col($ci).'5',$h,4);} $xmlRows.='</row>';
  foreach($rows as $ri=>$row){$excelRow=$ri+6;$xmlRows.='<row r="'.$excelRow.'" ht="24" customHeight="1">'; foreach($headers as $ci=>$h){$xmlRows.=$writeCell(_xlsx_col($ci).$excelRow,$row[$ci]??'',5);} $xmlRows.='</row>';}
  $cols='';
  foreach($headers as $i=>$h){$len=function_exists('mb_strlen')?mb_strlen((string)$h,'UTF-8'):strlen((string)$h);$width=min(42,max(14,$len*1.55+5));$cols.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';}
  $lastRow=max(5,count($rows)+5);
  $sheetXml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
    '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
    '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:'.$lastCol.$lastRow.'"/>' .
    '<sheetViews><sheetView rightToLeft="1" showGridLines="0" workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A6" sqref="A6"/></sheetView></sheetViews>'.
    '<sheetFormatPr defaultRowHeight="24"/>'.$cols.
    '<sheetData>'.$xmlRows.'</sheetData>'.
    '<autoFilter ref="A5:'.$lastCol.$lastRow.'"/>'.
    '<mergeCells count="4"><mergeCell ref="A1:'.$lastCol.'1"/><mergeCell ref="A2:'.$lastCol.'2"/><mergeCell ref="A3:'.$lastCol.'3"/><mergeCell ref="A4:'.$lastCol.'4"/></mergeCells>'.
    '<printOptions horizontalCentered="1"/><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
  $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
    '<numFmts count="0"/><fonts count="3"><font><sz val="12"/><name val="B Nazanin"/><family val="2"/></font><font><b/><sz val="18"/><name val="B Nazanin"/><family val="2"/></font><font><b/><sz val="12"/><name val="B Nazanin"/><family val="2"/></font></fonts>'.
    '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="173653"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="F7B500"/><bgColor indexed="64"/></patternFill></fill></fills>'.
    '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="D8E1EB"/></left><right style="thin"><color rgb="D8E1EB"/></right><top style="thin"><color rgb="D8E1EB"/></top><bottom style="thin"><color rgb="D8E1EB"/></bottom><diagonal/></border></borders>'.
    '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="6"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="0" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="3" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf></cellXfs>'.
    '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
  $files=[
    '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
    '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
    'docProps/core.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'._xlsx_xml_escape($title).'</dc:title><dc:subject>گزارش مدیریتی</dc:subject><dc:creator>سامانه مدیریت سرویس حمل و نقل دانش‌آموزی مشهد</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.date('c').'</dcterms:created></cp:coreProperties>',
    'docProps/app.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Microsoft Excel</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop><HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs><TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>'._xlsx_xml_escape($sheet).'</vt:lpstr></vt:vector></TitlesOfParts></Properties>',
    'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets><sheet name="'.$sheetName.'" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
    'xl/styles.xml'=>$styles,
    'xl/worksheets/sheet1.xml'=>$sheetXml
  ];
  $binary=_xlsx_package($files);
  while(ob_get_level()>0){ @ob_end_clean(); }
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Content-Length: '.strlen($binary));
  header('Cache-Control: private, max-age=0, must-revalidate');
  header('Pragma: public');
  header('X-Content-Type-Options: nosniff');
  echo $binary; exit;
}

function _cfg() { static $c; return $c ?: ($c = require __DIR__ . '/../config.php'); }
function _ensure_auth_sessions_table(){
  static $done=false; if($done) return; $done=true;
  Db::run("CREATE TABLE IF NOT EXISTS auth_sessions (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function _issue_session_token(array $payload, $ttl){
  _ensure_auth_sessions_table();
  $sid=bin2hex(random_bytes(32)); $payload['sid']=$sid;
  $token=Jwt::sign($payload, _cfg()['jwt_secret'], $ttl);
  Db::run("INSERT INTO auth_sessions(session_id,scope,user_id,last_activity,expires_at,ip,user_agent) VALUES(?,?,?,NOW(),?,?,?)",[
    $sid,(string)($payload['scope']??'any'),(int)($payload['id']??0),date('Y-m-d H:i:s',time()+$ttl),_client_ip(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)
  ]);
  return $token;
}
function _session_idle_minutes(){ return max(5,min(120,(int)_setting_get('session_idle_timeout_minutes','10'))); }
function _auth_session_touch($payload){
  _ensure_auth_sessions_table(); $sid=(string)($payload['sid']??''); if($sid==='') return false;
  $row=Db::one("SELECT session_id,last_activity,expires_at,revoked FROM auth_sessions WHERE session_id=?",[$sid]);
  if(!$row || (int)$row['revoked']===1 || strtotime((string)$row['expires_at'])<time()) return false;
  $idleApplies=(($payload['scope']??'')!=='company' || ($payload['client']??'mobile')==='web');
  if($idleApplies && _setting_get('session_idle_enabled','1')==='1' && strtotime((string)$row['last_activity']) < time()-(_session_idle_minutes()*60)){
    Db::run("UPDATE auth_sessions SET revoked=1 WHERE session_id=?",[$sid]); return false;
  }
  Db::run("UPDATE auth_sessions SET last_activity=NOW() WHERE session_id=?",[$sid]);
  if(random_int(1,100)===1) Db::run("DELETE FROM auth_sessions WHERE revoked=1 OR expires_at<DATE_SUB(NOW(),INTERVAL 1 DAY)");
  return true;
}
function _auth_session_revoke($payload){$sid=(string)($payload['sid']??'');if($sid!==''){_ensure_auth_sessions_table();Db::run("UPDATE auth_sessions SET revoked=1 WHERE session_id=?",[$sid]);}}
function _issue_admin_token($row) {
  return _issue_session_token(['scope' => 'admin', 'id' => $row['id'], 'username' => $row['username'], 'full_name' => $row['full_name'], 'role' => $row['role'] ?? 'super_admin'], _cfg()['access_ttl']);
}
function _issue_company_token($row, $client='mobile') {
  return _issue_session_token(['scope' => 'company', 'id' => $row['id'], 'username' => $row['username'], 'company_id' => (int)$row['company_id'], 'full_name' => $row['full_name'], 'client'=>$client], _cfg()['access_ttl']);
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

function _client_ip(){ return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function _rate_limit($key, $max=8, $window=900){
  $dir = sys_get_temp_dir() . '/madares_rate_limit'; if (!is_dir($dir)) @mkdir($dir, 0700, true);
  $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key) . '.json';
  $now = time(); $data = ['start'=>$now,'count'=>0];
  if (is_file($file)) { $tmp = json_decode((string)@file_get_contents($file), true); if (is_array($tmp)) $data=$tmp; }
  if (($now - (int)$data['start']) >= $window) $data = ['start'=>$now,'count'=>0];
  if ((int)$data['count'] >= $max) {
    $retry = max(1, $window - ($now - (int)$data['start']));
    header('Retry-After: '.$retry);
    Http::error('تعداد درخواست‌ها بیش از حد مجاز است. لطفاً پس از '.ceil($retry/60).' دقیقه دوباره تلاش کنید.', 429);
  }
  $data['count'] = (int)$data['count'] + 1; @file_put_contents($file, json_encode($data), LOCK_EX);
}
function _rate_limit_clear($key){
  $file = sys_get_temp_dir() . '/madares_rate_limit/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key) . '.json';
  if (is_file($file)) @unlink($file);
}
function _valid_lat_lng($lat,$lng){ return is_numeric($lat) && is_numeric($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180; }
function _require_strong_password($pw){
  if (strlen((string)$pw) < 8) Http::error('رمز عبور باید حداقل ۸ کاراکتر باشد', 400);
}

// اطمینان از وجود ستون‌های جدید مدارس (سازگاری با نصب‌های قبلی‌تر این پروژه)
function _ensure_school_columns(){
  static $done = false; if ($done) return; $done = true;
  $cols = [
    'school_type' => "VARCHAR(20) NULL",
    'start_time' => "VARCHAR(10) NULL",
    'end_time' => "VARCHAR(10) NULL",
    'shift1_start_time' => "VARCHAR(10) NULL",
    'shift1_end_time' => "VARCHAR(10) NULL",
    'shift2_start_time' => "VARCHAR(10) NULL",
    'shift2_end_time' => "VARCHAR(10) NULL",
    'driver_count' => "INT NULL",
    'gps_accuracy' => "DOUBLE NULL",
  ];
  foreach ($cols as $name => $ddl) {
    try { if (!Db::one("SHOW COLUMNS FROM schools WHERE Field=?", [$name])) Db::run("ALTER TABLE schools ADD COLUMN `$name` $ddl"); }
    catch (\Throwable $e) {}
  }
}

/* ---------------- احراز هویت: مدیر سایت ---------------- */
/* ---------------- ورود یکپارچه: بر اساس نام کاربری وارد‌شده، به‌صورت خودکار تشخیص می‌دهد که
   حساب متعلق به مدیر سامانه (admin_users، شامل تمام نقش‌ها) است یا نمایندهٔ شرکت (company_users)؛
   دیگر نیازی به انتخاب دستی «ورود مدیران» یا «ورود شرکت‌ها» از سوی کاربر نیست. ---------------- */
route('POST', '/api/unified-login', function ($p, $b) {
  $u = trim($b['username'] ?? ''); $pw = (string)($b['password'] ?? '');
  if (!$u || !$pw) Http::error('نام کاربری و رمز عبور را وارد کنید', 400);
  _rate_limit('unified_login_' . _client_ip() . '_' . $u, 10, 900);
  _ensure_admin_role_column();
  $admin = Db::one("SELECT * FROM admin_users WHERE username=? AND is_active=1", [$u]);
  if ($admin && password_verify($pw, $admin['password_hash'])) {
    return ['type' => 'admin', 'token' => _issue_admin_token($admin), 'user' => ['id' => $admin['id'], 'username' => $admin['username'], 'full_name' => $admin['full_name'], 'role' => $admin['role'] ?? 'super_admin']];
  }
  _ensure_company_columns();
  $company = Db::one("SELECT cu.*, c.title company_title, c.is_active company_active, c.profile_completed FROM company_users cu JOIN companies c ON c.id=cu.company_id WHERE cu.username=?", [$u]);
  if ($company && password_verify($pw, $company['password_hash'])) {
    if (!$company['is_active']) Http::error('حساب کاربری شما غیرفعال شده است.', 403);
    if (!$company['company_active']) Http::error('شرکت شما غیرفعال شده است.', 403);
    Db::run("UPDATE company_users SET last_login_at=NOW(), device_id=? WHERE id=?", [$b['device_id'] ?? 'web', $company['id']]);
    try { Db::run("INSERT INTO company_user_logins(company_user_id,device_model,ip) VALUES(?,?,?)", [$company['id'], $b['device_model'] ?? 'browser', $_SERVER['REMOTE_ADDR'] ?? null]); } catch (\Throwable $e) {}
    return ['type' => 'company', 'token' => _issue_company_token($company, 'web'), 'user' => [
      'id' => $company['id'], 'username' => $company['username'], 'full_name' => $company['full_name'],
      'company_id' => (int)$company['company_id'], 'company_title' => $company['company_title'],
      'requires_company_profile_update' => !(bool)($company['profile_completed'] ?? 0),
    ]];
  }
  Http::error('نام کاربری یا رمز عبور اشتباه است', 401);
}, true);

route('POST', '/api/admin/login', function ($p, $b) {
  _ensure_admin_role_column();
  $u = trim($b['username'] ?? ''); $pw = (string)($b['password'] ?? '');
  if (!$u || !$pw) Http::error('نام کاربری و رمز عبور را وارد کنید', 400);
  _rate_limit('admin_login_' . _client_ip() . '_' . $u, 8, 900);
  $row = Db::one("SELECT * FROM admin_users WHERE username=? AND is_active=1", [$u]);
  if (!$row || !password_verify($pw, $row['password_hash'])) Http::error('نام کاربری یا رمز عبور اشتباه است', 401);
  return ['token' => _issue_admin_token($row), 'user' => ['id' => $row['id'], 'username' => $row['username'], 'full_name' => $row['full_name'], 'role' => $row['role'] ?? 'super_admin']];
}, true);

route('GET', '/api/admin/me', function ($p, $b, $u) {
  return ['id' => $u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role'] ?? 'super_admin'];
}, false, 'admin');

route('POST', '/api/session/ping', function ($p, $b, $u) {
  return ['ok'=>true,'timeout_minutes'=>_session_idle_minutes(),'warning'=>_setting_get('session_idle_warning','1')==='1'];
}, false, 'any');
route('POST', '/api/session/logout', function ($p, $b, $u) {
  _auth_session_revoke($u); return ['ok'=>true];
}, false, 'any');

/* ---- مدیریت کاربران مدیر سایت و سطوح دسترسی (فقط super_admin) ---- */
route('GET', '/api/admin/admin-users', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  return Db::all("SELECT id,username,full_name,role,is_active,created_at FROM admin_users ORDER BY id");
}, false, 'admin');

route('POST', '/api/admin/admin-users', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  $un = trim($b['username'] ?? ''); $pw = (string)($b['password'] ?? ''); $fn = trim($b['full_name'] ?? '');
  $role = in_array($b['role'] ?? '', ['super_admin', 'manager', 'viewer', 'complaint_agent', 'complaint_manager'], true) ? $b['role'] : 'viewer';
  if (!$un || !$pw || !$fn) Http::error('نام کاربری، رمز عبور و نام کامل الزامی است', 400);
  _require_strong_password($pw);
  if (Db::one("SELECT id FROM admin_users WHERE username=?", [$un])) Http::error('این نام کاربری قبلاً استفاده شده است', 409);
  $id = Db::insert("INSERT INTO admin_users(username,password_hash,full_name,role) VALUES(?,?,?,?)", [$un, password_hash($pw, PASSWORD_BCRYPT), $fn, $role]);
  return ['id' => $id];
}, false, 'admin');

route('PUT', '/api/admin/admin-users/{id}', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  $sets = []; $args = [];
  if (isset($b['full_name']) && trim($b['full_name']) !== '') { $sets[] = 'full_name=?'; $args[] = trim($b['full_name']); }
  if (in_array($b['role'] ?? '', ['super_admin', 'manager', 'viewer', 'complaint_agent', 'complaint_manager'], true)) { $sets[] = 'role=?'; $args[] = $b['role']; }
  if (isset($b['is_active'])) {
    if ((int)$p['id'] === (int)$u['id'] && !$b['is_active']) Http::error('نمی‌توانید حساب خودتان را غیرفعال کنید.', 400);
    $sets[] = 'is_active=?'; $args[] = (int)!!$b['is_active'];
  }
  if (!empty($b['password'])) {
    _require_strong_password($b['password']);
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
  _rate_limit('company_login_' . _client_ip() . '_' . $u, 8, 900);
  _ensure_company_columns();
  $row = Db::one("SELECT cu.*, c.title company_title, c.is_active company_active, c.profile_completed FROM company_users cu JOIN companies c ON c.id=cu.company_id WHERE cu.username=?", [$u]);
  if (!$row || !password_verify($pw, $row['password_hash'])) Http::error('نام کاربری یا رمز عبور اشتباه است', 401);
  if (!$row['is_active']) Http::error('حساب کاربری شما غیرفعال شده است.', 403);
  if (!$row['company_active']) Http::error('شرکت شما غیرفعال شده است.', 403);
  Db::run("UPDATE company_users SET last_login_at=NOW(), device_id=? WHERE id=?", [$b['device_id'] ?? null, $row['id']]);
  try { Db::run("INSERT INTO company_user_logins(company_user_id,device_model,ip) VALUES(?,?,?)", [$row['id'], $b['device_model'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null]); } catch (\Throwable $e) {}
  $client=(($b['device_id']??'')==='web'||($b['device_model']??'')==='browser')?'web':'mobile';
  return ['token' => _issue_company_token($row,$client), 'user' => [
    'id' => $row['id'], 'username' => $row['username'], 'full_name' => $row['full_name'],
    'company_id' => (int)$row['company_id'], 'company_title' => $row['company_title'],
    'requires_company_profile_update' => !(bool)($row['profile_completed'] ?? 0),
  ]];
}, true);

route('POST','/api/auth/password/forgot/request', function($p,$b){
  _ensure_complaints_tables();
  $mobile=_normalize_mobile($b['mobile']??'');
  if(!preg_match('/^09\d{9}$/',$mobile)) Http::error('شماره همراه معتبر وارد کنید.',400);
  _rate_limit('company_forgot_'._client_ip().'_'.$mobile,6,900);
  $row=Db::one("SELECT id,full_name,phone FROM company_users WHERE REPLACE(REPLACE(phone,' ',''),'-','')=? AND is_active=1 LIMIT 1",[$mobile]);
  if(!$row) Http::error('نماینده فعالی با این شماره همراه یافت نشد.',404);
  $code=(string)random_int(10000,99999);
  Db::run("INSERT INTO company_password_otps(company_user_id,mobile,code,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))",[$row['id'],$mobile,$code]);
  $delivery=_deliver_message($mobile,"کد بازیابی رمز عبور نماینده شرکت: $code\nاعتبار: ۱۰ دقیقه",'otp');
  if(empty($delivery['ok'])) Http::error('ارسال کد بازیابی انجام نشد. تنظیمات روش ارسال را بررسی کنید.',502);
  return ['ok'=>true,'expires_in'=>600,'resend_after'=>60,'sms_sent'=>(bool)$delivery['sms'],'bale_sent'=>(bool)$delivery['bale']];
}, true);

route('POST','/api/auth/password/forgot/reset', function($p,$b){
  _ensure_complaints_tables();
  $mobile=_normalize_mobile($b['mobile']??''); $code=_normalize_otp_code($b['code']??''); $new=(string)($b['new_password']??'');
  _require_strong_password($new);
  $otp=Db::one("SELECT * FROM company_password_otps WHERE mobile=? AND code=? AND used=0 AND expires_at>=NOW() ORDER BY id DESC LIMIT 1",[$mobile,$code]);
  if(!$otp) Http::error('کد بازیابی نامعتبر یا منقضی شده است.',400);
  Db::run("UPDATE company_password_otps SET used=1 WHERE id=?",[$otp['id']]);
  Db::run("UPDATE company_users SET password_hash=? WHERE id=?",[password_hash($new,PASSWORD_BCRYPT),$otp['company_user_id']]);
  _rate_limit_clear('company_forgot_'._client_ip().'_'.$mobile);
  return ['ok'=>true];
}, true);

function _ensure_company_user_avatar_column(){
  static $done = false; if ($done) return; $done = true;
  try { if (!Db::one("SHOW COLUMNS FROM company_users WHERE Field='avatar_path'")) Db::run("ALTER TABLE company_users ADD COLUMN avatar_path VARCHAR(255) NULL"); }
  catch (\Throwable $e) {}
}

route('GET', '/api/auth/me', function ($p, $b, $u) {
  _ensure_company_user_avatar_column();
  $row = Db::one("SELECT cu.id,cu.username,cu.full_name,cu.phone,cu.avatar_path,c.id company_id,c.title company_title,c.profile_completed
    FROM company_users cu JOIN companies c ON c.id=cu.company_id WHERE cu.id=?", [$u['id']]);
  if (!$row) Http::error('کاربر یافت نشد', 404);
  if ($row['avatar_path']) $row['avatar_url'] = '/api/media?path=' . urlencode($row['avatar_path']);
  $row['requires_company_profile_update'] = !(bool)($row['profile_completed'] ?? 0);
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
  _require_strong_password($new);
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
  _ensure_company_columns(); _ensure_company_field_defs_table();
  $sets = []; $args = [];
  foreach (['manager_name', 'ceo_mobile', 'phone', 'address'] as $f) {
    if (array_key_exists($f, $b)) { $sets[] = "$f=?"; $args[] = trim((string)$b[$f]) ?: null; }
  }
  if (isset($b['custom_fields']) && is_array($b['custom_fields'])) { $sets[] = 'custom_fields=?'; $args[] = json_encode($b['custom_fields'], JSON_UNESCAPED_UNICODE); }
  if (isset($b['lat']) || isset($b['lng'])) {
    if (!isset($b['lat'], $b['lng']) || !_valid_lat_lng($b['lat'], $b['lng'])) Http::error('مختصات شرکت نامعتبر است', 400);
    $sets[] = 'lat=?'; $args[] = $b['lat']; $sets[] = 'lng=?'; $args[] = $b['lng'];
  }
  $manager = trim((string)($b['manager_name'] ?? ''));
  $mobile = trim((string)($b['ceo_mobile'] ?? ''));
  $phone = trim((string)($b['phone'] ?? ''));
  $address = trim((string)($b['address'] ?? ''));
  if ($manager && $mobile && $phone && $address) { $sets[] = 'profile_completed=1'; }
  if (!$sets) Http::error('داده‌ای برای بروزرسانی ارسال نشده', 400);
  $args[] = $u['company_id'];
  Db::run("UPDATE companies SET " . implode(',', $sets) . " WHERE id=?", $args);
  return ['ok' => true, 'profile_completed' => (bool)($manager && $mobile && $phone && $address)];
}, false, 'company');

/* ==================== نواحی آموزش و پرورش ==================== */
route('GET', '/api/admin/districts', fn($p, $b, $u) => Db::all(
  "SELECT d.*, (SELECT COUNT(*) FROM schools s WHERE s.district_id=d.id) schools_count
   FROM districts d ORDER BY d.title"), false, 'admin');

route('GET', '/api/districts', fn($p, $b) => Db::all("SELECT id,title FROM districts WHERE is_active=1 ORDER BY title"), true);
route('GET', '/api/public/districts', fn($p, $b) => Db::all("SELECT id,title FROM districts WHERE is_active=1 ORDER BY title"), true);

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


function _ensure_education_level_value($title){
  _ensure_education_levels_table();
  $title = trim((string)$title); if ($title === '') return null;
  $row = Db::one("SELECT id FROM education_levels WHERE title=?", [$title]);
  if ($row) { try { Db::run("UPDATE education_levels SET is_active=1 WHERE id=?", [$row['id']]); } catch (\Throwable $e) {} return $title; }
  try { Db::insert("INSERT INTO education_levels(title,sort_order,is_active) VALUES(?,999,1)", [$title]); } catch (\Throwable $e) {}
  return $title;
}

/* ==================== نوع مدرسه (قابل تعریف) ==================== */
function _ensure_school_types_table(){
  static $done = false; if ($done) return; $done = true;
  try {
    Db::run("CREATE TABLE IF NOT EXISTS school_types (
      id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100) NOT NULL, sort_order INT NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_school_type_title (title)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cnt = Db::one("SELECT COUNT(*) n FROM school_types")['n'] ?? 0;
    if ($cnt == 0) foreach (['دولتی','غیردولتی'] as $i=>$t) Db::run("INSERT IGNORE INTO school_types(title,sort_order) VALUES(?,?)", [$t,$i]);
  } catch (\Throwable $e) {}
}
function _ensure_school_type_value($title){
  _ensure_school_types_table();
  $title = trim((string)$title); if ($title === '') return null;
  $row = Db::one("SELECT id FROM school_types WHERE title=?", [$title]);
  if ($row) { try { Db::run("UPDATE school_types SET is_active=1 WHERE id=?", [$row['id']]); } catch (\Throwable $e) {} return $title; }
  try { Db::insert("INSERT INTO school_types(title,sort_order,is_active) VALUES(?,999,1)", [$title]); } catch (\Throwable $e) {}
  return $title;
}
route('GET', '/api/school-types', function ($p, $b) {
  _ensure_school_types_table();
  return Db::all("SELECT id,title FROM school_types WHERE is_active=1 ORDER BY sort_order, title");
}, true);
route('GET', '/api/admin/school-types', function ($p, $b, $u) {
  _ensure_school_types_table();
  return Db::all("SELECT * FROM school_types ORDER BY sort_order, title");
}, false, 'admin');
route('POST', '/api/admin/school-types', function ($p, $b, $u) {
  _block_viewer($u); _ensure_school_types_table();
  $t = trim($b['title'] ?? ''); if (!$t) Http::error('عنوان نوع مدرسه الزامی است', 400);
  if (Db::one("SELECT id FROM school_types WHERE title=?", [$t])) Http::error('این نوع مدرسه قبلاً ثبت شده است', 409);
  $id = Db::insert("INSERT INTO school_types(title,sort_order) VALUES(?,?)", [$t, (int)($b['sort_order'] ?? 0)]);
  return ['id'=>$id];
}, false, 'admin');
route('PUT', '/api/admin/school-types/{id}', function ($p, $b, $u) {
  _block_viewer($u); _ensure_school_types_table();
  $t = trim($b['title'] ?? ''); if (!$t) Http::error('عنوان نوع مدرسه الزامی است', 400);
  Db::run("UPDATE school_types SET title=?, sort_order=?, is_active=? WHERE id=?", [$t,(int)($b['sort_order'] ?? 0),isset($b['is_active'])?(int)!!$b['is_active']:1,(int)$p['id']]);
  return ['ok'=>true];
}, false, 'admin');
route('DELETE', '/api/admin/school-types/{id}', function ($p, $b, $u) {
  _block_viewer($u); _ensure_school_types_table();
  Db::run("DELETE FROM school_types WHERE id=?", [(int)$p['id']]);
  return ['ok'=>true];
}, false, 'admin');

/* ==================== شرکت‌های سرویس‌دهنده ==================== */
function _ensure_company_columns(){
  static $done = false; if ($done) return; $done = true;
  foreach (['ceo_mobile' => "VARCHAR(20) NULL", 'lat' => "DOUBLE NULL", 'lng' => "DOUBLE NULL", 'profile_completed' => "TINYINT(1) NOT NULL DEFAULT 0", 'custom_fields' => "JSON NULL"] as $col => $ddl) {
    try { if (!Db::one("SHOW COLUMNS FROM companies WHERE Field=?", [$col])) Db::run("ALTER TABLE companies ADD COLUMN `$col` $ddl"); }
    catch (\Throwable $e) {}
  }
}




function _ensure_company_field_defs_table(){
  _ensure_company_columns();
  static $done=false; if($done) return; $done=true;
  try {
    Db::run("CREATE TABLE IF NOT EXISTS company_field_defs (
      id INT AUTO_INCREMENT PRIMARY KEY, field_key VARCHAR(60) NOT NULL UNIQUE, label VARCHAR(150) NOT NULL,
      field_type VARCHAR(20) NOT NULL DEFAULT 'text', options TEXT NULL, is_required TINYINT(1) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (\Throwable $e) {}
}
const BUILTIN_COMPANY_FIELDS = [
  ['title','نام شرکت'], ['manager_name','نام و نام خانوادگی مدیرعامل'], ['ceo_mobile','تلفن همراه مدیرعامل'],
  ['phone','تلفن ثابت شرکت'], ['address','آدرس شرکت'], ['lat','عرض جغرافیایی'], ['lng','طول جغرافیایی']
];
route('GET', '/api/company-field-defs', function($p,$b){ _ensure_company_field_defs_table(); return Db::all("SELECT field_key,label,field_type,options,is_required FROM company_field_defs WHERE is_active=1 ORDER BY sort_order,id"); }, true);
route('GET', '/api/admin/company-builtin-fields', function($p,$b,$u){
  $meta=json_decode(_setting_get('company_builtin_field_meta','{}'),true)?:[];
  return array_map(fn($f)=>['field_key'=>$f[0],'label'=>$meta[$f[0]]['label']??$f[1],'is_required'=>(bool)($meta[$f[0]]['is_required']??in_array($f[0],['title','manager_name','ceo_mobile','phone','address'],true)),'is_active'=>(bool)($meta[$f[0]]['is_active']??true)], BUILTIN_COMPANY_FIELDS);
}, false, 'admin');
route('PUT', '/api/admin/company-builtin-fields', function($p,$b,$u){
  _block_viewer($u); $meta=json_decode(_setting_get('company_builtin_field_meta','{}'),true)?:[];
  foreach(($b['fields']??[]) as $key=>$conf){ if(!in_array($key,array_column(BUILTIN_COMPANY_FIELDS,0),true)) continue; $meta[$key]=['label'=>trim($conf['label']??''),'is_required'=>(bool)($conf['is_required']??false),'is_active'=>(bool)($conf['is_active']??true)]; }
  _setting_set('company_builtin_field_meta', json_encode($meta, JSON_UNESCAPED_UNICODE)); return ['ok'=>true];
}, false, 'admin');
route('GET', '/api/admin/company-field-defs', function($p,$b,$u){ _ensure_company_field_defs_table(); return Db::all("SELECT * FROM company_field_defs ORDER BY sort_order,id"); }, false, 'admin');
route('POST', '/api/admin/company-field-defs', function($p,$b,$u){
  _block_viewer($u); _ensure_company_field_defs_table(); $label=trim($b['label']??''); if(!$label) Http::error('عنوان فیلد الزامی است',400);
  $type=in_array($b['field_type']??'',['text','number','select','checkbox','date'],true)?$b['field_type']:'text'; $key=_slugify_field_key('company_'.$label);
  $id=Db::insert("INSERT INTO company_field_defs(field_key,label,field_type,options,is_required,sort_order) VALUES(?,?,?,?,?,?)",[$key,$label,$type,$b['options']??null,(int)!!($b['is_required']??false),(int)($b['sort_order']??0)]); return ['id'=>$id,'field_key'=>$key];
}, false, 'admin');
route('PUT', '/api/admin/company-field-defs/{id}', function($p,$b,$u){
  _block_viewer($u); _ensure_company_field_defs_table(); $label=trim($b['label']??''); if(!$label) Http::error('عنوان فیلد الزامی است',400);
  $type=in_array($b['field_type']??'',['text','number','select','checkbox','date'],true)?$b['field_type']:'text';
  Db::run("UPDATE company_field_defs SET label=?,field_type=?,options=?,is_required=?,sort_order=?,is_active=? WHERE id=?",[$label,$type,$b['options']??null,(int)!!($b['is_required']??false),(int)($b['sort_order']??0),isset($b['is_active'])?(int)!!$b['is_active']:1,(int)$p['id']]); return ['ok'=>true];
}, false, 'admin');
route('DELETE', '/api/admin/company-field-defs/{id}', function($p,$b,$u){ _block_viewer($u); _ensure_company_field_defs_table(); Db::run("DELETE FROM company_field_defs WHERE id=?",[(int)$p['id']]); return ['ok'=>true]; }, false, 'admin');

function _read_table_upload_rows($tmp, $originalName='') {
  $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
  $rows = [];
  if ($ext === 'csv') {
    $fh = fopen($tmp, 'r');
    if (!$fh) Http::error('امکان خواندن فایل CSV وجود ندارد', 400);
    $i = 0;
    while (($data = fgetcsv($fh)) !== false) {
      if ($i === 0 && isset($data[0])) $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
      $rows[] = $data; $i++;
    }
    fclose($fh);
    return $rows;
  }
  Xlsx::eachRow($tmp, function($cells) use (&$rows){
    if (!$cells) { $rows[] = []; return; }
    $max = max(array_keys($cells)); $row = [];
    for ($i=0; $i<=$max; $i++) $row[$i] = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
    $rows[] = $row;
  });
  return $rows;
}
function _norm_header($s){
  $s = trim((string)$s);
  $s = str_replace(["ي","ك","ة"],["ی","ک","ه"],$s);
  $s = preg_replace('/\s+/u',' ', $s);
  return $s;
}
function _cell_by_headers($row, $header, $names) {
  foreach ((array)$names as $nm) {
    $key = _norm_header($nm);
    if (isset($header[$key])) { $i = $header[$key]; return trim((string)($row[$i] ?? '')); }
  }
  return '';
}
function _split_import_items($s){
  $s = trim((string)$s); if ($s==='') return [];
  $parts = preg_split('/[؛;،,\n]+/u', $s);
  return array_values(array_filter(array_map('trim', $parts), fn($x)=>$x!==''));
}
function _safe_username_base($text){
  $text = trim((string)$text);
  $ascii = preg_replace('/[^a-zA-Z0-9_]+/', '', $text);
  if ($ascii !== '') return substr($ascii,0,40);
  return 'rep' . substr(abs(crc32($text)),0,8);
}
function _upsert_company_user($companyId, $name, $phone, $username, $password, &$createdUsers, &$updatedUsers){
  $name = trim((string)$name);
  $phone = trim((string)$phone);
  $username = trim((string)$username);
  $password = (string)$password;
  if ($name === '' || $username === '') return false;
  $exists = Db::one("SELECT id FROM company_users WHERE username=?", [$username]);
  if ($exists) {
    Db::run("UPDATE company_users SET company_id=?, full_name=?, phone=?, is_active=1 WHERE id=?", [$companyId,$name,$phone ?: null,$exists['id']]);
    $updatedUsers[] = ['full_name'=>$name,'username'=>$username,'phone'=>$phone];
  } else {
    Db::run("INSERT INTO company_users(company_id,username,password_hash,full_name,phone,is_active) VALUES(?,?,?,?,?,1)", [$companyId,$username,password_hash($password,PASSWORD_BCRYPT),$name,$phone ?: null]);
    $createdUsers[] = ['full_name'=>$name,'username'=>$username,'password'=>$password,'phone'=>$phone];
  }
  return true;
}
function _clean_mobile_username($phone){
  $digits = preg_replace('/\D+/', '', (string)$phone);
  if (preg_match('/^98(9\d{9})$/', $digits, $m)) return '0'.$m[1];
  if (preg_match('/^9\d{9}$/', $digits)) return '0'.$digits;
  if (preg_match('/^09\d{9}$/', $digits)) return $digits;
  return $digits;
}
function _parse_rep_item($item, $companyId, &$createdUsers, &$updatedUsers=[]){
  $item = trim((string)$item); if ($item==='') return;
  $bits = array_map('trim', preg_split('/[|\/]+/u', $item));
  $name = $bits[0] ?? '';
  $phone = $bits[1] ?? '';
  $username = $bits[2] ?? '';
  $password = $bits[3] ?? '';
  if (!$name) return;
  if (!$phone && preg_match('/09\d{9}/', $item, $m)) $phone = $m[0];
  if (!$username) $username = $phone ? _clean_mobile_username($phone) : (_safe_username_base($name) . '_' . $companyId);
  if (!$password || mb_strlen($password) < 8) $password = 'Mdr@' . random_int(100000,999999);
  _upsert_company_user($companyId, $name, $phone, $username, $password, $createdUsers, $updatedUsers);
}
function _assign_schools_to_company($schoolsText, $companyId){
  _ensure_company_capacity_tables();
  $assigned = 0; $missing = [];
  foreach (_split_import_items($schoolsText) as $school) {
    $q = trim($school); if ($q==='') continue;
    $row = Db::one("SELECT id FROM schools WHERE code=? OR name=? OR name LIKE ? ORDER BY (code=?) DESC, (name=?) DESC LIMIT 1", [$q,$q,'%'.$q.'%',$q,$q]);
    if ($row) {
      // فیلد company_id برای سازگاری با نسخه‌های قبلی حفظ می‌شود؛ جدول زیر امکان چندشرکتی بودن پوشش فعلی را فراهم می‌کند.
      Db::run("UPDATE schools SET company_id=COALESCE(company_id, ?) WHERE id=?", [$companyId,$row['id']]);
      Db::run("INSERT IGNORE INTO company_school_current_assignments(company_id,school_id) VALUES(?,?)", [$companyId,$row['id']]);
      $assigned++;
    }
    else $missing[] = $q;
  }
  return [$assigned,$missing];
}

route('GET', '/api/admin/companies/import-template', function($p,$b,$u){
  _block_viewer($u);
  $headers=['نام شرکت','مدیر عامل','تلفن','آدرس','ظرفیت دانش آموز','درصد کاهش مجاز','درصد افزایش مجاز','مدارس','ثبت شده','نمایندگان'];
  $sample=['شرکت نمونه','علی رضایی','05131234567','مشهد، ...','500','10','15','مدرسه نمونه ۱؛ مدرسه نمونه ۲','فعال','رضا احمدی|09151234567|reza.ahmadi|Mdr@123456؛ مریم کریمی|09151234568'];
  _xlsx_download('companies-import-template.xlsx',$headers,[$sample],'قالب ورود شرکت‌ها','قالب نمونه ایمپورت شرکت‌ها','سازمان مدیریت و نظارت بر تاکسیرانی شهرداری مشهد مقدس');
}, false, 'admin');

route('POST', '/api/admin/companies/import', function($p,$b,$u){
  _block_viewer($u); _ensure_company_columns(); _ensure_company_capacity_tables();
  if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== 0) Http::error('فایل اکسل یا CSV ارسال نشد', 400);
  $name = $_FILES['file']['name'] ?? '';
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ['xlsx','csv'], true)) Http::error('فقط فایل xlsx یا csv مجاز است', 400);
  if (($_FILES['file']['size'] ?? 0) > 5*1024*1024) Http::error('حجم فایل بیش از ۵ مگابایت است', 413);
  $rows = _read_table_upload_rows($_FILES['file']['tmp_name'], $name);
  if (count($rows) < 2) Http::error('فایل فاقد داده قابل ایمپورت است', 400);
  $header = [];
  foreach ($rows[0] as $i=>$h) { $h=_norm_header($h); if ($h!=='') $header[$h]=$i; }
  $inserted=0; $updated=0; $skipped=0; $schoolAssigned=0; $createdUsers=[]; $updatedUsers=[]; $errors=[]; $missingSchools=[];
  $createManagerUser = !empty($_POST['create_manager_user']) && in_array((string)$_POST['create_manager_user'], ['1','true','on','yes'], true);
  for ($r=1; $r<count($rows); $r++) {
    $row = $rows[$r];
    $title = _cell_by_headers($row,$header,['نام شرکت','شرکت','title','company']);
    if ($title==='') { $skipped++; continue; }
    $manager = _cell_by_headers($row,$header,['مدیر عامل','مدیرعامل','نام مدیرعامل','manager_name']);
    $phone = _cell_by_headers($row,$header,['تلفن','تلفن شرکت','phone']);
    $address = _cell_by_headers($row,$header,['آدرس','address']);
    $capacity = (int)_cell_by_headers($row,$header,['ظرفیت دانش آموز','ظرفیت دانش‌آموز','capacity_students','capacity']);
    $minPct = (float)_cell_by_headers($row,$header,['درصد کاهش مجاز','کاهش مجاز','allowed_min_percent','min_percent']);
    $maxPct = (float)_cell_by_headers($row,$header,['درصد افزایش مجاز','افزایش مجاز','allowed_max_percent','max_percent']);
    $schools = _cell_by_headers($row,$header,['مدارس','مدرسه','لیست مدارس','schools']);
    $registered = _cell_by_headers($row,$header,['ثبت شده','ثبت‌شده','وضعیت','status']);
    $reps = _cell_by_headers($row,$header,['نمایندگان','نماینده','representatives','users']);
    try {
      $isActive = 1;
      if ($registered !== '') {
        $v = mb_strtolower(str_replace([' ', '‌'], '', $registered));
        if (in_array($v, ['0','خیر','نه','غیرفعال','inactive','false'], true)) $isActive = 0;
      }
      $exists = Db::one("SELECT id FROM companies WHERE title=?", [$title]);
      if ($exists) {
        Db::run("UPDATE companies SET manager_name=?, phone=?, address=COALESCE(NULLIF(?,''),address), capacity_students=?, allowed_min_percent=?, allowed_max_percent=?, is_active=? WHERE id=?", [$manager ?: null,$phone ?: null,$address,$capacity,$minPct,$maxPct,$isActive,$exists['id']]);
        $companyId = (int)$exists['id']; $updated++;
      } else {
        $companyId = Db::insert("INSERT INTO companies(title,manager_name,phone,address,capacity_students,allowed_min_percent,allowed_max_percent,is_active) VALUES(?,?,?,?,?,?,?,?)", [$title,$manager ?: null,$phone ?: null,$address ?: null,$capacity,$minPct,$maxPct,$isActive]);
        $inserted++;
      }
      if ($createManagerUser) {
        $managerUsername = _clean_mobile_username($phone);
        if ($manager !== '' && $managerUsername !== '') {
          _upsert_company_user($companyId, $manager, $phone, $managerUsername, '12345678', $createdUsers, $updatedUsers);
        } else {
          $errors[] = 'ردیف '.($r+1).': برای ساخت کاربری مدیرعامل، نام مدیرعامل و موبایل/تلفن معتبر لازم است.';
        }
      }
      if ($schools !== '') { [$cnt,$miss] = _assign_schools_to_company($schools,$companyId); $schoolAssigned += $cnt; foreach($miss as $m) $missingSchools[] = ['row'=>$r+1,'school'=>$m,'company'=>$title]; }
      foreach (_split_import_items($reps) as $rep) _parse_rep_item($rep, $companyId, $createdUsers, $updatedUsers);
    } catch (Throwable $e) { $skipped++; $errors[] = 'ردیف '.($r+1).': '.$e->getMessage(); }
  }
  return ['ok'=>true,'inserted'=>$inserted,'updated'=>$updated,'skipped'=>$skipped,'school_assigned'=>$schoolAssigned,'created_users'=>$createdUsers,'updated_users'=>$updatedUsers,'default_manager_password'=>$createManagerUser ? '12345678' : null,'missing_schools'=>array_slice($missingSchools,0,50),'errors'=>array_slice($errors,0,30)];
}, false, 'admin');

route('GET', '/api/admin/companies', function($p, $b, $u) {
  _ensure_company_columns();
  return Db::all(
  "SELECT c.*, 
      (SELECT COUNT(*) FROM schools s WHERE s.company_id=c.id) schools_count,
      (SELECT COUNT(*) FROM schools s WHERE s.company_id=c.id AND s.location_status='done') done_count,
      (SELECT COUNT(*) FROM company_users cu WHERE cu.company_id=c.id) users_count
   FROM companies c ORDER BY c.title");
}, false, 'admin');

route('GET', '/api/admin/companies/export', function($p,$b,$u){
  _ensure_company_columns(); _ensure_company_field_defs_table();
  $q=trim((string)($_GET['q']??'')); $args=[]; $where='';
  if($q!==''){$where='WHERE c.title LIKE ? OR c.manager_name LIKE ? OR c.ceo_mobile LIKE ? OR c.phone LIKE ? OR c.address LIKE ?';$qq='%'.$q.'%';$args=[$qq,$qq,$qq,$qq,$qq];}
  $rows=Db::all("SELECT c.*, (SELECT COUNT(*) FROM schools s WHERE s.company_id=c.id) schools_count,(SELECT COUNT(*) FROM schools s WHERE s.company_id=c.id AND s.location_status='done') done_count,(SELECT COUNT(*) FROM company_users cu WHERE cu.company_id=c.id) users_count FROM companies c $where ORDER BY c.title",$args);
  $defs=Db::all("SELECT field_key,label FROM company_field_defs WHERE is_active=1 ORDER BY sort_order,id");
  $headers=['شناسه','نام شرکت','نام مدیرعامل','تلفن همراه مدیرعامل','تلفن ثابت','آدرس','ظرفیت دانش‌آموز','درصد کاهش مجاز','درصد افزایش مجاز','عرض جغرافیایی','طول جغرافیایی','تعداد مدارس','مدارس ثبت‌شده','تعداد نمایندگان','وضعیت','تکمیل پروفایل'];
  $builtin=['title','manager_name','ceo_mobile','phone','address','lat','lng']; foreach($defs as $d){if(!in_array($d['field_key'],$builtin,true))$headers[]=$d['label'];}
  $out=[]; foreach($rows as $r){$custom=json_decode($r['custom_fields']??'{}',true)?:[];$row=[$r['id'],$r['title'],$r['manager_name'],$r['ceo_mobile'],$r['phone'],$r['address'],$r['capacity_students'],$r['allowed_min_percent'],$r['allowed_max_percent'],$r['lat'],$r['lng'],$r['schools_count'],$r['done_count'],$r['users_count'],$r['is_active']?'فعال':'غیرفعال',$r['profile_completed']?'تکمیل‌شده':'ناقص'];foreach($defs as $d){if(!in_array($d['field_key'],$builtin,true))$row[]=$custom[$d['field_key']]??'';} $out[]=$row;}
  $format=trim((string)($_GET['format']??'full'));
  if($format==='summary'){
    $headers=['نام شرکت','مدیرعامل','ظرفیت دانش‌آموز','تعداد مدارس','مدارس ثبت‌شده','نمایندگان','وضعیت'];
    $out=array_map(fn($r)=>[$r['title'],$r['manager_name'],$r['capacity_students'],$r['schools_count'],$r['done_count'],$r['users_count'],$r['is_active']?'فعال':'غیرفعال'], $rows);
  } elseif($format==='contact'){
    $headers=['نام شرکت','مدیرعامل','موبایل مدیرعامل','تلفن','آدرس'];
    $out=array_map(fn($r)=>[$r['title'],$r['manager_name'],$r['ceo_mobile'],$r['phone'],$r['address']],$rows);
  }
  _xlsx_download('companies.xlsx',$headers,$out,'شرکت‌ها','فهرست شرکت‌های حمل‌ونقل دانش‌آموزی','سازمان مدیریت و نظارت بر تاکسیرانی شهرداری مشهد مقدس');
}, false, 'admin');

route('GET', '/api/admin/companies/{id}', function ($p, $b, $u) {
  _ensure_company_columns();
  $row = Db::one("SELECT * FROM companies WHERE id=?", [(int)$p['id']]);
  if (!$row) Http::error('یافت نشد', 404);
  $row['users'] = Db::all("SELECT id,username,full_name,phone,is_active,last_login_at,created_at FROM company_users WHERE company_id=? ORDER BY id DESC", [(int)$p['id']]);
  return $row;
}, false, 'admin');

route('POST', '/api/admin/companies', function ($p, $b, $u) {
_ensure_company_capacity_tables();
_block_viewer($u);
    $t = trim($b['title'] ?? ''); if (!$t) Http::error('نام شرکت الزامی است', 400);
  $id = Db::insert("INSERT INTO companies(title,manager_name,phone,address,capacity_students,allowed_min_percent,allowed_max_percent) VALUES(?,?,?,?,?,?,?)",
    [$t, $b['manager_name'] ?? null, $b['phone'] ?? null, $b['address'] ?? null, (int)($b['capacity_students'] ?? 0), (float)($b['allowed_min_percent'] ?? 0), (float)($b['allowed_max_percent'] ?? 0)]);
  return ['id' => $id];
}, false, 'admin');

route('PUT', '/api/admin/companies/{id}', function ($p, $b, $u) {
_ensure_company_capacity_tables();
_block_viewer($u);
    $t = trim($b['title'] ?? ''); if (!$t) Http::error('نام شرکت الزامی است', 400);
  Db::run("UPDATE companies SET title=?, manager_name=?, phone=?, address=?, capacity_students=?, allowed_min_percent=?, allowed_max_percent=?, is_active=? WHERE id=?",
    [$t, $b['manager_name'] ?? null, $b['phone'] ?? null, $b['address'] ?? null, (int)($b['capacity_students'] ?? 0), (float)($b['allowed_min_percent'] ?? 0), (float)($b['allowed_max_percent'] ?? 0), isset($b['is_active']) ? (int)!!$b['is_active'] : 1, (int)$p['id']]);
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
  _require_strong_password($pw);
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
    _require_strong_password($b['password']);
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
// فیلدهای پیش‌فرض سیستم (ستون‌های واقعی جدول schools).
// حذف این فیلدها به معنی غیرفعال/مخفی شدن در فرم‌ها، خروجی نمونه ایمپورت و تنظیمات ایمپورت است؛
// ستون فیزیکی در دیتابیس برای سازگاری نسخه‌های قبلی باقی می‌ماند.
const BUILTIN_SCHOOL_FIELDS = [
  ['name', 'نام مدرسه'], ['district_id', 'ناحیهٔ آموزش و پرورش'], ['company_id', 'شرکت سرویس‌دهنده'],
  ['gender', 'جنسیت دانش‌آموزان'], ['shift', 'شیفت'], ['level', 'مقطع تحصیلی'], ['school_type', 'نوع مدرسه'],
  ['start_time', 'شروع شیفت صبح'], ['end_time', 'پایان شیفت صبح'],
  ['shift1_start_time', 'شروع شیفت صبح'], ['shift1_end_time', 'پایان شیفت صبح'],
  ['shift2_start_time', 'شروع شیفت عصر'], ['shift2_end_time', 'پایان شیفت عصر'],
  ['driver_count', 'تعداد رانندگان'],
  ['student_count', 'تعداد دانش‌آموزان'], ['address', 'آدرس'], ['phone', 'تلفن'], ['principal_name', 'نام مدیر مدرسه'],
];
route('GET', '/api/school-builtin-fields', function ($p, $b) {
  $meta = json_decode(_setting_get('builtin_field_meta', '{}'), true) ?: [];
  $rows = array_map(function ($f) use ($meta) {
    return [
      'field_key' => $f[0],
      'label' => (function($key, $label, $fallback){
        $label = trim((string)$label);
        $map = [
          'shift1_start_time' => 'شروع شیفت صبح', 'shift1_end_time' => 'پایان شیفت صبح',
          'shift2_start_time' => 'شروع شیفت عصر', 'shift2_end_time' => 'پایان شیفت عصر',
          'start_time' => 'شروع فعالیت', 'end_time' => 'پایان فعالیت'
        ];
        if (isset($map[$key]) && ($label === '' || strpos($label, 'شیفت اول') !== false || strpos($label, 'شیفت دوم') !== false || strpos($label, 'فعالیت') !== false)) return $map[$key];
        return $label ?: $fallback;
      })($f[0], $meta[$f[0]]['label'] ?? '', $f[1]),
      'editable_by_rep' => (bool)($meta[$f[0]]['editable_by_rep'] ?? in_array($f[0], ['address', 'phone', 'principal_name', 'student_count'], true)),
      'is_active' => $f[0] === 'name' ? true : (bool)($meta[$f[0]]['is_active'] ?? true),
    ];
  }, BUILTIN_SCHOOL_FIELDS);
  if (($_GET['all'] ?? '') !== '1') $rows = array_values(array_filter($rows, fn($x) => $x['is_active']));
  return $rows;
}, true);

route('PUT', '/api/admin/school-builtin-fields', function ($p, $b, $u) {
  _block_viewer($u);
  $meta = json_decode(_setting_get('builtin_field_meta', '{}'), true) ?: [];
  $updates = $b['fields'] ?? [];
  foreach ($updates as $key => $conf) {
    if (!in_array($key, array_column(BUILTIN_SCHOOL_FIELDS, 0), true)) continue;
    $meta[$key] = [
      'label' => trim($conf['label'] ?? ''),
      'editable_by_rep' => (bool)($conf['editable_by_rep'] ?? false),
      'is_active' => $key === 'name' ? true : (bool)($conf['is_active'] ?? true),
    ];
  }
  _setting_set('builtin_field_meta', json_encode($meta, JSON_UNESCAPED_UNICODE));
  return ['ok' => true];
}, false, 'admin');

route('DELETE', '/api/admin/school-builtin-fields/{key}', function ($p, $b, $u) {
  _block_viewer($u);
  $key = $p['key'] ?? '';
  if (!in_array($key, array_column(BUILTIN_SCHOOL_FIELDS, 0), true)) Http::error('فیلد پیش‌فرض نامعتبر است', 404);
  if (in_array($key, ['name'], true)) Http::error('نام مدرسه فیلد هویتی سامانه است و قابل حذف نیست؛ فقط عنوان آن قابل ویرایش است.', 400);
  $meta = json_decode(_setting_get('builtin_field_meta', '{}'), true) ?: [];
  $fallback = null;
  foreach (BUILTIN_SCHOOL_FIELDS as $f) { if ($f[0] === $key) { $fallback = $f[1]; break; } }
  $meta[$key] = array_merge($meta[$key] ?? [], [
    'label' => trim($meta[$key]['label'] ?? ($fallback ?: $key)),
    'editable_by_rep' => (bool)($meta[$key]['editable_by_rep'] ?? false),
    'is_active' => false,
  ]);
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

function _school_shift_normalize($b, $strict = true) {
  $shift = trim((string)($b['shift'] ?? ''));
  $morningStart = $b['shift1_start_time'] ?? $b['start_time'] ?? null;
  $morningEnd = $b['shift1_end_time'] ?? $b['end_time'] ?? null;
  $eveningStart = $b['shift2_start_time'] ?? null;
  $eveningEnd = $b['shift2_end_time'] ?? null;
  $morningStart = $morningStart !== '' ? $morningStart : null;
  $morningEnd = $morningEnd !== '' ? $morningEnd : null;
  $eveningStart = $eveningStart !== '' ? $eveningStart : null;
  $eveningEnd = $eveningEnd !== '' ? $eveningEnd : null;
  if ($shift === 'صبح') {
    if ($strict && (!$morningStart || !$morningEnd)) Http::error('برای شیفت صبح، شروع و پایان شیفت صبح الزامی است', 400);
    $eveningStart = $eveningEnd = null;
    $start = $morningStart; $end = $morningEnd;
  } elseif ($shift === 'عصر') {
    if ($strict && (!$eveningStart || !$eveningEnd)) Http::error('برای شیفت عصر، شروع و پایان شیفت عصر الزامی است', 400);
    $morningStart = $morningEnd = null;
    $start = $eveningStart; $end = $eveningEnd;
  } elseif ($shift === 'دو شیفته' || $shift === 'دوشیفته' || $shift === 'دو شیفت') {
    $shift = 'دو شیفته';
    if ($strict && (!$morningStart || !$morningEnd || !$eveningStart || !$eveningEnd)) Http::error('برای مدرسه دوشیفته، شروع و پایان شیفت صبح و عصر الزامی است', 400);
    $start = $morningStart; $end = $eveningEnd;
  } else {
    $start = $b['start_time'] ?? $morningStart;
    $end = $b['end_time'] ?? $morningEnd;
  }
  return [$shift ?: null, $start ?: null, $end ?: null, $morningStart ?: null, $morningEnd ?: null, $eveningStart ?: null, $eveningEnd ?: null];
}

route('POST', '/api/admin/schools', function ($p, $b, $u) {
_block_viewer($u);
  _ensure_school_columns(); _ensure_school_field_defs_table();
  $code = trim($b['code'] ?? ''); $name = trim($b['name'] ?? '');
  if (!_school_code_valid($code)) Http::error('کد یکتای مدرسه باید عددی باشد', 400);
  if (!$name) Http::error('نام مدرسه الزامی است', 400);
  $exists = Db::one("SELECT id FROM schools WHERE code=?", [$code]);
  if ($exists) Http::error('مدرسه‌ای با این کد قبلاً ثبت شده است', 409);
  $customFields = isset($b['custom_fields']) && is_array($b['custom_fields']) ? json_encode($b['custom_fields'], JSON_UNESCAPED_UNICODE) : null;
  [$shiftVal, $startVal, $endVal, $morningStartVal, $morningEndVal, $eveningStartVal, $eveningEndVal] = _school_shift_normalize($b, true);
  $id = Db::insert("INSERT INTO schools(code,name,district_id,company_id,gender,shift,level,school_type,start_time,end_time,shift1_start_time,shift1_end_time,shift2_start_time,shift2_end_time,driver_count,address,phone,principal_name,student_count,custom_fields)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
    $code, $name, $b['district_id'] ?: null, $b['company_id'] ?: null, $b['gender'] ?? null, $shiftVal,
    $b['level'] ?? null, $b['school_type'] ?? null, $startVal, $endVal,
    $morningStartVal, $morningEndVal, $eveningStartVal, $eveningEndVal,
    $b['driver_count'] ?: null, $b['address'] ?? null, $b['phone'] ?? null, $b['principal_name'] ?? null, $b['student_count'] ?: null, $customFields,
  ]);
  return ['id' => $id];
}, false, 'admin');

route('PUT', '/api/admin/schools/{id}', function ($p, $b, $u) {
_block_viewer($u);
  _ensure_school_columns(); _ensure_school_field_defs_table();
  $name = trim($b['name'] ?? ''); if (!$name) Http::error('نام مدرسه الزامی است', 400);
  $sets = "name=?, district_id=?, company_id=?, gender=?, shift=?, level=?, school_type=?, start_time=?, end_time=?, shift1_start_time=?, shift1_end_time=?, shift2_start_time=?, shift2_end_time=?, driver_count=?, address=?, phone=?, principal_name=?, student_count=?";
  [$shiftVal, $startVal, $endVal, $morningStartVal, $morningEndVal, $eveningStartVal, $eveningEndVal] = _school_shift_normalize($b, true);
  $args = [
    $name, $b['district_id'] ?: null, $b['company_id'] ?: null, $b['gender'] ?? null, $shiftVal,
    $b['level'] ?? null, $b['school_type'] ?? null, $startVal, $endVal,
    $morningStartVal, $morningEndVal, $eveningStartVal, $eveningEndVal,
    $b['driver_count'] ?: null, $b['address'] ?? null, $b['phone'] ?? null, $b['principal_name'] ?? null, $b['student_count'] ?: null,
  ];
  if (isset($b['custom_fields']) && is_array($b['custom_fields'])) { $sets .= ", custom_fields=?"; $args[] = json_encode($b['custom_fields'], JSON_UNESCAPED_UNICODE); }
  $args[] = (int)$p['id'];
  Db::run("UPDATE schools SET $sets WHERE id=?", $args);
  // اجازهٔ اصلاح دستی موقعیت توسط ادمین
  if (isset($b['lat']) && isset($b['lng']) && _valid_lat_lng($b['lat'], $b['lng'])) {
    Db::run("UPDATE schools SET lat=?, lng=?, gps_accuracy=?, location_status='done', location_recorded_at=NOW() WHERE id=?", [$b['lat'], $b['lng'], $b['gps_accuracy'] ?? null, (int)$p['id']]);
  }
  return ['ok' => true];
}, false, 'admin');

route('DELETE', '/api/admin/schools/{id}', function ($p, $b, $u) {
_block_viewer($u);
    Db::run("DELETE FROM schools WHERE id=?", [(int)$p['id']]);
  return ['ok' => true];
}, false, 'admin');



function _school_builtin_meta() { return json_decode(_setting_get('builtin_field_meta', '{}'), true) ?: []; }
function _school_builtin_is_active($key, $meta = null) {
  if ($key === 'name') return true;
  if ($meta === null) $meta = _school_builtin_meta();
  return (bool)($meta[$key]['is_active'] ?? true);
}
function _school_builtin_label($key, $fallback, $meta = null) {
  if ($meta === null) $meta = _school_builtin_meta();
  $label = trim((string)($meta[$key]['label'] ?? ''));
  $map = [
    'shift1_start_time' => 'شروع شیفت صبح', 'shift1_end_time' => 'پایان شیفت صبح',
    'shift2_start_time' => 'شروع شیفت عصر', 'shift2_end_time' => 'پایان شیفت عصر',
    'start_time' => 'شروع فعالیت', 'end_time' => 'پایان فعالیت'
  ];
  if (isset($map[$key]) && ($label === '' || strpos($label, 'شیفت اول') !== false || strpos($label, 'شیفت دوم') !== false || strpos($label, 'فعالیت') !== false)) return $map[$key];
  return $label ?: $fallback;
}

route('GET', '/api/admin/schools/import-template', function($p,$b,$u){
  _ensure_school_field_defs_table();
  $builtins=(function(){
    $meta=_school_builtin_meta();
    $rows=array_map(function($f) use ($meta){return ['key'=>$f[0],'label'=>_school_builtin_label($f[0],$f[1],$meta),'active'=>_school_builtin_is_active($f[0],$meta)];},BUILTIN_SCHOOL_FIELDS);
    return array_values(array_filter($rows,fn($x)=>$x['active']));
  })();
  $headers=['کد مدرسه'];
  foreach($builtins as $f){if(in_array($f['key'],['lat','lng','start_time','end_time'],true))continue;$headers[]=$f['label'];}
  $custom=Db::all("SELECT label FROM school_field_defs WHERE is_active=1 ORDER BY sort_order,id");
  foreach($custom as $c)$headers[]=$c['label'];
  $sample=array_map(fn($h)=>'نمونه '.$h,$headers);
  _xlsx_download('schools-import-template.xlsx',$headers,[$sample],'قالب ورود مدارس','قالب نمونه ایمپورت مدارس','سازمان مدیریت و نظارت بر تاکسیرانی شهرداری مشهد مقدس');
}, false, 'admin');

// ایمپورت اکسل مدارس: ستون‌های ورودی (سرستون فارسی، ترتیب مهم نیست)
// کد مدرسه | نام مدرسه | ناحیه | شرکت سرویس‌دهنده | جنسیت | شیفت | مقطع | شروع شیفت صبح | پایان شیفت صبح | شروع شیفت عصر | پایان شیفت عصر | آدرس | تلفن | مدیر مدرسه | تعداد دانش‌آموز
route('POST', '/api/admin/schools/import', function ($p, $b, $u) {
_block_viewer($u);
  _ensure_school_columns(); _ensure_school_field_defs_table();
  $customDefs = Db::all("SELECT field_key,label,field_type FROM school_field_defs WHERE is_active=1 ORDER BY sort_order,id");
  $builtinMeta = _school_builtin_meta();
  $builtinLabel = function($key, $fallback) use ($builtinMeta) { return _school_builtin_label($key, $fallback, $builtinMeta); };
  $builtinActive = function($key) use ($builtinMeta) { return _school_builtin_is_active($key, $builtinMeta); };
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== 0) Http::error('فایلی ارسال نشد', 400);
  $tmp = $_FILES['file']['tmp_name'];
  $header = null; $inserted = 0; $updated = 0; $skipped = 0; $errors = [];
  $districtCache = []; $companyCache = []; $levelCache = []; $schoolTypeCache = [];
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
  $ensureLevelTitle = function ($title) use (&$levelCache) {
    $title = trim((string)$title); if ($title === '') return null;
    if (isset($levelCache[$title])) return $levelCache[$title];
    return $levelCache[$title] = _ensure_education_level_value($title);
  };
  $ensureSchoolTypeTitle = function ($title) use (&$schoolTypeCache) {
    $title = trim((string)$title); if ($title === '') return null;
    if (isset($schoolTypeCache[$title])) return $schoolTypeCache[$title];
    return $schoolTypeCache[$title] = _ensure_school_type_value($title);
  };
  $normalizeSchoolTime = function ($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace(['٫','،'], ['.', ':'], $v);
    // زمان‌های اکسل گاهی به شکل عدد اعشاری روز ذخیره می‌شوند؛ مانند 0.333333 برای 08:00
    if (is_numeric($v)) {
      $num = (float)$v;
      if ($num > 0 && $num < 1) {
        $mins = (int)round($num * 24 * 60);
        return sprintf('%02d:%02d', intdiv($mins, 60) % 24, $mins % 60);
      }
      if ($num >= 1 && $num <= 24 && strpos($v, '.') === false) return sprintf('%02d:00', (int)$num);
    }
    if (preg_match('/^(\d{1,2})[:.](\d{1,2})(?::\d{1,2})?$/', $v, $m)) {
      $h = max(0, min(23, (int)$m[1])); $mi = max(0, min(59, (int)$m[2]));
      return sprintf('%02d:%02d', $h, $mi);
    }
    if (preg_match('/^(\d{1,2})$/', $v, $m)) return sprintf('%02d:00', max(0, min(23, (int)$m[1])));
    return $v;
  };
  Xlsx::eachRow($tmp, function ($cells, $idx) use (&$header, &$inserted, &$updated, &$skipped, &$errors, $getDistrictId, $getCompanyId, $customDefs, $builtinLabel, $builtinActive, $normalizeSchoolTime, $ensureLevelTitle, $ensureSchoolTypeTitle) {
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
    $name = $get(['نام مدرسه', 'نام', $builtinLabel('name','نام مدرسه')]);
    if (!$code || !$name) { $skipped++; return; }
    $districtId = $builtinActive('district_id') ? $getDistrictId($get(['ناحیه', 'ناحیهٔ آموزش و پرورش', 'ناحیه آموزش و پرورش', $builtinLabel('district_id','ناحیهٔ آموزش و پرورش')])) : null;
    $companyId = $builtinActive('company_id') ? $getCompanyId($get(['شرکت سرویس‌دهنده', 'شرکت', 'نام شرکت', $builtinLabel('company_id','شرکت سرویس‌دهنده')])) : null;
    $gender = $builtinActive('gender') ? $get(['جنسیت', $builtinLabel('gender','جنسیت دانش‌آموزان')]) : null;
    if ($gender === 'دختر و پسر' || $gender === 'دخترانه پسرانه' || $gender === 'دخترانه/پسرانه') $gender = 'دخترانه و پسرانه';
    $shift = $builtinActive('shift') ? $get(['شیفت', $builtinLabel('shift','شیفت')]) : null;
    $level = $builtinActive('level') ? $ensureLevelTitle($get(['مقطع', 'مقطع تحصیلی', $builtinLabel('level','مقطع تحصیلی')])) : null;
    $schoolType = $builtinActive('school_type') ? $ensureSchoolTypeTitle($get(['نوع مدرسه', $builtinLabel('school_type','نوع مدرسه')])) : null;
    $startTime = $builtinActive('start_time') ? $normalizeSchoolTime($get(['ساعت شروع', 'ساعت شروع فعالیت', 'ساعت شروع مدرسه', 'ساعت شروع مدارس', 'شروع فعالیت', 'شروع', 'شروع شیفت صبح', 'start_time', $builtinLabel('start_time','شروع شیفت صبح')])) : null;
    $endTime = $builtinActive('end_time') ? $normalizeSchoolTime($get(['ساعت پایان', 'ساعت پایان فعالیت', 'ساعت پایان مدرسه', 'ساعت پایان مدارس', 'پایان فعالیت', 'پایان', 'پایان شیفت صبح', 'end_time', $builtinLabel('end_time','پایان شیفت صبح')])) : null;
    $shift1StartTime = $builtinActive('shift1_start_time') ? $normalizeSchoolTime($get(['شروع شیفت صبح', 'ساعت شروع شیفت صبح', 'ساعت شروع شیفت اول', 'شروع شیفت اول', 'shift1_start_time', $builtinLabel('shift1_start_time','شروع شیفت صبح')])) : null;
    $shift1EndTime = $builtinActive('shift1_end_time') ? $normalizeSchoolTime($get(['پایان شیفت صبح', 'ساعت پایان شیفت صبح', 'ساعت پایان شیفت اول', 'پایان شیفت اول', 'shift1_end_time', $builtinLabel('shift1_end_time','پایان شیفت صبح')])) : null;
    $shift2StartTime = $builtinActive('shift2_start_time') ? $normalizeSchoolTime($get(['شروع شیفت عصر', 'ساعت شروع شیفت عصر', 'ساعت شروع شیفت دوم', 'شروع شیفت دوم', 'shift2_start_time', $builtinLabel('shift2_start_time','شروع شیفت عصر')])) : null;
    $shift2EndTime = $builtinActive('shift2_end_time') ? $normalizeSchoolTime($get(['پایان شیفت عصر', 'ساعت پایان شیفت عصر', 'ساعت پایان شیفت دوم', 'پایان شیفت دوم', 'shift2_end_time', $builtinLabel('shift2_end_time','پایان شیفت عصر')])) : null;
    if (!$shift1StartTime && $startTime) $shift1StartTime = $startTime;
    if (!$shift1EndTime && $endTime && $shift !== 'عصر') $shift1EndTime = $endTime;
    $norm = _school_shift_normalize(['shift'=>$shift, 'start_time'=>$startTime, 'end_time'=>$endTime, 'shift1_start_time'=>$shift1StartTime, 'shift1_end_time'=>$shift1EndTime, 'shift2_start_time'=>$shift2StartTime, 'shift2_end_time'=>$shift2EndTime], false);
    [$shift, $startTime, $endTime, $shift1StartTime, $shift1EndTime, $shift2StartTime, $shift2EndTime] = $norm;
    $driverCount = $builtinActive('driver_count') ? $get(['تعداد راننده', 'تعداد رانندگان', $builtinLabel('driver_count','تعداد رانندگان')]) : null;
    $address = $builtinActive('address') ? $get(['آدرس', $builtinLabel('address','آدرس')]) : null; $phone = $builtinActive('phone') ? $get(['تلفن', $builtinLabel('phone','تلفن')]) : null; $principal = $builtinActive('principal_name') ? $get(['مدیر مدرسه', 'نام مدیر', $builtinLabel('principal_name','نام مدیر مدرسه')]) : null;
    $studentCount = $builtinActive('student_count') ? $get(['تعداد دانش‌آموز', 'تعداد دانش آموز', $builtinLabel('student_count','تعداد دانش‌آموزان')]) : null;
    $customFields = [];
    foreach ($customDefs as $def) { $val = $get([$def['label'], $def['field_key']]); if ($val !== null && $val !== '') $customFields[$def['field_key']] = $def['field_type']==='checkbox' ? in_array(trim((string)$val), ['1','true','بله','بلی','yes'], true) : $val; }
    $customJson = $customFields ? json_encode($customFields, JSON_UNESCAPED_UNICODE) : null;
    try {
      $exists = Db::one("SELECT id FROM schools WHERE code=?", [$code]);
      if ($exists) {
        Db::run("UPDATE schools SET name=?,district_id=?,company_id=?,gender=?,shift=?,level=?,school_type=?,start_time=?,end_time=?,shift1_start_time=?,shift1_end_time=?,shift2_start_time=?,shift2_end_time=?,driver_count=?,address=?,phone=?,principal_name=?,student_count=CASE WHEN ? IS NULL THEN student_count ELSE COALESCE(student_count,0)+? END,custom_fields=COALESCE(?,custom_fields) WHERE id=?",
          [$name, $districtId, $companyId, $gender, $shift, $level, $schoolType, $startTime, $endTime, $shift1StartTime, $shift1EndTime, $shift2StartTime, $shift2EndTime, $driverCount ?: null, $address, $phone, $principal, ($studentCount !== null && $studentCount !== '' ? (int)$studentCount : null), ($studentCount !== null && $studentCount !== '' ? (int)$studentCount : null), $customJson, $exists['id']]);
        $updated++;
      } else {
        Db::run("INSERT INTO schools(code,name,district_id,company_id,gender,shift,level,school_type,start_time,end_time,shift1_start_time,shift1_end_time,shift2_start_time,shift2_end_time,driver_count,address,phone,principal_name,student_count,custom_fields) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$code, $name, $districtId, $companyId, $gender, $shift, $level, $schoolType, $startTime, $endTime, $shift1StartTime, $shift1EndTime, $shift2StartTime, $shift2EndTime, $driverCount ?: null, $address, $phone, $principal, $studentCount ?: null, $customJson]);
        $inserted++;
      }
    } catch (\Throwable $e) { $errors[] = "ردیف $idx: " . $e->getMessage(); $skipped++; }
  });
  return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20)];
}, false, 'admin');

// خروجی XLSX شامل مختصات و تمام اطلاعات اصلی مدارس
route('GET', '/api/admin/schools/export', function ($p, $b, $u) {
  _ensure_school_columns(); _ensure_school_field_defs_table();
  $conds=[]; $args=[];
  if(!empty($_GET['district_id'])){$conds[]='s.district_id=?';$args[]=(int)$_GET['district_id'];}
  if(!empty($_GET['company_id'])){$conds[]='s.company_id=?';$args[]=(int)$_GET['company_id'];}
  if(!empty($_GET['status'])){$conds[]='s.location_status=?';$args[]=$_GET['status'];}
  if(!empty($_GET['q'])){$conds[]='(s.name LIKE ? OR s.code LIKE ?)';$q='%'.$_GET['q'].'%';$args[]=$q;$args[]=$q;}
  $where=$conds?'WHERE '.implode(' AND ',$conds):'';
  $rows=Db::all("SELECT s.code,s.name,d.title district_title,c.title company_title,s.gender,s.shift,s.level,s.school_type,s.start_time,s.end_time,s.shift1_start_time,s.shift1_end_time,s.shift2_start_time,s.shift2_end_time,s.driver_count,s.student_count,s.address,s.phone,s.principal_name,s.lat,s.lng,s.location_status,s.location_recorded_at,s.custom_fields FROM schools s LEFT JOIN districts d ON d.id=s.district_id LEFT JOIN companies c ON c.id=s.company_id $where ORDER BY s.id",$args);
  $defs=Db::all("SELECT field_key,label FROM school_field_defs WHERE is_active=1 ORDER BY sort_order,id");
  $headers=['کد مدرسه','نام مدرسه','ناحیه','شرکت سرویس‌دهنده','جنسیت','شیفت','مقطع تحصیلی','نوع مدرسه','شروع فعالیت','پایان فعالیت','شروع شیفت صبح','پایان شیفت صبح','شروع شیفت عصر','پایان شیفت عصر','تعداد رانندگان','تعداد دانش‌آموز','آدرس','تلفن','عرض جغرافیایی','طول جغرافیایی','وضعیت','تاریخ ثبت موقعیت'];
  foreach($defs as $d){ if(!in_array($d['field_key'],['name','district_id','company_id','gender','shift','level','school_type','start_time','end_time','shift1_start_time','shift1_end_time','shift2_start_time','shift2_end_time','driver_count','address','phone','student_count'],true))$headers[]=$d['label']; }
  $out=[];
  foreach($rows as $r){$custom=json_decode($r['custom_fields']??'{}',true)?:[];$out[]=[ $r['code'],$r['name'],$r['district_title'],$r['company_title'],$r['gender'],$r['shift'],$r['level'],$r['school_type'],$r['start_time'],$r['end_time'],$r['shift1_start_time'],$r['shift1_end_time'],$r['shift2_start_time'],$r['shift2_end_time'],$r['driver_count'],$r['student_count'],$r['address'],$r['phone'],$r['lat'],$r['lng'],$r['location_status']==='done'?'ثبت‌شده':'باقی‌مانده',$r['location_recorded_at'], ...array_map(fn($d)=>$custom[$d['field_key']]??'',array_filter($defs,fn($d)=>!in_array($d['field_key'],['name','district_id','company_id','gender','shift','level','school_type','start_time','end_time','shift1_start_time','shift1_end_time','shift2_start_time','shift2_end_time','driver_count','address','phone','student_count'],true))) ];}
  $format=trim((string)($_GET['format']??'full'));
  if($format==='summary'){
    $headers=['کد مدرسه','نام مدرسه','ناحیه','شرکت سرویس‌دهنده','تعداد دانش‌آموز','وضعیت موقعیت'];
    $out=array_map(fn($r)=>[$r['code'],$r['name'],$r['district_title'],$r['company_title'],$r['student_count'],$r['location_status']==='done'?'ثبت‌شده':'باقی‌مانده'], $rows);
  } elseif($format==='contact'){
    $headers=['کد مدرسه','نام مدرسه','ناحیه','مدیر مدرسه','تلفن','آدرس'];
    $out=array_map(fn($r)=>[$r['code'],$r['name'],$r['district_title'],$r['principal_name'],$r['phone'],$r['address']],$rows);
  }
  _xlsx_download('schools.xlsx',$headers,$out,'مدارس','فهرست مدارس سامانه حمل‌ونقل دانش‌آموزی','سازمان مدیریت و نظارت بر تاکسیرانی شهرداری مشهد مقدس');
}, false, 'admin');

// نقاط مدارس برای نقشهٔ داشبورد
route('GET', '/api/admin/schools/map', fn($p, $b, $u) => Db::all(
  "SELECT s.id,s.code,s.name,s.lat,s.lng,s.location_status,s.level,s.student_count,s.driver_count,s.address,s.photo_path,
      c.title company_title,d.title district_title
   FROM schools s LEFT JOIN companies c ON c.id=s.company_id LEFT JOIN districts d ON d.id=s.district_id
   WHERE s.lat IS NOT NULL AND s.lng IS NOT NULL"), false, 'admin');

// نقشه و جستجوی عمومی مدارس برای صفحهٔ اول سایت
// فهرست عمومی مدارس برای فرم ثبت‌نام/شکایت؛ شامل مدارس فاقد مختصات نیز می‌شود.
route('GET', '/api/public/schools', function ($p, $b) {
  _ensure_school_columns();
  $conds = ['1=1']; $args = [];
  if (!empty($_GET['q'])) {
    $q = '%' . trim($_GET['q']) . '%';
    $conds[] = '(s.name LIKE ? OR s.code LIKE ?)';
    array_push($args, $q, $q);
  }
  if (!empty($_GET['district_id'])) { $conds[] = 's.district_id=?'; $args[] = (int)$_GET['district_id']; }
  return Db::all("SELECT s.id,s.code,s.name,s.district_id,s.level,s.gender,s.shift,s.address
    FROM schools s WHERE " . implode(' AND ', $conds) . " ORDER BY s.name LIMIT 2000", $args);
}, true);

route('GET', '/api/public/schools/map', function ($p, $b) {
  _ensure_school_columns(); _ensure_company_columns();
  $conds = ["s.lat IS NOT NULL", "s.lng IS NOT NULL"];
  $args = [];
  if (!empty($_GET['q'])) { $conds[] = "(s.name LIKE ? OR s.code LIKE ? OR s.address LIKE ?)"; $q = '%' . trim($_GET['q']) . '%'; array_push($args, $q, $q, $q); }
  if (!empty($_GET['district_id'])) { $conds[] = "s.district_id=?"; $args[] = (int)$_GET['district_id']; }
  if (!empty($_GET['level'])) { $conds[] = "s.level=?"; $args[] = trim($_GET['level']); }
  if (!empty($_GET['gender'])) { $conds[] = "s.gender=?"; $args[] = trim($_GET['gender']); }
  $where = 'WHERE ' . implode(' AND ', $conds);
  $rows=Db::all("SELECT s.id,s.code,s.name,s.lat,s.lng,s.level,s.gender,s.student_count,s.driver_count,s.address,s.photo_path,
      c.id company_id,c.title company_title,c.manager_name,c.ceo_mobile,c.phone company_phone,c.address company_address,c.lat company_lat,c.lng company_lng,
      d.title district_title
    FROM schools s LEFT JOIN companies c ON c.id=s.company_id LEFT JOIN districts d ON d.id=s.district_id
    $where ORDER BY s.name LIMIT 500", $args);
  if(_setting_get('show_school_photos','0')==='1') foreach($rows as &$row){if(!empty($row['photo_path']))$row['photo_url']='/api/media?path='.rawurlencode($row['photo_path']);} else foreach($rows as &$row){unset($row['photo_path']);}
  unset($row); return $rows;
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
  _ensure_complaints_tables();
  $complaintsTotal = (int)(Db::one("SELECT COUNT(*) n FROM complaints")['n'] ?? 0);
  $topComplaintCompany = Db::one("SELECT c.id,c.title,COUNT(co.id) complaints_count FROM complaints co LEFT JOIN companies c ON c.id=co.company_id WHERE co.company_id IS NOT NULL GROUP BY c.id,c.title ORDER BY complaints_count DESC LIMIT 1");
  $bottomComplaintCompany = Db::one("SELECT c.id,c.title,COUNT(co.id) complaints_count FROM complaints co LEFT JOIN companies c ON c.id=co.company_id WHERE co.company_id IS NOT NULL GROUP BY c.id,c.title ORDER BY complaints_count ASC,c.title LIMIT 1");
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
    'complaints_total' => $complaintsTotal, 'top_complaint_company' => $topComplaintCompany, 'bottom_complaint_company' => $bottomComplaintCompany,
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
  $out=[]; foreach ($schools as $r) $out[]=[$r['code'], $r['name'], $r['district_title'], $r['location_status'] === 'done' ? 'ثبت‌شده' : 'باقی‌مانده', $r['lat'], $r['lng'], $r['location_recorded_at'], $r['editor_name']];
  _xlsx_download('company-report-'.$cid.'.xlsx',['کد مدرسه','نام مدرسه','ناحیه','وضعیت','عرض جغرافیایی','طول جغرافیایی','تاریخ ثبت','ثبت‌کننده'],$out,'گزارش عملکرد شرکت','گزارش عملکرد شرکت: '.($company['title']??''),'سازمان مدیریت و نظارت بر تاکسیرانی شهرداری مشهد مقدس');
}, false, 'admin');

/* ==================== اپ اندروید — نماینده شرکت ==================== */

// جستجوی مدرسه با کد یکتا (فقط در محدودهٔ شرکت خودِ نماینده)
// لیست کامل مدارس شرکتِ نماینده (فقط اگر ادمین این قابلیت را از تنظیمات فعال کرده باشد)
route('GET', '/api/schools/list', function ($p, $b, $u) {
  _ensure_company_capacity_tables();
  if (_setting_get('rep_can_view_school_list', '1') !== '1') Http::error('این قابلیت توسط مدیر سامانه غیرفعال شده است.', 403);
  return Db::all("SELECT s.id,s.code,s.name,s.location_status,s.level,s.gender,s.student_count,s.driver_count,d.title district_title,c.capacity_students,c.allowed_min_percent,c.allowed_max_percent FROM schools s
    LEFT JOIN districts d ON d.id=s.district_id LEFT JOIN companies c ON c.id=s.company_id WHERE s.company_id=? ORDER BY s.name", [$u['company_id']]);
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
  if (!_valid_lat_lng($lat, $lng)) Http::error('موقعیت مکانی نامعتبر است', 400);
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

/* ==================== آمار بازدید عمومی سایت ==================== */
function _ensure_site_visit_table() {
  static $done = false; if ($done) return; $done = true;
  Db::run("CREATE TABLE IF NOT EXISTS site_visit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    visitor_hash CHAR(64) NOT NULL,
    page_path VARCHAR(255) NOT NULL DEFAULT '/',
    referrer VARCHAR(500) NULL,
    user_agent VARCHAR(500) NULL,
    visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_svl_visited_at (visited_at),
    INDEX idx_svl_visitor_date (visitor_hash, visited_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function _fa_digits_to_en($s) {
  return strtr((string)$s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
}
function _jalali_to_gregorian($jy, $jm, $jd) {
  $jy=(int)$jy; $jm=(int)$jm; $jd=(int)$jd;
  $jy -= 979; $jm -= 1; $jd -= 1;
  $j_day_no = 365*$jy + intdiv($jy,33)*8 + intdiv(($jy%33)+3,4);
  if ($jm < 6) $j_day_no += $jm*31; else $j_day_no += $jm*30+6;
  $j_day_no += $jd; $g_day_no = $j_day_no + 79;
  $gy = 1600 + 400*intdiv($g_day_no,146097); $g_day_no %= 146097;
  $leap = true;
  if ($g_day_no >= 36525) { $g_day_no--; $gy += 100*intdiv($g_day_no,36524); $g_day_no %= 36524; if ($g_day_no >= 365) $g_day_no++; else $leap=false; }
  $gy += 4*intdiv($g_day_no,1461); $g_day_no %= 1461;
  if ($g_day_no >= 366) { $leap=false; $g_day_no--; $gy += intdiv($g_day_no,365); $g_day_no %= 365; }
  $sal_a=[0,31,($leap?29:28),31,30,31,30,31,31,30,31,30,31];
  $gm=1; while ($gm<=12 && $g_day_no >= $sal_a[$gm]) { $g_day_no -= $sal_a[$gm]; $gm++; }
  $gd=$g_day_no+1; return sprintf('%04d-%02d-%02d',$gy,$gm,$gd);
}
function _gregorian_to_jalali($gy,$gm,$gd) {
  $g_d_m=[0,31,59,90,120,151,181,212,243,273,304,334];
  $gy=(int)$gy; $gm=(int)$gm; $gd=(int)$gd; $gy2=($gm>2)?$gy+1:$gy;
  $days=355666+365*$gy+intdiv($gy2+3,4)-intdiv($gy2+99,100)+intdiv($gy2+399,400)+$gd+$g_d_m[$gm-1];
  $jy=-1595+33*intdiv($days,12053); $days%=12053; $jy+=4*intdiv($days,1461); $days%=1461;
  if ($days>365) { $jy+=intdiv($days-1,365); $days=($days-1)%365; }
  if ($days<186) { $jm=1+intdiv($days,31); $jd=1+($days%31); } else { $jm=7+intdiv($days-186,30); $jd=1+(($days-186)%30); }
  return sprintf('%04d-%02d-%02d',$jy,$jm,$jd);
}
function _normalize_jalali_range($from, $to) {
  $from=preg_replace('/[^0-9\-\/]/','',_fa_digits_to_en($from)); $to=preg_replace('/[^0-9\-\/]/','',_fa_digits_to_en($to));
  $parse=function($s){ $x=preg_split('/[-\/]/',$s); if(count($x)!==3) return null; return _jalali_to_gregorian((int)$x[0],(int)$x[1],(int)$x[2]); };
  return [$parse($from),$parse($to)];
}
function _site_visit_group_expr($group) {
  switch ($group) {
    case 'week': return "DATE_SUB(DATE(visited_at), INTERVAL WEEKDAY(visited_at) DAY)";
    case 'month': return "DATE_FORMAT(visited_at, '%Y-%m')";
    default: return "DATE(visited_at)";
  }
}
function _site_visit_report_rows($from, $to, $group='day') {
  $expr=_site_visit_group_expr($group);
  $rows=Db::all("SELECT $expr period_key, COUNT(*) page_views, COUNT(DISTINCT visitor_hash) unique_visitors
    FROM site_visit_logs WHERE visited_at>=? AND visited_at<? GROUP BY period_key ORDER BY period_key",[$from.' 00:00:00',$to.' 00:00:00']);
  $out=[];
  foreach($rows as $r){
    $key=(string)$r['period_key'];
    if($group==='day') { [$y,$m,$d]=array_map('intval',explode('-',$key)); $fa=_gregorian_to_jalali($y,$m,$d); $label=$fa; }
    elseif($group==='month') { [$y,$m]=array_map('intval',explode('-',$key)); $fa=_gregorian_to_jalali($y,$m,1); $label=substr($fa,0,7); }
    else { [$y,$m,$d]=array_map('intval',explode('-',$key)); $start=_gregorian_to_jalali($y,$m,$d); $end=_gregorian_to_jalali(...array_map('intval',explode('-',date('Y-m-d',strtotime($key.' +6 days'))))); $label='از '.$start.' تا '.$end; }
    $out[]=['period'=>$label,'period_key'=>$key,'page_views'=>(int)$r['page_views'],'unique_visitors'=>(int)$r['unique_visitors']];
  }
  return $out;
}
function _site_visit_defaults($group) {
  $today=date('Y-m-d');
  if($group==='month') $from=date('Y-m-01',strtotime('-11 months')); elseif($group==='week') $from=date('Y-m-d',strtotime('-83 days')); else $from=date('Y-m-d',strtotime('-29 days'));
  return [$from,date('Y-m-d',strtotime($today.' +1 day'))];
}
route('POST','/api/public/site-visit',function($p,$b){
  try {
    _ensure_site_visit_table();
    $client=(string)($b['visitor_id']??''); $path=substr((string)($b['path']??'/'),0,255);
    $ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500); $ref=substr((string)($_SERVER['HTTP_REFERER']??''),0,500);
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    $hash=hash('sha256',$client.'|'.$ip.'|'.$ua);
    Db::run("INSERT INTO site_visit_logs(visitor_hash,page_path,referrer,user_agent) VALUES(?,?,?,?)",[$hash,$path,$ref,$ua]);
  } catch(Throwable $e) {}
  return ['ok'=>true];
}, true);
route('GET','/api/admin/site-visits',function($p,$b,$u){
  _require_role($u,['super_admin']); _ensure_site_visit_table();
  $group=in_array(($p['group']??$_GET['group']??'day'),['day','week','month','custom'],true)?($p['group']??$_GET['group']??'day'):'day';
  if($group==='custom') $group='day';
  [$from,$to]=_site_visit_defaults($group);
  $fj=$_GET['from']??''; $tj=$_GET['to']??'';
  if($fj && $tj){ [$from,$to]=_normalize_jalali_range($fj,$tj); if($to) $to=date('Y-m-d',strtotime($to.' +1 day')); }
  if(!$from||!$to) Http::error('بازهٔ تاریخ نامعتبر است.',400);
  $rows=_site_visit_report_rows($from,$to,$group);
  $tot=Db::one("SELECT COUNT(*) page_views, COUNT(DISTINCT visitor_hash) unique_visitors FROM site_visit_logs WHERE visited_at>=? AND visited_at<?",[$from.' 00:00:00',$to.' 00:00:00']);
  return ['group'=>$group,'from'=>_gregorian_to_jalali(...array_map('intval',explode('-',$from))),'to'=>_gregorian_to_jalali(...array_map('intval',explode('-',date('Y-m-d',strtotime($to.' -1 day'))))),'page_views'=>(int)($tot['page_views']??0),'unique_visitors'=>(int)($tot['unique_visitors']??0),'rows'=>$rows];
}, false, 'admin');
route('GET','/api/admin/site-visits/export',function($p,$b,$u){
  _require_role($u,['super_admin']); _ensure_site_visit_table();
  $group=$_GET['group']??'day'; if(!in_array($group,['day','week','month'],true))$group='day';
  [$from,$to]=_site_visit_defaults($group); if(!empty($_GET['from'])&&!empty($_GET['to'])){[$from,$to]=_normalize_jalali_range($_GET['from'],$_GET['to']);if($to)$to=date('Y-m-d',strtotime($to.' +1 day'));}
  if(!$from||!$to)Http::error('بازهٔ تاریخ نامعتبر است.',400);
  $rows=_site_visit_report_rows($from,$to,$group);
  $out=array_map(fn($r)=>[$r['period'],$r['page_views'],$r['unique_visitors']],$rows);
  _xlsx_download('site-visits.xlsx',['بازه شمسی','تعداد بازدید','بازدیدکننده یکتا'],$out,'آمار بازدید','گزارش آمار بازدید سایت','سامانه مدیریت شرکت‌های حمل‌ونقل دانش‌آموزی مشهد');
},false,'admin');

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
function _feature_defaults(){
  return [
    'school_reservations' => ['title'=>'سیستم رزرو مدارس سال تحصیلی بعد', 'default'=>true],
    'company_reservation_view' => ['title'=>'مشاهده لیست رزرو توسط نماینده شرکت', 'default'=>true],
    'company_reservation_edit' => ['title'=>'ثبت و ویرایش لیست رزرو توسط نماینده شرکت', 'default'=>true],
    'company_capacity_view' => ['title'=>'نمایش ظرفیت مجاز شرکت در نرم‌افزار', 'default'=>true],
    'company_capacity_warning' => ['title'=>'کنترل و هشدار ظرفیت هنگام ثبت رزرو', 'default'=>true],
    'bulk_reservation_fill' => ['title'=>'تکمیل گروهی رزرو شرکت‌ها از مدارس فعلی', 'default'=>true],
    'complaints' => ['title'=>'سیستم ثبت و رسیدگی شکایات', 'default'=>true],
    'public_cost_calculator_map' => ['title'=>'محاسبه هزینه با انتخاب مدرسه روی نقشه (نیازمند GPS)', 'default'=>true],
    'public_cost_calculator_quick' => ['title'=>'محاسبه سریع هزینه با ورود دستی ناحیه، کلاس خودرو و مسافت', 'default'=>true],
    'public_school_map' => ['title'=>'نمایش مدارس روی نقشه عمومی', 'default'=>true],
    'public_company_map' => ['title'=>'نمایش شرکت‌ها روی نقشه عمومی', 'default'=>true],
    'school_photo_upload' => ['title'=>'ثبت تصویر سردر مدرسه', 'default'=>true],
    'school_location_capture' => ['title'=>'ثبت موقعیت جغرافیایی مدرسه', 'default'=>true],
    'sms_notifications' => ['title'=>'ارسال اعلان‌های پیامکی', 'default'=>true],
    'excel_export' => ['title'=>'خروجی Excel/CSV', 'default'=>true],
    'pdf_export' => ['title'=>'خروجی PDF', 'default'=>true],
  ];
}
function _feature_key($name){ return 'feature_' . preg_replace('/[^a-zA-Z0-9_\-]/','', (string)$name); }
function _feature_enabled($name){
  $defs=_feature_defaults(); $default = isset($defs[$name]) ? ($defs[$name]['default'] ? '1':'0') : '1';
  return _setting_get(_feature_key($name), $default) === '1';
}
function _require_feature($name, $message='این قابلیت توسط مدیر سامانه غیرفعال شده است.'){
  if (!_feature_enabled($name)) Http::error($message, 403);
}
function _features_payload(){
  $out=[]; foreach(_feature_defaults() as $k=>$v){ $out[$k]=['title'=>$v['title'],'enabled'=>_feature_enabled($k),'default'=>(bool)$v['default']]; } return $out;
}

// این مسیر عمومی است (بدون نیاز به توکن) چون هم پنل و هم اپ اندروید برای رسم نقشه به آن نیاز دارند؛
// دقیقاً مثل کلید نقشهٔ گوگل/نشان که همیشه سمت کلاینت قرار می‌گیرد.
route('GET', '/api/map-config', function ($p, $b) {
  return [
    'provider' => _setting_get('map_provider', 'osm'),
    'neshan_api_key' => _setting_get('neshan_api_key', ''),
    'neshan_map_key' => _setting_get('neshan_map_key', ''),
    'google_maps_api_key' => _setting_get('google_maps_api_key', ''),
    'school_map_icon' => _setting_get('school_map_icon', '🏫'),
    'company_map_icon' => _setting_get('company_map_icon', '🏢'),
  ];
}, true);

route('PUT', '/api/admin/map-config', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  $provider = in_array($b['provider'] ?? '', ['osm', 'google', 'neshan'], true) ? $b['provider'] : 'osm';
  _setting_set('map_provider', $provider);
  if (isset($b['neshan_api_key'])) _setting_set('neshan_api_key', trim($b['neshan_api_key']));
  if (isset($b['neshan_map_key'])) _setting_set('neshan_map_key', trim($b['neshan_map_key']));
  if (isset($b['google_maps_api_key'])) _setting_set('google_maps_api_key', trim($b['google_maps_api_key']));
  if (isset($b['school_map_icon'])) _setting_set('school_map_icon', trim((string)$b['school_map_icon']) ?: '🏫');
  if (isset($b['company_map_icon'])) _setting_set('company_map_icon', trim((string)$b['company_map_icon']) ?: '🏢');
  return ['ok' => true];
}, false, 'admin');


route('POST', '/api/admin/map-config/icon', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  $kind = ($_POST['kind'] ?? '') === 'company' ? 'company' : 'school';
  if (empty($_FILES['icon']) || ($_FILES['icon']['error'] ?? 1) !== 0) Http::error('فایل آیکون ارسال نشده است.', 400);
  $path = Media::saveMapIcon($_FILES['icon'], 'map-icons/' . $kind);
  if (!$path) Http::error('فرمت یا محتوای آیکون معتبر نیست. فقط SVG، PNG، ICO و WEBP تا حجم ۲ مگابایت مجاز است.', 400);
  $url = '/api/media?path=' . rawurlencode($path);
  $key = $kind === 'company' ? 'company_map_icon' : 'school_map_icon';
  $old = _setting_get($key, '');
  _setting_set($key, $url);
  if (is_string($old) && strpos($old, '/api/media?path=') === 0) {
    $q = parse_url($old, PHP_URL_QUERY); parse_str((string)$q, $params);
    if (!empty($params['path']) && $params['path'] !== $path) Media::delete($params['path']);
  }
  return ['ok'=>true,'kind'=>$kind,'icon_url'=>$url];
}, false, 'admin');



/* ==================== تعرفه و محاسبه عمومی هزینه سرویس حمل و نقل دانش آموزی ==================== */
function _default_service_pricing() {
  return [
    'mode' => 'map', // 'map' = بر اساس مکان روی نقشه (نشان) | 'excel' = بر اساس جدول فایل اکسل تعرفه
    'vehicle_classes' => [
      ['key' => 'sedan', 'title' => 'سواری'],
      ['key' => 'van', 'title' => 'ون'],
    ],
    'rates' => new stdClass(),
  ];
}
function _service_pricing_get() {
  $raw = _setting_get('service_pricing', '');
  $d = $raw ? json_decode($raw, true) : null;
  if (!is_array($d)) $d = _default_service_pricing();
  if (empty($d['vehicle_classes']) || !is_array($d['vehicle_classes'])) $d['vehicle_classes'] = _default_service_pricing()['vehicle_classes'];
  if (!isset($d['rates']) || !is_array($d['rates'])) $d['rates'] = [];
  if (($d['mode'] ?? 'map') !== 'excel') $d['mode'] = 'map';
  return $d;
}
function _service_pricing_set($data) {
  $mode = ($data['mode'] ?? 'map') === 'excel' ? 'excel' : 'map';
  $vehicles = [];
  foreach (($data['vehicle_classes'] ?? []) as $v) {
    $key = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($v['key'] ?? ''));
    $title = trim((string)($v['title'] ?? ''));
    if ($key && $title) $vehicles[] = ['key' => $key, 'title' => $title];
  }
  if (!$vehicles) Http::error('حداقل یک کلاس خودرو باید تعریف شود.', 400);
  $rates = [];
  foreach (($data['rates'] ?? []) as $districtId => $row) {
    $did = (string)(int)$districtId;
    foreach ((array)$row as $vehicleKey => $value) {
      $vk = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$vehicleKey);
      $rates[$did][$vk] = max(0, (float)$value);
    }
  }
  $out = ['mode' => $mode, 'vehicle_classes' => $vehicles, 'rates' => $rates];
  _setting_set('service_pricing', json_encode($out, JSON_UNESCAPED_UNICODE));
  return $out;
}
function _service_pricing_set_mode($mode) {
  $d = _service_pricing_get();
  $d['mode'] = ($mode === 'excel') ? 'excel' : 'map';
  _setting_set('service_pricing', json_encode($d, JSON_UNESCAPED_UNICODE));
  return $d;
}

/* ---- تعرفهٔ مبتنی بر فایل اکسل: هر شیت = یک ردهٔ خودرو با نرخ‌گذاری متفاوت بر اساس مسافت ----
   منطق محاسبه دقیقاً مطابق فرمول جدول رسمی تاکسیرانی است: از روی «بهای خدمات راننده» (ستون سال جدید)
   به‌همراه چند درصد ثابت (مدیریت/اطلاع‌رسانی/بالاسری روی بهای راننده، و بیمه روی جمع ماهیانه) و یک مبلغ ثابت
   «گواهی صلاحیت»، مبلغ نهایی «بهای خدمات ماهیانه (پرداختی اولیاء)» بازتولید می‌شود؛ همین فرمول امکان اعمال
   ضریب ترافیک نواحی (که باید روی «بهای خدمات راننده» اعمال شود) و برون‌یابی برای مسافت‌های بیش از جدول را می‌دهد.
   مهم: فایل اکسل فقط یک‌بار، در زمان بارگذاری توسط مدیر سامانه، خوانده می‌شود؛ تمام اعداد و قواعد استخراج‌شده
   (کلاس‌های خودرو، درصدها، مبلغ ثابت گواهی صلاحیت و بازه‌های مسافت/بهای‌خدمات‌راننده) در جداول واقعی پایگاه‌داده
   ذخیره می‌شوند. از این پس، محاسبهٔ هزینه در سامانه (چه در وب و چه در ربات بله) به‌طور کامل از همین جداول
   خوانده می‌شود و هیچ وابستگی‌ای به وجود یا بارگذاری مجدد فایل اکسل ندارد. */
function _ensure_service_tariff_tables() {
  static $done = false; if ($done) return; $done = true;
  // هر CREATE TABLE جداگانه try/catch می‌شود تا مثلاً اگر یکی از جداول قبلاً با ساختار قدیمی‌تری
  // ساخته شده، کل درخواست با خطای ۵۰۰ متوقف نشود؛ از FOREIGN KEY هم عمداً صرف‌نظر شده (به‌جای آن
  // ایندکس معمولی گذاشته شده) چون برخی میزبانی‌های اشتراکی اجازهٔ ایجاد کلید خارجی نمی‌دهند و صرفاً
  // همین موضوع می‌تواند باعث شکست کل CREATE TABLE و در نتیجه خطای ۵۰۰ شود؛ صحت ارجاع‌ها در همین فایل
  // در سطح برنامه (نه پایگاه‌داده) تضمین می‌شود.
  try { Db::run("CREATE TABLE IF NOT EXISTS service_tariff_vehicle_classes (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (\Throwable $e) { error_log('service_tariff_vehicle_classes: ' . $e->getMessage()); }
  try { Db::run("CREATE TABLE IF NOT EXISTS service_tariff_bands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_class_id INT NOT NULL,
    distance_from DECIMAL(7,2) NOT NULL DEFAULT 0,
    distance_to DECIMAL(7,2) NOT NULL,
    driver_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
    INDEX idx_tariff_band_vc (vehicle_class_id, distance_to)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (\Throwable $e) { error_log('service_tariff_bands: ' . $e->getMessage()); }
  try { Db::run("CREATE TABLE IF NOT EXISTS service_district_traffic (
    district_id INT PRIMARY KEY,
    traffic_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (\Throwable $e) { error_log('service_district_traffic: ' . $e->getMessage()); }
}
function _default_service_pricing_excel() { return ['source_filename' => '', 'uploaded_at' => null, 'sheets' => []]; }
// تعرفهٔ اکسلی را کاملاً از روی جداول پایگاه‌داده می‌خواند (بدون هیچ وابستگی به فایل اکسل)
function _service_pricing_excel_get() {
  try {
    _ensure_service_tariff_tables();
    $meta = json_decode(_setting_get('service_pricing_excel_meta', ''), true) ?: [];
    $classes = Db::all("SELECT * FROM service_tariff_vehicle_classes WHERE is_active=1 ORDER BY sort_order,id");
    $sheets = [];
    foreach ($classes as $c) {
      $bandRows = Db::all("SELECT distance_from,distance_to,driver_cost FROM service_tariff_bands WHERE vehicle_class_id=? ORDER BY distance_to", [$c['id']]);
      $rows = array_map(fn($r) => ['km' => (float)$r['distance_to'], 'driver_cost' => (float)$r['driver_cost']], $bandRows);
      $sheets[] = [
        'key' => $c['key'], 'title' => $c['title'], 'sheet_name' => $c['sheet_name'], 'notes' => $c['notes'],
        'certificate_fee' => (float)$c['certificate_fee'], 'management_pct' => (float)$c['management_pct'],
        'info_pct' => (float)$c['info_pct'], 'overhead_pct' => (float)$c['overhead_pct'], 'insurance_pct' => (float)$c['insurance_pct'],
        'extra_driver_cost_per_km' => (float)$c['extra_driver_cost_per_km'], 'rows' => $rows,
      ];
    }
    return ['source_filename' => (string)($meta['source_filename'] ?? ''), 'uploaded_at' => $meta['uploaded_at'] ?? null, 'sheets' => $sheets];
  } catch (\Throwable $e) {
    error_log('_service_pricing_excel_get: ' . $e->getMessage());
    return _default_service_pricing_excel();
  }
}
// ذخیرهٔ نهایی تعرفهٔ اکسلی در جداول واقعی پایگاه‌داده (این تنها لحظه‌ای است که Excel در محاسبات دخیل است؛
// از این پس هیچ فراخوانی محاسباتی دیگری به فایل اکسل نیاز ندارد)
function _service_pricing_excel_set($data) {
  _ensure_service_tariff_tables();
  $sheets = [];
  foreach (($data['sheets'] ?? []) as $s) {
    $key = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($s['key'] ?? ''));
    $title = trim((string)($s['title'] ?? ''));
    if ($key === '' || $title === '') continue;
    $rows = [];
    foreach (($s['rows'] ?? []) as $r) {
      $km = (float)($r['km'] ?? 0); $driverCost = (float)($r['driver_cost'] ?? 0);
      if ($km > 0) $rows[] = ['km' => $km, 'driver_cost' => max(0, $driverCost)];
    }
    usort($rows, fn($a, $b) => $a['km'] <=> $b['km']);
    if (!$rows) continue;
    $sheets[] = [
      'key' => $key, 'title' => $title,
      'sheet_name' => trim((string)($s['sheet_name'] ?? '')),
      'notes' => trim((string)($s['notes'] ?? '')),
      'certificate_fee' => max(0, (float)($s['certificate_fee'] ?? 0)),
      'management_pct' => max(0, (float)($s['management_pct'] ?? 0)),
      'info_pct' => max(0, (float)($s['info_pct'] ?? 0)),
      'overhead_pct' => max(0, (float)($s['overhead_pct'] ?? 0)),
      'insurance_pct' => max(0, (float)($s['insurance_pct'] ?? 0)),
      'extra_driver_cost_per_km' => max(0, (float)($s['extra_driver_cost_per_km'] ?? 0)),
      'rows' => $rows,
    ];
  }
  if (!$sheets) Http::error('حداقل یک ردهٔ خودرو با ردیف معتبر لازم است.', 400);
  Db::pdo()->beginTransaction();
  try {
    $keys = array_map(fn($s) => $s['key'], $sheets);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    // ردهٔ خودروهایی که دیگر در دادهٔ ارسالی نیستند غیرفعال می‌شوند (حذف نمی‌شوند تا سوابق محاسبات قبلی خراب نشود)
    Db::run("UPDATE service_tariff_vehicle_classes SET is_active=0 WHERE `key` NOT IN ($placeholders)", $keys);
    foreach ($sheets as $idx => $s) {
      $existing = Db::one("SELECT id FROM service_tariff_vehicle_classes WHERE `key`=?", [$s['key']]);
      if ($existing) {
        $vcId = (int)$existing['id'];
        Db::run("UPDATE service_tariff_vehicle_classes SET title=?,sheet_name=?,notes=?,certificate_fee=?,management_pct=?,info_pct=?,overhead_pct=?,insurance_pct=?,extra_driver_cost_per_km=?,sort_order=?,is_active=1 WHERE id=?", [
          $s['title'], $s['sheet_name'], $s['notes'], $s['certificate_fee'], $s['management_pct'], $s['info_pct'], $s['overhead_pct'], $s['insurance_pct'], $s['extra_driver_cost_per_km'], $idx, $vcId
        ]);
        Db::run("DELETE FROM service_tariff_bands WHERE vehicle_class_id=?", [$vcId]);
      } else {
        $vcId = Db::insert("INSERT INTO service_tariff_vehicle_classes(`key`,title,sheet_name,notes,certificate_fee,management_pct,info_pct,overhead_pct,insurance_pct,extra_driver_cost_per_km,sort_order,is_active) VALUES(?,?,?,?,?,?,?,?,?,?,?,1)", [
          $s['key'], $s['title'], $s['sheet_name'], $s['notes'], $s['certificate_fee'], $s['management_pct'], $s['info_pct'], $s['overhead_pct'], $s['insurance_pct'], $s['extra_driver_cost_per_km'], $idx
        ]);
      }
      $prevTo = 0.0;
      foreach ($s['rows'] as $r) {
        Db::run("INSERT INTO service_tariff_bands(vehicle_class_id,distance_from,distance_to,driver_cost) VALUES(?,?,?,?)", [$vcId, $prevTo, $r['km'], $r['driver_cost']]);
        $prevTo = $r['km'];
      }
    }
    Db::pdo()->commit();
  } catch (\Throwable $e) { if (Db::pdo()->inTransaction()) Db::pdo()->rollBack(); throw $e; }
  _setting_set('service_pricing_excel_meta', json_encode(['source_filename' => trim((string)($data['source_filename'] ?? '')), 'uploaded_at' => date('c')], JSON_UNESCAPED_UNICODE));
  return _service_pricing_excel_get();
}
// یک عدد فارسی/عربی/اعشاری/با جداکنندهٔ هزارگان را به float امن تبدیل می‌کند
function _pricing_cell_to_number($v) {
  $v = trim((string)$v);
  if ($v === '') return null;
  $v = str_replace(['٬', ',', '،'], '', $v);
  $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹']; $en = ['0','1','2','3','4','5','6','7','8','9'];
  $v = str_replace($fa, $en, $v);
  return is_numeric($v) ? (float)$v : null;
}
// اعداد داخل متن توضیحات (مثل «680.000 ریال») از جداکنندهٔ هزارگان با نقطه استفاده می‌کنند، نه اعشار
function _pricing_note_amount_to_float($s) {
  $s = trim((string)$s);
  $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹']; $en = ['0','1','2','3','4','5','6','7','8','9'];
  $s = str_replace($fa, $en, $s);
  if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) return (float)str_replace('.', '', $s);
  return is_numeric($s) ? (float)$s : null;
}
// درصد نوشته‌شده داخل پرانتز سرستون را استخراج می‌کند؛ مثل «(3% بهای خدمات راننده)» -> 3
function _pricing_pct_from_header($text) {
  if (preg_match('/\(([\d\.]+)\s*%/u', (string)$text, $m)) return (float)$m[1];
  return 0.0;
}
// از متن توضیحات، نرخ اضافهٔ هر کیلومتر برای مسافت بیشتر از جدول را برای هر ردهٔ خودرو استخراج می‌کند؛
// خروجی آرایه‌ای از [عبارت ردهٔ خودرو, مبلغ] است که بعداً با عنوان هر شیت مقایسه و تطبیق داده می‌شود.
function _pricing_extract_overage_amounts($notesText) {
  $out = [];
  if (!preg_match_all('/برای خودروهای\s+([^۰-۹0-9]+?)\s+به\s*میزان\s+([۰-۹0-9\.,]+)\s*ریال/u', $notesText, $ms, PREG_SET_ORDER)) return $out;
  foreach ($ms as $m) {
    $amount = _pricing_note_amount_to_float(trim($m[2]));
    if ($amount !== null) $out[] = ['phrase' => trim($m[1]), 'amount' => $amount];
  }
  return $out;
}
// بیشترین همپوشانی کلمات بین عنوان ردهٔ خودرو یک شیت و عبارت استخراج‌شده از توضیحات را پیدا می‌کند
function _pricing_best_overage_match($vehicleTitle, $overages) {
  $bestAmount = 0.0; $bestScore = 0;
  $titleWords = preg_split('/[\s،,]+/u', (string)$vehicleTitle, -1, PREG_SPLIT_NO_EMPTY);
  foreach ($overages as $o) {
    $words = preg_split('/[\s،,]+/u', $o['phrase'], -1, PREG_SPLIT_NO_EMPTY);
    $score = 0;
    foreach ($words as $w) { if (mb_strlen($w) >= 2 && mb_strpos((string)$vehicleTitle, $w) !== false) $score++; }
    if ($score > $bestScore) { $bestScore = $score; $bestAmount = $o['amount']; }
  }
  return $bestScore > 0 ? $bestAmount : 0.0;
}
// خواندن یک فایل اکسل تعرفه (مطابق قالب رسمی سازمان تاکسیرانی): هر شیت یک ردهٔ خودرو با ستون «مسافت (کیلومتر)»،
// ستون «بهای خدمات راننده» سال جدید، درصدهای مدیریت/اطلاع‌رسانی/بالاسری/بیمه و مبلغ ثابت گواهی صلاحیت.
// متن توضیحات زیر جدول هر شیت هم استخراج و نگه‌داری می‌شود و نرخ اضافهٔ هر کیلومتر برای مسافت بیش از جدول
// در صورت ذکرشدن در توضیحات، به‌صورت خودکار برای همان ردهٔ خودرو تشخیص داده می‌شود.
function _parse_service_pricing_excel($tmpPath) {
  $sheets = Xlsx::listSheets($tmpPath);
  if (!$sheets) Http::error('شیت معتبری در فایل اکسل یافت نشد.', 400);
  $out = [];
  $sheetIdx = 0;
  $allNotesForOverage = [];
  $rawSheets = [];
  foreach ($sheets as $sh) {
    $rows = [];
    Xlsx::eachRowIn($tmpPath, $sh['target'], function ($cells, $idx) use (&$rows) { $rows[] = $cells; });
    if (!$rows) continue;
    $rawSheets[] = ['sh' => $sh, 'rows' => $rows];
  }

  foreach ($rawSheets as $entry) {
    $sheetIdx++;
    $sh = $entry['sh']; $rows = $entry['rows'];

    $sheetTitle = '';
    foreach ($rows[0] ?? [] as $v) { if (trim((string)$v) !== '') { $sheetTitle = trim((string)$v); break; } }

    // ردیف سرستون اول: سلولی با متن «مسافت»
    $h1 = -1; $distCol = 1; $vehicleTitle = '';
    foreach ($rows as $i => $row) {
      foreach ($row as $ci => $v) {
        if (mb_strpos((string)$v, 'مسافت') !== false) { $h1 = $i; $distCol = $ci; break 2; }
      }
    }
    if ($h1 >= 0) {
      foreach ($rows[$h1] as $ci => $v) {
        $t = trim((string)$v);
        if ($t !== '' && mb_strpos($t, 'خودرو') !== false) { $vehicleTitle = $t; break; }
      }
    }
    // ردیف سرستون دوم (زیرستون‌ها): شامل چند ستون درصدی و ستون‌های مبلغی
    $h2 = -1; $driverCol = null; $certCol = null; $mgmtCol = null; $infoCol = null; $overheadCol = null; $insuranceCol = null; $finalCol = null;
    for ($i = max($h1, 0); $i < count($rows); $i++) {
      foreach ($rows[$i] as $ci => $v) {
        if (mb_strpos((string)$v, 'پرداختی اولیا') !== false) { $h2 = $i; break; }
      }
      if ($h2 >= 0) break;
    }
    if ($h2 < 0) $h2 = $h1 >= 0 ? $h1 + 1 : 0;
    foreach ($rows[$h2] ?? [] as $ci => $v) {
      $t = (string)$v;
      if (mb_strpos($t, 'بهای خدمات راننده') !== false && mb_strpos($t, '۱۴۰۵') === false && strpos($t, '1405') !== false) $driverCol = $ci;
      elseif (mb_strpos($t, 'گواهی صلاحیت') !== false) $certCol = $ci;
      elseif (mb_strpos($t, 'مدیریت') !== false) $mgmtCol = $ci;
      elseif (mb_strpos($t, 'اطلاع') !== false) $infoCol = $ci;
      elseif (mb_strpos($t, 'بالاسری') !== false) $overheadCol = $ci;
      elseif (mb_strpos($t, 'بیمه') !== false) $insuranceCol = $ci;
      elseif (mb_strpos($t, 'پرداختی اولیا') !== false) $finalCol = $ci;
    }
    if ($driverCol === null) { // یافت‌نشدن سال ۱۴۰۵ به‌شکل متنی؛ آخرین ستون «بهای خدمات راننده» را انتخاب می‌کنیم
      foreach ($rows[$h2] ?? [] as $ci => $v) { if (mb_strpos((string)$v, 'بهای خدمات راننده') !== false) $driverCol = $ci; }
    }
    if ($driverCol === null) $driverCol = $distCol + 1;
    if ($finalCol === null) { $lastRow = $rows[$h2] ?? []; $finalCol = $lastRow ? max(array_keys($lastRow)) : ($driverCol + 1); }
    if (!$vehicleTitle) $vehicleTitle = $sheetTitle ?: $sh['name'];

    $mgmtPct = $mgmtCol !== null ? _pricing_pct_from_header($rows[$h2][$mgmtCol] ?? '') : 0.0;
    $infoPct = $infoCol !== null ? _pricing_pct_from_header($rows[$h2][$infoCol] ?? '') : 0.0;
    $overheadPct = $overheadCol !== null ? _pricing_pct_from_header($rows[$h2][$overheadCol] ?? '') : 0.0;
    $insurancePct = $insuranceCol !== null ? _pricing_pct_from_header($rows[$h2][$insuranceCol] ?? '') : 0.0;

    // ردیف‌های داده: بلافاصله بعد از h2، تا وقتی سلول ستون «مسافت» عددی باشد
    $dataRows = []; $certFee = 0.0;
    $r = $h2 + 1;
    for (; $r < count($rows); $r++) {
      $kmRaw = $rows[$r][$distCol] ?? null;
      $km = $kmRaw !== null ? _pricing_cell_to_number($kmRaw) : null;
      if ($km === null || $km <= 0) break;
      $driverCost = _pricing_cell_to_number($rows[$r][$driverCol] ?? null) ?? 0;
      if ($certCol !== null && $certFee === 0.0) $certFee = _pricing_cell_to_number($rows[$r][$certCol] ?? null) ?? 0.0;
      $dataRows[] = ['km' => $km, 'driver_cost' => $driverCost];
    }
    // متن توضیحات: باقیماندهٔ ردیف‌ها پس از پایان جدول داده
    $notes = [];
    for (; $r < count($rows); $r++) {
      foreach ($rows[$r] as $v) {
        $t = trim((string)$v);
        if ($t !== '') { $notes[] = $t; break; }
      }
    }
    $notesText = implode("\n", $notes);
    if ($notesText !== '') $allNotesForOverage[] = $notesText;

    $out[] = [
      'key' => 'sheet_' . $sheetIdx,
      'title' => mb_substr($vehicleTitle, 0, 250),
      'sheet_name' => $sh['name'],
      'notes' => $notesText,
      'certificate_fee' => $certFee,
      'management_pct' => $mgmtPct,
      'info_pct' => $infoPct,
      'overhead_pct' => $overheadPct,
      'insurance_pct' => $insurancePct,
      'extra_driver_cost_per_km' => 0.0, // زیر پر می‌شود
      'rows' => $dataRows,
    ];
  }
  if (!$out) Http::error('هیچ جدول تعرفه‌ای در فایل اکسل شناسایی نشد. لطفاً از قالب صحیح استفاده کنید.', 400);

  // نرخ اضافهٔ هر کیلومتر (برای مسافت بیش از جدول) از هر متن توضیحاتی که در کل فایل یافت شد استخراج و به شیت متناظر نسبت داده می‌شود
  $allOverages = [];
  foreach ($allNotesForOverage as $nt) $allOverages = array_merge($allOverages, _pricing_extract_overage_amounts($nt));
  if ($allOverages) {
    foreach ($out as &$s) { $s['extra_driver_cost_per_km'] = _pricing_best_overage_match($s['title'], $allOverages); }
    unset($s);
  }
  return $out;
}
// محاسبهٔ «بهای خدمات راننده» برای یک مسافت مشخص از روی جدول یک شیت؛ نزدیک‌ترین ردیف با مسافت مساوی یا
// بزرگ‌تر انتخاب می‌شود و برای مسافت بیشتر از آخرین ردیف جدول، نرخ اضافهٔ هر کیلومتر (در صورت تعریف) اعمال می‌شود.
function _service_pricing_excel_driver_cost($sheet, $km) {
  $rows = $sheet['rows'] ?? [];
  if (!$rows) return null;
  $first = $rows[0]; $last = $rows[count($rows) - 1];
  if ($km <= $first['km']) return $first['driver_cost'];
  foreach ($rows as $row) { if ($km <= $row['km']) return $row['driver_cost']; }
  $extra = (float)($sheet['extra_driver_cost_per_km'] ?? 0);
  $over = $km - $last['km'];
  return $last['driver_cost'] + ($extra > 0 ? $over * $extra : 0);
}
// مبلغ نهایی «بهای خدمات ماهیانه (پرداختی اولیاء)» را دقیقاً طبق فرمول جدول رسمی بازمی‌سازد؛ امکان اعمال
// ضریب ترافیک ناحیه (که طبق توضیحات فایل باید روی «بهای خدمات راننده» اضافه شود) را هم فراهم می‌کند.
function _service_pricing_excel_compute_cost($sheet, $roundTripKm, $trafficPct = 0.0) {
  $driverCost = _service_pricing_excel_driver_cost($sheet, $roundTripKm);
  if ($driverCost === null) return null;
  $driverCost *= (1 + max(0, (float)$trafficPct) / 100);
  $multiplier = 1 + (((float)$sheet['management_pct'] + (float)$sheet['info_pct'] + (float)$sheet['overhead_pct']) / 100);
  $monthlySum = $driverCost * $multiplier + (float)$sheet['certificate_fee'];
  $final = $monthlySum * (1 + ((float)$sheet['insurance_pct'] / 100));
  return $final;
}

/* ---- ضریب ترافیک نواحی، مخصوص حالت «فایل اکسل»: طبق توضیحات فایل، به نواحی ۲/۵/۷ ده‌درصد و به نواحی ۳/۴/۶
   بیست‌وپنج‌درصد به «بهای خدمات راننده» افزوده می‌شود. چون شمارهٔ ناحیه در این سامانه آزادانه در عنوان ناحیه ثبت
   می‌شود، ابتدا از روی عدد داخل عنوان حدس زده می‌شود و مدیر سامانه می‌تواند هر ناحیه را در تنظیمات ویرایش کند. ---- */
function _pricing_default_traffic_pct_from_title($title) {
  if (preg_match('/(\d+)/u', strtr((string)$title, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']), $m)) {
    $n = (int)$m[1];
    if (in_array($n, [2, 5, 7], true)) return 10.0;
    if (in_array($n, [3, 4, 6], true)) return 25.0;
  }
  return 0.0;
}
function _service_pricing_excel_traffic_get() {
  try {
    _ensure_service_tariff_tables();
    $rows = Db::all("SELECT district_id,traffic_pct FROM service_district_traffic");
    $out = [];
    foreach ($rows as $r) $out[(string)$r['district_id']] = (float)$r['traffic_pct'];
    return $out;
  } catch (\Throwable $e) { error_log('_service_pricing_excel_traffic_get: ' . $e->getMessage()); return []; }
}
function _service_pricing_excel_traffic_set($data) {
  _ensure_service_tariff_tables();
  $out = [];
  foreach ((array)$data as $districtId => $pct) {
    $did = (int)$districtId;
    if ($did <= 0) continue;
    $p = max(0, min(200, (float)$pct));
    Db::run("INSERT INTO service_district_traffic(district_id,traffic_pct) VALUES(?,?) ON DUPLICATE KEY UPDATE traffic_pct=VALUES(traffic_pct)", [$did, $p]);
    $out[(string)$did] = $p;
  }
  return $out;
}
function _service_pricing_excel_traffic_for_district($districtId) {
  if (!$districtId) return 0.0;
  try {
    _ensure_service_tariff_tables();
    $row = Db::one("SELECT traffic_pct FROM service_district_traffic WHERE district_id=?", [(int)$districtId]);
    if ($row) return (float)$row['traffic_pct'];
    $d = Db::one("SELECT title FROM districts WHERE id=?", [(int)$districtId]);
    return $d ? _pricing_default_traffic_pct_from_title($d['title']) : 0.0;
  } catch (\Throwable $e) { error_log('_service_pricing_excel_traffic_for_district: ' . $e->getMessage()); return 0.0; }
}
function _coord_to_float($v) {
  if (is_string($v)) $v = str_replace(',', '.', trim($v));
  return is_numeric($v) ? (float)$v : 0.0;
}
function _valid_iran_coord($lat, $lng) {
  // محدوده عمومی ایران؛ از ارسال مختصات جابه‌جا یا اشتباه به نشان جلوگیری می‌کند.
  return $lat >= 24 && $lat <= 40.5 && $lng >= 43 && $lng <= 64.5;
}
function _safe_route_point($lat, $lng) {
  $lat = _coord_to_float($lat); $lng = _coord_to_float($lng);
  if (!_valid_iran_coord($lat, $lng)) return null;
  return ['lat' => $lat, 'lng' => $lng];
}
function _normalize_neshan_location($loc) {
  if (!$loc) return null;
  // طبق مستندات نشان، start_location در پاسخ به صورت [longitude, latitude] برمی‌گردد.
  if (is_array($loc) && array_key_exists(0, $loc) && array_key_exists(1, $loc)) {
    return _safe_route_point($loc[1], $loc[0]);
  }
  if (is_array($loc)) {
    $lat = $loc['lat'] ?? $loc['latitude'] ?? null;
    $lng = $loc['lng'] ?? $loc['lon'] ?? $loc['longitude'] ?? null;
    return _safe_route_point($lat, $lng);
  }
  return null;
}
function _neshan_route($originLat, $originLng, $destLat, $destLng) {
  $originLat = _coord_to_float($originLat); $originLng = _coord_to_float($originLng);
  $destLat = _coord_to_float($destLat); $destLng = _coord_to_float($destLng);
  if (!_valid_iran_coord($originLat, $originLng)) Http::error('موقعیت منزل معتبر نیست یا خارج از محدوده ایران است. لطفاً منزل را روی نقشه شهر انتخاب کنید.', 400);
  if (!_valid_iran_coord($destLat, $destLng)) Http::error('موقعیت مدرسه معتبر نیست. مختصات مدرسه را در پنل اصلاح کنید.', 400);
  $key = _setting_get('neshan_api_key', '') ?: _setting_get('neshan_map_key', '');
  if (!$key) Http::error('کلید وب‌سرویس نشان در تنظیمات نقشه ثبت نشده است.', 400);

  // مستندات رسمی نشان برای Direction API پارامترها را به صورت latitude,longitude و هدر Api-Key معرفی کرده است.
  // ابتدا نسخه v2 مطابق مستندات فعلی API فراخوانی می‌شود و اگر سرویس قدیمی برای کلید فعال نبود، v4 نیز به عنوان سازگاری پشتیبان امتحان می‌شود.
  $origin = sprintf('%.7F,%.7F', $originLat, $originLng);
  $dest = sprintf('%.7F,%.7F', $destLat, $destLng);
  $urls = [
    'https://api.neshan.org/v2/direction?origin=' . rawurlencode($origin) . '&destination=' . rawurlencode($dest) . '&avoidTrafficZone=false&avoidOddEvenZone=false&alternative=false',
    'https://api.neshan.org/v4/direction?type=car&origin=' . rawurlencode($origin) . '&destination=' . rawurlencode($dest) . '&alternative=false',
  ];
  $lastErr = '';
  foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 25,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTPHEADER => ['Api-Key: ' . $key, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false && $code < 400) {
      $data = json_decode($body, true);
      if (is_array($data) && !empty($data['routes'])) return $data;
      $lastErr = 'پاسخ نشان فاقد مسیر معتبر بود.';
    } else {
      $lastErr = $err ?: ('HTTP ' . $code);
    }
  }
  Http::error('محاسبه مسیر با نشان ناموفق بود: ' . $lastErr, 502);
}
function _decode_polyline_points($encoded) {
  $points = []; $index = 0; $lat = 0; $lng = 0; $len = strlen($encoded);
  while ($index < $len) {
    $b = 0; $shift = 0; $result = 0;
    do { if ($index >= $len) break 2; $b = ord($encoded[$index++]) - 63; $result |= ($b & 0x1f) << $shift; $shift += 5; } while ($b >= 0x20);
    $dlat = (($result & 1) ? ~($result >> 1) : ($result >> 1)); $lat += $dlat;
    $shift = 0; $result = 0;
    do { if ($index >= $len) break 2; $b = ord($encoded[$index++]) - 63; $result |= ($b & 0x1f) << $shift; $shift += 5; } while ($b >= 0x20);
    $dlng = (($result & 1) ? ~($result >> 1) : ($result >> 1)); $lng += $dlng;
    $p = _safe_route_point($lat / 1e5, $lng / 1e5);
    if ($p) $points[] = $p;
  }
  return $points;
}
function _extract_distance_value($d) {
  if (is_array($d)) return isset($d['value']) ? (float)$d['value'] : 0.0;
  return is_numeric($d) ? (float)$d : 0.0;
}
function _extract_route_summary($data) {
  $route = $data['routes'][0] ?? null;
  if (!$route) Http::error('مسیری بین منزل و مدرسه یافت نشد.', 502);
  $distance = 0.0; $points = [];

  $overview = $route['overview_polyline']['points'] ?? ($route['overview_polyline'] ?? ($route['polyline']['points'] ?? ($route['polyline'] ?? null)));
  if (is_string($overview) && $overview !== '') $points = _decode_polyline_points($overview);

  foreach (($route['legs'] ?? []) as $leg) {
    if (!empty($leg['distance'])) $distance += _extract_distance_value($leg['distance']);
    foreach (($leg['steps'] ?? []) as $step) {
      $poly = $step['polyline'] ?? ($step['encoded_polyline'] ?? null);
      if (is_array($poly)) $poly = $poly['points'] ?? ($poly['encodedPolyline'] ?? null);
      if (is_string($poly) && $poly !== '') {
        $decoded = _decode_polyline_points($poly);
        if ($decoded) $points = array_merge($points, $decoded);
      }
      foreach (['start_location','startLocation','end_location','endLocation'] as $k) {
        if (!empty($step[$k])) {
          $p = _normalize_neshan_location($step[$k]);
          if ($p) $points[] = $p;
        }
      }
    }
  }
  if ($distance <= 0 && isset($route['distance'])) $distance = _extract_distance_value($route['distance']);

  // حذف نقاط تکراری و نامعتبر تا مسیر به کشور دیگر پرش نکند.
  $clean = [];
  foreach ($points as $p) {
    $p = _safe_route_point($p['lat'] ?? 0, $p['lng'] ?? 0);
    if (!$p) continue;
    $key = round($p['lat'], 6) . ',' . round($p['lng'], 6);
    $clean[$key] = $p;
  }
  $points = array_values($clean);
  if ($distance <= 0) Http::error('فاصله مسیر از نشان دریافت نشد.', 502);
  if (count($points) < 2) Http::error('هندسه مسیر از نشان دریافت نشد.', 502);
  return ['distance_meters' => (int)round($distance), 'route_points' => $points];
}

// عمومی: تنظیمات نمایشی تعرفه برای صفحهٔ اصلی. در حالت «فایل اکسل»، کلاس‌های خودرو از روی شیت‌های
// وارد‌شده ساخته می‌شوند تا فرم محاسبهٔ هزینه در صفحهٔ اول بدون تغییر با هر دو حالت کار کند.
route('GET', '/api/public/service-pricing', function ($p, $b) {
  if (!_feature_enabled('public_cost_calculator_map') && !_feature_enabled('public_cost_calculator_quick')) Http::error('این قابلیت توسط مدیر سامانه غیرفعال شده است.', 403);
  $pricing = _service_pricing_get();
  if (($pricing['mode'] ?? 'map') === 'excel') {
    $excel = _service_pricing_excel_get();
    return [
      'mode' => 'excel',
      'vehicle_classes' => array_map(fn($s) => ['key' => $s['key'], 'title' => $s['title']], $excel['sheets']),
      'rates' => new stdClass(),
    ];
  }
  return $pricing;
}, true);

route('GET', '/api/admin/service-pricing', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  return _service_pricing_get();
}, false, 'admin');

route('PUT', '/api/admin/service-pricing', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  return _service_pricing_set($b);
}, false, 'admin');

route('PUT', '/api/admin/service-pricing-mode', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  $mode = ($b['mode'] ?? '') === 'excel' ? 'excel' : 'map';
  if ($mode === 'excel') {
    $excel = _service_pricing_excel_get();
    if (empty($excel['sheets'])) Http::error('پیش از فعال‌سازی حالت فایل اکسل، ابتدا یک فایل تعرفهٔ معتبر بارگذاری کنید.', 400);
  }
  return _service_pricing_set_mode($mode);
}, false, 'admin');

// دریافت جدول تعرفهٔ اکسلی ذخیره‌شده (برای نمایش/ویرایش در پنل مدیریت)
route('GET', '/api/admin/service-pricing-excel', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  return _service_pricing_excel_get();
}, false, 'admin');

// بارگذاری فایل اکسل تعرفه (قالب رسمی: هر شیت یک ردهٔ خودرو، ستون «مسافت (کیلومتر)» و ستون نهایی
// «بهای خدمات ماهیانه (پرداختی اولیاء)»)؛ خروجی، پیش‌نمایش قابل‌ویرایش جدول‌هاست و هنوز ذخیرهٔ نهایی انجام نشده.
route('POST', '/api/admin/service-pricing-excel/upload', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== 0) Http::error('فایلی ارسال نشد.', 400);
  $name = (string)($_FILES['file']['name'] ?? '');
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($ext !== 'xlsx') Http::error('فقط فایل اکسل با فرمت xlsx پذیرفته می‌شود.', 400);
  $sheets = _parse_service_pricing_excel($_FILES['file']['tmp_name']);
  return ['source_filename' => $name, 'sheets' => $sheets];
}, false, 'admin');

// ذخیرهٔ نهایی جدول تعرفهٔ اکسلی (پس از بررسی/ویرایش احتمالی پیش‌نمایش توسط مدیر)
route('PUT', '/api/admin/service-pricing-excel', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  return _service_pricing_excel_set($b);
}, false, 'admin');

// ضرایب ترافیک نواحی (مخصوص حالت «فایل اکسل»): فهرست همهٔ نواحی به همراه درصد ثبت‌شده یا پیشنهادی
route('GET', '/api/admin/service-pricing-excel-traffic', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  _ensure_service_tariff_tables();
  $districts = Db::all("SELECT id,title FROM districts WHERE is_active=1 ORDER BY title");
  $saved = _service_pricing_excel_traffic_get();
  return array_map(function ($d) use ($saved) {
    $has = isset($saved[(string)$d['id']]);
    return [
      'district_id' => (int)$d['id'], 'district_title' => $d['title'],
      'traffic_pct' => $has ? (float)$saved[(string)$d['id']] : _pricing_default_traffic_pct_from_title($d['title']),
      'is_custom' => $has,
    ];
  }, $districts);
}, false, 'admin');

route('PUT', '/api/admin/service-pricing-excel-traffic', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  return _service_pricing_excel_traffic_set($b['traffic'] ?? $b);
}, false, 'admin');

// موتور مستقل محاسبهٔ هزینه: کاملاً از روی دادهٔ ذخیره‌شده در پایگاه‌داده کار می‌کند (چه حالت نقشه، چه حالت
// اکسل) و هیچ وابستگی‌ای به فایل اکسل ندارد. هم از مسیر وب و هم از ربات بله فراخوانی می‌شود.
// $oneWayKm = مسافت یک‌طرفهٔ منزل تا مدرسه (از نشان یا ورودی دستی کاربر)؛ $districtId = ناحیهٔ آموزش‌وپرورش مدرسه.
// $lookupKm = مسافتی که مستقیماً برای جست‌وجو در جدول تعرفهٔ اکسلی/ضرب در نرخ نقشه استفاده می‌شود.
// $displayKm = مسافتی که به کاربر نمایش داده می‌شود (پیش‌فرض همان $lookupKm است، مگر آنکه صراحتاً مقدار
// دیگری داده شود؛ مثلاً در محاسبه‌گر نقشه/GPS که مسافت یک‌طرفهٔ واقعی را نشان می‌دهد ولی برای جدول تعرفهٔ
// اکسلی طبق توضیح فایل مرجع، مسافت رفت‌وبرگشت (دو برابر) را جست‌وجو می‌کند).
function _service_cost_calculate($vehicleClass, $lookupKm, $districtId, $displayKm = null) {
  if ($displayKm === null) $displayKm = $lookupKm;
  $pricing = _service_pricing_get();
  if (($pricing['mode'] ?? 'map') === 'excel') {
    $excel = _service_pricing_excel_get();
    $sheet = null; foreach ($excel['sheets'] as $s) if ($s['key'] === $vehicleClass) $sheet = $s;
    if (!$sheet) Http::error('کلاس خودرو معتبر نیست.', 400);
    $trafficPct = _service_pricing_excel_traffic_for_district($districtId);
    $cost = _service_pricing_excel_compute_cost($sheet, $lookupKm, $trafficPct);
    if ($cost === null) Http::error('برای این ردهٔ خودرو در جدول تعرفه، ردیفی ثبت نشده است.', 400);
    return [
      'vehicle_class' => $vehicleClass, 'vehicle_title' => $sheet['title'],
      'district_id' => $districtId ?: null,
      'distance_km' => round($displayKm, 2), 'round_trip_km' => round($lookupKm, 2),
      'traffic_percent' => $trafficPct, 'rate_per_km' => 0, 'cost' => round($cost), 'pricing_mode' => 'excel',
      'currency' => 'rial',
    ];
  }
  if (!$districtId) Http::error('ناحیهٔ آموزش و پرورش را انتخاب کنید.', 400);
  $vehicle = null; foreach ($pricing['vehicle_classes'] as $v) if ($v['key'] === $vehicleClass) $vehicle = $v;
  if (!$vehicle) Http::error('کلاس خودرو معتبر نیست.', 400);
  $rate = (float)($pricing['rates'][(string)$districtId][$vehicleClass] ?? 0);
  if ($rate <= 0) Http::error('برای این ناحیه و کلاس خودروی انتخابی تعرفه‌ای ثبت نشده است.', 400);
  return [
    'vehicle_class' => $vehicleClass, 'vehicle_title' => $vehicle['title'],
    'district_id' => $districtId,
    'distance_km' => round($displayKm, 2), 'round_trip_km' => round($lookupKm, 2),
    'traffic_percent' => 0, 'rate_per_km' => $rate, 'cost' => round($displayKm * $rate), 'pricing_mode' => 'map',
    'currency' => 'toman',
  ];
}

// محاسبهٔ سریع هزینهٔ سرویس بدون نیاز به نقشه/GPS: کاربر خودش ناحیه، کلاس خودرو و مسافت (کیلومتر) را
// وارد می‌کند. همین عدد مسافت مستقیماً (بدون دوبرابر کردن) برای جست‌وجو در تعرفه استفاده می‌شود.
route('GET', '/api/public/service-cost-quick', function ($p, $b) {
  _require_feature('public_cost_calculator_quick');
  $vehicleClass = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['vehicle_class'] ?? ''));
  $districtId = (int)($_GET['district_id'] ?? 0);
  $km = _pricing_cell_to_number($_GET['km'] ?? '') ?? 0;
  if (!$vehicleClass) Http::error('کلاس خودرو را انتخاب کنید.', 400);
  if (!$districtId) Http::error('ناحیهٔ آموزش و پرورش را انتخاب کنید.', 400);
  if ($km <= 0) Http::error('مسافت را وارد کنید.', 400);
  return _service_cost_calculate($vehicleClass, $km, $districtId);
}, true);

route('GET', '/api/public/service-cost', function ($p, $b) {
  _require_feature('public_cost_calculator_map');
  $schoolId = (int)($_GET['school_id'] ?? 0);
  $vehicleClass = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['vehicle_class'] ?? ''));
  $homeLat = (float)($_GET['home_lat'] ?? 0); $homeLng = (float)($_GET['home_lng'] ?? 0);
  if (!$schoolId || !$vehicleClass || !$homeLat || !$homeLng) Http::error('مدرسه، کلاس خودرو و مکان منزل الزامی است.', 400);
  $school = Db::one("SELECT s.id,s.name,s.lat,s.lng,s.district_id,d.title district_title FROM schools s LEFT JOIN districts d ON d.id=s.district_id WHERE s.id=? AND s.lat IS NOT NULL AND s.lng IS NOT NULL", [$schoolId]);
  if (!$school) Http::error('مدرسه یا موقعیت مدرسه یافت نشد.', 404);
  $route = _extract_route_summary(_neshan_route($homeLat, $homeLng, (float)$school['lat'], (float)$school['lng']));
  $oneWayKm = $route['distance_meters'] / 1000;
  $pricingMode = (_service_pricing_get()['mode'] ?? 'map');
  // فقط در حالت «فایل اکسل» طبق توضیح فایل مرجع، مسافت رفت‌وبرگشت (دو برابر مسیر یک‌طرفهٔ واقعی نشان) مبنای
  // جست‌وجوی جدول تعرفه قرار می‌گیرد؛ در حالت «نقشه» همان نرخ ساده هر کیلومتر × مسافت یک‌طرفه محاسبه می‌شود.
  $lookupKm = $pricingMode === 'excel' ? ($oneWayKm * 2) : $oneWayKm;
  $result = _service_cost_calculate($vehicleClass, $lookupKm, (int)$school['district_id'], $oneWayKm);
  return array_merge($result, [
    'school_id' => $schoolId, 'school_name' => $school['name'],
    'district_title' => $school['district_title'],
    'distance_meters' => $route['distance_meters'],
    'route_points' => $route['route_points'],
  ]);
}, true);

/* ==================== برندینگ سایت (لوگو/هدر/عنوان) و سیاست‌های عمومی ==================== */
// عمومی — پنل و اپ برای نمایش لوگو/عنوان و بررسی مجاز بودن «لیست مدارس نماینده» به این نیاز دارند
route('GET', '/api/app-config', function ($p, $b) {
  $logoUrl = (string)_setting_get('logo_url', '');
  // از بازگرداندن مسیر قدیمی/حذف‌شده جلوگیری می‌کنیم تا صفحه دائماً 404 ندهد.
  if ($logoUrl !== '') {
    $logoPath = '';
    $parts = parse_url($logoUrl);
    if (($parts['path'] ?? '') === '/api/media' && !empty($parts['query'])) {
      parse_str($parts['query'], $q); $logoPath = (string)($q['path'] ?? '');
    } elseif (strpos($logoUrl, 'uploads/') === 0) {
      $logoPath = $logoUrl;
    }
    if ($logoPath !== '' && !Media::exists($logoPath)) {
      $logoUrl = '/assets/logo.png';
    }
  }
  if ($logoUrl === '') $logoUrl = '/assets/logo.png';
  return [
    'site_title' => _setting_get('site_title', 'سامانهٔ سرویس حمل و نقل دانش آموزی مشهد'),
    'header_subtitle' => _setting_get('header_subtitle', 'سازمان مدیریت و نظارت بر تاکسیرانی مشهد'),
    'logo_url' => $logoUrl,
    'rep_can_view_school_list' => _setting_get('rep_can_view_school_list', '1') === '1',
    'show_school_photos' => _setting_get('show_school_photos', '0') === '1',
    'school_photo_as_map_marker' => _setting_get('school_photo_as_map_marker', '0') === '1',
    'session_idle_enabled' => _setting_get('session_idle_enabled', '1') === '1',
    'session_idle_timeout_minutes' => _session_idle_minutes(),
    'session_idle_warning' => _setting_get('session_idle_warning', '1') === '1',
    'features' => _features_payload(),
  ];
}, true);


route('GET', '/api/admin/bale-config', function ($p, $b, $u) {
  _require_role($u, ['super_admin','complaint_manager']);
  $secret = _setting_get('bale_webhook_secret', '');
  $base = rtrim((string)(_cfg()['public_url'] ?? ''), '/');
  if (!$base) $base = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''), '/');
  return [
    'enabled' => _setting_get('bale_bot_enabled', '0') === '1',
    'has_token' => _setting_get('bale_bot_token', '') !== '',
    'webhook_secret' => $secret,
    'webhook_url' => $secret ? $base . '/api/bale/complaints-webhook/' . rawurlencode($secret) : '',
    'bot_username' => _setting_get('bale_bot_username', 'stsmbot'),
    'welcome_message' => _setting_get('bale_welcome_message', 'به سامانه شکایات حمل و نقل دانش‌آموزی خوش آمدید.'),
    'menu_text' => _setting_get('bale_menu_text', "ثبت شکایت\nپیگیری شکایت\nمحاسبه هزینه\nراهنما"),
    'help_text' => _setting_get('bale_help_text', 'برای ثبت شکایت عبارت «ثبت شکایت»، برای پیگیری عبارت «پیگیری کدپیگیری» و برای محاسبهٔ هزینهٔ سرویس عبارت «محاسبه هزینه» را ارسال کنید.'),
  ];
}, false, 'admin');

route('PUT', '/api/admin/bale-config', function ($p, $b, $u) {
  _require_role($u, ['super_admin','complaint_manager']);
  _setting_set('bale_bot_enabled', !empty($b['enabled']) ? '1' : '0');
  if (array_key_exists('bot_token', $b) && trim((string)$b['bot_token']) !== '') {
    $token = trim((string)$b['bot_token']);
    if (!preg_match('/^[A-Za-z0-9:_\-]{20,220}$/', $token)) Http::error('ساختار توکن ربات بله معتبر نیست.', 400);
    _setting_set('bale_bot_token', $token);
  }
  $secret = trim((string)($b['webhook_secret'] ?? ''));
  if ($secret === '') $secret = bin2hex(random_bytes(24));
  if (strlen($secret) < 20) Http::error('کلید محرمانه وب‌هوک باید حداقل ۲۰ نویسه باشد.', 400);
  _setting_set('bale_webhook_secret', $secret);
  $base = rtrim((string)(_cfg()['public_url'] ?? ''), '/');
  if (!$base) $base = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''), '/');
  return ['ok'=>true,'webhook_url'=>$base . '/api/bale/complaints-webhook/' . rawurlencode($secret)];
}, false, 'admin');

route('PUT', '/api/admin/app-config', function ($p, $b, $u) {
  _require_role($u, ['super_admin']);
  if (isset($b['site_title'])) _setting_set('site_title', trim($b['site_title']));
  if (isset($b['header_subtitle'])) _setting_set('header_subtitle', trim($b['header_subtitle']));
  if (isset($b['rep_can_view_school_list'])) _setting_set('rep_can_view_school_list', $b['rep_can_view_school_list'] ? '1' : '0');
  if (isset($b['show_school_photos'])) _setting_set('show_school_photos', $b['show_school_photos'] ? '1' : '0');
  if (isset($b['school_photo_as_map_marker'])) _setting_set('school_photo_as_map_marker', (!empty($b['show_school_photos']) && $b['school_photo_as_map_marker']) ? '1' : '0');
  if (isset($b['session_idle_enabled'])) _setting_set('session_idle_enabled', $b['session_idle_enabled'] ? '1' : '0');
  if (isset($b['session_idle_timeout_minutes'])) _setting_set('session_idle_timeout_minutes', (string)max(5,min(120,(int)$b['session_idle_timeout_minutes'])));
  if (isset($b['session_idle_warning'])) _setting_set('session_idle_warning', $b['session_idle_warning'] ? '1' : '0');
  if (isset($b['features']) && is_array($b['features'])) {
    foreach (_feature_defaults() as $key => $meta) {
      if (array_key_exists($key, $b['features'])) _setting_set(_feature_key($key), $b['features'][$key] ? '1' : '0');
    }
  }
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

/* ==================== کانال ارسال کد و اعلان ==================== */
function _delivery_channel($kind='notification'){
  $key = $kind === 'otp' ? 'otp_delivery_channel' : 'notification_delivery_channel';
  $v = (string)_setting_get($key, 'sms');
  return in_array($v, ['sms','bale','both'], true) ? $v : 'sms';
}
function _bale_chat_by_mobile($mobile){
  try { $r=Db::one("SELECT chat_id FROM bale_subscribers WHERE mobile=? AND is_active=1 ORDER BY last_seen_at DESC,id DESC LIMIT 1",[_normalize_mobile($mobile)]); return $r['chat_id']??null; }
  catch (\Throwable $e) { return null; }
}
function _deliver_message($mobile,$text,$kind='notification',$sentBy=null,$forceChannel=null){
  $channel=$forceChannel ?: _delivery_channel($kind==='otp'?'otp':'notification');
  $result=['ok'=>false,'sms'=>false,'bale'=>false,'channel'=>$channel,'errors'=>[]];
  if($channel==='sms'||$channel==='both'){
    $r=_send_sms_safe($mobile,$text,$kind,$sentBy); $result['sms']=!empty($r['ok']);
    if(!$result['sms']&&!empty($r['error'])) $result['errors'][]=(string)$r['error'];
  }
  if($channel==='bale'||$channel==='both'){
    $chat=_bale_chat_by_mobile($mobile);
    if($chat) $result['bale']=_bale_send($chat,$text);
    else $result['errors'][]='شماره همراه هنوز در ربات بله عضو نشده است.';
  }
  $result['ok']=$result['sms']||$result['bale'];
  return $result;
}
route('GET','/api/admin/delivery-config',function($p,$b,$u){
  _require_role($u,['super_admin','complaint_manager']);
  return ['otp_channel'=>_delivery_channel('otp'),'notification_channel'=>_delivery_channel('notification')];
},false,'admin');
route('PUT','/api/admin/delivery-config',function($p,$b,$u){
  _require_role($u,['super_admin','complaint_manager']);
  foreach(['otp_channel'=>'otp_delivery_channel','notification_channel'=>'notification_delivery_channel'] as $in=>$key){
    if(isset($b[$in])){ $v=(string)$b[$in]; if(!in_array($v,['sms','bale','both'],true)) Http::error('روش ارسال انتخاب‌شده معتبر نیست.',400); _setting_set($key,$v); }
  }
  return ['ok'=>true];
},false,'admin');

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
  $r = Sms::send($row['phone'], "کد بازیابی رمز عبور سامانهٔ سرویس حمل و نقل دانش آموزی مشهد: $code\nاین کد تا ۱۰ دقیقه معتبر است.", 'password_reset');
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


// خروجی جامع XLSX از تمام اطلاعات مدیریتی سامانه
route('GET', '/api/admin/reports/full-export', function ($p, $b, $u) {
  _ensure_school_columns(); _ensure_school_field_defs_table();
  $rows=[];
  $companies=Db::all("SELECT c.*, (SELECT COUNT(*) FROM schools s WHERE s.company_id=c.id) sc,
      (SELECT COUNT(*) FROM schools s WHERE s.company_id=c.id AND s.location_status='done') dc,
      (SELECT COUNT(*) FROM company_users cu WHERE cu.company_id=c.id) uc FROM companies c ORDER BY c.title");
  foreach($companies as $c) $rows[]=['شرکت‌ها',$c['title'],$c['manager_name'],$c['phone'],$c['address'],$c['is_active']?'فعال':'غیرفعال',$c['sc'],$c['dc'],$c['uc']];
  foreach(Db::all("SELECT cu.*, c.title company_title FROM company_users cu JOIN companies c ON c.id=cu.company_id ORDER BY c.title, cu.full_name") as $r) $rows[]=['نمایندگان',$r['full_name'],$r['username'],$r['phone'],$r['company_title'],$r['is_active']?'فعال':'غیرفعال',$r['last_login_at'],'',''];
  foreach(Db::all("SELECT d.*, (SELECT COUNT(*) FROM schools s WHERE s.district_id=d.id) sc FROM districts d ORDER BY d.title") as $d) $rows[]=['نواحی',$d['title'],$d['sc'],$d['is_active']?'فعال':'','','','','',''];
  $customDefs=Db::all("SELECT field_key,label FROM school_field_defs WHERE is_active=1 ORDER BY sort_order");
  foreach(Db::all("SELECT s.*, d.title district_title, c.title company_title FROM schools s LEFT JOIN districts d ON d.id=s.district_id LEFT JOIN companies c ON c.id=s.company_id ORDER BY s.name") as $x){
    $row=['مدارس',$x['code'],$x['name'],$x['district_title'],$x['company_title'],$x['gender'],$x['shift'],$x['level'],$x['school_type'],$x['start_time'],$x['end_time'],$x['shift1_start_time'],$x['shift1_end_time'],$x['shift2_start_time'],$x['shift2_end_time'],$x['driver_count'],$x['student_count'],$x['address'],$x['phone'],$x['principal_name'],$x['lat'],$x['lng'],$x['location_status']==='done'?'ثبت‌شده':'باقی‌مانده',$x['location_recorded_at']];
    $cf=json_decode($x['custom_fields']??'{}',true)?:[]; foreach($customDefs as $cd) $row[]=$cf[$cd['field_key']]??''; $rows[]=$row;
  }
  $headers=['بخش','عنوان/کد','نام/نام کامل','ناحیه/تلفن','شرکت/آدرس','جنسیت/وضعیت','شیفت/تعداد مدارس','مقطع/تعداد ثبت‌شده','نوع مدرسه/تعداد نمایندگان','شروع فعالیت','پایان فعالیت','شروع شیفت صبح','پایان شیفت صبح','شروع شیفت عصر','پایان شیفت عصر','تعداد رانندگان','تعداد دانش‌آموز','آدرس','تلفن','مدیر مدرسه','عرض جغرافیایی','طول جغرافیایی','وضعیت','تاریخ ثبت']; foreach($customDefs as $cd)$headers[]=$cd['label'];
  _xlsx_download('full-report.xlsx',$headers,$rows,'گزارش جامع','گزارش جامع سامانه حمل‌ونقل دانش‌آموزی','سازمان مدیریت و نظارت بر تاکسیرانی شهرداری مشهد مقدس');
}, false, 'admin');

route('GET','/api/public/schools/{id}/company', function($p,$b){
  $row=Db::one("SELECT s.id,s.name,s.code,s.address school_address,c.id company_id,c.title company_title,c.manager_name,c.phone,c.address company_address FROM schools s LEFT JOIN companies c ON c.id=s.company_id WHERE s.id=?",[(int)$p['id']]);
  if(!$row) Http::error('مدرسه یافت نشد.',404);
  return $row;
}, true);

/* ==================== سامانه ثبت و رسیدگی به شکایات ==================== */
function _issue_complainant_token($row) {
  return _issue_session_token(['scope'=>'complainant','id'=>(int)$row['id'],'mobile'=>$row['mobile'],'full_name'=>$row['full_name'] ?? ''], _cfg()['access_ttl']);
}
function _ensure_complaints_tables(){
  static $done=false; if($done) return; $done=true;
  Db::run("CREATE TABLE IF NOT EXISTS complainants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mobile VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(160) NULL,
    national_code VARCHAR(20) NULL,
    child_full_name VARCHAR(160) NULL,
    child_school_id INT NULL,
    is_profile_complete TINYINT(1) NOT NULL DEFAULT 0,
    password_hash VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_complainant_school (child_school_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  try { if (!Db::one("SHOW COLUMNS FROM complainants WHERE Field='password_hash'")) Db::run("ALTER TABLE complainants ADD COLUMN password_hash VARCHAR(255) NULL"); } catch (\Throwable $e) {}
  Db::run("CREATE TABLE IF NOT EXISTS company_password_otps (id INT AUTO_INCREMENT PRIMARY KEY, company_user_id INT NOT NULL, mobile VARCHAR(20) NOT NULL, code VARCHAR(10) NOT NULL, expires_at DATETIME NOT NULL, used TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_company_pw_otp (mobile,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  Db::run("CREATE TABLE IF NOT EXISTS complainant_password_otps (id INT AUTO_INCREMENT PRIMARY KEY, complainant_id INT NOT NULL, mobile VARCHAR(20) NOT NULL, code VARCHAR(10) NOT NULL, expires_at DATETIME NOT NULL, used TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_complainant_pw_otp (mobile,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  Db::run("CREATE TABLE IF NOT EXISTS complaint_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mobile VARCHAR(20) NOT NULL,
    code VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    ip VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otp_mobile (mobile,created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  Db::run("CREATE TABLE IF NOT EXISTS complaints (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  Db::run("CREATE TABLE IF NOT EXISTS complaint_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    author_type VARCHAR(20) NOT NULL,
    author_id INT NULL,
    note TEXT NOT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cn_complaint (complaint_id,created_at),
    CONSTRAINT fk_cn_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  Db::run("CREATE TABLE IF NOT EXISTS complaint_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NULL,
    mime_type VARCHAR(100) NULL,
    file_size INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ca_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  // ارتقای گردش‌کار حرفه‌ای شکایات برای نصب‌های قدیمی
  $complaintCols = [
    'category' => "VARCHAR(80) NULL",
    'severity' => "VARCHAR(20) NOT NULL DEFAULT 'normal'",
    'workflow_stage' => "VARCHAR(40) NOT NULL DEFAULT 'agent_review'",
    'assigned_agent_id' => "INT NULL",
    'assigned_manager_id' => "INT NULL",
    'assigned_company_user_id' => "INT NULL",
    'manager_reviewed_at' => "DATETIME NULL",
    'final_response' => "TEXT NULL",
    'response_sent_at' => "DATETIME NULL",
    'source_channel' => "VARCHAR(20) NOT NULL DEFAULT 'web'",
    'sla_due_at' => "DATETIME NULL",
    'manager_due_at' => "DATETIME NULL"
  ];
  foreach($complaintCols as $column=>$ddl){ try{ if(!Db::one("SHOW COLUMNS FROM complaints WHERE Field=?",[$column])) Db::run("ALTER TABLE complaints ADD COLUMN `$column` $ddl"); }catch(\Throwable $e){} }
  $noteCols=['visibility'=>"VARCHAR(20) NOT NULL DEFAULT 'internal'",'target_type'=>"VARCHAR(20) NULL"];
  foreach($noteCols as $column=>$ddl){ try{ if(!Db::one("SHOW COLUMNS FROM complaint_notes WHERE Field=?",[$column])) Db::run("ALTER TABLE complaint_notes ADD COLUMN `$column` $ddl"); }catch(\Throwable $e){} }
  try{ if(!Db::one("SHOW COLUMNS FROM complaint_attachments WHERE Field='note_id'")) Db::run("ALTER TABLE complaint_attachments ADD COLUMN note_id INT NULL"); }catch(\Throwable $e){}
  Db::run("CREATE TABLE IF NOT EXISTS complaint_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, complaint_id INT NOT NULL, event_type VARCHAR(40) NOT NULL,
    from_stage VARCHAR(40) NULL, to_stage VARCHAR(40) NULL, actor_type VARCHAR(20) NOT NULL,
    actor_id INT NULL, title VARCHAR(180) NOT NULL, details TEXT NULL, is_public TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_ce_complaint(complaint_id,created_at),
    CONSTRAINT fk_ce_complaint FOREIGN KEY(complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  Db::run("CREATE TABLE IF NOT EXISTS complainant_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    complainant_id INT NOT NULL, complaint_id INT NULL,
    title VARCHAR(180) NOT NULL, body TEXT NOT NULL, message_type VARCHAR(30) NOT NULL DEFAULT 'system',
    is_read TINYINT(1) NOT NULL DEFAULT 0, read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cm_user_read(complainant_id,is_read,created_at),
    INDEX idx_cm_complaint(complaint_id),
    CONSTRAINT fk_cm_user FOREIGN KEY(complainant_id) REFERENCES complainants(id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_complaint FOREIGN KEY(complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  Db::run("CREATE TABLE IF NOT EXISTS complaint_report_settings (
    id TINYINT PRIMARY KEY DEFAULT 1, header_title VARCHAR(200) NULL, header_subtitle VARCHAR(250) NULL,
    footer_text VARCHAR(300) NULL, logo_path VARCHAR(255) NULL, font_family VARCHAR(80) NOT NULL DEFAULT 'Vazirmatn',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  Db::run("INSERT IGNORE INTO complaint_report_settings(id,header_title,footer_text) VALUES(1,'گزارش پرونده شکایت','سامانه حمل و نقل دانش‌آموزی')");
  Db::run("CREATE TABLE IF NOT EXISTS bale_complaint_sessions (
    chat_id VARCHAR(80) PRIMARY KEY, mobile VARCHAR(20) NULL, complainant_id INT NULL, step VARCHAR(40) NULL,
    payload_json LONGTEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  Db::run("CREATE TABLE IF NOT EXISTS bale_subscribers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(80) NOT NULL UNIQUE,
    mobile VARCHAR(20) NULL,
    first_name VARCHAR(120) NULL,
    last_name VARCHAR(120) NULL,
    username VARCHAR(120) NULL,
    complainant_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bale_mobile(mobile),
    INDEX idx_bale_active(is_active,last_seen_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  Db::run("CREATE TABLE IF NOT EXISTS bale_message_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NULL,
    target_type VARCHAR(20) NOT NULL,
    target_chat_id VARCHAR(80) NULL,
    message_text TEXT NOT NULL,
    sent_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bale_log_created(created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  Db::run("CREATE TABLE IF NOT EXISTS company_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL, company_user_id INT NULL, complaint_id INT NULL,
    title VARCHAR(180) NOT NULL, body TEXT NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_notify(company_id,company_user_id,is_read,created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

}
function _normalize_fa_digits($v){
  return strtr((string)$v,[
    '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'
  ]);
}
function _normalize_mobile($m){ $m=preg_replace('/\D+/', '', _normalize_fa_digits($m)); if(strlen($m)==10 && $m[0]=='9') $m='0'.$m; if(strlen($m)==12 && substr($m,0,2)=='98') $m='0'.substr($m,2); return $m; }
function _normalize_otp_code($code){ return preg_replace('/\D+/', '', _normalize_fa_digits($code)); }
function _complaint_code(){ return 'SHK-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)),0,8)); }
function _complaint_status_label($s){ return (_complaint_sla_config()['stage_labels'] ?? [])[$s] ?? ['new'=>'در انتظار بررسی کارشناس','agent_review'=>'بررسی کارشناس','referred_company'=>'ارجاع به شرکت','company_answered'=>'پاسخ شرکت ثبت شد','manager_review'=>'بررسی مدیر رسیدگی','answered'=>'پاسخ نهایی ارسال شد','closed'=>'مختومه'][$s] ?? $s; }
/* ---- مراحل و زمان مجاز رسیدگی به شکایات (قابل تنظیم از پنل مدیریت) ----
   عنوان هر مرحله و مهلت مجاز (به ساعت) هر مرحله در تنظیمات سامانه ذخیره می‌شود؛ ترتیب و منطق گردش‌کار
   (کدام مرحله به کدام مرحله می‌رود) در کد ثابت است، اما نام نمایشی و مهلت هر مرحله کاملاً قابل تغییر است. */
function _complaint_sla_default(){
  return [
    'stage_labels' => [
      'new'=>'در انتظار بررسی کارشناس','agent_review'=>'بررسی کارشناس','referred_company'=>'ارجاع به شرکت',
      'company_answered'=>'پاسخ شرکت ثبت شد','manager_review'=>'بررسی مدیر رسیدگی','answered'=>'پاسخ نهایی ارسال شد','closed'=>'مختومه',
    ],
    'stage_hours' => ['agent_review'=>24, 'company_review'=>48, 'manager_review'=>24],
  ];
}
function _complaint_sla_config(){
  $raw = _setting_get('complaint_sla_config', '');
  $d = $raw ? json_decode($raw, true) : null;
  $def = _complaint_sla_default();
  if (!is_array($d)) return $def;
  return [
    'stage_labels' => array_merge($def['stage_labels'], is_array($d['stage_labels'] ?? null) ? $d['stage_labels'] : []),
    'stage_hours' => array_merge($def['stage_hours'], is_array($d['stage_hours'] ?? null) ? $d['stage_hours'] : []),
  ];
}
function _complaint_sla_set($data){
  $def = _complaint_sla_default();
  $labels = [];
  foreach ($def['stage_labels'] as $key => $fallback) {
    $v = trim((string)($data['stage_labels'][$key] ?? ''));
    $labels[$key] = $v !== '' ? $v : $fallback;
  }
  $hours = [];
  foreach ($def['stage_hours'] as $key => $fallback) {
    $v = (int)($data['stage_hours'][$key] ?? 0);
    $hours[$key] = $v > 0 ? min($v, 24*90) : $fallback;
  }
  $out = ['stage_labels' => $labels, 'stage_hours' => $hours];
  _setting_set('complaint_sla_config', json_encode($out, JSON_UNESCAPED_UNICODE));
  return $out;
}
// مهلت یک مرحله را به‌صورت رشتهٔ SQL معتبر (تعداد ساعت) برمی‌گرداند تا در DATE_ADD استفاده شود
function _complaint_sla_hours($stageKey){
  $cfg = _complaint_sla_config();
  return max(1, (int)($cfg['stage_hours'][$stageKey] ?? _complaint_sla_default()['stage_hours'][$stageKey] ?? 24));
}
function _save_complaint_attachment($file){
  if(!$file || ($file['error']??1)!==0 || empty($file['tmp_name'])) return null;
  $max=8*1024*1024; if(($file['size']??0)>$max) Http::error('حجم هر فایل پیوست حداکثر ۸ مگابایت است.',400);
  if(!is_uploaded_file($file['tmp_name'])) Http::error('فایل پیوست نامعتبر است.',400);
  $mime = function_exists('finfo_open') ? (function() use($file){ $fi=finfo_open(FILEINFO_MIME_TYPE); $m=finfo_file($fi,$file['tmp_name']); finfo_close($fi); return $m; })() : ($file['type']??'');
  $allowed=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
  if(!isset($allowed[$mime])) Http::error('فقط فایل PDF یا تصویر JPG/PNG/WEBP قابل پیوست است.',400);
  $sub='complaints/'.date('Y').'/'.date('m'); $dir=Media::baseDir().'/'.$sub; if(!is_dir($dir)) @mkdir($dir,0755,true);
  $name=bin2hex(random_bytes(12)).'.'.$allowed[$mime]; $full=$dir.'/'.$name;
  if(!@move_uploaded_file($file['tmp_name'],$full)) Http::error('ذخیره فایل پیوست ناموفق بود.',500);
  @chmod($full,0644);
  return ['path'=>'uploads/'.$sub.'/'.$name,'mime'=>$mime,'size'=>(int)($file['size']??0),'name'=>$file['name']??$name];
}
function _complaint_row_sql(){ return "SELECT co.*, cp.mobile, cp.full_name complainant_name, cp.national_code, cp.child_full_name,
  s.name school_name, s.code school_code, s.address school_address, s.phone school_phone, c.title company_title, c.manager_name company_manager_name, c.phone company_phone, c.address company_address
  FROM complaints co JOIN complainants cp ON cp.id=co.complainant_id JOIN schools s ON s.id=co.school_id
  LEFT JOIN companies c ON c.id=co.company_id"; }
function _send_sms_safe($mobile,$body,$kind,$sentBy=null){ try{ if(Sms::isEnabled()) return Sms::send($mobile,$body,$kind,$sentBy); }catch(\Throwable $e){} return ['ok'=>false]; }

function _complaint_actor_label($type){ return ['complainant'=>'شاکی','admin'=>'کارشناس سامانه','complaint_agent'=>'کاربر رسیدگی به شکایات','complaint_manager'=>'مدیر رسیدگی به شکایات','company'=>'نماینده شرکت','system'=>'سامانه'][$type] ?? $type; }
function _complaint_event($id,$type,$from,$to,$actorType,$actorId,$title,$details='',$public=true){
  Db::run("INSERT INTO complaint_events(complaint_id,event_type,from_stage,to_stage,actor_type,actor_id,title,details,is_public) VALUES(?,?,?,?,?,?,?,?,?)",[$id,$type,$from,$to,$actorType,$actorId,$title,$details,$public?1:0]);
}
function _complainant_message($complainantId,$title,$body,$complaintId=null,$type='system'){
  if(!$complainantId) return;
  Db::run("INSERT INTO complainant_messages(complainant_id,complaint_id,title,body,message_type) VALUES(?,?,?,?,?)",[(int)$complainantId,$complaintId? (int)$complaintId:null,$title,$body,$type]);
}
function _complaint_sms_transition($row,$title){
  $body="وضعیت شکایت شما تغییر کرد: $title\nکد پیگیری: {$row['tracking_code']}";
  if(!empty($row['complainant_id'])) _complainant_message((int)$row['complainant_id'],$title,$body,(int)$row['id'],'complaint_status');
  if(!empty($row['mobile'])) _deliver_message($row['mobile'],$body."\nبرای مشاهده روند به سامانه مراجعه کنید.",'notification');
}
function _complaint_role_guard($u,$manager=false){ $roles=$manager?['super_admin','complaint_manager']:['super_admin','complaint_manager','complaint_agent']; _require_role($u,$roles); }
function _notify_company_complaint($row,$title,$body,$companyUserId=null){
  $companyId=(int)($row['company_id']??0); $complaintId=(int)($row['id']??0);
  if(!$companyId) return ['ok'=>false,'notified'=>0];
  $notified=0; $errors=[];
  try{
    if($companyUserId){
      Db::run("INSERT INTO company_notifications(company_id,company_user_id,complaint_id,title,body) VALUES(?,?,?,?,?)",[$companyId,(int)$companyUserId,$complaintId,$title,$body]);
      $users=Db::all("SELECT id,phone FROM company_users WHERE id=? AND company_id=? AND is_active=1",[(int)$companyUserId,$companyId]);
    }else{
      Db::run("INSERT INTO company_notifications(company_id,company_user_id,complaint_id,title,body) VALUES(?,NULL,?,?,?)",[$companyId,$complaintId,$title,$body]);
      $users=Db::all("SELECT id,phone FROM company_users WHERE company_id=? AND is_active=1",[$companyId]);
    }
    $notified++;
  }catch(\Throwable $e){ $errors[]='db:'.$e->getMessage(); }
  foreach($users??[] as $cu){
    $mobile=_normalize_mobile($cu['phone']??''); if(!$mobile) continue;
    try{
      $sub=Db::one("SELECT chat_id FROM bale_subscribers WHERE mobile=? AND is_active=1 ORDER BY id DESC LIMIT 1",[$mobile]);
      if($sub && _bale_send($sub['chat_id'],$body)) $notified++;
    }catch(\Throwable $e){ $errors[]='bale:'.$e->getMessage(); }
    try{ _send_sms_safe($mobile,$body,'complaint_referred'); }catch(\Throwable $e){ $errors[]='sms:'.$e->getMessage(); }
  }
  if($errors) error_log('Company complaint notification warnings: '.implode(' | ',$errors));
  return ['ok'=>true,'notified'=>$notified];
}
function _complaint_full($id,$publicOnly=false){
  $row=Db::one(_complaint_row_sql()." WHERE co.id=?",[$id]); if(!$row) return null;
  $where=$publicOnly?' AND is_public=1':'';
  $row['notes']=Db::all("SELECT n.*,a.full_name admin_name FROM complaint_notes n LEFT JOIN admin_users a ON a.id=n.author_id WHERE n.complaint_id=?$where ORDER BY n.id",[$id]);
  $row['attachments']=Db::all("SELECT id,note_id,file_path,original_name,mime_type,file_size,created_at FROM complaint_attachments WHERE complaint_id=? ORDER BY id",[$id]);
  $row['events']=Db::all("SELECT * FROM complaint_events WHERE complaint_id=?".($publicOnly?' AND is_public=1':'')." ORDER BY id",[$id]);
  return $row;
}
function _save_complaint_uploaded_files($complaintId,$noteId=null){
  foreach($_FILES as $file){ if(!is_array($file) || ($file['error']??1)!==0) continue; $att=_save_complaint_attachment($file); if($att) Db::run("INSERT INTO complaint_attachments(complaint_id,note_id,file_path,original_name,mime_type,file_size) VALUES(?,?,?,?,?,?)",[$complaintId,$noteId,$att['path'],$att['name'],$att['mime'],$att['size']]); }
}
function _bale_send($chatId,$text,$replyMarkup=null){
  if (_setting_get('bale_bot_enabled','0') !== '1') return false;
  $token=_setting_get('bale_bot_token',''); if(!$token||!$chatId) return false;
  $url='https://tapi.bale.ai/bot'.$token.'/sendMessage';
  $data=['chat_id'=>$chatId,'text'=>$text];
  if($replyMarkup!==null) $data['reply_markup']=$replyMarkup;
  $payload=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(function_exists('curl_init')){
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json; charset=utf-8'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
    $r=curl_exec($ch); $ok=$r!==false && (int)curl_getinfo($ch,CURLINFO_HTTP_CODE)<400; curl_close($ch); return $ok;
  }
  return false;
}
function _bale_contact_keyboard(){
  return ['keyboard'=>[[['text'=>'اشتراک‌گذاری شماره همراه','request_contact'=>true]]],'resize_keyboard'=>true,'one_time_keyboard'=>true];
}
function _bale_location_keyboard(){
  return ['keyboard'=>[[['text'=>'اشتراک‌گذاری موقعیت مکانی منزل','request_location'=>true]]],'resize_keyboard'=>true,'one_time_keyboard'=>true];
}
function _bale_remove_keyboard(){ return ['remove_keyboard'=>true]; }


route('POST','/api/complaints/otp/request', function($p,$b){
  _ensure_complaints_tables(); $mobile=_normalize_mobile($b['mobile']??'');
  if(!preg_match('/^09\d{9}$/',$mobile)) Http::error('شماره موبایل معتبر وارد کنید.',400);
  _rate_limit('complaint_otp_v2_'._client_ip().'_'.$mobile,8,900);
  $code=(string)random_int(10000,99999);
  Db::run("INSERT INTO complaint_otps(mobile,code,expires_at,ip) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE),?)",[$mobile,$code,_client_ip()]);
  $delivery=_deliver_message($mobile,"کد ورود ثبت شکایت سرویس حمل و نقل دانش آموزی: $code\nاعتبار: ۱۰ دقیقه",'otp');
  if(empty($delivery['ok'])){
    Db::run("UPDATE complaint_otps SET used=1 WHERE mobile=? AND code=?",[$mobile,$code]);
    $err=implode(' | ',array_filter($delivery['errors']??[]));
    error_log('Complaint OTP delivery failed for '._mask_phone($mobile).': '.$err);
    $msg='ارسال کد انجام نشد.';
    if($delivery['channel']==='bale') $msg.=' ابتدا ربات @stsmbot را باز کنید، «شروع» را بزنید و شماره همراه خود را به اشتراک بگذارید.';
    elseif($delivery['channel']==='both') $msg.=' نه پیامک و نه بله در دسترس نبودند؛ تنظیمات هر دو کانال را بررسی کنید.';
    else $msg.=' تنظیمات سرویس پیامک را بررسی کنید.';
    Http::error($msg,502);
  }
  return ['ok'=>true,'phone_hint'=>_mask_phone($mobile),'sms_sent'=>(bool)$delivery['sms'],'bale_sent'=>(bool)$delivery['bale'],'channel'=>$delivery['channel'],'expires_in'=>600,'resend_after'=>60];
}, true);

route('POST','/api/complaints/otp/verify', function($p,$b){
  _ensure_complaints_tables(); $mobile=_normalize_mobile($b['mobile']??''); $code=_normalize_otp_code($b['code']??'');
  if(!$mobile || !$code) Http::error('شماره موبایل و کد الزامی است.',400);
  $otp=Db::one("SELECT * FROM complaint_otps WHERE mobile=? AND code=? AND used=0 AND expires_at>=NOW() ORDER BY id DESC LIMIT 1",[$mobile,$code]);
  if(!$otp) Http::error('کد واردشده نامعتبر یا منقضی شده است.',400);
  Db::run("UPDATE complaint_otps SET used=1 WHERE id=?",[$otp['id']]);
  _rate_limit_clear('complaint_otp_v2_'._client_ip().'_'.$mobile);
  $row=Db::one("SELECT * FROM complainants WHERE mobile=?",[$mobile]);
  if(!$row){ $id=Db::insert("INSERT INTO complainants(mobile) VALUES(?)",[$mobile]); $row=Db::one("SELECT * FROM complainants WHERE id=?",[$id]); }
  if(!empty($row['is_profile_complete']) && !Db::one("SELECT id FROM complainant_messages WHERE complainant_id=? LIMIT 1",[$row['id']])) _complainant_message((int)$row['id'],'خوش آمدید','به پنل کاربری سامانه شکایات خوش آمدید. از این بخش می‌توانید شکایت جدید ثبت کنید، روند پرونده‌های قبلی را ببینید و پیام‌های سامانه را دریافت کنید.',null,'welcome');
  return ['token'=>_issue_complainant_token($row),'user'=>$row];
}, true);

route('POST','/api/complaints/password/forgot/request', function($p,$b){
  _ensure_complaints_tables(); $mobile=_normalize_mobile($b['mobile']??'');
  if(!preg_match('/^09\d{9}$/',$mobile)) Http::error('شماره همراه معتبر وارد کنید.',400);
  _rate_limit('complainant_forgot_'._client_ip().'_'.$mobile,6,900);
  $row=Db::one("SELECT id,mobile FROM complainants WHERE mobile=? AND password_hash IS NOT NULL",[$mobile]);
  if(!$row) Http::error('کاربری با رمز ثابت برای این شماره یافت نشد.',404);
  $code=(string)random_int(10000,99999);
  Db::run("INSERT INTO complainant_password_otps(complainant_id,mobile,code,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))",[$row['id'],$mobile,$code]);
  $delivery=_deliver_message($mobile,"کد بازیابی رمز ثابت سامانه شکایات: $code\nاعتبار: ۱۰ دقیقه",'otp');
  if(empty($delivery['ok'])) Http::error('ارسال کد بازیابی انجام نشد.',502);
  return ['ok'=>true,'expires_in'=>600,'resend_after'=>60];
}, true);
route('POST','/api/complaints/password/forgot/reset', function($p,$b){
  _ensure_complaints_tables(); $mobile=_normalize_mobile($b['mobile']??''); $code=_normalize_otp_code($b['code']??''); $new=(string)($b['new_password']??'');
  _require_strong_password($new);
  $otp=Db::one("SELECT * FROM complainant_password_otps WHERE mobile=? AND code=? AND used=0 AND expires_at>=NOW() ORDER BY id DESC LIMIT 1",[$mobile,$code]);
  if(!$otp) Http::error('کد بازیابی نامعتبر یا منقضی شده است.',400);
  Db::run("UPDATE complainant_password_otps SET used=1 WHERE id=?",[$otp['id']]);
  Db::run("UPDATE complainants SET password_hash=? WHERE id=?",[password_hash($new,PASSWORD_BCRYPT),$otp['complainant_id']]);
  _rate_limit_clear('complainant_forgot_'._client_ip().'_'.$mobile);
  $row=Db::one("SELECT * FROM complainants WHERE id=?",[$otp['complainant_id']]);
  return ['ok'=>true,'token'=>_issue_complainant_token($row),'user'=>$row];
}, true);

route('POST','/api/complaints/password/login', function($p,$b){
  _ensure_complaints_tables(); $mobile=_normalize_mobile($b['mobile']??''); $pw=(string)($b['password']??'');
  if(!$mobile||!$pw) Http::error('شماره همراه و رمز عبور الزامی است.',400);
  _rate_limit('complainant_pw_login_'._client_ip().'_'.$mobile,8,900);
  $row=Db::one("SELECT * FROM complainants WHERE mobile=?",[$mobile]);
  if(!$row || empty($row['password_hash']) || !password_verify($pw,$row['password_hash'])) Http::error('شماره همراه یا رمز عبور نادرست است.',401);
  return ['token'=>_issue_complainant_token($row),'user'=>$row];
}, true);

route('PUT','/api/complaints/password', function($p,$b,$u){
  _ensure_complaints_tables(); $current=(string)($b['current_password']??''); $new=(string)($b['new_password']??'');
  _require_strong_password($new);
  $row=Db::one("SELECT password_hash FROM complainants WHERE id=?",[$u['id']]);
  if(!empty($row['password_hash']) && !password_verify($current,$row['password_hash'])) Http::error('رمز عبور فعلی نادرست است.',401);
  Db::run("UPDATE complainants SET password_hash=? WHERE id=?",[password_hash($new,PASSWORD_BCRYPT),$u['id']]);
  return ['ok'=>true];
}, false, 'complainant');

route('GET','/api/complaints/me', function($p,$b,$u){ _ensure_complaints_tables(); return Db::one("SELECT c.*, s.district_id child_district_id,s.name child_school_name,s.address child_school_address,s.phone child_school_phone,co.id company_id,co.title company_title,co.manager_name company_manager_name,co.phone company_phone,co.address company_address FROM complainants c LEFT JOIN schools s ON s.id=c.child_school_id LEFT JOIN companies co ON co.id=s.company_id WHERE c.id=?",[$u['id']]); }, false, 'complainant');
route('PUT','/api/complaints/me', function($p,$b,$u){
  _ensure_complaints_tables(); $fn=trim($b['full_name']??''); $nc=preg_replace('/\D/','', $b['national_code']??''); $child=trim($b['child_full_name']??''); $school=(int)($b['child_school_id']??0);
  if(!$fn || !$nc || !$child || !$school) Http::error('نام ولی، کد ملی، مشخصات فرزند و مدرسه الزامی است.',400);
  if(!Db::one("SELECT id FROM schools WHERE id=?",[$school])) Http::error('مدرسه انتخاب‌شده معتبر نیست.',400);
  Db::run("UPDATE complainants SET full_name=?,national_code=?,child_full_name=?,child_school_id=?,is_profile_complete=1 WHERE id=?",[$fn,$nc,$child,$school,$u['id']]);
  if(!Db::one("SELECT id FROM complainant_messages WHERE complainant_id=? LIMIT 1",[$u['id']])) _complainant_message((int)$u['id'],'خوش آمدید','حساب کاربری شما با موفقیت تکمیل شد. از این پنل می‌توانید شکایت ثبت کنید، روند رسیدگی را ببینید و پیام‌های سامانه را دریافت کنید.',null,'welcome');
  return ['ok'=>true];
}, false, 'complainant');

route('POST','/api/complaints', function($p,$b,$u){
  _ensure_complaints_tables();
  $me=Db::one("SELECT * FROM complainants WHERE id=?",[$u['id']]); if(!$me || !$me['is_profile_complete']) Http::error('ابتدا مشخصات ولی و فرزند را تکمیل کنید.',400);
  $schoolId=(int)($_POST['school_id'] ?? $me['child_school_id']); $subject=trim($_POST['subject']??''); $body=trim($_POST['body']??'');
  if(!$schoolId || !$subject || !$body) Http::error('مدرسه، موضوع و متن شکایت الزامی است.',400);
  $school=Db::one("SELECT s.id,s.name,s.company_id,c.title company_title FROM schools s LEFT JOIN companies c ON c.id=s.company_id WHERE s.id=?",[$schoolId]);
  if(!$school) Http::error('مدرسه معتبر نیست.',400);
  $code=_complaint_code();
  $id=Db::insert("INSERT INTO complaints(tracking_code,complainant_id,school_id,company_id,subject,body,status,workflow_stage,sla_due_at) VALUES(?,?,?,?,?,?,'agent_review','agent_review',DATE_ADD(NOW(), INTERVAL ? HOUR))",[$code,$u['id'],$schoolId,$school['company_id'],$subject,$body,_complaint_sla_hours('agent_review')]);
  foreach(['attachment','file','document'] as $field){ if(!empty($_FILES[$field]) && ($_FILES[$field]['error']??1)===0){ $att=_save_complaint_attachment($_FILES[$field]); Db::run("INSERT INTO complaint_attachments(complaint_id,file_path,original_name,mime_type,file_size) VALUES(?,?,?,?,?)",[$id,$att['path'],$att['name'],$att['mime'],$att['size']]); }}
  Db::run("INSERT INTO complaint_notes(complaint_id,author_type,author_id,note,is_public,visibility,target_type) VALUES(?,?,?,?,1,'public','complainant')",[$id,'system',null,'شکایت ثبت شد و برای بررسی اولیه به کاربر رسیدگی به شکایات ارجاع گردید.']); _complaint_event($id,'created',null,'agent_review','complainant',$u['id'],'ثبت شکایت','پرونده تشکیل شد و کد پیگیری صادر گردید.',true);
  _complainant_message((int)$u['id'],'ثبت موفق شکایت',"شکایت شما ثبت شد و برای بررسی اولیه ارجاع گردید. کد پیگیری: $code",$id,'complaint_created');
  _deliver_message($me['mobile'],"شکایت شما در سامانه سرویس حمل و نقل دانش آموزی ثبت شد.\nکد پیگیری: $code",'notification');
  return ['ok'=>true,'id'=>$id,'tracking_code'=>$code,'company_title'=>$school['company_title']];
}, false, 'complainant');

route('GET','/api/complaints/my', function($p,$b,$u){ _ensure_complaints_tables(); return Db::all(_complaint_row_sql()." WHERE co.complainant_id=? ORDER BY co.id DESC",[$u['id']]); }, false, 'complainant');

route('GET','/api/complaints/inbox', function($p,$b,$u){
  _ensure_complaints_tables();
  return ['unread'=>(int)(Db::one("SELECT COUNT(*) c FROM complainant_messages WHERE complainant_id=? AND is_read=0",[$u['id']])['c']??0),
    'items'=>Db::all("SELECT m.*,c.tracking_code FROM complainant_messages m LEFT JOIN complaints c ON c.id=m.complaint_id WHERE m.complainant_id=? ORDER BY m.id DESC LIMIT 300",[$u['id']])];
}, false, 'complainant');
route('POST','/api/complaints/inbox/{id}/read', function($p,$b,$u){
  _ensure_complaints_tables(); Db::run("UPDATE complainant_messages SET is_read=1,read_at=NOW() WHERE id=? AND complainant_id=?",[(int)$p['id'],$u['id']]); return ['ok'=>true];
}, false, 'complainant');
route('POST','/api/complaints/inbox/read-all', function($p,$b,$u){
  _ensure_complaints_tables(); Db::run("UPDATE complainant_messages SET is_read=1,read_at=COALESCE(read_at,NOW()) WHERE complainant_id=? AND is_read=0",[$u['id']]); return ['ok'=>true];
}, false, 'complainant');
route('GET','/api/complaints/{id}', function($p,$b,$u){
  _ensure_complaints_tables(); $row=Db::one("SELECT id FROM complaints WHERE id=? AND complainant_id=?",[(int)$p['id'],$u['id']]); if(!$row) Http::error('شکایت یافت نشد.',404); return _complaint_full((int)$p['id'],true);
}, false, 'complainant');


// پیگیری عمومی با کد رهگیری و شماره موبایل
route('POST','/api/complaints/track', function($p,$b){
  _ensure_complaints_tables(); $code=strtoupper(trim($b['tracking_code']??'')); $mobile=_normalize_mobile($b['mobile']??'');
  if(!$code||!$mobile) Http::error('کد پیگیری و شماره موبایل الزامی است.',400);
  _rate_limit('complaint_track_'._client_ip(),20,900);
  $row=Db::one(_complaint_row_sql()." WHERE co.tracking_code=? AND cp.mobile=?",[$code,$mobile]); if(!$row) Http::error('پرونده‌ای با این مشخصات یافت نشد.',404);
  return _complaint_full((int)$row['id'],true);
}, true);

route('GET','/api/admin/complaints/report-summary', function($p,$b,$u){
  _ensure_complaints_tables(); _complaint_role_guard($u,false); $where=[];$args=[];
  if(!empty($_GET['from'])){$where[]='DATE(co.created_at)>=?';$args[]=$_GET['from'];} if(!empty($_GET['to'])){$where[]='DATE(co.created_at)<=?';$args[]=$_GET['to'];}
  if(!empty($_GET['company_id'])){$where[]='co.company_id=?';$args[]=(int)$_GET['company_id'];} if(!empty($_GET['status'])){$where[]='co.status=?';$args[]=$_GET['status'];}
  $w=$where?' WHERE '.implode(' AND ',$where):'';
  return ['totals'=>Db::one("SELECT COUNT(*) total,SUM(co.status IN ('answered','closed')) resolved,SUM(co.status='referred_company' AND co.company_due_at<NOW()) overdue FROM complaints co$w",$args),
    'by_status'=>Db::all("SELECT co.status,COUNT(*) count FROM complaints co$w GROUP BY co.status ORDER BY count DESC",$args),
    'by_company'=>Db::all("SELECT c.id,c.title,COUNT(co.id) count FROM complaints co LEFT JOIN companies c ON c.id=co.company_id$w GROUP BY c.id,c.title ORDER BY count DESC LIMIT 50",$args)];
}, false, 'admin');

route('GET','/api/admin/complaints/export', function($p,$b,$u){
  _ensure_complaints_tables(); _complaint_role_guard($u,false); $rows=Db::all(_complaint_row_sql()." ORDER BY co.id DESC LIMIT 5000");
  $headers=['کد پیگیری','تاریخ ثبت','شاکی','موبایل','مدرسه','شرکت','موضوع','وضعیت','مرحله','مهلت شرکت']; $out=[]; foreach($rows as $r) $out[]=[$r['tracking_code'],$r['created_at'],$r['complainant_name'],$r['mobile'],$r['school_name'],$r['company_title'],$r['subject'],_complaint_status_label($r['status']),$r['workflow_stage'],$r['company_due_at']]; _xlsx_download('complaints-report.xlsx',$headers,$out,'شکایات','گزارش شکایات سامانه','سامانه مدیریت شرکت‌های حمل‌ونقل دانش‌آموزی مشهد');
}, false, 'admin');

route('GET','/api/admin/complaints/report-settings', function($p,$b,$u){ _ensure_complaints_tables(); _complaint_role_guard($u,true); return Db::one("SELECT * FROM complaint_report_settings WHERE id=1"); }, false, 'admin');
route('PUT','/api/admin/complaints/report-settings', function($p,$b,$u){ _ensure_complaints_tables(); _complaint_role_guard($u,true); Db::run("UPDATE complaint_report_settings SET header_title=?,header_subtitle=?,footer_text=?,font_family=? WHERE id=1",[trim($b['header_title']??''),trim($b['header_subtitle']??''),trim($b['footer_text']??''),trim($b['font_family']??'Vazirmatn')]); return ['ok'=>true]; }, false, 'admin');

// مراحل و زمان مجاز رسیدگی به شکایات: عنوان هر مرحله و مهلت آن (به ساعت) از پنل مدیریت قابل تنظیم است
route('GET','/api/admin/complaints/sla-config', function($p,$b,$u){
  _require_role($u,['super_admin']);
  return _complaint_sla_config();
}, false, 'admin');
route('PUT','/api/admin/complaints/sla-config', function($p,$b,$u){
  _require_role($u,['super_admin']);
  return _complaint_sla_set($b);
}, false, 'admin');

route('GET','/api/admin/complaints/assignment-options', function($p,$b,$u){
  _ensure_complaints_tables(); _complaint_role_guard($u,false);
  return ['companies'=>Db::all("SELECT id,title,manager_name,phone,address FROM companies WHERE is_active=1 ORDER BY title"),
    'representatives'=>Db::all("SELECT cu.id,cu.company_id,cu.full_name,cu.phone,c.title company_title FROM company_users cu JOIN companies c ON c.id=cu.company_id WHERE cu.is_active=1 ORDER BY c.title,cu.full_name")];
}, false, 'admin');

route('POST','/api/admin/complaints/{id}/workflow', function($p,$b,$u){
  _ensure_complaints_tables(); _complaint_role_guard($u,false); $id=(int)$p['id']; $row=Db::one(_complaint_row_sql()." WHERE co.id=?",[$id]); if(!$row) Http::error('شکایت یافت نشد.',404);
  $action=trim((string)($b['action']??($_POST['action']??''))); $note=trim((string)($b['note']??($_POST['note']??''))); $from=$row['workflow_stage']??$row['status']; $role=$u['role']??'super_admin';
  if($action==='assign_agent'){ _complaint_role_guard($u,true); $agent=(int)($b['agent_id']??0); Db::run("UPDATE complaints SET assigned_agent_id=?,workflow_stage='agent_review',status='agent_review' WHERE id=?",[$agent,$id]); $to='agent_review'; $title='ارجاع به کاربر رسیدگی به شکایات'; }
  elseif($action==='refer_company'){
    $companyId=(int)($b['company_id']??($_POST['company_id']??($row['company_id']??0))); if(!$companyId) Http::error('شرکت مرتبط با مدرسه مشخص نیست.',400);
    $companyUserId=(int)($b['company_user_id']??($_POST['company_user_id']??0));
    if($companyUserId && !Db::one("SELECT id FROM company_users WHERE id=? AND company_id=? AND is_active=1",[$companyUserId,$companyId])) Http::error('نماینده انتخاب‌شده متعلق به شرکت نیست.',400);
    Db::run("UPDATE complaints SET company_id=?,assigned_company_user_id=?,status='referred_company',workflow_stage='company_review',assigned_agent_id=?,referred_at=NOW(),company_due_at=DATE_ADD(NOW(),INTERVAL ? HOUR) WHERE id=?",[$companyId,$companyUserId?:null,$u['id'],_complaint_sla_hours('company_review'),$id]);
    $row['company_id']=$companyId; $row['assigned_company_user_id']=$companyUserId?:null; $to='company_review'; $title='ارجاع به شرکت سرویس‌دهنده';
    try { _notify_company_complaint($row,$title,"شکایت جدید برای شرکت شما ارجاع شد.
کد: {$row['tracking_code']}
مدرسه: {$row['school_name']}
مهلت پاسخ: ۴۸ ساعت",$companyUserId?:null); }
    catch (\Throwable $e) { error_log('Complaint company notification failed: '.$e->getMessage()); }
  }
  elseif($action==='refer_manager'){ Db::run("UPDATE complaints SET status='manager_review',workflow_stage='manager_review',assigned_manager_id=?,manager_reviewed_at=NOW(),manager_due_at=DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id=?",[(int)($b['manager_id']??$u['id']),_complaint_sla_hours('manager_review'),$id]); $to='manager_review'; $title='ارجاع به مدیر رسیدگی به شکایات'; }
  elseif($action==='answer_complainant'){ $answer=$note?:trim($b['answer']??''); if(!$answer) Http::error('متن پاسخ الزامی است.',400); Db::run("UPDATE complaints SET status='answered',workflow_stage='answered',final_response=?,response_sent_at=NOW() WHERE id=?",[$answer,$id]); $to='answered'; $title='ثبت و ارسال پاسخ نهایی به شاکی'; }
  elseif($action==='close'){ Db::run("UPDATE complaints SET status='closed',workflow_stage='closed',closed_at=NOW() WHERE id=?",[$id]); $to='closed'; $title='مختومه شدن پرونده'; }
  else Http::error('عملیات گردش‌کار نامعتبر است.',400);
  $author=in_array($role,['complaint_agent','complaint_manager'],true)?$role:'admin';
  if($note){ $nid=Db::insert("INSERT INTO complaint_notes(complaint_id,author_type,author_id,note,is_public,visibility,target_type) VALUES(?,?,?,?,?,?,?)",[$id,$author,$u['id'],$note,!empty($b['is_public']??($_POST['is_public']??null))?1:0,!empty($b['is_public']??($_POST['is_public']??null))?'public':'internal',$b['target_type']??($_POST['target_type']??null)]); _save_complaint_uploaded_files($id,$nid); }
  _complaint_event($id,$action,$from,$to,$author,$u['id'],$title,$note,true); _complaint_sms_transition($row,$title); return ['ok'=>true,'stage'=>$to];
}, false, 'admin');

route('GET','/api/admin/complaints/{id}/print', function($p,$b,$u){
  _ensure_complaints_tables(); _complaint_role_guard($u,false); $r=_complaint_full((int)$p['id'],false); if(!$r) Http::error('پرونده یافت نشد.',404); $s=Db::one("SELECT * FROM complaint_report_settings WHERE id=1");
  header('Content-Type: text/html; charset=UTF-8'); echo '<!doctype html><html dir="rtl"><meta charset="utf-8"><title>گزارش شکایت</title><style>body{font-family:'.htmlspecialchars($s['font_family']?:'Vazirmatn').',Tahoma;padding:28px;line-height:2;color:#172033}.head{text-align:center;border-bottom:2px solid #b58b00;margin-bottom:20px}.box{border:1px solid #ddd;border-radius:10px;padding:12px;margin:10px 0}.event{border-right:4px solid #b58b00;padding:8px;margin:8px 0;background:#fafafa}@media print{button{display:none}}</style><body><div class="head"><h2>'.htmlspecialchars($s['header_title']).'</h2><p>'.htmlspecialchars($s['header_subtitle']).'</p></div><div class="box"><b>کد پیگیری:</b> '.htmlspecialchars($r['tracking_code']).'<br><b>موضوع:</b> '.htmlspecialchars($r['subject']).'<br><b>شاکی:</b> '.htmlspecialchars($r['complainant_name']).'<br><b>مدرسه/شرکت:</b> '.htmlspecialchars($r['school_name'].' / '.$r['company_title']).'</div><div class="box"><b>شرح:</b><br>'.nl2br(htmlspecialchars($r['body'])).'</div><h3>گردش پرونده</h3>'; foreach($r['events'] as $e) echo '<div class="event"><b>'.htmlspecialchars($e['title']).'</b><br><small>'.htmlspecialchars($e['created_at']).'</small><br>'.nl2br(htmlspecialchars($e['details']??'')).'</div>'; echo '<p>'.htmlspecialchars($s['footer_text']).'</p><button onclick="print()">چاپ</button></body></html>'; exit;
}, false, 'admin');

route('GET','/api/admin/bale-subscribers', function($p,$b,$u){
  _ensure_complaints_tables();
  $q=trim((string)($_GET['q']??'')); $args=[]; $where='';
  if($q!==''){ $where=' WHERE chat_id LIKE ? OR mobile LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR username LIKE ?'; $like='%'.$q.'%'; $args=[$like,$like,$like,$like,$like]; }
  return Db::all("SELECT id,chat_id,mobile,first_name,last_name,username,is_active,joined_at,last_seen_at FROM bale_subscribers".$where." ORDER BY last_seen_at DESC LIMIT 1000",$args);
}, false, 'admin');

route('POST','/api/admin/bale-messages/send', function($p,$b,$u){
  _ensure_complaints_tables();
  $text=trim((string)($b['message']??'')); if($text==='') Http::error('متن پیام الزامی است.',400);
  if(mb_strlen($text)>3500) Http::error('متن پیام بیش از حد طولانی است.',400);
  $target=(string)($b['target']??'all'); $chatId=trim((string)($b['chat_id']??''));
  $sent=0; $failed=0;
  if($target==='single'){
    if($chatId==='') Http::error('مشترک انتخاب نشده است.',400);
    if(_bale_send($chatId,$text)) $sent++; else $failed++;
  } else {
    $rows=Db::all("SELECT chat_id FROM bale_subscribers WHERE is_active=1 ORDER BY id");
    foreach($rows as $row){ if(_bale_send($row['chat_id'],$text)) $sent++; else $failed++; }
  }
  Db::run("INSERT INTO bale_message_logs(admin_id,target_type,target_chat_id,message_text,sent_count,failed_count) VALUES(?,?,?,?,?,?)",[(int)$u['id'],$target,$target==='single'?$chatId:null,$text,$sent,$failed]);
  return ['ok'=>true,'sent'=>$sent,'failed'=>$failed];
}, false, 'admin');

route('PUT','/api/admin/bale-subscribers/{id}', function($p,$b,$u){
  _ensure_complaints_tables(); $id=(int)$p['id'];
  Db::run("UPDATE bale_subscribers SET is_active=? WHERE id=?",[!empty($b['is_active'])?1:0,$id]);
  return ['ok'=>true];
}, false, 'admin');

// وب‌هوک مرحله‌ای ربات بله: ثبت‌نام، ثبت شکایت و پیگیری
route('POST','/api/bale/complaints-webhook/{secret}', function($p,$b){
  _ensure_complaints_tables(); if (_setting_get('bale_bot_enabled','0') !== '1') Http::error('ربات بله غیرفعال است.',403); $expected=_setting_get('bale_webhook_secret',''); if(!$expected||!hash_equals($expected,(string)$p['secret'])) Http::error('دسترسی نامعتبر',403);
  $msg=$b['message']??[]; $chat=(string)($msg['chat']['id']??''); $txt=trim($msg['text']??''); $contact=$msg['contact']??null; $location=$msg['location']??null; if(!$chat) return ['ok'=>true];
  $from=$msg['from']??[]; Db::run("INSERT INTO bale_subscribers(chat_id,first_name,last_name,username,last_seen_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE first_name=VALUES(first_name),last_name=VALUES(last_name),username=VALUES(username),last_seen_at=NOW(),is_active=1",[$chat,$from['first_name']??null,$from['last_name']??null,$from['username']??null]);
  $session=Db::one("SELECT * FROM bale_complaint_sessions WHERE chat_id=?",[$chat]); $payload=json_decode($session['payload_json']??'{}',true)?:[]; $step=$session['step']??'';
  $save=function($next,$data) use($chat){ Db::run("INSERT INTO bale_complaint_sessions(chat_id,step,payload_json) VALUES(?,?,?) ON DUPLICATE KEY UPDATE step=VALUES(step),payload_json=VALUES(payload_json)",[$chat,$next,json_encode($data,JSON_UNESCAPED_UNICODE)]); };
  if(preg_match('/^(?:شروع|\/start)$/iu',$txt)){
    $save('await_contact',[]);
    $welcome=_setting_get('bale_welcome_message','به سامانه شکایات حمل و نقل دانش‌آموزی خوش آمدید.');
    _bale_send($chat,$welcome."\n\nبرای ثبت‌نام و استفاده از خدمات، شماره همراه خود را با دکمه زیر به اشتراک بگذارید.",_bale_contact_keyboard());
    return ['ok'=>true];
  }
  if(preg_match('/(?:پیگیری|track)\s+([A-Z0-9-]+)/iu',$txt,$m)){ $row=Db::one(_complaint_row_sql()." WHERE co.tracking_code=?",[strtoupper($m[1])]); if(!$row){_bale_send($chat,'کد پیگیری یافت نشد.');return ['ok'=>true];} $events=Db::all("SELECT title,created_at FROM complaint_events WHERE complaint_id=? AND is_public=1 ORDER BY id DESC LIMIT 5",[$row['id']]); $timeline=implode("
",array_map(fn($e)=>$e['created_at'].' - '.$e['title'],$events)); _bale_send($chat,"وضعیت پرونده {$row['tracking_code']}: "._complaint_status_label($row['status'])."
مدرسه: {$row['school_name']}
$timeline"); return ['ok'=>true]; }
  // ---- محاسبهٔ هزینهٔ سرویس مدرسه از طریق ربات بله: مدرسه → کلاس خودرو → موقعیت مکانی منزل → محاسبه ----
  if(preg_match('/^محاسبه\s*هزینه(?:\s*سرویس)?$/iu',$txt)){
    if(!_feature_enabled('public_cost_calculator_map')){ _bale_send($chat,'این قابلیت توسط مدیر سامانه غیرفعال شده است.'); return ['ok'=>true]; }
    $save('await_cost_school',['intent'=>'cost']);
    _bale_send($chat,'برای محاسبهٔ هزینهٔ سرویس، نام یا کد یکتای مدرسه را ارسال کنید.',_bale_remove_keyboard());
    return ['ok'=>true];
  }
  if($step==='await_cost_school'){
    $school=Db::one("SELECT s.id,s.name,s.district_id,d.title district_title FROM schools s LEFT JOIN districts d ON d.id=s.district_id WHERE s.code=? OR s.name=? ORDER BY s.code=? DESC LIMIT 1",[$txt,$txt,$txt]);
    if(!$school){ $school=Db::one("SELECT s.id,s.name,s.district_id,d.title district_title FROM schools s LEFT JOIN districts d ON d.id=s.district_id WHERE s.name LIKE ? ORDER BY s.name LIMIT 1",['%'.$txt.'%']); }
    if(!$school){ _bale_send($chat,'مدرسه یافت نشد. نام یا کد دقیق‌تری ارسال کنید.'); return ['ok'=>true]; }
    $pricing=_service_pricing_get();
    $vehicles=($pricing['mode']??'map')==='excel' ? array_map(fn($s)=>['key'=>$s['key'],'title'=>$s['title']], _service_pricing_excel_get()['sheets']) : $pricing['vehicle_classes'];
    if(!$vehicles){ _bale_send($chat,'کلاس خودرویی برای محاسبه تعریف نشده است.'); $save('',[]); return ['ok'=>true]; }
    $payload['school_id']=$school['id']; $payload['school_name']=$school['name']; $payload['district_id']=$school['district_id']; $payload['vehicles']=$vehicles;
    $save('await_cost_vehicle',$payload);
    $list=implode("\n",array_map(fn($i,$v)=>($i+1).'- '.$v['title'], array_keys($vehicles), $vehicles));
    _bale_send($chat,"مدرسه انتخاب شد: {$school['name']}\nکلاس خودرو را با ارسال شمارهٔ آن انتخاب کنید:\n$list");
    return ['ok'=>true];
  }
  if($step==='await_cost_vehicle'){
    $vehicles=$payload['vehicles']??[]; $idx=(int)preg_replace('/\D/','',$txt)-1;
    $chosen=null;
    if(isset($vehicles[$idx])) $chosen=$vehicles[$idx];
    else foreach($vehicles as $v){ if(trim($v['title'])===$txt){ $chosen=$v; break; } }
    if(!$chosen){ _bale_send($chat,'شمارهٔ کلاس خودرو را از فهرست بالا ارسال کنید.'); return ['ok'=>true]; }
    $payload['vehicle_class']=$chosen['key']; $payload['vehicle_title']=$chosen['title']; unset($payload['vehicles']);
    $save('await_cost_location',$payload);
    _bale_send($chat,'موقعیت مکانی منزل را با دکمهٔ زیر ارسال کنید تا مسافت واقعی خیابانی و هزینهٔ سرویس محاسبه شود.',_bale_location_keyboard());
    return ['ok'=>true];
  }
  if($step==='await_cost_location'){
    if(!is_array($location) || !isset($location['latitude'],$location['longitude'])){
      _bale_send($chat,'لطفاً از دکمه «اشتراک‌گذاری موقعیت مکانی منزل» استفاده کنید.',_bale_location_keyboard());
      return ['ok'=>true];
    }
    $school=Db::one("SELECT id,name,lat,lng,district_id FROM schools WHERE id=? AND lat IS NOT NULL AND lng IS NOT NULL",[$payload['school_id']??0]);
    if(!$school){ _bale_send($chat,'موقعیت مدرسه ثبت نشده است؛ امکان محاسبه وجود ندارد.',_bale_remove_keyboard()); $save('',[]); return ['ok'=>true]; }
    try{
      $route=_extract_route_summary(_neshan_route((float)$location['latitude'],(float)$location['longitude'],(float)$school['lat'],(float)$school['lng']));
      $oneWayKm=$route['distance_meters']/1000;
      $pricingMode=(_service_pricing_get()['mode'] ?? 'map');
      $lookupKm = $pricingMode === 'excel' ? ($oneWayKm * 2) : $oneWayKm;
      $r=_service_cost_calculate($payload['vehicle_class'],$lookupKm,(int)$school['district_id'],$oneWayKm);
      $unit = ($r['pricing_mode']??'')==='excel' ? 'ریال' : 'تومان';
      $lines=["مدرسه: {$school['name']}","کلاس خودرو: {$r['vehicle_title']}","مسافت یک‌طرفه: ".number_format($r['distance_km'],2)." کیلومتر"];
      if(($r['pricing_mode']??'')==='excel'){ $lines[]="مسافت رفت‌وبرگشت (مبنای محاسبه): ".number_format($r['round_trip_km'],2)." کیلومتر"; if(($r['traffic_percent']??0)>0) $lines[]="ضریب ترافیک ناحیه: ".number_format($r['traffic_percent'],0)."%"; }
      else $lines[]="نرخ هر کیلومتر: ".number_format($r['rate_per_km'],0)." $unit";
      $lines[]="هزینهٔ ماهیانهٔ سرویس: ".number_format($r['cost'],0)." $unit";
      _bale_send($chat,implode("\n",$lines),_bale_remove_keyboard());
    } catch(\Throwable $e){ _bale_send($chat,'در محاسبهٔ مسیر خطایی رخ داد. لطفاً بعداً دوباره تلاش کنید.',_bale_remove_keyboard()); }
    $save('',[]);
    return ['ok'=>true];
  }
  if($txt==='ثبت شکایت'){
    $subscriber=Db::one("SELECT mobile,complainant_id FROM bale_subscribers WHERE chat_id=?",[$chat]);
    $mobile=_normalize_mobile((string)($subscriber['mobile']??''));
    if(!preg_match('/^09\d{9}$/',$mobile)){
      $save('await_contact',['intent'=>'complaint']);
      _bale_send($chat,'برای ثبت شکایت، ابتدا شماره همراه خود را با دکمه زیر به اشتراک بگذارید.',_bale_contact_keyboard());
      return ['ok'=>true];
    }
    $person=Db::one("SELECT * FROM complainants WHERE mobile=?",[$mobile]);
    if(!$person || empty($person['is_profile_complete'])){
      $save('await_full_name',['mobile'=>$mobile,'intent'=>'complaint']);
      _bale_send($chat,'برای ثبت شکایت، ابتدا نام و نام خانوادگی ولی را ارسال کنید.',_bale_remove_keyboard());
      return ['ok'=>true];
    }
    $save('await_school',['mobile'=>$mobile,'complainant_id'=>(int)$person['id'],'intent'=>'complaint']);
    _bale_send($chat,'کد یکتا یا نام مدرسه را ارسال کنید.',_bale_remove_keyboard());
    return ['ok'=>true];
  }
  if($step==='await_contact'){
    if(!is_array($contact) || empty($contact['phone_number'])){
      _bale_send($chat,'لطفاً از دکمه «اشتراک‌گذاری شماره همراه» استفاده کنید.',_bale_contact_keyboard());
      return ['ok'=>true];
    }
    $senderId=(string)($msg['from']['id']??''); $contactUserId=(string)($contact['user_id']??'');
    if($contactUserId!=='' && $senderId!=='' && $contactUserId!==$senderId){
      _bale_send($chat,'فقط شماره همراه متعلق به حساب خودتان قابل پذیرش است.',_bale_contact_keyboard());
      return ['ok'=>true];
    }
    $mobile=_normalize_mobile((string)$contact['phone_number']);
    if(!preg_match('/^09\d{9}$/',$mobile)){
      _bale_send($chat,'شماره همراه دریافت‌شده معتبر نیست. لطفاً شماره حساب بله خود را دوباره به اشتراک بگذارید.',_bale_contact_keyboard());
      return ['ok'=>true];
    }
    $person=Db::one("SELECT * FROM complainants WHERE mobile=?",[$mobile]);
    $payload=['mobile'=>$mobile];
    if($person){ $payload['complainant_id']=(int)$person['id']; Db::run("UPDATE bale_subscribers SET mobile=?,complainant_id=?,last_seen_at=NOW(),is_active=1 WHERE chat_id=?",[$mobile,$person['id'],$chat]); }
    else { Db::run("UPDATE bale_subscribers SET mobile=?,last_seen_at=NOW(),is_active=1 WHERE chat_id=?",[$mobile,$chat]); }
    $save('',[]);
    _bale_send($chat,'شماره همراه شما با موفقیت ثبت شد و عضویت ربات فعال گردید.\n\n'._setting_get('bale_menu_text',"ثبت شکایت\nپیگیری شکایت\nمحاسبه هزینه\nراهنما"),_bale_remove_keyboard());
    return ['ok'=>true];
  }
  if($step==='await_full_name'){ $payload['full_name']=$txt; $save('await_national',$payload); _bale_send($chat,'کد ملی ولی را ارسال کنید.'); return ['ok'=>true]; }
  if($step==='await_national'){ $nc=preg_replace('/\D/','',$txt); if(strlen($nc)!=10){_bale_send($chat,'کد ملی باید ۱۰ رقم باشد.');return ['ok'=>true];} $payload['national_code']=$nc; $save('await_child',$payload); _bale_send($chat,'نام و نام خانوادگی فرزند را ارسال کنید.'); return ['ok'=>true]; }
  if($step==='await_child'){ $payload['child_name']=$txt; $save('await_school',$payload); _bale_send($chat,'کد یکتا یا نام مدرسه را ارسال کنید.'); return ['ok'=>true]; }
  if($step==='await_school'){ $school=Db::one("SELECT id,name,company_id FROM schools WHERE code=? OR name=? ORDER BY code=? DESC LIMIT 1",[$txt,$txt,$txt]); if(!$school){$school=Db::one("SELECT id,name,company_id FROM schools WHERE name LIKE ? ORDER BY name LIMIT 1",['%'.$txt.'%']);} if(!$school){_bale_send($chat,'مدرسه یافت نشد. نام یا کد دقیق‌تری ارسال کنید.');return ['ok'=>true];} $payload['school_id']=$school['id'];$payload['school_name']=$school['name'];$payload['company_id']=$school['company_id'];$save('await_subject',$payload);_bale_send($chat,"مدرسه انتخاب شد: {$school['name']}
موضوع شکایت را ارسال کنید.");return ['ok'=>true]; }
  if($step==='await_subject'){ $payload['subject']=$txt;$save('await_body',$payload);_bale_send($chat,'شرح کامل شکایت را ارسال کنید.');return ['ok'=>true]; }
  if($step==='await_body'){
    $mobile=$payload['mobile']; $person=Db::one("SELECT * FROM complainants WHERE mobile=?",[$mobile]);
    if(!$person){
      $pid=Db::insert("INSERT INTO complainants(mobile,full_name,national_code,child_full_name,child_school_id,is_profile_complete) VALUES(?,?,?,?,?,1)",[$mobile,$payload['full_name']??'', $payload['national_code']??'', $payload['child_name']??'', $payload['school_id']]);
    }else{
      $pid=(int)$person['id'];
      if(empty($person['is_profile_complete'])){
        Db::run("UPDATE complainants SET full_name=?,national_code=?,child_full_name=?,child_school_id=?,is_profile_complete=1 WHERE id=?",[$payload['full_name']??'', $payload['national_code']??'', $payload['child_name']??'', $payload['school_id'],$pid]);
      }
    }
    Db::run("UPDATE bale_subscribers SET complainant_id=? WHERE chat_id=?",[$pid,$chat]);
    $code=_complaint_code(); $id=Db::insert("INSERT INTO complaints(tracking_code,complainant_id,school_id,company_id,subject,body,status,workflow_stage,source_channel,sla_due_at) VALUES(?,?,?,?,?,?,'agent_review','agent_review','bale',DATE_ADD(NOW(),INTERVAL ? HOUR))",[$code,$pid,$payload['school_id'],$payload['company_id'],$payload['subject'],$txt,_complaint_sla_hours('agent_review')]); _complaint_event($id,'created',null,'agent_review','complainant',$pid,'ثبت شکایت از طریق بله','پرونده برای بررسی اولیه تشکیل شد.',true); _deliver_message($mobile,"شکایت شما ثبت شد. کد پیگیری: $code",'notification'); $save('',[]); _bale_send($chat,"شکایت ثبت شد.
کد پیگیری: $code
برای مشاهده وضعیت بنویسید: پیگیری $code"); return ['ok'=>true];
  }
  _bale_send($chat,"دستور معتبر ارسال کنید:
ثبت شکایت
پیگیری SHK-...
محاسبه هزینه"); return ['ok'=>true];
}, true);

route('GET','/api/admin/complaints', function($p,$b,$u){
  _ensure_complaints_tables(); _complaint_role_guard($u,false); $conds=[]; $args=[];
  if(!empty($_GET['status'])){$conds[]='co.status=?';$args[]=trim($_GET['status']);}
  if(!empty($_GET['q'])){$q='%'.trim($_GET['q']).'%';$conds[]='(co.tracking_code LIKE ? OR co.subject LIKE ? OR cp.full_name LIKE ? OR cp.mobile LIKE ? OR s.name LIKE ? OR c.title LIKE ?)';array_push($args,$q,$q,$q,$q,$q,$q);} 
  $where=$conds?' WHERE '.implode(' AND ',$conds):'';
  return Db::all(_complaint_row_sql().$where." ORDER BY FIELD(co.status,'new','referred_company','company_answered','answered','closed'), co.id DESC LIMIT 300",$args);
}, false, 'admin');
route('GET','/api/admin/complaints/{id}', function($p,$b,$u){
  _ensure_complaints_tables(); $id=(int)$p['id']; $row=Db::one(_complaint_row_sql()." WHERE co.id=?",[$id]); if(!$row) Http::error('شکایت یافت نشد.',404);
  $row=_complaint_full($id,false); return $row;
}, false, 'admin');
route('POST','/api/admin/complaints/{id}/note', function($p,$b,$u){ _ensure_complaints_tables(); $note=trim($b['note']??''); if(!$note) Http::error('متن یادداشت الزامی است.',400); Db::run("INSERT INTO complaint_notes(complaint_id,author_type,author_id,note,is_public) VALUES(?,?,?,?,?)",[(int)$p['id'],'admin',$u['id'],$note,!empty($b['is_public'])?1:0]); Db::run("UPDATE complaints SET last_note_at=NOW() WHERE id=?",[(int)$p['id']]); return ['ok'=>true]; }, false, 'admin');
route('POST','/api/admin/complaints/{id}/refer-company', function($p,$b,$u){
  _ensure_complaints_tables(); $id=(int)$p['id']; $row=Db::one(_complaint_row_sql()." WHERE co.id=?",[$id]); if(!$row) Http::error('شکایت یافت نشد.',404); if(!$row['company_id']) Http::error('برای این مدرسه شرکت ثبت نشده است.',400);
  $note=trim($b['note']??''); Db::run("UPDATE complaints SET status='referred_company',assigned_admin_id=?,referred_at=NOW(),company_due_at=DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id=?",[$u['id'],_complaint_sla_hours('company_review'),$id]);
  Db::run("INSERT INTO complaint_notes(complaint_id,author_type,author_id,note,is_public) VALUES(?,?,?,?,0)",[$id,'admin',$u['id'],$note?:'شکایت برای پاسخگویی به نماینده شرکت ارجاع شد.']);
  $phones=Db::all("SELECT phone FROM company_users WHERE company_id=? AND is_active=1 AND phone IS NOT NULL AND phone<>''",[$row['company_id']]);
  foreach($phones as $ph) _send_sms_safe($ph['phone'],"یک شکایت جدید در سامانه برای شرکت شما ثبت و ارجاع شد.\nکد پیگیری: {$row['tracking_code']}\nمهلت پاسخگویی: ۴۸ ساعت",'complaint_referred',$u['id']);
  return ['ok'=>true];
}, false, 'admin');
route('POST','/api/admin/complaints/{id}/answer', function($p,$b,$u){
  _ensure_complaints_tables(); $id=(int)$p['id']; $answer=trim($b['answer']??''); if(!$answer) Http::error('متن پاسخ الزامی است.',400);
  $row=Db::one(_complaint_row_sql()." WHERE co.id=?",[$id]); if(!$row) Http::error('شکایت یافت نشد.',404);
  Db::run("INSERT INTO complaint_notes(complaint_id,author_type,author_id,note,is_public) VALUES(?,?,?,?,1)",[$id,'admin',$u['id'],$answer]);
  Db::run("UPDATE complaints SET status='answered',closed_at=IF(?=1,NOW(),closed_at),last_note_at=NOW() WHERE id=?",[!empty($b['close'])?1:0,$id]);
  _deliver_message($row['mobile'],"پاسخ شکایت شما ثبت شد.\nکد پیگیری: {$row['tracking_code']}\nبرای مشاهده نتیجه به سامانه مراجعه کنید.",'notification',$u['id']);
  return ['ok'=>true];
}, false, 'admin');

route('GET','/api/company/notifications', function($p,$b,$u){
  _ensure_complaints_tables();
  return ['unread'=>(int)(Db::one("SELECT COUNT(*) n FROM company_notifications WHERE company_id=? AND (company_user_id IS NULL OR company_user_id=?) AND is_read=0",[$u['company_id'],$u['id']])['n']??0),
    'items'=>Db::all("SELECT * FROM company_notifications WHERE company_id=? AND (company_user_id IS NULL OR company_user_id=?) ORDER BY id DESC LIMIT 100",[$u['company_id'],$u['id']])];
}, false, 'company');
route('POST','/api/company/notifications/{id}/read', function($p,$b,$u){
  _ensure_complaints_tables(); Db::run("UPDATE company_notifications SET is_read=1 WHERE id=? AND company_id=? AND (company_user_id IS NULL OR company_user_id=?)",[(int)$p['id'],$u['company_id'],$u['id']]); return ['ok'=>true];
}, false, 'company');

route('GET','/api/company/complaints', function($p,$b,$u){
  _require_feature('complaints'); _ensure_complaints_tables(); return Db::all(_complaint_row_sql()." WHERE co.company_id=? AND (co.assigned_company_user_id IS NULL OR co.assigned_company_user_id=?) AND co.status IN ('referred_company','company_answered') ORDER BY co.company_due_at IS NULL, co.company_due_at ASC, co.id DESC",[$u['company_id'],$u['id']]); }, false, 'company');
route('GET','/api/company/complaints/{id}', function($p,$b,$u){ _ensure_complaints_tables(); $id=(int)$p['id']; $row=Db::one(_complaint_row_sql()." WHERE co.id=? AND co.company_id=? AND (co.assigned_company_user_id IS NULL OR co.assigned_company_user_id=?)",[$id,$u['company_id'],$u['id']]); if(!$row) Http::error('شکایت یافت نشد.',404); $row['notes']=Db::all("SELECT * FROM complaint_notes WHERE complaint_id=? ORDER BY id",[$id]); return $row; }, false, 'company');
route('POST','/api/company/complaints/{id}/reply', function($p,$b,$u){
  _ensure_complaints_tables(); $id=(int)$p['id']; $reply=trim($b['reply']??''); if(!$reply) Http::error('متن پاسخ الزامی است.',400);
  $row=Db::one("SELECT * FROM complaints WHERE id=? AND company_id=? AND (assigned_company_user_id IS NULL OR assigned_company_user_id=?)",[$id,$u['company_id'],$u['id']]); if(!$row) Http::error('شکایت یافت نشد.',404); if($row['status']!=='referred_company' && $row['status']!=='company_answered') Http::error('این شکایت در وضعیت قابل پاسخ شرکت نیست.',400);
  $nid=Db::insert("INSERT INTO complaint_notes(complaint_id,author_type,author_id,note,is_public,visibility,target_type) VALUES(?,?,?,?,0,'internal','complaint_agent')",[$id,'company',$u['id'],$reply]); _save_complaint_uploaded_files($id,$nid);
  Db::run("UPDATE complaints SET status='company_answered',workflow_stage='agent_review',company_replied_at=NOW(),last_note_at=NOW() WHERE id=?",[$id]); _complaint_event($id,'company_reply','company_review','agent_review','company',$u['id'],'ثبت پاسخ شرکت',$reply,true);
  $full=Db::one(_complaint_row_sql()." WHERE co.id=?",[$id]); if($full) _complaint_sms_transition($full,'پاسخ شرکت ثبت شد و پرونده برای بررسی کارشناس ارسال گردید');
  return ['ok'=>true];
}, false, 'company');



/* ==================== ظرفیت شرکت‌ها و رزرو مدارس سال تحصیلی بعد ==================== */
function _ensure_company_capacity_tables(){
  static $done=false; if($done) return; $done=true;
  $cols=[
    'capacity_students'=>'INT NULL',
    'allowed_min_percent'=>'DECIMAL(6,2) NOT NULL DEFAULT 0',
    'allowed_max_percent'=>'DECIMAL(6,2) NOT NULL DEFAULT 0',
  ];
  foreach($cols as $c=>$ddl){ try{ if(!Db::one("SHOW COLUMNS FROM companies WHERE Field=?",[$c])) Db::run("ALTER TABLE companies ADD COLUMN `$c` $ddl"); }catch(\Throwable $e){} }
  try{ Db::run("CREATE TABLE IF NOT EXISTS company_school_reservations (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(\Throwable $e){}
  try{ if(!Db::one("SHOW COLUMNS FROM company_school_reservations WHERE Field='reserved_student_count'")) Db::run("ALTER TABLE company_school_reservations ADD COLUMN reserved_student_count INT NULL AFTER academic_year"); }catch(\Throwable $e){}
  try{ Db::run("CREATE TABLE IF NOT EXISTS company_school_current_assignments (
    company_id INT NOT NULL,
    school_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(company_id, school_id),
    INDEX idx_csca_school(school_id),
    CONSTRAINT fk_csca_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_csca_school FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(\Throwable $e){}
  try{ Db::run("CREATE TABLE IF NOT EXISTS company_school_reservation_status (
    company_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL DEFAULT 'next',
    initialized_from_current TINYINT(1) NOT NULL DEFAULT 0,
    initialized_at DATETIME NULL,
    updated_by_company_user_id INT NULL,
    updated_by_admin_id INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(company_id, academic_year),
    CONSTRAINT fk_csrs_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(\Throwable $e){}
}
function _capacity_summary($companyId,$year='next'){
  _ensure_company_capacity_tables();
  $c=Db::one("SELECT id,title,lat,lng,capacity_students,allowed_min_percent,allowed_max_percent FROM companies WHERE id=?",[(int)$companyId]);
  if(!$c) Http::error('شرکت یافت نشد.',404);
  $cap=(int)($c['capacity_students']??0); $minPct=(float)($c['allowed_min_percent']??0); $maxPct=(float)($c['allowed_max_percent']??0);
  $min=$cap>0 ? (int)floor($cap*(1-$minPct/100)) : 0;
  $max=$cap>0 ? (int)ceil($cap*(1+$maxPct/100)) : 0;
  $row=Db::one("SELECT COALESCE(SUM(COALESCE(r.reserved_student_count,s.student_count,0)),0) total, COUNT(*) cnt FROM company_school_reservations r JOIN schools s ON s.id=r.school_id WHERE r.company_id=? AND r.academic_year=?",[(int)$companyId,$year]);
  $total=(int)($row['total']??0); $status='no_capacity';
  if($cap>0){ if($total>$max) $status='over'; elseif($total<$min) $status='under'; else $status='ok'; }
  return ['company'=>$c,'capacity'=>$cap,'allowed_min_percent'=>$minPct,'allowed_max_percent'=>$maxPct,'allowed_min_students'=>$min,'allowed_max_students'=>$max,'reserved_students'=>$total,'reserved_count'=>(int)($row['cnt']??0),'status'=>$status,'academic_year'=>$year];
}
function _reservation_school_sql(){ return "SELECT s.id,s.code,s.name,s.student_count,s.gender,s.level,s.address,s.lat,s.lng,d.title district_title FROM schools s LEFT JOIN districts d ON d.id=s.district_id"; }
function _reservation_reserved_expr(){ return "COALESCE(r.reserved_student_count,s.student_count,0)"; }
function _ensure_company_reservations_prefilled($companyId, $year='next', $companyUserId=null, $adminId=null){
  _ensure_company_capacity_tables();
  $companyId=(int)$companyId; $year=trim((string)$year) ?: 'next';
  $status=Db::one("SELECT company_id FROM company_school_reservation_status WHERE company_id=? AND academic_year=?",[$companyId,$year]);
  $count=(int)(Db::one("SELECT COUNT(*) n FROM company_school_reservations WHERE company_id=? AND academic_year=?",[$companyId,$year])['n'] ?? 0);
  if($count>0 || $status) return ['seeded'=>false,'count'=>$count];
  $schools=Db::all("SELECT DISTINCT s.id, COALESCE(s.student_count,0) student_count FROM schools s LEFT JOIN company_school_current_assignments a ON a.school_id=s.id WHERE s.company_id=? OR a.company_id=? ORDER BY s.name",[$companyId,$companyId]);
  foreach($schools as $sc){
    Db::run("INSERT INTO company_school_reservations(company_id,school_id,academic_year,reserved_student_count,created_by_company_user_id,created_by_admin_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE reserved_student_count=COALESCE(reserved_student_count,VALUES(reserved_student_count))",[$companyId,(int)$sc['id'],$year,(int)($sc['student_count']??0),$companyUserId,$adminId]);
  }
  Db::run("INSERT INTO company_school_reservation_status(company_id,academic_year,initialized_from_current,initialized_at,updated_by_company_user_id,updated_by_admin_id) VALUES(?,?,1,NOW(),?,?) ON DUPLICATE KEY UPDATE initialized_from_current=VALUES(initialized_from_current), initialized_at=COALESCE(initialized_at,NOW()), updated_by_company_user_id=VALUES(updated_by_company_user_id), updated_by_admin_id=VALUES(updated_by_admin_id)",[$companyId,$year,$companyUserId,$adminId]);
  return ['seeded'=>true,'count'=>count($schools)];
}

route('GET','/api/company/capacity', function($p,$b,$u){ _require_feature('company_capacity_view'); return _capacity_summary($u['company_id'], $_GET['year']??'next'); }, false, 'company');

route('GET','/api/company/reservations', function($p,$b,$u){
  _require_feature('school_reservations'); _require_feature('company_reservation_view');
  _ensure_company_capacity_tables(); $year=$_GET['year']??'next';
  $seed=_ensure_company_reservations_prefilled($u['company_id'],$year,$u['id']??null,null);
  $rows=Db::all("SELECT s.id,s.code,s.name,s.student_count,s.gender,s.level,s.address,s.lat,s.lng,d.title district_title, r.reserved_student_count, GREATEST(COALESCE(s.student_count,0)-COALESCE((SELECT SUM(r2.reserved_student_count) FROM company_school_reservations r2 WHERE r2.school_id=s.id AND r2.academic_year=?),0),0) remaining_student_count FROM company_school_reservations r JOIN schools s ON s.id=r.school_id LEFT JOIN districts d ON d.id=s.district_id WHERE r.company_id=? AND r.academic_year=? ORDER BY s.name",[$year,$u['company_id'],$year]);
  $sum=_capacity_summary($u['company_id'],$year);
  return ['summary'=>$sum,'schools'=>$rows,'is_default_from_current_schools'=>(bool)$seed['seeded'],'prefill_count'=>(int)$seed['count']];
}, false, 'company');

route('POST','/api/company/reservations', function($p,$b,$u){
  _require_feature('school_reservations'); _require_feature('company_reservation_edit');
  _ensure_company_capacity_tables(); $year=trim($b['year']??'next') ?: 'next';
  $items = $b['reservation_items'] ?? null;
  if ($items === null) { $ids=$b['school_ids']??[]; if(!is_array($ids)) Http::error('لیست مدارس نامعتبر است.',400); $items=array_map(fn($id)=>['school_id'=>(int)$id], $ids); }
  if(!is_array($items)) Http::error('لیست مدارس نامعتبر است.',400);
  $clean=[];
  foreach($items as $it){
    if(is_numeric($it)) $it=['school_id'=>(int)$it];
    $sid=(int)($it['school_id'] ?? $it['id'] ?? 0); if($sid<=0 || isset($clean[$sid])) continue;
    $school=Db::one("SELECT id,student_count FROM schools WHERE id=?",[$sid]); if(!$school) continue;
    $cnt = isset($it['reserved_student_count']) && $it['reserved_student_count']!=='' ? max(0,(int)$it['reserved_student_count']) : (int)($school['student_count'] ?? 0);
    $clean[$sid]=$cnt;
  }
  Db::run("DELETE FROM company_school_reservations WHERE company_id=? AND academic_year=?",[$u['company_id'],$year]);
  Db::run("INSERT INTO company_school_reservation_status(company_id,academic_year,initialized_from_current,initialized_at,updated_by_company_user_id) VALUES(?,?,0,NOW(),?) ON DUPLICATE KEY UPDATE initialized_from_current=0, updated_by_company_user_id=VALUES(updated_by_company_user_id), updated_at=NOW()",[$u['company_id'],$year,$u['id']]);
  foreach($clean as $sid=>$cnt){ Db::run("INSERT INTO company_school_reservations(company_id,school_id,academic_year,reserved_student_count,created_by_company_user_id) VALUES(?,?,?,?,?)",[$u['company_id'],$sid,$year,$cnt,$u['id']]); }
  $sum=_capacity_summary($u['company_id'],$year);
  if(_feature_enabled('company_capacity_warning') && $sum['capacity']>0 && $sum['reserved_students']>$sum['allowed_max_students']) Http::error('مجموع تعداد دانش‌آموزان از آستانه مجاز بیشتر است و امکان ثبت لیست رزرو وجود ندارد.',400);
  return ['ok'=>true,'summary'=>$sum];
}, false, 'company');

route('GET','/api/company/reservations/search-schools', function($p,$b,$u){
  _require_feature('school_reservations'); _require_feature('company_reservation_edit');
  $conds=[]; $args=[];
  if(!empty($_GET['q'])){ $q='%'.trim($_GET['q']).'%'; $conds[]='(s.name LIKE ? OR s.code LIKE ?)'; array_push($args,$q,$q); }
  if(!empty($_GET['district_id'])){ $conds[]='s.district_id=?'; $args[]=(int)$_GET['district_id']; }
  if(!empty($_GET['gender'])){ $conds[]='s.gender=?'; $args[]=trim($_GET['gender']); }
  if(!empty($_GET['level'])){ $conds[]='s.level=?'; $args[]=trim($_GET['level']); }
  $where=$conds?' WHERE '.implode(' AND ',$conds):'';
  $year = trim((string)($_GET['year'] ?? 'next')) ?: 'next';
  $rows=Db::all("SELECT s.id,s.code,s.name,s.student_count,s.gender,s.level,s.address,s.lat,s.lng,d.title district_title, COALESCE((SELECT SUM(r2.reserved_student_count) FROM company_school_reservations r2 WHERE r2.school_id=s.id AND r2.academic_year=?),0) reserved_total, GREATEST(COALESCE(s.student_count,0)-COALESCE((SELECT SUM(r3.reserved_student_count) FROM company_school_reservations r3 WHERE r3.school_id=s.id AND r3.academic_year=?),0),0) remaining_student_count FROM schools s LEFT JOIN districts d ON d.id=s.district_id".$where." ORDER BY s.name LIMIT 300", array_merge([$year,$year],$args));
  return $rows;
}, false, 'company');

route('GET','/api/admin/company-reservations', function($p,$b,$u){
  _ensure_company_capacity_tables(); $year=$_GET['year']??'next';
  $rows=Db::all("SELECT c.id,c.title,c.capacity_students,c.allowed_min_percent,c.allowed_max_percent,COUNT(r.id) reserved_count,COALESCE(SUM(COALESCE(r.reserved_student_count,s.student_count,0)),0) reserved_students
    FROM companies c LEFT JOIN company_school_reservations r ON r.company_id=c.id AND r.academic_year=? LEFT JOIN schools s ON s.id=r.school_id
    GROUP BY c.id,c.title,c.capacity_students,c.allowed_min_percent,c.allowed_max_percent ORDER BY c.title",[$year]);
  foreach($rows as &$r){ $cap=(int)($r['capacity_students']??0); $min=(int)floor($cap*(1-(float)$r['allowed_min_percent']/100)); $max=(int)ceil($cap*(1+(float)$r['allowed_max_percent']/100)); $total=(int)$r['reserved_students']; $r['allowed_min_students']=$min; $r['allowed_max_students']=$max; $r['status']=$cap>0?($total>$max?'over':($total<$min?'under':'ok')):'no_capacity'; }
  return $rows;
}, false, 'admin');


route('POST','/api/admin/company-reservations/fill-from-current', function($p,$b,$u){
  _require_role($u, ['super_admin','manager']); _require_feature('bulk_reservation_fill');
  _block_viewer($u);
  _ensure_company_capacity_tables();
  $year = trim((string)($b['year'] ?? ($_GET['year'] ?? 'next'))) ?: 'next';
  $companies = Db::all("SELECT DISTINCT c.id, c.title FROM companies c LEFT JOIN schools s ON s.company_id=c.id LEFT JOIN company_school_current_assignments a ON a.company_id=c.id WHERE s.id IS NOT NULL OR a.school_id IS NOT NULL ORDER BY c.title");
  $result = ['ok'=>true,'academic_year'=>$year,'companies_total'=>count($companies),'companies_updated'=>0,'schools_added'=>0,'details'=>[]];
  foreach($companies as $c){
    $cid=(int)$c['id'];
    $before=(int)(Db::one("SELECT COUNT(*) n FROM company_school_reservations WHERE company_id=? AND academic_year=?",[$cid,$year])['n'] ?? 0);
    $current=Db::all("SELECT DISTINCT s.id, COALESCE(s.student_count,0) student_count FROM schools s LEFT JOIN company_school_current_assignments a ON a.school_id=s.id WHERE s.company_id=? OR a.company_id=?",[$cid,$cid]);
    foreach($current as $sc){
      Db::run("INSERT INTO company_school_reservations(company_id,school_id,academic_year,reserved_student_count,created_by_admin_id) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE reserved_student_count=COALESCE(reserved_student_count,VALUES(reserved_student_count)), updated_at=NOW()",[$cid,(int)$sc['id'],$year,(int)($sc['student_count']??0),$u['id']??null]);
    }
    Db::run("INSERT INTO company_school_reservation_status(company_id,academic_year,initialized_from_current,initialized_at,updated_by_admin_id) VALUES(?,?,1,NOW(),?) ON DUPLICATE KEY UPDATE initialized_from_current=1, updated_by_admin_id=VALUES(updated_by_admin_id), updated_at=NOW()",[$cid,$year,$u['id']??null]);
    $after=(int)(Db::one("SELECT COUNT(*) n FROM company_school_reservations WHERE company_id=? AND academic_year=?",[$cid,$year])['n'] ?? 0);
    $added=max(0,$after-$before);
    if($added>0) $result['companies_updated']++;
    $result['schools_added'] += $added;
    $result['details'][] = ['company_id'=>$cid,'company_title'=>$c['title'],'current_schools'=>count($current),'before'=>$before,'after'=>$after,'added'=>$added];
  }
  return $result;
}, false, 'admin');

route('GET','/api/admin/company-reservations/{id}', function($p,$b,$u){
  _ensure_company_capacity_tables(); $year=$_GET['year']??'next'; $cid=(int)$p['id'];
  $seed=_ensure_company_reservations_prefilled($cid,$year,null,$u['id']??null);
  return ['summary'=>_capacity_summary($cid,$year),'schools'=>Db::all("SELECT s.id,s.code,s.name,s.student_count,s.gender,s.level,s.address,s.lat,s.lng,d.title district_title,r.reserved_student_count FROM company_school_reservations r JOIN schools s ON s.id=r.school_id LEFT JOIN districts d ON d.id=s.district_id WHERE r.company_id=? AND r.academic_year=? ORDER BY s.name",[$cid,$year]),'is_default_from_current_schools'=>(bool)$seed['seeded']];
}, false, 'admin');

route('GET','/api/admin/company-reservations/{id}/export', function($p,$b,$u){
  _ensure_company_capacity_tables(); $year=$_GET['year']??'next'; $cid=(int)$p['id']; _ensure_company_reservations_prefilled($cid,$year,null,$u['id']??null); $sum=_capacity_summary($cid,$year);
  $rows=Db::all("SELECT s.id,s.code,s.name,s.student_count,s.gender,s.level,s.address,s.lat,s.lng,d.title district_title,r.reserved_student_count FROM company_school_reservations r JOIN schools s ON s.id=r.school_id LEFT JOIN districts d ON d.id=s.district_id WHERE r.company_id=? AND r.academic_year=? ORDER BY s.name",[$cid,$year]);
  $out=[]; foreach($rows as $r) $out[]=[$r['code'],$r['name'],$r['district_title'],$r['gender'],$r['level'],$r['student_count'],$r['reserved_student_count'],$r['address'],$r['lat'],$r['lng']];
  _xlsx_download('company-reservations-'.$cid.'.xlsx',['کد یکتا','نام مدرسه','ناحیه','جنسیت','مقطع','تعداد کل دانش‌آموز','تعداد رزروشده برای شرکت','آدرس','عرض','طول'],$out,'رزرو مدارس شرکت','گزارش رزرو مدارس: '.($sum['company']['title']??''),'ظرفیت: '.($sum['capacity']??0).' | حداقل مجاز: '.($sum['allowed_min_students']??0).' | حداکثر مجاز: '.($sum['allowed_max_students']??0).' | مجموع رزرو: '.($sum['reserved_students']??0));
}, false, 'admin');

route('GET','/api/admin/reservation-coverage', function($p,$b,$u){
  _ensure_company_capacity_tables(); $year=$_GET['year']??'next'; $conds=[]; $args=[$year];
  if(!empty($_GET['district_id'])){ $conds[]='s.district_id=?'; $args[]=(int)$_GET['district_id']; }
  if(!empty($_GET['gender'])){ $conds[]='s.gender=?'; $args[]=trim($_GET['gender']); }
  if(!empty($_GET['level'])){ $conds[]='s.level=?'; $args[]=trim($_GET['level']); }
  if(!empty($_GET['q'])){ $q='%'.trim($_GET['q']).'%'; $conds[]='(s.name LIKE ? OR s.code LIKE ?)'; array_push($args,$q,$q); }
  $where=$conds?' WHERE '.implode(' AND ',$conds):'';
  $rows=Db::all("SELECT s.id,s.code,s.name,s.student_count,s.gender,s.level,s.lat,s.lng,d.title district_title,COUNT(r.company_id) reserved_by_count,COALESCE(SUM(COALESCE(r.reserved_student_count,0)),0) reserved_students_total,GROUP_CONCAT(CONCAT(c.title,' (',COALESCE(r.reserved_student_count,0),' نفر)') ORDER BY c.title SEPARATOR '، ') company_titles
    FROM schools s LEFT JOIN districts d ON d.id=s.district_id LEFT JOIN company_school_reservations r ON r.school_id=s.id AND r.academic_year=? LEFT JOIN companies c ON c.id=r.company_id
    $where GROUP BY s.id,s.code,s.name,s.student_count,s.gender,s.level,s.lat,s.lng,d.title ORDER BY reserved_by_count DESC,s.name LIMIT 1000",$args);
  foreach($rows as &$r){ $n=(int)$r['reserved_by_count']; $r['coverage_status']=$n===0?'none':($n===1?'single':'multi'); }
  return $rows;
}, false, 'admin');


route('GET','/api/admin/school-reservation-status', function($p,$b,$u){
  _ensure_company_capacity_tables(); $year=$_GET['year']??'next'; $conds=[]; $args=[$year];
  if(!empty($_GET['district_id'])){ $conds[]='s.district_id=?'; $args[]=(int)$_GET['district_id']; }
  if(!empty($_GET['gender'])){ $conds[]='s.gender=?'; $args[]=trim($_GET['gender']); }
  if(!empty($_GET['level'])){ $conds[]='s.level=?'; $args[]=trim($_GET['level']); }
  if(!empty($_GET['q'])){ $q='%'.trim($_GET['q']).'%'; $conds[]='(s.name LIKE ? OR s.code LIKE ?)'; array_push($args,$q,$q); }
  $where=$conds?' WHERE '.implode(' AND ',$conds):'';
  $rows=Db::all("SELECT s.id,s.code,s.name,s.student_count,s.gender,s.level,d.title district_title,COALESCE(SUM(COALESCE(r.reserved_student_count,0)),0) reserved_students_total,COUNT(r.company_id) reserved_company_count,GROUP_CONCAT(CONCAT(c.title,' (',COALESCE(r.reserved_student_count,0),' نفر)') ORDER BY c.title SEPARATOR '، ') company_titles
    FROM schools s LEFT JOIN districts d ON d.id=s.district_id LEFT JOIN company_school_reservations r ON r.school_id=s.id AND r.academic_year=? LEFT JOIN companies c ON c.id=r.company_id
    $where GROUP BY s.id,s.code,s.name,s.student_count,s.gender,s.level,d.title ORDER BY s.name LIMIT 2000",$args);
  foreach($rows as &$r){ $total=(int)($r['student_count']??0); $reserved=(int)($r['reserved_students_total']??0); $r['remaining_students']=max(0,$total-$reserved); $r['status']=$reserved===0?'none':($reserved<$total?'partial':($reserved===$total?'complete':'over')); }
  return $rows;
}, false, 'admin');

function _company_reservations_map_payload($companyId, $year='next'){
  _ensure_company_capacity_tables();
  $cid=(int)$companyId;
  $seed=_ensure_company_reservations_prefilled($cid,$year,null,null);
  $company=Db::one("SELECT id,title,lat,lng,address FROM companies WHERE id=?",[$cid]);
  if(!$company) Http::error('شرکت یافت نشد.',404);
  $schools=Db::all("SELECT s.id,s.code,s.name,s.student_count,s.gender,s.level,s.address,s.lat,s.lng,d.title district_title,r.reserved_student_count FROM company_school_reservations r JOIN schools s ON s.id=r.school_id LEFT JOIN districts d ON d.id=s.district_id WHERE r.company_id=? AND r.academic_year=? AND s.lat IS NOT NULL AND s.lng IS NOT NULL ORDER BY s.name",[$cid,$year]);
  return ['company'=>$company,'schools'=>$schools,'summary'=>_capacity_summary($cid,$year),'source'=>$seed['seeded']?'prefilled_from_current_schools':'saved_reservations'];
}

route('GET','/api/admin/company-reservations/{id}/map', function($p,$b,$u){
  return _company_reservations_map_payload((int)$p['id'], $_GET['year']??'next');
}, false, 'admin');

route('GET','/api/company/reservations/map', function($p,$b,$u){
  _require_feature('school_reservations'); _require_feature('company_reservation_view');
  return _company_reservations_map_payload((int)$u['company_id'], $_GET['year']??'next');
}, false, 'company');

route('GET', '/api/media', function ($p, $b, $u) {
  $rel = $_GET['path'] ?? ''; if (!$rel) Http::error('نامعتبر', 400);
  Media::serve($rel); exit;
}, true);
