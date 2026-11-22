<?php
session_start();
require_once __DIR__ . '/auth_check.php';
require_role(['radiologi', 'superadmin']);
include 'config.php';

// ============================================
// HANDLER: SIMPAN TANGGAL SAMPEL
// ============================================
if (isset($_POST['simpan_sampel'])) {
    $noorder    = $_POST['noorder'] ?? '';
    $tgl_sampel = $_POST['tgl_sampel'] ?? '';
    $jam_sampel = $_POST['jam_sampel'] ?? '';

    if ($noorder && $tgl_sampel && $jam_sampel) {
        try {
            $pdo->beginTransaction();

            $updateRadiologi = "UPDATE permintaan_radiologi
                                 SET tgl_sampel = :tgl_sampel,
                                     jam_sampel = :jam_sampel
                                 WHERE noorder = :noorder";
            $stmtRadiologi = $pdo->prepare($updateRadiologi);
            $stmtRadiologi->execute([
                'tgl_sampel' => $tgl_sampel,
                'jam_sampel' => $jam_sampel,
                'noorder'    => $noorder
            ]);

            $acc = substr($noorder, 2);
            $updatePacs = "UPDATE khanza_pacs_mapping
                            SET updated_at = NOW()
                            WHERE acc = :acc OR acc LIKE :acc_like";
            $stmtPacs = $pdo->prepare($updatePacs);
            $stmtPacs->execute(['acc' => $acc, 'acc_like' => $acc . '-%']);

            logActivity($pdo, "Update tanggal sampel order {$noorder}: {$tgl_sampel} {$jam_sampel}", 'RADIOLOGI');

            $pdo->commit();

            $_SESSION['notif_sampel'] = [
                'status'  => 'success',
                'message' => '✓ Tanggal sampel berhasil disimpan'
            ];

        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['notif_sampel'] = [
                'status'  => 'error',
                'message' => '✗ Error: ' . $e->getMessage()
            ];
        }

        header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
        exit;
    }
}

$tanggal_dari   = $_GET['tanggal_dari'] ?? date('Y-m-d');
$tanggal_sampai = $_GET['tanggal_sampai'] ?? date('Y-m-d');
$dokter         = $_GET['dokter'] ?? '';
$keyword        = $_GET['keyword'] ?? '';
$active_tab     = $_GET['tab'] ?? 'ralan';

$params = ['tanggal_dari' => $tanggal_dari, 'tanggal_sampai' => $tanggal_sampai];

function getOrders($pdo, $params, $dokter, $keyword, $status) {
    $where = "WHERE DATE(pr.tgl_permintaan) BETWEEN :tanggal_dari AND :tanggal_sampai AND pr.status = :status ";
    $params['status'] = $status;

    if ($dokter) {
        $where .= "AND pr.dokter_perujuk LIKE :dokter ";
        $params['dokter'] = '%' . $dokter . '%';
    }

    if ($keyword) {
        $where .= "AND (pr.noorder LIKE :keyword OR pr.no_rawat LIKE :keyword OR pr.pasien LIKE :keyword) ";
        $params['keyword'] = '%' . $keyword . '%';
    }

    $query = "SELECT
        pr.noorder, pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan,
        MIN(pr.tgl_sampel) as tgl_sampel, MIN(pr.jam_sampel) as jam_sampel,
        MIN(pr.tgl_hasil) as tgl_hasil, MIN(pr.jam_hasil) as jam_hasil,
        pr.dokter_perujuk, pr.status, pr.informasi_tambahan, pr.diagnosa_klinis,
        pol.nm_poli as poli_registrasi, pnj.png_jawab as jenis_bayar
        FROM permintaan_radiologi pr
        LEFT JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
        LEFT JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
        LEFT JOIN penjab pnj ON rp.kd_pj = pnj.kd_pj
        $where
        GROUP BY pr.noorder, pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan,
        pr.dokter_perujuk, pr.status, pr.informasi_tambahan, pr.diagnosa_klinis,
        pol.nm_poli, pnj.png_jawab
        ORDER BY pr.tgl_permintaan DESC, pr.jam_permintaan DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt;
}

function getDetailRows($pdo, $noorder) {
    $query = "SELECT pasien FROM permintaan_radiologi WHERE noorder = :noorder ORDER BY pasien";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['noorder' => $noorder]);
    return $stmt->fetchAll();
}

