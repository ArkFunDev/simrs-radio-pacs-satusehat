<?php
/**
 * diskom_auto_pull.php
 * ============================================================
 * Tarik otomatis data PACS (link DICOM + teks bacaan) di background.
 * Dijalankan terjadwal tiap 1 jam (Task Scheduler / cron), tanpa browser.
 *
 * - Hanya memproses pasien yang datanya BELUM LENGKAP (dicom_url atau
 *   expertise_text masih kosong). Begitu semua pemeriksaannya terisi,
 *   pasien itu otomatis tidak dicek lagi.
 * - Cara tarik sama dengan tombol "Ambil Semua DICOM" di diskom_radiologi.php
 *   (endpoint PACS, update khanza_pacs_mapping, simpan ke hasil_radiologi).
 *   File diskom_radiologi.php TIDAK diubah.
 * - Hanya bisa dijalankan lewat CLI.
 *
 * Pemakaian:
 *   php diskom_auto_pull.php
 *   php diskom_auto_pull.php --no_rawat=2026/10/16/000123   (tes 1 pasien, tampil detail)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
chdir(__DIR__);

/* ===== PENGATURAN ===== */
const AUTO_PULL_STATUSES       = ['1', 'active', 'sent']; // sama dengan tombol Ambil Semua DICOM
const AUTO_PULL_MAX_AGE_DAYS   = 7;    // hanya data yang dibuat <= N hari terakhir (0 = tanpa batas)
const AUTO_PULL_MAX_PER_RUN    = 200;  // maksimal pasien (no_rawat) per putaran
const AUTO_PULL_MAX_CONN_FAILS = 3;    // hentikan putaran jika PACS gagal dihubungi N kali berturut-turut
const AUTO_PULL_LOG_FILE       = __DIR__ . '/auto_pull_pacs.log';

/* ===== HELPER ===== */
function apLog(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;

    if (is_file(AUTO_PULL_LOG_FILE) && @filesize(AUTO_PULL_LOG_FILE) > 2 * 1024 * 1024) {
        @unlink(AUTO_PULL_LOG_FILE . '.old');
        @rename(AUTO_PULL_LOG_FILE, AUTO_PULL_LOG_FILE . '.old');
    }
    @file_put_contents(AUTO_PULL_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function apStr($v): string
{
    return is_scalar($v) ? (string)$v : '';
}

// pakai nilai baru jika tidak kosong, selain itu pertahankan nilai lama
function apKeep($new, $old): string
{
    $n = apStr($new);
    return $n !== '' ? $n : apStr($old);
}

// sama seperti ajax_ambil_single: <br> -> baris baru
function apPlain(string $html): string
{
    return trim((string)preg_replace('/<br\s*\/?>/i', "\n", $html));
}

// Salinan pacsGetOrder() di diskom_radiologi.php (endpoint & aturan sama), timeout lebih pendek
function apPacsGetOrder(string $acc, string $mrn): array
{
    $base      = rtrim(PACS_BASE_URL, '/');
    $endpoints = ["/api/orders/{$acc}/{$mrn}", "/api/simrs/{$acc}/{$mrn}"];
    $last      = ['http_code' => 0, 'response' => null, 'error' => null];

    foreach ($endpoints as $path) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (defined('API_TOKEN') && !empty(API_TOKEN)) {
            $headers[] = 'Authorization: Bearer ' . API_TOKEN;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        $last = ['http_code' => $code, 'response' => $response, 'error' => $error];

        if ($code === 200 && $response) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw = is_array($decoded) ? ($decoded['data'] ?? $decoded) : [];
                $ex  = extractPacsOrderData(is_array($raw) ? $raw : []);
                if ($ex['has_image'] || $ex['has_report']) {
                    return $last;
                }
            }
            continue;
        }
        if ($code === 404) {
            continue;
        }
        return $last;
    }
    return $last;
}

/**
 * Tarik semua pemeriksaan milik satu no_rawat (seperti tombol Ambil Semua DICOM).
 */
