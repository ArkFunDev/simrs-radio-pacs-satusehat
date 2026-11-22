<?php
/**
 * Copy this file to config.local.php and fill in your own values.
 * config.local.php is ignored by git. NEVER commit real credentials.
 */

// ---- Database (SIMRS Khanza) ----
$host     = 'localhost';
$dbname   = 'your_database';
$username = 'your_db_user';
$password = 'your_db_password';

// ---- PACS ----
define('PACS_BASE_URL', 'http://pacs.example.local:8080'); // no trailing slash
define('API_TOKEN', 'your_pacs_api_token');                // or 'xx' if not used
// {uid} is replaced with the Study Instance UID
define('DICOM_VIEWER_URL', 'http://pacs.example.local/viewer.html?studyUID={uid}');

// ---- SatuSehat (Indonesian Ministry of Health, FHIR R4) ----
define('SATUSEHAT_ENV', 'STAGING'); // STAGING or PRODUCTION
define('SATUSEHAT_CLIENT_ID', 'your_client_id');
define('SATUSEHAT_CLIENT_SECRET', 'your_client_secret');
define('SATUSEHAT_ORG_ID', 'your_organization_id');
// Folder untuk cache token SatuSehat (chmod 700, SEBAIKNYA di luar web root).
// Jika tidak didefinisikan, dipakai folder temp sistem.
// define('TOKEN_CACHE_DIR', '/var/lib/simrs-radio/cache');

// ---- Login (Khanza AES keys used to decrypt user/admin credentials) ----
define('AES_KEY_USER', 'your_aes_key_for_username');
define('AES_KEY_PASS', 'your_aes_key_for_password');

// ---- Access control ----
define('ROLE_CODE_RADIOLOGY_STAFF', 'J000');   // petugas.kd_jbtn of radiology staff
define('ROLE_CODE_RADIOLOGY_DOCTOR', 'S0000'); // dokter.kd_sps of radiologists
define('HOSPITAL_NAME', 'Your Hospital Name');

// ---- Defaults used in hasil_radiologi.php ----
define('DEFAULT_KD_DOKTER_PJ', 'D000');
define('DEFAULT_NM_DOKTER_PJ', 'Default Responsible Doctor');
define('DEFAULT_NIP_PETUGAS', '000000');
define('DEFAULT_NAMA_PETUGAS', 'Default Staff');