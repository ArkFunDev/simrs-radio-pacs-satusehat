<?php
/**
 * config.php
 * Shared configuration and helper functions.
 * All secrets live in config.local.php (NOT committed to git).
 */

// ========================================
// STEP 0: LOAD LOCAL SECRETS
// ========================================
$localConfig = __DIR__ . '/config.local.php';
if (!is_file($localConfig)) {
    error_log('config.local.php not found. Copy config.local.example.php and fill it in.');
    die('Configuration file is missing.');
}
require_once $localConfig;

// Make sure every required value is defined
foreach ([
    'PACS_BASE_URL', 'API_TOKEN', 'DICOM_VIEWER_URL',
    'SATUSEHAT_ENV', 'SATUSEHAT_CLIENT_ID', 'SATUSEHAT_CLIENT_SECRET', 'SATUSEHAT_ORG_ID',
] as $requiredConst) {
    if (!defined($requiredConst)) {
        error_log("Missing constant in config.local.php: {$requiredConst}");
        die('Configuration is incomplete.');
    }
}

// ========================================
// STEP 1: DATABASE CONNECTION
// ========================================
try {
    // Create the PDO connection
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);

    // Throw exceptions on errors
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Default fetch mode
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Log details server-side, show a generic message to the user
    error_log('DB connection failed: ' . $e->getMessage());
    die('❌ Koneksi database gagal.');
}

// ========================================
// STEP 2: PACS API SETTINGS
// ========================================
// PACS_BASE_URL, API_TOKEN and DICOM_VIEWER_URL come from config.local.php
define('PACS_TIMEOUT', 30); // Connection timeout in seconds

// Alias for backward compatibility
define('URL_BASE', PACS_BASE_URL);

// ========================================
// STEP 3: SATUSEHAT API SETTINGS
// ========================================

/**
 * HOW TO GET SATUSEHAT CREDENTIALS:
 *
 * 1. Register/login at https://satusehat.kemkes.go.id
 * 2. Menu: Developer Console -> My Apps
 * 3. Create a new app or choose an existing one
 * 4. Copy the Client ID and Client Secret
 * 5. Find your Organization ID under Master Data -> Organisasi
 *
 * Put these values in config.local.php (never in this file).
 */

// Base URLs depend on the environment (SATUSEHAT_ENV is set in config.local.php)
if (SATUSEHAT_ENV == 'PRODUCTION') {
    define('SATUSEHAT_AUTH_URL', 'https://api-satusehat.kemkes.go.id/oauth2/v1/accesstoken');
    define('SATUSEHAT_BASE_URL', 'https://api-satusehat.kemkes.go.id/fhir-r4/v1');
} else {
    define('SATUSEHAT_AUTH_URL', 'https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1/accesstoken');
    define('SATUSEHAT_BASE_URL', 'https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1');
}

// ========================================
// STEP 4: APPLICATION SETTINGS
// ========================================
define('APP_NAME', 'Sistem Radiologi SIMRS');
define('APP_VERSION', '2.2.0');
define('TIMEZONE', 'Asia/Jakarta');

// Default timezone
date_default_timezone_set(TIMEZONE);

// Start session with hardened cookie flags
if (session_status() == PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,   // aktif otomatis saat diakses lewat HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
} elseif (PHP_SAPI === 'cli') {
    $_SESSION = $_SESSION ?? [];  // cron/CLI: tidak perlu cookie sesi
}

// ========================================
// SHARED HELPERS
// ========================================

/**
 * Nomor accession dasar untuk sebuah nomor order Khanza.
 *
 * Aturan yang dipakai alur produksi (hasil_radiologi.php, diskom_radiologi.php):
 * buang 4 karakter pertama. Contoh: PR202611170001 -> 2611170001 (lihat docs/AUDIT.md
 * soal DataPermintaanRadio.php yang masih memakai aturan lama). Accession per
 * pemeriksaan = base + indeks 0-based.
 */
function accessionBase(string $noorder): string {
    return substr($noorder, 4);
}

function accessionFor(string $noorder, int $examIndex): string {
    return accessionBase($noorder) . $examIndex;
}

