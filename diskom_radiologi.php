<?php
session_start();
require_once __DIR__ . '/auth_check.php';
require_role(['radiologi', 'superadmin']);
require_once 'config.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$no_rawat    = $_GET['no_rawat'] ?? '';
$tgl_periksa = $_GET['tgl_periksa'] ?? '';

if (!$no_rawat) {
    die('No.Rawat tidak ditemukan');
}

function pacsGetOrder($pacs_acc, $pacs_mrn) {
    $base = rtrim(PACS_BASE_URL, '/');

    $endpoints = [
        "/api/orders/{$pacs_acc}/{$pacs_mrn}",
        "/api/simrs/{$pacs_acc}/{$pacs_mrn}",
    ];

    $last = [
        'http_code' => 0,
        'response'  => null,
        'error'     => null,
        'used_url'  => null,
    ];

    foreach ($endpoints as $path) {
        $api_url = $base . $path;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        if (defined('API_TOKEN') && !empty(API_TOKEN)) {
            $headers[] = 'Authorization: Bearer ' . API_TOKEN;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        $last = [
            'http_code' => $http_code,
            'response'  => $response,
            'error'     => $error,
            'used_url'  => $api_url,
        ];

        if ($http_code == 200 && $response) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $rawData   = $decoded['data'] ?? $decoded;
                $extracted = extractPacsOrderData(is_array($rawData) ? $rawData : []);
                if ($extracted['has_image'] || $extracted['has_report']) {
                    return $last;
                }
            }
            continue;
        }

        if ($http_code == 404) {
            continue;
        }

        return $last;
    }

    return $last;
}

function pacsCheckLiveDiskom($pacs_acc, $pacs_mrn): array {
    $base = rtrim(PACS_BASE_URL, '/');
    $url = $base . "/api/orders/{$pacs_acc}/{$pacs_mrn}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (defined('API_TOKEN') && !empty(API_TOKEN) && API_TOKEN != 'xx') {
        $headers[] = 'Authorization: Bearer ' . API_TOKEN;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($http_code == 200 && $response) {
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $rawData = $decoded['data'] ?? $decoded;
            if (function_exists('extractPacsOrderData')) {
                $ex = extractPacsOrderData(is_array($rawData) ? $rawData : []);
                if ($ex['has_image'] || $ex['has_report']) {
                    return ['found' => true, 'not_found' => false, 'error' => null, 'data' => $ex];
                }
            }
        }
        return ['found' => false, 'not_found' => true, 'error' => null, 'data' => null];
    }
    if ($http_code == 404) {
        return ['found' => false, 'not_found' => true, 'error' => null, 'data' => null];
    }
    return ['found' => false, 'not_found' => false, 'error' => $error ?: "HTTP {$http_code}", 'data' => null];
}

// ============================================
// AJAX HANDLER - CEK EXPERTISE TEXT
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_check_expertise'])) {
    header('Content-Type: application/json');
    try {
        $mapping_id = $_POST['mapping_id'] ?? 0;
        $pacs_acc   = $_POST['pacs_acc'] ?? '';
        $pacs_mrn   = $_POST['pacs_mrn'] ?? '';

        if (empty($mapping_id)) {
            throw new Exception('ID Mapping tidak valid');
        }

        $stmt = $pdo->prepare("SELECT id, dicom_url, expertise_url, expertise_text, pacs_acc, pacs_mrn FROM khanza_pacs_mapping WHERE id = ? AND pacs_acc = ? AND pacs_mrn = ? LIMIT 1");
        $stmt->execute([$mapping_id, $pacs_acc, $pacs_mrn]);
        $map = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$map) {
            throw new Exception('Data mapping tidak ditemukan');
        }

        $hasDicomUrl      = !empty($map['dicom_url']);
        $hasExpertiseText = !empty($map['expertise_text']);

        die(json_encode([
            'status'              => 'success',
            'has_dicom_url'       => $hasDicomUrl,
            'has_expertise_text'  => $hasExpertiseText,
            'dicom_url'           => $map['dicom_url'] ?? '',
            'expertise_text'      => $map['expertise_text'] ?? '',
            'pacs_acc'            => $map['pacs_acc'],
            'pacs_mrn'            => $map['pacs_mrn']
        ]));
    } catch (Exception $e) {
        die(json_encode([
            'status'  => 'error',
            'message' => $e->getMessage()
        ]));
    }
}

// ============================================
// AJAX HANDLER - AMBIL SEMUA DATA DARI PACS
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_ambil_pacs'])) {
    header('Content-Type: application/json');
    try {
        $pdo->beginTransaction();

        $no_rawat_input = trim($_POST['no_rawat'] ?? '');
        $no_rkm_medis    = $_POST['no_rkm_medis'] ?? '';

        if ($no_rawat_input === '') {
            throw new Exception('no_rawat kosong/tidak terkirim dari halaman. Silakan refresh halaman dan coba lagi.');
        }

        $query = "SELECT * FROM khanza_pacs_mapping WHERE no_rawat = :no_rawat AND status IN ('1', 'active', 'sent') ORDER BY created_at DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['no_rawat' => $no_rawat_input]);
        $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($mappings) == 0) {
            throw new Exception("❌ Data belum dikirim ke PACS!\n\nSilakan kirim data ke PACS terlebih dahulu melalui form input hasil radiologi.");
        }

        $hasil = [];
        $sukses = 0;
        $gagal = 0;
        $all_data = [];
        $all_expertise = [];

        foreach ($mappings as $map) {
            $pacs_acc = $map['pacs_acc'];
            $pacs_mrn = $map['pacs_mrn'];

            $req = pacsGetOrder($pacs_acc, $pacs_mrn);
            $response  = $req['response'];
            $http_code = $req['http_code'];
            $error     = $req['error'];

            if ($http_code == 200 && $response) {
                $responseData = json_decode($response, true);
                $rawData = $responseData['data'] ?? $responseData;
                $ex = extractPacsOrderData(is_array($rawData) ? $rawData : []);

                $dicom_url = '';
                if (!empty($ex['study_uid']) && defined('DICOM_VIEWER_URL')) {
                    $dicom_url = str_replace('{uid}', $ex['study_uid'], DICOM_VIEWER_URL);
                } elseif (!empty($rawData['link_dicom'])) {
                    $dicom_url = $rawData['link_dicom'];
                }

                $stmtOld = $pdo->prepare("SELECT dicom_url, expertise_text FROM khanza_pacs_mapping WHERE id = ?");
                $stmtOld->execute([$map['id']]);
                $old = $stmtOld->fetch(PDO::FETCH_ASSOC) ?: [];

                $final_dicom_url      = !empty($dicom_url) ? $dicom_url : ($old['dicom_url'] ?? '');
                $final_expertise_text = !empty($ex['expertise_text']) ? $ex['expertise_text'] : ($old['expertise_text'] ?? '');
                $reallyGotData = !empty($final_dicom_url) || !empty($final_expertise_text);

                if (!empty($ex['expertise_text'])) {
                    $all_expertise[] = "ACC: {$pacs_acc}\n" . $ex['expertise_text_plain'];
                }

                $updateQuery = "UPDATE khanza_pacs_mapping
                                 SET dicom_url = :dicom_url,
                                     expertise_url = :expertise_url,
                                     expertise_text = :expertise_text,
                                     procedure_name = :procedure_name,
                                     modality = :modality,
                                     radname = :radname,
                                     notes = :notes,
                                     updated_at = NOW()
                                 WHERE id = :id";
                $stmtUpdate = $pdo->prepare($updateQuery);
                $stmtUpdate->execute([
                    'dicom_url'      => $final_dicom_url,
                    'expertise_url'  => $rawData['expertise_url'] ?? '',
                    'expertise_text' => $final_expertise_text,
                    'procedure_name' => $ex['prosedur'],
                    'modality'       => $ex['modality'] ?: ($map['modality'] ?? ''),
                    'radname'        => $ex['dokrad_name'],
                    'notes'          => "Status: {$ex['report_status']} | Retrieved: " . date('Y-m-d H:i:s')
                                         . ($reallyGotData ? '' : ' | Data PACS ditemukan tapi imaging/laporan belum tersedia'),
                    'id'             => $map['id']
                ]);

                if ($reallyGotData) {
                    $sukses++;
                    $hasil[] = [
                        'acc'            => $pacs_acc,
                        'mrn'            => $pacs_mrn,
                        'status'         => 'success',
                        'dicom_url'      => $final_dicom_url,
                        'expertise_text' => $final_expertise_text,
                        'image_count'    => $ex['image_count'],
                        'prosedur'       => $ex['prosedur'],
                        'report_status'  => $ex['report_status'],
                    ];
                    $all_data[] = $rawData;
                } else {
                    $gagal++;
                    $hasil[] = [
                        'acc'     => $pacs_acc,
                        'status'  => 'pending',
                        'message' => 'Order ditemukan di PACS, tapi imaging/laporan belum tersedia (report status: ' . ($ex['report_status'] ?: 'unknown') . ')'
                    ];
                }

            } else if ($http_code == 404) {
                $gagal++;
                $hasil[] = [
                    'acc'     => $pacs_acc,
                    'status'  => 'not_found',
                    'message' => 'Pemeriksaan belum ditemukan di PACS (belum diperiksa / ACC salah)'
                ];
            } else {
                $gagal++;
                $hasil[] = [
                    'acc'     => $pacs_acc,
                    'status'  => 'error',
                    'message' => $error ?: "HTTP {$http_code}"
                ];
            }
        }

        $pdo->commit();

        $hasilRadiologiError = null;
        if (count($all_expertise) > 0) {
            try {
                $combined_expertise = implode("\n\n" . str_repeat("=", 50) . "\n\n", $all_expertise);

                $stmtTglJam = $pdo->prepare("SELECT tgl_periksa, jam FROM periksa_radiologi WHERE no_rawat = ? ORDER BY tgl_periksa DESC, jam DESC LIMIT 1");
                $stmtTglJam->execute([$no_rawat_input]);
                $tglJamData = $stmtTglJam->fetch(PDO::FETCH_ASSOC);
                $tgl_hasil = $tglJamData['tgl_periksa'] ?? date('Y-m-d');
                $jam_hasil = $tglJamData['jam'] ?? date('H:i:s');

                if (empty($no_rawat_input)) {
                    throw new Exception('no_rawat kosong saat mau simpan ke hasil_radiologi');
                }

                $stmtUpsertHasil = $pdo->prepare("
                    INSERT INTO hasil_radiologi (no_rawat, tgl_periksa, jam, hasil)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        tgl_periksa = VALUES(tgl_periksa),
                        jam = VALUES(jam),
                        hasil = VALUES(hasil)
                ");
                $stmtUpsertHasil->execute([$no_rawat_input, $tgl_hasil, $jam_hasil, $combined_expertise]);

            } catch (Exception $eHasil) {
                $hasilRadiologiError = $eHasil->getMessage();
                error_log('[ajax_ambil_pacs] Gagal simpan hasil_radiologi: ' . $hasilRadiologiError);
            }
        }

        $message = "📊 RINGKASAN PULL DICOM\n\n";
        $message .= "✅ Berhasil: {$sukses} pemeriksaan\n";
        if ($gagal > 0) {
            $message .= "⚠️ Belum tersedia: {$gagal} pemeriksaan\n";
        }
        if (count($all_expertise) > 0) {
            if ($hasilRadiologiError) {
                $message .= "\n⚠️ Data mapping PACS tersimpan, tapi gagal auto-save ke tabel hasil_radiologi ({$hasilRadiologiError})";
            } else {
                $message .= "\n💾 Data tersimpan! ✅";
            }
        }

        die(json_encode([
            'status'                  => 'success',
            'message'                 => $message,
            'total_mapping'           => count($mappings),
            'sukses'                  => $sukses,
            'gagal'                   => $gagal,
            'hasil'                   => $hasil,
            'data'                    => $all_data,
            'expertise_combined'      => implode("\n\n" . str_repeat("=", 50) . "\n\n", $all_expertise),
            'hasil_radiologi_warning' => $hasilRadiologiError
        ]));

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die(json_encode([
            'status'  => 'error',
            'message' => $e->getMessage()
        ]));
    }
}

