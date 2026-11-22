<?php
session_start();
require_once __DIR__ . '/auth_check.php';
require_once 'config.php';
require_role(['radiologi', 'superadmin']);
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$noorder = $_GET['noorder'] ?? '';
$error = '';
$success = '';

// ☑️ Default Dokter PJ
// Nilai diambil dari konstanta di config.local.php (sebelumnya berupa teks literal
// 'DEFAULT_KD_DOKTER_PJ' yang ikut tersimpan ke database).
$default_kd_dokter_pj = defined('DEFAULT_KD_DOKTER_PJ') ? DEFAULT_KD_DOKTER_PJ : '';
$default_nm_dokter_pj = defined('DEFAULT_NM_DOKTER_PJ') ? DEFAULT_NM_DOKTER_PJ : '';


// ☑️ Default Petugas
$default_nip_petugas = defined('DEFAULT_NIP_PETUGAS') ? DEFAULT_NIP_PETUGAS : '';
$default_nama_petugas = defined('DEFAULT_NAMA_PETUGAS') ? DEFAULT_NAMA_PETUGAS : '';
// ============================================
// 1. AJAX HANDLERS - SEARCH & FETCH DATA
// ============================================

// AJAX: Search Dokter
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_dokter'])){
    header('Content-Type: application/json');
    $q = $_POST['q'] ?? '';
    $stmt = $pdo->prepare("SELECT kd_dokter, nm_dokter FROM dokter WHERE (kd_dokter LIKE ? OR nm_dokter LIKE ?) AND status='1' ORDER BY nm_dokter LIMIT 50");
    $stmt->execute(["%$q%", "%$q%"]);
    die(json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// AJAX: Search Petugas
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_petugas'])){
    header('Content-Type: application/json');
    $q = $_POST['q'] ?? '';
    $stmt = $pdo->prepare("SELECT nip, nama FROM petugas WHERE nip LIKE ? OR nama LIKE ? LIMIT 50");
    $stmt->execute(["%$q%", "%$q%"]);
    die(json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// AJAX: Ambil template hasil
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_template'])){
    header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT no_template, nama_pemeriksaan, template_hasil_radiologi
                         FROM template_hasil_radiologi
                         ORDER BY no_template");
    die(json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// AJAX: Search Paket Pemeriksaan Radiologi
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_search_paket'])){
    header('Content-Type: application/json');
    $q = $_POST['q'] ?? '';
    $stmt = $pdo->prepare("SELECT kd_jenis_prw, nm_perawatan, total_byr
        FROM jns_perawatan_radiologi
        WHERE (kd_jenis_prw LIKE ? OR nm_perawatan LIKE ?) AND status='1'
        ORDER BY nm_perawatan LIMIT 50");
    $stmt->execute(["%$q%", "%$q%"]);
    die(json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// AJAX: Tambah pemeriksaan ke database (REAL-TIME)
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_tambah_pemeriksaan'])){
    header('Content-Type: application/json');
    try{
        $noorder_post = $_POST['noorder'] ?? '';
        $kd_jenis_prw = $_POST['kd_jenis_prw'] ?? '';

        if(!$noorder_post || !$kd_jenis_prw){
            throw new Exception('Parameter tidak lengkap');
        }

        // CEK: Apakah pemeriksaan sudah ada?
        $stmt = $pdo->prepare("SELECT * FROM permintaan_pemeriksaan_radiologi
            WHERE noorder=? AND kd_jenis_prw=?");
        $stmt->execute([$noorder_post, $kd_jenis_prw]);

        if($stmt->rowCount() > 0){
            throw new Exception('Pemeriksaan ini sudah ada di order!');
        }

        // Ambil data paket
        $stmt = $pdo->prepare("SELECT * FROM jns_perawatan_radiologi WHERE kd_jenis_prw=?");
        $stmt->execute([$kd_jenis_prw]);
        $paket = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!$paket){
            throw new Exception('Paket tidak ditemukan di database');
        }

        // INSERT ke permintaan_pemeriksaan_radiologi
        $stmt = $pdo->prepare("INSERT INTO permintaan_pemeriksaan_radiologi
            (noorder, kd_jenis_prw)
            VALUES (?, ?)");
        $stmt->execute([$noorder_post, $kd_jenis_prw]);

        die(json_encode([
            'status' => 'success',
            'message' => 'Pemeriksaan ' . $paket['nm_perawatan'] . ' berhasil ditambahkan!',
            'data' => $paket
        ]));

    } catch(Exception $e){
        die(json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]));
    }
}

// AJAX: Hapus pemeriksaan dari database (REAL-TIME)
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_hapus_pemeriksaan'])){
    header('Content-Type: application/json');
    try{
        $noorder_post = $_POST['noorder'] ?? '';
        $kd_jenis_prw = $_POST['kd_jenis_prw'] ?? '';

        if(!$noorder_post || !$kd_jenis_prw){
            throw new Exception('Parameter tidak lengkap');
        }

        // DELETE dari permintaan_pemeriksaan_radiologi
        $stmt = $pdo->prepare("DELETE FROM permintaan_pemeriksaan_radiologi
            WHERE noorder=? AND kd_jenis_prw=?");
        $stmt->execute([$noorder_post, $kd_jenis_prw]);

        die(json_encode([
            'status' => 'success',
            'message' => 'Pemeriksaan berhasil dihapus!'
        ]));

    } catch(Exception $e){
        die(json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]));
    }
}

// ============================================
// 2. SAVE & PROCESS HANDLERS
// ============================================

// AJAX: Simpan data hasil radiologi
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_save'])){
    header('Content-Type: application/json');
    try{
        $pdo->beginTransaction();

        $noorder_post = $_POST['noorder'] ?? '';
        $no_rawat = $_POST['no_rawat'] ?? '';
        $status_periksa_post = $_POST['status_periksa'] ?? 'Ralan';

        if(!$noorder_post || !$no_rawat){
            throw new Exception('Parameter tidak lengkap (noorder/no_rawat)');
        }

        // Gabung tanggal dari dropdown
        $tgl_periksa = ($_POST['tgl_tahun'] ?? date('Y')) . '-' .
            str_pad($_POST['tgl_bulan'] ?? date('m'), 2, '0', STR_PAD_LEFT) . '-' .
            str_pad($_POST['tgl_tanggal'] ?? date('d'), 2, '0', STR_PAD_LEFT);

        // Gabung jam dari dropdown
        $jam_periksa = str_pad($_POST['jam_jam'] ?? date('H'), 2, '0', STR_PAD_LEFT) . ':' .
            str_pad($_POST['jam_menit'] ?? date('i'), 2, '0', STR_PAD_LEFT) . ':' .
            str_pad($_POST['jam_detik'] ?? date('s'), 2, '0', STR_PAD_LEFT);

        $kd_dokter_pj = $_POST['kd_dokter_pj'] ?? '';
        $nip_petugas = $_POST['nip_petugas'] ?? '';
        $kd_dokter_perujuk = $_POST['kd_dokter_perujuk'] ?? '';
        $hasil_pemeriksaan = substr($_POST['hasil_pemeriksaan'] ?? '', 0, 1000);

        if(!$kd_dokter_pj || !$nip_petugas){
            throw new Exception('Dokter P.J dan Petugas harus diisi');
        }

        // Update permintaan_radiologi
        $stmt = $pdo->prepare("UPDATE permintaan_radiologi SET tgl_hasil=?, jam_hasil=?, dokter_perujuk=? WHERE noorder=?");
        $stmt->execute([$tgl_periksa, $jam_periksa, $kd_dokter_perujuk, $noorder_post]);

        // Ambil data pemeriksaan dari form
        $pemeriksaan = json_decode($_POST['pemeriksaan_data'] ?? '[]', true);
        if(!is_array($pemeriksaan) || count($pemeriksaan) === 0){
            throw new Exception('Belum ada pemeriksaan untuk disimpan');
        }

        // SIMPAN DATA PEMERIKSAAN ke periksa_radiologi dengan STATUS
        foreach($pemeriksaan as $px){
            $kd_jenis_prw = $px['kd_jenis_prw'] ?? '';
            if(!$kd_jenis_prw) continue;

            // Ambil data tarif dari jns_perawatan_radiologi
            $stmtTarif = $pdo->prepare("SELECT total_byr, bagian_rs, bhp, tarif_perujuk, tarif_tindakan_dokter, tarif_tindakan_petugas, kso, menejemen
                FROM jns_perawatan_radiologi WHERE kd_jenis_prw=?");
            $stmtTarif->execute([$kd_jenis_prw]);
            $tarif = $stmtTarif->fetch(PDO::FETCH_ASSOC);

            $bagian_rs = $tarif['bagian_rs'] ?? 0;
            $bhp = $tarif['bhp'] ?? 0;
            $tarif_perujuk = $tarif['tarif_perujuk'] ?? 0;
            $tarif_tindakan_dokter = $tarif['tarif_tindakan_dokter'] ?? 0;
            $tarif_tindakan_petugas = $tarif['tarif_tindakan_petugas'] ?? 0;
            $kso = $tarif['kso'] ?? 0;
            $menejemen = $tarif['menejemen'] ?? 0;
            $biaya = $tarif['total_byr'] ?? 0;

            // CEK: Apakah sudah ada record?
            $stmt = $pdo->prepare("SELECT * FROM periksa_radiologi
                WHERE no_rawat=? AND kd_jenis_prw=? AND tgl_periksa=? AND jam=?");
            $stmt->execute([$no_rawat, $kd_jenis_prw, $tgl_periksa, $jam_periksa]);

            if($stmt->rowCount() > 0){
                // UPDATE existing record dengan STATUS
                $upd = $pdo->prepare("UPDATE periksa_radiologi SET
                    nip=?, dokter_perujuk=?, kd_dokter=?,
                    proyeksi=?, kV=?, mAS=?, FFD=?, BSF=?, inak=?, jml_penyinaran=?, dosis=?,
                    status=?
                    WHERE no_rawat=? AND kd_jenis_prw=? AND tgl_periksa=? AND jam=?");
                $upd->execute([
                    $nip_petugas, $kd_dokter_perujuk, $kd_dokter_pj,
                    $px['proyeksi'] ?? '', $px['kV'] ?? '', $px['mAs'] ?? '', $px['FFD'] ?? '',
                    $px['BSF'] ?? '', $px['inak'] ?? '', $px['jml_penyinaran'] ?? '', $px['dosis_radiasi'] ?? '',
                    $status_periksa_post,
                    $no_rawat, $kd_jenis_prw, $tgl_periksa, $jam_periksa
                ]);
            } else {
                // INSERT new record dengan STATUS
                $ins = $pdo->prepare("INSERT INTO periksa_radiologi
                    (no_rawat, nip, kd_jenis_prw, tgl_periksa, jam, dokter_perujuk,
                     bagian_rs, bhp, tarif_perujuk, tarif_tindakan_dokter, tarif_tindakan_petugas,
                     kso, menejemen, biaya, kd_dokter, status,
                     proyeksi, kV, mAS, FFD, BSF, inak, jml_penyinaran, dosis)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([
                    $no_rawat, $nip_petugas, $kd_jenis_prw, $tgl_periksa, $jam_periksa, $kd_dokter_perujuk,
                    $bagian_rs, $bhp, $tarif_perujuk, $tarif_tindakan_dokter, $tarif_tindakan_petugas,
                    $kso, $menejemen, $biaya, $kd_dokter_pj, $status_periksa_post,
                    $px['proyeksi'] ?? '', $px['kV'] ?? '', $px['mAs'] ?? '', $px['FFD'] ?? '',
                    $px['BSF'] ?? '', $px['inak'] ?? '', $px['jml_penyinaran'] ?? '', $px['dosis_radiasi'] ?? ''
                ]);
            }
        }

        // SIMPAN HASIL PEMERIKSAAN ke hasil_radiologi
        if(!empty($hasil_pemeriksaan)){
            $stmtCekHasil = $pdo->prepare("SELECT * FROM hasil_radiologi WHERE no_rawat=?");
            $stmtCekHasil->execute([$no_rawat]);

            if($stmtCekHasil->rowCount() > 0){
                $updHasil = $pdo->prepare("UPDATE hasil_radiologi SET tgl_periksa=?, jam=?, hasil=? WHERE no_rawat=?");
                $updHasil->execute([$tgl_periksa, $jam_periksa, $hasil_pemeriksaan, $no_rawat]);
            } else {
                $insHasil = $pdo->prepare("INSERT INTO hasil_radiologi (no_rawat, tgl_periksa, jam, hasil) VALUES (?,?,?,?)");
                $insHasil->execute([$no_rawat, $tgl_periksa, $jam_periksa, $hasil_pemeriksaan]);
            }
        }

        $pdo->commit();
        
        $status_label = $status_periksa_post == 'Ralan' ? 'Rawat Jalan' : 'Rawat Inap';
        die(json_encode([
            'status'=>'success',
            'message'=>"☑️ Data berhasil disimpan!\nPemeriksaan: ".count($pemeriksaan)." item\n✖️Status: {$status_label}\nHasil: Tersimpan"
        ]));
    } catch(Exception $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        die(json_encode(['status'=>'error', 'message'=>'✖️'.$e->getMessage()]));
    }
}

// ============================================
// 3. PACS INTEGRATION HANDLERS
// ============================================

// AJAX Kirim ke PACS
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_kirim_pacs'])){
    header('Content-Type: application/json');
    try {
        $pdo->beginTransaction();

        $STATUS_PENDING = '0';
        $STATUS_ACTIVE  = '1';
        $ACTIVE_SET = ['1','active','A','a'];

        $noorder_post = $_POST['noorder'] ?? '';
        if(!$noorder_post){
            throw new Exception('✖️ noorder kosong');
        }

        $query = "SELECT ppr.noorder, ppr.kd_jenis_prw, jns.nm_perawatan,
                         pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan, pr.status,
                         pas.no_rkm_medis, pas.nm_pasien, pas.jk, pas.tgl_lahir, pas.alamat,
                         pr.dokter_perujuk, pr.diagnosa_klinis,
                         dok.kd_dokter, dok.nm_dokter,
                         pol.nm_poli,
                         rp.kd_poli
                  FROM permintaan_pemeriksaan_radiologi ppr
                  INNER JOIN jns_perawatan_radiologi jns ON ppr.kd_jenis_prw = jns.kd_jenis_prw
                  INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                  INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                  INNER JOIN pasien pas ON rp.no_rkm_medis = pas.no_rkm_medis
                  LEFT JOIN dokter dok ON pr.dokter_perujuk = dok.kd_dokter
                  LEFT JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
                  WHERE ppr.noorder = :noorder
                  ORDER BY ppr.kd_jenis_prw";

        $stmt = $pdo->prepare($query);
        $stmt->execute(['noorder' => $noorder_post]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($items) == 0) {
            throw new Exception('✖️ Tidak ada pemeriksaan untuk noorder ini');
        }

        $acc_base = substr($noorder_post, 4);
        if (!is_numeric($acc_base)) {
            throw new Exception('✖️ ACC harus berupa angka');
        }

        $pemeriksaanValid = [];
        foreach ($items as $pemeriksaan) {
            $nm_perawatan = $pemeriksaan['nm_perawatan'] ?? '';
            if (stripos($nm_perawatan, 'USG') !== false) continue;
            $pemeriksaanValid[] = $pemeriksaan;
        }

        if (count($pemeriksaanValid) == 0) {
            throw new Exception('Semua pemeriksaan adalah USG, tidak perlu dikirim ke PACS');
        }

        $item = $items[0];

        $clean_patient_id = preg_replace('/[^0-9]/', '', $item['no_rkm_medis'] ?? '');
        $clean_patient_id = str_pad($clean_patient_id, 6, '0', STR_PAD_LEFT);

        $patient_name = trim($item['nm_pasien'] ?? '');
        $gender = strtoupper($item['jk'] ?? '') == 'L' ? 'M' : 'F';
        $birth_date = (!empty($item['tgl_lahir']) && $item['tgl_lahir'] != '0000-00-00')
            ? $item['tgl_lahir']
            : date('Y-m-d');

        $jam_permintaan = $item['jam_permintaan'] ?? '';
        if (empty($jam_permintaan) || $jam_permintaan == '00:00:00') {
            $jam_permintaan = date('H:i:s');
        }
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $jam_permintaan)) {
            if (strlen($jam_permintaan) == 6 && is_numeric($jam_permintaan)) {
                $jam_permintaan = substr($jam_permintaan, 0, 2) . ':' .
                                  substr($jam_permintaan, 2, 2) . ':' .
                                  substr($jam_permintaan, 4, 2);
            } else {
                $jam_permintaan = date('H:i:s');
            }
        }

        $create_time = date('Y-m-d H:i:s');
        $schedule_date = date('Y-m-d', strtotime($item['tgl_permintaan'] ?? date('Y-m-d')));

        $hasil = [];
        $sukses = 0;
        $gagal = 0;

        foreach ($pemeriksaanValid as $index => $px) {

            $acc_with_suffix = $acc_base . $index;

            $checkAccFull = "SELECT * FROM khanza_pacs_mapping WHERE pacs_acc = :acc";
            $stmtCheckAccFull = $pdo->prepare($checkAccFull);
            $stmtCheckAccFull->execute(['acc' => $acc_with_suffix]);

            if ($stmtCheckAccFull->rowCount() > 0) {
                $recordAcc = $stmtCheckAccFull->fetch(PDO::FETCH_ASSOC);
                $statusVal = (string)($recordAcc['status'] ?? '');
                $isActive = in_array($statusVal, $ACTIVE_SET, true);

                if ($isActive) {
                    $hasil[] = "ACC {$acc_with_suffix} ({$px['nm_perawatan']}) sudah ada di PACS - SKIP";
                    continue;
                } else {
                    $deleteOld = "DELETE FROM khanza_pacs_mapping WHERE pacs_acc = :acc";
                    $stmtDelOld = $pdo->prepare($deleteOld);
                    $stmtDelOld->execute(['acc' => $acc_with_suffix]);
                }
            }

            $id_modality = 2;
            $xray_type_code = 'CR';
            if (stripos($px['nm_perawatan'] ?? '', 'CT') === 0) {
                $id_modality = 1;
                $xray_type_code = 'CT';
            }

            $mapping_id = null;
            try {
                $notes = "Order: {$item['noorder']} | Index: {$index} | Pemeriksaan: {$px['nm_perawatan']} | Dokter: {$item['nm_dokter']} | Modality: {$xray_type_code}";

                $insertLocal = "INSERT INTO khanza_pacs_mapping (
                    no_rawat,
                    no_rkm_medis,
                    nm_pasien,
                    patientid,
                    pacs_acc,
                    pacs_mrn,
                    status,
                    notes,
                    created_at
                ) VALUES (
                    :no_rawat,
                    :no_rkm_medis,
                    :nm_pasien,
                    :patientid,
                    :pacs_acc,
                    :pacs_mrn,
                    :status,
                    :notes,
                    NOW()
                )";

                $stmtLocal = $pdo->prepare($insertLocal);
                $stmtLocal->execute([
                    'no_rawat'    => $item['no_rawat'],
                    'no_rkm_medis'=> $item['no_rkm_medis'],
                    'nm_pasien'   => $item['nm_pasien'],
                    'patientid'   => $clean_patient_id,
                    'pacs_acc'    => $acc_with_suffix,
                    'pacs_mrn'    => $clean_patient_id,
                    'status'      => $STATUS_PENDING,
                    'notes'       => $notes
                ]);

                $mapping_id = $pdo->lastInsertId();

                $payload = [
                    "acc" => $acc_with_suffix,
                    "patientid" => "-",
                    "mrn" => $clean_patient_id,
                    "name_dep" => $item['nm_poli'] ?: 'RADIOLOGI',
                    "prosedur" => $px['nm_perawatan'],
                    "dokterid" => $item['kd_dokter'] ?: '-',
                    "named" => $item['nm_dokter'] ?: 'Unknown',
                    "create_time" => $create_time,
                    "schedule_date" => $schedule_date,
                    "schedule_time" => $jam_permintaan,
                    "fromorder" => $item['status'] ?: 'Ralan',
                    "name" => $patient_name,
                    "sex" => $gender,
                    "birth_date" => $birth_date,
                    "address" => substr($item['alamat'] ?? '', 0, 100),
                    "id_modality" => $id_modality,
                    "xray_type_code" => $xray_type_code,
                    "order_id" => ($item['noorder'] ?? $noorder_post) . '-' . $index,
                    "diagnosis" => substr($item['diagnosa_klinis'] ?? '-', 0, 200),
                    "priority" => "ROUTINE"
                ];

                if (!function_exists('callPacsAPI')) {
                    throw new Exception('Fungsi callPacsAPI tidak ditemukan');
                }

                $apiResponse = callPacsAPI('/api/orders', $payload, 'POST');

                if (!empty($apiResponse['success'])) {
                    $updateStatus = "UPDATE khanza_pacs_mapping
                                     SET status = :status,
                                         notes = CONCAT(IFNULL(notes,''), ' | Sent: ', NOW()),
                                         updated_at = NOW()
                                     WHERE id = :id";
                    $stmtUpdate = $pdo->prepare($updateStatus);
                    $stmtUpdate->execute([
                        'status' => $STATUS_ACTIVE,
                        'id' => $mapping_id
                    ]);

                    $sukses++;
                    $hasil[] = "<strong>ACC {$acc_with_suffix}</strong>: {$px['kd_jenis_prw']} - {$px['nm_perawatan']}";
                } else {
                    $gagal++;

                    if ($mapping_id) {
                        $deleteLocal = "DELETE FROM khanza_pacs_mapping WHERE id = :id";
                        $stmtDelete = $pdo->prepare($deleteLocal);
                        $stmtDelete->execute(['id' => $mapping_id]);
                    }

                    $errorMsg = isset($apiResponse['response']['message'])
                        ? $apiResponse['response']['message']
                        : 'Unknown error';

                    $hasil[] = "<strong>ACC {$acc_with_suffix}</strong>: {$px['nm_perawatan']} - GAGAL ({$errorMsg})";
                }

            } catch (Exception $e) {
                $gagal++;

                if ($mapping_id) {
                    $deleteLocal = "DELETE FROM khanza_pacs_mapping WHERE id = :id";
                    $stmtDelete = $pdo->prepare($deleteLocal);
                    $stmtDelete->execute(['id' => $mapping_id]);
                }

                $hasil[] = "<strong>ACC {$acc_with_suffix}</strong>: {$px['nm_perawatan']} - ERROR ({$e->getMessage()})";
            }
        }

        $pdo->commit();

        $summary = [];
        $summary[] = "<h4>RINGKASAN PENGIRIMAN KE PACS</h4>";
        $summary[] = "Order: <strong>{$noorder_post}</strong>";
        $summary[] = "Pasien: <strong>{$patient_name}</strong> (MRN: {$clean_patient_id})";
        $summary[] = "☑️ Sukses: <strong>{$sukses}</strong> pemeriksaan";
        if ($gagal > 0) $summary[] = "✖️ Gagal: <strong>{$gagal}</strong> pemeriksaan";
        $summary[] = "";
        $summary[] = "<strong>Detail:</strong>";

        $allResults = array_merge($summary, $hasil);

        if ($sukses > 0) {
            die(json_encode([
                'status' => 'success',
                'message' => implode('<br>', $allResults)
            ]));
        } else {
            throw new Exception(implode('<br>', $allResults));
        }

    } catch(Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        die(json_encode([
            'status'=>'error',
            'message'=>$e->getMessage()
        ]));
    }
}