/** Escape untuk output HTML. */
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ========================================
// SATUSEHAT FUNCTIONS
// ========================================

/**
 * FUNCTION 1: GET SATUSEHAT ACCESS TOKEN
 *
 * The token authenticates every request to SatuSehat.
 * It is valid for about 1 hour and cached in the session.
 *
 * NOTE: sent as urlencoded POST body (not Basic Auth).
 * NOTE: SATUSEHAT_AUTH_URL already ends with /accesstoken.
 *
 * @return string|false Token, or false on failure
 */
function getSatuSehatToken() {

    // Check that credentials were filled in
    if (SATUSEHAT_CLIENT_ID == 'xx' || SATUSEHAT_CLIENT_SECRET == 'xx' || SATUSEHAT_CLIENT_ID === '') {
        error_log("❌ Kredensial SatuSehat belum diisi di config.local.php!");
        return false;
    }

    // Session cache (avoid requesting a token every time)
    if (isset($_SESSION['satusehat_token']) &&
        isset($_SESSION['satusehat_token_expires']) &&
        time() < $_SESSION['satusehat_token_expires']) {
        return $_SESSION['satusehat_token'];
    }

    // FIX: URL already ends with /accesstoken, do not append it again
    $url = SATUSEHAT_AUTH_URL . "?grant_type=client_credentials";

    $postData = http_build_query([
        'client_id'     => SATUSEHAT_CLIENT_ID,
        'client_secret' => SATUSEHAT_CLIENT_SECRET
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // SECURITY: keep TLS verification on
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/x-www-form-urlencoded"
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Log only the status (never log the token or secret)
    error_log("Token Request - HTTP Code: {$http_code}");

    if ($curl_error) {
        error_log("cURL Error: {$curl_error}");
    }

    if ($http_code == 200) {
        $data = json_decode($response, true);

        if (isset($data['access_token'])) {
            // Cache for 55 minutes (5 minute buffer from 1 hour)
            $_SESSION['satusehat_token'] = $data['access_token'];
            $_SESSION['satusehat_token_expires'] = time() + 3300;

            return $data['access_token'];
        }
    }

    error_log("❌ Gagal mendapatkan token SatuSehat! HTTP {$http_code}");
    return false;
}

/**
 * FUNCTION 2: MAP RADIOLOGY EXAMINATION TO SNOMED CT CODE
 *
 * SatuSehat requires SNOMED CT codes for ServiceRequest.
 * Add mappings that match the examinations in your hospital.
 *
 * @param string $namaPemeriksaan Examination name from the Khanza database
 * @return string SNOMED CT code
 */
function getKodeSNOMEDRadiologi($namaPemeriksaan) {

    // Uppercase for matching
    $nama = strtoupper(trim($namaPemeriksaan));

    // Format: 'KEYWORD' => 'SNOMED_CODE'
    $mapping = [

        // ORDER MATTERS: the first keyword contained in the name wins, so the specific
        // CT / USG / mammography keys must come BEFORE the generic X-ray keys below
        // (otherwise 'CT SCAN KEPALA' matches 'KEPALA' and returns the skull X-ray code).

        // === CT SCAN ===
        'CT SCAN KEPALA'  => '303653007', // CT scan kepala
        'CT SCAN HEAD'    => '303653007',
        'CT HEAD'         => '303653007',
        'CT SCAN THORAX'  => '418891003', // CT scan thorax
        'CT THORAX'       => '418891003',
        'CT SCAN ABDOMEN' => '303659006', // CT scan abdomen
        'CT ABDOMEN'      => '303659006',

        // === ULTRASOUND ===
        'USG ABDOMEN'  => '241685008', // USG abdomen
        'USG OBSTETRI' => '268445003', // USG kehamilan
        'USG MAMMAE'   => '241679002', // USG payudara
        'USG'          => '241685008', // USG umum

        // === MAMMOGRAPHY ===
        'MAMMOGRAFI' => '241055006',
        'MAMMO'      => '241055006',

        // === CHEST X-RAY ===
        'THORAX' => '168537006', // Rontgen thorax (chest X-ray)
        'CHEST'  => '168537006',
        'DADA'   => '168537006',

        // === ABDOMEN X-RAY ===
        'ABDOMEN' => '168557005', // Rontgen abdomen
        'PERUT'   => '168557005',
        'BNO'     => '168557005', // BNO (Buik Nier Overzicht)

        // === UPPER EXTREMITY X-RAY ===
        'HUMERUS'     => '241645004', // Rontgen ekstremitas
        'ANTEBRACHII' => '241645004',
        'WRIST'       => '241664009', // Rontgen pergelangan tangan
        'HAND'        => '241667002', // Rontgen tangan
        'TANGAN'      => '241667002',

        // === LOWER EXTREMITY X-RAY ===
        'FEMUR'  => '241645004', // Rontgen ekstremitas
        'CRURIS' => '241645004',
        'TIBIA'  => '241645004',
        'FIBULA' => '241645004',
        'ANKLE'  => '241656006', // Rontgen pergelangan kaki
        'FOOT'   => '241659004', // Rontgen kaki
        'KAKI'   => '241659004',

        // === SPINE X-RAY ===
        'CERVICAL'        => '241636001', // Rontgen vertebra
        'THORACAL'        => '241636001',
        'LUMBAL'          => '241636001',
        'LUMBOSACRAL'     => '241636001',
        'VERTEBRA'        => '241636001',
        'TULANG BELAKANG' => '241636001',

        // === SKULL X-RAY ===
        'SKULL'     => '168532004', // Rontgen kepala
        'KEPALA'    => '168532004',
        'CRANIUM'   => '168532004',
        'SINUS'     => '168532004',
        'MANDIBULA' => '168532004',
        'RAHANG'    => '168532004',

        // DEFAULT (when nothing matches)
        'DEFAULT' => '363680008' // Imaging procedure (generic)
    ];

    // Find the first keyword contained in the examination name
    foreach ($mapping as $keyword => $kode) {
        if (strpos($nama, $keyword) !== false) {
            return $kode;
        }
    }

    return $mapping['DEFAULT'];
}

/**
 * FUNCTION 3: SEND SERVICE REQUEST TO SATUSEHAT
 *
 * LEGACY / tidak dipakai di alur produksi: ServiceRequest dibuat oleh modul SatuSehat
 * bawaan SIMRS Khanza (tabel satu_sehat_servicerequest_radiologi); modul ini hanya
 * membacanya untuk ImagingStudy. Dipertahankan sebagai referensi. Payload di bawah
 * belum memuat requester/performer/occurrence; lihat form_imagingstudy.php untuk
 * payload yang lebih lengkap.
 *
 * ServiceRequest = radiology examination order.
 * MUST be sent BEFORE the PACS sends DICOM images.
 *
 * Flow:
 * 1. Get SatuSehat token
 * 2. Validate data (IHS Number, Encounter ID)
 * 3. Build FHIR JSON payload
 * 4. POST to SatuSehat API
 * 5. Save ServiceRequest ID to the database
 *
 * @param PDO $pdo Database connection
 * @param array $dataOrder Radiology order data
 * @return array ['success' => bool, 'service_request_id' => string, 'message' => string]
 */
function kirimServiceRequestSatuSehat($pdo, $dataOrder) {

    try {

        // ==========================================
        // STEP 1: GET TOKEN
        // ==========================================
        $token = getSatuSehatToken();

        if (!$token) {
            return [
                'success' => false,
                'message' => '❌ Gagal mendapatkan token SatuSehat. Periksa kredensial di config.local.php!'
            ];
        }

        // ==========================================
        // STEP 2: VALIDATE REQUIRED DATA
        // ==========================================

        // IHS Number (patient ID in SatuSehat)
        if (empty($dataOrder['ihs_number'])) {
            return [
                'success' => false,
                'message' => "❌ IHS Number pasien tidak ditemukan!\n\n" .
                             "Pastikan:\n" .
                             "1. NIK pasien sudah diinput di Khanza\n" .
                             "2. NIK sudah dimapping ke IHS Number\n" .
                             "3. Tabel patient_ihs_mapping sudah ada data"
            ];
        }

        // Encounter ID (visit ID in SatuSehat)
        if (empty($dataOrder['encounter_id'])) {
            return [
                'success' => false,
                'message' => "❌ Encounter ID tidak ditemukan!\n\n" .
                             "Pastikan:\n" .
                             "1. Kunjungan (no_rawat) sudah dikirim ke SatuSehat\n" .
                             "2. Encounter ID sudah tersimpan di database\n" .
                             "3. Tabel satusehat_encounter sudah ada data"
            ];
        }

        // ==========================================
        // STEP 3: PREPARE SNOMED CODE
        // ==========================================
        $kodeSNOMED = getKodeSNOMEDRadiologi($dataOrder['jenis_pemeriksaan']);

        // ==========================================
        // STEP 4: BUILD JSON PAYLOAD (FHIR)
        // ==========================================

        $payload = [

            // Resource type
            "resourceType" => "ServiceRequest",

            // Identifiers (order number)
            "identifier" => [
                [
                    "system" => "http://sys-ids.kemkes.go.id/servicerequest/" . SATUSEHAT_ORG_ID,
                    "use"    => "official",
                    "value"  => $dataOrder['nomor_order'] // Example: PR202512230001
                ],
                [
                    "system" => "http://sys-ids.kemkes.go.id/acsn/" . SATUSEHAT_ORG_ID,
                    "use"    => "official",
                    "value"  => substr($dataOrder['nomor_order'], 2) // Accession number (digits only)
                ]
            ],

            // Request status (active = in progress)
            "status" => "active",

            // Intent
            "intent" => "original-order",

            // Category (Imaging)
            "category" => [[
                "coding" => [[
                    "system"  => "http://snomed.info/sct",
                    "code"    => "363679005",
                    "display" => "Imaging"
                ]]
            ]],

            // Examination code (SNOMED CT)
            "code" => [
                "coding" => [[
                    "system"  => "http://snomed.info/sct",
                    "code"    => $kodeSNOMED,
                    "display" => $dataOrder['jenis_pemeriksaan']
                ]],
                "text" => $dataOrder['jenis_pemeriksaan']
            ],

            // Subject (patient)
            "subject" => [
                "reference" => "Patient/" . $dataOrder['ihs_number'],
                "display"   => $dataOrder['nama_pasien'] ?? ''
            ],

            // Encounter (visit)
            "encounter" => [
                "reference" => "Encounter/" . $dataOrder['encounter_id']
            ],

            // Order time (UTC)
            "authoredOn" => gmdate('Y-m-d\TH:i:s\Z')
        ];

        // ==========================================
        // STEP 5: SEND TO SATUSEHAT API
        // ==========================================

        $url = SATUSEHAT_BASE_URL . "/ServiceRequest";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // SECURITY: keep TLS verification on
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // ==========================================
        // STEP 6: HANDLE RESPONSE
        // ==========================================

        // HTTP 201 = Created
        if ($http_code == 201) {

            $result = json_decode($response, true);
            $serviceRequestID = $result['id'] ?? null;

            if ($serviceRequestID) {

                // Save ServiceRequest ID to the database
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO satusehat_servicerequest
                        (noorder, no_rawat, service_request_id, status, created_at)
                        VALUES (?, ?, ?, 'sent', NOW())
                        ON DUPLICATE KEY UPDATE
                        service_request_id=?, status='sent', updated_at=NOW()
                    ");
                    $stmt->execute([
                        $dataOrder['nomor_order'],
                        $dataOrder['no_rawat'],
                        $serviceRequestID,
                        $serviceRequestID
                    ]);
                } catch (Exception $e) {
                    // Table may not exist yet: log a warning but continue
                    error_log("⚠️ Warning: Gagal simpan ServiceRequest ID - " . $e->getMessage());
                }

                return [
                    'success' => true,
                    'service_request_id' => $serviceRequestID,
                    'message' => "✅ ServiceRequest berhasil dikirim!\n\n" .
                                 "ServiceRequest ID: {$serviceRequestID}\n" .
                                 "Accession Number: " . substr($dataOrder['nomor_order'], 2) . "\n\n" .
                                 "PACS sekarang bisa kirim gambar DICOM ke SatuSehat."
                ];
            }
        }

        // FAILED
        $errorMsg = "HTTP {$http_code}";
        if ($curl_error) {
            $errorMsg .= " - cURL: {$curl_error}";
        }

        if ($response) {
            $errorData = json_decode($response, true);
            // Parse SatuSehat error
            if (isset($errorData['issue'])) {
                $errorMsg .= "\n\nDetail Error:\n";
                foreach ($errorData['issue'] as $issue) {
                    $errorMsg .= "- " . ($issue['details']['text'] ?? $issue['diagnostics'] ?? 'Unknown error') . "\n";
                }
            } else {
                $errorMsg .= "\n\n" . substr($response, 0, 500);
            }
        }

        return [
            'success' => false,
            'message' => "❌ Gagal kirim ServiceRequest ke SatuSehat!\n\n{$errorMsg}"
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => '❌ Exception: ' . $e->getMessage()
        ];
    }
}

// ========================================
// PACS FUNCTIONS
// ========================================

/**
 * CALL THE PACS API
 *
 * @param string $endpoint API endpoint (example: /api/orders)
 * @param array $data Data to send
 * @param string $method HTTP method (GET/POST/PUT/DELETE)
 * @return array API response
 */
function callPacsAPI($endpoint, $data, $method = 'POST') {

    $url = PACS_BASE_URL . $endpoint;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, PACS_TIMEOUT);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    if ($method == 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method == 'PUT' || $method == 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    // GET: no extra settings needed

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    // Add token if configured
    if (defined('API_TOKEN') && API_TOKEN != 'xx' && API_TOKEN !== '') {
        $headers[] = 'Authorization: Bearer ' . API_TOKEN;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);

    $decoded = json_decode($response, true);

    return [
        'success'   => ($http_code >= 200 && $http_code < 300),
        'http_code' => $http_code,
        'response'  => $decoded ? $decoded : $response,
        'error'     => $curl_error,
        'errno'     => $curl_errno
    ];
}

/**
 * EXTRACT DATA FROM THE PACS ORDER RESPONSE (v2.2)
 *
 * Reads the result of GET /api/orders/{acc}/{mrn} safely and consistently.
 * The real response structure is NESTED inside the 'workload' object:
 *
 *   data.uid                    -> Study Instance UID
 *   data.acc                    -> confirmed accession number from PACS
 *   data.prosedur               -> procedure name
 *   data.xray_type_code         -> modality (CR/CT/etc.)
 *   data.dokradid / dokrad_name -> radiologist
 *   data.study.study_iuid       -> study UID (fallback if data.uid is empty)
 *   data.study.num_instances    -> number of instances/images
 *   data.study.img              -> image flag/count (string, fallback)
 *   data.study.mods_in_study    -> modality from the PACS study
 *   data.workload.status        -> report status: approved / pending / etc.
 *   data.workload.fill          -> RADIOLOGIST REPORT TEXT (HTML)
 *   data.workload.approved_at   -> report approval time
 *
 * @param array $data Contents of $responseData['data'] (not the whole body)
 * @return array Normalized fields
 */
function extractPacsOrderData(array $data): array {
    // Study UID: prefer data.uid, fall back to data.study.study_iuid
    $study_uid = $data['uid'] ?? ($data['study']['study_iuid'] ?? '');

    // Workload = radiologist report object (NESTED)
    $workload           = $data['workload'] ?? [];
    $expertise_text_raw = $workload['fill'] ?? '';
    $report_status      = $workload['status'] ?? ''; // approved | pending | etc.
    $approved_at        = $workload['approved_at'] ?? null;

    // Study = DICOM study info object (NESTED)
    $study = $data['study'] ?? [];
    $image_count = 0;
    if (isset($study['num_instances']) && is_numeric($study['num_instances'])) {
        $image_count = (int)$study['num_instances'];
    } elseif (isset($study['img']) && is_numeric($study['img'])) {
        $image_count = (int)$study['img'];
    }

    $modality         = $data['xray_type_code'] ?? ($study['mods_in_study'] ?? '');
    $prosedur         = $data['prosedur'] ?? ($study['study_desc'] ?? '');
    $dokrad_name      = $data['dokrad_name'] ?? '';
    $dokrad_id        = $data['dokradid'] ?? '';
    $referring_doctor = $data['named'] ?? '';
    $acc_confirmed    = $data['acc'] ?? '';

    // FIX: the 'fill' field is HTML from a rich-text editor, so spaces come as
    // "&nbsp;" and literal "<" / ">" (e.g. "< 5mm") come as "&lt;" / "&gt;".
    // Without decoding, these entities would be stored raw in the database.
    // html_entity_decode() turns them back into real characters. Valid <br>
    // tags are kept (decode only converts "&...;" entities, it does not strip tags).
    $expertise_text = $expertise_text_raw !== ''
        ? html_entity_decode($expertise_text_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        : '';

    // FIX: the PACS sometimes inserts the word "view"/"View"/"VIEW" (usually the
    // leftover text of a button/link in the report template) into the report.
    // Remove it as a whole word (\b...\b) so words that merely contain "view"
    // (e.g. "review", "overview", "viewer") are NOT cut.
    if ($expertise_text !== '') {
        $expertise_text = preg_replace('/\s*\bview\b\s*/i', ' ', $expertise_text);
        $expertise_text = trim($expertise_text);
    }

    // Plain-text version for the combined hasil_radiologi: <br> -> newline,
    // then strip any remaining HTML tags.
    $expertise_text_plain = $expertise_text !== ''
        ? trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $expertise_text)))
        : '';

    return [
        'study_uid'            => $study_uid,
        'expertise_text'       => $expertise_text,       // original (HTML) - store this in the DB
        'expertise_text_plain' => $expertise_text_plain, // plain text for the combined hasil_radiologi
        'report_status'        => $report_status,
        'approved_at'          => $approved_at,
        'image_count'          => $image_count,
        'modality'             => $modality,
        'prosedur'             => $prosedur,
        'dokrad_name'          => $dokrad_name,
        'dokrad_id'            => $dokrad_id,
        'referring_doctor'     => $referring_doctor,
        'acc_confirmed'        => $acc_confirmed,
        'has_image'            => ($study_uid !== '' || $image_count > 0),
        'has_report'           => ($expertise_text !== ''),
    ];
}

