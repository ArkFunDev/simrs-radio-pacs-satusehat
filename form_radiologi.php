<?php
session_start();
require_once __DIR__ . '/auth_check.php';
require_once 'config.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


// AJAX Handler
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax'])){
    header('Content-Type: application/json');
    if($_POST['ajax']=='search_pemeriksaan'){
        $q = $_POST['q'] ?? '';
        $stmt = $pdo->prepare("SELECT kd_jenis_prw,nm_perawatan,total_byr FROM jns_perawatan_radiologi WHERE nm_perawatan LIKE ? LIMIT 50");
        $stmt->execute(["%$q%"]);
        die(json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)));
    }
    if($_POST['ajax']=='get_dokter'){
        $stmt = $pdo->query("SELECT kd_dokter,nm_dokter FROM dokter WHERE status='1' ORDER BY nm_dokter");
        die(json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)));
    }
}


// Proses Simpan
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['simpan'])){
    try{
        $pdo->beginTransaction();

        $no_rawat=$_POST['no_rawat'];
        $tgl_permintaan=$_POST['tgl_periksa'];
        $jam_permintaan=$_POST['jam_periksa'];
        $kd_dokter=$_POST['kd_dokter_perujuk'];
        $info=substr($_POST['informasi_tambahan']??'',0,60);
        $diag=substr($_POST['diagnosa_klinis']??'',0,80);

        if(!isset($_POST['pemeriksaan']) || count($_POST['pemeriksaan'])==0){
            throw new Exception("Pilih minimal 1 pemeriksaan radiologi!");
        }

        if(empty($kd_dokter)){
            throw new Exception("Dokter perujuk wajib dipilih!");
        }

        // ✅ Generate nomor order dasar format PRyyyymmddxxxx (4 digit terakhir) dari no_rawat
        $parts = explode('/', $no_rawat);
        if(count($parts) != 4){
            throw new Exception("Format no_rawat tidak valid!");
        }
        
        $tahun = $parts[0];
        $bulan = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $tanggal = str_pad($parts[2], 2, '0', STR_PAD_LEFT);
        $counter_raw = $parts[3];
        $counter4 = substr($counter_raw, -4);

        // Noorder dasar (tanpa suffix increment pasien)
        $noorder_base = 'PR' . $tahun . $bulan . $tanggal . $counter4;

        // ✅ Cek apakah pasien ini (no_rawat) sudah pernah punya permintaan radiologi dengan prefix noorder_base
        // pola: PRyyyymmddxxxxX (X = digit 1,2,3,...)
        $stmt = $pdo->prepare("
            SELECT noorder 
            FROM permintaan_radiologi 
            WHERE no_rawat = ? 
              AND noorder LIKE ?
            ORDER BY noorder DESC 
            LIMIT 1
        ");
        $likePrefix = $noorder_base.'%';
        $stmt->execute([$no_rawat, $likePrefix]);
        $lastOrderRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if($lastOrderRow){
            $lastNoorder = $lastOrderRow['noorder'];
            // Ambil 1 digit terakhir sebagai suffix
            $lastSuffix = substr($lastNoorder, -1);
            if(ctype_digit($lastSuffix)){
                $nextSuffix = (int)$lastSuffix + 1;
            }else{
                $nextSuffix = 1;
            }
            // batasi 1 digit (0-9), kalau >9 bisa di-handle sesuai kebijakan (di sini paksa 9)
            if($nextSuffix > 9){
                $nextSuffix = 9;
            }
            $noorder = $noorder_base . $nextSuffix;
        }else{
            // order pertama untuk no_rawat ini → gunakan suffix 1
            $noorder = $noorder_base . '1';
        }

        error_log("=== Generate noorder ===");
        error_log("No Rawat: $no_rawat");
        error_log("Counter Raw: $counter_raw");
        error_log("Counter 4 digit: $counter4");
        error_log("Base noorder: $noorder_base");
        error_log("Final noorder: $noorder");

        // Cek status pasien
        $stmt=$pdo->prepare("SELECT no_rawat FROM kamar_inap WHERE no_rawat=? AND (stts_pulang='-' OR stts_pulang IS NULL)");
        $stmt->execute([$no_rawat]);
        $status = $stmt->rowCount()>0?'Ranap':'Ralan';

        // ✅ INSERT dengan noorder yang sudah di-generate
        $sql = "INSERT INTO permintaan_radiologi 
        (noorder, no_rawat, tgl_permintaan, jam_permintaan, tgl_sampel, jam_sampel, tgl_hasil, jam_hasil, dokter_perujuk, status, informasi_tambahan, diagnosa_klinis) 
        VALUES (?,?,?,?,'0000-00-00','00:00:00','0000-00-00','00:00:00',?,?,?,?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $noorder,
            $no_rawat,
            $tgl_permintaan,
            $jam_permintaan,
            $kd_dokter,
            $status,
            $info,
            $diag
        ]);

        // Insert detail pemeriksaan
        $stmtDetail=$pdo->prepare("INSERT INTO permintaan_pemeriksaan_radiologi (noorder,kd_jenis_prw) VALUES (?,?)");
        foreach($_POST['pemeriksaan'] as $kd){
            $stmtDetail->execute([$noorder,$kd]);
        }

        $pdo->commit();
        header("Location: data_radiologi.php?success=1&noorder=$noorder");
        exit;

    }catch(Exception $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $error="❌ ".$e->getMessage();
    }
}


