<?php
session_start();
require_once __DIR__ . '/auth_check.php';
require_once 'config.php'; // File config DB
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tanggal_dari = $_GET['tanggal_dari'] ?? date('Y-m-d');
$tanggal_sampai = $_GET['tanggal_sampai'] ?? date('Y-m-d');
$tab = $_GET['tab'] ?? 'rjalan';
$status_periksa = $_GET['status_periksa'] ?? 'Semua';
$status_bayar = $_GET['status_bayar'] ?? 'Semua';
$kd_dokter_filter = $_GET['kd_dokter'] ?? '';
$kd_poli_filter = $_GET['kd_poli'] ?? '';
$kd_bangsal_filter = $_GET['kd_bangsal'] ?? '';
$tgl_masuk_dari = $_GET['tgl_masuk_dari'] ?? date('Y-m-d');
$tgl_masuk_sampai = $_GET['tgl_masuk_sampai'] ?? date('Y-m-d');
$tgl_keluar_dari = $_GET['tgl_keluar_dari'] ?? date('Y-m-d');
$tgl_keluar_sampai = $_GET['tgl_keluar_sampai'] ?? date('Y-m-d');
$filter_type = $_GET['filter_type'] ?? 'belum_pulang'; // Radio button filter type
$keyword = $_GET['keyword'] ?? '';

