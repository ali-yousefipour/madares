<?php
// خوانندهٔ جریانی xlsx (کم‌حافظه) با XMLReader + وارد‌کنندهٔ مقاوم
class Xlsx {
  private static function sharedStrings($path) {
    $shared = [];
    $r = new XMLReader();
    if (@$r->open('zip://' . $path . '#xl/sharedStrings.xml') === false) return $shared;
    while ($r->read()) {
      if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
        $xml = simplexml_load_string($r->readOuterXML());
        $shared[] = self::siText($xml);
      }
    }
    $r->close();
    return $shared;
  }
  private static function siText($si) {
    if (isset($si->t)) return (string)$si->t;
    $o = ''; foreach ($si->r as $rr) $o .= (string)$rr->t; return $o;
  }
  private static function colIndex($letters) {
    $n = 0; $letters = preg_replace('/[^A-Z]/', '', strtoupper($letters));
    foreach (str_split($letters) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
  }
  public static function eachRow($path, callable $cb) {
    $shared = self::sharedStrings($path);
    $r = new XMLReader();
    if (@$r->open('zip://' . $path . '#xl/worksheets/sheet1.xml') === false) throw new Exception('شیت کاری در فایل یافت نشد');
    $idx = 0;
    while ($r->read()) {
      if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'row') {
        $row = simplexml_load_string($r->readOuterXML());
        $cells = [];
        foreach ($row->c as $c) {
          $col = self::colIndex((string)$c['r']);
          $t = (string)$c['t']; $v = '';
          if ($t === 's') { $v = $shared[(int)$c->v] ?? ''; }
          elseif ($t === 'inlineStr') { $v = isset($c->is->t) ? (string)$c->is->t : ''; }
          else { $v = (string)$c->v; }
          $cells[$col] = $v;
        }
        $cb($cells, $idx++);
      }
    }
    $r->close();
  }
}