// Form tampilan
$no_rawat=$_GET['no_rawat']??'';
$kd_dokter_default=$_GET['kd_dokter']??'';
$dataPasien=null;
$namaDokter='';
$status_periksa = 'Ralan';
$status_label = 'Rawat Jalan';

// ✅ Ambil noorder terbaru dari permintaan_radiologi (untuk preview global)
$latest_noorder = '-';
try{
    $stmt_last = $pdo->query("
        SELECT noorder 
        FROM permintaan_radiologi 
        ORDER BY tgl_permintaan DESC, jam_permintaan DESC, noorder DESC 
        LIMIT 1
    ");
    $row_last = $stmt_last->fetch(PDO::FETCH_ASSOC);
    if($row_last && !empty($row_last['noorder'])){
        $latest_noorder = $row_last['noorder'];
    }
}catch(Exception $e){
    // jika error, biarkan preview '-'
}

if($no_rawat){
    $stmt=$pdo->prepare("SELECT rp.*, pas.*, rp.status_lanjut 
        FROM reg_periksa rp 
        INNER JOIN pasien pas ON rp.no_rkm_medis=pas.no_rkm_medis 
        WHERE rp.no_rawat=?");
    $stmt->execute([$no_rawat]);
    $dataPasien=$stmt->fetch();

    if($dataPasien && !empty($dataPasien['status_lanjut'])){
        $status_raw = trim($dataPasien['status_lanjut']);
        
        if(stripos($status_raw, 'ralan') !== false || stripos($status_raw, 'rajal') !== false || strtolower($status_raw) === 'ralan'){
            $status_periksa = 'Ralan';
            $status_label = 'Rawat Jalan';
        } elseif(stripos($status_raw, 'ranap') !== false || strtolower($status_raw) === 'ranap'){
            $status_periksa = 'Ranap';
            $status_label = 'Rawat Inap';
        } else {
            $status_periksa = 'Ralan';
            $status_label = 'Rawat Jalan';
        }
    }

    if(!$kd_dokter_default && $dataPasien){
        $kd_dokter_default=$dataPasien['kd_dokter'];
    }

    if($kd_dokter_default){
        $stmt=$pdo->prepare("SELECT nm_dokter FROM dokter WHERE kd_dokter=?");
        $stmt->execute([$kd_dokter_default]);
        $row=$stmt->fetch();
        $namaDokter=$row['nm_dokter']??'';
    }
}


// ✅ Ambil daftar dokter untuk datalist
$stmt_dokter = $pdo->query("SELECT kd_dokter, nm_dokter FROM dokter WHERE status='1' ORDER BY nm_dokter");
$list_dokter = $stmt_dokter->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Form Permintaan Radiologi - <?=$status_label?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
/* ✅ SEMUA FONT 16px */
body{font:16px Arial,sans-serif;background:#f5f5f5;padding:10px}
.card{background:#fff;padding:12px;margin:8px 0;border-radius:5px;box-shadow:0 2px 4px rgba(0,0,0,.1)}
h2{color:#007bff;margin-bottom:10px;border-bottom:2px solid #007bff;padding-bottom:8px;display:flex;align-items:center;justify-content:space-between;font-size:16px}
.h2-left{display:flex;align-items:center;gap:10px}


/* Compact Table - ✅ FONT 16px */
table{width:100%;border-collapse:collapse;font-size:16px;margin:8px 0}
th,td{padding:6px 8px;text-align:left;border:1px solid #ddd}
th{background:#007bff;color:#fff;font-weight:600;font-size:16px}
td{font-size:16px}


/* Form Grid Layout 2 Kolom - ✅ FONT 16px */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:8px 0}
.form-group{margin:0}
.form-group.full-width{grid-column:1 / -1}
.form-group label{display:block;font-weight:600;margin-bottom:3px;color:#333;font-size:16px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:6px;border:1px solid #ddd;border-radius:3px;font-size:16px}
.form-group textarea{resize:vertical;min-height:50px}
.form-group small{color:#666;font-size:14px;display:block;margin-top:2px}


/* ✅ TOMBOL TETAP 11px */
.btn{padding:7px 15px;border:none;border-radius:3px;cursor:pointer;font-weight:600;margin:5px 3px;display:inline-block;text-decoration:none;font-size:16px}
.btn-primary{background:#007bff;color:#fff}
.btn-success{background:#28a745;color:#fff}
.btn:hover{opacity:0.9}


/* Search Box - ✅ FONT 16px */
.search-box{position:relative}
.search-results{position:absolute;background:#fff;border:1px solid #ddd;max-height:180px;overflow-y:auto;width:100%;z-index:999;box-shadow:0 4px 6px rgba(0,0,0,.1);font-size:16px}
.search-item{padding:8px;cursor:pointer;border-bottom:1px solid #eee;font-size:16px}
.search-item:hover{background:#e9f5ff}
.search-item strong{font-size:16px}
.search-item small{font-size:14px}


/* Selected List - ✅ FONT 16px */
.selected-list{margin-top:6px;min-height:40px;border:1px dashed #ddd;padding:5px;border-radius:3px;max-height:100px;overflow-y:auto}
.selected-item{display:inline-block;background:#007bff;color:#fff;padding:4px 10px;margin:4px;border-radius:3px;font-size:16px}
.selected-item span{cursor:pointer;margin-left:8px;font-weight:bold}


/* Alerts - ✅ FONT 16px */
.alert-error{color:#721c24;background:#f8d7da;border:1px solid:#f5c6cb;padding:8px;border-radius:3px;margin-bottom:8px;font-size:16px}
.alert-success{color:#155724;background:#d4edda;border:1px solid:#c3e6cb;padding:8px;border-radius:3px;margin-bottom:8px;font-size:16px}


/* Info Box - ✅ FONT 16px */
.info-box{background:#e7f3ff;border-left:3px solid #007bff;padding:8px 10px;margin:6px 0;border-radius:3px;font-size:16px}
.info-box strong{color:#007bff}
.info-box small{font-size:14px}


/* Status Badge - ✅ FONT 16px */
.status-badge{display:inline-block;padding:5px 12px;border-radius:14px;font-size:16px;font-weight:bold}
.status-badge.ralan{background:#e3f2fd;color:#1565c0;border:2px solid #1565c0}
.status-badge.ranap{background:#fff3e0;color:#e65100;border:2px solid #e65100}


/* Button Group */
.btn-group{margin-top:16px;text-align:left}


/* Autocomplete Input Style */
.autocomplete-input{min-width:100%}
</style>
</head>
<body>
<div class="card">
<h2>
<div class="h2-left">
<span>📝 Form Permintaan Radiologi</span>
<span class="status-badge <?=strtolower($status_periksa)?>"><?=$status_label?></span>
</div>
</h2>

<?php if(isset($error)):?><div class="alert-error"><?=$error?></div><?php endif;?>

<?php if($dataPasien):?>
<table>
<tr>
<th>No. Rawat</th><td><?=$dataPasien['no_rawat']?></td>
<th>No. RM</th><td><?=$dataPasien['no_rkm_medis']?></td>
<th>JK</th><td><?=$dataPasien['jk']=='L'?'Laki-laki':'Perempuan'?></td>
</tr>
<tr>
<th>Nama Pasien</th><td><?=$dataPasien['nm_pasien']?></td>
<th>Tgl Lahir</th><td><?=date('d-m-Y',strtotime($dataPasien['tgl_lahir']))?></td>
<th>Alamat</th><td><?=$dataPasien['alamat']??'-'?></td>
</tr>
</table>

<?php 
// Preview noorder yang akan di-generate untuk pasien ini (base dari no_rawat)
$preview_noorder = '-';
$parts = explode('/', $dataPasien['no_rawat']);
if(count($parts) == 4){
    $preview_noorder = 'PR' . $parts[0] . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . str_pad($parts[2], 2, '0', STR_PAD_LEFT) . substr($parts[3], -4) . '1';
}
?>
<div class="info-box">
    ℹ️ <strong>No. Order Terbaru Sistem:</strong> <?=$latest_noorder?><br>
    <small>Preview no. order baru untuk pasien ini (order pertama): <?=$preview_noorder?></small>
</div>

<form method="POST">
<input type="hidden" name="no_rawat" value="<?=$no_rawat?>">

<div class="form-grid">
    <!-- Kolom 1 -->
    <div class="form-group">
        <label>📅 Tanggal Permintaan</label>
        <input type="date" name="tgl_periksa" value="<?=date('Y-m-d')?>" required>
    </div>

    <!-- Kolom 2 -->
    <div class="form-group">
        <label>🕐 Jam Permintaan</label>
        <input type="time" name="jam_periksa" value="<?=date('H:i:s')?>" step="1" required>
    </div>

    <!-- Full Width - DOKTER DENGAN AUTOCOMPLETE -->
    <div class="form-group full-width">
        <label>👨‍⚕️ Dokter Perujuk</label>
        <input type="text" 
               id="input_dokter" 
               class="autocomplete-input" 
               placeholder="Ketik nama dokter..." 
               list="list_dokter" 
               value="<?=$namaDokter?>" 
               autocomplete="off"
               required>
        <datalist id="list_dokter">
            <option value="">-- Pilih Dokter --</option>
            <?php foreach($list_dokter as $dok): ?>
            <option value="<?=htmlspecialchars($dok['nm_dokter'])?>" data-kode="<?=$dok['kd_dokter']?>"><?=$dok['kd_dokter']?></option>
            <?php endforeach; ?>
        </datalist>
        <input type="hidden" name="kd_dokter_perujuk" id="kd_dokter" value="<?=$kd_dokter_default?>">
        <small>Default: <strong><?=$namaDokter?:'-'?></strong></small>
    </div>

    <!-- Kolom 1 -->
    <div class="form-group">
        <label>ℹ️ Informasi Tambahan</label>
        <textarea name="informasi_tambahan" maxlength="100" placeholder="Contoh: Permintaan foto lateral"></textarea>
    </div>

    <!-- Kolom 2 -->
    <div class="form-group">
        <label>🩺 Diagnosis Klinis</label>
        <textarea name="diagnosa_klinis" maxlength="100" placeholder="Contoh: Suspect pneumonia"></textarea>
    </div>

    <!-- Full Width -->
    <div class="form-group full-width">
        <label>🔍 Daftar Paket Pemeriksaan</label>
        <div class="search-box">
            <input type="text" id="searchPx" placeholder="Ketik min 2 huruf..." autocomplete="off">
            <div id="searchResults" class="search-results"></div>
        </div>
        <div id="selectedList" class="selected-list"></div>
    </div>
</div>

<div class="btn-group">
    <button type="submit" name="simpan" class="btn btn-primary">💾 Simpan Permintaan</button>
    <a href="data_pasien.php" class="btn btn-success">🔙 Kembali</a>
</div>
</form>

<?php else:?>
<p class="alert-error">Data pasien tidak ditemukan. <a href="data_pasien.php">Kembali</a></p>
<?php endif;?>
</div>

<script>
let selectedPx=[];

// ✅ DATA MAPPING DOKTER
const dokterMap = <?=json_encode(array_column($list_dokter, 'kd_dokter', 'nm_dokter'))?>;

// ✅ EVENT LISTENER UNTUK INPUT DOKTER (AUTOCOMPLETE)
const inputDokter = document.getElementById('input_dokter');
if(inputDokter){
    inputDokter.addEventListener('input', function() {
        const namaDokter = this.value;
        const kdDokter = dokterMap[namaDokter] || '';
        document.getElementById('kd_dokter').value = kdDokter;
        console.log('Nama Dokter:', namaDokter);
        console.log('Kode Dokter:', kdDokter);
    });

    inputDokter.addEventListener('blur', function() {
        const namaDokter = this.value.trim();
        const kdDokter = document.getElementById('kd_dokter').value;
        
        if(namaDokter && !kdDokter){
            const found = Object.keys(dokterMap).find(nm => 
                nm.toLowerCase().includes(namaDokter.toLowerCase())
            );
            
            if(found){
                this.value = found;
                document.getElementById('kd_dokter').value = dokterMap[found];
            } else {
                alert('Dokter tidak ditemukan dalam daftar. Silakan pilih dari list.');
                this.value = '';
                document.getElementById('kd_dokter').value = '';
            }
        }
    });
}

// ✅ PEMERIKSAAN RADIOLOGI
let timeout;
document.getElementById('searchPx').addEventListener('input',function(){
    clearTimeout(timeout);
    const q=this.value.trim();
    const res=document.getElementById('searchResults');
    if(q.length<2){res.innerHTML='';return;}
    timeout=setTimeout(()=>{
        fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`ajax=search_pemeriksaan&q=${encodeURIComponent(q)}`})
        .then(r=>r.json()).then(d=>{
            res.innerHTML='';
            if(d.length==0){res.innerHTML='<div class="search-item" style="color:#999">Tidak ada hasil</div>';return;}
            d.forEach(p=>{
                const div=document.createElement('div');
                div.className='search-item';
                div.innerHTML=`<strong>${p.nm_perawatan}</strong><br><small>Rp ${parseInt(p.total_byr).toLocaleString('id-ID')}</small>`;
                div.onclick=()=>{
                    if(selectedPx.find(x=>x.kd_jenis_prw==p.kd_jenis_prw)){alert('Sudah dipilih!');return;}
                    selectedPx.push(p);
                    render();
                    res.innerHTML='';
                    document.getElementById('searchPx').value='';
                };
                res.appendChild(div);
            });
        });
    },300);
});

function render(){
    const list=document.getElementById('selectedList');
    list.innerHTML='';
    if(selectedPx.length==0){
        list.innerHTML='<div style="color:#999;padding:8px;font-size:14px">Belum ada pemeriksaan</div>';
        return;
    }
    selectedPx.forEach(p=>{
        const div=document.createElement('div');
        div.className='selected-item';
        div.innerHTML=`${p.nm_perawatan} <span onclick="remove('${p.kd_jenis_prw}')">✖</span><input type="hidden" name="pemeriksaan[]" value="${p.kd_jenis_prw}">`;
        list.appendChild(div);
    });
}

function remove(kd){
    selectedPx=selectedPx.filter(x=>x.kd_jenis_prw!=kd);
    render();
}

document.addEventListener('click',e=>{
    if(!document.getElementById('searchPx').contains(e.target))
        document.getElementById('searchResults').innerHTML='';
});
render();
</script>
</body>
</html>