// ========================================
// OTHER HELPER FUNCTIONS
// ========================================

/**
 * Format a date in Indonesian format
 */
function formatTanggalIndo($date) {
    if (!$date || $date == '0000-00-00') return '-';

    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    $split = explode('-', $date);
    return $split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];
}

/**
 * Generate a radiology order number
 */
function generateNoOrder($pdo, $prefix = 'PR') {
    $tgl = date('Ymd');
    $stmt = $pdo->prepare("
        SELECT MAX(CAST(SUBSTRING(noorder,11) AS UNSIGNED)) as max_order
        FROM permintaan_radiologi
        WHERE DATE(tgl_permintaan)=CURDATE() AND noorder LIKE ?
    ");
    $stmt->execute([$prefix . $tgl . '%']);
    $result = $stmt->fetch();
    $maxOrder = $result['max_order'] ?? 0;

    return $prefix . $tgl . str_pad($maxOrder + 1, 4, '0', STR_PAD_LEFT);
}

/**
 * Log system activity
 */
function logActivity($pdo, $activity, $user = 'SYSTEM') {
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_log (timestamp, user, activity) VALUES (NOW(), ?, ?)");
        $stmt->execute([$user, $activity]);
    } catch (Exception $e) {
        // Silent fail - never disturb the main process
    }
}

// ========================================
// SECURITY & ERROR HANDLING
// ========================================

// Block direct access to the config file
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    die('Akses ditolak.');
}

// Production mode: jangan tampilkan error ke browser, tetapi TETAP catat di log server
// (sebelumnya error_reporting(0) menyembunyikan semua error sehingga sulit di-debug).
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

?>