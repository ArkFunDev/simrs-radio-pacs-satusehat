<?php
/**
 * imagingstudy.php
 * Kirim FHIR ImagingStudy ke SatuSehat (basedOn ServiceRequest yang sudah ada).
 *
 * Perubahan keamanan dibanding versi awal:
 *  - wajib login (auth_check.php) + hanya peran radiologi/superadmin
 *  - verifikasi TLS aktif (sebelumnya dimatikan)
 *  - tidak ada fallback token ke endpoint production saat env = STAGING
 *  - token SatuSehat di-cache di file (bukan per-sesi), tidak pernah dikirim ke browser
 *  - CSRF token untuk semua aksi POST, whitelist nama aksi
 *  - display_errors dimatikan; error ditulis ke log server
 */
ini_set('log_errors', 1);
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/config.php';      // memulai sesi + memuat konfigurasi
require_once __DIR__ . '/auth_check.php';  // 401/redirect jika belum login
require_role(['radiologi', 'superadmin']); // petugas non-radiologi tidak boleh kirim ke SatuSehat

if (empty($_SESSION['csrf_is'])) {
    $_SESSION['csrf_is'] = bin2hex(random_bytes(32));
}
define('CSRF_IS', $_SESSION['csrf_is']);

define('SSAUTHURL',      SATUSEHAT_AUTH_URL);
define('SSBASEURL',      SATUSEHAT_BASE_URL);
define('SSCLIENTID',     SATUSEHAT_CLIENT_ID);
define('SSCLIENTSECRET', SATUSEHAT_CLIENT_SECRET);
define('SSORGID',        SATUSEHAT_ORG_ID);


try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    error_log('DB Error: ' . $e->getMessage());
    die('Koneksi database gagal.');
}


/* ── Escape wildcard LIKE (% dan _) dari input pengguna ── */
function likeEscape(string $s): string {
    return addcslashes($s, '%_\\');
}

/* ── Ambil UID dari dicom_url ── */
function extractAfterEq(?string $s): string {
    if (!$s) return '';
    $needle = 'studyUID=';
    $pos = stripos($s, $needle);
    if ($pos !== false) {
        $val = substr($s, $pos + strlen($needle));
        $amp = strpos($val, '&');
        return $amp !== false ? substr($val, 0, $amp) : $val;
    }
    $pos = strrpos($s, '=');
    return $pos !== false ? substr($s, $pos + 1) : $s;
}


/* ══════════════════════════════════════════════════════════════════
 *  TOKEN MANAGEMENT — cache di file server, auto-refresh, retry on 401
 *  Token tidak disimpan di sesi dan TIDAK PERNAH dikirim ke browser.
 * ══════════════════════════════════════════════════════════════════ */

function tokenCacheFile(): string {
    // satu file per kombinasi environment + client, di luar web root jika memungkinkan
    $dir = defined('TOKEN_CACHE_DIR') ? TOKEN_CACHE_DIR : sys_get_temp_dir();
    return rtrim($dir, '/\\') . '/ss_token_' . md5(SSAUTHURL . '|' . SSCLIENTID) . '.json';
}

function readCachedToken(): ?string {
    $f = tokenCacheFile();
    if (!is_file($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || empty($j['token']) || empty($j['exp'])) return null;
    return (time() + 120) < (int)$j['exp'] ? $j['token'] : null; // sisa < 2 menit → refresh
}

function writeCachedToken(string $tok, int $exp): void {
    $f = tokenCacheFile();
    @file_put_contents($f, json_encode(['token' => $tok, 'exp' => $exp]), LOCK_EX);
    @chmod($f, 0600);
}

/**
 * Ambil token baru dari SatuSehat OAuth2.
 * Hanya memakai endpoint dari config (STAGING atau PRODUCTION sesuai SATUSEHAT_ENV).
 * Tidak ada fallback ke environment lain, supaya kredensial staging tidak pernah
 * terkirim ke production dan sebaliknya.
 */
function fetchFreshToken(): ?string {
    @unlink(tokenCacheFile());

    $base = rtrim(SSAUTHURL, '/');
    if (substr($base, -12) !== '/accesstoken') $base .= '/accesstoken';
    $url = $base . '?grant_type=client_credentials';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => SSCLIENTID,
            'client_secret' => SSCLIENTSECRET,
        ]),
    ]);
    $body     = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $curlErr) {
        $_SESSION['ss_token_err'] = 'Tidak dapat menghubungi server token SatuSehat.';
        error_log("[SatuSehat Token] cURL error: {$curlErr}");
        return null;
    }

    $data = json_decode($body, true);
    $tok  = is_array($data) ? ($data['access_token'] ?? null) : null;
    if ($tok) {
        $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] - 60 : 55 * 60;
        writeCachedToken($tok, time() + max($expiresIn, 60));
        unset($_SESSION['ss_token_err']);
        return $tok;
    }

    // Log detail di server; ke browser hanya pesan singkat
    error_log("[SatuSehat Token] HTTP {$httpCode}: " . substr((string)$body, 0, 300));
    $_SESSION['ss_token_err'] = "Gagal mendapatkan token (HTTP {$httpCode}). Periksa kredensial & environment.";
    return null;
}

function getToken(): ?string {
    return readCachedToken() ?? fetchFreshToken();
}

function invalidateToken(): void {
    @unlink(tokenCacheFile());
}


/* ── FHIR GET — dengan auto-retry 1x jika 401 ── */
function fhirGet(string $tok, string $path): array {
    $result = fhirGetRaw($tok, $path);


    // Token expired di server → refresh & retry sekali
    if ($result['code'] === 401) {
        invalidateToken();
        $newTok = fetchFreshToken();
        if ($newTok) {
            $result = fhirGetRaw($newTok, $path);
        }
    }
    return $result;
}


function fhirGetRaw(string $tok, string $path): array {
    $ch = curl_init(rtrim(SSBASEURL, '/') . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tok],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($body, true)];
}


/* ── FHIR POST — dengan auto-retry 1x jika 401 ── */
function fhirPost(string $tok, string $res, array $pay, string $queryParam = ''): array {
    $result = fhirPostRaw($tok, $res, $pay, $queryParam);


    // Token expired di server → refresh & retry sekali
    if ($result['code'] === 401) {
        invalidateToken();
        $newTok = fetchFreshToken();
        if ($newTok) {
            $result = fhirPostRaw($newTok, $res, $pay, $queryParam);
        }
    }
    return $result;
}


function fhirPostRaw(string $tok, string $res, array $pay, string $queryParam = ''): array {
    $url = rtrim(SSBASEURL, '/') . '/' . $res;
    if ($queryParam) $url .= '?' . $queryParam;


    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $tok,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($pay, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($body, true)];
}


/* ── Build payload ImagingStudy ── */
function buildIS(array $d, string $orgId): array {
    $modCode     = strtoupper($d['modality']        ?? 'CR');
    $acc         = $d['pacs_acc']                   ?? '';
    $started     = $d['startedutc']                 ?? date('c');
    $srId        = $d['sr_id']                      ?? '';
    $studyUID    = $d['study_instance_uid']          ?? '';
    $seriesUID   = $d['series_uid']                 ?? '';
    $instanceUID = $d['instance_uid']               ?? '';
    $sopClass    = $d['sopclass']                   ?? '1.2.840.10008.5.1.4.1.1.1';


    $payload = [
        'resourceType' => 'ImagingStudy',
        'status'       => 'available',
        'subject'      => ['reference' => "Patient/{$d['patient_ihs']}"],
        'started'      => $started,
        'basedOn'      => $srId ? [['reference' => "ServiceRequest/{$srId}"]] : [],
        'identifier'   => [],
        'modality'     => [['system' => 'http://dicom.nema.org/resources/ontology/DCM', 'code' => $modCode]],
        'series'       => [[
            'uid'      => $seriesUID,
            'modality' => ['system' => 'http://dicom.nema.org/resources/ontology/DCM', 'code' => $modCode],
            'instance' => [[
                'uid'      => $instanceUID,
                'sopClass' => ['system' => 'urn:ietf:rfc:3986', 'code' => 'urn:oid:' . $sopClass],
            ]],
        ]],
    ];


    if ($acc) {
        $payload['identifier'][] = [
            'use'    => 'usual',
            'type'   => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0203', 'code' => 'ACSN']]],
            'system' => "http://sys-ids.kemkes.go.id/acsn/{$orgId}",
            'value'  => $acc,
        ];
    }
    if ($studyUID) {
        $payload['identifier'][] = [
            'system' => 'urn:dicom:uid',
            'value'  => 'urn:oid:' . $studyUID,
        ];
    }
    if (!empty($d['radihs'])) {
        $payload['interpreter'] = [['reference' => "Practitioner/{$d['radihs']}"]];
    }


    return $payload;
}