// ============================================
// AJAX HANDLER - AMBIL SINGLE DICOM BY ID
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_ambil_single'])) {
    header('Content-Type: application/json');
    try {
        $pdo->beginTransaction();

        $mapping_id = $_POST['mapping_id'] ?? 0;
        if (empty($mapping_id)) {
            throw new Exception('ID Mapping tidak valid');
        }

        $stmt = $pdo->prepare("SELECT * FROM khanza_pacs_mapping WHERE id = ?");
        $stmt->execute([$mapping_id]);
        $map = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$map) {
            throw new Exception('Data mapping tidak ditemukan');
        }

        $pacs_acc        = $map['pacs_acc'];
        $pacs_mrn        = $map['pacs_mrn'];
        $no_rawat_single = $map['no_rawat'];

        $req = pacsGetOrder($pacs_acc, $pacs_mrn);
        $response  = $req['response'];
        $http_code = $req['http_code'];
        $error     = $req['error'];

        if ($http_code == 200 && $response) {
            $responseData = json_decode($response, true);
            $rawData = $responseData['data'] ?? $responseData;
            $ex = extractPacsOrderData(is_array($rawData) ? $rawData : []);

            $dicom_url = '';
            if (!empty($ex['study_uid']) && defined('DICOM_VIEWER_URL')) {
                $dicom_url = str_replace('{uid}', $ex['study_uid'], DICOM_VIEWER_URL);
            } elseif (!empty($rawData['link_dicom'])) {
                $dicom_url = $rawData['link_dicom'];
            }

            $stmtOld = $pdo->prepare("SELECT dicom_url, expertise_text FROM khanza_pacs_mapping WHERE id = ?");
            $stmtOld->execute([$mapping_id]);
            $old = $stmtOld->fetch(PDO::FETCH_ASSOC) ?: [];

            $final_dicom_url      = !empty($dicom_url) ? $dicom_url : ($old['dicom_url'] ?? '');
            $final_expertise_text = !empty($ex['expertise_text']) ? $ex['expertise_text'] : ($old['expertise_text'] ?? '');
            $reallyGotData = !empty($final_dicom_url) || !empty($final_expertise_text);

            $updateQuery = "UPDATE khanza_pacs_mapping
                             SET dicom_url = :dicom_url,
                                 expertise_url = :expertise_url,
                                 expertise_text = :expertise_text,
                                 procedure_name = :procedure_name,
                                 modality = :modality,
                                 radname = :radname,
                                 notes = :notes,
                                 updated_at = NOW()
                             WHERE id = :id";
            $stmtUpdate = $pdo->prepare($updateQuery);
            $stmtUpdate->execute([
                'dicom_url'      => $final_dicom_url,
                'expertise_url'  => $rawData['expertise_url'] ?? '',
                'expertise_text' => $final_expertise_text,
                'procedure_name' => $ex['prosedur'],
                'modality'       => $ex['modality'] ?: ($map['modality'] ?? ''),
                'radname'        => $ex['dokrad_name'],
                'notes'          => "Status: {$ex['report_status']} | Retrieved: " . date('Y-m-d H:i:s')
                                     . ($reallyGotData ? '' : ' | Data PACS ditemukan tapi imaging/laporan belum tersedia'),
                'id'             => $mapping_id
            ]);

            $pdo->commit();

            $hasilRadiologiErrorSingle = null;
            if (!empty($ex['expertise_text'])) {
                try {
                    $stmtAllExpertise = $pdo->prepare("SELECT pacs_acc, expertise_text FROM khanza_pacs_mapping WHERE no_rawat = ? AND expertise_text IS NOT NULL AND expertise_text != '' ORDER BY created_at ASC");
                    $stmtAllExpertise->execute([$no_rawat_single]);
                    $allExpertiseData = $stmtAllExpertise->fetchAll(PDO::FETCH_ASSOC);
                    $expertiseArray = [];
                    foreach ($allExpertiseData as $exp) {
                        $plain = trim(preg_replace('/<br\s*\/?>/i', "\n", $exp['expertise_text']));
                        $expertiseArray[] = "ACC: " . $exp['pacs_acc'] . "\n" . $plain;
                    }
                    $combined_expertise = implode("\n\n" . str_repeat("=", 50) . "\n\n", $expertiseArray);

                    $stmtTglJam = $pdo->prepare("SELECT tgl_periksa, jam FROM periksa_radiologi WHERE no_rawat = ? ORDER BY tgl_periksa DESC, jam DESC LIMIT 1");
                    $stmtTglJam->execute([$no_rawat_single]);
                    $tglJamData = $stmtTglJam->fetch(PDO::FETCH_ASSOC);
                    $tgl_hasil = $tglJamData['tgl_periksa'] ?? date('Y-m-d');
                    $jam_hasil = $tglJamData['jam'] ?? date('H:i:s');

                    if (empty($no_rawat_single)) {
                        throw new Exception('no_rawat kosong pada data mapping ini');
                    }

                    $stmtUpsertHasilSingle = $pdo->prepare("
                        INSERT INTO hasil_radiologi (no_rawat, tgl_periksa, jam, hasil)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            tgl_periksa = VALUES(tgl_periksa),
                            jam = VALUES(jam),
                            hasil = VALUES(hasil)
                    ");
                    $stmtUpsertHasilSingle->execute([$no_rawat_single, $tgl_hasil, $jam_hasil, $combined_expertise]);

                } catch (Exception $eHasil) {
                    $hasilRadiologiErrorSingle = $eHasil->getMessage();
                    error_log('[ajax_ambil_single] Gagal simpan hasil_radiologi: ' . $hasilRadiologiErrorSingle);
                }
            }

            if ($reallyGotData) {
                die(json_encode([
                    'status'  => 'success',
                    'message' => '✅ DICOM/Laporan berhasil ditarik dan tersimpan!' . ($hasilRadiologiErrorSingle ? "\n⚠️ (auto-save hasil_radiologi gagal: {$hasilRadiologiErrorSingle})" : ''),
                    'data'    => [
                        'dicom_url'      => $final_dicom_url,
                        'expertise_text' => $final_expertise_text,
                        'image_count'    => $ex['image_count'],
                        'status'         => $ex['report_status'],
                        'prosedur'       => $ex['prosedur'],
                        'dokrad_name'    => $ex['dokrad_name'],
                        'mrn'            => $pacs_mrn
                    ]
                ]));
            } else {
                die(json_encode([
                    'status'  => 'pending',
                    'message' => "⏳ Order ditemukan di PACS, tapi imaging/laporan belum tersedia.\nReport status: " . ($ex['report_status'] ?: 'unknown')
                ]));
            }

        } else if ($http_code == 401) {
            throw new Exception("🔒 Authentication Error (HTTP 401)\n\nToken API tidak valid atau expired.\n\nACC: {$pacs_acc}\nMRN: {$pacs_mrn}");
        } else if ($http_code == 404) {
            throw new Exception("⚠️ Data DICOM belum tersedia di PACS (HTTP 404)\n\nACC: {$pacs_acc}\nMRN: {$pacs_mrn}\n\nKemungkinan pemeriksaan belum selesai / ACC salah.");
        } else {
            $errorDetail = $error ?: 'Unknown error';
            throw new Exception("❌ PACS API Error (HTTP {$http_code})\n\n{$errorDetail}");
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die(json_encode([
            'status'  => 'error',
            'message' => $e->getMessage()
        ]));
    }
}

