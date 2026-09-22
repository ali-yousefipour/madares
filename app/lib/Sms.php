<?php
// کتابخانهٔ ارسال پیامک از طریق وب‌سرویس نگین (سرویس AlmasSms — نمونه: https://sms.3300.ir/almassms.asmx)
// مبتنی بر WSDL واقعی: namespace = http://www.neginalmas.ir/
// تنظیمات از جدول app_settings خوانده می‌شود (sms_*).

class Sms
{
    const DEFAULT_WSDL = 'https://sms.3300.ir/almassms.asmx?WSDL';
    const LIVE_ENDPOINT = 'https://sms.3300.ir/almassms.asmx';
    const LOCAL_WSDL = __DIR__ . '/almassms.wsdl';

    private static function cfg($key, $default = null)
    {
        $r = Db::one("SELECT value FROM app_settings WHERE `key`=?", [$key]);
        if (!$r) return $default;
        $v = json_decode($r['value'], true);
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function isEnabled()
    {
        return !empty(self::cfg('sms_enabled', false))
            && self::cfg('sms_username') && self::cfg('sms_password');
    }

    // گزینه‌های پایه برای SoapClient (SSL آسان‌گیر + User-Agent برای جلوگیری از صفحهٔ HTML)
    private static function baseOptions()
    {
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
            'http' => [
                'user_agent' => 'Mozilla/5.0 (compatible; TaxiSystem-SMS/1.0)',
                'header' => "Accept: text/xml, application/xml\r\n",
                'timeout' => 25,
            ],
        ]);
        return [
            'connection_timeout' => 25,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'trace' => 1,
            'exceptions' => true,
            'encoding' => 'UTF-8',
            'soap_version' => SOAP_1_1,
            'stream_context' => $ctx,
        ];
    }

    private static function client()
    {
        if (!class_exists('SoapClient')) {
            throw new \Exception('افزونهٔ SOAP در سرور فعال نیست (php-soap).');
        }
        $opts = self::baseOptions();
        $wsdl = self::cfg('sms_wsdl', self::DEFAULT_WSDL);

        // تلاش اول: WSDL تنظیم‌شده/پیش‌فرض (آنلاین)
        try {
            return new \SoapClient($wsdl, $opts);
        } catch (\Throwable $e) {
            $online_err = $e->getMessage();
        }
        // تلاش دوم: فایل WSDL محلی (همراهِ نرم‌افزار) با آدرس زندهٔ سرویس
        // این حالت زمانی به کار می‌آید که سرور WSDL را برنگرداند یا گواهی SSL مشکل داشته باشد.
        if (is_file(self::LOCAL_WSDL)) {
            try {
                $opts['location'] = self::LIVE_ENDPOINT;
                return new \SoapClient(self::LOCAL_WSDL, $opts);
            } catch (\Throwable $e2) {
                throw new \Exception('اتصال به وب‌سرویس پیامک ناموفق بود: ' . ($online_err ?? $e2->getMessage()));
            }
        }
        throw new \Exception('بارگذاری WSDL ناموفق بود: ' . ($online_err ?? 'نامشخص'));
    }

    private static function user() { return (string)self::cfg('sms_username'); }
    private static function pass() { return (string)self::cfg('sms_password'); }
    private static function line() { $l = self::cfg('sms_line', ''); return ($l === '' || $l === null) ? null : $l; }

    /**
     * ارسال یک متن به یک یا چند شماره.
     * بر اساس WSDL: SendSms(pUsername,pPassword,messages[],mobiles[]) → SendSmsResult(long) + pMessageIds(long[])
     * نتیجهٔ منفی = موفق، مقدار کوچک مثبت = کد خطا.
     * @return array ['ok'=>bool, 'id'=>?string, 'ids'=>array, 'error'=>?string]
     */
    public static function send($mobiles, $message, $kind = null, $sentBy = null)
    {
        $mobiles = is_array($mobiles) ? array_values(array_filter(array_map('trim', $mobiles))) : [trim($mobiles)];
        if (!$mobiles) return ['ok' => false, 'error' => 'شماره‌ای برای ارسال وجود ندارد'];
        if (trim((string)$message) === '') return ['ok' => false, 'error' => 'متن پیامک خالی است'];
        if (!self::isEnabled()) return ['ok' => false, 'error' => 'سرویس پیامک فعال یا تنظیم نشده است'];
        // بررسی محدودیت روزانهٔ ارسال پیامک کاربر
        if ($sentBy) {
            $limitR = \Db::one("SELECT value FROM app_settings WHERE `key`='sms_daily_limit'");
            $limit = $limitR ? (int)json_decode($limitR['value'], true) : 0;
            if ($limit > 0) {
                $sentToday = (int)(\Db::one("SELECT COUNT(*) n FROM sms_log WHERE sent_by=? AND DATE(created_at)=CURDATE()", [$sentBy])['n'] ?? 0);
                if ($sentToday >= $limit) return ['ok' => false, 'error' => "سقف ارسال روزانه ({$limit} پیامک) برای شما تمام شده است."];
            }
            // محدودیت اختصاصی کاربر
            $userLimitR = \Db::one("SELECT value FROM app_settings WHERE `key`=?", ["sms_limit_user_{$sentBy}"]);
            if ($userLimitR) {
                $userLimit = (int)json_decode($userLimitR['value'], true);
                if ($userLimit > 0) {
                    $sentToday = (int)(\Db::one("SELECT COUNT(*) n FROM sms_log WHERE sent_by=? AND DATE(created_at)=CURDATE()", [$sentBy])['n'] ?? 0);
                    if ($sentToday >= $userLimit) return ['ok' => false, 'error' => "سقف ارسال روزانهٔ شما ({$userLimit} پیامک) تمام شده است."];
                }
            }
        }

        $ok = false; $ids = []; $firstId = null; $err = null; $result = -999;
        try {
            $client = self::client();
            // متد اصلی و غیرمنسوخِ WSDL: SendSms(pUsername,pPassword,messages[],mobiles[])
            $resp = $client->SendSms([
                'pUsername' => self::user(), 'pPassword' => self::pass(),
                'messages' => [$message], 'mobiles' => $mobiles,
            ]);
            $result = isset($resp->SendSmsResult) ? (float)$resp->SendSmsResult : -999;
            $ids = self::longArray($resp->pMessageIds ?? null);
            // تفسیر نتیجه: شناسه‌ها = موفق، نتیجهٔ منفی = موفق، مقدار کوچک مثبت = کد خطا
            if (!empty($ids)) { $ok = true; $firstId = (string)$ids[0]; }
            elseif ($result < 0) { $ok = true; $firstId = (string)$result; }
            else { $ok = false; $err = self::errorText((int)$result); }
        } catch (\Throwable $e) {
            $ok = false; $err = $e->getMessage();
        }

        // ثبت در لاگ: برای هر شماره یک ردیف، با شناسهٔ متناظر در صورت وجود
        try {
            foreach ($mobiles as $i => $m) {
                $mid = $ids[$i] ?? $firstId;
                Db::run("INSERT INTO sms_log(to_mobile,body,kind,status,message_id,sent_by) VALUES(?,?,?,?,?,?)",
                    [$m, $message, $kind, $ok ? 'ok' : 'error', $mid !== null ? (string)$mid : null, $sentBy]);
            }
        } catch (\Throwable $e) {}

        return ['ok' => $ok, 'id' => $firstId, 'ids' => $ids, 'error' => $ok ? null : ($err ?: 'ارسال ناموفق')];
    }

    /**
     * دریافت وضعیت تحویل پیامک‌ها بر اساس شناسه‌ها.
     * GetMessageStatus(pUsername,pPassword,pMessageIds[]) → GetMessageStatusResult(long) + pStatuses(int[]) + pMsgIds(long[])
     * @return array  ['<messageId>'=>statusCode, ...]  یا  ['ok'=>false,'error'=>..]
     */
    public static function messageStatus($messageIds)
    {
        $ids = array_values(array_filter(array_map('strval', (array)$messageIds), fn($x) => $x !== '' && is_numeric($x)));
        if (!$ids) return [];
        if (!self::isEnabled()) return ['ok' => false, 'error' => 'سرویس پیامک فعال نیست'];
        try {
            $client = self::client();
            $resp = $client->GetMessageStatus([
                'pUsername' => self::user(), 'pPassword' => self::pass(),
                'pMessageIds' => array_map('floatval', $ids),
            ]);
            $statuses = self::intArray($resp->pStatuses ?? null);
            $msgIds   = self::longArray($resp->pMsgIds ?? null);
            $out = [];
            if ($msgIds && $statuses && count($msgIds) === count($statuses)) {
                foreach ($msgIds as $i => $mid) $out[(string)$mid] = (int)$statuses[$i];
            } elseif ($statuses && count($statuses) === count($ids)) {
                foreach ($ids as $i => $mid) $out[(string)$mid] = (int)$statuses[$i];
            }
            return $out;
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * اعتبار باقی‌مانده (ریال).
     * GetCredit(pUsername,pPassword) → GetCreditResult(long) + pCredit(long)
     */
    public static function credit()
    {
        if (!self::isEnabled()) return ['ok' => false, 'error' => 'سرویس پیامک فعال نیست'];
        try {
            $client = self::client();
            $resp = $client->GetCredit(['pUsername' => self::user(), 'pPassword' => self::pass()]);
            $credit = isset($resp->pCredit) ? (float)$resp->pCredit : null;
            if ($credit === null && isset($resp->GetCreditResult)) $credit = (float)$resp->GetCreditResult;
            return ['ok' => true, 'credit' => $credit];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * اطلاعات حساب: شمارهٔ خط، اعتبار، تاریخ انقضا.
     * GetInfo(pUsername,pPassword) → pUserId,pSmsNumber,pCredit,pEndDate
     */
    public static function info()
    {
        if (!self::isEnabled()) return ['ok' => false, 'error' => 'سرویس پیامک فعال نیست'];
        try {
            $client = self::client();
            $resp = $client->GetInfo(['pUsername' => self::user(), 'pPassword' => self::pass()]);
            return ['ok' => true,
                'sms_number' => $resp->pSmsNumber ?? null,
                'credit' => isset($resp->pCredit) ? (float)$resp->pCredit : null,
                'end_date' => $resp->pEndDate ?? null,
                'user_id' => $resp->pUserId ?? null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ---- کمکی: تبدیل خروجی‌های آرایه‌ایِ SOAP به آرایهٔ ساده ----
    private static function longArray($node) { return self::soapArray($node, 'long'); }
    private static function intArray($node)  { return self::soapArray($node, 'int'); }
    private static function soapArray($node, $key)
    {
        if ($node === null) return [];
        if (is_object($node)) {
            if (isset($node->$key)) $node = $node->$key;
            else { $vals = array_values((array)$node); $node = (count($vals) === 1) ? $vals[0] : $vals; }
        }
        if (!is_array($node)) $node = [$node];
        return array_values(array_map(fn($x) => is_numeric($x) ? (0 + $x) : $x, $node));
    }

    public static function deliveryText($code)
    {
        $map = [-1 => 'ارسال نشده', 1 => 'تحویل به گیرنده', 2 => 'ناموفق (Fail)', 8 => 'تحویل به مخابرات', 16 => 'عدم تحویل مخابرات', 0 => 'نامشخص'];
        return $map[(int)$code] ?? ('کد ' . $code);
    }

    private static function errorText($code)
    {
        $map = [
            1 => 'شماره گیرنده نامعتبر است', 2 => 'شمارهٔ خط نامعتبر است', 3 => 'encoding نامعتبر',
            4 => 'messageClass نامعتبر', 6 => 'messageClass نامعتبر', 13 => 'متن پیامک خالی است',
            14 => 'اعتبار کافی نیست', 15 => 'خطای داخلی سرور؛ دوباره ارسال کنید', 16 => 'حساب غیرفعال است',
            17 => 'حساب منقضی شده است', 18 => 'نام کاربری یا رمز اشتباه است', 19 => 'احراز هویت ناموفق',
            22 => 'این سرویس برای حساب شما فعال نیست', 23 => 'ترافیک بالا؛ دوباره تلاش کنید',
            24 => 'شناسهٔ پیامک نامعتبر است', 25 => 'نوع سرویس نامعتبر', 27 => 'انصراف مخاطب از دریافت',
            101 => 'عدم تطابق طول آرایهٔ پیام‌ها', 106 => 'فهرست گیرندگان خالی است', 107 => 'تعداد گیرندگان بیش از حد مجاز',
            409 => 'فاصلهٔ زمانی بین درخواست‌ها رعایت نشده (حداقل ۵ ثانیه)',
        ];
        return $map[$code] ?? ('کد خطای سرویس پیامک: ' . $code);
    }
}