function cekDataRadiologi($pdo, $noorder) {
    try {
        $queryHasil = "SELECT COUNT(*) as count FROM hasil_radiologi WHERE noorder = :noorder";
        $stmtHasil = $pdo->prepare($queryHasil);
        $stmtHasil->execute(['noorder' => $noorder]);
        $countHasil = $stmtHasil->fetch()['count'];

        $queryPeriksa = "SELECT COUNT(*) as count FROM periksa_radiologi WHERE noorder = :noorder";
        $stmtPeriksa = $pdo->prepare($queryPeriksa);
        $stmtPeriksa->execute(['noorder' => $noorder]);
        $countPeriksa = $stmtPeriksa->fetch()['count'];

        $queryPermintaan = "SELECT COUNT(*) as count FROM permintaan_pemeriksaan_radiologi WHERE noorder = :noorder";
        $stmtPermintaan = $pdo->prepare($queryPermintaan);
        $stmtPermintaan->execute(['noorder' => $noorder]);
        $countPermintaan = $stmtPermintaan->fetch()['count'];

        return [
            'hasil'      => $countHasil > 0,
            'periksa'    => $countPeriksa > 0,
            'permintaan' => $countPermintaan > 0,
            'all_exist'  => ($countHasil > 0 && $countPeriksa > 0 && $countPermintaan > 0)
        ];

    } catch (PDOException $e) {
        return [
            'error'   => true,
            'message' => $e->getMessage()
        ];
    }
}

// ============================================
// HANDLER: KIRIM KE PACS
// ============================================
if (isset($_POST['kirim_pacs'])) {
    $noorder = $_POST['noorder'] ?? '';

    if (!$noorder) {
        $_SESSION['notif_pacs'] = [
            'status'  => 'error',
            'message' => '✗ No Order tidak ditemukan'
        ];
    } else {
        $cek = cekDataRadiologi($pdo, $noorder);

        if (isset($cek['error'])) {
            $_SESSION['notif_pacs'] = [
                'status'  => 'error',
                'message' => '✗ Error cek data: ' . $cek['message']
            ];
        } elseif (!$cek['all_exist']) {
            $missing = [];
            if (!$cek['hasil'])      $missing[] = 'hasil_radiologi';
            if (!$cek['periksa'])    $missing[] = 'periksa_radiologi';
            if (!$cek['permintaan']) $missing[] = 'permintaan_pemeriksaan_radiologi';

            $_SESSION['notif_pacs'] = [
                'status'  => 'warning',
                'message' => '⚠ **DATA BELUM LENGKAP**' . "\n" .
                             'Silakan input data radiologi terlebih dahulu di tabel: ' . "\n" .
                             implode(', ', $missing)
            ];
        } else {
            $hasil = kirimKePACS($pdo, $noorder);
            $_SESSION['notif_pacs'] = $hasil;
        }
    }

    header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
    exit;
}

