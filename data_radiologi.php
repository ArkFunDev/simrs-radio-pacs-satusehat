<?php
session_start();
require_once __DIR__ . '/auth_check.php';
require_once 'config.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// AJAX Update Sampel - Manual input
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_sampel'])){
    header('Content-Type: application/json');
    $noorder = $_POST['noorder'] ?? '';
    $tgl_sampel = $_POST['tgl_sampel'] ?? '';
    $jam_sampel = $_POST['jam_sampel'] ?? '';
    
    if(!$noorder) die(json_encode(['status'=>'error','message'=>'No. Permintaan kosong']));
    if(!$tgl_sampel || !$jam_sampel) die(json_encode(['status'=>'error','message'=>'Tanggal dan Jam harus diisi']));

    $stmt=$pdo->prepare("UPDATE permintaan_radiologi SET tgl_sampel=?,jam_sampel=? WHERE noorder=?");
    $stmt->execute([$tgl_sampel,$jam_sampel,$noorder]);
    die(json_encode(['status'=>'success','message'=>'Waktu sampel berhasil diupdate']));
}

// AJAX Update Hasil
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_hasil'])){
    header('Content-Type: application/json');
    $noorder=$_POST['noorder'] ?? '';
    $tgl_hasil=$_POST['tgl_hasil'] ?? '';
    $jam_hasil=$_POST['jam_hasil'] ?? '';
    if(!$noorder) die(json_encode(['status'=>'error','message'=>'No. Permintaan kosong']));

    $stmt=$pdo->prepare("UPDATE permintaan_radiologi SET tgl_hasil=?,jam_hasil=? WHERE noorder=?");
    $stmt->execute([$tgl_hasil,$jam_hasil,$noorder]);
    die(json_encode(['status'=>'success','message'=>'Waktu hasil berhasil diupdate']));
}

// AJAX Hapus Permintaan Radiologi (MASTER + DETAIL)
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_hapus_permintaan'])){
    header('Content-Type: application/json');
    $noorder = $_POST['noorder'] ?? '';
    if(!$noorder) die(json_encode(['status'=>'error','message'=>'No. Permintaan kosong']));

    try {
        $pdo->beginTransaction();

        // HAPUS DETAIL dulu
        $stmt = $pdo->prepare("DELETE FROM permintaan_pemeriksaan_radiologi WHERE noorder=?");
        $stmt->execute([$noorder]);
        $deleted_detail = $stmt->rowCount();

        // HAPUS MASTER
        $stmt = $pdo->prepare("DELETE FROM permintaan_radiologi WHERE noorder=?");
        $stmt->execute([$noorder]);

        if($stmt->rowCount() == 0){
            $pdo->rollBack();
            die(json_encode(['status'=>'error','message'=>'Data master tidak ditemukan / sudah terhapus.']));
        }

        $pdo->commit();
        die(json_encode([
            'status'=>'success',
            'message'=>"Permintaan berhasil dihapus. Detail terhapus: {$deleted_detail}"
        ]));
    } catch (Exception $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        die(json_encode(['status'=>'error','message'=>'Gagal hapus: '.$e->getMessage()]));
    }
}