// Fungsi truncate text
function truncate($text, $length = 24) {
    $text = trim($text);
    if(mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . '...';
}

// Ambil daftar status periksa
$stmt_stts = $pdo->query("SELECT DISTINCT stts FROM reg_periksa WHERE stts IS NOT NULL AND stts != '' ORDER BY stts");
$list_status_periksa = $stmt_stts->fetchAll(PDO::FETCH_COLUMN);

// Ambil daftar status bayar
$stmt_bayar = $pdo->query("SELECT DISTINCT status_bayar FROM reg_periksa WHERE status_bayar IS NOT NULL AND status_bayar != '' ORDER BY status_bayar");
$list_status_bayar = $stmt_bayar->fetchAll(PDO::FETCH_COLUMN);

// Ambil daftar dokter
$stmt_dokter = $pdo->query("SELECT kd_dokter, nm_dokter FROM dokter ORDER BY nm_dokter");
$list_dokter = $stmt_dokter->fetchAll(PDO::FETCH_ASSOC);

// Ambil daftar poliklinik
$stmt_poli = $pdo->query("SELECT kd_poli, nm_poli FROM poliklinik ORDER BY nm_poli");
$list_poli = $stmt_poli->fetchAll(PDO::FETCH_ASSOC);

// Ambil daftar bangsal
$stmt_bangsal = $pdo->query("SELECT kd_bangsal, nm_bangsal FROM bangsal ORDER BY nm_bangsal");
$list_bangsal = $stmt_bangsal->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SIMRS - Beranda</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font:14px Arial,sans-serif;background:#f5f5f5}
.container{max-width:100%;padding:15px}
.tabs{display:flex;gap:10px;margin-bottom:15px;border-bottom:2px solid #ddd}
.tab{padding:8px 16px;cursor:pointer;background:#fff;border:1px solid #ddd;border-bottom:none;border-radius:5px 5px 0 0;font-size:13px}
.tab.active{background:#007bff;color:#fff;font-weight:bold}
.filters{background:#fff;padding:12px;margin-bottom:15px;border-radius:5px;box-shadow:0 2px 4px rgba(0,0,0,.1)}
.filters input,.filters select{padding:6px;margin:3px;border:1px solid #ddd;border-radius:3px;font-size:11px}
.filters button{padding:6px 12px;background:#007bff;color:#fff;border:none;border-radius:3px;cursor:pointer;margin:3px;font-size:11px}
.filters button.btn-success{background:#28a745}
.filters button.btn-warning{background:#ffc107;color:#000}
.filter-row{margin-bottom:8px}
.autocomplete-wrapper{position:relative;display:inline-block}
.autocomplete-input{min-width:200px}

/* Radio Button Styling */
.radio-group{display:flex;gap:20px;align-items:center;margin-bottom:10px;padding:8px;background:#f8f9fa;border-radius:5px}
.radio-item{display:flex;align-items:center;gap:8px;cursor:pointer}
.radio-item input[type="radio"]{width:16px;height:16px;cursor:pointer}
.radio-item label{font-size:12px;font-weight:600;cursor:pointer;color:#333}
.date-range-group{display:flex;align-items:center;gap:5px;margin-left:10px}
.date-range-group input[type="date"]{padding:5px;font-size:11px}
.date-range-group span{font-size:11px;color:#666}

.table-header{
    background:#fff;
    padding:10px 15px;
    border-radius:5px 5px 0 0;
    box-shadow:0 2px 4px rgba(0,0,0,.1);
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:0;
}
.table-header h3{font-size:14px;color:#333}
.patient-count{
    background:#007bff;
    color:#fff;
    padding:5px 12px;
    border-radius:20px;
    font-weight:bold;
    font-size:13px;
}
.table-wrapper{
    background:#fff;
    border-radius:0 0 5px 5px;
    box-shadow:0 2px 4px rgba(0,0,0,.1);
    overflow-y:auto;
    max-height:calc(100vh - 300px);
    position:relative;
    margin-top:0;
}
table{
    width:100%;
    border-collapse:collapse;
    font-size:9px;
    table-layout:fixed;
}
th{
    background:#007bff;
    color:#fff;
    padding:5px 3px;
    text-align:left;
    position:sticky;
    top:0;
    white-space:normal;
    z-index:10;
    font-size:9px;
    font-weight:600;
    border-right:1px solid rgba(255,255,255,0.2);
    line-height:1.2;
}
td{
    padding:4px 3px;
    border-bottom:1px solid #eee;
    border-right:1px solid #f5f5f5;
    font-size:9px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    cursor:help;
}
tr:hover{background:#f8f9fa;cursor:pointer}
.context-menu{
    display:none;
    position:fixed;
    background:#fff;
    border:1px solid #ccc;
    box-shadow:0 2px 8px rgba(0,0,0,.2);
    z-index:9999;
    padding:5px 0;
    border-radius:3px;
}
.context-menu div{padding:8px 15px;cursor:pointer;font-size:13px}
.context-menu div:hover{background:#007bff;color:#fff}
.badge{
    display:inline-block;
    padding:2px 4px;
    border-radius:2px;
    font-size:8px;
    font-weight:bold;
}
.badge.green{background:#28a745;color:#fff}
.badge.red{background:#dc3545;color:#fff}
.badge.blue{background:#007bff;color:#fff}
.badge.orange{background:#fd7e14;color:#fff}

/* Column Widths Rawat Jalan - Total 100% */
.col-kd-dok{width:4%}
.col-dokter{width:8%}
.col-rm{width:5%}
.col-pasien{width:8%}
.col-poli{width:6%}
.col-pj{width:7%}
.col-almt{width:9%}
.col-hub{width:5%}
.col-biaya{width:5%}
.col-jns-bayar{width:6%}
.col-status{width:4%}
.col-norawat{width:7%}
.col-tgl{width:5%}
.col-jam{width:4%}
.col-noreg{width:3%}
.col-stsbayar{width:5%}
.col-stspoli{width:4%}
.col-telp{width:5%}

/* Column Widths Rawat Inap - 21 kolom */
.ranap .col-norawat-ri{width:5.5%}
.ranap .col-rm-ri{width:4%}
.ranap .col-pasien-ri{width:7%}
.ranap .col-almt-ri{width:6%}
.ranap .col-pj-ri{width:5%}
.ranap .col-hub-ri{width:4%}
.ranap .col-jnsbyr-ri{width:5%}
.ranap .col-kamar-ri{width:6%}
.ranap .col-tarif-ri{width:4%}
.ranap .col-diagawal-ri{width:6%}
.ranap .col-diagakhir-ri{width:6%}
.ranap .col-tglmsk-ri{width:4%}
.ranap .col-jammsk-ri{width:3.5%}
.ranap .col-tglklr-ri{width:4%}
.ranap .col-jamklr-ri{width:3.5%}
.ranap .col-ttlbiaya-ri{width:4.5%}
.ranap .col-sttspulang-ri{width:4%}
.ranap .col-lama-ri{width:3%}
.ranap .col-dokpj-ri{width:6%}
.ranap .col-stsbyr-ri{width:4%}
.ranap .col-agama-ri{width:4.5%}
</style>
</head>
<body>
<div class="container">
<h1 style="font-size:18px;margin-bottom:10px">📋 SIMRS - Data Pasien</h1>
<div class="tabs">
<div class="tab <?=$tab=='rjalan'?'active':''?>" onclick="switchTab('rjalan')">Rawat Jalan</div>
<div class="tab <?=$tab=='ranap'?'active':''?>" onclick="switchTab('ranap')">Rawat Inap</div>
</div>

<div class="filters">
<?php if($tab == 'rjalan'): ?>
<div class="filter-row">
<input type="date" id="tgl_dari" value="<?=$tanggal_dari?>">
<input type="date" id="tgl_sampai" value="<?=$tanggal_sampai?>">
<input type="text" id="keyword" placeholder="Cari pasien..." value="<?=$keyword?>" style="min-width:180px">
<button onclick="applyFilter()">🔍 Filter</button>
<button onclick="showAll()" class="btn-warning">📋 Semua</button>
<button onclick="window.location.href='data_radiologi.php'" class="btn-success">📊 Data Radiologi</button>
</div>
<div class="filter-row">
<select id="status_periksa">
<option value="Semua">-- Semua Status Periksa --</option>
<?php foreach($list_status_periksa as $st): ?>
<option value="<?=$st?>" <?=$status_periksa==$st?'selected':''?>><?=$st?></option>
<?php endforeach; ?>
</select>

<select id="status_bayar">
<option value="Semua">-- Semua Status Bayar --</option>
<?php foreach($list_status_bayar as $sb): ?>
<option value="<?=$sb?>" <?=$status_bayar==$sb?'selected':''?>><?=$sb?></option>
<?php endforeach; ?>
</select>

<input type="text" id="input_dokter" class="autocomplete-input" placeholder="Ketik nama dokter..." list="list_dokter" value="<?php
if($kd_dokter_filter){
    foreach($list_dokter as $d){
        if($d['kd_dokter']==$kd_dokter_filter){
            echo htmlspecialchars($d['nm_dokter']);
            break;
        }
    }
}
?>">
<datalist id="list_dokter">
<option value="">-- Semua Dokter --</option>
<?php foreach($list_dokter as $dok): ?>
<option value="<?=htmlspecialchars($dok['nm_dokter'])?>" data-kode="<?=$dok['kd_dokter']?>"><?=$dok['kd_dokter']?></option>
<?php endforeach; ?>
</datalist>
<input type="hidden" id="kd_dokter" value="<?=$kd_dokter_filter?>">

<input type="text" id="input_poli" class="autocomplete-input" placeholder="Ketik nama poli..." list="list_poli" value="<?php
if($kd_poli_filter){
    foreach($list_poli as $p){
        if($p['kd_poli']==$kd_poli_filter){
            echo htmlspecialchars($p['nm_poli']);
            break;
        }
    }
}
?>">
<datalist id="list_poli">
<option value="">-- Semua Poliklinik --</option>
<?php foreach($list_poli as $pol): ?>
<option value="<?=htmlspecialchars($pol['nm_poli'])?>" data-kode="<?=$pol['kd_poli']?>"><?=$pol['kd_poli']?></option>
<?php endforeach; ?>
</datalist>
<input type="hidden" id="kd_poli" value="<?=$kd_poli_filter?>">
</div>
<?php else: ?>
<!-- FILTER RAWAT INAP DENGAN RADIO BUTTON -->
<div class="radio-group">
    <div class="radio-item">
        <input type="radio" name="filter_type" id="filter_belum_pulang" value="belum_pulang" <?=$filter_type=='belum_pulang'?'checked':''?> onchange="toggleDateInputs()">
        <label for="filter_belum_pulang">⏳ Belum Pulang</label>
    </div>
    
    <div class="radio-item">
        <input type="radio" name="filter_type" id="filter_tgl_masuk" value="tgl_masuk" <?=$filter_type=='tgl_masuk'?'checked':''?> onchange="toggleDateInputs()">
        <label for="filter_tgl_masuk">📅 Tgl.Masuk :</label>
        <div class="date-range-group">
            <input type="date" id="tgl_masuk_dari" value="<?=$tgl_masuk_dari?>">
            <span>s.d</span>
            <input type="date" id="tgl_masuk_sampai" value="<?=$tgl_masuk_sampai?>">
        </div>
    </div>
    
    <div class="radio-item">
        <input type="radio" name="filter_type" id="filter_pulang" value="pulang" <?=$filter_type=='pulang'?'checked':''?> onchange="toggleDateInputs()">
        <label for="filter_pulang">🏠 Pulang :</label>
        <div class="date-range-group">
            <input type="date" id="tgl_keluar_dari" value="<?=$tgl_keluar_dari?>">
            <span>s.d</span>
            <input type="date" id="tgl_keluar_sampai" value="<?=$tgl_keluar_sampai?>">
        </div>
    </div>
</div>

<div class="filter-row">
    <input type="text" id="keyword" placeholder="Cari pasien..." value="<?=$keyword?>" style="min-width:180px">
    
    <input type="text" id="input_bangsal" class="autocomplete-input" placeholder="Ketik nama kamar..." list="list_bangsal" value="<?php
    if($kd_bangsal_filter){
        foreach($list_bangsal as $b){
            if($b['kd_bangsal']==$kd_bangsal_filter){
                echo htmlspecialchars($b['nm_bangsal']);
                break;
            }
        }
    }
    ?>">
    <datalist id="list_bangsal">
    <option value="">-- Semua Kamar --</option>
    <?php foreach($list_bangsal as $bng): ?>
    <option value="<?=htmlspecialchars($bng['nm_bangsal'])?>" data-kode="<?=$bng['kd_bangsal']?>"><?=$bng['kd_bangsal']?></option>
    <?php endforeach; ?>
    </datalist>
    <input type="hidden" id="kd_bangsal" value="<?=$kd_bangsal_filter?>">
    
    <button onclick="applyFilterRanap()">🔍 Filter</button>
    <button onclick="showAllRanap()" class="btn-warning">📋 Semua</button>
    <button onclick="window.location.href='data_radiologi.php'" class="btn-success">📊 Data Radiologi</button>
</div>
<?php endif; ?>
</div>

<?php if($tab == 'rjalan'):
// RAWAT JALAN - Filter default hari ini dari tabel reg_periksa
$sql = "SELECT rp.no_rawat, rp.tgl_registrasi, rp.jam_reg, rp.no_reg, rp.no_rkm_medis,
        rp.kd_dokter, rp.kd_poli, rp.p_jawab, rp.almt_pj, rp.hubunganpj,
        rp.biaya_reg, rp.kd_pj, rp.stts, rp.status_bayar, rp.status_poli,
        pas.nm_pasien, pas.no_tlp,
        dok.nm_dokter,
        pol.nm_poli,
        pnj.png_jawab
        FROM reg_periksa rp
        INNER JOIN pasien pas ON rp.no_rkm_medis=pas.no_rkm_medis
        INNER JOIN dokter dok ON rp.kd_dokter=dok.kd_dokter
        LEFT JOIN poliklinik pol ON rp.kd_poli=pol.kd_poli
        LEFT JOIN penjab pnj ON rp.kd_pj=pnj.kd_pj
        WHERE rp.tgl_registrasi BETWEEN ? AND ? AND rp.status_lanjut='Ralan'";
$params = [$tanggal_dari, $tanggal_sampai];

if($status_periksa != 'Semua'){$sql.=" AND rp.stts=?"; $params[]=$status_periksa;}
if($status_bayar != 'Semua'){$sql.=" AND rp.status_bayar=?"; $params[]=$status_bayar;}
if($kd_dokter_filter){$sql.=" AND rp.kd_dokter=?"; $params[]=$kd_dokter_filter;}
if($kd_poli_filter){$sql.=" AND rp.kd_poli=?"; $params[]=$kd_poli_filter;}
if($keyword){$sql.=" AND (pas.nm_pasien LIKE ? OR rp.no_rkm_medis LIKE ? OR rp.no_rawat LIKE ?)"; $params[]="%$keyword%"; $params[]="%$keyword%"; $params[]="%$keyword%";}

$sql.=" ORDER BY rp.tgl_registrasi DESC, rp.jam_reg DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data_rjalan = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_pasien = count($data_rjalan);
?>
<div class="table-header">
<h3>Data Pasien Rawat Jalan - <?=date('d/m/Y', strtotime($tanggal_dari))?> s/d <?=date('d/m/Y', strtotime($tanggal_sampai))?></h3>
<div class="patient-count">👥 <?=$total_pasien?> Pasien</div>
</div>
<div class="table-wrapper">
<table>
<thead>
<tr>
<th class="col-kd-dok">Kd Dok</th>
<th class="col-dokter">Dokter</th>
<th class="col-rm">No RM</th>
<th class="col-pasien">Pasien</th>
<th class="col-poli">Poli</th>
<th class="col-pj">P.Jawab</th>
<th class="col-almt">Alamat PJ</th>
<th class="col-hub">Hub PJ</th>
<th class="col-biaya">Biaya</th>
<th class="col-jns-bayar">Jns Bayar</th>
<th class="col-status">Sts</th>
<th class="col-norawat">No Rawat</th>
<th class="col-tgl">Tanggal</th>
<th class="col-jam">Jam</th>
<th class="col-noreg">Reg</th>
<th class="col-stsbayar">Sts Bayar</th>
<th class="col-stspoli">Sts Poli</th>
<th class="col-telp">Telp</th>
</tr>
</thead>
<tbody>
<?php foreach($data_rjalan as $r): ?>
<tr class="patient-row" data-norawat="<?=$r['no_rawat']?>" data-norm="<?=$r['no_rkm_medis']?>" data-kddokter="<?=$r['kd_dokter']?>">
<td class="col-kd-dok" title="<?=htmlspecialchars($r['kd_dokter'])?>"><?=truncate($r['kd_dokter'])?></td>
<td class="col-dokter" title="<?=htmlspecialchars($r['nm_dokter'])?>"><?=truncate($r['nm_dokter'])?></td>
<td class="col-rm" title="<?=htmlspecialchars($r['no_rkm_medis'])?>"><?=truncate($r['no_rkm_medis'])?></td>
<td class="col-pasien" title="<?=htmlspecialchars($r['nm_pasien'])?>"><?=truncate($r['nm_pasien'])?></td>
<td class="col-poli" title="<?=htmlspecialchars($r['nm_poli'])?>"><?=truncate($r['nm_poli'])?></td>
<td class="col-pj" title="<?=htmlspecialchars($r['p_jawab'])?>"><?=truncate($r['p_jawab'])?></td>
<td class="col-almt" title="<?=htmlspecialchars($r['almt_pj'])?>"><?=truncate($r['almt_pj'])?></td>
<td class="col-hub" title="<?=htmlspecialchars($r['hubunganpj'])?>"><?=truncate($r['hubunganpj'])?></td>
<td class="col-biaya" style="text-align:right" title="Rp <?=number_format($r['biaya_reg'],0,',','.')?>"><?=number_format($r['biaya_reg']/1000,0)?>rb</td>
<td class="col-jns-bayar" title="<?=htmlspecialchars($r['png_jawab'])?>"><?=truncate($r['png_jawab'])?></td>
<td class="col-status"><span class="badge <?=$r['stts']=='Sudah'?'green':'red'?>" title="<?=htmlspecialchars($r['stts'])?>"><?=truncate($r['stts'],6)?></span></td>
<td class="col-norawat" title="<?=htmlspecialchars($r['no_rawat'])?>"><?=truncate($r['no_rawat'])?></td>
<td class="col-tgl" title="<?=date('d-m-Y',strtotime($r['tgl_registrasi']))?>"><?=date('d/m/y',strtotime($r['tgl_registrasi']))?></td>
<td class="col-jam" title="<?=htmlspecialchars($r['jam_reg'])?>"><?=substr($r['jam_reg'],0,5)?></td>
<td class="col-noreg" title="<?=htmlspecialchars($r['no_reg'])?>"><?=truncate($r['no_reg'])?></td>
<td class="col-stsbayar"><span class="badge <?=$r['status_bayar']=='Sudah Bayar'?'green':($r['status_bayar']=='Belum Bayar'?'red':'orange')?>" title="<?=htmlspecialchars($r['status_bayar'])?>"><?=truncate($r['status_bayar'],6)?></span></td>
<td class="col-stspoli"><span class="badge blue" title="<?=htmlspecialchars($r['status_poli'])?>"><?=truncate($r['status_poli'],5)?></span></td>
<td class="col-telp" title="<?=htmlspecialchars($r['no_tlp'])?>"><?=truncate($r['no_tlp'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else:
// RAWAT INAP - Filter berdasarkan radio button
$sql = "SELECT ki.no_rawat, ki.tgl_masuk, ki.jam_masuk, ki.tgl_keluar, ki.jam_keluar,
        ki.diagnosa_awal, ki.diagnosa_akhir, ki.ttl_biaya, ki.stts_pulang, ki.lama, ki.kd_kamar,
        rp.no_rkm_medis, rp.almt_pj, rp.p_jawab, rp.hubunganpj, rp.status_bayar, rp.kd_dokter,
        pas.nm_pasien, pas.agama,
        dok.nm_dokter,
        pnj.png_jawab,
        km.trf_kamar, km.kd_bangsal,
        b.nm_bangsal
        FROM kamar_inap ki
        INNER JOIN reg_periksa rp ON ki.no_rawat=rp.no_rawat
        INNER JOIN pasien pas ON rp.no_rkm_medis=pas.no_rkm_medis
        INNER JOIN kamar km ON ki.kd_kamar=km.kd_kamar
        INNER JOIN bangsal b ON km.kd_bangsal=b.kd_bangsal
        LEFT JOIN dokter dok ON rp.kd_dokter=dok.kd_dokter
        LEFT JOIN penjab pnj ON rp.kd_pj=pnj.kd_pj
        WHERE 1=1";
$params = [];

// Filter berdasarkan radio button
if($filter_type == 'belum_pulang'){
    // ✅ UPDATE: Belum Pulang dengan kondisi lebih spesifik
    // stts_pulang = "-" DAN tgl_keluar = "0000-00-00" DAN jam_keluar = "00:00:00"
    $sql.=" AND ki.stts_pulang='-' AND ki.tgl_keluar='0000-00-00' AND ki.jam_keluar='00:00:00'";
    $filter_label = "Belum Pulang";
} elseif($filter_type == 'tgl_masuk'){
    // Filter Tgl Masuk
    $sql.=" AND ki.tgl_masuk BETWEEN ? AND ?";
    $params[]=$tgl_masuk_dari;
    $params[]=$tgl_masuk_sampai;
    $filter_label = "Tgl Masuk: ".date('d/m/Y', strtotime($tgl_masuk_dari))." s/d ".date('d/m/Y', strtotime($tgl_masuk_sampai));
} elseif($filter_type == 'pulang'){
    // Filter Pulang (tgl_keluar)
    $sql.=" AND ki.tgl_keluar BETWEEN ? AND ? AND ki.tgl_keluar!='0000-00-00' AND ki.tgl_keluar IS NOT NULL";
    $params[]=$tgl_keluar_dari;
    $params[]=$tgl_keluar_sampai;
    $filter_label = "Pulang: ".date('d/m/Y', strtotime($tgl_keluar_dari))." s/d ".date('d/m/Y', strtotime($tgl_keluar_sampai));
}

// Filter bangsal
if($kd_bangsal_filter){
    $sql.=" AND km.kd_bangsal=?";
    $params[]=$kd_bangsal_filter;
}

// Filter keyword
if($keyword){
    $sql.=" AND (pas.nm_pasien LIKE ? OR ki.no_rawat LIKE ? OR rp.no_rkm_medis LIKE ?)";
    $params[]="%$keyword%";
    $params[]="%$keyword%";
    $params[]="%$keyword%";
}

$sql.=" ORDER BY ki.tgl_masuk DESC, ki.jam_masuk DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data_ranap = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_pasien = count($data_ranap);
?>
<div class="table-header">
<h3>Data Pasien Rawat Inap - <?=$filter_label?></h3>
<div class="patient-count">👥 <?=$total_pasien?> Pasien</div>
</div>
<div class="table-wrapper">
<table class="ranap">
<thead>
<tr>
<th class="col-norawat-ri">No Rawat</th>
<th class="col-rm-ri">No RM</th>
<th class="col-pasien-ri">Pasien</th>
<th class="col-almt-ri">Alamat</th>
<th class="col-pj-ri">P.Jawab</th>
<th class="col-hub-ri">Hub PJ</th>
<th class="col-jnsbyr-ri">Jns Bayar</th>
<th class="col-kamar-ri">Kamar</th>
<th class="col-tarif-ri">Tarif</th>
<th class="col-diagawal-ri">Diag Awal</th>
<th class="col-diagakhir-ri">Diag Akhir</th>
<th class="col-tglmsk-ri">Tgl Msk</th>
<th class="col-jammsk-ri">Jam Msk</th>
<th class="col-tglklr-ri">Tgl Klr</th>
<th class="col-jamklr-ri">Jam Klr</th>
<th class="col-ttlbiaya-ri">Ttl Biaya</th>
<th class="col-sttspulang-ri">Sts Pulang</th>
<th class="col-lama-ri">Lama</th>
<th class="col-dokpj-ri">Dokter PJ</th>
<th class="col-stsbyr-ri">Sts Bayar</th>
<th class="col-agama-ri">Agama</th>
</tr>
</thead>
<tbody>
<?php foreach($data_ranap as $r):
$kamar_display = $r['kd_kamar'] . ' ' . $r['nm_bangsal'];
$tgl_keluar = ($r['tgl_keluar'] && $r['tgl_keluar']!='0000-00-00') ? date('d/m/y',strtotime($r['tgl_keluar'])) : '-';
$jam_keluar = ($r['jam_keluar'] && $r['jam_keluar']!='00:00:00') ? substr($r['jam_keluar'],0,5) : '-';
?>
<tr class="patient-row" data-norawat="<?=$r['no_rawat']?>" data-norm="<?=$r['no_rkm_medis']?>" data-kddokter="<?=$r['kd_dokter']?>">
<td class="col-norawat-ri" title="<?=htmlspecialchars($r['no_rawat'])?>"><?=truncate($r['no_rawat'],20)?></td>
<td class="col-rm-ri" title="<?=htmlspecialchars($r['no_rkm_medis'])?>"><?=truncate($r['no_rkm_medis'],15)?></td>
<td class="col-pasien-ri" title="<?=htmlspecialchars($r['nm_pasien'])?>"><?=truncate($r['nm_pasien'],20)?></td>
<td class="col-almt-ri" title="<?=htmlspecialchars($r['almt_pj'])?>"><?=truncate($r['almt_pj'],18)?></td>
<td class="col-pj-ri" title="<?=htmlspecialchars($r['p_jawab'])?>"><?=truncate($r['p_jawab'],18)?></td>
<td class="col-hub-ri" title="<?=htmlspecialchars($r['hubunganpj'])?>"><?=truncate($r['hubunganpj'],15)?></td>
<td class="col-jnsbyr-ri" title="<?=htmlspecialchars($r['png_jawab'])?>"><?=truncate($r['png_jawab'],18)?></td>
<td class="col-kamar-ri" title="<?=htmlspecialchars($kamar_display)?>"><?=truncate($kamar_display,20)?></td>
<td class="col-tarif-ri" style="text-align:right" title="Rp <?=number_format($r['trf_kamar'],0,',','.')?>"><?=number_format($r['trf_kamar']/1000,0)?>rb</td>
<td class="col-diagawal-ri" title="<?=htmlspecialchars($r['diagnosa_awal'])?>"><?=truncate($r['diagnosa_awal'],20)?></td>
<td class="col-diagakhir-ri" title="<?=htmlspecialchars($r['diagnosa_akhir'])?>"><?=truncate($r['diagnosa_akhir'],20)?></td>
<td class="col-tglmsk-ri" title="<?=date('d-m-Y',strtotime($r['tgl_masuk']))?>"><?=date('d/m/y',strtotime($r['tgl_masuk']))?></td>
<td class="col-jammsk-ri" title="<?=htmlspecialchars($r['jam_masuk'])?>"><?=substr($r['jam_masuk'],0,5)?></td>
<td class="col-tglklr-ri" title="<?=$tgl_keluar=='-'?'Belum Keluar':date('d-m-Y',strtotime($r['tgl_keluar']))?>"><?=$tgl_keluar?></td>
<td class="col-jamklr-ri" title="<?=$jam_keluar=='-'?'Belum Keluar':htmlspecialchars($r['jam_keluar'])?>"><?=$jam_keluar?></td>
<td class="col-ttlbiaya-ri" style="text-align:right" title="Rp <?=number_format($r['ttl_biaya'],0,',','.')?>"><?=number_format($r['ttl_biaya']/1000,0)?>rb</td>
<td class="col-sttspulang-ri" title="<?=htmlspecialchars($r['stts_pulang'])?>"><?=truncate($r['stts_pulang'],15)?></td>
<td class="col-lama-ri" title="<?=$r['lama']?> hari"><?=$r['lama']?></td>
<td class="col-dokpj-ri" title="<?=htmlspecialchars($r['nm_dokter'])?>"><?=truncate($r['nm_dokter'],20)?></td>
<td class="col-stsbyr-ri"><span class="badge <?=$r['status_bayar']=='Sudah Bayar'?'green':($r['status_bayar']=='Belum Bayar'?'red':'orange')?>" title="<?=htmlspecialchars($r['status_bayar'])?>"><?=truncate($r['status_bayar'],8)?></span></td>
<td class="col-agama-ri" title="<?=htmlspecialchars($r['agama'])?>"><?=truncate($r['agama'],15)?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>

<div id="contextMenu" class="context-menu">
<div onclick="kirimPermintaan()">📝 Kirim Permintaan Radiologi</div>
</div>

<script>
let selectedRow = null;

// Data mapping dokter, poli, dan bangsal
const dokterMap = <?=json_encode(array_column($list_dokter, 'kd_dokter', 'nm_dokter'))?>;
const poliMap = <?=json_encode(array_column($list_poli, 'kd_poli', 'nm_poli'))?>;
const bangsalMap = <?=json_encode(array_column($list_bangsal, 'kd_bangsal', 'nm_bangsal'))?>;

// Toggle date inputs based on radio selection
function toggleDateInputs() {
    const filterType = document.querySelector('input[name="filter_type"]:checked').value;
    
    // Enable/disable tanggal masuk
    const tglMasukDari = document.getElementById('tgl_masuk_dari');
    const tglMasukSampai = document.getElementById('tgl_masuk_sampai');
    
    // Enable/disable tanggal keluar
    const tglKeluarDari = document.getElementById('tgl_keluar_dari');
    const tglKeluarSampai = document.getElementById('tgl_keluar_sampai');
    
    if(filterType === 'belum_pulang') {
        tglMasukDari.disabled = true;
        tglMasukSampai.disabled = true;
        tglKeluarDari.disabled = true;
        tglKeluarSampai.disabled = true;
    } else if(filterType === 'tgl_masuk') {
        tglMasukDari.disabled = false;
        tglMasukSampai.disabled = false;
        tglKeluarDari.disabled = true;
        tglKeluarSampai.disabled = true;
    } else if(filterType === 'pulang') {
        tglMasukDari.disabled = true;
        tglMasukSampai.disabled = true;
        tglKeluarDari.disabled = false;
        tglKeluarSampai.disabled = false;
    }
}

// Initialize toggle on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleDateInputs();
});

// Event listener untuk input dokter (Rawat Jalan)
const inputDokter = document.getElementById('input_dokter');
if(inputDokter){
    inputDokter.addEventListener('input', function() {
        const namaDokter = this.value;
        const kdDokter = dokterMap[namaDokter] || '';
        document.getElementById('kd_dokter').value = kdDokter;
    });
    inputDokter.addEventListener('keypress', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyFilter();
        }
    });
}

// Event listener untuk input poli (Rawat Jalan)
const inputPoli = document.getElementById('input_poli');
if(inputPoli){
    inputPoli.addEventListener('input', function() {
        const namaPoli = this.value;
        const kdPoli = poliMap[namaPoli] || '';
        document.getElementById('kd_poli').value = kdPoli;
    });
    inputPoli.addEventListener('keypress', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyFilter();
        }
    });
}

// Event listener untuk input bangsal (Rawat Inap)
const inputBangsal = document.getElementById('input_bangsal');
if(inputBangsal){
    inputBangsal.addEventListener('input', function() {
        const namaBangsal = this.value;
        const kdBangsal = bangsalMap[namaBangsal] || '';
        document.getElementById('kd_bangsal').value = kdBangsal;
    });
    inputBangsal.addEventListener('keypress', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyFilterRanap();
        }
    });
}

function switchTab(t){
const today = '<?=date('Y-m-d')?>';
if(t=='rjalan'){
    window.location.href=`?tab=${t}&tanggal_dari=${today}&tanggal_sampai=${today}`;
} else {
    window.location.href=`?tab=${t}&filter_type=belum_pulang`;
}
}

function applyFilter(){
const d1=document.getElementById('tgl_dari').value;
const d2=document.getElementById('tgl_sampai').value;
const k=document.getElementById('keyword').value;
const sp=document.getElementById('status_periksa').value;
const sb=document.getElementById('status_bayar').value;
const dok=document.getElementById('kd_dokter').value;
const pol=document.getElementById('kd_poli').value;
window.location.href=`?tab=rjalan&tanggal_dari=${d1}&tanggal_sampai=${d2}&keyword=${k}&status_periksa=${sp}&status_bayar=${sb}&kd_dokter=${dok}&kd_poli=${pol}`;
}

function applyFilterRanap(){
const filterType = document.querySelector('input[name="filter_type"]:checked').value;
const k = document.getElementById('keyword').value;
const bng = document.getElementById('kd_bangsal').value;
const tm1 = document.getElementById('tgl_masuk_dari').value;
const tm2 = document.getElementById('tgl_masuk_sampai').value;
const tk1 = document.getElementById('tgl_keluar_dari').value;
const tk2 = document.getElementById('tgl_keluar_sampai').value;

window.location.href=`?tab=ranap&filter_type=${filterType}&keyword=${k}&kd_bangsal=${bng}&tgl_masuk_dari=${tm1}&tgl_masuk_sampai=${tm2}&tgl_keluar_dari=${tk1}&tgl_keluar_sampai=${tk2}`;
}

function showAll(){
const today = '<?=date('Y-m-d')?>';
window.location.href=`?tab=rjalan&tanggal_dari=${today}&tanggal_sampai=${today}&keyword=&status_periksa=Semua&status_bayar=Semua&kd_dokter=&kd_poli=`;
}

function showAllRanap(){
window.location.href=`?tab=ranap&filter_type=belum_pulang&keyword=`;
}

// Event listener untuk Enter key pada input keyword
document.getElementById('keyword').addEventListener('keypress', function(event) {
if (event.key === 'Enter') {
event.preventDefault();
<?=$tab=='rjalan'?'applyFilter()':'applyFilterRanap()'?>;
}
});

// Event listener untuk setiap baris pasien
document.querySelectorAll('.patient-row').forEach(row=>{
// Right click - context menu
row.addEventListener('contextmenu',e=>{
e.preventDefault();
selectedRow=row;
const menu=document.getElementById('contextMenu');
menu.style.display='block';
menu.style.left=e.pageX+'px';
menu.style.top=e.pageY+'px';
});
   
// Double click - langsung ke form radiologi
row.addEventListener('dblclick', function() {
const norawat = this.dataset.norawat;
const norm = this.dataset.norm;
const kddokter = this.dataset.kddokter;
window.location.href = `form_radiologi.php?no_rawat=${norawat}&no_rm=${norm}&kd_dokter=${kddokter}`;
});
});

document.addEventListener('click',()=>document.getElementById('contextMenu').style.display='none');

function kirimPermintaan(){
if(!selectedRow)return;
const norawat=selectedRow.dataset.norawat;
const norm=selectedRow.dataset.norm;
const kddokter=selectedRow.dataset.kddokter;
window.location.href=`form_radiologi.php?no_rawat=${norawat}&no_rm=${norm}&kd_dokter=${kddokter}`;
}
</script>
</body>
</html>
