<?php
session_start();
require_once __DIR__ . '/auth_check.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/config.php';

define('SS_AUTH_URL',      SATUSEHAT_AUTH_URL);
define('SS_BASE_URL',      SATUSEHAT_BASE_URL);
define('SS_CLIENT_ID',     SATUSEHAT_CLIENT_ID);
define('SS_CLIENT_SECRET', SATUSEHAT_CLIENT_SECRET);
define('SS_ORG_ID',        SATUSEHAT_ORG_ID);

// ── DB ────────────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    die('DB Error: ' . $e->getMessage());
}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['act'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_POST['act']) {
        case 'send':      echo json_encode(doSend($_POST, $pdo));     break;
        case 'autoload':  echo json_encode(doAutoload($_POST, $pdo)); break;
        case 'checkihs':  echo json_encode(doCheckIHS($_POST, $pdo)); break;
        case 'testtoken':
            [$tok, $err] = getTokenEx();
            echo json_encode([
                'ok'            => (bool)$tok,
                'token_preview' => $tok ? substr($tok, 0, 40) . '…' : null,
                'error'         => $err,
                'ss_auth_url'   => SS_AUTH_URL,
                'ss_base_url'   => SS_BASE_URL,
                'client_id'     => substr(SS_CLIENT_ID, 0, 6) . '****',
                'org_id'        => SS_ORG_ID,
                'php_curl'      => function_exists('curl_init') ? 'OK' : 'TIDAK ADA (ext-curl belum aktif)',
                'php_version'   => PHP_VERSION,
            ]);
            break;
        case 'row':
            $s = $pdo->prepare('SELECT * FROM khanza_pacs_mapping WHERE id=?');
            $s->execute([intval($_POST['id'])]);
            echo json_encode($s->fetch());
            break;
    }
    exit;
}

// ── Token: getTokenEx() → [string|null, errorMsg|null] ───────────────────────
function getTokenEx(): array {
    // Auto-normalize: pastikan URL selalu berakhir /accesstoken
    $authBase = rtrim(SS_AUTH_URL, '/');
    if (!str_ends_with($authBase, '/accesstoken')) {
        $authBase .= '/accesstoken';
    }
    $url = $authBase . '?grant_type=client_credentials';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => SS_CLIENT_ID,
            'client_secret' => SS_CLIENT_SECRET,
        ]),
    ]);

    $body      = curl_exec($ch);
    $curlErr   = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $urlUsed   = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); // URL aktual setelah redirect
    curl_close($ch);

    if ($body === false || $curlErrNo !== 0) {
        return [null, "cURL error #{$curlErrNo}: {$curlErr} | URL: {$urlUsed}"];
    }
    if (empty(trim((string)$body))) {
        return [null, "Response kosong. HTTP {$httpCode} | URL: {$urlUsed}"];
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Tampilkan URL aktual untuk debugging
        return [null, "Response bukan JSON. HTTP {$httpCode} | URL yg dipakai: {$urlUsed} | Raw: " . substr($body, 0, 400)];
    }

    $tok = $data['access_token'] ?? null;
    if (!$tok) {
        $errMsg = $data['error_description']
               ?? $data['error']
               ?? $data['fault']['faultstring']
               ?? $data['message']
               ?? 'access_token tidak ditemukan dalam response';
        return [null, "HTTP {$httpCode} – {$errMsg} | Full: " . json_encode($data, JSON_UNESCAPED_UNICODE)];
    }

    return [$tok, null];
}

/** Wrapper ringkas untuk backward-compat */
function getToken(): ?string {
    [$tok] = getTokenEx();
    return $tok;
}

// ── fhirGet / fhirPost ────────────────────────────────────────────────────────
function fhirGet(string $tok, string $path): array {
    $ch = curl_init(rtrim(SS_BASE_URL, '/') . '/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $tok,
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($body, true)];
}

function fhirPost(string $tok, string $res, array $pay): array {
    $ch = curl_init(rtrim(SS_BASE_URL, '/') . '/' . $res);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $tok,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($pay, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($body, true)];
}

// ── doCheckIHS: cek IHS pasien via NIK ke SatuSehat ──────────────────────────
function doCheckIHS(array $d, PDO $pdo): array {
    $nik     = trim($d['nik']      ?? '');
    $noRawat = trim($d['no_rawat'] ?? '');
    $mapId   = intval($d['map_id'] ?? 0);

    if (!$nik) return ['ok' => false, 'msg' => 'NIK tidak boleh kosong'];

    [$tok, $tokErr] = getTokenEx();
    if (!$tok) {
        return ['ok'  => false,
                'msg' => 'Gagal token SatuSehat:<br>'
                       . '<code style="font-size:.68rem;word-break:break-all">'
                       . htmlspecialchars($tokErr) . '</code>'];
    }

    $path = 'Patient?identifier=' . urlencode('https://fhir.kemkes.go.id/id/nik|' . $nik);
    $res  = fhirGet($tok, $path);

    if ($res['code'] !== 200) {
        return ['ok'     => false,
                'msg'    => "SatuSehat HTTP {$res['code']}",
                'detail' => $res['body']];
    }

    $total = $res['body']['total'] ?? 0;
    if ($total === 0 || empty($res['body']['entry'])) {
        return ['ok' => false, 'msg' => "Pasien NIK <b>{$nik}</b> tidak ditemukan di SatuSehat"];
    }

    $resource = $res['body']['entry'][0]['resource'] ?? [];
    $ihsId    = $resource['id'] ?? null;
    $ihsName  = $resource['name'][0]['text'] ?? ($resource['name'][0]['family'] ?? '');

    if (!$ihsId) {
        return ['ok' => false, 'msg' => 'IHS ID tidak ada dalam respons', 'raw' => $resource];
    }

    // Simpan ke khanza_pacs_mapping.patient_ihs
    $saved = false;
    try {
        if ($mapId > 0) {
            $st = $pdo->prepare("UPDATE khanza_pacs_mapping SET patient_ihs=?, updated_at=NOW() WHERE id=?");
            $st->execute([$ihsId, $mapId]);
            $saved = $st->rowCount() > 0;
        } elseif ($noRawat) {
            $st = $pdo->prepare("UPDATE khanza_pacs_mapping SET patient_ihs=?, updated_at=NOW() WHERE no_rawat=?");
            $st->execute([$ihsId, $noRawat]);
            $saved = $st->rowCount() > 0;
        }
    } catch (Exception $e) { /* non-fatal */ }

    return [
        'ok'    => true,
        'ihs_id'=> $ihsId,
        'name'  => $ihsName,
        'saved' => $saved,
        'msg'   => "IHS: <b>{$ihsId}</b>" . ($ihsName ? " &nbsp;·&nbsp; {$ihsName}" : ''),
    ];
}