$tgl_dari=$_GET['tgl_dari']??date('Y-m-d');
$tgl_sampai=$_GET['tgl_sampai']??date('Y-m-d');
$tab_status=$_GET['tab_status']??'Ralan';
$tab_view=$_GET['tab_view']??'data';
$keyword=$_GET['keyword']??'';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Data Permintaan Radiologi</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font:11px Arial,sans-serif;background:#f0f0f0;padding:10px}
.container{background:#fff;border-radius:5px;box-shadow:0 2px 6px rgba(0,0,0,.15)}
.header{background:linear-gradient(135deg,#e74c3c,#c0392b);color:#fff;padding:12px 20px;font-weight:bold;font-size:16px;border-radius:5px 5px 0 0}

.tabs-status{display:flex;background:#ecf0f1;border-bottom:2px solid #bdc3c7;padding:5px 10px}
.tab-status{padding:8px 20px;cursor:pointer;background:#bdc3c7;color:#2c3e50;border-radius:5px 5px 0 0;margin-right:3px;font-weight:bold;font-size:13px}
.tab-status.active{background:#3498db;color:#fff}
.tab-status:hover{background:#5dade2}

.tabs-view{display:flex;background:#d5dbdb;border-bottom:1px solid #95a5a6;padding:5px 10px}
.tab-view{padding:6px 18px;cursor:pointer;background:#95a5a6;color:#fff;border-radius:3px 3px 0 0;margin-right:2px;font-size:12px}
.tab-view.active{background:#fff;color:#2c3e50;font-weight:bold}
.tab-view:hover{background:#7f8c8d}

.filters{background:#ecf0f1;padding:12px;border-bottom:1px solid #bdc3c7;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.filters label{font-size:11px;font-weight:bold;color:#34495e}
.filters input{padding:6px 8px;border:1px solid #bdc3c7;border-radius:3px;font-size:11px}
.filters button{padding:6px 15px;background:#3498db;color:#fff;border:none;border-radius:3px;cursor:pointer;font-size:11px;font-weight:bold}
.filters button:hover{background:#2980b9}
.filters .btn-home{background:#27ae60}
.filters .btn-home:hover{background:#229954}
.filters .actions{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center}

.table-header{
    background:#fff;
    padding:10px 15px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:2px solid #e74c3c;
}
.table-header h3{font-size:13px;color:#2c3e50;font-weight:bold}
.record-count{
    background:#e74c3c;
    color:#fff;
    padding:6px 14px;
    border-radius:20px;
    font-weight:bold;
    font-size:12px;
}

table{width:100%;border-collapse:collapse;font-size:10px}
th{background:#3498db;color:#fff;padding:6px 4px;text-align:center;position:sticky;top:0;z-index:10;font-size:10px;border:1px solid #2980b9;font-weight:bold}
td{padding:5px 4px;border:1px solid #d5dbdb;text-align:center;vertical-align:middle;font-size:10px}
tbody tr:nth-child(even){background:#f9f9f9}
tbody tr:hover{background:#d6eaf8}

.btn{padding:4px 8px;border:none;border-radius:3px;cursor:pointer;font-size:10px;margin:1px;font-weight:bold}
.btn-sampel{background:#27ae60;color:#fff}
.btn-sampel:hover{background:#229954}
.btn-input{background:#9b59b6;color:#fff}
.btn-input:hover{background:#8e44ad}
.btn-danger{background:#e74c3c;color:#fff}
.btn-danger:hover{background:#c0392b}
.btn-disabled{opacity:.55;cursor:not-allowed}
.btn-disabled:hover{filter:none}

tr.row-selected{background:#fff3cd !important;outline:2px solid #f39c12}
tr.row-selected td{font-weight:bold}

.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center}
.modal.show{display:flex}
.modal-content{background:#fff;padding:25px;width:450px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.3);animation:modalSlideIn .25s ease-out}
.modal-content h3{margin-bottom:20px;color:#3498db;border-bottom:2px solid #3498db;padding-bottom:10px}
.modal-content .form-group{margin-bottom:15px}
.modal-content .form-group label{display:block;margin-bottom:5px;font-weight:bold;color:#2c3e50;font-size:12px}
.modal-content input{width:100%;padding:8px;border:1px solid #bdc3c7;border-radius:3px;font-size:12px}
.modal-content .btn-group{margin-top:20px;text-align:right}

@keyframes modalSlideIn{
  from{opacity:0;transform:translateY(-20px) scale(.95)}
  to{opacity:1;transform:translateY(0) scale(1)}
}

/* CUSTOM CONFIRM DIALOG */
.confirm-dialog{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:99999;align-items:center;justify-content:center}
.confirm-dialog.show{display:flex;animation:fadeIn .2s ease-out}
.confirm-box{background:#fff;padding:0;width:420px;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.35);animation:confirmSlideIn .3s cubic-bezier(.25,.46,.45,.94);overflow:hidden}
.confirm-header{background:linear-gradient(135deg,#e74c3c,#c0392b);color:#fff;padding:18px 24px;display:flex;align-items:center;gap:12px}
.confirm-header-icon{font-size:28px;line-height:1}
.confirm-header-title{font-size:16px;font-weight:700;letter-spacing:.3px}
.confirm-body{padding:24px;color:#2c3e50;line-height:1.6}
.confirm-message{font-size:13px;margin-bottom:16px}
.confirm-detail{background:#ecf0f1;padding:12px 14px;border-radius:6px;font-family:monospace;font-size:12px;color:#34495e;border-left:4px solid #e74c3c}
.confirm-footer{padding:0 24px 24px;display:flex;gap:10px;justify-content:flex-end}
.confirm-btn{padding:10px 24px;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;transition:all .2s ease;letter-spacing:.4px}
.confirm-btn-cancel{background:#95a5a6;color:#fff}
.confirm-btn-cancel:hover{background:#7f8c8d;transform:translateY(-1px);box-shadow:0 4px 8px rgba(0,0,0,.15)}
.confirm-btn-ok{background:#e74c3c;color:#fff}
.confirm-btn-ok:hover{background:#c0392b;transform:translateY(-1px);box-shadow:0 6px 12px rgba(231,76,60,.35)}

@keyframes fadeIn{
  from{opacity:0}
  to{opacity:1}
}
@keyframes confirmSlideIn{
  from{opacity:0;transform:translateY(-30px) scale(.9)}
  to{opacity:1;transform:translateY(0) scale(1)}
}

.footer{background:#ecf0f1;padding:10px 15px;border-top:1px solid #bdc3c7;display:flex;justify-content:space-between;font-size:11px;color:#34495e}
.table-wrapper{max-height:420px;overflow-y:auto;overflow-x:hidden}

/* TOAST */
.toast-container{
  position:fixed; top:16px; right:16px; z-index:99999;
  display:flex; flex-direction:column; gap:10px; max-width:560px;
}
.toast{
  background:#2c3e50; color:#ecf0f1;
  padding:12px 14px; border-radius:10px;
  box-shadow:0 8px 20px rgba(0,0,0,.25);
  font-size:12px; line-height:1.35;
  border-left:6px solid #3498db;
  opacity:0; transform:translateY(-6px);
  transition:all .18s ease;
  white-space:pre-wrap;
}
.toast.show{opacity:1; transform:translateY(0)}
.toast.success{border-left-color:#27ae60}
.toast.error{border-left-color:#e74c3c}
.toast.warning{border-left-color:#f39c12}
.toast.info{border-left-color:#3498db}
.toast .close{float:right;border:none;background:transparent;color:#ecf0f1;cursor:pointer;font-size:14px;line-height:14px;opacity:.75}
.toast .close:hover{opacity:1}
.toast .title{font-weight:700;margin-bottom:6px}
</style>
</head>
<body>

<div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>

<!-- Custom Confirm Dialog -->
<div id="confirmDialog" class="confirm-dialog">
  <div class="confirm-box">
    <div class="confirm-header">
      <div class="confirm-header-icon">⚠️</div>
      <div class="confirm-header-title" id="confirmTitle">Konfirmasi</div>
    </div>
    <div class="confirm-body">
      <div class="confirm-message" id="confirmMessage"></div>
      <div class="confirm-detail" id="confirmDetail" style="display:none"></div>
    </div>
    <div class="confirm-footer">
      <button class="confirm-btn confirm-btn-cancel" id="confirmBtnCancel">✖ Batal</button>
      <button class="confirm-btn confirm-btn-ok" id="confirmBtnOk">✓ Ya, Lanjutkan</button>
    </div>
  </div>
</div>

<div class="container">
<div class="header">📊 Data Permintaan Radiologi</div>

<?php
if(isset($_GET['success'])){
  $no = $_GET['noorder'] ?? '-';
  echo "<script>
    window.addEventListener('DOMContentLoaded', () => {
      showToast('✅ Permintaan berhasil disimpan!\\nNo. Order: {$no}', 'success', 4500, 'Sukses');
    });
  </script>";
}
?>

<div class="tabs-status">
  <div class="tab-status <?=$tab_status=='Ralan'?'active':''?>" onclick="switchTab('Ralan','<?=$tab_view?>')">Rawat Jalan</div>
  <div class="tab-status <?=$tab_status=='Ranap'?'active':''?>" onclick="switchTab('Ranap','<?=$tab_view?>')">Rawat Inap</div>
</div>

<div class="tabs-view">
  <div class="tab-view <?=$tab_view=='data'?'active':''?>" onclick="switchView('<?=$tab_status?>','data')">Data Permintaan</div>
  <div class="tab-view <?=$tab_view=='item'?'active':''?>" onclick="switchView('<?=$tab_status?>','item')">Item Permintaan</div>
</div>

<div class="filters">
  <label>Tanggal:</label>
  <input type="date" id="tgl_dari" value="<?=$tgl_dari?>">
  <label>s.d.</label>
  <input type="date" id="tgl_sampai" value="<?=$tgl_sampai?>">
  <label>Cari:</label>
  <input type="text" id="keyword" value="<?=$keyword?>" placeholder="No.Order/No.Rawat/Pasien" style="width:180px">
  <button onclick="applyFilter()">✓ Cari</button>


  <div class="actions">
    <button class="btn-home" onclick="window.location.href='data_pasien.php'">🏠 Beranda</button>
    <button id="btnToolbarSampel" class="btn btn-sampel btn-disabled" onclick="toolbarSampel()" title="Update Sampel untuk baris terpilih">🔬 Sampel</button>
    <button id="btnToolbarHasil" class="btn btn-input btn-disabled" onclick="toolbarHasil()" title="Input Hasil untuk baris terpilih">📋 Hasil</button>
    <button id="btnToolbarHapus" class="btn btn-danger btn-disabled" onclick="toolbarHapus()" title="Hapus permintaan radiologi terpilih">🗑 Hapus</button>
  </div>
</div>

<?php if($tab_view == 'data'): ?>
<?php
$sql="SELECT pr.*,
(SELECT CONCAT(p.no_rkm_medis,' ',p.nm_pasien) FROM reg_periksa rp
 INNER JOIN pasien p ON rp.no_rkm_medis=p.no_rkm_medis
 WHERE rp.no_rawat=pr.no_rawat LIMIT 1) as pasien_info,
(SELECT nm_dokter FROM dokter WHERE kd_dokter=pr.dokter_perujuk LIMIT 1) as nm_dokter,
(SELECT nm_poli FROM reg_periksa rp LEFT JOIN poliklinik pol ON rp.kd_poli=pol.kd_poli WHERE rp.no_rawat=pr.no_rawat LIMIT 1) as poli_registrasi,
(SELECT png_jawab FROM reg_periksa rp LEFT JOIN penjab pnj ON rp.kd_pj=pnj.kd_pj WHERE rp.no_rawat=pr.no_rawat LIMIT 1) as jenis_bayar,
(SELECT GROUP_CONCAT(jp.nm_perawatan SEPARATOR ', ') FROM permintaan_pemeriksaan_radiologi ppr
 INNER JOIN jns_perawatan_radiologi jp ON ppr.kd_jenis_prw=jp.kd_jenis_prw
 WHERE ppr.noorder=pr.noorder) as pemeriksaan_list
FROM permintaan_radiologi pr
WHERE DATE(pr.tgl_permintaan) BETWEEN ? AND ?
AND pr.status=?";
$params=[$tgl_dari,$tgl_sampai,$tab_status];

if($keyword){
  $sql.=" AND (pr.noorder LIKE ? OR pr.no_rawat LIKE ? OR (SELECT CONCAT(p.no_rkm_medis,' ',p.nm_pasien) FROM reg_periksa rp INNER JOIN pasien p ON rp.no_rkm_medis=p.no_rkm_medis WHERE rp.no_rawat=pr.no_rawat) LIKE ?)";
  $params[]="%$keyword%";
  $params[]="%$keyword%";
  $params[]="%$keyword%";
}
$sql.=" GROUP BY pr.noorder ORDER BY pr.tgl_permintaan DESC, pr.jam_permintaan DESC";

$stmt=$pdo->prepare($sql);
$stmt->execute($params);
$data_permintaan = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_records = count($data_permintaan);
?>

<div class="table-header">
  <h3>📋 <?=$tab_status=='Ralan'?'Rawat Jalan':'Rawat Inap'?> - Data Permintaan</h3>
  <div class="record-count">📊 <?=$total_records?> Permintaan</div>
</div>

<div class="table-wrapper">
<table id="tableData">
<thead>
<tr>
  <th style="min-width:110px">No.Permintaan</th>
  <th style="min-width:110px">No.Rawat</th>
  <th style="min-width:140px">Pasien</th>
  <th style="min-width:150px">Pemeriksaan</th>
  <th style="min-width:80px">Permintaan</th>
  <th style="min-width:60px">Jam</th>
  <th style="min-width:80px">Sampel</th>
  <th style="min-width:60px">Jam</th>
  <th style="min-width:80px">Hasil</th>
  <th style="min-width:60px">Jam</th>
  <th style="min-width:140px">Dokter Perujuk</th>
  <th style="min-width:100px"><?=$tab_status=='Ralan'?'Poli Registrasi':'Kamar Terakhir'?></th>
  <th style="min-width:120px">Informasi Tambahan</th>
  <th style="min-width:120px">Diagnosis Klinis</th>
  <th style="min-width:90px">Jenis Bayar</th>
</tr>
</thead>
<tbody>
<?php
if($total_records==0):?>
<tr><td colspan="15" style="padding:30px;color:#95a5a6;font-size:13px">📭 Tidak ada data permintaan radiologi untuk <?=$tab_status=='Ralan'?'Rawat Jalan':'Rawat Inap'?></td></tr>
<?php else:
foreach($data_permintaan as $r):
  $tgl_sampel=$r['tgl_sampel']!='0000-00-00'?date('d-m-Y',strtotime($r['tgl_sampel'])):'-';
  $jam_sampel=$r['jam_sampel']!='00:00:00'?substr($r['jam_sampel'],0,8):'-';
  $tgl_hasil=$r['tgl_hasil']!='0000-00-00'?date('d-m-Y',strtotime($r['tgl_hasil'])):'-';
  $jam_hasil=$r['jam_hasil']!='00:00:00'?substr($r['jam_hasil'],0,8):'-';
?>
<tr data-noorder="<?=$r['noorder']?>">
  <td style="font-family:monospace"><?=$r['noorder']?></td>
  <td style="font-family:monospace"><?=$r['no_rawat']?></td>
  <td style="text-align:left;font-weight:bold"><?=htmlspecialchars($r['pasien_info']??'-')?></td>
  <td style="text-align:left"><?=htmlspecialchars($r['pemeriksaan_list']??'-')?></td>
  <td><?=date('d-m-Y',strtotime($r['tgl_permintaan']))?></td>
  <td><?=substr($r['jam_permintaan'],0,5)?></td>
  <td><?=$tgl_sampel?></td>
  <td><?=$jam_sampel!=='-'?substr($jam_sampel,0,5):'-'?></td>
  <td><?=$tgl_hasil?></td>
  <td><?=$jam_hasil!=='-'?substr($jam_hasil,0,5):'-'?></td>
  <td style="text-align:left"><?=htmlspecialchars($r['nm_dokter']??$r['dokter_perujuk'])?></td>
  <td style="text-align:left"><?=htmlspecialchars($r['poli_registrasi']??'-')?></td>
  <td style="text-align:left"><?=htmlspecialchars($r['informasi_tambahan']??'-')?></td>
  <td style="text-align:left"><?=htmlspecialchars($r['diagnosa_klinis']??'-')?></td>
  <td><?=htmlspecialchars($r['jenis_bayar']??'UMUM')?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

<?php else: ?>
<?php
$sql="SELECT
ppr.noorder,
ppr.kd_jenis_prw,
jp.nm_perawatan,
jp.total_byr,
pr.no_rawat,
pr.tgl_permintaan,
pr.jam_permintaan,
pr.tgl_sampel,
pr.jam_sampel,
pr.tgl_hasil,
pr.jam_hasil,
pr.dokter_perujuk,
pr.informasi_tambahan,
pr.diagnosa_klinis,
(SELECT CONCAT(p.no_rkm_medis,' ',p.nm_pasien) FROM reg_periksa rp
 INNER JOIN pasien p ON rp.no_rkm_medis=p.no_rkm_medis
 WHERE rp.no_rawat=pr.no_rawat LIMIT 1) as pasien_info,
(SELECT nm_dokter FROM dokter WHERE kd_dokter=pr.dokter_perujuk LIMIT 1) as nm_dokter,
(SELECT nm_poli FROM reg_periksa rp LEFT JOIN poliklinik pol ON rp.kd_poli=pol.kd_poli WHERE rp.no_rawat=pr.no_rawat LIMIT 1) as poli_registrasi,
(SELECT png_jawab FROM reg_periksa rp LEFT JOIN penjab pnj ON rp.kd_pj=pnj.kd_pj WHERE rp.no_rawat=pr.no_rawat LIMIT 1) as jenis_bayar
FROM permintaan_pemeriksaan_radiologi ppr
INNER JOIN jns_perawatan_radiologi jp ON ppr.kd_jenis_prw=jp.kd_jenis_prw
INNER JOIN permintaan_radiologi pr ON ppr.noorder=pr.noorder
WHERE DATE(pr.tgl_permintaan) BETWEEN ? AND ?
AND pr.status=?";
$params=[$tgl_dari,$tgl_sampai,$tab_status];

if($keyword){
  $sql.=" AND (ppr.noorder LIKE ? OR pr.no_rawat LIKE ? OR jp.nm_perawatan LIKE ? OR ppr.kd_jenis_prw LIKE ? OR (SELECT CONCAT(p.no_rkm_medis,' ',p.nm_pasien) FROM reg_periksa rp INNER JOIN pasien p ON rp.no_rkm_medis=p.no_rkm_medis WHERE rp.no_rawat=pr.no_rawat) LIKE ?)";
  $params[]="%$keyword%";
  $params[]="%$keyword%";
  $params[]="%$keyword%";
  $params[]="%$keyword%";
  $params[]="%$keyword%";
}
$sql.=" ORDER BY pr.tgl_permintaan DESC, ppr.noorder, ppr.kd_jenis_prw";

$stmt=$pdo->prepare($sql);
$stmt->execute($params);
$data_item = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_records = count($data_item);
?>

<div class="table-header">
  <h3>📋 <?=$tab_status=='Ralan'?'Rawat Jalan':'Rawat Inap'?> - Item Permintaan</h3>
  <div class="record-count">📊 <?=$total_records?> Item</div>
</div>

<div class="table-wrapper">
<table id="tableItem">
<thead>
<tr>
  <th style="min-width:110px">No.Permintaan</th>
  <th style="min-width:110px">No.Rawat</th>
  <th style="min-width:140px">Pasien</th>
  <th style="min-width:150px">Pemeriksaan</th>
  <th style="min-width:80px">Permintaan</th>
  <th style="min-width:60px">Jam</th>
  <th style="min-width:80px">Sampel</th>
  <th style="min-width:60px">Jam</th>
  <th style="min-width:80px">Hasil</th>
  <th style="min-width:60px">Jam</th>
  <th style="min-width:140px">Dokter Perujuk</th>
  <th style="min-width:100px"><?=$tab_status=='Ralan'?'Poli Registrasi':'Kamar Terakhir'?></th>
  <th style="min-width:120px">Informasi Tambahan</th>
  <th style="min-width:120px">Diagnosis Klinis</th>
  <th style="min-width:90px">Jenis Bayar</th>
</tr>
</thead>
<tbody>
<?php
if($total_records==0):?>
<tr><td colspan="15" style="padding:30px;color:#95a5a6;font-size:13px">📭 Tidak ada item permintaan untuk <?=$tab_status=='Ralan'?'Rawat Jalan':'Rawat Inap'?></td></tr>
<?php else:
foreach($data_item as $r):
  $tgl_sampel=$r['tgl_sampel']!='0000-00-00'?date('d-m-Y',strtotime($r['tgl_sampel'])):'-';
  $jam_sampel=$r['jam_sampel']!='00:00:00'?substr($r['jam_sampel'],0,8):'-';
  $tgl_hasil=$r['tgl_hasil']!='0000-00-00'?date('d-m-Y',strtotime($r['tgl_hasil'])):'-';
  $jam_hasil=$r['jam_hasil']!='00:00:00'?substr($r['jam_hasil'],0,8):'-';
?>
<tr data-noorder="<?=$r['noorder']?>">
  <td style="font-family:monospace"><?=$r['noorder']?></td>
  <td style="font-family:monospace"><?=$r['no_rawat']?></td>
  <td style="text-align:left;font-weight:bold"><?=htmlspecialchars($r['pasien_info']??'-')?></td>
  <td style="text-align:left;font-weight:bold"><?=htmlspecialchars($r['nm_perawatan'])?></td>
  <td><?=date('d-m-Y',strtotime($r['tgl_permintaan']))?></td>
  <td><?=substr($r['jam_permintaan'],0,5)?></td>
  <td><?=$tgl_sampel?></td>
  <td><?=$jam_sampel!=='-'?substr($jam_sampel,0,5):'-'?></td>
  <td><?=$tgl_hasil?></td>
  <td><?=$jam_hasil!=='-'?substr($jam_hasil,0,5):'-'?></td>
  <td style="text-align:left"><?=htmlspecialchars($r['nm_dokter']??$r['dokter_perujuk'])?></td>
  <td style="text-align:left"><?=htmlspecialchars($r['poli_registrasi']??'-')?></td>
  <td style="text-align:left"><?=htmlspecialchars($r['informasi_tambahan']??'-')?></td>
  <td style="text-align:left"><?=htmlspecialchars($r['diagnosa_klinis']??'-')?></td>
  <td><?=htmlspecialchars($r['jenis_bayar']??'UMUM')?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<div class="footer">
  <div><strong>Record:</strong> <?=$total_records?> | <strong>Status:</strong> <?=$tab_status=='Ralan'?'Rawat Jalan':'Rawat Inap'?> | <strong>View:</strong> <?=$tab_view=='data'?'Data Permintaan':'Item Permintaan'?></div>
  <div><strong>Periode:</strong> <?=date('d/m/Y',strtotime($tgl_dari))?> - <?=date('d/m/Y',strtotime($tgl_sampai))?></div>
</div>
</div>

<!-- Modal Sampel -->
<div id="modalSampel" class="modal">
  <div class="modal-content">
    <h3>🔬 Update Waktu Sampel</h3>
    <input type="hidden" id="sampel_noorder">
    <div class="form-group">
      <label for="sampel_tgl">📅 Tanggal Sampel:</label>
      <input type="date" id="sampel_tgl" required>
    </div>
    <div class="form-group">
      <label for="sampel_jam">⏰ Jam Sampel:</label>
      <input type="time" id="sampel_jam" required>
    </div>
    <div class="btn-group">
      <button class="btn btn-sampel" style="padding:8px 20px" onclick="saveSampel()">💾 Simpan</button>
      <button class="btn" style="background:#95a5a6;color:#fff;padding:8px 20px" onclick="closeModal('modalSampel')">✖ Batal</button>
    </div>
  </div>
</div>

<script>
let selectedNoorder = null;
let confirmCallback = null;

/* CUSTOM CONFIRM DIALOG */
function showConfirm(message, detail, onOk, title='Konfirmasi Hapus'){
  return new Promise((resolve) => {
    const dialog = document.getElementById('confirmDialog');
    const titleEl = document.getElementById('confirmTitle');
    const messageEl = document.getElementById('confirmMessage');
    const detailEl = document.getElementById('confirmDetail');
    const btnOk = document.getElementById('confirmBtnOk');
    const btnCancel = document.getElementById('confirmBtnCancel');

    titleEl.textContent = title;
    messageEl.textContent = message;

    if(detail){
      detailEl.textContent = detail;
      detailEl.style.display = 'block';
    } else {
      detailEl.style.display = 'none';
    }

    dialog.classList.add('show');

    const handleOk = () => {
      dialog.classList.remove('show');
      btnOk.removeEventListener('click', handleOk);
      btnCancel.removeEventListener('click', handleCancel);
      dialog.removeEventListener('click', handleOutside);
      resolve(true);
      if(onOk) onOk();
    };

    const handleCancel = () => {
      dialog.classList.remove('show');
      btnOk.removeEventListener('click', handleOk);
      btnCancel.removeEventListener('click', handleCancel);
      dialog.removeEventListener('click', handleOutside);
      resolve(false);
    };

    const handleOutside = (e) => {
      if(e.target === dialog){
        handleCancel();
      }
    };

    btnOk.addEventListener('click', handleOk);
    btnCancel.addEventListener('click', handleCancel);
    dialog.addEventListener('click', handleOutside);
  });
}

/* Override browser confirm */
window.confirm = function(msg){
  const parts = msg.split('\n');
  const mainMsg = parts[0] || msg;
  const detail = parts.slice(1).join('\n').trim();

  return new Promise((resolve) => {
    showConfirm(mainMsg, detail, () => resolve(true), 'Konfirmasi').then(result => {
      if(!result) resolve(false);
    });
  });
};

/* TOAST */
function showToast(message, type='info', timeout=4500, title=null){
  const container = document.getElementById('toastContainer');
  if(!container) return;

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;

  const safeMsg = String(message || '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<[^>]*>/g, '');

  const ttl = title || (type === 'success' ? 'Berhasil' :
                       type === 'error' ? 'Gagal' :
                       type === 'warning' ? 'Peringatan' : 'Info');

  toast.innerHTML = `
    <button class="close" onclick="this.parentElement.remove()" title="Tutup">×</button>
    <div class="title">${ttl}</div>
    <div>${safeMsg}</div>
  `;

  container.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('show'));

  if(timeout && timeout > 0){
    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 200);
    }, timeout);
  }
}

/* override alert -> toast */
window.alert = function(msg){
  const s = String(msg || '');
  const lower = s.toLowerCase();
  let type = 'info';
  if(lower.includes('gagal') || lower.includes('error') || lower.includes('sqlstate') || lower.includes('warning')) type='error';
  else if(lower.includes('sukses') || lower.includes('berhasil') || lower.includes('✅')) type='success';
  else if(lower.includes('peringatan') || lower.includes('belum') || lower.includes('⚠')) type='warning';
  showToast(s, type, type==='error' ? 9000 : 4500);
};

function toastRequireSelection(){
  showToast('Pilih salah satu baris data dulu.', 'warning', 2500, 'Aksi');
}

function setToolbarEnabled(enabled){
  const b1 = document.getElementById('btnToolbarSampel');
  const b2 = document.getElementById('btnToolbarHasil');
  const b3 = document.getElementById('btnToolbarHapus');
  if(!b1 || !b2 || !b3) return;

  if(enabled){
    b1.classList.remove('btn-disabled');
    b2.classList.remove('btn-disabled');
    b3.classList.remove('btn-disabled');
  } else {
    b1.classList.add('btn-disabled');
    b2.classList.add('btn-disabled');
    b3.classList.add('btn-disabled');
  }
}

function bindRowSelection(){
  // Support untuk tab Data Permintaan
  const tableData = document.getElementById('tableData');
  if(tableData){
    const rows = tableData.querySelectorAll('tbody tr[data-noorder]');
    rows.forEach(tr => {
      tr.style.cursor = 'pointer';
      tr.addEventListener('click', () => {
        rows.forEach(x => x.classList.remove('row-selected'));
        tr.classList.add('row-selected');
        selectedNoorder = tr.getAttribute('data-noorder');
        setToolbarEnabled(true);
      });
    });
  }

  // Support untuk tab Item Permintaan
  const tableItem = document.getElementById('tableItem');
  if(tableItem){
    const rows = tableItem.querySelectorAll('tbody tr[data-noorder]');
    rows.forEach(tr => {
      tr.style.cursor = 'pointer';
      tr.addEventListener('click', () => {
        rows.forEach(x => x.classList.remove('row-selected'));
        tr.classList.add('row-selected');
        selectedNoorder = tr.getAttribute('data-noorder');
        setToolbarEnabled(true);
      });
    });
  }
}

function toolbarSampel(){
  const btn = document.getElementById('btnToolbarSampel');
  if(btn.classList.contains('btn-disabled') || !selectedNoorder){ toastRequireSelection(); return; }
  updateSampel(selectedNoorder);
}

function toolbarHasil(){
  const btn = document.getElementById('btnToolbarHasil');
  if(btn.classList.contains('btn-disabled') || !selectedNoorder){ toastRequireSelection(); return; }
  window.location.href = 'hasil_radiologi.php?noorder=' + encodeURIComponent(selectedNoorder);
}

async function toolbarHapus(){
  const btn = document.getElementById('btnToolbarHapus');
  if(btn.classList.contains('btn-disabled') || !selectedNoorder){ toastRequireSelection(); return; }

  const confirmed = await showConfirm(
    'Apakah Anda yakin ingin menghapus permintaan radiologi ini?',
    'No. Permintaan: ' + selectedNoorder + '\n\nTindakan ini tidak dapat dibatalkan!',
    null,
    'Konfirmasi Hapus'
  );

  if(!confirmed) return;

  btn.disabled = true;

  fetch('',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`ajax_hapus_permintaan=1&noorder=${encodeURIComponent(selectedNoorder)}`
  })
  .then(r=>r.json())
  .then(d=>{
    btn.disabled = false;
    if(d.status === 'success'){
      showToast('✅ ' + (d.message || 'Berhasil dihapus'), 'success', 3000, 'Hapus');
      setTimeout(()=>location.reload(), 700);
    }else{
      showToast('❌ ' + (d.message || 'Gagal hapus'), 'error', 9000, 'Hapus');
    }
  })
  .catch(e=>{
    btn.disabled = false;
    showToast('❌ Network Error: ' + e.message, 'error', 9000, 'Hapus');
  });
}

function switchTab(status,view){
  const d1=document.getElementById('tgl_dari').value;
  const d2=document.getElementById('tgl_sampai').value;
  const kw=document.getElementById('keyword').value;
  window.location.href=`?tab_status=${status}&tab_view=${view}&tgl_dari=${d1}&tgl_sampai=${d2}&keyword=${encodeURIComponent(kw)}`;
}
function switchView(status,view){ switchTab(status,view); }
function applyFilter(){
  const d1=document.getElementById('tgl_dari').value;
  const d2=document.getElementById('tgl_sampai').value;
  const kw=document.getElementById('keyword').value;
  window.location.href=`?tab_status=<?=$tab_status?>&tab_view=<?=$tab_view?>&tgl_dari=${d1}&tgl_sampai=${d2}&keyword=${encodeURIComponent(kw)}`;
}

function updateSampel(noorder){
  document.getElementById('sampel_noorder').value = noorder;
  
  // Set default value ke tanggal dan jam sekarang
  const now = new Date();
  const dateStr = now.getFullYear() + '-' + 
                  String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                  String(now.getDate()).padStart(2, '0');
  const timeStr = String(now.getHours()).padStart(2, '0') + ':' + 
                  String(now.getMinutes()).padStart(2, '0');
  
  document.getElementById('sampel_tgl').value = dateStr;
  document.getElementById('sampel_jam').value = timeStr;
  
  document.getElementById('modalSampel').classList.add('show');
}

function closeModal(id){
  document.getElementById(id).classList.remove('show');
}

function saveSampel(){
  const noorder = document.getElementById('sampel_noorder').value;
  const tgl = document.getElementById('sampel_tgl').value;
  const jam = document.getElementById('sampel_jam').value;

  if(!tgl || !jam){
    showToast('⚠️ Tanggal dan Jam harus diisi!', 'warning', 2500, 'Validasi');
    return;
  }

  fetch('',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`ajax_sampel=1&noorder=${encodeURIComponent(noorder)}&tgl_sampel=${encodeURIComponent(tgl)}&jam_sampel=${encodeURIComponent(jam)}`
  })
  .then(r=>r.json())
  .then(d=>{
    if(d.status==='success'){
      closeModal('modalSampel');
      showToast('✅ ' + (d.message || 'Waktu sampel berhasil diupdate'), 'success', 2500, 'Sampel');
      setTimeout(()=>location.reload(), 700);
    } else {
      showToast('❌ ' + (d.message || 'Gagal update sampel'), 'error', 9000, 'Sampel');
    }
  })
  .catch(e => showToast('❌ Network Error: ' + e.message, 'error', 9000, 'Sampel'));
}

// Event listener untuk Enter key pada input keyword
function setupKeywordEnter(){
  const keywordInput = document.getElementById('keyword');
  if(!keywordInput) return;

  keywordInput.addEventListener('keypress', function(e){
    if(e.key === 'Enter' || e.keyCode === 13){
      e.preventDefault();
      applyFilter();
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  setToolbarEnabled(false);
  bindRowSelection();
  setupKeywordEnter();
});
</script>
</body>
</html>