// AJAX: Reset status PACS
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['ajax_reset_pacs'])){
    header('Content-Type: application/json');
    try{
        $noorder_post = $_POST['noorder'] ?? '';
        if(!$noorder_post) throw new Exception('noorder kosong');

        $acc_base = substr($noorder_post, 4);

        $stmt = $pdo->prepare("DELETE FROM khanza_pacs_mapping WHERE pacs_acc LIKE ?");
        $stmt->execute([$acc_base.'%']);

        if($stmt->rowCount() > 0){
            die(json_encode([
                'status'=>'success',
                'message'=>"☑️ Mapping PACS berhasil direset!\n\nACC Base: {$acc_base}\n\nSilakan klik 'Kirim ke PACS' untuk membuat mapping baru."
            ]));
        } else {
            die(json_encode([
                'status'=>'info',
                'message'=>"✖️ Tidak ada data mapping PACS untuk ACC Base: {$acc_base}\n\nData sudah bersih, silakan klik 'Kirim ke PACS' untuk membuat mapping baru."
            ]));
        }

    } catch(Exception $e){
        die(json_encode(['status'=>'error', 'message'=>'✖️'.$e->getMessage()]));
    }
}

// ============================================
// 4. LOAD INITIAL DATA
// ============================================

$dataPerm = null;
$dataPemeriksaan = [];
$status_periksa = 'Ralan'; // ☑️ DEFAULT VALUE