function kirimKePACS($pdo, $noorder) {
    try {
        $query = "SELECT ppr.noorder, ppr.kd_jenis_prw, jns.nm_perawatan,
                         pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan,
                         pas.no_rkm_medis, pas.nm_pasien, pas.jk, pas.tgl_lahir,
                         pas.alamat, pr.dokter_perujuk, pr.diagnosa_klinis
                  FROM permintaan_pemeriksaan_radiologi ppr
                  INNER JOIN jns_perawatan_radiologi jns ON ppr.kd_jenis_prw = jns.kd_jenis_prw
                  INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                  INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                  INNER JOIN pasien pas ON rp.no_rkm_medis = pas.no_rkm_medis
                  WHERE ppr.noorder = :noorder
                  ORDER BY ppr.kd_jenis_prw";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['noorder' => $noorder]);
        $items = $stmt->fetchAll();

        if (count($items) == 0) {
            return ['status' => 'error', 'message' => 'Tidak ada pemeriksaan untuk noorder ini'];
        }

        $acc_base   = substr($noorder, 2);
        $multi_item = count($items) > 1;
        $hasil = [];

        foreach ($items as $index => $item) {
            $nm_perawatan = $item['nm_perawatan'];

            if (stripos($nm_perawatan, 'USG') !== false) {
                $hasil[] = "⊘ SKIP - {$item['kd_jenis_prw']} - USG tidak dikirim ke PACS";
                continue;
            }

            if (stripos($nm_perawatan, 'CT Scan') === 0 || stripos($nm_perawatan, 'CT SCAN') === 0 || stripos($nm_perawatan, 'CT-Scan') === 0) {
                $id_modality = 1;
                $xray_type_code = 'CT';
            } else {
                $id_modality = 2;
                $xray_type_code = 'CR';
            }

            // ✅ FIX: ACC unik per item kalau order berisi >1 pemeriksaan
            $acc = $multi_item ? ($acc_base . '-' . ($index + 1)) : $acc_base;

            // Cek per-ACC (bukan per-noorder), konsisten dengan ACC yang sudah unik
            $checkAccFull = "SELECT * FROM khanza_pacs_mapping WHERE acc = :acc";
            $stmtCheckAccFull = $pdo->prepare($checkAccFull);
            $stmtCheckAccFull->execute(['acc' => $acc]);
            if ($stmtCheckAccFull->rowCount() > 0) {
                $recordAcc = $stmtCheckAccFull->fetch();
                if ($recordAcc['status'] == 'active') {
                    $hasil[] = "⚠ **SUDAH DIKIRIM** - ACC {$acc} sudah ada di PACS ({$nm_perawatan})";
                    continue;
                } else {
                    $deleteOld = "DELETE FROM khanza_pacs_mapping WHERE acc = :acc";
                    $stmtDelOld = $pdo->prepare($deleteOld);
                    $stmtDelOld->execute(['acc' => $acc]);
                }
            }

            try {
                $notes = "Order: {$item['noorder']} | Jenis: {$nm_perawatan} | Kode: {$item['kd_jenis_prw']} | Modality: {$xray_type_code} | Dokter: {$item['dokter_perujuk']}";

                $insertLocal = "INSERT INTO khanza_pacs_mapping (
                    no_rawat, no_rkm_medis, nm_pasien, acc, pacs_mrn,
                    modality, procedure_name, status, notes, created_at
                ) VALUES (
                    :no_rawat, :no_rkm_medis, :nm_pasien, :acc, :pacs_mrn,
                    :modality, :procedure_name, 'active', :notes, NOW()
                )";
                $stmtLocal = $pdo->prepare($insertLocal);
                $stmtLocal->execute([
                    'no_rawat'       => $item['no_rawat'],
                    'no_rkm_medis'   => $item['no_rkm_medis'],
                    'nm_pasien'      => $item['nm_pasien'],
                    'acc'            => $acc,
                    'pacs_mrn'       => $item['no_rkm_medis'],
                    'modality'       => $xray_type_code,
                    'procedure_name' => substr(trim($nm_perawatan), 0, 100),
                    'notes'          => $notes
                ]);

                try {
                    $patient_name     = trim(preg_replace('/[^a-zA-Z0-9\s\.\-]/', '', $item['nm_pasien']));
                    $referring_doctor = trim(preg_replace('/[^a-zA-Z0-9\s\.\-]/', '', $item['dokter_perujuk']));
                    $procedure_name   = substr(trim($nm_perawatan), 0, 100);
                    $diagnosis        = substr(trim($item['diagnosa_klinis']), 0, 200);
                    $patient_address  = substr(trim($item['alamat']), 0, 100);
                    $gender = strtoupper($item['jk']);
                    if ($gender == 'L') $gender = 'M';
                    if ($gender == 'P') $gender = 'F';
                    $birth_date = $item['tgl_lahir'] && $item['tgl_lahir'] != '0000-00-00' ? $item['tgl_lahir'] : date('Y-m-d');

                    $payload = [
                        "acc"                 => $acc,
                        "patient_id"          => trim($item['no_rkm_medis']),
                        "mrn"                 => trim($item['no_rkm_medis']),
                        "name"                => $patient_name ?: 'Unknown',
                        "sex"                 => $gender,
                        "birth_date"          => $birth_date,
                        "address"             => $patient_address ?: '-',
                        "id_modality"         => (int)$id_modality,
                        "xray_type_code"      => strtoupper($xray_type_code),
                        "order_id"            => $item['noorder'],
                        "procedure"           => $procedure_name,
                        "diagnosis"           => $diagnosis ?: '-',
                        "referring_physician" => $referring_doctor ?: 'Unknown',
                        "study_date"          => date('Y-m-d', strtotime($item['tgl_permintaan'])),
                        "study_time"          => str_replace(':', '', $item['jam_permintaan']),
                        "requested_procedure" => $procedure_name,
                        "priority"            => "ROUTINE"
                    ];

                    $apiResponse = callPacsAPI('/api/orders', $payload, 'POST');

                    if ($apiResponse['success']) {
                        $hasil[] = "✓ **SUKSES KIRIM** - ACC: **{$acc}** | {$xray_type_code} | {$item['kd_jenis_prw']} | {$nm_perawatan}";
                        logActivity($pdo, "Kirim order {$item['noorder']} ke PACS dengan ACC {$acc}", 'RADIOLOGI');
                    } else {
                        $deleteLocal = "DELETE FROM khanza_pacs_mapping WHERE acc = :acc";
                        $stmtDelete = $pdo->prepare($deleteLocal);
                        $stmtDelete->execute(['acc' => $acc]);

                        $response_msg = '';
                        if (isset($apiResponse['response']['message'])) {
                            $response_msg = $apiResponse['response']['message'];
                        } elseif (isset($apiResponse['response']['error'])) {
                            $response_msg = $apiResponse['response']['error'];
                        }
                        if ($apiResponse['error']) {
                            $hasil[] = "✗ **GAGAL KIRIM** - ACC: {$acc} | Error: {$apiResponse['error']}";
                        } else {
                            $hasil[] = "✗ **GAGAL KIRIM** - ACC: {$acc} | HTTP {$apiResponse['http_code']} {$response_msg}";
                        }
                    }
                } catch (Exception $e) {
                    $deleteLocal = "DELETE FROM khanza_pacs_mapping WHERE acc = :acc";
                    $stmtDelete = $pdo->prepare($deleteLocal);
                    $stmtDelete->execute(['acc' => $acc]);
                    $hasil[] = "✗ **EXCEPTION KIRIM** - ACC: {$acc} | {$e->getMessage()}";
                }
            } catch (Exception $e) {
                $hasil[] = "✗ **ERROR DB INSERT** - ACC: {$acc} | {$e->getMessage()}";
            }
        }

        if (count($hasil) == 0) {
            return ['status' => 'warning', 'message' => 'Semua pemeriksaan adalah USG'];
        }
        return ['status' => 'success', 'message' => implode("\n", $hasil)];

    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => 'Error Database: ' . $e->getMessage()];
    }
}