// ============================================
// AJAX HANDLER - CEK STATUS PACS SECARA LANGSUNG (LIVE) BERDASARKAN NO_RAWAT
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_cek_pacs_live_by_norawat'])) {
    header('Content-Type: application/json');
    try {
        $no_rawat_input = $_POST['no_rawat'] ?? '';
        if (!$no_rawat_input) {
            throw new Exception('no_rawat kosong');
        }

        $stmtOrders = $pdo->prepare("SELECT DISTINCT noorder FROM permintaan_radiologi WHERE no_rawat = ?");
        $stmtOrders->execute([$no_rawat_input]);
        $noorders = $stmtOrders->fetchAll(PDO::FETCH_COLUMN);

        if (count($noorders) == 0) {
            throw new Exception('Tidak ada permintaan radiologi untuk no_rawat ini');
        }

        $hasilCek = [];

        foreach ($noorders as $noorder_item) {
            $query = "SELECT ppr.noorder, ppr.kd_jenis_prw, jns.nm_perawatan,
                             pr.no_rawat, pas.no_rkm_medis, pas.nm_pasien
                      FROM permintaan_pemeriksaan_radiologi ppr
                      INNER JOIN jns_perawatan_radiologi jns ON ppr.kd_jenis_prw = jns.kd_jenis_prw
                      INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                      INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                      INNER JOIN pasien pas ON rp.no_rkm_medis = pas.no_rkm_medis
                      WHERE ppr.noorder = :noorder
                      ORDER BY ppr.kd_jenis_prw";
            $stmt = $pdo->prepare($query);
            $stmt->execute(['noorder' => $noorder_item]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($items) == 0) continue;

            $acc_base = substr($noorder_item, 4);
            if (!is_numeric($acc_base)) continue;

            $pemeriksaanValid = [];
            foreach ($items as $pemeriksaan) {
                $nm_perawatan = $pemeriksaan['nm_perawatan'] ?? '';
                if (stripos($nm_perawatan, 'USG') !== false) continue;
                $pemeriksaanValid[] = $pemeriksaan;
            }
            if (count($pemeriksaanValid) == 0) continue;

            $item0 = $items[0];
            $clean_patient_id = preg_replace('/[^0-9]/', '', $item0['no_rkm_medis'] ?? '');
            $clean_patient_id = str_pad($clean_patient_id, 6, '0', STR_PAD_LEFT);
            $pacs_mrn = $clean_patient_id;

            foreach ($pemeriksaanValid as $index => $px) {
                $acc_with_suffix = $acc_base . $index;

                $pacsResult = pacsCheckLiveDiskom($acc_with_suffix, $pacs_mrn);

                $rowResult = [
                    'acc' => $acc_with_suffix,
                    'mrn' => $pacs_mrn,
                    'noorder' => $noorder_item,
                    'nm_perawatan' => $px['nm_perawatan'],
                ];

                if ($pacsResult['found']) {
                    $ex = $pacsResult['data'];
                    $dicom_url = '';
                    if (!empty($ex['study_uid']) && defined('DICOM_VIEWER_URL')) {
                        $dicom_url = str_replace('{uid}', $ex['study_uid'], DICOM_VIEWER_URL);
                    }

                    $stmtFind = $pdo->prepare("SELECT id FROM khanza_pacs_mapping WHERE pacs_acc = ?");
                    $stmtFind->execute([$acc_with_suffix]);
                    $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);

                    if (!$existing) {
                        try {
                            $stmtInsertSync = $pdo->prepare("INSERT INTO khanza_pacs_mapping
                                (no_rawat, no_rkm_medis, nm_pasien, patientid, pacs_acc, pacs_mrn,
                                 status, dicom_url, expertise_text, procedure_name, modality, radname,
                                 notes, created_at)
                                VALUES
                                (:no_rawat, :no_rkm_medis, :nm_pasien, :patientid, :pacs_acc, :pacs_mrn,
                                 '1', :dicom_url, :expertise_text, :procedure_name, :modality, :radname,
                                 :notes, NOW())");
                            $stmtInsertSync->execute([
                                'no_rawat' => $item0['no_rawat'],
                                'no_rkm_medis' => $item0['no_rkm_medis'],
                                'nm_pasien' => $item0['nm_pasien'],
                                'patientid' => $clean_patient_id,
                                'pacs_acc' => $acc_with_suffix,
                                'pacs_mrn' => $pacs_mrn,
                                'dicom_url' => $dicom_url,
                                'expertise_text' => $ex['expertise_text'],
                                'procedure_name' => $ex['prosedur'],
                                'modality' => $ex['modality'],
                                'radname' => $ex['dokrad_name'],
                                'notes' => 'Auto-sync dari live check PACS (diskom_radiologi): ' . date('Y-m-d H:i:s')
                            ]);
                        } catch (Exception $eIns) {
                            error_log('[ajax_cek_pacs_live_by_norawat] Insert gagal: ' . $eIns->getMessage());
                        }
                    }

                    $rowResult['found_in_pacs'] = true;
                    $rowResult['message'] = $ex['has_report']
                        ? '✅ Ditemukan di PACS, laporan tersedia'
                        : '🟡 Ditemukan di PACS, laporan/imaging belum lengkap';
                } elseif ($pacsResult['not_found']) {
                    $rowResult['found_in_pacs'] = false;
                    $rowResult['message'] = '❌ Belum ditemukan di PACS';
                } else {
                    $rowResult['found_in_pacs'] = false;
                    $rowResult['error'] = true;
                    $rowResult['message'] = '⚠️ Gagal cek: ' . ($pacsResult['error'] ?: 'Unknown error');
                }

                $hasilCek[] = $rowResult;
            }
        }

        $anyFound = count(array_filter($hasilCek, function($r) { return !empty($r['found_in_pacs']); })) > 0;

        echo json_encode([
            'status' => 'success',
            'hasil' => $hasilCek,
            'any_found' => $anyFound
        ]);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// AMBIL DATA PEMERIKSAAN RADIOLOGI
// ============================================
$sql = "SELECT pr.*, p.nm_pasien, p.no_rkm_medis, rp.tgl_registrasi, pol.nm_poli,
               d1.nm_dokter as nm_dokter_perujuk, d2.nm_dokter as nm_dokter_pj,
               pt.nama as nm_petugas, jpr.nm_perawatan, pj.png_jawab,
               ki.kd_kamar, b.nm_bangsal,
               CASE WHEN ki.kd_kamar IS NOT NULL
                    THEN CONCAT('Kamar: ', ki.kd_kamar, ', ', b.nm_bangsal)
                    ELSE CONCAT('Poli: ', pol.nm_poli)
               END as lokasi_pelayanan
        FROM periksa_radiologi pr
        LEFT JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
        LEFT JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
        LEFT JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
        LEFT JOIN kamar_inap ki ON rp.no_rawat = ki.no_rawat AND ki.stts_pulang = '-'
        LEFT JOIN kamar k ON ki.kd_kamar = k.kd_kamar
        LEFT JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
        LEFT JOIN dokter d1 ON pr.dokter_perujuk = d1.kd_dokter
        LEFT JOIN dokter d2 ON pr.kd_dokter = d2.kd_dokter
        LEFT JOIN petugas pt ON pr.nip = pt.nip
        LEFT JOIN jns_perawatan_radiologi jpr ON pr.kd_jenis_prw = jpr.kd_jenis_prw
        LEFT JOIN penjab pj ON rp.kd_pj = pj.kd_pj
        WHERE pr.no_rawat = ?";

if ($tgl_periksa) {
    $sql .= " AND DATE(pr.tgl_periksa) = ?";
    $params = [$no_rawat, $tgl_periksa];
} else {
    $params = [$no_rawat];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($data) == 0) {
    die('Data tidak ditemukan');
}
$pasien_info = $data[0];

// ============================================
// AMBIL DATA PACS MAPPING
// ============================================
$stmtMapping = $pdo->prepare("SELECT * FROM khanza_pacs_mapping WHERE no_rawat = ? ORDER BY created_at DESC");
$stmtMapping->execute([$no_rawat]);
$pacs_mappings = $stmtMapping->fetchAll(PDO::FETCH_ASSOC);

$hasPendingMapping = false;
foreach ($pacs_mappings as $map) {
    if (empty($map['dicom_url'])) {
        $hasPendingMapping = true;
        break;
    }
}

$combined_expertise = [];
foreach ($pacs_mappings as $map) {
    if (!empty($map['expertise_text'])) {
        $plain = trim(preg_replace('/<br\s*\/?>/i', "\n", $map['expertise_text']));
        $combined_expertise[] = "ACC: " . $map['pacs_acc'] . "\n" . $plain;
    }
}
$hasil_bacaan_default = implode("\n\n" . str_repeat("=", 50) . "\n\n", $combined_expertise);

$stmtHasilRadiologi = $pdo->prepare("SELECT hasil FROM hasil_radiologi WHERE no_rawat = ?");
$stmtHasilRadiologi->execute([$no_rawat]);
$hasilRadiologiData = $stmtHasilRadiologi->fetch(PDO::FETCH_ASSOC);
if ($hasilRadiologiData && !empty($hasilRadiologiData['hasil'])) {
    $hasil_bacaan_default = $hasilRadiologiData['hasil'];
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Detail Pemeriksaan Radiologi - <?= htmlspecialchars($pasien_info['nm_pasien']) ?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, sans-serif; background: #f8f9fa; padding: 20px; font-size: 12px; }
.info-header { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px; }
.info-header h2 { color: #2c3e50; margin-bottom: 15px; font-size: 18px; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
.info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
.info-item { display: flex; }
.info-label { font-weight: bold; color: #34495e; width: 150px; flex-shrink: 0; }
.info-value { color: #2c3e50; }
.tab-container { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; }
.tab-buttons { display: flex; background: #ecf0f1; border-bottom: 2px solid #bdc3c7; }
.tab-btn { flex: 1; padding: 12px 20px; background: transparent; border: none; cursor: pointer; font-size: 13px; font-weight: 600; color: #7f8c8d; transition: all 0.3s; }
.tab-btn:hover { background: #d5dbdb; }
.tab-btn.active { background: white; color: #3498db; border-bottom: 3px solid #3498db; }
.tab-content-area { padding: 20px; }
.tab-pane { display: none; }
.tab-pane.active { display: block; animation: fadeIn 0.3s; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.mapping-list { display: flex; flex-direction: column; gap: 15px; }
.mapping-item { background: white; border: 2px solid #ecf0f1; border-radius: 8px; overflow: hidden; transition: all 0.3s; }
.mapping-item:hover { border-color: #3498db; box-shadow: 0 4px 12px rgba(52, 152, 219, 0.2); }
.mapping-header { background: #ecf0f1; padding: 12px 15px; display: flex; justify-content: space-between; align-items: center; }
.mapping-title { font-size: 13px; color: #2c3e50; flex: 1; }
.procedure-name { color: #16a085; font-weight: 600; margin-left: 5px; }
.mapping-status { padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: bold; margin-left: 10px; }
.status-retrieved { background: #d5f4e6; color: #0b5345; }
.status-pending { background: #fef5e7; color: #7d6608; }

.mapping-actions { padding: 12px 15px; display: flex; gap: 8px; flex-wrap: wrap; }
.btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 600; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; justify-content: center; text-decoration: none; }
.btn-sm { padding: 6px 12px; font-size: 11px; }
.btn-ambil { background: #27ae60; color: white; }
.btn-ambil:hover { background: #229954; transform: translateY(-2px); }
.btn-refresh { background: #3498db; color: white; }
.btn-refresh:hover { background: #2980b9; transform: translateY(-2px); }
.btn-viewer { background: #9b59b6; color: white; }
.btn-viewer:hover { background: #8e44ad; }
.btn-update { background: #f39c12; color: white; }
.btn-update:hover { background: #e67e22; }
.btn:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-live-check { background: #16a085; color: white; }
.btn-live-check:hover { background: #138d75; transform: translateY(-2px); }

.modal-overlay { 
    display: none; 
    position: fixed; 
    top: 0; 
    left: 0; 
    width: 100%; 
    height: 100%; 
    background: rgba(0,0,0,0.6); 
    z-index: 9999; 
    animation: fadeIn 0.3s ease;
}
.modal-overlay.show { display: flex; align-items: center; justify-content: center; }
.modal-container { 
    background: white; 
    border-radius: 10px; 
    max-width: 800px; 
    width: 90%; 
    max-height: 90vh; 
    overflow-y: auto; 
    box-shadow: 0 10px 40px rgba(0,0,0,0.3); 
    animation: slideIn 0.3s ease;
}
@keyframes slideIn { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.modal-header { 
    background: #3498db; 
    color: white; 
    padding: 15px 20px; 
    border-radius: 10px 10px 0 0; 
    display: flex; 
    justify-content: space-between; 
    align-items: center;
}
.modal-header h3 { margin: 0; font-size: 16px; }
.modal-close { 
    background: transparent; 
    border: none; 
    color: white; 
    font-size: 24px; 
    cursor: pointer; 
    padding: 0; 
    width: 30px; 
    height: 30px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    border-radius: 50%;
    transition: background 0.3s;
}
.modal-close:hover { background: rgba(255,255,255,0.2); }
.modal-body { padding: 20px; }
.modal-info-grid { 
    display: grid; 
    grid-template-columns: repeat(3, 1fr); 
    gap: 15px; 
    margin-bottom: 20px; 
    background: #f8f9fa; 
    padding: 15px; 
    border-radius: 8px;
}
.modal-info-item { display: flex; flex-direction: column; }
.modal-info-label { font-size: 11px; color: #7f8c8d; font-weight: 600; margin-bottom: 5px; }
.modal-info-value { font-size: 13px; color: #2c3e50; font-weight: 500; }
.modal-hasil { 
    background: #fff; 
    border: 1px solid #ddd; 
    border-radius: 6px; 
    padding: 15px; 
    max-height: 400px; 
    overflow-y: auto; 
    white-space: pre-wrap; 
    font-family: 'Courier New', monospace; 
    font-size: 12px; 
    line-height: 1.6; 
    color: #2c3e50;
}
.modal-hasil:empty::before { 
    content: "Tidak ada hasil bacaan radiologi"; 
    color: #95a5a6; 
    font-style: italic;
}
.btn-hasil-bacaan { background: #16a085; color: white; }
.btn-hasil-bacaan:hover { background: #138d75; transform: translateY(-2px); }

.orthanc-container { display: flex; gap: 20px; }
.orthanc-left { width: 250px; }
.orthanc-right { flex: 1; }
.orthanc-tabs { display: flex; flex-direction: column; gap: 5px; }
.orthanc-tab-btn { padding: 10px 15px; background: #ecf0f1; border: none; text-align: left; cursor: pointer; font-size: 12px; border-left: 3px solid transparent; transition: all 0.3s; }
.orthanc-tab-btn:hover { background: #d5dbdb; }
.orthanc-tab-btn.active { background: white; border-left-color: #3498db; font-weight: bold; color: #3498db; }
.orthanc-content { background: #f8f9fa; padding: 20px; border-radius: 8px; min-height: 400px; }
.orthanc-pane { display: none; }
.orthanc-pane.active { display: block; }
.orthanc-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
.orthanc-field { display: flex; flex-direction: column; }
.orthanc-field label { font-size: 11px; color: #7f8c8d; margin-bottom: 5px; font-weight: 600; }
.orthanc-field input { padding: 8px; border: 1px solid #bdc3c7; border-radius: 4px; font-size: 12px; background: white; }
.btn-tampilkan-dicom { background: #9b59b6; color: white; margin-top: 15px; width: 100%; }
.btn-tampilkan-dicom:hover { background: #8e44ad; }

@media (max-width: 768px) {
  .info-grid, .form-row, .orthanc-grid { grid-template-columns: 1fr; }
  .orthanc-container { flex-direction: column; }
  .orthanc-left { width: 100%; }
}

.toast-container{
  position: fixed; top: 16px; right: 16px; z-index: 99999;
  display: flex; flex-direction: column; gap: 10px; max-width: 420px;
}
.toast{
  background: #2c3e50; color: #ecf0f1; padding: 12px 14px; border-radius: 10px;
  box-shadow: 0 8px 20px rgba(0,0,0,.25);
  font-size: 12px; line-height: 1.35; overflow: hidden;
  border-left: 6px solid #3498db; opacity: 0; transform: translateY(-6px);
  transition: all .18s ease;
}
.toast.show{ opacity: 1; transform: translateY(0); }
.toast.success{ border-left-color: #27ae60; }
.toast.error{ border-left-color: #e74c3c; }
.toast.info{ border-left-color: #3498db; }
.toast.warning{ border-left-color: #f39c12; }
.toast .title{ font-weight: 700; margin-bottom: 6px; font-size: 12px; }
.toast .close{
  float: right; border: none; background: transparent; color: #ecf0f1;
  cursor: pointer; font-size: 14px; line-height: 14px; opacity: .7;
}
.toast .close:hover{ opacity: 1; }

#pacsLiveResultDiskom{
  margin-top: 15px; text-align: left; font-size: 12px;
}
</style>
</head>
<body>

<div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>

<div class="info-header">
    <h2>📋 Informasi Pasien</h2>
    <div class="info-grid">
        <div class="info-item"><span class="info-label">No.Rawat:</span><span class="info-value"><?= htmlspecialchars($pasien_info['no_rawat']) ?></span></div>
        <div class="info-item"><span class="info-label">No.RM:</span><span class="info-value"><?= htmlspecialchars($pasien_info['no_rkm_medis']) ?></span></div>
        <div class="info-item"><span class="info-label">Pasien:</span><span class="info-value"><?= htmlspecialchars($pasien_info['nm_pasien']) ?></span></div>
        <div class="info-item"><span class="info-label">Tanggal:</span><span class="info-value"><?= date('d-m-Y', strtotime($pasien_info['tgl_registrasi'])) ?> | <?= date('H:i:s', strtotime($pasien_info['jam'])) ?></span></div>
        <div class="info-item"><span class="info-label">Lokasi:</span><span class="info-value"><?= htmlspecialchars($pasien_info['lokasi_pelayanan']) ?></span></div>
        <div class="info-item"><span class="info-label">Cara Bayar:</span><span class="info-value"><?= htmlspecialchars($pasien_info['png_jawab'] ?? 'UMUM') ?></span></div>
    </div>
</div>

<div class="tab-container">
    <div class="tab-buttons">
        <button class="tab-btn active" onclick="switchMainTab('photo')">📷 Photo Radiologi</button>
        <button class="tab-btn" onclick="switchMainTab('integrasi')">🔗 Integrasi Orthanc</button>
    </div>

    <div class="tab-content-area">
        <div id="photo" class="tab-pane active">
            <div style="margin-bottom: 20px; background: #f8f9fa; padding: 15px; border-radius: 5px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h4 style="margin: 0 0 5px 0; color: #2c3e50;">📊 Status PACS Mapping</h4>
                        <p style="margin: 0; font-size: 12px; color: #7f8c8d;">
                            <?php if (count($pacs_mappings) == 0): ?>
                                ⚠️ Belum ada data yang dikirim ke PACS. Silakan kirim data terlebih dahulu dari form input hasil radiologi.
                            <?php else: ?>
                                Total: <strong><?= count($pacs_mappings) ?></strong> pemeriksaan |
                                Tersedia: <strong id="countTersedia">0</strong> |
                                Belum: <strong id="countBelum"><?= count($pacs_mappings) ?></strong>
                            <?php endif; ?>
                        </p>
                        <p id="pacsStatusLine" style="margin: 4px 0 0 0; font-size: 11px; color: #16a085;"></p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <?php if (count($pacs_mappings) > 0): ?>
                        <button class="btn btn-ambil" onclick="ambilSemuaDICOM()" id="btnAmbilSemua">⬇️ Ambil Semua DICOM</button>
                        <button class="btn btn-refresh" onclick="ambilSemuaDICOM()" id="btnRefreshData">🔄 Refresh Data</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (count($pacs_mappings) > 0): ?>
            <div class="mapping-list">
                <?php foreach ($pacs_mappings as $map):
                    $hasData = !empty($map['dicom_url']);
                    $statusClass = $hasData ? 'status-retrieved' : 'status-pending';
                    $statusText = $hasData ? '✅ Data Tersedia' : '⏳ Belum Ditarik';
                    $procedureName = $map['procedure_name'] ?? '';
                ?>
                <div class="mapping-item" id="mapping-<?= $map['id'] ?>" data-acc="<?= htmlspecialchars($map['pacs_acc']) ?>" data-mrn="<?= htmlspecialchars($map['pacs_mrn']) ?>">
                    <div class="mapping-header">
                        <div class="mapping-title">
                            📋 ACC: <strong><?= htmlspecialchars($map['pacs_acc']) ?></strong> |
                            MRN: <strong><?= htmlspecialchars($map['pacs_mrn']) ?></strong>
                            <?php if (!empty($procedureName)): ?>
                            | <span class="procedure-name" id="procedure-<?= $map['id'] ?>"><?= htmlspecialchars($procedureName) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="mapping-status <?= $statusClass ?>" id="status-<?= $map['id'] ?>"><?= $statusText ?></span>
                    </div>
                    <div class="mapping-actions" id="actions-<?= $map['id'] ?>">
                        <?php if ($hasData && !empty($map['dicom_url'])): ?>
                            <a href="<?= htmlspecialchars($map['dicom_url']) ?>" target="_blank" class="btn btn-sm btn-viewer btn-viewer-static" data-mapping-id="<?= $map['id'] ?>" data-acc-val="<?= htmlspecialchars($map['pacs_acc']) ?>" data-mrn-val="<?= htmlspecialchars($map['pacs_mrn']) ?>">🖼️ Buka DICOM Viewer</a>
                            <?php if (!empty($map['expertise_text'])): ?>
                            <button class="btn btn-sm btn-hasil-bacaan btn-hasil-static" data-mapping-id="<?= $map['id'] ?>">📝 Hasil Bacaan Radiologi</button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button class="btn btn-sm btn-ambil" id="btn-<?= $map['id'] ?>" onclick="ambilSingleDICOM(<?= $map['id'] ?>, '<?= htmlspecialchars($map['pacs_acc']) ?>')">⬇️ Ambil Data DICOM</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 30px; color: #95a5a6;">
                <p style="font-size: 16px; margin-bottom: 10px;">📭 Belum ada data mapping PACS</p>
                <p style="font-size: 12px; margin-bottom: 20px;">Silakan kirim data ke PACS terlebih dahulu dari form input hasil radiologi,<br>atau cek langsung ke PACS jika Anda yakin data sudah pernah dikirim.</p>
                <button class="btn btn-live-check" onclick="cekPacsLiveByNoRawat()" id="btnCekPacsLive">🔍 Cek PACS Sekarang</button>
                <div id="pacsLiveResultDiskom"></div>
            </div>
            <?php endif; ?>
        </div>

        <div id="integrasi" class="tab-pane">
            <div class="orthanc-container">
                <div class="orthanc-left">
                    <div class="orthanc-tabs">
                        <button class="orthanc-tab-btn active" onclick="switchOrthancTab('uuid')">UUID Pasien</button>
                        <button class="orthanc-tab-btn" onclick="switchOrthancTab('idstudies')">ID Studies</button>
                        <button class="orthanc-tab-btn" onclick="switchOrthancTab('idseries')">ID Series</button>
                    </div>
                </div>

                <div class="orthanc-right">
                    <div class="orthanc-content">
                        <div id="uuid" class="orthanc-pane active">
                            <h3 style="margin-bottom:15px; color:#2c3e50;">UUID Pasien</h3>
                            <div class="orthanc-grid">
                                <div class="orthanc-field"><label>No.Rawat</label><input type="text" value="<?= htmlspecialchars($pasien_info['no_rawat']) ?>" readonly></div>
                                <div class="orthanc-field"><label>No.RM</label><input type="text" value="<?= htmlspecialchars($pasien_info['no_rkm_medis']) ?>" readonly></div>
                                <div class="orthanc-field"><label>Nama Pasien</label><input type="text" value="<?= htmlspecialchars($pasien_info['nm_pasien']) ?>" readonly></div>
                                <div class="orthanc-field"><label>UUID</label><input type="text" placeholder="UUID akan muncul di sini" readonly></div>
                            </div>
                        </div>

                        <div id="idstudies" class="orthanc-pane">
                            <h3 style="margin-bottom:15px; color:#2c3e50;">ID Studies</h3>
                            <div class="orthanc-grid">
                                <div class="orthanc-field"><label>Study Instance UID</label><input type="text" placeholder="Study UID" readonly></div>
                                <div class="orthanc-field"><label>Study Date</label><input type="text" placeholder="Study Date" readonly></div>
                                <div class="orthanc-field"><label>Study Description</label><input type="text" placeholder="Description" readonly></div>
                                <div class="orthanc-field"><label>Modality</label><input type="text" placeholder="Modality" readonly></div>
                            </div>
                        </div>

                        <div id="idseries" class="orthanc-pane">
                            <h3 style="margin-bottom:15px; color:#2c3e50;">ID Series</h3>
                            <div class="orthanc-grid">
                                <div class="orthanc-field"><label>Series Instance UID</label><input type="text" placeholder="Series UID" readonly></div>
                                <div class="orthanc-field"><label>Series Number</label><input type="text" placeholder="Series Number" readonly></div>
                                <div class="orthanc-field"><label>Series Description</label><input type="text" placeholder="Description" readonly></div>
                                <div class="orthanc-field"><label>Number of Images</label><input type="text" placeholder="Image Count" readonly></div>
                            </div>
                        </div>

                        <button class="btn btn-tampilkan-dicom">📊 Tampilkan DICOM</button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// ============================================
// KUNCI PROSES GLOBAL -- v2.6
// Mencegah 2 operasi PACS (auto-trigger vs klik manual) jalan bersamaan,
// yang sebelumnya menyebabkan tombol/status saling menimpa ("ngaco").
// ============================================
let pacsOperationLock = false;

function showToast(message, type = 'info', timeout = 3500, title = null) {
  const container = document.getElementById('toastContainer');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;

  const safeMsg = String(message || '').replace(/<[^>]*>/g, '');
  const ttl = title || (type === 'success' ? 'Berhasil' :
                       type === 'error' ? 'Gagal' :
                       type === 'warning' ? 'Peringatan' : 'Info');

  const closeBtn = document.createElement('button');
  closeBtn.className = 'close';
  closeBtn.title = 'Tutup';
  closeBtn.textContent = '×';
  closeBtn.addEventListener('click', function() { toast.remove(); });

  const titleDiv = document.createElement('div');
  titleDiv.className = 'title';
  titleDiv.textContent = ttl;

  const msgDiv = document.createElement('div');
  msgDiv.className = 'msg';
  msgDiv.style.whiteSpace = 'pre-wrap';
  msgDiv.textContent = safeMsg;

  toast.appendChild(closeBtn);
  toast.appendChild(titleDiv);
  toast.appendChild(msgDiv);

  container.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('show'));

  if (timeout && timeout > 0) {
    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 200);
    }, timeout);
  }
}

function checkExpertiseBeforeOpen(e, mappingId, pacsAcc, pacsMrn) {
  e.preventDefault();

  const btn = e.currentTarget;
  const originalText = btn.textContent;
  btn.textContent = '⏳ Memuat...';
  btn.style.pointerEvents = 'none';

  fetch('', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: `ajax_check_expertise=1&mapping_id=${encodeURIComponent(mappingId)}&pacs_acc=${encodeURIComponent(pacsAcc)}&pacs_mrn=${encodeURIComponent(pacsMrn)}`
  })
  .then(r => r.json())
  .then(data => {
    btn.textContent = originalText;
    btn.style.pointerEvents = 'auto';

    if (data.status === 'success') {
      const hasDicomUrl = data.has_dicom_url;
      const hasExpertiseText = data.has_expertise_text;
      const dicomUrl = data.dicom_url;

      if (hasDicomUrl && hasExpertiseText) {
        window.open(dicomUrl, '_blank');
      } else {
        let message = 'Gambar Radiologi Pasien Belum Siap\n\n';
        if (!hasDicomUrl && !hasExpertiseText) {
          message += 'Link DICOM dan Expertise belum tersedia.';
        } else if (!hasDicomUrl) {
          message += 'Link DICOM belum tersedia.';
        } else if (!hasExpertiseText) {
          message += 'Expertise belum tersedia.\nMohon tunggu beberapa saat.';
        }
        showToast(message, 'warning', 2500, '⏳ Sedang Diproses');
      }
    } else {
      showToast(data.message || 'Terjadi kesalahan saat memeriksa data', 'error');
    }
  })
  .catch(err => {
    console.error('Error checking expertise:', err);
    btn.textContent = originalText;
    btn.style.pointerEvents = 'auto';
    showToast('Terjadi kesalahan koneksi', 'error');
  });

  return false;
}

function switchMainTab(tabId) {
  document.querySelectorAll('.tab-pane').forEach(tab => tab.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
  document.getElementById(tabId).classList.add('active');
  event.target.classList.add('active');
}

function switchOrthancTab(tabId) {
  document.querySelectorAll('.orthanc-pane').forEach(pane => pane.classList.remove('active'));
  document.querySelectorAll('.orthanc-tab-btn').forEach(btn => btn.classList.remove('active'));
  document.getElementById(tabId).classList.add('active');
  event.target.classList.add('active');
}

/**
 * Bangun tombol "Buka DICOM Viewer" + "Hasil Bacaan Radiologi" dengan
 * DOM API murni (bukan string HTML), supaya tidak ada risiko karakter
 * spesial (acc/mrn/dicom_url) merusak atribut onclick.
 */
function renderMappingActions(mappingId, dicomUrl, acc, mrn, expertiseText) {
  const actionsDiv = document.getElementById('actions-' + mappingId);
  if (!actionsDiv) return;
  actionsDiv.innerHTML = '';

  const viewerLink = document.createElement('a');
  viewerLink.href = dicomUrl;
  viewerLink.target = '_blank';
  viewerLink.className = 'btn btn-sm btn-viewer';
  viewerLink.textContent = '🖼️ Buka DICOM Viewer';
  viewerLink.addEventListener('click', function(e) {
    return checkExpertiseBeforeOpen(e, mappingId, acc, mrn);
  });
  actionsDiv.appendChild(viewerLink);

  if (expertiseText) {
    const hasilBtn = document.createElement('button');
    hasilBtn.className = 'btn btn-sm btn-hasil-bacaan';
    hasilBtn.textContent = '📝 Hasil Bacaan Radiologi';
    hasilBtn.addEventListener('click', function() {
      showHasilBacaan(String(mappingId));
    });
    actionsDiv.appendChild(hasilBtn);
  }
}

/**
 * Pasang event listener untuk tombol viewer/hasil-bacaan yang di-render
 * langsung dari PHP saat page load pertama kali (class btn-viewer-static
 * dan btn-hasil-static).
 */
function bindStaticMappingButtons() {
  document.querySelectorAll('.btn-viewer-static').forEach(function(link) {
    link.addEventListener('click', function(e) {
      const mappingId = this.dataset.mappingId;
      const acc = this.dataset.accVal;
      const mrn = this.dataset.mrnVal;
      return checkExpertiseBeforeOpen(e, mappingId, acc, mrn);
    });
  });
  document.querySelectorAll('.btn-hasil-static').forEach(function(btn) {
    btn.addEventListener('click', function() {
      showHasilBacaan(this.dataset.mappingId);
    });
  });
}

// ============================================
// AMBIL SEMUA DICOM -- pakai kunci proses, tidak reload halaman
// ============================================
function ambilSemuaDICOM(silent = false) {
  if (pacsOperationLock) {
    if (!silent) showToast('Sedang ada proses PACS berjalan, mohon tunggu sebentar...', 'info', 2500);
    return;
  }
  pacsOperationLock = true;

  const btnAmbil = document.getElementById('btnAmbilSemua');
  const btnRefresh = document.getElementById('btnRefreshData');
  const originalAmbilText = btnAmbil ? btnAmbil.textContent : null;
  const originalRefreshText = btnRefresh ? btnRefresh.textContent : null;

  if (btnAmbil) { btnAmbil.disabled = true; btnAmbil.textContent = '⏳ Mengambil data...'; }
  if (btnRefresh) { btnRefresh.disabled = true; }

  const statusLine = document.getElementById('pacsStatusLine');
  if (statusLine) statusLine.textContent = '⏳ Mengecek ke PACS...';

  const noRawat = '<?= htmlspecialchars($pasien_info['no_rawat'], ENT_QUOTES) ?>';
  const noRkmMedis = '<?= htmlspecialchars($pasien_info['no_rkm_medis'], ENT_QUOTES) ?>';

  fetch('', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: `ajax_ambil_pacs=1&no_rawat=${encodeURIComponent(noRawat)}&no_rkm_medis=${encodeURIComponent(noRkmMedis)}`
  })
  .then(r => r.json())
  .then(data => {
    if (data.status === 'success') {
      if (data.expertise_combined) {
        const hasilBacaanEl = document.getElementById('hasilBacaan');
        if (hasilBacaanEl) hasilBacaanEl.value = data.expertise_combined;
      }

      if (Array.isArray(data.hasil)) {
        data.hasil.forEach(item => updateMappingItemDOM(item));
      }

      updateCounter();
      if (statusLine) statusLine.textContent = '';

      if (!silent) {
        const msg = (data.message || 'Selesai.')
          .replace(/<br>/g, '\n')
          .replace(/<[^>]*>/g, '');
        showToast(msg, 'success', 3500, 'Pull DICOM');
      } else if (data.sukses > 0) {
        showToast(`✅ ${data.sukses} pemeriksaan berhasil diperbarui dari PACS`, 'success', 3000, 'Auto-Check PACS');
      }
    } else {
      if (statusLine) statusLine.textContent = '';
      if (!silent) {
        showToast(data.message || 'Terjadi kesalahan.', 'error', 7000, 'Pull DICOM gagal');
      } else {
        console.warn('Auto-check PACS gagal:', data.message);
      }
    }
  })
  .catch(err => {
    if (statusLine) statusLine.textContent = '';
    if (!silent) showToast('Error: ' + err.message, 'error', 8000, 'Network/Server');
    else console.warn('Auto-check PACS error:', err.message);
  })
  .finally(() => {
    pacsOperationLock = false;
    if (btnAmbil) { btnAmbil.disabled = false; btnAmbil.textContent = originalAmbilText; }
    if (btnRefresh) { btnRefresh.disabled = false; btnRefresh.textContent = originalRefreshText; }
  });
}

function updateMappingItemDOM(item) {
  const container = document.querySelector(`.mapping-item[data-acc="${item.acc}"]`);
  if (!container) return;

  const statusBadge = container.querySelector('.mapping-status');
  const mappingId = container.id.replace('mapping-', '');
  const pacsMrn = container.dataset.mrn || item.mrn || '';

  if (item.status === 'success' && item.dicom_url) {
    if (statusBadge) {
      statusBadge.textContent = '✅ Data Tersedia';
      statusBadge.className = 'mapping-status status-retrieved';
    }

    if (item.prosedur) {
      const mappingTitle = container.querySelector('.mapping-title');
      let procedureSpan = document.getElementById('procedure-' + mappingId);
      if (procedureSpan) {
        procedureSpan.textContent = item.prosedur;
      } else if (mappingTitle) {
        mappingTitle.appendChild(document.createTextNode(' | '));
        procedureSpan = document.createElement('span');
        procedureSpan.id = 'procedure-' + mappingId;
        procedureSpan.className = 'procedure-name';
        procedureSpan.textContent = item.prosedur;
        mappingTitle.appendChild(procedureSpan);
      }
    }

    const currentData = pacsDataMap.get(mappingId) || {};
    if (typeof item.expertise_text !== 'undefined') {
      currentData.expertise_text = item.expertise_text || currentData.expertise_text || '';
    }
    pacsDataMap.set(mappingId, currentData);

    renderMappingActions(mappingId, item.dicom_url, item.acc, pacsMrn, currentData.expertise_text);
  } else {
    if (statusBadge && !statusBadge.classList.contains('status-retrieved')) {
      statusBadge.textContent = '⏳ Belum Ditarik';
      statusBadge.className = 'mapping-status status-pending';
    }
  }
}

function ambilSingleDICOM(mappingId, acc) {
  if (pacsOperationLock) {
    showToast('Sedang ada proses PACS berjalan, mohon tunggu sebentar...', 'info', 2500);
    return;
  }
  pacsOperationLock = true;

  const btn = document.getElementById('btn-' + mappingId);
  const originalText = btn.textContent;
  btn.disabled = true;
  btn.textContent = '⏳ Loading...';

  const container = document.getElementById('mapping-' + mappingId);
  const pacsMrnFallback = container ? (container.dataset.mrn || '') : '';

  fetch('', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: `ajax_ambil_single=1&mapping_id=${encodeURIComponent(mappingId)}`
  })
  .then(r => r.json())
  .then(data => {
    if (data.status === 'success') {
      const statusBadge = document.getElementById('status-' + mappingId);
      statusBadge.textContent = '✅ Data Tersedia';
      statusBadge.className = 'mapping-status status-retrieved';

      if (data.data && data.data.prosedur) {
        const mappingTitle = document.querySelector('#mapping-' + mappingId + ' .mapping-title');
        let procedureSpan = document.getElementById('procedure-' + mappingId);
        if (procedureSpan) {
          procedureSpan.textContent = data.data.prosedur;
        } else if (mappingTitle) {
          mappingTitle.appendChild(document.createTextNode(' | '));
          procedureSpan = document.createElement('span');
          procedureSpan.id = 'procedure-' + mappingId;
          procedureSpan.className = 'procedure-name';
          procedureSpan.textContent = data.data.prosedur;
          mappingTitle.appendChild(procedureSpan);
        }
      }

      const currentData = pacsDataMap.get(String(mappingId)) || {};
      currentData.expertise_text = data.data.expertise_text || '';
      pacsDataMap.set(String(mappingId), currentData);

      const pacsMrn = (data.data && data.data.mrn) ? data.data.mrn : pacsMrnFallback;
      renderMappingActions(mappingId, data.data.dicom_url, acc, pacsMrn, currentData.expertise_text);

      if (data.data && data.data.expertise_text) {
        const hasilBacaanEl = document.getElementById('hasilBacaan');
        if (hasilBacaanEl) {
          const currentText = hasilBacaanEl.value || '';
          const newExpertise = 'ACC: ' + acc + '\n' + data.data.expertise_text;
          if (currentText.trim() === '' || currentText === 'Hasil bacaan radiologi akan muncul di sini setelah data DICOM ditarik...') {
            hasilBacaanEl.value = newExpertise;
          } else if (!currentText.includes('ACC: ' + acc)) {
            hasilBacaanEl.value = currentText + '\n\n' + '='.repeat(50) + '\n\n' + newExpertise;
          }
        }
      }

      updateCounter();
      showToast(data.message || 'DICOM berhasil ditarik.', 'success', 3500, 'Pull DICOM');
    } else {
      showToast(data.message || 'Data belum tersedia.', 'warning', 6500, 'PACS');
    }
  })
  .catch(err => showToast('Error: ' + err.message, 'error', 8000, 'Network/Server'))
  .finally(() => {
    pacsOperationLock = false;
    btn.disabled = false;
    btn.textContent = originalText;
  });
}

function updateCounter() {
  const total = <?= count($pacs_mappings) ?>;
  const tersedia = document.querySelectorAll('.status-retrieved').length;
  const belum = total - tersedia;

  const countTersedia = document.getElementById('countTersedia');
  const countBelum = document.getElementById('countBelum');

  if (countTersedia) countTersedia.textContent = tersedia;
  if (countBelum) countBelum.textContent = belum;
}

function cekPacsLiveByNoRawat(forceClick = true) {
  if (pacsOperationLock) {
    if (forceClick) showToast('Sedang ada proses PACS berjalan, mohon tunggu sebentar...', 'info', 2500);
    return;
  }
  pacsOperationLock = true;

  const noRawat = '<?= htmlspecialchars($pasien_info['no_rawat'], ENT_QUOTES) ?>';
  const btn = document.getElementById('btnCekPacsLive');
  const resultDiv = document.getElementById('pacsLiveResultDiskom');

  if (btn) { btn.disabled = true; btn.textContent = '⏳ Mengecek...'; }
  if (resultDiv) resultDiv.innerHTML = '';
  if (resultDiv) {
    const loadingDiv = document.createElement('div');
    loadingDiv.style.textAlign = 'center';
    loadingDiv.style.color = '#888';
    loadingDiv.textContent = '⏳ Mengecek langsung ke PACS...';
    resultDiv.appendChild(loadingDiv);
  }

  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'ajax_cek_pacs_live_by_norawat=1&no_rawat=' + encodeURIComponent(noRawat)
  })
  .then(r => r.json())
  .then(data => {
    if (btn) { btn.disabled = false; btn.textContent = '🔍 Cek PACS Sekarang'; }
    if (!resultDiv) return;
    resultDiv.innerHTML = '';

    if (data.status !== 'success') {
      const errDiv = document.createElement('div');
      errDiv.style.color = '#c0392b';
      errDiv.textContent = '⚠️ ' + (data.message || 'Gagal cek PACS');
      resultDiv.appendChild(errDiv);
      return;
    }
    if (!data.hasil || data.hasil.length === 0) {
      const infoDiv = document.createElement('div');
      infoDiv.style.color = '#888';
      infoDiv.textContent = data.message || 'Tidak ada pemeriksaan untuk dicek';
      resultDiv.appendChild(infoDiv);
      return;
    }

    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;flex-direction:column;gap:6px;font-size:12px;text-align:left;max-width:600px;margin:0 auto';
    data.hasil.forEach(item => {
      const color = item.found_in_pacs ? '#1b5e20' : (item.error ? '#c0392b' : '#616161');
      const row = document.createElement('div');
      row.style.color = color;
      const b = document.createElement('b');
      b.textContent = item.nm_perawatan;
      row.appendChild(b);
      row.appendChild(document.createTextNode(' (ACC: ' + item.acc + ') — ' + item.message));
      wrap.appendChild(row);
    });
    resultDiv.appendChild(wrap);

    if (data.any_found) {
      const reloadBtnWrap = document.createElement('div');
      reloadBtnWrap.style.cssText = 'margin-top:10px;text-align:center';
      const reloadBtn = document.createElement('button');
      reloadBtn.className = 'btn btn-refresh';
      reloadBtn.textContent = '🔄 Muat Ulang Halaman';
      reloadBtn.addEventListener('click', function() { location.reload(); });
      reloadBtnWrap.appendChild(reloadBtn);
      resultDiv.appendChild(reloadBtnWrap);
      if (forceClick) showToast('Data ditemukan di PACS & sudah disinkronkan!', 'success', 4000);
    } else if (forceClick) {
      showToast('Cek selesai, belum ada data baru di PACS', 'info', 3500);
    }
  })
  .catch(err => {
    if (btn) { btn.disabled = false; btn.textContent = '🔍 Cek PACS Sekarang'; }
    if (resultDiv) {
      resultDiv.innerHTML = '';
      const errDiv = document.createElement('div');
      errDiv.style.color = '#c0392b';
      errDiv.textContent = '⚠️ Koneksi gagal: ' + err.message;
      resultDiv.appendChild(errDiv);
    }
  })
  .finally(() => {
    pacsOperationLock = false;
  });
}

// ============================================
// DATA MAPPING UNTUK MODAL
// ============================================
const pacsDataMap = new Map();

<?php foreach ($pacs_mappings as $map): ?>
pacsDataMap.set('<?= $map['id'] ?>', {
    no_rawat: '<?= htmlspecialchars($pasien_info['no_rawat'], ENT_QUOTES) ?>',
    tanggal: '<?= date('d/m/Y', strtotime($pasien_info['tgl_registrasi'])) ?>',
    jam: '<?= date('H:i:s', strtotime($map['jam'] ?? $pasien_info['jam'])) ?>',
    dokter: '<?= htmlspecialchars($pasien_info['nm_dokter_perujuk'] ?? $pasien_info['dokter_perujuk'], ENT_QUOTES) ?>',
    pemeriksaan: '<?= htmlspecialchars($map['procedure_name'] ?? $pasien_info['nm_perawatan'], ENT_QUOTES) ?>',
    expertise_text: <?= json_encode($map['expertise_text'] ?? '') ?>
});
<?php endforeach; ?>

function showHasilBacaan(mappingId) {
    const data = pacsDataMap.get(String(mappingId));

    if (!data) {
        showToast('Data tidak ditemukan', 'error', 3000);
        return;
    }

    document.getElementById('modalNoRawat').textContent = data.no_rawat;
    document.getElementById('modalTanggal').textContent = data.tanggal;
    document.getElementById('modalJam').textContent = data.jam;
    document.getElementById('modalDokter').textContent = data.dokter;
    document.getElementById('modalPemeriksaan').textContent = data.pemeriksaan;

    const rawHasil = data.expertise_text || '';
    if (rawHasil) {
        document.getElementById('modalHasilText').innerHTML = sanitizeHasilBacaan(rawHasil);
    } else {
        document.getElementById('modalHasilText').innerHTML = '';
    }

    const modal = document.getElementById('modalHasilBacaan');
    modal.style.display = 'flex';
    setTimeout(() => modal.classList.add('show'), 10);
    document.body.style.overflow = 'hidden';
}

function sanitizeHasilBacaan(html) {
    const allowedTags = /<(br|b|strong|i|u|em)\s*\/?>/gi;
    const placeholder = '@@ALLOWED_TAG@@';
    const marks = [];

    let temp = html.replace(allowedTags, (match) => {
        marks.push(match);
        return placeholder;
    });

    temp = temp.replace(/<[^>]*>/g, '');

    let result = temp;
    marks.forEach((tag) => {
        result = result.replace(placeholder, tag);
    });

    return result;
}

function closeHasilModal() {
    const modal = document.getElementById('modalHasilBacaan');
    modal.classList.remove('show');
    setTimeout(() => {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }, 300);
}

function closeModalIfOutside(event) {
    if (event.target.id === 'modalHasilBacaan') {
        closeHasilModal();
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeHasilModal();
    }
});

function loadTemplate() { showToast('Fitur template belum tersedia', 'info', 3000, 'Info'); }
function saveHasil() { showToast('Fitur save hasil belum tersedia', 'info', 3000, 'Info'); }
function cetakHasil() { window.print(); }

// ============================================
// INIT -- pasang listener tombol statis + auto-trigger cek PACS
// ============================================
document.addEventListener('DOMContentLoaded', function() {
  updateCounter();
  bindStaticMappingButtons();

  <?php if (count($pacs_mappings) == 0): ?>
  cekPacsLiveByNoRawat(false);
  <?php elseif ($hasPendingMapping): ?>
  ambilSemuaDICOM(true);
  <?php endif; ?>
});
</script>

<div id="modalHasilBacaan" class="modal-overlay" onclick="closeModalIfOutside(event)">
    <div class="modal-container" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3>📝 Hasil Bacaan Radiologi</h3>
            <button class="modal-close" onclick="closeHasilModal()">×</button>
        </div>
        <div class="modal-body">
            <div class="modal-info-grid">
                <div class="modal-info-item">
                    <span class="modal-info-label">No.Rawat</span>
                    <span class="modal-info-value" id="modalNoRawat">-</span>
                </div>
                <div class="modal-info-item">
                    <span class="modal-info-label">Tanggal</span>
                    <span class="modal-info-value" id="modalTanggal">-</span>
                </div>
                <div class="modal-info-item">
                    <span class="modal-info-label">Jam</span>
                    <span class="modal-info-value" id="modalJam">-</span>
                </div>
                <div class="modal-info-item" style="grid-column: 1 / -1;">
                    <span class="modal-info-label">Dokter Perujuk</span>
                    <span class="modal-info-value" id="modalDokter">-</span>
                </div>
                <div class="modal-info-item" style="grid-column: 1 / -1;">
                    <span class="modal-info-label">Pemeriksaan</span>
                    <span class="modal-info-value" id="modalPemeriksaan">-</span>
                </div>
            </div>
            <div style="margin-bottom: 10px;">
                <span class="modal-info-label">Hasil Bacaan Radiologi</span>
            </div>
            <div class="modal-hasil" id="modalHasilText"></div>
        </div>
    </div>
</div>

</body>
</html>
