<?php
require_once __DIR__ . '/../lib/Xlsx.php';
require_once __DIR__ . '/../lib/Db.php';

$xlsx = __DIR__ . '/../new data.xlsx';
$out = __DIR__ . '/../school_sync.sql';
$audit = __DIR__ . '/../school_sync_audit.csv';
if (!is_file($xlsx)) die("Excel not found: $xlsx\n");

function nrm($v){
  $s=trim((string)$v);
  $s=strtr($s,['ي'=>'ی','ى'=>'ی','ك'=>'ک','ۀ'=>'ه','ة'=>'ه','‌'=>' ','‏'=>'','‎'=>'']);
  $s=preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u','',$s);
  $s=str_replace(['ـ','-','_','/'], ' ', $s);
  $s=preg_replace('/\s+/u',' ',$s);
  $s=strtr($s,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
  return mb_strtolower(trim($s),'UTF-8');
}
function districtKey($v){
  $s=nrm($v);
  if(preg_match('/\d+/',$s,$m)) return (string)(int)$m[0];
  return $s;
}
function col($headers,$names){
  foreach($headers as $i=>$h) foreach($names as $n) if(nrm($h)===nrm($n)) return $i;
  return null;
}
function q($v){ if($v===null||$v==='') return 'NULL'; return "'".str_replace(['\\',"'"],['\\\\',"''"],(string)$v)."'"; }
function intvalSql($v){ $v=trim((string)$v); if($v==='')return 'NULL'; $v=str_replace(',','',$v); return preg_match('/^\d+(?:\.0+)?$/',$v)?(string)(int)$v:'NULL'; }

$pdo=Db::pdo();
$districts=$pdo->query('SELECT id,title FROM districts')->fetchAll();
$companies=$pdo->query('SELECT id,title FROM companies')->fetchAll();
$schools=$pdo->query('SELECT id,code,name,district_id,company_id,level,student_count,is_active FROM schools')->fetchAll();
$dmap=[]; foreach($districts as $d){$dmap[districtKey($d['title'])]=(int)$d['id'];}
$cmap=[]; foreach($companies as $c){$cmap[nrm($c['title'])]=(int)$c['id'];}
$byCode=[];$byKey=[];
foreach($schools as $s){$byCode[nrm($s['code'])]=$s;$dk=''; foreach($districts as $d) if((int)$d['id']===(int)$s['district_id']){$dk=districtKey($d['title']);break;} $key=nrm($s['name']).'|'.$dk.'|'.nrm($s['level']); $byKey[$key][]=$s;}

$excel=[];
foreach(Xlsx::listSheets($xlsx) as $sheet){
  Xlsx::eachRowIn($xlsx,$sheet['target'],function($row,$ri)use(&$excel,$sheet){
    static $headersBySheet=[];
    if($ri===0){$headersBySheet[$sheet['name']]=$row;return;}
    $h=$headersBySheet[$sheet['name']]??[];
    $ci=[
      'code'=>col($h,['کد مدرسه','کد','school code']),
      'name'=>col($h,['نام مدرسه','نام','school name']),
      'district'=>col($h,['ناحیه','منطقه','district']),
      'level'=>col($h,['مقطع','مقطع تحصیلی','level']),
      'company'=>col($h,['شرکت','شرکت سرویس','company']),
      'students'=>col($h,['تعداد دانش آموز','تعداد دانش‌آموز','دانش آموز','student count'])];
    $get=function($k)use($row,$ci){$i=$ci[$k];return $i===null?null:($row[$i]??null);};
    if(($get('name')??'')!=='' && ($get('district')??'')!=='')$excel[]=['sheet'=>$sheet['name'],'code'=>$get('code'),'name'=>$get('name'),'district'=>$get('district'),'level'=>$get('level'),'company'=>$get('company'),'students'=>$get('students')];
  });
}

$matched=[];$amb=[];$missing=[];$seen=[];
foreach($excel as $e){
  $key=nrm($e['name']).'|'.districtKey($e['district']).'|'.nrm($e['level']);$cands=[];$code=nrm($e['code']);
  if($code!=='' && isset($byCode[$code])){ $s=$byCode[$code]; $valid=(nrm($s['name'])===nrm($e['name'])); foreach($districts as $d)if((int)$d['id']===(int)$s['district_id']){$valid=$valid&&(districtKey($d['title'])===districtKey($e['district']));break;} $valid=$valid&&(nrm($s['level'])===nrm($e['level'])); if($valid)$cands=[$s]; }
  if(!$cands)$cands=$byKey[$key]??[];
  if(count($cands)===1){$matched[]=[$e,$cands[0]];$seen[(int)$cands[0]['id']]=true;}
  elseif(count($cands)>1)$amb[]=[$e,$cands];
  else $missing[]=$e;
}

$fh=fopen($audit,'w');fputcsv($fh,['status','sheet','name','district','level','code','company','students','db_id','note']);
foreach($matched as [$e,$s])fputcsv($fh,['matched',$e['sheet'],$e['name'],$e['district'],$e['level'],$e['code'],$e['company'],$e['students'],$s['id'],'']);
foreach($amb as [$e,$c])fputcsv($fh,['ambiguous',$e['sheet'],$e['name'],$e['district'],$e['level'],$e['code'],$e['company'],$e['students'],'','multiple database candidates']);
foreach($missing as $e)fputcsv($fh,['missing_in_db',$e['sheet'],$e['name'],$e['district'],$e['level'],$e['code'],$e['company'],$e['students'],'','no database candidate']);
fclose($fh);

echo 'Excel rows: '.count($excel).PHP_EOL.'Matched: '.count($matched).PHP_EOL.'Ambiguous: '.count($amb).PHP_EOL.'Missing in DB: '.count($missing).PHP_EOL.'DB schools: '.count($schools).PHP_EOL;
if($amb||$missing){ echo "SAFE STOP: school_sync.sql was NOT generated because the Excel list has unresolved rows. See school_sync_audit.csv\n"; exit(2); }

$sql=['SET NAMES utf8mb4;','START TRANSACTION;'];
foreach($matched as [$e,$s]){
  $sets=['is_active=1','student_count='.intvalSql($e['students'])];
  if(($e['company']??'')!==''){ $ck=nrm($e['company']); if(!isset($cmap[$ck])){echo "Company not found: {$e['company']}\n";exit(3);} $sets[]='company_id='.$cmap[$ck]; }
  $sql[]='UPDATE schools SET '.implode(', ',$sets).' WHERE id='.(int)$s['id'].';';
}
foreach($schools as $s)if(!isset($seen[(int)$s['id']]))$sql[]='UPDATE schools SET is_active=0 WHERE id='.(int)$s['id'].';';
$sql[]='COMMIT;';
file_put_contents($out,implode(PHP_EOL,$sql).PHP_EOL);
echo "Generated: $out\n";