$countRalan = $pdo->prepare("SELECT COUNT(DISTINCT noorder) as total FROM permintaan_radiologi pr WHERE DATE(pr.tgl_permintaan) BETWEEN :tanggal_dari AND :tanggal_sampai AND pr.status = 'Ralan'");
$countRalan->execute(['tanggal_dari' => $tanggal_dari, 'tanggal_sampai' => $tanggal_sampai]);
$totalRalan = $countRalan->fetch()['total'];

$countRanap = $pdo->prepare("SELECT COUNT(DISTINCT noorder) as total FROM permintaan_radiologi pr WHERE DATE(pr.tgl_permintaan) BETWEEN :tanggal_dari AND :tanggal_sampai AND pr.status = 'Ranap'");
$countRanap->execute(['tanggal_dari' => $tanggal_dari, 'tanggal_sampai' => $tanggal_sampai]);
$totalRanap = $countRanap->fetch()['total'];

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= APP_NAME ?> - Data Permintaan Radiologi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-size: 11px; background-color: #f8f9fa; }
        .header-section { background: linear-gradient(to right, #dc3545, #ff6b7a); color: white; padding: 8px 15px; border-radius: 5px 5px 0 0; font-weight: bold; }
        .filter-section { background-color: white; padding: 15px; border: 1px solid #dee2e6; margin-bottom: 10px; }
        .table-wrapper { background-color: white; border: 1px solid #dee2e6; overflow-x: auto; max-height: 500px; overflow-y: auto; }
        .table { font-size: 10px; margin: 0; }
        .table th { background-color: #e9ecef; font-weight: 600; padding: 8px 5px; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
        .table td { padding: 6px 5px; }
        .btn-action { padding: 2px 8px; font-size: 10px; margin: 1px; }
        .nav-tabs { background-color: white; border-bottom: 2px solid #dee2e6; padding-left: 10px; }
        .nav-tabs .nav-link { color: #495057; font-weight: 600; border: none; border-bottom: 3px solid transparent; padding: 10px 20px; font-size: 11px; }
        .nav-tabs .nav-link.active { color: #dc3545; border-bottom: 3px solid #dc3545; }
        .sub-tabs { background-color: #f8f9fa; padding: 8px 15px; border-bottom: 1px solid #dee2e6; }
        .sub-tabs .nav-link { font-size: 10px; padding: 5px 15px; margin-right: 5px; border: 1px solid #dee2e6; border-radius: 4px; background-color: white; }
        .sub-tabs .nav-link.active { background-color: #dc3545; color: white; }
        .row-header { background-color: #fff3cd; font-weight: bold; }
        .row-detail { background-color: #f8f9fa; }
        .alert-floating { position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 700px; animation: slideIn 0.3s ease-out; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .app-info { font-size: 10px; color: #6c757d; position: fixed; bottom: 10px; right: 10px; }
    </style>
</head>
<body>

<?php
if (isset($_SESSION['notif_pacs'])) {
    $notif = $_SESSION['notif_pacs'];
    $alert_type = $notif['status'] == 'success' ? 'success' : ($notif['status'] == 'warning' ? 'warning' : 'danger');
    ?>
    <div class="alert alert-<?= $alert_type ?> alert-dismissible alert-floating" role="alert">
        <strong><?= $notif['status'] == 'success' ? '✓ SUKSES' : ($notif['status'] == 'warning' ? '⚠ INFO' : '✗ GAGAL') ?></strong><br>
        <div style="font-size: 11px; margin-top: 8px;"><?= $notif['message'] ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php
    unset($_SESSION['notif_pacs']);
}

if (isset($_SESSION['notif_sampel'])) {
    $notif = $_SESSION['notif_sampel'];
    $alert_type = $notif['status'] == 'success' ? 'success' : 'danger';
    ?>
    <div class="alert alert-<?= $alert_type ?> alert-dismissible alert-floating" style="top: 80px;" role="alert">
        <strong><?= $notif['status'] == 'success' ? '✓ SUKSES' : '✗ GAGAL' ?></strong><br>
        <div style="font-size: 11px; margin-top: 8px;"><?= $notif['message'] ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php
    unset($_SESSION['notif_sampel']);
}
?>

<div class="container-fluid mt-3">
    <div class="header-section">:: <?= APP_NAME ?> - Data Permintaan Radiologi ::</div>
    
    <div class="filter-section">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= $active_tab ?>">
            <div class="col-auto">
                <label class="form-label mb-1"><strong>Tanggal:</strong></label>
                <input type="date" class="form-control form-control-sm" name="tanggal_dari" value="<?= $tanggal_dari ?>" style="width:140px;">
            </div>
            <div class="col-auto d-flex align-items-end pb-1"><span>s.d</span></div>
            <div class="col-auto">
                <label class="form-label mb-1">&nbsp;</label>
                <input type="date" class="form-control form-control-sm" name="tanggal_sampai" value="<?= $tanggal_sampai ?>" style="width:140px;">
            </div>
            <div class="col-auto">
                <label class="form-label mb-1"><strong>Dokter:</strong></label>
                <input type="text" class="form-control form-control-sm" name="dokter" value="<?= htmlspecialchars($dokter) ?>" placeholder="Nama dokter..." style="width:200px;">
            </div>
            <div class="col-auto">
                <label class="form-label mb-1"><strong>Key Word:</strong></label>
                <div class="input-group input-group-sm" style="width:250px;">
                    <input type="text" class="form-control" name="keyword" value="<?= htmlspecialchars($keyword) ?>" placeholder="No order, pasien...">
                    <button type="submit" class="btn btn-success">✔</button>
                </div>
            </div>
        </form>
    </div>

    <ul class="nav nav-tabs">
        <li class="nav-item">
            <button class="nav-link <?= $active_tab == 'ralan' ? 'active' : '' ?>" id="ralan-tab" data-bs-toggle="tab" data-bs-target="#ralan" type="button">
                Rawat Jalan <span class="badge bg-danger"><?= $totalRalan ?></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $active_tab == 'ranap' ? 'active' : '' ?>" id="ranap-tab" data-bs-toggle="tab" data-bs-target="#ranap" type="button">
                Rawat Inap <span class="badge bg-danger"><?= $totalRanap ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- RAWAT JALAN -->
        <div class="tab-pane fade <?= $active_tab == 'ralan' ? 'show active' : '' ?>" id="ralan">
            <div class="sub-tabs">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ralan-data">Data Permintaan</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ralan-item">Item Permintaan</button>
                    </li>
                </ul>
            </div>
            
            <div class="tab-content">
                <div class="tab-pane fade show active" id="ralan-data">
                    <div class="table-wrapper">
                        <table class="table table-bordered table-hover table-sm">
                            <thead>
                                <tr>
                                    <th style="width:100px;">No. Permintaan</th>
                                    <th style="width:120px;">No. Rawat</th>
                                    <th style="width:200px;">Pasien</th>
                                    <th style="width:100px;">Permintaan</th>
                                    <th style="width:80px;">Jam</th>
                                    <th style="width:100px;">Sampel</th>
                                    <th style="width:80px;">Jam</th>
                                    <th style="width:100px;">Hasil</th>
                                    <th style="width:80px;">Jam</th>
                                    <th style="width:150px;">Dokter</th>
                                    <th style="width:150px;">Poli</th>
                                    <th style="width:200px;">Info</th>
                                    <th style="width:200px;">Diagnosis</th>
                                    <th style="width:120px;">Jenis Bayar</th>
                                    <th style="width:100px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = getOrders($pdo, $params, $dokter, $keyword, 'Ralan');
                                if ($stmt->rowCount() > 0) {
                                    while ($row = $stmt->fetch()) {
                                        $tgl_sampel = ($row['tgl_sampel'] && $row['tgl_sampel'] != '0000-00-00') ? date('d-m-Y', strtotime($row['tgl_sampel'])) : '';
                                        $jam_sampel = ($row['jam_sampel'] && $row['jam_sampel'] != '00:00:00') ? $row['jam_sampel'] : '';
                                        $tgl_hasil = ($row['tgl_hasil'] && $row['tgl_hasil'] != '0000-00-00') ? date('d-m-Y', strtotime($row['tgl_hasil'])) : '';
                                        $jam_hasil = ($row['jam_hasil'] && $row['jam_hasil'] != '00:00:00') ? $row['jam_hasil'] : '';
                                        
                                        $detailRows = getDetailRows($pdo, $row['noorder']);
                                        $firstRow = true;
                                        foreach ($detailRows as $detail) {
                                            if ($firstRow) {
                                                $acc_display = substr($row['noorder'], 2);
                                                ?>
                                                <tr class="row-header">
                                                    <td><?= $row['noorder'] ?></td>
                                                    <td><?= $row['no_rawat'] ?></td>
                                                    <td><?= htmlspecialchars($detail['pasien']) ?></td>
                                                    <td><?= date('d-m-Y', strtotime($row['tgl_permintaan'])) ?></td>
                                                    <td><?= $row['jam_permintaan'] ?></td>
                                                    <td>
                                                        <?php if ($tgl_sampel): ?>
                                                            <?= $tgl_sampel ?>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-sm btn-warning btn-action" onclick="openSampelModal('<?= $row['noorder'] ?>')">Sampel</button>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= $jam_sampel ?></td>
                                                    <td><?= $tgl_hasil ?></td>
                                                    <td><?= $jam_hasil ?></td>
                                                    <td><?= $row['dokter_perujuk'] ?></td>
                                                    <td><?= $row['poli_registrasi'] ?? '-' ?></td>
                                                    <td><?= htmlspecialchars($row['informasi_tambahan']) ?></td>
                                                    <td><?= htmlspecialchars($row['diagnosa_klinis']) ?></td>
                                                    <td><?= $row['jenis_bayar'] ?? '-' ?></td>
                                                    <td>
                                                        <form method="POST" style="display:inline;">
                                                            <input type="hidden" name="noorder" value="<?= $row['noorder'] ?>">
                                                            <button type="submit" name="kirim_pacs" class="btn btn-sm btn-primary btn-action" onclick="return confirm('Cek data radiologi dan kirim ke PACS?\n\nNoOrder: <?= $row['noorder'] ?>\nACC: <?= $acc_display ?>')">📡 PACS</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php
                                                $firstRow = false;
                                            } else {
                                                echo '<tr class="row-detail"><td colspan="2"></td><td>'.htmlspecialchars($detail['pasien']).'</td><td colspan="12"></td></tr>';
                                            }
                                        }
                                    }
                                } else {
                                    echo '<tr><td colspan="15" class="text-center">Tidak ada data</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="tab-pane fade" id="ralan-item">
                    <div class="table-wrapper">
                        <table class="table table-bordered table-hover table-sm">
                            <thead>
                                <tr><th>No. Permintaan</th><th>Kode</th><th>Jenis</th><th>Biaya</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $itemQuery = "SELECT ppr.noorder, ppr.kd_jenis_prw, jns.nm_perawatan, jns.total_byr, pr.status
                                    FROM permintaan_pemeriksaan_radiologi ppr
                                    INNER JOIN jns_perawatan_radiologi jns ON ppr.kd_jenis_prw = jns.kd_jenis_prw
                                    INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                                    WHERE pr.status = 'Ralan' AND DATE(pr.tgl_permintaan) BETWEEN :tanggal_dari AND :tanggal_sampai
                                    ORDER BY ppr.noorder DESC";
                                $stmtItem = $pdo->prepare($itemQuery);
                                $stmtItem->execute(['tanggal_dari' => $tanggal_dari, 'tanggal_sampai' => $tanggal_sampai]);
                                
                                while ($item = $stmtItem->fetch()) {
                                    ?>
                                    <tr>
                                        <td><?= $item['noorder'] ?></td>
                                        <td><?= $item['kd_jenis_prw'] ?></td>
                                        <td><?= $item['nm_perawatan'] ?></td>
                                        <td class="text-end"><?= number_format($item['total_byr'], 0, ',', '.') ?></td>
                                        <td><?= strtoupper($item['status']) ?></td>
                                    </tr>
                                    <?php
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- RAWAT INAP -->
        <div class="tab-pane fade <?= $active_tab == 'ranap' ? 'show active' : '' ?>" id="ranap">
            <div class="sub-tabs">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ranap-data">Data Permintaan</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ranap-item">Item Permintaan</button>
                    </li>
                </ul>
            </div>
            
            <div class="tab-content">
                <div class="tab-pane fade show active" id="ranap-data">
                    <div class="table-wrapper">
                        <table class="table table-bordered table-hover table-sm">
                            <thead>
                                <tr>
                                    <th style="width:100px;">No. Permintaan</th>
                                    <th style="width:120px;">No. Rawat</th>
                                    <th style="width:200px;">Pasien</th>
                                    <th style="width:100px;">Permintaan</th>
                                    <th style="width:80px;">Jam</th>
                                    <th style="width:100px;">Sampel</th>
                                    <th style="width:80px;">Jam</th>
                                    <th style="width:100px;">Hasil</th>
                                    <th style="width:80px;">Jam</th>
                                    <th style="width:150px;">Dokter</th>
                                    <th style="width:150px;">Poli</th>
                                    <th style="width:200px;">Info</th>
                                    <th style="width:200px;">Diagnosis</th>
                                    <th style="width:120px;">Jenis Bayar</th>
                                    <th style="width:100px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = getOrders($pdo, $params, $dokter, $keyword, 'Ranap');
                                if ($stmt->rowCount() > 0) {
                                    while ($row = $stmt->fetch()) {
                                        $tgl_sampel = ($row['tgl_sampel'] && $row['tgl_sampel'] != '0000-00-00') ? date('d-m-Y', strtotime($row['tgl_sampel'])) : '';
                                        $jam_sampel = ($row['jam_sampel'] && $row['jam_sampel'] != '00:00:00') ? $row['jam_sampel'] : '';
                                        $tgl_hasil = ($row['tgl_hasil'] && $row['tgl_hasil'] != '0000-00-00') ? date('d-m-Y', strtotime($row['tgl_hasil'])) : '';
                                        $jam_hasil = ($row['jam_hasil'] && $row['jam_hasil'] != '00:00:00') ? $row['jam_hasil'] : '';
                                        
                                        $detailRows = getDetailRows($pdo, $row['noorder']);
                                        $firstRow = true;
                                        foreach ($detailRows as $detail) {
                                            if ($firstRow) {
                                                $acc_display = substr($row['noorder'], 2);
                                                ?>
                                                <tr class="row-header">
                                                    <td><?= $row['noorder'] ?></td>
                                                    <td><?= $row['no_rawat'] ?></td>
                                                    <td><?= htmlspecialchars($detail['pasien']) ?></td>
                                                    <td><?= date('d-m-Y', strtotime($row['tgl_permintaan'])) ?></td>
                                                    <td><?= $row['jam_permintaan'] ?></td>
                                                    <td>
                                                        <?php if ($tgl_sampel): ?>
                                                            <?= $tgl_sampel ?>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-sm btn-warning btn-action" onclick="openSampelModal('<?= $row['noorder'] ?>')">Sampel</button>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= $jam_sampel ?></td>
                                                    <td><?= $tgl_hasil ?></td>
                                                    <td><?= $jam_hasil ?></td>
                                                    <td><?= $row['dokter_perujuk'] ?></td>
                                                    <td><?= $row['poli_registrasi'] ?? '-' ?></td>
                                                    <td><?= htmlspecialchars($row['informasi_tambahan']) ?></td>
                                                    <td><?= htmlspecialchars($row['diagnosa_klinis']) ?></td>
                                                    <td><?= $row['jenis_bayar'] ?? '-' ?></td>
                                                    <td>
                                                        <form method="POST" style="display:inline;">
                                                            <input type="hidden" name="noorder" value="<?= $row['noorder'] ?>">
                                                            <button type="submit" name="kirim_pacs" class="btn btn-sm btn-primary btn-action" onclick="return confirm('Cek data radiologi dan kirim ke PACS?\n\nNoOrder: <?= $row['noorder'] ?>\nACC: <?= $acc_display ?>')">📡 PACS</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php
                                                $firstRow = false;
                                            } else {
                                                echo '<tr class="row-detail"><td colspan="2"></td><td>'.htmlspecialchars($detail['pasien']).'</td><td colspan="12"></td></tr>';
                                            }
                                        }
                                    }
                                } else {
                                    echo '<tr><td colspan="15" class="text-center">Tidak ada data</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="tab-pane fade" id="ranap-item">
                    <div class="table-wrapper">
                        <table class="table table-bordered table-hover table-sm">
                            <thead>
                                <tr><th>No. Permintaan</th><th>Kode</th><th>Jenis</th><th>Biaya</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $itemQuery = "SELECT ppr.noorder, ppr.kd_jenis_prw, jns.nm_perawatan, jns.total_byr, pr.status
                                    FROM permintaan_pemeriksaan_radiologi ppr
                                    INNER JOIN jns_perawatan_radiologi jns ON ppr.kd_jenis_prw = jns.kd_jenis_prw
                                    INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                                    WHERE pr.status = 'Ranap' AND DATE(pr.tgl_permintaan) BETWEEN :tanggal_dari AND :tanggal_sampai
                                    ORDER BY ppr.noorder DESC";
                                $stmtItem = $pdo->prepare($itemQuery);
                                $stmtItem->execute(['tanggal_dari' => $tanggal_dari, 'tanggal_sampai' => $tanggal_sampai]);
                                
                                while ($item = $stmtItem->fetch()) {
                                    ?>
                                    <tr>
                                        <td><?= $item['noorder'] ?></td>
                                        <td><?= $item['kd_jenis_prw'] ?></td>
                                        <td><?= $item['nm_perawatan'] ?></td>
                                        <td class="text-end"><?= number_format($item['total_byr'], 0, ',', '.') ?></td>
                                        <td><?= strtoupper($item['status']) ?></td>
                                    </tr>
                                    <?php
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Input Sampel -->
<div class="modal fade" id="modalSampel" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Input Tanggal & Jam Sampel</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formSampel">
                <div class="modal-body">
                    <input type="hidden" name="noorder" id="sampel_noorder">
                    <div class="mb-3">
                        <label class="form-label">Tanggal Sampel</label>
                        <input type="date" name="tgl_sampel" id="tgl_sampel" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Jam Sampel</label>
                        <input type="time" name="jam_sampel" id="jam_sampel" class="form-control" step="1" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="simpan_sampel" class="btn btn-success">💾 Simpan</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="app-info">
    <small><?= APP_NAME ?> v<?= APP_VERSION ?> | © <?= date('Y') ?></small>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openSampelModal(noorder) {
    document.getElementById('sampel_noorder').value = noorder;
    
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    
    document.getElementById('tgl_sampel').value = `${year}-${month}-${day}`;
    document.getElementById('jam_sampel').value = `${hours}:${minutes}:${seconds}`;
    
    const modal = new bootstrap.Modal(document.getElementById('modalSampel'));
    modal.show();
}

setTimeout(() => {
    let alerts = document.querySelectorAll('.alert-floating');
    alerts.forEach(alert => {
        setTimeout(() => new bootstrap.Alert(alert).close(), 12000);
    });
}, 100);

document.querySelectorAll('#mainTabs button, #ralan-tab, #ranap-tab').forEach(button => {
    button.addEventListener('click', function() {
        const tab = this.id.replace('-tab', '');
        if (document.querySelector('input[name="tab"]')) {
            document.querySelector('input[name="tab"]').value = tab;
        }
    });
});
</script>
</body>
</html>
