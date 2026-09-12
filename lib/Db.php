<?php
class Db {
  private static $pdo = null;
  public static function pdo() {
    if (self::$pdo) return self::$pdo;
    $c = require __DIR__ . '/../config.php';
    $dsn = "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4";
    self::$pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    self::$pdo->exec("SET time_zone = '+03:30'");
    return self::$pdo;
  }
  // اجرای کوئری با پارامتر و بازگرداندن همهٔ ردیف‌ها
  public static function all($sql, $params = []) { $st = self::pdo()->prepare($sql); $st->execute($params); return $st->fetchAll(); }
  public static function one($sql, $params = []) { $st = self::pdo()->prepare($sql); $st->execute($params); $r = $st->fetch(); return $r === false ? null : $r; }
  public static function run($sql, $params = []) { $st = self::pdo()->prepare($sql); $st->execute($params); return $st; }
  public static function insert($sql, $params = []) { self::run($sql, $params); return (int) self::pdo()->lastInsertId(); }
}