// ── doAutoload: ambil semua data pendukung dari DB ────────────────────────────
function doAutoload(array $d, PDO $pdo): array {
    $nr = trim($d['no_rawat'] ?? '');
    if (!$nr) return ['ok' => false, 'msg' => 'no_rawat kosong'];

    $out = ['ok' => true, 'patient_nik' => null, 'patient_ihs' => null,
            'encounter_id' => null, 'rad_ihs' => null, 'rad_name' => null,
            'icd10_code' => null, 'icd10_display' => null];

    // ① NIK + IHS tersimpan
    try {
        $q = $pdo->prepare("
            SELECT p.no_ktp AS nik, m.patient_ihs AS ihs_saved
            FROM   reg_periksa rp
            JOIN   pasien p ON p.no_rkm_medis = rp.no_rkm_medis
            LEFT JOIN khanza_pacs_mapping m ON m.no_rawat = rp.no_rawat
            WHERE  rp.no_rawat = ? LIMIT 1
        ");
        $q->execute([$nr]); $row = $q->fetch();
        $out['patient_nik'] = $row['nik']       ?? null;
        $out['patient_ihs'] = $row['ihs_saved'] ?? null;
    } catch (Exception $e) { $out['err_patient'] = $e->getMessage(); }

    // ② Encounter ID
    try {
        $q = $pdo->prepare("SELECT id_encounter FROM satu_sehat_encounter WHERE no_rawat=? LIMIT 1");
        $q->execute([$nr]); $row = $q->fetch();
        $out['encounter_id'] = $row['id_encounter'] ?? null;
    } catch (Exception $e) { $out['err_encounter'] = $e->getMessage(); }

    // ③ Radiolog: periksa_radiologi → dokter → pegawai.nik = kd_dokter → no_ktp
    try {
        $q = $pdo->prepare("
            SELECT pg.no_ktp AS rad_ihs, d.nm_dokter AS rad_name
            FROM   periksa_radiologi pr
            JOIN   dokter  d  ON d.kd_dokter = pr.kd_dokter
            JOIN   pegawai pg ON pg.nik       = pr.kd_dokter
            WHERE  pr.no_rawat = ? LIMIT 1
        ");
        $q->execute([$nr]); $row = $q->fetch();
        $out['rad_ihs']  = $row['rad_ihs']  ?? null;
        $out['rad_name'] = $row['rad_name'] ?? null;
    } catch (Exception $e) { $out['err_rad'] = $e->getMessage(); }

    // ④ ICD-10 + nama penyakit
    try {
        $q = $pdo->prepare("
            SELECT dp.kd_penyakit, py.nm_penyakit
            FROM   diagnosa_pasien dp
            LEFT JOIN penyakit py ON py.kd_penyakit = dp.kd_penyakit
            WHERE  dp.no_rawat = ? ORDER BY dp.kd_penyakit ASC LIMIT 1
        ");
        $q->execute([$nr]); $row = $q->fetch();
        $out['icd10_code']    = $row['kd_penyakit'] ?? null;
        $out['icd10_display'] = $row['nm_penyakit'] ?? null;
    } catch (Exception $e) { $out['err_icd'] = $e->getMessage(); }

    return $out;
}

// ── Build FHIR payloads ───────────────────────────────────────────────────────
function buildSR(array $d): array {
    $o = SS_ORG_ID;

    // code.coding: LOINC (wajib) + KPTL (opsional)
    $codeCoding = [
        [
            'system'  => 'http://loinc.org',
            'code'    => $d['loinc_code'],
            'display' => $d['loinc_display'] ?? '',
        ],
    ];
    if (!empty(trim($d['kptl_code'] ?? ''))) {
        $codeCoding[] = [
            'system'  => 'http://terminology.kemkes.go.id/CodeSystem/kptl',
            'code'    => $d['kptl_code'],
            'display' => $d['kptl_display'] ?? '',
        ];
    }

    // orderDetail: Modality (wajib) + AE Title (opsional) + KFA/kontras (opsional)
    $orderDetail = [
        [
            'coding' => [['system' => 'http://dicom.nema.org/resources/ontology/DCM', 'code' => $d['modality']]],
            'text'   => 'Modality Code: ' . $d['modality'],
        ],
    ];
    if (!empty(trim($d['ae_title'] ?? ''))) {
        $orderDetail[] = [
            'coding' => [['system' => 'http://sys-ids.kemkes.go.id/ae-title', 'display' => $d['ae_title']]],
        ];
    }
    if (!empty(trim($d['kfa_code'] ?? ''))) {
        $orderDetail[] = [
            'coding' => [[
                'system'  => 'http://sys-ids.kemkes.go.id/kfa',
                'code'    => $d['kfa_code'],
                'display' => $d['kfa_display'] ?? '',
            ]],
        ];
    }

    $payload = [
        'resourceType' => 'ServiceRequest',
        'identifier'   => [
            [
                'system' => "http://sys-ids.kemkes.go.id/servicerequest/{$o}",
                'value'  => $d['pacs_acc'],
            ],
            [
                'use'    => 'usual',
                'type'   => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0203', 'code' => 'ACSN']]],
                'system' => "http://sys-ids.kemkes.go.id/acsn/{$o}",
                'value'  => $d['pacs_acc'],
            ],
        ],
        'status'             => 'active',
        'intent'             => 'original-order',
        'priority'           => 'routine',
        'category'           => [['coding' => [['system' => 'http://snomed.info/sct', 'code' => '363679005', 'display' => 'Imaging']]]],
        'code'               => ['coding' => $codeCoding, 'text' => $d['procedure_name']],
        'orderDetail'        => $orderDetail,
        'subject'            => ['reference' => 'Patient/'   . $d['patient_ihs']],
        'encounter'          => ['reference' => 'Encounter/' . $d['encounter_id']],
        'occurrenceDateTime' => $d['started_utc'],
        'requester'          => ['reference' => 'Practitioner/' . $d['req_ihs'], 'display' => $d['req_name'] ?? ''],
    ];

    // performer: opsional — hanya dikirim jika rad_ihs diisi
    if (!empty(trim($d['rad_ihs'] ?? ''))) {
        $payload['performer'] = [['reference' => 'Practitioner/' . $d['rad_ihs'], 'display' => $d['rad_name'] ?? '']];
    }

    // reasonCode: opsional — hanya dikirim jika icd10_code diisi
    if (!empty(trim($d['icd10_code'] ?? ''))) {
        $payload['reasonCode'] = [[
            'coding' => [[
                'system'  => 'http://hl7.org/fhir/sid/icd-10',
                'code'    => $d['icd10_code'],
                'display' => $d['icd10_display'] ?? '',
            ]],
        ]];
    }

    return $payload;
}

function buildIS(array $d, string $srId): array {
    $o  = SS_ORG_ID;
    $su = ltrim($d['study_uid'], 'urn:oid:');
    $sp = ltrim($d['sop_uid'],   'urn:oid:');
    return [
        'resourceType' => 'ImagingStudy',
        'identifier'   => [
            ['use' => 'usual', 'type' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0203', 'code' => 'ACSN']]],
             'system' => "http://sys-ids.kemkes.go.id/acsn/{$o}", 'value' => $d['pacs_acc']],
            ['system' => 'urn:dicom:uid', 'value' => "urn:oid:{$su}"],
        ],
        'status'            => 'available',
        'modality'          => [['system' => 'http://dicom.nema.org/resources/ontology/DCM', 'code' => $d['modality']]],
        'subject'           => ['reference' => 'Patient/'    . $d['patient_ihs']],
        'started'           => $d['started_utc'],
        'basedOn'           => [['reference' => "ServiceRequest/{$srId}"]],
        'numberOfSeries'    => 1, 'numberOfInstances' => 1,
        'interpreter'       => [['reference' => 'Practitioner/' . $d['rad_ihs'], 'display' => $d['rad_name']]],
        'series' => [[
            'uid' => $d['series_uid'], 'number' => 1,
            'modality'          => ['system' => 'http://dicom.nema.org/resources/ontology/DCM', 'code' => $d['modality']],
            'numberOfInstances' => 1, 'started' => $d['started_utc'],
            'instance' => [['uid' => $d['inst_uid'],
                'sopClass' => ['system' => 'urn:ietf:rfc:3986', 'code' => "urn:oid:{$sp}"],
                'number' => 1, 'title' => 'ORIGINAL\\PRIMARY']],
        ]],
    ];
}

// ── doSend ────────────────────────────────────────────────────────────────────
function doSend(array $d, PDO $pdo): array {
    // Wajib: pacs_acc, patient_ihs, encounter_id, started_utc, modality,
    //         study_uid, series_uid, inst_uid, sop_uid, req_ihs, loinc_code
    // Opsional: rad_ihs, icd10_code, ae_title, kfa_code, kptl_code
    $req = ['pacs_acc','patient_ihs','encounter_id','started_utc','modality',
            'study_uid','series_uid','inst_uid','sop_uid',
            'req_ihs','loinc_code'];
    foreach ($req as $f) {
        if (empty(trim($d[$f] ?? '')))
            return ['ok' => false, 'step' => 'validasi', 'msg' => "Field <b>{$f}</b> wajib diisi"];
    }

    [$tok, $tokErr] = getTokenEx();
    if (!$tok) return ['ok' => false, 'step' => 'auth',
                       'msg' => 'Gagal token: ' . htmlspecialchars($tokErr)];

    $sr = fhirPost($tok, 'ServiceRequest', buildSR($d));
    if (!in_array($sr['code'], [200, 201]))
        return ['ok' => false, 'step' => 'ServiceRequest', 'msg' => "HTTP {$sr['code']}",
                'detail' => $sr['body'], 'payload' => buildSR($d)];

    $srId = $sr['body']['id'];
    $is   = fhirPost($tok, 'ImagingStudy', buildIS($d, $srId));
    if (!in_array($is['code'], [200, 201]))
        return ['ok' => false, 'step' => 'ImagingStudy', 'msg' => "HTTP {$is['code']}",
                'sr_id' => $srId, 'detail' => $is['body'], 'payload' => buildIS($d, $srId)];

    $isId = $is['body']['id'];
    $pdo->prepare("UPDATE khanza_pacs_mapping SET status='sent', notes=?, updated_at=NOW() WHERE id=?")
        ->execute(["SR:{$srId}|IS:{$isId}|" . date('Y-m-d H:i:s'), intval($d['map_id'])]);

    return ['ok' => true, 'sr_id' => $srId, 'is_id' => $isId, 'msg' => 'Berhasil dikirim ke SatuSehat'];
}

// ── Fetch rows ────────────────────────────────────────────────────────────────
$q   = trim($_GET['q']  ?? '');
$stf = trim($_GET['st'] ?? '');
$pg  = max(1, intval($_GET['pg'] ?? 1));
$pp  = 25; $off = ($pg - 1) * $pp;
$w   = 'WHERE 1=1'; $p = [];
if ($q)  { $w .= ' AND (no_rawat LIKE ? OR no_rkm_medis LIKE ? OR nm_pasien LIKE ? OR pacs_acc LIKE ?)'; $p = ["%$q%","%$q%","%$q%","%$q%"]; }
if ($stf){ $w .= ' AND status=?'; $p[] = $stf; }
$cnt = $pdo->prepare("SELECT COUNT(*) FROM khanza_pacs_mapping $w");
$cnt->execute($p); $total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $pp));
$rs  = $pdo->prepare("SELECT * FROM khanza_pacs_mapping $w ORDER BY created_at DESC LIMIT $pp OFFSET $off");
$rs->execute($p); $rows = $rs->fetchAll();

$MODS = ['CR'=>'CR','CT'=>'CT','DX'=>'DX','MG'=>'MG','MR'=>'MR','NM'=>'NM','PT'=>'PT','RF'=>'RF','US'=>'US','XA'=>'XA'];
$SOP  = ['CR'=>'1.2.840.10008.5.1.4.1.1.1','CT'=>'1.2.840.10008.5.1.4.1.1.2','DX'=>'1.2.840.10008.5.1.4.1.1.1.1',
         'MG'=>'1.2.840.10008.5.1.4.1.1.1.3','MR'=>'1.2.840.10008.5.1.4.1.1.4','NM'=>'1.2.840.10008.5.1.4.1.1.20',
         'PT'=>'1.2.840.10008.5.1.4.1.1.128','RF'=>'1.2.840.10008.5.1.4.1.1.12.2',
         'US'=>'1.2.840.10008.5.1.4.1.1.6.1','XA'=>'1.2.840.10008.5.1.4.1.1.12.1'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ImagingStudy – SatuSehat</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
*{box-sizing:border-box}
body{background:#f4f6f9;font-size:.82rem}
.topbar{background:#1565c0;color:#fff;padding:.6rem 1rem;display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap}
.topbar h6{margin:0;font-size:.95rem;font-weight:700}
.topbar small{opacity:.8;font-size:.75rem}
.env-badge{background:rgba(255,255,255,.2);border-radius:.3rem;padding:.1rem .5rem;font-size:.7rem;font-weight:700}
.card{border:0;box-shadow:0 1px 4px rgba(0,0,0,.1);border-radius:.5rem}
.table thead th{background:#37474f;color:#fff;font-size:.75rem;padding:.4rem .5rem;white-space:nowrap;border:0}
.table td{padding:.35rem .5rem;font-size:.78rem;vertical-align:middle}
.table tbody tr:hover{background:#e8f0fe}
.bs{font-size:.7rem;padding:.15rem .45rem;border-radius:.25rem;font-weight:600}
.bs-sent{background:#c8e6c9;color:#1b5e20}.bs-pending{background:#fff3e0;color:#e65100}
.bs-failed{background:#ffcdd2;color:#b71c1c}.bs-null{background:#eceff1;color:#546e7a}
.fl{font-size:.72rem;color:#546e7a}
.fl-label{font-size:.7rem;font-weight:600;margin-bottom:.15rem;color:#455a64}
.opt-label{font-size:.7rem;font-weight:600;margin-bottom:.15rem;color:#90a4ae}
.sec{border-left:3px solid #1565c0;padding:.4rem .7rem;background:#f8f9fa;border-radius:0 .4rem .4rem 0;margin-bottom:.6rem}
.sec-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#1565c0;margin-bottom:.5rem}
input.form-control,select.form-select{font-size:.78rem;padding:.25rem .45rem;height:auto}
input[readonly]{background:#f1f3f4!important;color:#546e7a}
input.optional{border-style:dashed}
pre.jbox{background:#1e1e2e;color:#cdd6f4;border-radius:.4rem;padding:.6rem;font-size:.7rem;max-height:260px;overflow:auto;margin:0}
.modal-header{padding:.5rem .75rem}.modal-footer{padding:.4rem .75rem}.modal-body{padding:.75rem;background:#f8f9fa}
.auto-filled{border-color:#4caf50!important;background:#f1f8e9!important}
.auto-hint{font-size:.65rem;color:#2e7d32;margin-top:.1rem;display:none}
.auto-hint.show{display:block}
.ihs-box{background:#fff;border:1px solid #c5cae9;border-radius:.45rem;padding:.5rem .7rem;margin-bottom:.1rem}
.ihs-box-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#1565c0;margin-bottom:.4rem}
.ihs-status{font-size:.72rem;margin-top:.3rem;min-height:1rem;line-height:1.5;word-break:break-word}
.ihs-ok{color:#1b5e20;font-weight:600}.ihs-err{color:#b71c1c;font-weight:600}.ihs-warn{color:#e65100}
.ihs-verified{border-color:#4caf50!important;background:#f1f8e9!important}
.badge-saved{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;font-size:.64rem;padding:.1rem .4rem;border-radius:.2rem;font-weight:700}
.badge-unsaved{background:#fff3e0;color:#e65100;border:1px solid #ffcc80;font-size:.64rem;padding:.1rem .4rem;border-radius:.2rem;font-weight:700}
#btnCheckIHS{font-size:.73rem;padding:.22rem .6rem;white-space:nowrap}
</style>
</head>
<body>

<div class="topbar">
  <span style="font-size:1.3rem">&#x1F52C;</span>
  <div>
    <h6>Pengiriman Radiologi &ndash; SatuSehat</h6>
    <small>
      ServiceRequest &amp; ImagingStudy &nbsp;|&nbsp;
      Org: <b><?= htmlspecialchars(SS_ORG_ID) ?></b> &nbsp;|&nbsp;
      <span class="env-badge"><?= strpos(SS_BASE_URL,'stg')!==false?'STG':(strpos(SS_BASE_URL,'dev')!==false?'DEV':'PROD') ?></span>
    </small>
  </div>
  <!-- Tombol debug token -->
  <div class="ms-auto">
    <button type="button" id="btnTestToken"
            class="btn btn-sm btn-outline-light"
            style="font-size:.7rem;padding:.15rem .55rem"
            title="Uji koneksi token SatuSehat">
      &#x1F511; Test Token
    </button>
  </div>
</div>

<!-- Modal debug token -->
<div class="modal fade" id="mToken" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white py-2">
        <small class="fw-bold">&#x1F511; Diagnostik Token SatuSehat</small>
        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-3" id="tokenModalBody">
        <div class="text-center text-muted py-3">&#x23F3; Mengecek...</div>
      </div>
    </div>
  </div>
</div>

<div class="container-fluid px-3">

  <!-- Filter -->
  <form method="GET" class="card mb-2">
    <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-end">
      <div>
        <div class="fl-label">Cari</div>
        <input type="text" name="q" class="form-control form-control-sm" style="width:260px"
               placeholder="No rawat / RM / Nama / Accession" value="<?= htmlspecialchars($q) ?>">
      </div>
      <div>
        <div class="fl-label">Status</div>
        <select name="st" class="form-select form-select-sm" style="width:140px">
          <option value="">Semua</option>
          <option value="sent"    <?= $stf==='sent'   ?'selected':'' ?>>&#x2705; Sent</option>
          <option value="pending" <?= $stf==='pending'?'selected':'' ?>>&#x23F3; Pending</option>
          <option value="failed"  <?= $stf==='failed' ?'selected':'' ?>>&#x274C; Failed</option>
        </select>
      </div>
      <div class="d-flex gap-1">
        <button class="btn btn-sm btn-primary px-3">Cari</button>
        <a href="?" class="btn btn-sm btn-outline-secondary">Reset</a>
      </div>
      <div class="ms-auto">
        <span class="badge bg-secondary"><?= number_format($total) ?> data</span>
      </div>
    </div>
  </form>

  <!-- Tabel -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-bordered mb-0">
        <thead>
          <tr>
            <th>#</th><th>No Rawat</th><th>No RM</th><th>Nama Pasien</th>
            <th>Acc No</th><th>Prosedur</th><th>IHS Pasien</th>
            <th>Status</th><th>Dibuat</th><th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($rows)): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">Tidak ada data</td></tr>
          <?php else: foreach($rows as $i=>$r):
            $bc = match($r['status']??''){ 'sent'=>'bs-sent','pending'=>'bs-pending','failed'=>'bs-failed',default=>'bs-null' };
          ?>
          <tr>
            <td class="text-muted"><?= $off+$i+1 ?></td>
            <td><code class="small"><?= htmlspecialchars($r['no_rawat']) ?></code></td>
            <td><?= htmlspecialchars($r['no_rkm_medis']) ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($r['nm_pasien']) ?></td>
            <td><code><?= htmlspecialchars($r['pacs_acc']) ?></code></td>
            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                title="<?= htmlspecialchars($r['procedure_name']) ?>"><?= htmlspecialchars($r['procedure_name']) ?></td>
            <td>
              <?php if(!empty($r['patient_ihs'])): ?>
                <code style="font-size:.7rem;color:#1b5e20"><?= htmlspecialchars($r['patient_ihs']) ?></code>
              <?php else: ?><span class="text-muted fl">&ndash;</span><?php endif; ?>
            </td>
            <td><span class="bs <?= $bc ?>"><?= strtoupper($r['status']??'&ndash;') ?></span></td>
            <td class="fl"><?= substr($r['created_at']??'',0,16) ?></td>
            <td>
              <button class="btn btn-sm btn-success py-0 px-2 btn-kirim" data-id="<?= $r['id'] ?>">
                &#x25B6; <?= ($r['status']==='sent')?'Ulang':'Kirim' ?>
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if($pages>1): ?>
    <div class="card-footer py-1 d-flex justify-content-between align-items-center">
      <small class="text-muted">Hal <?=$pg?>/<?=$pages?></small>
      <div class="d-flex gap-1">
        <?php for($p2=max(1,$pg-3);$p2<=min($pages,$pg+3);$p2++): ?>
        <a href="?<?= http_build_query(array_merge($_GET,['pg'=>$p2])) ?>"
           class="btn btn-<?= $p2===$pg?'primary':'outline-secondary' ?>"
           style="padding:.1rem .45rem;font-size:.72rem"><?= $p2 ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════ MODAL KIRIM ══════════════════════════════ -->
<div class="modal fade" id="mKirim" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <span class="fw-bold" style="font-size:.85rem">
          &#x25B6; Kirim ke SatuSehat &nbsp;<small id="mInfo" class="opacity-75"></small>
        </span>
        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="autoLoadBar" class="d-none alert alert-info py-1 px-2 mb-2" style="font-size:.72rem">
          &#x23F3; Memuat data otomatis dari database&hellip;</div>
        <div id="autoLoadResult" class="d-none mb-2"></div>

        <form id="frm">
          <input type="hidden" name="act"         value="send">
          <input type="hidden" name="map_id"      id="fMapId">
          <input type="hidden" name="started_utc" id="fUtc">
          <input type="hidden" id="fNoRawat">

          <!-- ① Data DB -->
          <div class="sec">
            <div class="sec-title">&#x2460; Data dari DB</div>
            <div class="row g-1">
              <div class="col-md-2">
                <div class="fl-label">No Rawat</div>
                <input id="vRawat" class="form-control form-control-sm" readonly>
              </div>
              <div class="col-md-2">
                <div class="fl-label">No RM</div>
                <input id="vRM" class="form-control form-control-sm" readonly>
              </div>
              <div class="col-md-3">
                <div class="fl-label">Nama Pasien</div>
                <input id="vNama" class="form-control form-control-sm" readonly>
              </div>
              <div class="col-md-2">
                <div class="fl-label">Accession No</div>
                <input name="pacs_acc" id="fAcc" class="form-control form-control-sm fw-bold" readonly>
              </div>
              <div class="col-md-3">
                <div class="fl-label">Prosedur</div>
                <input name="procedure_name" id="fProc" class="form-control form-control-sm">
              </div>
            </div>
          </div>

          <!-- ② Referensi SatuSehat -->
          <div class="sec">
            <div class="sec-title">&#x2461; Referensi SatuSehat <span style="color:red">*</span></div>
            <div class="row g-1 align-items-start">

              <!-- Widget IHS Verifikasi -->
              <div class="col-md-6">
                <div class="ihs-box">
                  <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="ihs-box-title mb-0">&#x1F50D; Verifikasi Patient IHS</span>
                    <span id="ihsSavedBadge" class="d-none"></span>
                  </div>
                  <div class="row g-1">
                    <div class="col-7">
                      <div class="fl-label">NIK Pasien <span class="fl">(dari DB)</span></div>
                      <div class="input-group input-group-sm">
                        <input id="fNIK" class="form-control form-control-sm font-monospace"
       placeholder="Masukkan NIK 16 digit&hellip;"
       maxlength="16" inputmode="numeric"
       oninput="this.value=this.value.replace(/\D/g,'')">
                        <button type="button" id="btnCheckIHS" class="btn btn-primary"
                                title="Cek IHS ke SatuSehat via NIK">
                          <span id="chkTxt">&#x2713; Cek IHS</span>
                          <span id="chkSpin" class="d-none">&#x23F3;</span>
                        </button>
                      </div>
                    </div>
                    <div class="col-5">
                      <div class="fl-label">Patient IHS Number <span style="color:red">*</span></div>
                      <input name="patient_ihs" id="fPIhs"
                             class="form-control form-control-sm font-monospace"
                             required placeholder="P00000000000">
                    </div>
                  </div>
                  <div id="ihsCheckStatus" class="ihs-status"></div>
                </div>
              </div>

              <!-- Encounter ID -->
              <div class="col-md-3">
                <div class="fl-label">Encounter ID SatuSehat <span style="color:red">*</span></div>
                <input id="fEncVis" class="form-control form-control-sm"
                       placeholder="uuid encounter"
                       oninput="document.getElementById('fEncHidden').value=this.value">
                <input type="hidden" name="encounter_id" id="fEncHidden">
                <div class="auto-hint" id="ahEnc">&#x2705; Terisi otomatis dari satu_sehat_encounter</div>
              </div>

              <!-- Tgl/Waktu -->
              <div class="col-md-3">
                <div class="fl-label">Tgl/Waktu Studi <span style="color:red">*</span> <span class="fl">(&rarr; UTC)</span></div>
                <input type="datetime-local" name="started_local" id="fLocal"
                       class="form-control form-control-sm" required>
              </div>
            </div>
          </div>

          <!-- ③ DICOM UIDs -->
          <div class="sec">
            <div class="sec-title">&#x2462; DICOM UIDs <span style="color:red">*</span></div>
            <div class="row g-1">
              <div class="col-md-3">
                <div class="fl-label">Modality <span style="color:red">*</span></div>
                <select name="modality" id="fMod" class="form-select form-select-sm" required>
                  <?php foreach($MODS as $k=>$v): ?><option value="<?=$k?>"><?=$k?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <div class="fl-label">SOP Class UID <span style="color:red">*</span></div>
                <input name="sop_uid" id="fSop" class="form-control form-control-sm font-monospace"
                       required placeholder="1.2.840.10008&hellip;">
              </div>
              <div class="col-md-3">
                <div class="opt-label">AE Title <span class="fl">(opsional)</span></div>
                <input name="ae_title" id="fAe" class="form-control form-control-sm optional" placeholder="XRAY001">
              </div>
              <div class="col-md-2">
                <div class="opt-label">Kode KFA / Kontras <span class="fl">(opsional)</span></div>
                <input name="kfa_code" id="fKfaC" class="form-control form-control-sm optional" placeholder="91000928">
              </div>
              <div class="col-md-4">
                <div class="opt-label">Nama KFA / Kontras <span class="fl">(opsional)</span></div>
                <input name="kfa_display" id="fKfaD" class="form-control form-control-sm optional" placeholder="mis. Barium Sulfate">
              </div>
              <div class="col-12">
                <div class="fl-label">Study Instance UID <span style="color:red">*</span></div>
                <input name="study_uid" id="fSU" class="form-control form-control-sm font-monospace"
                       required placeholder="1.2.840.113619.2.xxx&hellip;">
              </div>
              <div class="col-md-6">
                <div class="fl-label">Series Instance UID <span style="color:red">*</span></div>
                <input name="series_uid" id="fSeU" class="form-control form-control-sm font-monospace"
                       required placeholder="1.2.840.113619.2.xxx&hellip;1">
              </div>
              <div class="col-md-6">
                <div class="fl-label">SOP Instance UID <span style="color:red">*</span></div>
                <input name="inst_uid" id="fIU" class="form-control form-control-sm font-monospace"
                       required placeholder="1.2.840.113619.2.xxx&hellip;1.1">
              </div>
            </div>
          </div>

          <!-- ④ Dokter -->
          <div class="sec">
            <div class="sec-title">&#x2463; Dokter</div>
            <div class="row g-1">
              <div class="col-md-3">
                <div class="opt-label">IHS Radiolog <span class="fl">(opsional)</span></div>
                <input name="rad_ihs" id="fRI" class="form-control form-control-sm optional" placeholder="10000000000">
                <div class="auto-hint" id="ahRad">&#x2705; Terisi otomatis dari pegawai.no_ktp</div>
              </div>
              <div class="col-md-3">
                <div class="opt-label">Nama Radiolog <span class="fl">(opsional)</span></div>
                <input name="rad_name" id="fRN" class="form-control form-control-sm optional" placeholder="dr. Budi Sp.Rad">
                <div class="auto-hint" id="ahRadNm">&#x2705; Terisi otomatis dari dokter.nm_dokter</div>
              </div>
              <div class="col-md-3">
                <div class="fl-label">IHS Dokter Pengirim <span style="color:red">*</span></div>
                <input name="req_ihs" id="fQI" class="form-control form-control-sm" required placeholder="10000000001">
              </div>
              <div class="col-md-3">
                <div class="opt-label">Nama Pengirim <span class="fl">(opsional)</span></div>
                <input name="req_name" id="fQN" class="form-control form-control-sm optional" placeholder="dr. Ani">
              </div>
            </div>
          </div>

          <!-- ⑤ Kode Klinis -->
          <div class="sec">
            <div class="sec-title">&#x2464; Kode Klinis</div>
            <div class="row g-1">
              <div class="col-md-2">
                <div class="fl-label">LOINC <span style="color:red">*</span></div>
                <input name="loinc_code" id="fLC" class="form-control form-control-sm" required placeholder="24648-8">
                <div class="auto-hint" id="ahLoinc">&#x2705; Terisi otomatis dari nama prosedur</div>
              </div>
              <div class="col-md-4">
                <div class="opt-label">Display LOINC <span class="fl">(opsional)</span></div>
                <input name="loinc_display" id="fLD" class="form-control form-control-sm optional" placeholder="XR Chest PA upright">
              </div>
              <div class="col-md-2">
                <div class="opt-label">KPTL <span class="fl">(opsional)</span></div>
                <input name="kptl_code" id="fKC" class="form-control form-control-sm optional" placeholder="31243.NP001.AP005">
              </div>
              <div class="col-md-4">
                <div class="opt-label">Display KPTL <span class="fl">(opsional)</span></div>
                <input name="kptl_display" id="fKD" class="form-control form-control-sm optional" placeholder="Radiografi Thorax 1 proyeksi">
              </div>
              <div class="col-md-2">
                <div class="opt-label">ICD-10 <span class="fl">(opsional)</span></div>
                <input name="icd10_code" id="fIC" class="form-control form-control-sm optional" placeholder="J18.9">
                <div class="auto-hint" id="ahIcd">&#x2705; Terisi otomatis dari diagnosa_pasien</div>
              </div>
              <div class="col-md-10">
                <div class="opt-label">Display ICD-10 <span class="fl">(opsional)</span></div>
                <input name="icd10_display" id="fID" class="form-control form-control-sm optional" placeholder="Pneumonia, unspecified organism">
                <div class="auto-hint" id="ahIcdDsp">&#x2705; Terisi otomatis dari penyakit.nm_penyakit</div>
              </div>
            </div>
          </div>
        </form>

        <div id="divRes" class="d-none mt-2"></div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
        <button type="button" id="btnPrev" class="btn btn-sm btn-outline-dark">&#x1F441; Preview JSON</button>
        <button type="button" id="btnSend" class="btn btn-sm btn-success px-4">
          <span id="bTxt">&#x25B6; Kirim</span>
          <span id="bSpin" class="d-none" style="font-size:.7rem">&#x23F3; Mengirim&hellip;</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="mPrev" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white py-2">
        <small class="fw-bold">Preview JSON Payload</small>
        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div style="font-size:.68rem;font-weight:700;color:#666;margin-bottom:.3rem">&#x2460; ServiceRequest</div>
        <pre class="jbox" id="pSR"></pre>
        <div style="font-size:.68rem;font-weight:700;color:#666;margin:.5rem 0 .3rem">&#x2461; ImagingStudy</div>
        <pre class="jbox" id="pIS"></pre>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const SOP = <?= json_encode($SOP) ?>;
const ORG = '<?= SS_ORG_ID ?>';
const mK  = new bootstrap.Modal('#mKirim');
const mP  = new bootstrap.Modal('#mPrev');
const mT  = new bootstrap.Modal('#mToken');

// ── LOINC mapping ─────────────────────────────────────────────────────────────
const LOINC_MAP = [
  { keys:['CHEST PA','THORAX PA','THORAK PA'],                    code:'24648-8', disp:'XR Chest PA upright' },
  { keys:['CHEST','THORAX','THORAK','TORAKS','DADA','PARU'],      code:'36572-6', disp:'XR Chest AP' },
  { keys:['SKULL','KEPALA','CRANIUM','KRANIUM'],                   code:'24532-4', disp:'XR Skull AP and Lateral' },
  { keys:['LUMBAL','LUMBAR','LUMBOSACRAL','LS SPINE'],             code:'36643-5', disp:'XR Lumbar spine AP and Lateral' },
  { keys:['CERVICAL','SERVIKAL','C-SPINE'],                        code:'24966-4', disp:'XR Cervical spine AP and Lateral' },
  { keys:['ABDOMEN','PERUT','BNO'],                                code:'48462-2', disp:'XR Abdomen AP' },
  { keys:['PELVIS','PANGGUL'],                                     code:'24733-9', disp:'XR Pelvis AP' },
  { keys:['HAND','TANGAN','MANUS'],                                code:'36643-5', disp:'XR Hand AP and Lateral' },
  { keys:['KNEE','LUTUT','GENU'],                                  code:'24748-7', disp:'XR Knee AP and Lateral' },
  { keys:['ANKLE','PERGELANGAN KAKI'],                             code:'24627-2', disp:'XR Ankle AP and Lateral' },
  { keys:['CT SCAN','CT-SCAN','COMPUTED TOMOGRAPHY'],              code:'24725-5', disp:'CT unspecified body region' },
  { keys:['MRI','MAGNETIC RESONANCE'],                             code:'24559-7', disp:'MR unspecified body region' },
  { keys:['USG','ULTRASONOGRAFI','ULTRASONOGRAPHY','ULTRASOUND'], code:'24627-2', disp:'US unspecified body region' },
];
function autoLoinc(proc) {
  if (!proc) return;
  const up = proc.toUpperCase();
  for (const m of LOINC_MAP) {
    if (m.keys.some(k => up.includes(k))) {
      setAuto('fLC', m.code, 'ahLoinc');
      document.getElementById('fLD').value = m.disp;
      return;
    }
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function setAuto(id, val, hintId) {
  const el = document.getElementById(id);
  if (!el || !val) return;
  el.value = val; el.classList.add('auto-filled');
  if (hintId) document.getElementById(hintId)?.classList.add('show');
}
function clearAutoFill() {
  document.querySelectorAll('.auto-filled').forEach(e => e.classList.remove('auto-filled'));
  document.querySelectorAll('.auto-hint.show').forEach(e => e.classList.remove('show'));
}
function setIhsStatus(cls, html) {
  const el = document.getElementById('ihsCheckStatus');
  el.className = 'ihs-status ihs-' + cls; el.innerHTML = html;
}
function showAutoResult(type, msg) {
  const el = document.getElementById('autoLoadResult');
  el.innerHTML = `<div class="alert alert-${type} py-1 px-2 mb-0" style="font-size:.72rem">${msg}</div>`;
  el.classList.remove('d-none');
}

// ── Test Token (tombol di topbar) ─────────────────────────────────────────────
document.getElementById('btnTestToken').addEventListener('click', async () => {
  document.getElementById('tokenModalBody').innerHTML =
    '<div class="text-center text-muted py-3">&#x23F3; Mengecek koneksi token&hellip;</div>';
  mT.show();
  try {
    const r = await post({act:'testtoken'});
    const rows = [
      ['Status',      r.ok ? '<span class="text-success fw-bold">&#x2705; OK</span>' : '<span class="text-danger fw-bold">&#x274C; GAGAL</span>'],
      ['PHP cURL',    r.php_curl],
      ['PHP Version', r.php_version],
      ['Auth URL',    `<code style="font-size:.7rem;word-break:break-all">${r.ss_auth_url}</code>`],
      ['Base URL',    `<code style="font-size:.7rem;word-break:break-all">${r.ss_base_url}</code>`],
      ['Client ID',   `<code>${r.client_id}</code>`],
      ['Org ID',      `<code>${r.org_id}</code>`],
      ['Token',       r.token_preview ? `<code style="font-size:.7rem">${r.token_preview}</code>` : '&ndash;'],
    ];
    let html = '<table class="table table-sm table-bordered" style="font-size:.76rem">';
    rows.forEach(([k,v]) => { html += `<tr><td class="fw-semibold text-muted" style="width:35%">${k}</td><td>${v}</td></tr>`; });
    html += '</table>';
    if (!r.ok && r.error) {
      html += `<div class="alert alert-danger py-2 px-2 mt-1" style="font-size:.71rem">
        <b>Detail Error:</b><br>${r.error}</div>`;
    }
    document.getElementById('tokenModalBody').innerHTML = html;
  } catch(e) {
    document.getElementById('tokenModalBody').innerHTML =
      `<div class="alert alert-danger">JS Error: ${e.message}</div>`;
  }
});

// ── Buka modal Kirim ──────────────────────────────────────────────────────────
document.querySelectorAll('.btn-kirim').forEach(b => {
  b.addEventListener('click', async () => {
    reset();
    const r = await post({act:'row', id: b.dataset.id});
    if (!r) return;
    document.getElementById('fMapId').value   = r.id;
    document.getElementById('fNoRawat').value = r.no_rawat       || '';
    document.getElementById('vRawat').value   = r.no_rawat       || '';
    document.getElementById('vRM').value      = r.no_rkm_medis   || '';
    document.getElementById('vNama').value    = r.nm_pasien      || '';
    document.getElementById('fAcc').value     = r.pacs_acc       || '';
    document.getElementById('fProc').value    = r.procedure_name || '';
    document.getElementById('mInfo').textContent = (r.nm_pasien||'') + ' | ' + (r.pacs_acc||'');
    if (r.dicom_url) {
      const m = r.dicom_url.match(/(\d[\d.]{9,})/);
      if (m) document.getElementById('fSU').value = m[1];
    }
    const now = new Date(); now.setSeconds(0,0);
    document.getElementById('fLocal').value = fmtLocal(now);
    setSop(); mK.show();
    await doAutoLoad(r.no_rawat||'', r.procedure_name||'');
  });
});

// ── Auto-load ─────────────────────────────────────────────────────────────────
async function doAutoLoad(noRawat, procName) {
  document.getElementById('autoLoadBar').classList.remove('d-none');
  document.getElementById('autoLoadResult').classList.add('d-none');
  try {
    const d = await post({act:'autoload', no_rawat: noRawat});
    document.getElementById('autoLoadBar').classList.add('d-none');
    if (!d?.ok) { showAutoResult('warning', '&#x26A0;&#xFE0F; Auto-fill gagal: ' + (d?.msg||'unknown')); return; }
    const filled = [];
    if (d.patient_nik) document.getElementById('fNIK').value = d.patient_nik;
    if (d.patient_ihs) {
      const el = document.getElementById('fPIhs');
      el.value = d.patient_ihs; el.classList.add('ihs-verified');
      const badge = document.getElementById('ihsSavedBadge');
      badge.className = 'badge-saved'; badge.textContent = '\u2713 IHS tersimpan di DB';
      badge.classList.remove('d-none');
      setIhsStatus('ok', `\u2705 IHS tersimpan: <b>${d.patient_ihs}</b> <span class="fl">\u2014 klik \u2713 Cek IHS untuk memperbarui</span>`);
      filled.push('Patient IHS');
    } else if (d.patient_nik) {
      setIhsStatus('warn', '\u26A0\uFE0F NIK ditemukan. Klik <b>\u2713 Cek IHS</b> untuk mendapatkan IHS Number dari SatuSehat.');
    }
    if (d.encounter_id) {
      document.getElementById('fEncVis').value    = d.encounter_id;
      document.getElementById('fEncHidden').value = d.encounter_id;
      document.getElementById('fEncVis').classList.add('auto-filled');
      document.getElementById('ahEnc').classList.add('show');
      filled.push('Encounter ID');
    }
    if (d.rad_ihs)       { setAuto('fRI', d.rad_ihs,       'ahRad');    filled.push('IHS Radiolog'); }
    if (d.rad_name)      { setAuto('fRN', d.rad_name,      'ahRadNm');  filled.push('Nama Radiolog'); }
    if (d.icd10_code)    { setAuto('fIC', d.icd10_code,    'ahIcd');    filled.push('ICD-10'); }
    if (d.icd10_display) { setAuto('fID', d.icd10_display, 'ahIcdDsp'); filled.push('Display ICD-10'); }
    autoLoinc(procName);
    if (document.getElementById('fLC').value) filled.push('LOINC');
    if (filled.length) showAutoResult('success', `\u2705 Auto-fill: <b>${filled.join(', ')}</b>`);
  } catch(e) {
    document.getElementById('autoLoadBar').classList.add('d-none');
    showAutoResult('danger', '\u274C Error auto-fill: ' + e.message);
  }
}

// ── Tombol Cek IHS ────────────────────────────────────────────────────────────
document.getElementById('btnCheckIHS').addEventListener('click', async () => {
  const nik     = document.getElementById('fNIK').value.trim();
  const noRawat = document.getElementById('fNoRawat').value.trim();
  const mapId   = document.getElementById('fMapId').value.trim();
  if (!nik) {
    setIhsStatus('err', '\u274C NIK tidak tersedia. Pastikan data pasien sudah ada di tabel pasien.');
    return;
  }
  document.getElementById('btnCheckIHS').disabled = true;
  document.getElementById('chkTxt').classList.add('d-none');
  document.getElementById('chkSpin').classList.remove('d-none');
  setIhsStatus('warn', `\u23F3 Mengecek NIK <b>${nik}</b> ke SatuSehat\u2026`);
  try {
    const res = await post({act:'checkihs', nik, no_rawat: noRawat, map_id: mapId});
    if (res.ok) {
      const el = document.getElementById('fPIhs');
      el.value = res.ihs_id; el.classList.add('ihs-verified','auto-filled');
      const badge = document.getElementById('ihsSavedBadge');
      badge.className   = res.saved ? 'badge-saved'   : 'badge-unsaved';
      badge.textContent = res.saved ? '\u2713 Tersimpan ke DB' : '\u26A0 Gagal simpan';
      badge.classList.remove('d-none');
      setIhsStatus('ok',
        `\u2705 ${res.msg}` +
        (res.saved ? ` <span class="fl">(\u2713 disimpan ke khanza_pacs_mapping)</span>`
                   : ` <span style="color:#e65100">(\u26A0 gagal simpan \u2014 periksa koneksi DB)</span>`)
      );
    } else {
      setIhsStatus('err', `\u274C ${res.msg}`);
    }
  } catch(e) {
    setIhsStatus('err', '\u274C Error: ' + e.message);
  } finally {
    document.getElementById('btnCheckIHS').disabled = false;
    document.getElementById('chkTxt').classList.remove('d-none');
    document.getElementById('chkSpin').classList.add('d-none');
  }
});

// ── Modality → SOP ───────────────────────────────────────────────────────────
document.getElementById('fMod').addEventListener('change', setSop);
function setSop() { document.getElementById('fSop').value = SOP[document.getElementById('fMod').value]||''; }
setSop();

// ── LOINC saat prosedur diubah ────────────────────────────────────────────────
document.getElementById('fProc').addEventListener('input', function() { autoLoinc(this.value); });

// ── Preview JSON ──────────────────────────────────────────────────────────────
document.getElementById('btnPrev').addEventListener('click', () => {
  const d = collect(); if (!d) return;
  document.getElementById('pSR').textContent = JSON.stringify(makeSR(d), null, 2);
  document.getElementById('pIS').textContent = JSON.stringify(makeIS(d,'__SR_ID__'), null, 2);
  mP.show();
});

// ── Kirim ─────────────────────────────────────────────────────────────────────
document.getElementById('btnSend').addEventListener('click', async () => {
  if (!document.getElementById('fPIhs').value.trim()) {
    alert('Patient IHS Number wajib diisi.\nKlik tombol "\u2713 Cek IHS" untuk mendapatkan IHS dari SatuSehat.');
    return;
  }
  const d = collect(); if (!d) return;
  setLoad(true);
  const res = await post(d);
  setLoad(false);
  showRes(res);
});

function collect() {
  const loc = document.getElementById('fLocal').value;
  if (!loc) { alert('Tanggal/waktu wajib diisi'); return null; }
  document.getElementById('fUtc').value      = new Date(loc).toISOString().replace(/\.\d{3}Z$/,'+00:00');
  document.getElementById('fEncHidden').value = document.getElementById('fEncVis').value;
  return Object.fromEntries(new FormData(document.getElementById('frm')).entries());
}

function showRes(r) {
  const d = document.getElementById('divRes');
  d.classList.remove('d-none');
  if (r.ok) {
    d.innerHTML = `<div style="background:#e8f5e9;border-left:3px solid #2e7d32;padding:.5rem .75rem;border-radius:.3rem;margin-bottom:.5rem">
      \u2705 <b>${r.msg}</b></div>
      <div class="row g-1">
        <div class="col-md-6"><div class="fl-label">ServiceRequest ID</div>
          <div class="input-group input-group-sm">
            <input class="form-control font-monospace" id="bSR" value="${r.sr_id}" readonly>
            <button class="btn btn-outline-secondary" onclick="cp('bSR')">&#x1F4CB;</button>
          </div></div>
        <div class="col-md-6"><div class="fl-label">ImagingStudy ID</div>
          <div class="input-group input-group-sm">
            <input class="form-control font-monospace" id="bIS" value="${r.is_id}" readonly>
            <button class="btn btn-outline-secondary" onclick="cp('bIS')">&#x1F4CB;</button>
          </div></div>
      </div>`;
    setTimeout(() => location.reload(), 3000);
  } else {
    const det = r.detail  ? `<pre class="jbox mt-1">${JSON.stringify(r.detail,null,2)}</pre>`  : '';
    const pay = r.payload ? `<pre class="jbox mt-1">${JSON.stringify(r.payload,null,2)}</pre>` : '';
    d.innerHTML = `<div style="background:#ffebee;border-left:3px solid #c62828;padding:.5rem .75rem;border-radius:.3rem">
      \u274C <b>Gagal [${r.step}]:</b> ${r.msg}</div>${det}${pay}`;
  }
  d.scrollIntoView({behavior:'smooth'});
}

async function post(data) {
  const r = await fetch(location.href, {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams(data).toString()
  });
  return r.json();
}

function reset() {
  document.getElementById('frm').reset();
  document.getElementById('fEncVis').value    = '';
  document.getElementById('fEncHidden').value = '';
  document.getElementById('fNIK').value       = '';
  document.getElementById('fPIhs').value      = '';
  document.getElementById('fPIhs').classList.remove('ihs-verified','auto-filled');
  document.getElementById('ihsCheckStatus').innerHTML = '';
  document.getElementById('ihsCheckStatus').className = 'ihs-status';
  document.getElementById('ihsSavedBadge').classList.add('d-none');
  document.getElementById('divRes').classList.add('d-none');
  document.getElementById('autoLoadBar').classList.add('d-none');
  document.getElementById('autoLoadResult').classList.add('d-none');
  document.getElementById('mInfo').textContent = '';
  document.getElementById('fKfaC').value = '';
  document.getElementById('fKfaD').value = '';
  clearAutoFill();
}
function setLoad(on) {
  document.getElementById('btnSend').disabled = on;
  document.getElementById('bTxt').classList.toggle('d-none', on);
  document.getElementById('bSpin').classList.toggle('d-none', !on);
}
function cp(id) { navigator.clipboard.writeText(document.getElementById(id).value).then(()=>alert('Disalin!')); }
function fmtLocal(d) {
  const p = n => String(n).padStart(2,'0');
  return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

// ── Preview builders (mirror PHP) ─────────────────────────────────────────────
function makeSR(d) {
  // code.coding: LOINC (wajib) + KPTL (opsional)
  const coding = [{system:'http://loinc.org',code:d.loinc_code,display:d.loinc_display||''}];
  if (d.kptl_code?.trim()) coding.push({system:'http://terminology.kemkes.go.id/CodeSystem/kptl',code:d.kptl_code,display:d.kptl_display||''});

  // orderDetail: Modality (wajib) + AE Title (opsional) + KFA (opsional)
  const orderDetail = [
    {coding:[{system:'http://dicom.nema.org/resources/ontology/DCM',code:d.modality}],text:'Modality Code: '+d.modality},
  ];
  if (d.ae_title?.trim()) orderDetail.push({coding:[{system:'http://sys-ids.kemkes.go.id/ae-title',display:d.ae_title}]});
  if (d.kfa_code?.trim()) orderDetail.push({coding:[{system:'http://sys-ids.kemkes.go.id/kfa',code:d.kfa_code,display:d.kfa_display||''}]});

  const payload = {
    resourceType:'ServiceRequest',
    identifier:[
      {system:`http://sys-ids.kemkes.go.id/servicerequest/${ORG}`,value:d.pacs_acc},
      {use:'usual',type:{coding:[{system:'http://terminology.hl7.org/CodeSystem/v2-0203',code:'ACSN'}]},
       system:`http://sys-ids.kemkes.go.id/acsn/${ORG}`,value:d.pacs_acc},
    ],
    status:'active',intent:'original-order',priority:'routine',
    category:[{coding:[{system:'http://snomed.info/sct',code:'363679005',display:'Imaging'}]}],
    code:{coding,text:d.procedure_name},
    orderDetail,
    subject:{reference:'Patient/'+d.patient_ihs},
    encounter:{reference:'Encounter/'+d.encounter_id},
    occurrenceDateTime:d.started_utc,
    requester:{reference:'Practitioner/'+d.req_ihs,display:d.req_name||''},
  };
  // performer: opsional
  if (d.rad_ihs?.trim()) payload.performer = [{reference:'Practitioner/'+d.rad_ihs,display:d.rad_name||''}];
  // reasonCode: opsional
  if (d.icd10_code?.trim()) payload.reasonCode = [{coding:[{system:'http://hl7.org/fhir/sid/icd-10',code:d.icd10_code,display:d.icd10_display||''}]}];
  return payload;
}
function makeIS(d,srId) {
  return {resourceType:'ImagingStudy',
    identifier:[{use:'usual',type:{coding:[{system:'http://terminology.hl7.org/CodeSystem/v2-0203',code:'ACSN'}]},
      system:`http://sys-ids.kemkes.go.id/acsn/${ORG}`,value:d.pacs_acc},
      {system:'urn:dicom:uid',value:'urn:oid:'+d.study_uid.replace(/^urn:oid:/,'')}],
    status:'available',modality:[{system:'http://dicom.nema.org/resources/ontology/DCM',code:d.modality}],
    subject:{reference:'Patient/'+d.patient_ihs},started:d.started_utc,
    basedOn:[{reference:'ServiceRequest/'+srId}],numberOfSeries:1,numberOfInstances:1,
    interpreter:[{reference:'Practitioner/'+d.rad_ihs,display:d.rad_name}],
    series:[{uid:d.series_uid,number:1,modality:{system:'http://dicom.nema.org/resources/ontology/DCM',code:d.modality},
      numberOfInstances:1,started:d.started_utc,
      instance:[{uid:d.inst_uid,sopClass:{system:'urn:ietf:rfc:3986',code:'urn:oid:'+d.sop_uid.replace(/^urn:oid:/,'')},
        number:1,title:'ORIGINAL\\PRIMARY'}]}]};
}
</script>
</body>
</html>
