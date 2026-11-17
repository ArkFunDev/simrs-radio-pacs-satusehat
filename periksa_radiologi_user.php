<?php
session_start();

// Cek login
if (!isset($_SESSION['user_radiologi'])) {
    header('Location: login.php');
    exit();
}

$user_login = $_SESSION['user_radiologi'];

// Fungsi untuk mendapatkan IP LAN Lokal SAJA (Private IP)
function getLocalLanIP() {
    $lan_ip = 'No LAN IP';
    
    // Cek dari berbagai sumber
    $ip_sources = array(
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    );
    
    foreach ($ip_sources as $key) {
        if (!empty($_SERVER[$key])) {
            $ip_list = explode(',', $_SERVER[$key]);
            foreach ($ip_list as $ip_item) {
                $ip_item = trim($ip_item);
                
                if (filter_var($ip_item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    if (isPrivateIP($ip_item)) {
                        $lan_ip = $ip_item;
                        break 2;
                    }
                }
            }
        }
    }
    
    if ($lan_ip === 'No LAN IP' && !empty($_SERVER['SERVER_ADDR'])) {
        $server_ip = $_SERVER['SERVER_ADDR'];
        if (filter_var($server_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && isPrivateIP($server_ip)) {
            $lan_ip = $server_ip;
        }
    }
    
    if ($lan_ip === 'No LAN IP') {
        $hostname = gethostname();
        $host_ip = gethostbyname($hostname);
        if (filter_var($host_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && isPrivateIP($host_ip)) {
            $lan_ip = $host_ip;
        }
    }
    
    return $lan_ip;
}

function isPrivateIP($ip) {
    $ip_long = ip2long($ip);
    
    if ($ip_long === false) {
        return false;
    }
    
    $private_ranges = array(
        array(ip2long('10.0.0.0'), ip2long('10.255.255.255')),
        array(ip2long('172.16.0.0'), ip2long('172.31.255.255')),
        array(ip2long('192.168.0.0'), ip2long('192.168.255.255'))
    );
    
    foreach ($private_ranges as $range) {
        if ($ip_long >= $range[0] && $ip_long <= $range[1]) {
            return true;
        }
    }
    
    if ($ip === '127.0.0.1') {
        return false;
    }
    
    return false;
}

$user_ip = getLocalLanIP();

require_once 'config.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Filter parameters
$tgl_dari = $_GET['tgl_dari'] ?? date('Y-m-d');
$tgl_sampai = $_GET['tgl_sampai'] ?? date('Y-m-d');
$no_rawat = $_GET['no_rawat'] ?? '';
$pasien = $_GET['pasien'] ?? '';
$petugas = $_GET['petugas'] ?? '';
$keyword = $_GET['keyword'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Periksa Radiologi</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {   
    font-family: Arial, sans-serif;
    background: #f0f0f0;
    padding: 13px;
    font-size: 12px;
}

.container {
    background: #fff;
    border-radius: 5px;
    box-shadow: 0 2px 6px rgba(255, 255, 255, 0.15);
}

.header {
    background: linear-gradient(135deg, #009468, #009468);
    color: #000000;
    padding: 12px 20px;
    font-weight: bold;
    font-size: 16px;
    border-radius: 5px 5px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 15px;
}

.user-info-header {
    display: flex;
    align-items: center;
    gap: 13px;
    background: rgb(255, 255, 255);
    padding: 6px 15px;
    border-radius: 20px;
    font-size: 16px;
}

.user-info-header i {
    font-size: 16px;
}

.ip-info-header {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgb(255, 255, 255);
    padding: 6px 15px;
    border-radius: 20px;
    font-size: 16px;
    font-family: 'Courier New', monospace;
}

.ip-info-header i {
    font-size: 14px;
}

.header-center {
    flex: 1;
    text-align: center;
}

.header-right {
    display: flex;
    gap: 13px;
}

.btn-logout {
    background: rgba(255,255,255,0.2);
    color: white;
    border: 2px solid rgba(255,255,255,0.4);
    padding: 6px 15px;
    border-radius: 20px;
    cursor: pointer;
    font-weight: bold;
    font-size: 12px;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-logout:hover {
    background: rgba(255,255,255,0.3);
    border-color: rgba(255,255,255,0.6);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.btn-logout i {
    font-size: 14px;
}

.filters {
    background: #ecf0f1;
    padding: 15px;
    border-bottom: 2px solid #bdc3c7;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 12px;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 5px;
    color: #34495e;
}

.filter-group input {
    padding: 6px 8px;
    border: 1px solid #bdc3c7;
    border-radius: 3px;
    font-size: 16px;
}

.filter-group input:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
}

.date-range {
    display: flex;
    align-items: center;
    gap: 8px;
}

.date-range input {
    flex: 1;
    padding: 6px 8px;
    border: 1px solid #bdc3c7;
    border-radius: 3px;
    font-size: 16px;
}

.date-range span {
    color: #34495e;
    font-weight: bold;
    font-size: 16px;
}

.btn-row {
    display: flex;
    gap: 8px;
    justify-content: flex-start;
}

.btn {
    padding: 6px 15px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 16px;
    font-weight: bold;
    transition: all 0.2s;
}

.btn-search {
    background: #3498db;
    color: #fff;
}

.btn-search:hover {
    background: #2980b9;
    transform: translateY(-1px);
}

.btn-home {
    background: #27ae60;
    color: #fff;
}

.btn-home:hover {
    background: #229954;
    transform: translateY(-1px);
}

.btn-clear {
    background: #e74c3c;
    color: #fff;
}

.btn-clear:hover {
    background: #c0392b;
    transform: translateY(-1px);
}

.btn-danger {
    background: #95a5a6;
    color: #fff;
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-disabled:hover {
    transform: none;
}

.keyword-row {
    display: flex;
    align-items: center;
    gap: 13px;
    margin-top: 13px;
}

.keyword-row label {
    font-size: 16px;
    font-weight: bold;
    color: #34495e;
}

.keyword-row input {
    padding: 6px 8px;
    border: 1px solid #bdc3c7;
    border-radius: 3px;
    font-size: 16px;
    width: 300px;
}

.keyword-row .btn-check {
    padding: 6px 12px;
    background: #27ae60;
    color: #fff;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-weight: bold;
    font-size: 16px;
    transition: all 0.2s;
}

.keyword-row .btn-check:hover {
    background: #229954;
    transform: translateY(-1px);
}

.keyword-row .btn-delete {
    padding: 6px 12px;
    background: #e74c3c;
    color: #fff;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-weight: bold;
    font-size: 16px;
    transition: all 0.2s;
}

.keyword-row .btn-delete:hover {
    background: #c0392b;
    transform: translateY(-1px);
}

.table-wrapper {
    max-height: 650px;
    overflow: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 16px;
}

th {
    background: #3498db;
    color: #fff;
    padding: 8px 5px;
    text-align: center;
    position: sticky;
    top: 0;
    z-index: 10;
    font-size: 16px;
    border: 1px solid #2980b9;
    font-weight: bold;
    white-space: nowrap;
}

td {
    padding: 8px 5px;
    border: 1px solid #d5dbdb;
    text-align: left;
    vertical-align: top;
}

tbody tr {
    cursor: pointer;
    transition: background 0.2s;
}

tbody tr:nth-child(even) {
    background: #f9f9f9;
}

tbody tr:hover {
    background: #d6eaf8;
}

tbody tr.selected {
    background: #aed6f1;
    border-left: 4px solid #3498db;
}

.footer {
    background: #ecf0f1;
    padding: 13px 15px;
    border-top: 1px solid #bdc3c7;
    display: flex;
    justify-content: space-between;
    font-size: 16px;
    color: #34495e;
}

.no-data {
    text-align: center;
    padding: 30px;
    color: #95a5a6;
    font-size: 16px;
}

.detail-item {
    margin: 3px 0;
    line-height: 1.5;
}

.detail-label {
    color: #7f8c8d;
    font-size: 13px;
    font-weight: normal;
}

.detail-value {
    color: #2c3e50;
    font-size: 16px;
}

/* Toast Notification */
.toast-notification {
    position: fixed;
    top: 20px;
    right: 20px;
    min-width: 350px;
    max-width: 450px;
    background: white;
    border-radius: 13px;
    box-shadow: 0 13px 40px rgba(0,0,0,0.3);
    z-index: 999999;
    opacity: 0;
    transform: translateX(400px);
    transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    overflow: hidden;
}

.toast-notification.show {
    opacity: 1;
    transform: translateX(0);
}

.toast-notification.hide {
    opacity: 0;
    transform: translateX(400px);
}

.toast-header {
    padding: 15px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    color: white;
    font-weight: bold;
    font-size: 14px;
}

.toast-success .toast-header {
    background: linear-gradient(135deg, #27ae60, #229954);
}

.toast-error .toast-header {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
}

.toast-warning .toast-header {
    background: linear-gradient(135deg, #f39c12, #e67e22);
}

.toast-icon {
    font-size: 28px;
    line-height: 1;
}

.toast-title {
    flex: 1;
}

.toast-close {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    cursor: pointer;
    padding: 5px 8px;
    border-radius: 4px;
    font-size: 16px;
    line-height: 1;
    transition: background 0.2s;
}

.toast-close:hover {
    background: rgba(255,255,255,0.3);
}

.toast-body {
    padding: 15px 20px;
    color: #2c3e50;
    font-size: 16px;
    line-height: 1.6;
}

.toast-progress {
    height: 4px;
    background: rgba(0,0,0,0.1);
    position: relative;
    overflow: hidden;
}

.toast-progress-bar {
    height: 100%;
    background: rgba(255,255,255,0.8);
    width: 100%;
    animation: progressBar 3s linear;
}

@keyframes progressBar {
    from { width: 100%; }
    to { width: 0%; }
}

/* Custom Confirmation Modal */
.confirm-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.75);
    z-index: 99999;
    justify-content: center;
    align-items: center;
    animation: fadeIn 0.15s ease;
}

.confirm-modal-overlay.show {
    display: flex;
}

.confirm-modal-box {
    background: white;
    width: 90%;
    max-width: 480px;
    border-radius: 12px;
    box-shadow: 0 15px 50px rgba(0,0,0,0.4);
    overflow: hidden;
    animation: modalZoomIn 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes modalZoomIn {
    from {
        transform: scale(0.7);
        opacity: 0;
    }
    to {
        transform: scale(1);
        opacity: 1;
    }
}

.confirm-modal-header {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
    padding: 20px 25px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.confirm-modal-icon {
    font-size: 36px;
    line-height: 1;
    animation: iconPulse 0.5s ease;
}

@keyframes iconPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.15); }
}

.confirm-modal-title {
    font-size: 18px;
    font-weight: bold;
    letter-spacing: 0.3px;
}

.confirm-modal-body {
    padding: 25px;
}

.confirm-modal-message {
    font-size: 14px;
    color: #2c3e50;
    line-height: 1.6;
    margin-bottom: 18px;
}

.confirm-modal-detail {
    background: #fff5f5;
    border-left: 4px solid #e74c3c;
    padding: 15px 18px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.confirm-modal-detail-item {
    display: flex;
    margin-bottom: 13px;
    font-size: 16px;
}

.confirm-modal-detail-item:last-child {
    margin-bottom: 0;
}

.confirm-modal-detail-label {
    font-weight: bold;
    color: #7f8c8d;
    min-width: 113px;
}

.confirm-modal-detail-value {
    color: #2c3e50;
    font-family: monospace;
    font-weight: bold;
    flex: 1;
}

.confirm-modal-warning {
    background: #fff9e6;
    border: 2px solid #f39c12;
    padding: 12px 15px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 13px;
    font-size: 12px;
    color: #856404;
}

.confirm-modal-warning-icon {
    font-size: 20px;
}

.confirm-modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding: 0 25px 25px;
}

.confirm-modal-btn {
    padding: 13px 30px;
    border: none;
    border-radius: 6px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.2s;
    letter-spacing: 0.5px;
}

.confirm-modal-btn-cancel {
    background: #95a5a6;
    color: white;
}

.confirm-modal-btn-cancel:hover {
    background: #7f8c8d;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(149, 165, 166, 0.4);
}

.confirm-modal-btn-confirm {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
}

.confirm-modal-btn-confirm:hover {
    background: linear-gradient(135deg, #c0392b, #a93226);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(231, 76, 60, 0.5);
}

/* Modal Styles for Detail */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 9999;
    justify-content: center;
    align-items: center;
}

.modal-overlay.show {
    display: flex;
}

.modal-content-box {
    background: white;
    width: 95%;
    max-width: 1400px;
    height: 95%;
    border-radius: 13px;
    box-shadow: 0 13px 40px rgba(0,0,0,0.5);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        transform: scale(0.8);
        opacity: 0;
    }
    to {
        transform: scale(1);
        opacity: 1;
    }
}

.modal-header {
    background: linear-gradient(135deg, #3498db, #2ecc71);
    color: white;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 3px solid #2980b9;
}

.modal-header h3 {
    margin: 0;
    font-size: 16px;
}

.modal-close {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    font-size: 24px;
    cursor: pointer;
    padding: 5px;
    width: 35px;
    height: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 5px;
    transition: all 0.3s;
}

.modal-close:hover {
    background: rgba(255,255,255,0.3);
    transform: rotate(90deg);
}

.modal-body-iframe {
    flex: 1;
    overflow: hidden;
    background: #f8f9fa;
}

.modal-body-iframe iframe {
    width: 100%;
    height: 100%;
    border: none;
}

@media (max-width: 768px) {
    .header {
        flex-direction: column;
        gap: 13px;
    }

    .header-left,
    .header-right {
        width: 100%;
        justify-content: center;
    }

    .filter-row {
        grid-template-columns: 1fr;
    }

    .keyword-row input {
        width: 100%;
    }

    .modal-content-box {
        width: 98%;
        height: 98%;
    }

    .confirm-modal-box {
        width: 95%;
    }

    .confirm-modal-footer {
        flex-direction: column;
    }

    .confirm-modal-btn {
        width: 100%;
    }

    .toast-notification {
        right: 13px;
        left: 13px;
        min-width: auto;
        top: 13px;
    }
}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-left">
            <div class="user-info-header">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($user_login); ?></span>
            </div>
            <div class="ip-info-header">
                <i class="fas fa-network-wired"></i>
                <span><?php echo htmlspecialchars($user_ip); ?></span>
            </div>
        </div>
        
        <div class="header-center">
            .:[ Data Pemeriksaan Radiologi ]:.
        </div>

        <div class="header-right">
            <a href="logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </div>

    <div class="filters">
        <div class="filter-row">
            <div class="filter-group">
                <label>No.Rawat :</label>
                <input type="text" id="no_rawat" value="<?= htmlspecialchars($no_rawat) ?>" placeholder="No. Rawat">
            </div>

            <div class="filter-group">
                <label>Pasien :</label>
                <input type="text" id="pasien" value="<?= htmlspecialchars($pasien) ?>" placeholder="Nama Pasien">
            </div>

            <div class="filter-group">
                <label>Tanggal :</label>
                <div class="date-range">
                    <input type="date" id="tgl_dari" value="<?= $tgl_dari ?>">
                    <span>s.d.</span>
                    <input type="date" id="tgl_sampai" value="<?= $tgl_sampai ?>">
                </div>
            </div>

            <div class="filter-group">
                <label>Petugas :</label>
                <input type="text" id="petugas" value="<?= htmlspecialchars($petugas) ?>" placeholder="Nama Petugas">
            </div>
        </div>

        <div class="keyword-row">
            <label>Key Word :</label>
            <input type="text" id="keyword" value="<?= htmlspecialchars($keyword) ?>" placeholder="Pencarian cepat...">
            <button class="btn-check" onclick="searchKeyword()">✓</button>
            <button class="btn btn-search" onclick="applyFilter()">🔍 Semua</button>
            <button class="btn btn-home" onclick="window.print()">🖨️ Cetak</button>
            <button id="btnHapusData" class="btn btn-danger" disabled title="Fitur hapus dinonaktifkan">🗑️ Hapus Data</button>
        </div>
    </div>

    <div class="table-wrapper">
        <table id="dataTable">
            <thead>
                <tr>
                    <th style="min-width:140px">No.Rawat</th>
                    <th style="min-width:250px">Pasien</th>
                    <th style="min-width:150px">Petugas</th>
                    <th style="min-width:150px">Tgl.Periksa</th>
                    <th style="min-width:100px">Jam Periksa</th>
                    <th style="min-width:180px">Dokter Perujuk</th>
                    <th style="min-width:180px">Penanggung Jawab</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sql = "SELECT 
                        pr.no_rawat,
                        pr.tgl_periksa,
                        pr.jam,
                        p.no_rkm_medis,
                        p.nm_pasien,
                        CASE 
                            WHEN ki.kd_kamar IS NOT NULL THEN 
                                CONCAT(p.no_rkm_medis, ' ', p.nm_pasien, ' (Kamar: ', ki.kd_kamar, ', ', b.nm_bangsal, ')')
                            ELSE 
                                CONCAT(p.no_rkm_medis, ' ', p.nm_pasien, ' (Poli: ', pol.nm_poli, ')')
                        END as info_pasien,
                        pt.nama as nama_petugas,
                        d1.nm_dokter as nama_dokter_perujuk,
                        pr.dokter_perujuk,
                        d2.nm_dokter as nama_dokter_penanggung,
                        pr.kd_dokter,
                        pj.png_jawab as cara_bayar,
                        GROUP_CONCAT(DISTINCT pr.kd_jenis_prw ORDER BY pr.kd_jenis_prw SEPARATOR '||') as kode_periksa_list,
                        GROUP_CONCAT(DISTINCT jpr.nm_perawatan ORDER BY pr.kd_jenis_prw SEPARATOR '||') as nama_pemeriksaan_list,
                        GROUP_CONCAT(DISTINCT pr.biaya ORDER BY pr.kd_jenis_prw SEPARATOR '||') as biaya_list
                        FROM periksa_radiologi pr
                        LEFT JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                        LEFT JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                        LEFT JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
                        LEFT JOIN kamar_inap ki ON pr.no_rawat = ki.no_rawat
                        LEFT JOIN kamar k ON ki.kd_kamar = k.kd_kamar
                        LEFT JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
                        LEFT JOIN petugas pt ON pr.nip = pt.nip
                        LEFT JOIN dokter d1 ON pr.dokter_perujuk = d1.kd_dokter
                        LEFT JOIN dokter d2 ON pr.kd_dokter = d2.kd_dokter
                        LEFT JOIN jns_perawatan_radiologi jpr ON pr.kd_jenis_prw = jpr.kd_jenis_prw
                        LEFT JOIN penjab pj ON rp.kd_pj = pj.kd_pj
                        WHERE DATE(pr.tgl_periksa) BETWEEN ? AND ?";

                $params = [$tgl_dari, $tgl_sampai];

                if($no_rawat) {
                    $sql .= " AND pr.no_rawat LIKE ?";
                    $params[] = "%$no_rawat%";
                }

                if($pasien) {
                    $sql .= " AND p.nm_pasien LIKE ?";
                    $params[] = "%$pasien%";
                }

                if($petugas) {
                    $sql .= " AND (pt.nama LIKE ? OR pr.nip LIKE ?)";
                    $params[] = "%$petugas%";
                    $params[] = "%$petugas%";
                }

                if($keyword) {
    $sql .= " AND (pr.no_rawat LIKE ? OR p.nm_pasien LIKE ? OR p.no_rkm_medis LIKE ? OR pt.nama LIKE ? OR pr.nip LIKE ?)";
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
}

                $sql .= " GROUP BY pr.no_rawat, pr.tgl_periksa, pr.jam
                          ORDER BY pr.tgl_periksa DESC, pr.jam DESC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if($stmt->rowCount() == 0):
                ?>
                <tr>
                    <td colspan="7" class="no-data">📭 Tidak ada data pemeriksaan radiologi</td>
                </tr>
                <?php else:
                    while($r = $stmt->fetch(PDO::FETCH_ASSOC)):
                        $kode_periksa_arr = explode('||', $r['kode_periksa_list'] ?? '');
                        $nama_pemeriksaan_arr = explode('||', $r['nama_pemeriksaan_list'] ?? '');
                        $biaya_arr = explode('||', $r['biaya_list'] ?? '');
                ?>
                <tr class="data-row" 
                    data-norawat="<?= htmlspecialchars($r['no_rawat']) ?>"
                    data-tglperiksa="<?= htmlspecialchars($r['tgl_periksa']) ?>"
                    data-pasien="<?= htmlspecialchars($r['nm_pasien']) ?>"
                    ondblclick="openModal('<?= htmlspecialchars($r['no_rawat']) ?>', '<?= htmlspecialchars($r['tgl_periksa']) ?>')"
                    onclick="selectRow(this)">
                    <td style="font-family:monospace; font-weight:bold;">
                        <?= htmlspecialchars($r['no_rawat']) ?>
                    </td>
                    <td>
                        <div class="detail-item detail-value" style="font-weight:bold;">
                            <?= htmlspecialchars($r['info_pasien'] ?? '-') ?>
                        </div>
                        <div class="detail-item detail-label">Proyeksi & Dosis Radiasi</div>
                    </td>
                    <td>
                        <div class="detail-item detail-value" style="font-weight:bold;">
                            <?= htmlspecialchars($r['nama_petugas'] ?? '-') ?>
                        </div>
                        <div class="detail-item detail-label">Kode Periksa</div>
                        <?php foreach($kode_periksa_arr as $kode): ?>
                        <?php if(trim($kode)): ?>
                        <div class="detail-item detail-value"><?= htmlspecialchars($kode) ?></div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <div class="detail-item detail-value" style="font-weight:bold;">
                            <?= date('d-m-Y', strtotime($r['tgl_periksa'])) ?>
                        </div>
                        <div class="detail-item detail-label">Nama Pemeriksaan</div>
                        <?php foreach($nama_pemeriksaan_arr as $nama): ?>
                        <?php if(trim($nama)): ?>
                        <div class="detail-item detail-value"><?= htmlspecialchars($nama) ?></div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                    <td style="text-align:center;">
                        <div class="detail-item detail-value" style="font-weight:bold;">
                            <?= substr($r['jam'], 0, 8) ?>
                        </div>
                        <div class="detail-item detail-label">Biaya</div>
                        <?php foreach($biaya_arr as $biaya): ?>
                        <?php if(trim($biaya)): ?>
                        <div class="detail-item detail-value" style="text-align:left;">
                            <?= number_format($biaya, 0, ',', '.') ?>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <div class="detail-item detail-value" style="font-weight:bold;">
                            <?= htmlspecialchars($r['nama_dokter_perujuk'] ?? $r['dokter_perujuk']) ?>
                        </div>
                        <div class="detail-item detail-label">
                            Cara Bayar : <?= htmlspecialchars($r['cara_bayar'] ?? 'UMUM') ?>
                        </div>
                    </td>
                    <td>
                        <?= htmlspecialchars($r['nama_dokter_penanggung'] ?? $r['kd_dokter']) ?>
                    </td>
                </tr>
                <?php 
                    endwhile;
                endif;
                ?>
            </tbody>
        </table>
    </div>

    <div class="footer">
        <div><strong>Record:</strong> <?= $stmt->rowCount() ?> data</div>
        <div><strong>Periode:</strong> <?= date('d/m/Y', strtotime($tgl_dari)) ?> - <?= date('d/m/Y', strtotime($tgl_sampai)) ?></div>
    </div>
</div>

<!-- Modal Overlay for Detail -->
<div class="modal-overlay" id="modalOverlay" onclick="closeModalOnOverlay(event)">
    <div class="modal-content-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3 id="modalTitle">📄 Detail Pemeriksaan Radiologi</h3>
            <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body-iframe">
            <iframe id="modalIframe" src=""></iframe>
        </div>
    </div>
</div>

<!-- Toast Notification Container -->
<div id="toastContainer"></div>

<script>
let selectedRow = null;
let selectedData = {
    no_rawat: null,
    tgl_periksa: null,
    nama_pasien: null
};

// Toast Notification Function
function showToast(type, title, message, duration = 3000) {
    const container = document.getElementById('toastContainer');

    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;

    const icons = {
        success: '✅',
        error: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    };

    toast.innerHTML = `
        <div class="toast-header">
            <span class="toast-icon">${icons[type] || icons.info}</span>
            <span class="toast-title">${title}</span>
            <button class="toast-close" onclick="closeToast(this)">×</button>
        </div>
        <div class="toast-body">${message}</div>
        <div class="toast-progress">
            <div class="toast-progress-bar"></div>
        </div>
    `;

    container.appendChild(toast);

    // Trigger animation
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);

    // Auto hide
    setTimeout(() => {
        closeToast(toast);
    }, duration);
}

function closeToast(element) {
    const toast = element.classList ? element : element.parentElement.parentElement;
    toast.classList.remove('show');
    toast.classList.add('hide');

    setTimeout(() => {
        toast.remove();
    }, 400);
}

function applyFilter() {
    const noRawat = document.getElementById('no_rawat').value;
    const pasien = document.getElementById('pasien').value;
    const tglDari = document.getElementById('tgl_dari').value;
    const tglSampai = document.getElementById('tgl_sampai').value;
    const petugas = document.getElementById('petugas').value;

    window.location.href = `?tgl_dari=${tglDari}&tgl_sampai=${tglSampai}&no_rawat=${encodeURIComponent(noRawat)}&pasien=${encodeURIComponent(pasien)}&petugas=${encodeURIComponent(petugas)}`;
}

function searchKeyword() {
    const keyword = document.getElementById('keyword').value;
    const tglDari = document.getElementById('tgl_dari').value;
    const tglSampai = document.getElementById('tgl_sampai').value;

    window.location.href = `?tgl_dari=${tglDari}&tgl_sampai=${tglSampai}&keyword=${encodeURIComponent(keyword)}`;
}

function selectRow(row) {
    if(selectedRow) {
        selectedRow.classList.remove('selected');
    }

    row.classList.add('selected');
    selectedRow = row;

    selectedData.no_rawat = row.getAttribute('data-norawat');
    selectedData.tgl_periksa = row.getAttribute('data-tglperiksa');
    selectedData.nama_pasien = row.getAttribute('data-pasien');
}

function openModal(noRawat, tglPeriksa) {
    const modal = document.getElementById('modalOverlay');
    const iframe = document.getElementById('modalIframe');
    const modalTitle = document.getElementById('modalTitle');

    modalTitle.textContent = `📄 Detail Pemeriksaan - No.Rawat: ${noRawat}`;
    iframe.src = `diskom_radiologi.php?no_rawat=${encodeURIComponent(noRawat)}&tgl_periksa=${encodeURIComponent(tglPeriksa)}`;

    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    const modal = document.getElementById('modalOverlay');
    const iframe = document.getElementById('modalIframe');

    modal.classList.remove('show');
    iframe.src = '';
    document.body.style.overflow = 'auto';
}

function closeModalOnOverlay(event) {
    if(event.target.id === 'modalOverlay') {
        closeModal();
    }
}

// ESC key to close modals
document.addEventListener('keydown', function(e) {
    if(e.key === 'Escape') {
        closeModal();
    }
});

// Enter key support for filters
document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('input');
    inputs.forEach(input => {
        input.addEventListener('keypress', function(e) {
            if(e.key === 'Enter') {
                applyFilter();
            }
        });
    });

    document.getElementById('keyword').addEventListener('keypress', function(e) {
        if(e.key === 'Enter') {
            searchKeyword();
        }
    });
});
</script>
</body>
</html>