function apPullNoRawat(PDO $pdo, string $noRawat, int &$connFails, bool $verbose): array
{
    $in = implode(',', array_fill(0, count(AUTO_PULL_STATUSES), '?'));
    $st = $pdo->prepare(
        "SELECT * FROM khanza_pacs_mapping
         WHERE no_rawat = ? AND status IN ($in)
           AND pacs_acc IS NOT NULL AND pacs_acc <> ''
           AND pacs_mrn IS NOT NULL AND pacs_mrn <> ''
         ORDER BY created_at DESC"
    );
    $st->execute(array_merge([$noRawat], AUTO_PULL_STATUSES));
    $mappings = $st->fetchAll();

    /* 1) Tarik dari PACS (belum menulis apa pun ke DB) */
    $plans = [];
    foreach ($mappings as $old) {
        $acc = apStr($old['pacs_acc']);
        $mrn = apStr($old['pacs_mrn']);

        $req  = apPacsGetOrder($acc, $mrn);
        $code = $req['http_code'];

        if ($code === 401 || $code === 403) {
            return ['aborted' => true, 'msg' => "HTTP {$code} - periksa API_TOKEN"];
        }
        if ($code === 0 || $code >= 500) {
            if (++$connFails >= AUTO_PULL_MAX_CONN_FAILS) {
                return ['aborted' => true, 'msg' => ($req['error'] ?: "HTTP {$code}")];
            }
        } else {
            $connFails = 0;
        }

        $plan = [
            'id'                => (int)$old['id'],
            'acc'               => $acc,
            'new'               => null,
            'status'            => '',
            'plain'             => '',
            'dicom'             => apStr($old['dicom_url'] ?? ''),
            'final_expertise'   => apStr($old['expertise_text'] ?? ''),
            'changed'           => false,
            'expertise_changed' => false,
        ];

        if ($code === 200 && $req['response']) {
            $resp = json_decode($req['response'], true);
            $raw  = is_array($resp) ? ($resp['data'] ?? $resp) : [];
            if (!is_array($raw)) {
                $raw = [];
            }
            $ex = extractPacsOrderData($raw);

            $dicomUrl = '';
            if (!empty($ex['study_uid']) && defined('DICOM_VIEWER_URL')) {
                $dicomUrl = str_replace('{uid}', $ex['study_uid'], DICOM_VIEWER_URL);
            } elseif (!empty($raw['link_dicom'])) {
                $dicomUrl = $raw['link_dicom'];
            }

            $new = [
                'dicom_url'      => apKeep($dicomUrl, $old['dicom_url'] ?? ''),
                'expertise_url'  => apKeep($raw['expertise_url'] ?? '', $old['expertise_url'] ?? ''),
                'expertise_text' => apKeep($ex['expertise_text'] ?? '', $old['expertise_text'] ?? ''),
                'procedure_name' => apKeep($ex['prosedur'] ?? '', $old['procedure_name'] ?? ''),
                'modality'       => apKeep($ex['modality'] ?? '', $old['modality'] ?? ''),
                'radname'        => apKeep($ex['dokrad_name'] ?? '', $old['radname'] ?? ''),
            ];
            foreach ($new as $col => $val) {
                if (apStr($old[$col] ?? '') !== $val) {
                    $plan['changed'] = true;
                    break;
                }
            }

            $plan['new']               = $new;
            $plan['status']            = apStr($ex['report_status'] ?? '');
            $plan['dicom']             = $new['dicom_url'];
            $plan['final_expertise']   = $new['expertise_text'];
            $plan['expertise_changed'] = (apStr($old['expertise_text'] ?? '') !== $new['expertise_text']);
            if (!empty($ex['expertise_text'])) {
                $plan['plain'] = apStr($ex['expertise_text_plain'] ?? '');
            }

            $note = $plan['changed']
                ? 'data diperbarui'
                : 'ditemukan, belum ada data baru (report: ' . ($plan['status'] ?: 'unknown') . ')';
        } elseif ($code === 404) {
            $note = 'belum ditemukan di PACS (404)';
        } else {
            $note = 'error HTTP ' . $code . ($req['error'] ? ' - ' . $req['error'] : '');
        }

        if ($verbose || ($code !== 200 && $code !== 404)) {
            apLog("  {$noRawat} ACC {$acc}: {$note}");
        }
        $plans[] = $plan;
    }

    /* 2) Simpan ke khanza_pacs_mapping (hanya baris yang berubah) */
    $toUpdate = [];
    foreach ($plans as $p) {
        if ($p['changed']) {
            $toUpdate[] = $p;
        }
    }
    if ($toUpdate) {
        $upd = $pdo->prepare(
            "UPDATE khanza_pacs_mapping
             SET dicom_url = :dicom_url, expertise_url = :expertise_url, expertise_text = :expertise_text,
                 procedure_name = :procedure_name, modality = :modality, radname = :radname,
                 notes = CONCAT('Status: ', :st, ' | Retrieved: ', NOW(), ' | Auto-pull'),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $pdo->beginTransaction();
        try {
            foreach ($toUpdate as $p) {
                $upd->execute($p['new'] + ['st' => $p['status'], 'id' => $p['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /* 3) Gabungkan teks bacaan -> hasil_radiologi (sama seperti tombol) */
    $sections   = [];
    $expChanged = false;
    foreach ($plans as $p) {
        // bacaan segar dari PACS; kalau putaran ini gagal menarik, pakai yang sudah tersimpan
        $txt = $p['plain'] !== '' ? $p['plain'] : apPlain($p['final_expertise']);
        if ($txt !== '') {
            $sections[] = 'ACC: ' . $p['acc'] . "\n" . $txt;
        }
        if ($p['expertise_changed']) {
            $expChanged = true;
        }
    }

    $hasilMsg = '';
    if ($sections) {
        try {
            $need = $expChanged;
            if (!$need) { // belum pernah tersimpan -> isi
                $c = $pdo->prepare("SELECT COUNT(*) FROM hasil_radiologi WHERE no_rawat = ? AND hasil IS NOT NULL AND hasil <> ''");
                $c->execute([$noRawat]);
                $need = ((int)$c->fetchColumn() === 0);
            }
            if ($need) {
                $q = $pdo->prepare("SELECT tgl_periksa, jam FROM periksa_radiologi WHERE no_rawat = ? ORDER BY tgl_periksa DESC, jam DESC LIMIT 1");
                $q->execute([$noRawat]);
                $tj = $q->fetch();
                if (!$tj) {
                    $hasilMsg = 'hasil_radiologi dilewati: data periksa_radiologi tidak ditemukan';
                } else {
                    $up = $pdo->prepare(
                        "INSERT INTO hasil_radiologi (no_rawat, tgl_periksa, jam, hasil)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                             tgl_periksa = VALUES(tgl_periksa),
                             jam = VALUES(jam),
                             hasil = VALUES(hasil)"
                    );
                    $up->execute([
                        $noRawat,
                        $tj['tgl_periksa'],
                        $tj['jam'],
                        implode("\n\n" . str_repeat('=', 50) . "\n\n", $sections),
                    ]);
                }
            }
        } catch (Throwable $e) {
            $hasilMsg = 'gagal simpan hasil_radiologi: ' . $e->getMessage();
        }
    }

    /* 4) Masih ada yang belum lengkap? */
    $pending = 0;
    foreach ($plans as $p) {
        if ($p['dicom'] === '' || $p['final_expertise'] === '') {
            $pending++;
        }
    }

    return [
        'aborted'   => false,
        'mappings'  => count($plans),
        'updated'   => count($toUpdate),
        'pending'   => $pending,
        'hasil_msg' => $hasilMsg,
    ];
}

/* ===== MAIN ===== */

// cegah 2 putaran berjalan bersamaan
$lockFp = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'diskom_auto_pull.lock', 'c');
if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
    apLog('Putaran sebelumnya masih berjalan, putaran ini dilewati.');
    exit(0);
}

require_once __DIR__ . '/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (isset($host, $dbname, $username, $password)) {
        $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $username, $password);
    } else {
        apLog('ERROR: koneksi database ($pdo) tidak tersedia dari config.php');
        exit(1);
    }
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!defined('PACS_BASE_URL') || !function_exists('extractPacsOrderData')) {
    apLog('ERROR: PACS_BASE_URL / extractPacsOrderData() tidak ditemukan (seharusnya dari config.php)');
    exit(1);
}

$opt         = getopt('', ['no_rawat:']);
$onlyNoRawat = isset($opt['no_rawat']) ? trim((string)$opt['no_rawat']) : '';
$verbose     = ($onlyNoRawat !== '');

// Pasien yang masih punya data belum lengkap
$params = AUTO_PULL_STATUSES;
$where  = 'status IN (' . implode(',', array_fill(0, count(AUTO_PULL_STATUSES), '?')) . ")
           AND no_rawat IS NOT NULL AND no_rawat <> ''
           AND pacs_acc IS NOT NULL AND pacs_acc <> ''
           AND pacs_mrn IS NOT NULL AND pacs_mrn <> ''
           AND (dicom_url IS NULL OR dicom_url = '' OR expertise_text IS NULL OR expertise_text = '')";
if ($onlyNoRawat !== '') {
    $where   .= ' AND no_rawat = ?';   // mode tes: abaikan batas usia data
    $params[] = $onlyNoRawat;
} elseif (AUTO_PULL_MAX_AGE_DAYS > 0) {
    $where .= ' AND created_at >= DATE_SUB(NOW(), INTERVAL ' . (int)AUTO_PULL_MAX_AGE_DAYS . ' DAY)';
}

try {
    $st = $pdo->prepare(
        'SELECT no_rawat FROM khanza_pacs_mapping WHERE ' . $where
        . ' GROUP BY no_rawat ORDER BY MAX(created_at) DESC LIMIT ' . (int)AUTO_PULL_MAX_PER_RUN
    );
    $st->execute($params);
    $list = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    apLog('ERROR query daftar: ' . $e->getMessage());
    exit(1);
}

if (!$list) {
    apLog('Tidak ada data yang perlu ditarik (semua sudah lengkap / tidak memenuhi syarat).');
    exit(0);
}

$stats     = ['cek' => 0, 'baru' => 0, 'lengkap' => 0, 'menunggu' => 0, 'error' => 0];
$connFails = 0;

foreach ($list as $noRawat) {
    $noRawat = (string)$noRawat;
    $stats['cek']++;

    try {
        $r = apPullNoRawat($pdo, $noRawat, $connFails, $verbose);
    } catch (Throwable $e) {
        $stats['error']++;
        apLog("[ERROR] {$noRawat}: " . $e->getMessage());
        continue;
    }

    if (!empty($r['aborted'])) {
        apLog('PACS bermasalah (' . $r['msg'] . '). Putaran dihentikan, dicoba lagi pada jadwal berikutnya.');
        break;
    }
    if ($r['mappings'] === 0) {
        continue;
    }

    if ($r['updated'] > 0) {
        $stats['baru']++;
    }
    if ($r['pending'] === 0) {
        $stats['lengkap']++;
        apLog("[LENGKAP] {$noRawat}: gambar & bacaan sudah tertarik, tidak dicek lagi");
    } else {
        $stats['menunggu']++;
    }
    if ($r['hasil_msg'] !== '') {
        apLog("[WARN] {$noRawat}: {$r['hasil_msg']}");
    }
}

apLog(sprintf(
    'Selesai: %d pasien dicek | %d diperbarui | %d lengkap | %d masih menunggu | %d error',
    $stats['cek'], $stats['baru'], $stats['lengkap'], $stats['menunggu'], $stats['error']
));