/* ── Render satu baris tabel sebagai HTML ── */
function renderRowHtml(array $r, int $idx): string {
    $hasIs = !empty($r['is_id']);
    $id    = (int)($r['id'] ?? 0);
    $noRawat = htmlspecialchars($r['no_rawat'] ?? '');
    $nmPasien = htmlspecialchars($r['nm_pasien'] ?? '');
    $acc = htmlspecialchars($r['pacs_acc'] ?? '');
    $procName = htmlspecialchars($r['procedure_name'] ?? '');
    $procDisplay = $procName ?: '&mdash;';
    $modality = !empty($r['modality']) ? htmlspecialchars($r['modality']) : '&mdash;';

    $srBadge = !empty($r['sr_id'])
        ? '<span class="srid-ref">' . htmlspecialchars(substr($r['sr_id'],0,18)) . '&hellip;</span>'
        : '<span class="text-muted fl">&mdash;</span>';

    $isBadge = $hasIs
        ? '<span class="isid-badge">&#x2705; ' . htmlspecialchars(substr($r['is_id'],0,18)) . '&hellip;</span>'
        : '<span class="text-muted fl">belum</span>';

    $btnLabel = $hasIs ? 'Ulang' : 'Kirim IS';
    $hasIsInt = $hasIs ? 1 : 0;

    return <<<HTML
<tr data-id="{$id}">
  <td class="text-center"><input type="checkbox" class="row-check" data-id="{$id}" data-has-is="{$hasIsInt}"></td>
  <td class="text-muted">{$idx}</td>
  <td><code class="small">{$noRawat}</code></td>
  <td class="fw-semibold">{$nmPasien}</td>
  <td><code>{$acc}</code></td>
  <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{$procName}">{$procDisplay}</td>
  <td class="fw-bold">{$modality}</td>
  <td>{$srBadge}</td>
  <td>{$isBadge}</td>
  <td>
    <button class="btn btn-sm btn-success py-0 px-2 btn-kirim" data-id="{$id}">{$btnLabel}</button>
  </td>
</tr>
HTML;
}


/* ══════════════════════════════════════════════════════════════════
 *  AJAX HANDLERS
 * ══════════════════════════════════════════════════════════════════ */