if($noorder){
    // ☑️ AMBIL DATA DARI DATABASE DAN JOIN KE reg_periksa
    $stmt = $pdo->prepare("SELECT
        pr.*,
        rp.no_rkm_medis,
        rp.status_lanjut,
        pas.nm_pasien,
        (SELECT nm_dokter FROM dokter WHERE kd_dokter=pr.dokter_perujuk) as nm_dokter_perujuk
        FROM permintaan_radiologi pr
        INNER JOIN reg_periksa rp ON pr.no_rawat=rp.no_rawat
        INNER JOIN pasien pas ON rp.no_rkm_medis=pas.no_rkm_medis
        WHERE pr.noorder=?");
    $stmt->execute([$noorder]);
    $dataPerm = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ☑️ AMBIL STATUS DARI reg_periksa.status_lanjut (BUKAN dari permintaan_radiologi.status)
    if($dataPerm && !empty($dataPerm['status_lanjut'])){
        $status_raw = trim($dataPerm['status_lanjut']);
        
        // ☑️ NORMALISASI: Rajal/Ralan â†’ Ralan, Ranap â†’ Ranap
        if(stripos($status_raw, 'ralan') !== false || stripos($status_raw, 'rajal') !== false || strtolower($status_raw) === 'ralan'){
            $status_periksa = 'Ralan';
        } elseif(stripos($status_raw, 'ranap') !== false || strtolower($status_raw) === 'ranap'){
            $status_periksa = 'Ranap';
        } else {
            // Jika tidak cocok, gunakan default
            $status_periksa = 'Ralan';
        }
    } else {
        $status_periksa = 'Ralan';
    }

    // ☑️ AMBIL DATA PEMERIKSAAN
    $stmt = $pdo->prepare("SELECT jp.* FROM permintaan_pemeriksaan_radiologi ppr
        INNER JOIN jns_perawatan_radiologi jp ON ppr.kd_jenis_prw=jp.kd_jenis_prw
        WHERE ppr.noorder=?");
    $stmt->execute([$noorder]);
    $dataPemeriksaan = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ☑️ LABEL STATUS UNTUK TAMPILAN
$status_label = ($status_periksa == 'Ralan') ? 'Rawat Jalan' : 'Rawat Inap';

$currentYear = date('Y');
$years = range($currentYear - 5, $currentYear + 1);
$months = range(1, 12);
$days = range(1, 31);
$hours = range(0, 23);
$minutes = range(0, 59);
$seconds = range(0, 59);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Input Hasil Radiologi - <?=h($status_label)?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font:12px Arial,sans-serif;background:#ecf0f1;padding:10px}
.container{background:#fff;border-radius:8px;box-shadow:0 4px 8px rgba(0,0,0,.15);max-width:1400px;margin:0 auto}
.header{background:linear-gradient(135deg,#3498db,#2980b9);color:#fff;padding:15px 20px;font-weight:bold;font-size:16px;border-radius:8px 8px 0 0;display:flex;align-items:center;justify-content:space-between}
.header-left{display:flex;align-items:center;gap:10px}
.close-btn{background:#e74c3c;color:#fff;border:none;padding:5px 15px;border-radius:3px;cursor:pointer;font-size:12px}
.close-btn:hover{background:#c0392b}
.content{padding:20px}
.info-section{background:#ecf0f1;padding:15px;border-radius:5px;margin-bottom:20px}
.info-row{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:10px}
.info-item{display:flex;flex-direction:column}
.info-item label{font-weight:bold;color:#2c3e50;font-size:11px;margin-bottom:5px}
.info-group{display:flex;gap:5px;align-items:center}
.info-group input,.info-group select{flex:1}
.info-item input,.info-item select{padding:8px;border:1px solid #bdc3c7;border-radius:3px;font-size:12px}
.info-item input[readonly]{background:#ecf0f1;cursor:not-allowed;color:#555}
.tabs{display:flex;border-bottom:2px solid #bdc3c7;margin-bottom:10px}
.tab{padding:10px 20px;cursor:pointer;background:#ecf0f1;border-radius:5px 5px 0 0;margin-right:2px;font-weight:bold;font-size:13px}
.tab.active{background:#3498db;color:#fff}
.tab:hover{background:#5dade2;color:#fff}
.tab-content{display:none;padding:15px;border:1px solid #bdc3c7;border-radius:0 5px 5px 5px;min-height:200px}
.tab-content.active{display:block}
table{width:100%;border-collapse:collapse;font-size:11px}
th{background:#3498db;color:#fff;padding:8px;text-align:left;border:1px solid #2980b9;font-weight:bold}
td{padding:8px;border:1px solid #ddd}
td input{width:100%;padding:5px;border:1px solid #bdc3c7;border-radius:3px;font-size:11px}
.btn{padding:8px 15px;border:none;border-radius:4px;cursor:pointer;font-weight:bold;margin:5px;font-size:12px}
.btn-primary{background:#3498db;color:#fff}
.btn-success{background:#27ae60;color:#fff}
.btn-danger{background:#e74c3c;color:#fff}
.btn-warning{background:#f39c12;color:#fff}
.btn-info{background:#3498db;color:#fff;padding:6px 10px;font-size:11px}
.btn:hover{opacity:0.9}
textarea{width:100%;padding:10px;border:1px solid #bdc3c7;border-radius:4px;font-size:12px;resize:vertical}
.footer{padding:15px 20px;border-top:2px solid #ecf0f1;text-align:right;background:#f8f9fa}
.alert{padding:12px;margin:10px 0;border-radius:4px}
.alert-success{background:#d5f4e6;color:#0b5345;border-left:4px solid #27ae60}
.alert-error{background:#fadbd8;color:#641e16;border-left:4px solid #e74c3c}
.search-dropdown{position:relative}
.search-results{position:absolute;background:#fff;border:1px solid #bdc3c7;max-height:200px;overflow-y:auto;width:100%;z-index:999;box-shadow:0 4px 6px rgba(0,0,0,.1);border-radius:3px}
.search-item{padding:8px;cursor:pointer;border-bottom:1px solid #ecf0f1;font-size:11px}
.search-item:hover{background:#d6eaf8}
.modal-template{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center}
.modal-template.show{display:flex}
.modal-template-content{background:#fff;width:1100px;max-height:90vh;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.3);display:flex;flex-direction:column}
.modal-template-header{background:#4a90e2;color:#fff;padding:15px 20px;border-radius:8px 8px 0 0;display:flex;justify-content:space-between;align-items:center}
.modal-template-header h3{margin:0;font-size:16px}
.modal-template-body{flex:1;overflow:auto;padding:20px}
.modal-template-footer{padding:15px 20px;border-top:1px solid #ddd;text-align:right;background:#f9f9f9}
.btn-use-template{background:#4caf50;color:#fff;padding:8px 20px;border:none;border-radius:4px;cursor:pointer;font-weight:bold;font-size:12px}
.btn-use-template:hover{background:#45a049}
.btn-close-modal{background:#f44336;color:#fff;padding:8px 20px;border:none;border-radius:4px;cursor:pointer;font-weight:bold;font-size:12px;margin-left:10px}
.btn-close-modal:hover{background:#da190b}
#tableTemplate{width:100%;border-collapse:collapse;font-size:11px}
#tableTemplate thead{background:#3498db;color:#fff}
#tableTemplate th{padding:10px;text-align:left;border:1px solid #2980b9;font-weight:bold}
#tableTemplate td{padding:8px;border:1px solid #ddd;vertical-align:top}
#tableTemplate tbody tr{transition:background-color .2s;cursor:pointer}
#tableTemplate tbody tr:hover{background-color:#f5f5f5}
.toast-container{
  position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:99999;
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
.toast .close{
  float:right; border:none; background:transparent; color:#ecf0f1;
  cursor:pointer; font-size:14px; line-height:14px; opacity:.75;
}
.toast .close:hover{opacity:1}
.toast .title{font-weight:700; margin-bottom:6px}
.status-badge{
  display:inline-block;
  padding:6px 14px;
  border-radius:16px;
  font-size:12px;
  font-weight:bold;
}
.status-badge.ralan{
  background:#e3f2fd;
  color:#1565c0;
  border:2px solid #1565c0;
}
.status-badge.ranap{
  background:#fff3e0;
  color:#e65100;
  border:2px solid #e65100;
}
.modal-paket{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center}
.modal-paket.show{display:flex}
.modal-paket-content{background:#fff;width:900px;max-height:90vh;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.3);display:flex;flex-direction:column}
.modal-paket-header{background:#27ae60;color:#fff;padding:15px 20px;border-radius:8px 8px 0 0;display:flex;justify-content:space-between;align-items:center}
.modal-paket-body{flex:1;overflow:auto;padding:20px}
.modal-paket-footer{padding:15px 20px;border-top:1px solid #ddd;text-align:right;background:#f9f9f9}
#searchPaketInput{width:100%;padding:10px;border:1px solid #bdc3c7;border-radius:4px;font-size:12px;margin-bottom:15px}
#tablePaket{width:100%;border-collapse:collapse;font-size:11px}
#tablePaket thead{background:#27ae60;color:#fff}
#tablePaket th{padding:10px;text-align:left;border:1px solid #229954;font-weight:bold}
#tablePaket td{padding:8px;border:1px solid #ddd}
#tablePaket tbody tr{cursor:pointer;transition:background-color .2s}
#tablePaket tbody tr:hover{background-color:#d5f4e6}

/* Hapus garis tengah pada input Dokter PJ, Petugas, dan Dokter Perujuk */
#kd_dokter_pj, #nm_dokter_pj, #nip_petugas, #nama_petugas, #kd_dokter_perujuk, #nm_dokter_perujuk {
  text-decoration: none !important;
}
</style>
</head>
<body>

<div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>

<div class="container">
<div class="header">
<div class="header-left">
<span>Input Hasil Pemeriksaan Radiologi</span>
<span class="status-badge <?=h(strtolower($status_periksa))?>"><?=h($status_label)?></span>
</div>
<button class="close-btn" onclick="window.location.href='data_radiologi.php'">✖️ Tutup</button>
</div>

<div class="content">
<?php if($dataPerm): ?>
<input type="hidden" id="noorder" value="<?=h($noorder)?>">
<input type="hidden" id="status_periksa" value="<?=h($status_periksa)?>">

<div class="info-section">
<h3 style="margin-bottom:15px;color:#2c3e50">Informasi Pasien & Pemeriksaan</h3>

<div class="info-row">
<div class="info-item">
<label>No.Rawat</label>
<input type="text" id="no_rawat" value="<?=h($dataPerm['no_rawat'])?>" readonly>
</div>
<div class="info-item">
<label>No.RM</label>
<input type="text" value="<?=h($dataPerm['no_rkm_medis'])?>" readonly>
</div>
<div class="info-item">
<label>Nama Pasien</label>
<input type="text" value="<?=h($dataPerm['nm_pasien'])?>" readonly>
</div>
</div>

<div class="info-row">
<div class="info-item">
<label>Dokter P.J</label>
<div class="info-group search-dropdown">
<input type="text" id="kd_dokter_pj" value="<?=h($default_kd_dokter_pj)?>" placeholder="Kode" style="width:120px" readonly>
<input type="text" id="nm_dokter_pj" value="<?=h($default_nm_dokter_pj)?>" placeholder="Cari dokter..." autocomplete="off">
<div id="search_dokter_pj" class="search-results"></div>
</div>
</div>
<div class="info-item">
<label>Petugas</label>
<div class="info-group search-dropdown">
<input type="text" id="nip_petugas" value="<?=h($default_nip_petugas)?>" placeholder="NIP" style="width:140px">
<input type="text" id="nama_petugas" value="<?=h($default_nama_petugas)?>" placeholder="Cari petugas..." autocomplete="off">
<div id="search_petugas" class="search-results"></div>
</div>
</div>
<div class="info-item"></div>
</div>

<div class="info-row">
<div class="info-item">
<label>Dokter Perujuk</label>
<div class="info-group search-dropdown">
<input type="text" id="kd_dokter_perujuk" value="<?=h($dataPerm['dokter_perujuk'])?>" placeholder="Kode" style="width:120px" readonly>
<input type="text" id="nm_dokter_perujuk" value="<?=h($dataPerm['nm_dokter_perujuk'])?>" placeholder="Cari dokter..." autocomplete="off">
<div id="search_dokter_perujuk" class="search-results"></div>
</div>
</div>

<div class="info-item">
<label>Tanggal</label>
<div class="info-group">
<select id="tgl_tanggal" style="width:70px">
<?php foreach($days as $d): ?>
<option value="<?=$d?>" <?=$d==date('d')?'selected':''?>><?=str_pad($d,2,'0',STR_PAD_LEFT)?></option>
<?php endforeach; ?>
</select>
<span>-</span>
<select id="tgl_bulan" style="width:70px">
<?php foreach($months as $m): ?>
<option value="<?=$m?>" <?=$m==date('m')?'selected':''?>><?=str_pad($m,2,'0',STR_PAD_LEFT)?></option>
<?php endforeach; ?>
</select>
<span>-</span>
<select id="tgl_tahun" style="width:90px">
<?php foreach($years as $y): ?>
<option value="<?=$y?>" <?=$y==$currentYear?'selected':''?>><?=$y?></option>
<?php endforeach; ?>
</select>
</div>
</div>

<div class="info-item">
<label>Jam</label>
<div class="info-group">
<select id="jam_jam" style="width:70px">
<?php foreach($hours as $h): ?>
<option value="<?=$h?>" <?=$h==date('H')?'selected':''?>><?=str_pad($h,2,'0',STR_PAD_LEFT)?></option>
<?php endforeach; ?>
</select>
<span>:</span>
<select id="jam_menit" style="width:70px">
<?php foreach($minutes as $m): ?>
<option value="<?=$m?>" <?=$m==date('i')?'selected':''?>><?=str_pad($m,2,'0',STR_PAD_LEFT)?></option>
<?php endforeach; ?>
</select>
<span>:</span>
<select id="jam_detik" style="width:70px">
<?php foreach($seconds as $s): ?>
<option value="<?=$s?>" <?=$s==date('s')?'selected':''?>><?=str_pad($s,2,'0',STR_PAD_LEFT)?></option>
<?php endforeach; ?>
</select>
<button class="btn-info" onclick="setCurrentTime()" title="Set ke waktu sekarang">⏱️Now</button>
</div>
</div>
</div>
</div>

<div class="tabs">
<div class="tab active" onclick="switchTab('pemeriksaan')">Pemeriksaan</div>
<div class="tab" onclick="switchTab('bhp')">BHP Radiologi</div>
<div class="tab" onclick="switchTab('hasil')">Hasil Pemeriksaan</div>
</div>

<div id="tab-pemeriksaan" class="tab-content active">
<div style="margin-bottom:10px">
<button class="btn btn-success" onclick="openTambahPaket()">Tambah Paket Pemeriksaan</button>
</div>
<table id="table-pemeriksaan">
<thead>
<tr>
<th style="width:80px">Kode</th>
<th>Nama Pemeriksaan</th>
<th style="width:100px">Proyeksi</th>
<th style="width:70px">kV</th>
<th style="width:70px">mAs</th>
<th style="width:70px">FFD</th>
<th style="width:70px">BSF</th>
<th style="width:70px">Inak</th>
<th style="width:80px">Jml Penyinaran</th>
<th style="width:100px">Dosis Radiasi</th>
<th style="width:60px">Aksi</th>
</tr>
</thead>
<tbody id="tbody-pemeriksaan">
<?php foreach($dataPemeriksaan as $px): ?>
<tr data-kode="<?=h($px['kd_jenis_prw'])?>">
<td><?=h($px['kd_jenis_prw'])?></td>
<td><?=h($px['nm_perawatan'])?></td>
<td><input type="text" class="proyeksi" placeholder="Proyeksi"></td>
<td><input type="number" class="kV" placeholder="kV"></td>
<td><input type="number" class="mAs" placeholder="mAs"></td>
<td><input type="number" class="FFD" placeholder="FFD"></td>
<td><input type="text" class="BSF" placeholder="BSF"></td>
<td><input type="text" class="inak" placeholder="Inak"></td>
<td><input type="number" class="jml_penyinaran" placeholder="Jml"></td>
<td><input type="number" class="dosis_radiasi" placeholder="Dosis" step="0.01"></td>
<td><button class="btn btn-danger" style="padding:4px 8px;font-size:10px" onclick="deleteRow(this)">✖️</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div id="tab-bhp" class="tab-content">
<table id="table-bhp">
<thead>
<tr>
<th style="width:80px">Jml</th>
<th style="width:120px">Kode</th>
<th>Nama BHP</th>
<th style="width:100px">Satuan</th>
<th style="width:80px">Aksi</th>
</tr>
</thead>
<tbody>
<tr>
<td colspan="5" style="text-align:center;color:#95a5a6;padding:30px">Belum ada data BHP</td>
</tr>
</tbody>
</table>
</div>

<div id="tab-hasil" class="tab-content">
<label style="display:block;margin-bottom:10px;font-weight:bold;color:#2c3e50">Hasil Pemeriksaan <small style="color:#7f8c8d">(Maksimal 1000 karakter)</small></label>
<textarea id="hasil_pemeriksaan" rows="12" maxlength="1000" placeholder="Masukkan hasil pemeriksaan radiologi..."></textarea>
<div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center">
<span id="char_count" style="color:#7f8c8d;font-size:11px">0/1000 karakter</span>
<button class="btn btn-warning" onclick="openTemplate()">Pilih Template</button>
</div>
</div>

<?php else: ?>
<div class="alert alert-error">Data permintaan tidak ditemukan!<a href="data_radiologi.php" style="color:#e74c3c;font-weight:bold">🔙 Kembali ke Data Radiologi</a></div>
<?php endif; ?>
</div>

<div class="footer">
<button class="btn btn-success" onclick="simpanData()">💾 Simpan Data</button>
<button class="btn btn-primary" onclick="kirimPACS()">☑️ Kirim ke PACS</button>
<button class="btn btn-warning" onclick="resetDanKirimUlangPACS()" title="Reset status jika data sudah dikirim ke PACS">🔜 Reset & Ulang Kirim</button>
<button class="btn btn-danger" onclick="window.location.href='data_radiologi.php'">🔙 Kembali</button>
</div>
</div>

<!-- Modal Template Hasil -->
<div id="modalTemplate" class="modal-template">
<div class="modal-template-content">
<div class="modal-template-header">
<h3>Pilih Template Hasil Pemeriksaan</h3>
<button class="btn-close-modal" onclick="closeModalTemplate()">✖️ Tutup</button>
</div>
<div class="modal-template-body">
<table id="tableTemplate">
<thead>
<tr>
<th style="width:60px">No</th>
<th style="width:250px">Nama Pemeriksaan</th>
<th>Template Hasil</th>
<th style="width:100px">Aksi</th>
</tr>
</thead>
<tbody id="tbody-template">
<tr><td colspan="4" style="text-align:center;padding:20px;color:#95a5a6">Loading...</td></tr>
</tbody>
</table>
</div>
<div class="modal-template-footer">
<button class="btn-close-modal" onclick="closeModalTemplate()">✖️ Tutup</button>
</div>
</div>
</div>

<!-- Modal Paket -->
<div id="modalPaket" class="modal-paket">
<div class="modal-paket-content">
<div class="modal-paket-header">
<h3>Tambah Paket Pemeriksaan</h3>
<button class="btn-close-modal" onclick="closeModalPaket()">✖️ Tutup</button>
</div>
<div class="modal-paket-body">
<input type="text" id="searchPaketInput" placeholder="Cari kode atau nama pemeriksaan..." onkeyup="searchPaket()">
<table id="tablePaket">
<thead>
<tr>
<th style="width:120px">Kode</th>
<th>Nama Pemeriksaan</th>
<th style="width:120px">Tarif</th>
<th style="width:100px">Aksi</th>
</tr>
</thead>
<tbody id="tbody-paket">
<tr><td colspan="4" style="text-align:center;padding:20px;color:#95a5a6">Ketik untuk mencari...</td></tr>
</tbody>
</table>
</div>
<div class="modal-paket-footer">
<button class="btn-close-modal" onclick="closeModalPaket()">✖️ Tutup</button>
</div>
</div>
</div>

<script>
const noorder = <?=json_encode($noorder)?>;
let allPaket = [];

// ============================================
// TOAST NOTIFICATION
// ============================================
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
    <button class="close" onclick="this.parentElement.remove()" title="Tutup">Ã—</button>
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

window.alert = function(msg){
  const s = String(msg || '');
  const lower = s.toLowerCase();
  let type = 'info';
  if(lower.includes('gagal') || lower.includes('error') || lower.includes('sqlstate') || lower.includes('warning')) type='error';
  else if(lower.includes('sukses') || lower.includes('berhasil') || lower.includes('☑️')) type='success';
  else if(lower.includes('peringatan') || lower.includes('belum') || lower.includes('âš ')) type='warning';
  showToast(s, type, type==='error' ? 9000 : 4500);
};

// ============================================
// TABS
// ============================================
function switchTab(name){
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
  
  event.target.classList.add('active');
  document.getElementById('tab-'+name).classList.add('active');
}

// ============================================
// DATETIME HELPER
// ============================================
function setCurrentTime(){
  const now = new Date();
  document.getElementById('jam_jam').value = now.getHours();
  document.getElementById('jam_menit').value = now.getMinutes();
  document.getElementById('jam_detik').value = now.getSeconds();
  showToast('Waktu diset ke: ' + now.toLocaleTimeString('id-ID'), 'info', 2000);
}

// ============================================
// SEARCH DOKTER & PETUGAS
// ============================================
function setupSearch(inputId, resultId, endpoint, codeField, nameField){
  const input = document.getElementById(inputId);
  const result = document.getElementById(resultId);
  const codeInput = document.getElementById(codeField);
  
  let timeout = null;
  
  input.addEventListener('input', function(){
    clearTimeout(timeout);
    const q = this.value.trim();
    
    if(q.length < 2){
      result.innerHTML = '';
      result.style.display = 'none';
      return;
    }
    
    timeout = setTimeout(() => {
      fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: endpoint + '=1&q=' + encodeURIComponent(q)
      })
      .then(r => r.json())
      .then(data => {
        if(data.length === 0){
          result.innerHTML = '<div class="search-item" style="color:#95a5a6">Tidak ada hasil</div>';
        } else {
          result.innerHTML = data.map(item => {
            const code = item[Object.keys(item)[0]];
            const name = item[Object.keys(item)[1]];
            return `<div class="search-item" onclick="selectItem('${codeField}', '${nameField}', '${code}', '${name}', '${resultId}')">${code} - ${name}</div>`;
          }).join('');
        }
        result.style.display = 'block';
      })
      .catch(e => {
        result.innerHTML = '<div class="search-item" style="color:#e74c3c">Error: ' + e.message + '</div>';
        result.style.display = 'block';
      });
    }, 300);
  });
  
  input.addEventListener('blur', () => {
    setTimeout(() => {
      result.style.display = 'none';
    }, 200);
  });
}

function selectItem(codeField, nameField, code, name, resultId){
  document.getElementById(codeField).value = code;
  document.getElementById(nameField).value = name;
  document.getElementById(resultId).style.display = 'none';
}

// ============================================
// PEMERIKSAAN TABLE
// ============================================
function deleteRow(btn){
  const row = btn.closest('tr');
  const kode = row.dataset.kode;
  const nama = row.cells[1].textContent;
  
  if(!confirm(`Hapus pemeriksaan:\n${kode} - ${nama}?`)) return;
  
  // Hapus dari database
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `ajax_hapus_pemeriksaan=1&noorder=${encodeURIComponent(noorder)}&kd_jenis_prw=${encodeURIComponent(kode)}`
  })
  .then(r => r.json())
  .then(d => {
    if(d.status === 'success'){
      row.remove();
      showToast('☑️' + (d.message || 'Berhasil dihapus'), 'success', 2500);
    } else {
      showToast('✖️' + (d.message || 'Gagal hapus'), 'error', 4500);
    }
  })
  .catch(e => showToast('✖️ Error: ' + e.message, 'error', 4500));
}

// ============================================
// MODAL PAKET
// ============================================
function openTambahPaket(){
  document.getElementById('modalPaket').classList.add('show');
  document.getElementById('searchPaketInput').value = '';
  document.getElementById('tbody-paket').innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#95a5a6">Ketik untuk mencari...</td></tr>';
}

function closeModalPaket(){
  document.getElementById('modalPaket').classList.remove('show');
}

function searchPaket(){
  const q = document.getElementById('searchPaketInput').value.trim();
  const tbody = document.getElementById('tbody-paket');
  
  if(q.length < 2){
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#95a5a6">Ketik minimal 2 karakter...</td></tr>';
    return;
  }
  
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `ajax_search_paket=1&q=${encodeURIComponent(q)}`
  })
  .then(r => r.json())
  .then(data => {
    if(data.length === 0){
      tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#95a5a6">Tidak ada hasil</td></tr>';
    } else {
      tbody.innerHTML = data.map(item => `
        <tr>
          <td style="font-family:monospace">${item.kd_jenis_prw}</td>
          <td>${item.nm_perawatan}</td>
          <td style="text-align:right">Rp ${parseInt(item.total_byr || 0).toLocaleString('id-ID')}</td>
          <td><button class="btn btn-success" style="padding:4px 10px;font-size:10px" onclick="tambahPaket('${item.kd_jenis_prw}', '${item.nm_perawatan.replace(/'/g, "\\'")}')">+ Tambah</button></td>
        </tr>
      `).join('');
    }
  })
  .catch(e => {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#e74c3c">Error: ' + e.message + '</td></tr>';
  });
}

function tambahPaket(kode, nama){
  // Cek duplikat
  const exists = document.querySelector(`#tbody-pemeriksaan tr[data-kode="${kode}"]`);
  if(exists){
    showToast('Pemeriksaan ini sudah ada di tabel!', 'warning', 2500);
    return;
  }
  
  // Tambah ke database dulu
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `ajax_tambah_pemeriksaan=1&noorder=${encodeURIComponent(noorder)}&kd_jenis_prw=${encodeURIComponent(kode)}`
  })
  .then(r => r.json())
  .then(d => {
    if(d.status === 'success'){
      // Tambah ke tabel
      const tbody = document.getElementById('tbody-pemeriksaan');
      const row = tbody.insertRow();
      row.dataset.kode = kode;
      row.innerHTML = `
        <td>${kode}</td>
        <td>${nama}</td>
        <td><input type="text" class="proyeksi" placeholder="Proyeksi"></td>
        <td><input type="number" class="kV" placeholder="kV"></td>
        <td><input type="number" class="mAs" placeholder="mAs"></td>
        <td><input type="number" class="FFD" placeholder="FFD"></td>
        <td><input type="text" class="BSF" placeholder="BSF"></td>
        <td><input type="text" class="inak" placeholder="Inak"></td>
        <td><input type="number" class="jml_penyinaran" placeholder="Jml"></td>
        <td><input type="number" class="dosis_radiasi" placeholder="Dosis" step="0.01"></td>
        <td><button class="btn btn-danger" style="padding:4px 8px;font-size:10px" onclick="deleteRow(this)">✖️</button></td>
      `;
      
      showToast('☑️' + (d.message || 'Berhasil ditambahkan'), 'success', 2500);
      closeModalPaket();
    } else {
      showToast('✖️ ' + (d.message || 'Gagal tambah'), 'error', 4500);
    }
  })
  .catch(e => showToast('✖️ Error: ' + e.message, 'error', 4500));
}

// ============================================
// TEMPLATE HASIL
// ============================================
function openTemplate(){
  const modal = document.getElementById('modalTemplate');
  modal.classList.add('show');
  
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'ajax_template=1'
  })
  .then(r => r.json())
  .then(data => {
    const tbody = document.getElementById('tbody-template');
    if(data.length === 0){
      tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#95a5a6">Tidak ada template</td></tr>';
    } else {
      tbody.innerHTML = data.map(item => `
        <tr>
          <td style="text-align:center">${item.no_template}</td>
          <td>${item.nama_pemeriksaan}</td>
          <td style="white-space:pre-wrap;font-family:monospace;font-size:10px">${item.template_hasil_radiologi.substring(0, 200)}${item.template_hasil_radiologi.length > 200 ? '...' : ''}</td>
          <td><button class="btn-use-template" onclick="useTemplate(\`${item.template_hasil_radiologi.replace(/`/g, '\\`')}\`)">☑️Pilih</button></td>
        </tr>
      `).join('');
    }
  })
  .catch(e => {
    document.getElementById('tbody-template').innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#e74c3c">Error: ' + e.message + '</td></tr>';
  });
}

function closeModalTemplate(){
  document.getElementById('modalTemplate').classList.remove('show');
}

function useTemplate(template){
  document.getElementById('hasil_pemeriksaan').value = template;
  updateCharCount();
  closeModalTemplate();
  showToast('Template berhasil diterapkan', 'success', 2000);
  
  // Switch ke tab hasil
  document.querySelectorAll('.tab').forEach((t, i) => {
    t.classList.remove('active');
    if(i === 2) t.classList.add('active');
  });
  document.querySelectorAll('.tab-content').forEach((tc, i) => {
    tc.classList.remove('active');
    if(i === 2) tc.classList.add('active');
  });
}

// ============================================
// CHAR COUNTER
// ============================================
function updateCharCount(){
  const textarea = document.getElementById('hasil_pemeriksaan');
  const counter = document.getElementById('char_count');
  const len = textarea.value.length;
  counter.textContent = len + '/1000 karakter';
  counter.style.color = len > 900 ? '#e74c3c' : '#7f8c8d';
}

// ============================================
// SAVE DATA
// ============================================
function simpanData(){
  const no_rawat = document.getElementById('no_rawat').value;
  const kd_dokter_pj = document.getElementById('kd_dokter_pj').value;
  const nip_petugas = document.getElementById('nip_petugas').value;
  const kd_dokter_perujuk = document.getElementById('kd_dokter_perujuk').value;
  const status_periksa = document.getElementById('status_periksa').value;
  
  if(!kd_dokter_pj || !nip_petugas){
    showToast('Dokter P.J dan Petugas harus diisi!', 'warning', 3000);
    return;
  }
  
  // Kumpulkan data pemeriksaan
  const pemeriksaan = [];
  document.querySelectorAll('#tbody-pemeriksaan tr').forEach(row => {
    pemeriksaan.push({
      kd_jenis_prw: row.dataset.kode,
      proyeksi: row.querySelector('.proyeksi')?.value || '',
      kV: row.querySelector('.kV')?.value || '',
      mAs: row.querySelector('.mAs')?.value || '',
      FFD: row.querySelector('.FFD')?.value || '',
      BSF: row.querySelector('.BSF')?.value || '',
      inak: row.querySelector('.inak')?.value || '',
      jml_penyinaran: row.querySelector('.jml_penyinaran')?.value || '',
      dosis_radiasi: row.querySelector('.dosis_radiasi')?.value || ''
    });
  });
  
  if(pemeriksaan.length === 0){
    showToast('✖️ Belum ada pemeriksaan untuk disimpan!', 'warning', 3000);
    return;
  }
  
  const formData = new URLSearchParams();
  formData.append('ajax_save', '1');
  formData.append('noorder', noorder);
  formData.append('no_rawat', no_rawat);
  formData.append('status_periksa', status_periksa);
  formData.append('kd_dokter_pj', kd_dokter_pj);
  formData.append('nip_petugas', nip_petugas);
  formData.append('kd_dokter_perujuk', kd_dokter_perujuk);
  formData.append('tgl_tanggal', document.getElementById('tgl_tanggal').value);
  formData.append('tgl_bulan', document.getElementById('tgl_bulan').value);
  formData.append('tgl_tahun', document.getElementById('tgl_tahun').value);
  formData.append('jam_jam', document.getElementById('jam_jam').value);
  formData.append('jam_menit', document.getElementById('jam_menit').value);
  formData.append('jam_detik', document.getElementById('jam_detik').value);
  formData.append('hasil_pemeriksaan', document.getElementById('hasil_pemeriksaan').value);
  formData.append('pemeriksaan_data', JSON.stringify(pemeriksaan));
  
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: formData.toString()
  })
  .then(r => r.json())
  .then(d => {
    if(d.status === 'success'){
      showToast(d.message || '☑️ Data berhasil disimpan!', 'success', 4000);
    } else {
      showToast(d.message || '✖️ Gagal menyimpan data!', 'error', 6000);
    }
  })
  .catch(e => showToast('✖️ Error: ' + e.message, 'error', 6000));
}

// ============================================
// PACS
// ============================================
function kirimPACS(){
  if(!confirm('Kirim data pemeriksaan ini ke PACS?')) return;
  
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `ajax_kirim_pacs=1&noorder=${encodeURIComponent(noorder)}`
  })
  .then(r => r.json())
  .then(d => {
    if(d.status === 'success'){
      showToast(d.message, 'success', 8000);
    } else {
      showToast(d.message || '✖️ Gagal kirim ke PACS', 'error', 8000);
    }
  })
  .catch(e => showToast('✖️ Error: ' + e.message, 'error', 6000));
}

function resetDanKirimUlangPACS(){
  if(!confirm('Reset data PACS dan kirim ulang?\n\nPeringatan: Data mapping PACS sebelumnya akan dihapus!')) return;
  
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `ajax_reset_pacs=1&noorder=${encodeURIComponent(noorder)}`
  })
  .then(r => r.json())
  .then(d => {
    if(d.status === 'success' || d.status === 'info'){
      showToast(d.message, d.status, 5000);
    } else {
      showToast(d.message || '✖️ Gagal reset PACS', 'error', 6000);
    }
  })
  .catch(e => showToast('✖️ Error: ' + e.message, 'error', 6000));
}

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', () => {
  // Setup search
  setupSearch('nm_dokter_pj', 'search_dokter_pj', 'ajax_dokter', 'kd_dokter_pj', 'nm_dokter_pj');
  setupSearch('nama_petugas', 'search_petugas', 'ajax_petugas', 'nip_petugas', 'nama_petugas');
  setupSearch('nm_dokter_perujuk', 'search_dokter_perujuk', 'ajax_dokter', 'kd_dokter_perujuk', 'nm_dokter_perujuk');
  
  // Char counter
  const textarea = document.getElementById('hasil_pemeriksaan');
  if(textarea){
    textarea.addEventListener('input', updateCharCount);
    updateCharCount();
  }
});
</script>

</body>
</html>