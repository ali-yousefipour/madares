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
    self::eachRowIn($path, 'xl/worksheets/sheet1.xml', $cb);
  }
  // فهرست شیت‌های فایل اکسل به ترتیب همان‌طور که در ورک‌بوک تعریف شده‌اند: [['name'=>..,'target'=>'xl/worksheets/sheetX.xml'], ...]
  public static function listSheets($path) {
    $sheets = [];
    $wbXml = @file_get_contents('zip://' . $path . '#xl/workbook.xml');
    $relsXml = @file_get_contents('zip://' . $path . '#xl/_rels/workbook.xml.rels');
    if ($wbXml === false) return $sheets;
    $wb = @simplexml_load_string($wbXml);
    if (!$wb || !isset($wb->sheets->sheet)) return $sheets;
    $relMap = [];
    if ($relsXml !== false) {
      $rels = @simplexml_load_string($relsXml);
      if ($rels) foreach ($rels->Relationship as $rel) { $relMap[(string)$rel['Id']] = (string)$rel['Target']; }
    }
    $rNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $i = 0;
    foreach ($wb->sheets->sheet as $sheet) {
      $i++;
      $rAttrs = $sheet->attributes($rNs);
      $rid = (string)($rAttrs['id'] ?? '');
      $target = $relMap[$rid] ?? ('worksheets/sheet' . $i . '.xml');
      $target = ltrim($target, '/');
      if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
      $sheets[] = ['name' => (string)$sheet['name'], 'target' => $target];
    }
    return $sheets;
  }
  public static function eachRowIn($path, $target, callable $cb) {
    $shared = self::sharedStrings($path);
    $r = new XMLReader();
    if (@$r->open('zip://' . $path . '#' . $target) === false) throw new Exception('شیت کاری در فایل یافت نشد');
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
