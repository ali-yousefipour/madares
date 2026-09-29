<?php
class Jwt {
  private static function b64($d){ return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
  private static function b64d($d){ return base64_decode(strtr($d, '-_', '+/')); }
  public static function sign($payload, $secret, $ttl) {
    $payload['iat'] = time(); $payload['exp'] = time() + $ttl;
    $h = self::b64(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $p = self::b64(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $sig = self::b64(hash_hmac('sha256', "$h.$p", $secret, true));
    return "$h.$p.$sig";
  }
  public static function verify($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $sig] = $parts;
    $expected = self::b64(hash_hmac('sha256', "$h.$p", $secret, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(self::b64d($p), true);
    if (!$payload || ($payload['exp'] ?? 0) < time()) return null;
    return $payload;
  }
}