const ALLOWED_ACTS = ['ping_token', 'send', 'row', 'cek_ihs', 'load_more', 'get_all_ids', 'send_one'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['act'])) {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF: token dikirim JS lewat header X-CSRF-Token atau field csrf
    $sentCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!is_string($sentCsrf) || !hash_equals(CSRF_IS, $sentCsrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Sesi tidak valid, muat ulang halaman.', 'step' => 'CSRF']);
        exit;
    }
    if (!in_array($_POST['act'], ALLOWED_ACTS, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal']);
        exit;
    }


    switch ($_POST['act']) {


        /* ── Ping token (dipanggil JS sebelum kirim untuk pre-warm token) ── */
        case 'ping_token':
            $tok = getToken();
            if ($tok) {
                $cached = json_decode((string)@file_get_contents(tokenCacheFile()), true);
                $exp    = (int)($cached['exp'] ?? 0);
                echo json_encode(['ok' => true, 'expires' => $exp, 'remain' => max(0, $exp - time())]);
            } else {
                echo json_encode([
                    'ok'  => false,
                    'msg' => $_SESSION['ss_token_err'] ?? 'Gagal mendapatkan token',
                ]);
            }
            exit;


        /* ── Kirim ImagingStudy ── */
        case 'send':
            try {
                $d   = $_POST;
                $tok = getToken();


                if (!$tok) {
                    echo json_encode([
                        'ok'   => false,
                        'msg'  => 'Gagal mendapatkan token: ' . ($_SESSION['ss_token_err'] ?? 'Tidak ada response dari server'),
                        'step' => 'Token',
                    ]);
                    exit;
                }
                if (empty($d['patient_ihs'])) {
                    echo json_encode(['ok' => false, 'msg' => 'Patient ID (IHS) kosong', 'step' => 'Validasi']);
                    exit;
                }
                // IHS number & ServiceRequest ID masuk ke URL referensi FHIR: batasi karakternya
                if (!preg_match('/^[A-Za-z0-9\-\.]{3,64}$/', (string)$d['patient_ihs'])
                    || !preg_match('/^[A-Za-z0-9\-\.]{3,64}$/', (string)($d['sr_id'] ?? ''))) {
                    echo json_encode(['ok' => false, 'msg' => 'Format IHS / ServiceRequest ID tidak valid', 'step' => 'Validasi']);
                    exit;
                }
                if (empty($d['sr_id'])) {
                    echo json_encode(['ok' => false, 'msg' => 'ServiceRequest ID kosong', 'step' => 'Validasi']);
                    exit;
                }


                $orgId      = SSORGID;
                $acc        = $d['pacs_acc'] ?? '';
                $is         = buildIS($d, $orgId);
                $queryParam = $acc
                    ? 'identifier=' . urlencode("http://sys-ids.kemkes.go.id/acsn/{$orgId}|{$acc}")
                    : '';


                // fhirPost sudah include auto-retry 1x jika 401
                $res = fhirPost($tok, 'ImagingStudy', $is, $queryParam);


                if ($res['code'] !== 201) {
                    echo json_encode([
                        'ok'      => false,
                        'msg'     => 'HTTP ' . $res['code'],
                        'step'    => 'ImagingStudy',
                        'detail'  => $res['body'],
                        'payload' => $is,
                    ]);
                    exit;
                }


                $isId = $res['body']['id'] ?? null;
                $pdo->prepare("UPDATE khanza_pacs_mapping SET is_id = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$isId, (int)($d['mapid'] ?? 0)]);


                echo json_encode(['ok' => true, 'msg' => 'ImagingStudy berhasil dikirim', 'isid' => $isId]);


            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage(), 'step' => 'Exception']);
            }
            break;


        /* ── Ambil 1 baris mapping ── */
        case 'row':
            $s = $pdo->prepare("
                SELECT m.*,
                       p.no_ktp            AS nik_pasien,
                       sr.id_servicerequest AS sr_id_fhir
                FROM   khanza_pacs_mapping m
                LEFT JOIN reg_periksa        rp   ON rp.no_rawat    = m.no_rawat
                LEFT JOIN pasien             p    ON p.no_rkm_medis = rp.no_rkm_medis
                LEFT JOIN permintaan_radiologi prad ON prad.no_rawat = m.no_rawat
                LEFT JOIN satu_sehat_servicerequest_radiologi sr ON sr.noorder = prad.noorder
                WHERE  m.id = ?
                /* pacs_acc = substr(noorder,4) + index pemeriksaan (1 digit). Utamakan order
                   yang nomornya cocok dengan accession, baru fallback ke order lain di no_rawat
                   yang sama. Tanpa ini, pasien dengan >1 order bisa terpasang ke SR yang salah. */
                ORDER BY (prad.noorder = CONCAT('PR20', LEFT(m.pacs_acc, CHAR_LENGTH(m.pacs_acc) - 1))) DESC,
                         (sr.id_servicerequest IS NOT NULL) DESC
                LIMIT 1
            ");
            $s->execute([(int)$_POST['id']]);
            $row = $s->fetch();


            if ($row && empty($row['sr_id']) && !empty($row['sr_id_fhir'])) {
                $pdo->prepare("UPDATE khanza_pacs_mapping SET sr_id = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$row['sr_id_fhir'], $row['id']]);
                $row['sr_id'] = $row['sr_id_fhir'];
            }
            if ($row) {
                $uid = '';
                foreach ($row as $col => $val) {
                    if (is_string($val) && stripos($val, 'studyUID=') !== false) {
                        $uid = extractAfterEq($val);
                        break;
                    }
                }
                if (!$uid && !empty($row['dicom_url'])) {
                    $uid = extractAfterEq($row['dicom_url']);
                }
                $row['_study_uid']    = $uid;
                $row['_series_uid']   = $uid;
                $row['_instance_uid'] = $uid;
                $row['_uid_debug']    = $uid;
            }
            echo json_encode($row);
            break;


        /* ── Cek IHS by NIK ── */
        case 'cek_ihs':
            $nik = trim($_POST['nik'] ?? '');
            if (!$nik) { echo json_encode(['ok' => false, 'msg' => 'NIK kosong']); exit; }
            try {
                $tok = getToken();
                if (!$tok) {
                    echo json_encode([
                        'ok'  => false,
                        'msg' => 'Gagal token SatuSehat: ' . ($_SESSION['ss_token_err'] ?? 'Tidak ada response'),
                    ]);
                    exit;
                }
                $path = 'Patient?identifier=' . urlencode('https://fhir.kemkes.go.id/id/nik|' . $nik);
                $res  = fhirGet($tok, $path);


                if ($res['code'] !== 200) {
                    echo json_encode(['ok' => false, 'msg' => 'HTTP ' . $res['code'], 'detail' => $res['body']]);
                    exit;
                }
                $total = $res['body']['total'] ?? 0;
                if ($total < 1 || empty($res['body']['entry'])) {
                    echo json_encode(['ok' => false, 'msg' => 'Pasien tidak ditemukan di SatuSehat']);
                    exit;
                }
                $ihs         = '';
                $identifiers = $res['body']['entry'][0]['resource']['identifier'] ?? [];
                foreach ($identifiers as $id) {
                    if (($id['system'] ?? '') === 'https://fhir.kemkes.go.id/id/ihs-number') {
                        $ihs = $id['value'] ?? '';
                        break;
                    }
                }
                if (!$ihs) {
                    echo json_encode(['ok' => false, 'msg' => 'IHS number tidak ditemukan di response']);
                    exit;
                }
                echo json_encode(['ok' => true, 'ihs' => $ihs]);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
            break;


        /* ── Load more rows untuk infinite scroll ── */
        case 'load_more':
            $qFilter = trim($_POST['q'] ?? '');
            $dt1Filter = trim($_POST['dt1'] ?? '');
            $dt2Filter = trim($_POST['dt2'] ?? '');
            $pgMore  = max(1, (int)($_POST['pg'] ?? 1));
            $ppMore  = 25;
            $offMore = ($pgMore - 1) * $ppMore;

            $wMore = "WHERE 1=1";
            $pMore = [];
            if ($dt1Filter) {
                $wMore .= " AND DATE(created_at) >= ?";
                $pMore[] = $dt1Filter;
            }
            if ($dt2Filter) {
                $wMore .= " AND DATE(created_at) <= ?";
                $pMore[] = $dt2Filter;
            }
            if ($qFilter) {
                $wMore .= " AND (no_rawat LIKE ? OR nm_pasien LIKE ? OR pacs_acc LIKE ?)";
                $pMore[] = "%" . likeEscape($qFilter) . "%";
                $pMore[] = "%" . likeEscape($qFilter) . "%";
                $pMore[] = "%" . likeEscape($qFilter) . "%";
            }

                        $aksiMore = trim($_POST['aksi'] ?? '');
            if ($aksiMore === 'kirim') {
                $wMore .= " AND (is_id IS NULL OR is_id = '')";
            } elseif ($aksiMore === 'ulang') {
                $wMore .= " AND (is_id IS NOT NULL AND is_id <> '')";
            }

            $cntMore = $pdo->prepare("SELECT COUNT(*) FROM khanza_pacs_mapping $wMore");
            $cntMore->execute($pMore);
            $totalMore = (int)$cntMore->fetchColumn();
            $hasMore = ($offMore + $ppMore) < $totalMore;

            $rsMore = $pdo->prepare("SELECT * FROM khanza_pacs_mapping $wMore ORDER BY created_at DESC LIMIT $ppMore OFFSET $offMore");
            $rsMore->execute($pMore);
            $rowsMore = $rsMore->fetchAll();

            $htmlMore = '';
            foreach ($rowsMore as $iM => $rM) {
                $htmlMore .= renderRowHtml($rM, $offMore + $iM + 1);
            }

            echo json_encode(['html' => $htmlMore, 'hasMore' => $hasMore, 'total' => $totalMore]);
            break;


        /* ── Get all IDs matching filter (untuk kirim semua data) ── */
        case 'get_all_ids':
            $qAll = trim($_POST['q'] ?? '');
            $dt1All = trim($_POST['dt1'] ?? '');
            $dt2All = trim($_POST['dt2'] ?? '');

            $wAll = "WHERE 1=1";
            $pAll = [];
            if ($dt1All) {
                $wAll .= " AND DATE(created_at) >= ?";
                $pAll[] = $dt1All;
            }
            if ($dt2All) {
                $wAll .= " AND DATE(created_at) <= ?";
                $pAll[] = $dt2All;
            }
            if ($qAll) {
                $wAll .= " AND (no_rawat LIKE ? OR nm_pasien LIKE ? OR pacs_acc LIKE ?)";
                $pAll[] = "%" . likeEscape($qAll) . "%";
                $pAll[] = "%" . likeEscape($qAll) . "%";
                $pAll[] = "%" . likeEscape($qAll) . "%";
            }
            
                        $aksiAll = trim($_POST['aksi'] ?? '');
            if ($aksiAll === 'kirim') {
                $wAll .= " AND (is_id IS NULL OR is_id = '')";
            } elseif ($aksiAll === 'ulang') {
                $wAll .= " AND (is_id IS NOT NULL AND is_id <> '')";
            }

            $rsAll = $pdo->prepare("SELECT id, no_rawat, nm_pasien, is_id FROM khanza_pacs_mapping $wAll ORDER BY created_at DESC");
            $rsAll->execute($pAll);
            $itemsAll = $rsAll->fetchAll();

            echo json_encode(['items' => $itemsAll, 'total' => count($itemsAll)]);
            break;


        /* ── Send one by mapid (untuk batch kirim) ── */
        case 'send_one':
            try {
                $mapId = (int)($_POST['mapid'] ?? 0);
                if (!$mapId) {
                    echo json_encode(['ok' => false, 'msg' => 'MapID kosong', 'step' => 'Validasi', 'no_rawat' => '', 'nm_pasien' => '', 'already_sent' => false]);
                    exit;
                }

                // Load row data (same SQL as 'row' action)
                $s1 = $pdo->prepare("
                    SELECT m.*,
                           p.no_ktp            AS nik_pasien,
                           sr.id_servicerequest AS sr_id_fhir
                    FROM   khanza_pacs_mapping m
                    LEFT JOIN reg_periksa        rp   ON rp.no_rawat    = m.no_rawat
                    LEFT JOIN pasien             p    ON p.no_rkm_medis = rp.no_rkm_medis
                    LEFT JOIN permintaan_radiologi prad ON prad.no_rawat = m.no_rawat
                    LEFT JOIN satu_sehat_servicerequest_radiologi sr ON sr.noorder = prad.noorder
                    WHERE  m.id = ?
                    ORDER BY (prad.noorder = CONCAT('PR20', LEFT(m.pacs_acc, CHAR_LENGTH(m.pacs_acc) - 1))) DESC,
                             (sr.id_servicerequest IS NOT NULL) DESC
                    LIMIT 1
                ");
                $s1->execute([$mapId]);
                $row1 = $s1->fetch();

                if (!$row1) {
                    echo json_encode(['ok' => false, 'msg' => 'Data tidak ditemukan', 'step' => 'Validasi', 'no_rawat' => '', 'nm_pasien' => '', 'already_sent' => false]);
                    exit;
                }

                $alreadySent = !empty($row1['is_id']);

                // Auto-fill sr_id if empty
                if (empty($row1['sr_id']) && !empty($row1['sr_id_fhir'])) {
                    $pdo->prepare("UPDATE khanza_pacs_mapping SET sr_id = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$row1['sr_id_fhir'], $row1['id']]);
                    $row1['sr_id'] = $row1['sr_id_fhir'];
                }

                // Extract UIDs
                $uid1 = '';
                foreach ($row1 as $col1 => $val1) {
                    if (is_string($val1) && stripos($val1, 'studyUID=') !== false) {
                        $uid1 = extractAfterEq($val1);
                        break;
                    }
                }
                if (!$uid1 && !empty($row1['dicom_url'])) {
                    $uid1 = extractAfterEq($row1['dicom_url']);
                }

                // Auto-fetch IHS if empty and NIK available
                if (empty($row1['patient_ihs']) && !empty($row1['nik_pasien'])) {
                    $tokAuto = getToken();
                    if ($tokAuto) {
                        $pathAuto = 'Patient?identifier=' . urlencode('https://fhir.kemkes.go.id/id/nik|' . $row1['nik_pasien']);
                        $resAuto  = fhirGet($tokAuto, $pathAuto);
                        if ($resAuto['code'] === 200 && !empty($resAuto['body']['entry'])) {
                            $ihsAuto = '';
                            $idsAuto = $resAuto['body']['entry'][0]['resource']['identifier'] ?? [];
                            foreach ($idsAuto as $idAuto) {
                                if (($idAuto['system'] ?? '') === 'https://fhir.kemkes.go.id/id/ihs-number') {
                                    $ihsAuto = $idAuto['value'] ?? '';
                                    break;
                                }
                            }
                            if ($ihsAuto) {
                                $row1['patient_ihs'] = $ihsAuto;
                                $pdo->prepare("UPDATE khanza_pacs_mapping SET patient_ihs = ?, updated_at = NOW() WHERE id = ?")
                                    ->execute([$ihsAuto, $row1['id']]);
                            }
                        }
                    }
                }

                // Validate required fields
                if (empty($row1['patient_ihs'])) {
                    echo json_encode(['ok' => false, 'msg' => 'Patient ID (IHS) kosong', 'step' => 'Validasi', 'no_rawat' => $row1['no_rawat'] ?? '', 'nm_pasien' => $row1['nm_pasien'] ?? '', 'already_sent' => $alreadySent]);
                    exit;
                }
                if (empty($row1['sr_id'])) {
                    echo json_encode(['ok' => false, 'msg' => 'ServiceRequest ID kosong', 'step' => 'Validasi', 'no_rawat' => $row1['no_rawat'] ?? '', 'nm_pasien' => $row1['nm_pasien'] ?? '', 'already_sent' => $alreadySent]);
                    exit;
                }

                // Get token
                $tok1 = getToken();
                if (!$tok1) {
                    echo json_encode(['ok' => false, 'msg' => 'Gagal mendapatkan token: ' . ($_SESSION['ss_token_err'] ?? ''), 'step' => 'Token', 'no_rawat' => $row1['no_rawat'] ?? '', 'nm_pasien' => $row1['nm_pasien'] ?? '', 'already_sent' => $alreadySent]);
                    exit;
                }

                // Prepare startedutc
                $dtStr1 = $row1['studydatetime'] ?? $row1['created_at'] ?? date('Y-m-d H:i:s');
                $dtObj1 = new DateTime($dtStr1);
                $dtObj1->setTimezone(new DateTimeZone('UTC'));
                $startedUtc1 = $dtObj1->format('Y-m-d\TH:i:s+00:00');

                // Build data array
                $d1 = [
                    'patient_ihs'       => $row1['patient_ihs'] ?? '',
                    'pacs_acc'          => $row1['pacs_acc'] ?? '',
                    'sr_id'             => $row1['sr_id'],
                    'study_instance_uid' => $uid1,
                    'series_uid'        => $uid1,
                    'instance_uid'      => $uid1,
                    'sopclass'          => $row1['sopclass'] ?? '1.2.840.10008.5.1.4.1.1.1',
                    'modality'          => $row1['modality'] ?? 'CR',
                    'radihs'            => $row1['dokter_rad_ihs'] ?? '',
                    'startedutc'        => $startedUtc1,
                ];

                // Build and send
                $orgId1 = SSORGID;
                $is1    = buildIS($d1, $orgId1);
                $queryParam1 = $d1['pacs_acc']
                    ? 'identifier=' . urlencode("http://sys-ids.kemkes.go.id/acsn/{$orgId1}|{$d1['pacs_acc']}")
                    : '';

                $res1 = fhirPost($tok1, 'ImagingStudy', $is1, $queryParam1);

                if ($res1['code'] !== 201) {
                    echo json_encode([
                        'ok'           => false,
                        'msg'          => 'HTTP ' . $res1['code'],
                        'step'         => 'ImagingStudy',
                        'detail'       => $res1['body'],
                        'no_rawat'     => $row1['no_rawat'] ?? '',
                        'nm_pasien'    => $row1['nm_pasien'] ?? '',
                        'already_sent' => $alreadySent,
                    ]);
                    exit;
                }

                $isId1 = $res1['body']['id'] ?? null;
                $pdo->prepare("UPDATE khanza_pacs_mapping SET is_id = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$isId1, $mapId]);

                echo json_encode([
                    'ok'           => true,
                    'msg'          => 'ImagingStudy berhasil dikirim',
                    'isid'         => $isId1,
                    'no_rawat'     => $row1['no_rawat'] ?? '',
                    'nm_pasien'    => $row1['nm_pasien'] ?? '',
                    'already_sent' => $alreadySent,
                ]);

            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage(), 'step' => 'Exception', 'no_rawat' => '', 'nm_pasien' => '', 'already_sent' => false]);
            }
            break;
    }
    exit;
}


/* ── Pagination + Filter listing ── */
$q    = trim($_GET['q'] ?? '');
$dt1  = trim($_GET['dt1'] ?? date('Y-m-d')); // Default tanggal hari ini
$dt2  = trim($_GET['dt2'] ?? date('Y-m-d')); // Default tanggal hari ini
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt1)) $dt1 = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt2)) $dt2 = date('Y-m-d');
$pg   = 1; // Always start from page 1 for infinite scroll
$pp   = 25;
$off  = 0;
$w    = "WHERE 1=1";
$p    = [];
if ($dt1) {
    $w .= " AND DATE(created_at) >= ?";
    $p[] = $dt1;
}
if ($dt2) {
    $w .= " AND DATE(created_at) <= ?";
    $p[] = $dt2;
}
if ($q) {
    $w .= " AND (no_rawat LIKE ? OR nm_pasien LIKE ? OR pacs_acc LIKE ?)";
    $likeQ = "%" . likeEscape($q) . "%";
    $p  = array_merge($p, [$likeQ, $likeQ, $likeQ]);
}

$aksi = trim($_GET['aksi'] ?? '');
if (!in_array($aksi, ['kirim', 'ulang'], true)) $aksi = '';
if ($aksi === 'kirim') {
    $w .= " AND (is_id IS NULL OR is_id = '')";
} elseif ($aksi === 'ulang') {
    $w .= " AND (is_id IS NOT NULL AND is_id <> '')";
}

$cnt   = $pdo->prepare("SELECT COUNT(*) FROM khanza_pacs_mapping $w");
$cnt->execute($p);
$total = (int)$cnt->fetchColumn();
$hasMoreInit = ($off + $pp) < $total;
$rs    = $pdo->prepare("SELECT * FROM khanza_pacs_mapping $w ORDER BY created_at DESC LIMIT $pp OFFSET $off");
$rs->execute($p);
$rows  = $rs->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ImagingStudy SatuSehat</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
body{background:#f4f6f9;font-size:.82rem}
.topbar{background:#00695c;color:#fff;padding:.6rem 1rem;display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap}
.topbar h6{margin:0;font-size:.95rem;font-weight:700}
.env-badge{background:rgba(255,255,255,.2);border-radius:.3rem;padding:.1rem .5rem;font-size:.7rem;font-weight:700}
.card{border:0;box-shadow:0 1px 4px rgba(0,0,0,.1);border-radius:.5rem}
.table thead th{background:#37474f;color:#fff;font-size:.75rem;padding:.4rem .5rem;white-space:nowrap;border:0}
.table td{padding:.35rem .5rem;font-size:.78rem;vertical-align:middle}
.table tbody tr:hover{background:#e8f5e9}
.fl{font-size:.72rem;color:#546e7a}
.fl-label{font-size:.7rem;font-weight:600;margin-bottom:.15rem;color:#455a64}
.opt-label{font-size:.7rem;font-weight:600;margin-bottom:.15rem;color:#78909c}
.sec{border-left:3px solid #00695c;padding:.4rem .7rem;background:#f8f9fa;border-radius:0 .4rem .4rem 0;margin-bottom:.6rem}
.sec-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#00695c;margin-bottom:.5rem}
input.form-control,select.form-select{font-size:.78rem;padding:.25rem .45rem;height:auto}
input[readonly]{background:#f1f3f4!important;color:#546e7a}
.auto-hint{font-size:.65rem;color:#2e7d32;margin-top:.1rem;display:block}
.auto-filled{border-color:#4caf50!important;background:#f1f8e9!important}
pre.jbox{background:#1e1e2e;color:#cdd6f4;border-radius:.4rem;padding:.6rem;font-size:.7rem;max-height:300px;overflow:auto;margin:0}
.isid-badge{font-size:.67rem;color:#1b5e20;font-weight:600;word-break:break-all}
.srid-ref{font-size:.67rem;color:#0d47a1;word-break:break-all}
.btn-cek-ihs{font-size:.7rem;padding:.2rem .55rem;white-space:nowrap}
#ihsStatus{font-size:.68rem;margin-top:.25rem;min-height:1rem}
/* Token status indicator */
#tokenBar{font-size:.68rem;padding:.25rem .6rem;border-radius:.3rem;margin-bottom:.5rem;display:flex;align-items:center;gap:.4rem}
#tokenBar.ok{background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7}
#tokenBar.err{background:#ffebee;color:#b71c1c;border:1px solid #ef9a9a}
#tokenBar.loading{background:#e3f2fd;color:#0d47a1;border:1px solid #90caf9}
/* Sticky table header */
#tableScrollWrap thead th{position:sticky;top:0;z-index:10;background:#37474f;color:#fff;}
#tableScrollWrap thead th:first-child{z-index:11}
/* Infinite scroll */
#loadMoreIndicator{text-align:center;padding:1rem;color:#546e7a}
#noMoreData{text-align:center;padding:.75rem;color:#90a4ae;font-size:.75rem}
/* Batch modal */
.batch-row-ok{color:#1b5e20}
.batch-row-fail{color:#b71c1c}
.batch-row-warn{color:#e65100}
</style>
</head>
<body>
<div class="topbar">
  <span style="font-size:1.3rem">&#x1F9BB;</span>
  <div>
    <h6>ImagingStudy &ndash; SatuSehat</h6>
    <small>basedOn ServiceRequest &nbsp;&nbsp; Org: <b><?= htmlspecialchars(SSORGID) ?></b>
      &nbsp;<span class="env-badge"><?= strpos(SSBASEURL,'stg')!==false?'STG':(strpos(SSBASEURL,'dev')!==false?'DEV':'PROD') ?></span>
    </small>
  </div>
</div>


<div class="container-fluid px-3">
<form method="GET" class="card mb-2" id="filterForm">
  <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-end">
    <div>
      <div class="fl-label">Dari Tanggal</div>
      <input type="date" name="dt1" class="form-control form-control-sm" style="width:150px"
             value="<?= htmlspecialchars($dt1) ?>">
    </div>
    <div>
      <div class="fl-label">Sampai Tanggal</div>
      <input type="date" name="dt2" class="form-control form-control-sm" style="width:150px"
             value="<?= htmlspecialchars($dt2) ?>">
    </div>
    <div>
      <div class="fl-label">Cari</div>
      <input type="text" name="q" class="form-control form-control-sm" style="width:260px"
             placeholder="No rawat / Nama / Acc" value="<?= htmlspecialchars($q) ?>">
    </div>

        <div>
      <div class="fl-label">Aksi</div>
      <select name="aksi" class="form-select form-select-sm" style="width:190px">
        <option value="">Semua</option>
        <option value="kirim" <?= $aksi === 'kirim' ? 'selected' : '' ?>>Kirim IS (belum terkirim)</option>
        <option value="ulang" <?= $aksi === 'ulang' ? 'selected' : '' ?>>Ulang (sudah terkirim)</option>
      </select>
    </div>

    <div class="d-flex gap-1 align-items-end">
      <button class="btn btn-sm btn-primary px-3">Cari</button>
      <a href="?" class="btn btn-sm btn-outline-secondary">Reset</a>
    </div>
    <div class="d-flex gap-1 align-items-end">
      <button type="button" id="btnSendChecked" class="btn btn-sm btn-warning px-3" disabled>Kirim Terpilih (<span id="checkedCount">0</span>)</button>
      <button type="button" id="btnSendAll" class="btn btn-sm btn-info text-white px-3">Kirim Semua Data</button>
    </div>
    <div class="ms-auto"><span class="badge bg-secondary"><?= number_format($total) ?> data</span></div>
  </div>
</form>


<div class="card">
<div class="table-responsive" id="tableScrollWrap" style="max-height:65vh;overflow-y:auto;position:relative">
<table class="table table-bordered mb-0" id="mainTable">
<thead><tr>
  <th style="width:32px"><input type="checkbox" id="checkAll" title="Pilih semua yang tampil"></th>
  <th>#</th><th>No Rawat</th><th>Nama Pasien</th>
  <th>ACSN / No. Accession</th><th>Prosedur</th><th>Mod</th>
  <th>SR ID</th><th>IS ID</th><th>Aksi</th>
</tr></thead>
<tbody id="tableBody">
<?php if (empty($rows)): ?>
<tr><td colspan="10" class="text-center text-muted py-4">Tidak ada data</td></tr>
<?php else: foreach ($rows as $i => $r): ?>
<?= renderRowHtml($r, $off + $i + 1) ?>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<div id="loadMoreIndicator" class="d-none">
  <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
  <span class="ms-2 text-muted small">Memuat data&hellip;</span>
</div>
<div id="noMoreData" class="<?= $hasMoreInit ? 'd-none' : '' ?>">
  &mdash; Semua data telah dimuat &mdash;
</div>
</div>
</div>


<!-- ══ MODAL KIRIM ══════════════════════════════════════════════════ -->
<div class="modal fade" id="mKirim" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header bg-success text-white">
  <span class="fw-bold" style="font-size:.85rem">Kirim ImagingStudy &nbsp;<small id="mInfo" class="opacity-75"></small></span>
  <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">

  <!-- Token status bar -->
  <div id="tokenBar" class="loading">
    <span id="tokenIcon">&#x23F3;</span>
    <span id="tokenMsg">Memeriksa koneksi SatuSehat&hellip;</span>
    <button type="button" id="btnRefreshToken" class="btn btn-xs btn-outline-secondary ms-auto d-none"
            style="font-size:.62rem;padding:.1rem .4rem;line-height:1.2">&#x21BB; Refresh Token</button>
  </div>

  <div id="divLoad" class="d-none alert alert-info py-1 px-2 mb-2" style="font-size:.72rem">Memuat data&hellip;</div>
  <form id="frm">
    <input type="hidden" name="act"        value="send">
    <input type="hidden" name="mapid"      id="fMapId">
    <input type="hidden" name="startedutc" id="fUtc">

    <!-- ── Referensi ── -->
    <div class="sec"><div class="sec-title">Referensi</div>
    <div class="row g-1">
      <div class="col-md-3"><div class="fl-label">No Rawat</div>
        <input id="vRawat" class="form-control form-control-sm" readonly></div>
      <div class="col-md-3"><div class="fl-label">ACSN / No. Accession</div>
        <input name="pacs_acc" id="fAcc" class="form-control form-control-sm" readonly></div>
      <div class="col-md-3"><div class="fl-label">Modality</div>
        <input name="modality" id="fMod" class="form-control form-control-sm" readonly></div>
      <div class="col-md-3"><div class="fl-label">Prosedur</div>
        <input name="procedure_name" id="fProc" class="form-control form-control-sm" readonly></div>

      <div class="col-md-3">
        <div class="fl-label">NIK</div>
        <input id="fNIK" class="form-control form-control-sm font-monospace" readonly placeholder="otomatis dari DB">
      </div>
      <div class="col-md-1 d-flex align-items-end pb-0">
        <button type="button" id="btnCekIHS" class="btn btn-sm btn-outline-primary btn-cek-ihs w-100">
          Cek IHS
        </button>
      </div>
      <div class="col-md-8">
        <div class="fl-label">Patient ID (IHS) <span style="color:red">*</span></div>
        <input name="patient_ihs" id="fPIhs" class="form-control form-control-sm font-monospace" required placeholder="P-...">
        <span id="ihsStatus"></span>
      </div>
    </div></div>

    <!-- ── ServiceRequest ── -->
    <div class="sec"><div class="sec-title">basedOn &mdash; ServiceRequest <span style="color:red">*</span></div>
    <div class="row g-1">
      <div class="col-12"><div class="fl-label">ServiceRequest ID</div>
        <input name="sr_id" id="fSR" class="form-control form-control-sm font-monospace fw-bold" required placeholder="uuid sr_id">
        <div class="auto-hint">&#x2705; Terisi otomatis dari kolom sr_id</div>
      </div>
    </div></div>

    <!-- ── DICOM UIDs ── -->
    <div class="sec"><div class="sec-title">DICOM UIDs</div>
    <div class="row g-1">
      <div class="col-md-12"><div class="fl-label">Study Instance UID <span class="fl">(tanpa "urn:oid:")</span></div>
        <input name="study_instance_uid" id="fSIUID" class="form-control form-control-sm font-monospace" placeholder="1.2.840.xxxxx" readonly></div>
      <div class="col-md-6"><div class="fl-label">Series UID</div>
        <input name="series_uid" id="fSerUID" class="form-control form-control-sm font-monospace" placeholder="1.2.840.xxxxx" readonly></div>
      <div class="col-md-6"><div class="fl-label">Instance UID</div>
        <input name="instance_uid" id="fInstUID" class="form-control form-control-sm font-monospace" placeholder="1.2.840.xxxxx" readonly></div>
      <div class="col-md-4"><div class="opt-label">SOP Class UID <span class="fl">opsional</span></div>
        <input name="sopclass" id="fSOP" class="form-control form-control-sm" placeholder="1.2.840.10008..."></div>
    </div></div>

    <!-- ── Detail Studi ── -->
    <div class="sec"><div class="sec-title">Detail Studi</div>
    <div class="row g-1">
      <div class="col-md-4"><div class="fl-label">Waktu Mulai (started) <span style="color:red">*</span> <span class="fl">&rarr;UTC</span></div>
        <input type="datetime-local" name="startedlocal" id="fLocal" class="form-control form-control-sm" required></div>
      <div class="col-md-4"><div class="opt-label">Practitioner ID (IHS) <span class="fl">opsional</span></div>
        <input name="radihs" id="fRI" class="form-control form-control-sm font-monospace" placeholder="10004684578"></div>
      <div class="col-md-4"><div class="opt-label">Nama Radiolog <span class="fl">opsional</span></div>
        <input name="radname" id="fRN" class="form-control form-control-sm" placeholder="dr. Budi Sp.Rad"></div>
    </div></div>
  </form>
  <div id="divRes" class="d-none mt-2"></div>
</div>
<div class="modal-footer">
  <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
  <button type="button" id="btnPrev" class="btn btn-sm btn-outline-dark">Preview JSON</button>
  <button type="button" id="btnSend" class="btn btn-sm btn-success px-4" disabled>
    <span id="bTxt">Kirim</span>
    <span id="bSpin" class="d-none" style="font-size:.7rem">Mengirim&hellip;</span>
  </button>
</div>
</div></div></div>


<!-- ══ MODAL PREVIEW ════════════════════════════════════════════════ -->
<div class="modal fade" id="mPrev" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
<div class="modal-header bg-dark text-white py-2">
  <small class="fw-bold">Preview JSON &mdash; ImagingStudy</small>
  <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body p-2"><pre class="jbox" id="pIS"></pre></div>
</div></div></div>


<!-- ══ MODAL BATCH ══════════════════════════════════════════════════ -->
<div class="modal fade" id="mBatch" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
<div class="modal-header bg-info text-white py-2">
  <span class="fw-bold" style="font-size:.85rem">Kirim Batch ImagingStudy</span>
  <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
  <div id="batchProgress" class="mb-2">
    <div class="d-flex justify-content-between align-items-center mb-1">
      <span id="batchStatus" class="small fw-bold">Menyiapkan&hellip;</span>
      <span id="batchCount" class="small text-muted">0 / 0</span>
    </div>
    <div class="progress" style="height:6px">
      <div id="batchBar" class="progress-bar bg-success" style="width:0%"></div>
    </div>
  </div>
  <div id="batchSummary" class="d-none alert alert-info py-1 px-2 small mb-2"></div>
  <div id="batchLogWrap" style="max-height:350px;overflow:auto">
    <table class="table table-sm mb-0">
      <thead><tr><th>#</th><th>No Rawat</th><th>Nama Pasien</th><th>Status</th><th>Pesan</th></tr></thead>
      <tbody id="batchLogBody"></tbody>
    </table>
  </div>
</div>
<div class="modal-footer py-1">
  <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
  <button type="button" id="btnBatchReload" class="btn btn-sm btn-primary d-none">Muat Ulang Halaman</button>
</div>
</div></div></div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode(CSRF_IS) ?>;
/* escape HTML untuk teks dari server / SatuSehat sebelum masuk innerHTML */
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const ORG = '<?= SSORGID ?>';
const FILTER_Q  = '<?= htmlspecialchars($q, ENT_QUOTES) ?>';
const FILTER_DT1 = '<?= htmlspecialchars($dt1, ENT_QUOTES) ?>';
const FILTER_DT2 = '<?= htmlspecialchars($dt2, ENT_QUOTES) ?>';
const FILTER_AKSI = '<?= htmlspecialchars($aksi, ENT_QUOTES) ?>';
const HAS_MORE_INIT = <?= $hasMoreInit ? 'true' : 'false' ?>;

const mK  = new bootstrap.Modal(document.getElementById('mKirim'));
const mP  = new bootstrap.Modal(document.getElementById('mPrev'));
const mB  = new bootstrap.Modal(document.getElementById('mBatch'));
const $   = id => document.getElementById(id);
const post = data => fetch(location.href, {
  method: 'POST',
  body: new URLSearchParams(data),
  headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF}
}).then(r => r.json());


/* ══ Token status bar ══════════════════════════════════════════════ */
let tokenOk = false;

function setTokenBar(state, msg) {
  const bar  = $('tokenBar');
  const icon = $('tokenIcon');
  const txt  = $('tokenMsg');
  const btn  = $('btnRefreshToken');
  bar.className = 'loading';
  bar.classList.add(state);

  if (state === 'ok') {
    icon.textContent = '\u2705';
    btn.classList.add('d-none');
    tokenOk = true;
    $('btnSend').disabled = false;
  } else if (state === 'err') {
    icon.textContent = '\u274C';
    btn.classList.remove('d-none');
    tokenOk = false;
    $('btnSend').disabled = true;
  } else {
    icon.textContent = '\u23F3';
    btn.classList.add('d-none');
    tokenOk = false;
    $('btnSend').disabled = true;
  }
  txt.textContent = msg;
}

function pingToken() {
  setTokenBar('loading', 'Memeriksa koneksi SatuSehat\u2026');
  post({act: 'ping_token'}).then(r => {
    if (r.ok) {
      const sisa = Math.max(0, Math.floor(r.remain / 60));
      setTokenBar('ok', `Token aktif \u2014 sisa \u00B1${sisa} menit`);
    } else {
      setTokenBar('err', 'Token gagal: ' + (r.msg || 'Error tidak diketahui'));
    }
  }).catch(e => {
    setTokenBar('err', 'Koneksi gagal: ' + e.message);
  });
}

$('btnRefreshToken').addEventListener('click', pingToken);


/* ══ Utilities ═════════════════════════════════════════════════════ */
function setVal(id, val) {
  const el = $(id);
  if (!el) return;
  el.value = val ?? '';
  el.classList.toggle('auto-filled', !!val);
}
function localToUtc(str) {
  if (!str) return '';
  return new Date(str).toISOString().replace('.000Z', '+00:00');
}
function dbToLocal(str) {
  if (!str) return '';
  const dt = new Date(str.replace(' ', 'T'));
  if (isNaN(dt)) return '';
  return new Date(dt.getTime() - dt.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}


/* ══ Infinite Scroll ════════════════════════════════════════════════ */
let currentPage = 1;
let isLoadingMore = false;
let hasMoreData = HAS_MORE_INIT;

function loadMoreRows() {
  if (isLoadingMore || !hasMoreData) return;
  isLoadingMore = true;
  $('loadMoreIndicator').classList.remove('d-none');

  currentPage++;
    post({
    act: 'load_more',
    pg: currentPage,
    q: FILTER_Q,
    dt1: FILTER_DT1,
    dt2: FILTER_DT2,
    aksi: FILTER_AKSI
  }).then(r => {
    $('loadMoreIndicator').classList.add('d-none');
    isLoadingMore = false;

    if (r.html) {
      $('tableBody').insertAdjacentHTML('beforeend', r.html);
    }
    hasMoreData = r.hasMore;
    if (!hasMoreData) {
      $('noMoreData').classList.remove('d-none');
    }
    updateCheckedCount();
  }).catch(e => {
    isLoadingMore = false;
    $('loadMoreIndicator').classList.add('d-none');
  });
}

const scrollWrap = $('tableScrollWrap');
scrollWrap.addEventListener('scroll', () => {
  if (scrollWrap.scrollTop + scrollWrap.clientHeight >= scrollWrap.scrollHeight - 100) {
    loadMoreRows();
  }
});


/* ══ Select All & Checked Count ════════════════════════════════════ */
function updateCheckedCount() {
  const checked = document.querySelectorAll('.row-check:checked');
  $('checkedCount').textContent = checked.length;
  $('btnSendChecked').disabled = checked.length === 0;
}

$('checkAll').addEventListener('change', function() {
  document.querySelectorAll('.row-check').forEach(cb => { cb.checked = this.checked; });
  updateCheckedCount();
});

document.querySelector('#mainTable tbody').addEventListener('change', function(e) {
  if (e.target.classList.contains('row-check')) {
    updateCheckedCount();
  }
});


/* ══ Buka modal kirim (event delegation untuk infinite scroll) ═════ */
document.querySelector('#mainTable tbody').addEventListener('click', function(e) {
  const btn = e.target.closest('.btn-kirim');
  if (!btn) return;
  resetForm();
  mK.show();
  pingToken();
  loadRow(btn.dataset.id);
});


/* ══ Load data baris ═══════════════════════════════════════════════ */
function loadRow(id) {
  $('divLoad').classList.remove('d-none');
  post({act: 'row', id}).then(row => {
    $('divLoad').classList.add('d-none');
    if (!row) return;
    setVal('fMapId',  row.id);
    setVal('vRawat',  row.no_rawat);
    setVal('fAcc',    row.pacs_acc);
    setVal('fMod',    row.modality || 'CR');
    setVal('fProc',   row.procedure_name);
    setVal('fPIhs',   row.patient_ihs);
    setVal('fSR',     row.sr_id);
    setVal('fRI',     row.dokter_rad_ihs);
    setVal('fRN',     row.radname ?? '');
    setVal('fNIK',    row.nik_pasien ?? '');
    setVal('fSIUID',  row._study_uid    ?? '');
    setVal('fSerUID', row._series_uid   ?? '');
    setVal('fInstUID',row._instance_uid ?? '');
    setVal('fSOP',    row.sopclass      ?? '');
    $('mInfo').textContent = (row.no_rawat ?? '') + ' \u2014 ' + (row.nm_pasien ?? '');
    const dtL = dbToLocal(row.studydatetime || row.created_at);
    if (dtL) { $('fLocal').value = dtL; $('fUtc').value = localToUtc(dtL); }
  });
}


/* ══ Cek IHS by NIK ════════════════════════════════════════════════ */
$('btnCekIHS').addEventListener('click', () => {
  const nik = ($('fNIK').value || '').trim();
  const statusEl = $('ihsStatus');
  if (!nik) {
    statusEl.innerHTML = '<span class="text-danger">NIK kosong, tidak dapat cek IHS.</span>';
    return;
  }
  statusEl.innerHTML = '<span class="text-info">\u23F3 Mencari IHS\u2026</span>';
  $('btnCekIHS').disabled = true;
  post({act: 'cek_ihs', nik}).then(r => {
    $('btnCekIHS').disabled = false;
    if (r.ok) {
      setVal('fPIhs', r.ihs);
      statusEl.innerHTML = '<span class="text-success">\u2705 IHS ditemukan & terisi otomatis.</span>';
    } else {
      statusEl.innerHTML = `<span class="text-danger">\u274C ${esc(r.msg)}</span>`;
    }
  }).catch(e => {
    $('btnCekIHS').disabled = false;
    statusEl.innerHTML = `<span class="text-danger">\u274C Fetch error: ${esc(e.message)}</span>`;
  });
});


/* ══ Konversi waktu lokal → UTC ════════════════════════════════════ */
$('fLocal').addEventListener('change', function () { $('fUtc').value = localToUtc(this.value); });


/* ══ Preview JSON ══════════════════════════════════════════════════ */
$('btnPrev').addEventListener('click', () => {
  const loc = $('fLocal').value;
  if (loc) $('fUtc').value = localToUtc(loc);
  const fd = Object.fromEntries(new FormData($('frm')).entries());
  if (!fd.startedutc && fd.startedlocal) fd.startedutc = localToUtc(fd.startedlocal);
  $('pIS').textContent = JSON.stringify(makeIS(fd), null, 2);
  mP.show();
});


/* ══ Kirim — dengan pre-check token otomatis ══════════════════════ */
$('btnSend').addEventListener('click', async () => {
  const loc = $('fLocal').value;
  if (loc) $('fUtc').value = localToUtc(loc);
  if (!$('frm').reportValidity()) return;

  setLoad(true);
  $('divRes').classList.add('d-none');

  if (!tokenOk) {
    setTokenBar('loading', 'Mendapatkan token baru\u2026');
    try {
      const tr = await post({act: 'ping_token'});
      if (tr.ok) {
        const sisa = Math.max(0, Math.floor(tr.remain / 60));
        setTokenBar('ok', `Token aktif \u2014 sisa \u00B1${sisa} menit`);
      } else {
        setTokenBar('err', 'Token gagal: ' + (tr.msg || 'Error'));
        setLoad(false);
        showRes({ok: false, msg: 'Gagal mendapatkan token SatuSehat: ' + (tr.msg || ''), step: 'Token'});
        return;
      }
    } catch (e) {
      setTokenBar('err', 'Koneksi gagal: ' + e.message);
      setLoad(false);
      showRes({ok: false, msg: 'Koneksi ke server gagal: ' + e.message, step: 'Token'});
      return;
    }
  }

  fetch(location.href, {
    method: 'POST',
    body: new URLSearchParams(new FormData($('frm'))),
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF}
  }).then(r => r.json())
    .then(r  => {
      setLoad(false);
      if (!r.ok && r.step === 'Token') {
        setTokenBar('err', 'Token error: ' + r.msg);
        tokenOk = false;
      }
      showRes(r);
    })
    .catch(e => { setLoad(false); showRes({ok: false, msg: 'Fetch error: ' + e.message}); });
});


function setLoad(on) {
  $('btnSend').disabled = on;
  $('bTxt').classList.toggle('d-none', on);
  $('bSpin').classList.toggle('d-none', !on);
}

function showRes(r) {
  const div = $('divRes');
  div.classList.remove('d-none');
  if (r.ok) {
    div.innerHTML = `<div style="background:#e8f5e9;border-left:3px solid #2e7d32;padding:.5rem .75rem;border-radius:.3rem">
      \u2705 <b>${esc(r.msg)}</b><br><div class="fl-label mt-1">ImagingStudy ID</div>
      <input class="form-control form-control-sm font-monospace" value="${esc(r.isid)}" readonly></div>`;
    setTimeout(() => location.reload(), 3000);
  } else {
    let det = '';
    if (r.detail)  det += `<pre class="jbox mt-1">${esc(JSON.stringify(r.detail,  null, 2))}</pre>`;
    if (r.payload) det += `<div class="fl mt-1">Payload</div><pre class="jbox">${esc(JSON.stringify(r.payload, null, 2))}</pre>`;
    div.innerHTML = `<div style="background:#ffebee;border-left:3px solid #c62828;padding:.5rem .75rem;border-radius:.3rem">
      \u274C <b>${esc(r.step)}</b>: ${esc(r.msg)}${det}</div>`;
  }
}

function resetForm() {
  $('frm').reset();
  ['fMapId','fUtc','vRawat','fAcc','fMod','fProc','fNIK','fPIhs','fSR',
   'fSIUID','fSerUID','fInstUID','fSOP','fRI','fRN'].forEach(id => {
    const el = $(id);
    if (el) { el.value = ''; el.classList.remove('auto-filled'); }
  });
  $('mInfo').textContent = '';
  $('ihsStatus').innerHTML = '';
  $('divRes').classList.add('d-none');
  $('divRes').innerHTML = '';
  $('divLoad').classList.add('d-none');
  tokenOk = false;
  $('btnSend').disabled = true;
  setTokenBar('loading', 'Menunggu\u2026');
}


/* ══ Batch: Kirim Terpilih ══════════════════════════════════════════ */
$('btnSendChecked').addEventListener('click', () => {
  const checked = document.querySelectorAll('.row-check:checked');
  if (checked.length === 0) {
    alert('Pilih minimal 1 data untuk dikirim.');
    return;
  }
  const ids = Array.from(checked).map(cb => ({
    id: cb.dataset.id,
    no_rawat: '',
    nm_pasien: '',
    has_is: cb.dataset.hasIs === '1'
  }));
  runBatchSend(ids, 'Mengirim data terpilih');
});


/* ══ Batch: Kirim Semua Data ═══════════════════════════════════════ */
$('btnSendAll').addEventListener('click', async () => {
  if (!confirm(`Kirim SEMUA ImagingStudy yang cocok dengan filter ke SatuSehat?`)) return;

  $('btnSendAll').disabled = true;
  $('btnSendAll').textContent = 'Mengambil daftar\u2026';

  try {
        const r = await post({act: 'get_all_ids', q: FILTER_Q, dt1: FILTER_DT1, dt2: FILTER_DT2, aksi: FILTER_AKSI});
    if (!r.items || r.items.length === 0) {
      alert('Tidak ada data yang cocok dengan filter.');
      $('btnSendAll').disabled = false;
      $('btnSendAll').textContent = 'Kirim Semua Data';
      return;
    }
    const ids = r.items.map(it => ({
      id: String(it.id),
      no_rawat: it.no_rawat || '',
      nm_pasien: it.nm_pasien || '',
      has_is: !!it.is_id
    }));
    runBatchSend(ids, `Mengirim semua data (${ids.length} item)`);
  } catch (e) {
    alert('Gagal mengambil daftar: ' + e.message);
  }
  $('btnSendAll').disabled = false;
  $('btnSendAll').textContent = 'Kirim Semua Data';
});


/* ══ Run batch send ════════════════════════════════════════════════ */
async function runBatchSend(items, title) {
  mB.show();
  $('batchStatus').textContent = title;
  $('batchCount').textContent = `0 / ${items.length}`;
  $('batchBar').style.width = '0%';
  $('batchBar').className = 'progress-bar bg-success';
  $('batchSummary').classList.add('d-none');
  $('btnBatchReload').classList.add('d-none');
  $('batchLogBody').innerHTML = '';

  let ok = 0, fail = 0, reSent = 0;

  for (let i = 0; i < items.length; i++) {
    const item = items[i];
    $('batchStatus').textContent = `Mengirim ${i + 1} dari ${items.length}\u2026`;
    $('batchCount').textContent = `${i + 1} / ${items.length}`;
    $('batchBar').style.width = `${((i + 1) / items.length) * 100}%`;

    let statusIcon, statusClass, statusText, msgText;

    try {
      const r = await post({act: 'send_one', mapid: item.id});

      if (r.ok) {
        ok++;
        if (r.already_sent) {
          reSent++;
          statusIcon = '\u26A0\uFE0F';
          statusClass = 'batch-row-warn';
          statusText = 'Dikirim Ulang';
          msgText = `${r.msg} (sebelumnya sudah dikirim, IS ID: ${r.isid ?? ''})`;
        } else {
          statusIcon = '\u2705';
          statusClass = 'batch-row-ok';
          statusText = 'Berhasil';
          msgText = `${r.msg} — IS ID: ${r.isid ?? ''}`;
        }
      } else {
        fail++;
        statusIcon = '\u274C';
        statusClass = 'batch-row-fail';
        statusText = 'Gagal';
        if (r.already_sent) {
          msgText = `${r.msg} (data sebelumnya sudah dikirim)`;
        } else {
          msgText = `${r.step ?? ''}: ${r.msg}`;
        }
      }
    } catch (e) {
      fail++;
      statusIcon = '\u274C';
      statusClass = 'batch-row-fail';
      statusText = 'Error';
      msgText = e.message;
    }

    const noRawat = item.no_rawat || '-';
    const nmPasien = item.nm_pasien || '-';
    $('batchLogBody').insertAdjacentHTML('beforeend',
      `<tr class="${statusClass}">
        <td>${i + 1}</td>
        <td><code class="small">${noRawat}</code></td>
        <td>${nmPasien}</td>
        <td>${statusIcon} ${statusText}</td>
        <td class="small">${msgText}</td>
      </tr>`);
    $('batchLogWrap').scrollTop = $('batchLogWrap').scrollHeight;
  }

  let summary = `Selesai! \u2705 Berhasil: ${ok}`;
  if (reSent > 0) summary += ` (\u26A0 ${reSent} dikirim ulang)`;
  summary += `  \u274C Gagal: ${fail}`;
  if (fail > 0) $('batchBar').className = 'progress-bar bg-warning';

  $('batchStatus').textContent = 'Selesai';
  $('batchSummary').textContent = summary;
  $('batchSummary').classList.remove('d-none');
  $('btnBatchReload').classList.remove('d-none');
}

$('btnBatchReload').addEventListener('click', () => location.reload());


/* ══ Build payload JS (Preview) ════════════════════════════════════ */
function makeIS(d) {
  const mod = (d.modality || 'CR').toUpperCase();
  const p = {
    resourceType: 'ImagingStudy',
    status:       'available',
    subject:      {reference: `Patient/${d.patient_ihs}`},
    started:      d.startedutc,
    basedOn:      d.sr_id ? [{reference: `ServiceRequest/${d.sr_id}`}] : [],
    identifier:   [],
    modality:     [{system: 'http://dicom.nema.org/resources/ontology/DCM', code: mod}],
    series: [{
      uid:      d.series_uid || '',
      modality: {system: 'http://dicom.nema.org/resources/ontology/DCM', code: mod},
      instance: [{
        uid:      d.instance_uid || '',
        sopClass: {system: 'urn:ietf:rfc:3986', code: 'urn:oid:' + (d.sopclass || '1.2.840.10008.5.1.4.1.1.1')},
      }],
    }],
  };
  if (d.pacs_acc) {
    p.identifier.push({
      use:    'usual',
      type:   {coding: [{system: 'http://terminology.hl7.org/CodeSystem/v2-0203', code: 'ACSN'}]},
      system: `http://sys-ids.kemkes.go.id/acsn/${ORG}`,
      value:  d.pacs_acc,
    });
  }
  if (d.study_instance_uid) {
    p.identifier.push({system: 'urn:dicom:uid', value: 'urn:oid:' + d.study_instance_uid});
  }
  if (d.radihs) p.interpreter = [{reference: `Practitioner/${d.radihs}`}];
  return p;
}
</script>
</body>
</html>