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
                
                // Validasi IP dan pastikan IPv4
                if (filter_var($ip_item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    // CEK APAKAH IP ADALAH IP LAN/PRIVATE (bukan public)
                    if (isPrivateIP($ip_item)) {
                        $lan_ip = $ip_item;
                        break 2; // Keluar dari nested loop
                    }
                }
            }
        }
    }
    
    // Jika masih belum dapat, coba dari SERVER_ADDR
    if ($lan_ip === 'No LAN IP' && !empty($_SERVER['SERVER_ADDR'])) {
        $server_ip = $_SERVER['SERVER_ADDR'];
        if (filter_var($server_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && isPrivateIP($server_ip)) {
            $lan_ip = $server_ip;
        }
    }
    
    // Fallback: Coba deteksi dari hostname
    if ($lan_ip === 'No LAN IP') {
        $hostname = gethostname();
        $host_ip = gethostbyname($hostname);
        if (filter_var($host_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && isPrivateIP($host_ip)) {
            $lan_ip = $host_ip;
        }
    }
    
    return $lan_ip;
}

// Fungsi untuk cek apakah IP adalah Private IP (LAN)
function isPrivateIP($ip) {
    // Konversi IP ke long integer
    $ip_long = ip2long($ip);
    
    if ($ip_long === false) {
        return false;
    }
    

    
    $private_ranges = array(
        array(ip2long('10.0.0.0'), ip2long('10.255.255.255')),       // 10.x.x.x
        array(ip2long('172.16.0.0'), ip2long('172.31.255.255')),     // 172.16.x.x - 172.31.x.x
        array(ip2long('192.168.0.0'), ip2long('192.168.255.255'))    // 192.168.x.x
    );
    
    foreach ($private_ranges as $range) {
        if ($ip_long >= $range[0] && $ip_long <= $range[1]) {
            return true;
        }
    }
    
    // Skip localhost
    if ($ip === '127.0.0.1') {
        return false;
    }
    
    return false;
}

$user_ip = getLocalLanIP();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Radiologi</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 15px;
        }

        .container {
            max-width: 98%;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }

        .tab-header {
            display: flex;
            background: linear-gradient(to bottom, #34495e, #2c3e50);
            position: relative;
            padding: 0;
        }

        .tab-button {
            flex: 1;
            padding: 20px 30px;
            background: transparent;
            border: none;
            font-size: 16px;
            font-weight: 600;
            color: #bdc3c7;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            text-align: center;
            overflow: hidden;
            border-right: 1px solid rgba(255,255,255,0.1);
        }

        .tab-button:last-child {
            border-right: none;
        }

        .tab-button::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 4px;
            background: linear-gradient(90deg, #3498db, #2ecc71);
            transition: all 0.4s ease;
            transform: translateX(-50%);
        }

        .tab-button::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(52,152,219,0.2), rgba(46,204,113,0.2));
            opacity: 0;
            transition: opacity 0.3s;
        }

        .tab-button:hover {
            background: rgba(52,152,219,0.15);
            color: #ecf0f1;
            transform: translateY(-3px);
        }

        .tab-button:hover::before {
            width: 80%;
        }

        .tab-button:hover::after {
            opacity: 1;
        }

        .tab-button.active {
            background: linear-gradient(135deg, #3498db, #2ecc71);
            color: white;
            font-weight: 700;
            box-shadow: inset 0 -3px 10px rgba(0,0,0,0.2);
        }

        .tab-button.active::before {
            width: 100%;
            height: 4px;
            background: #fff;
        }

        .tab-content {
            padding: 0;
            display: none;
            animation: fadeIn 0.5s ease;
            background: #f8f9fa;
            position: relative;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        iframe {
            width: 100%;
            border: none;
            min-height: 700px;
            background: white;
            display: block;
        }

        .header-title {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 25px 30px;
            font-size: 24px;
            font-weight: 700;
            text-align: center;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            border-bottom: 4px solid #3498db;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .header-title-text {
            flex: 1;
            text-align: center;
            min-width: 300px;
        }

        .header-title-text::before {
            content: "🩻";
            margin-right: 10px;
            font-size: 28px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            background: rgba(255,255,255,0.1);
            padding: 10px 20px;
            border-radius: 25px;
        }

        .user-info i {
            font-size: 18px;
            color: #3498db;
        }

        .ip-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            background: rgba(46,204,113,0.2);
            padding: 10px 20px;
            border-radius: 25px;
            border: 2px solid rgba(46,204,113,0.4);
        }

        .ip-info i {
            font-size: 16px;
            color: #2ecc71;
        }

        .ip-info span {
            font-weight: 600;
            letter-spacing: 0.5px;
            font-family: 'Courier New', monospace;
        }

        .logout-btn {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: linear-gradient(135deg, #c0392b, #a93226);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231,76,60,0.4);
        }

        .logout-btn i {
            font-size: 14px;
        }

        /* Loading overlay */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.95);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .loading-overlay.hidden {
            display: none;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin-bottom: 15px;
        }

        .loading-text {
            color: #34495e;
            font-size: 14px;
            font-weight: 600;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Error message */
        .error-message {
            background: #fadbd8;
            color: #641e16;
            padding: 20px;
            margin: 20px;
            border-radius: 8px;
            border-left: 4px solid #e74c3c;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        @media (max-width: 768px) {
            .tab-button {
                padding: 15px 20px;
                font-size: 14px;
            }
            
            .header-title {
                font-size: 18px;
                padding: 20px;
                flex-direction: column;
                gap: 15px;
            }

            .header-left {
                width: 100%;
                justify-content: center;
            }

            .header-title-text {
                min-width: auto;
            }

            .header-title-text::before {
                font-size: 22px;
            }

            .user-info {
                font-size: 12px;
                padding: 8px 15px;
            }

            .ip-info {
                font-size: 11px;
                padding: 8px 15px;
            }

            .logout-btn {
                padding: 8px 15px;
                font-size: 12px;
            }

            .container {
                max-width: 100%;
                border-radius: 8px;
            }

            body {
                padding: 5px;
            }

            iframe {
                min-height: 600px;
            }
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .container {
                box-shadow: none;
                border-radius: 0;
            }
            
            .tab-header,
            .loading-overlay,
            .user-info,
            .ip-info,
            .logout-btn {
                display: none !important;
            }
            
            .tab-content {
                display: block !important;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header-title">
            <div class="header-left">
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($user_login); ?></span>
                </div>
                
                <div class="ip-info">
                    <i class="fas fa-network-wired"></i>
                    <span><?php echo htmlspecialchars($user_ip); ?></span>
                </div>
            </div>
            
            <div class="header-title-text">
                Sistem Informasi Radiologi
            </div>

            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
        
        <div class="tab-header">
            <button class="tab-button active" onclick="openTab(event, 'tab1')" id="btnTab1">
                📋 Data Permintaan Radiologi
            </button>
            <button class="tab-button" onclick="openTab(event, 'tab2')" id="btnTab2">
                🔬 Periksa Radiologi
            </button>
        </div>

        <div id="tab1" class="tab-content active">
            <div class="loading-overlay" id="loading1">
                <div class="spinner"></div>
                <div class="loading-text">Memuat data permintaan radiologi...</div>
            </div>
            <div class="error-message" id="error1">
                ❌ Gagal memuat halaman. Silakan refresh halaman.
            </div>
            <iframe src="data_pasien.php" id="iframe1" onload="handleIframeLoad('loading1', 'error1')" onerror="handleIframeError('loading1', 'error1')"></iframe>
        </div>

        <div id="tab2" class="tab-content">
            <div class="loading-overlay" id="loading2">
                <div class="spinner"></div>
                <div class="loading-text">Memuat data pemeriksaan radiologi...</div>
            </div>
            <div class="error-message" id="error2">
                ❌ Gagal memuat halaman. Silakan refresh halaman.
            </div>
            <iframe src="periksa_radiologi.php" id="iframe2" onload="handleIframeLoad('loading2', 'error2')" onerror="handleIframeError('loading2', 'error2')"></iframe>
        </div>
    </div>

    <script>
        // Tab switching function
        function openTab(evt, tabName) {
            var i, tabContent, tabButtons;
            
            // Hide all tab contents
            tabContent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabContent.length; i++) {
                tabContent[i].classList.remove("active");
            }
            
            // Remove active class from all buttons
            tabButtons = document.getElementsByClassName("tab-button");
            for (i = 0; i < tabButtons.length; i++) {
                tabButtons[i].classList.remove("active");
            }
            
            // Show current tab and mark button as active
            document.getElementById(tabName).classList.add("active");
            evt.currentTarget.classList.add("active");

            // Save active tab to localStorage
            localStorage.setItem('activeRadiologyTab', tabName);
        }

        // Handle iframe load success
        function handleIframeLoad(loadingId, errorId) {
            setTimeout(function() {
                document.getElementById(loadingId).classList.add('hidden');
                document.getElementById(errorId).classList.remove('show');
            }, 300);
        }

        // Handle iframe load error
        function handleIframeError(loadingId, errorId) {
            document.getElementById(loadingId).classList.add('hidden');
            document.getElementById(errorId).classList.add('show');
        }

        // Auto-resize iframe based on content
        window.addEventListener('message', function(e) {
            if (e.data && e.data.height) {
                var iframeId = e.data.iframeId || 'iframe1';
                var iframe = document.getElementById(iframeId);
                if(iframe) {
                    iframe.style.height = e.data.height + 'px';
                }
            }
        });

        // Restore active tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            var activeTab = localStorage.getItem('activeRadiologyTab');
            if(activeTab && (activeTab === 'tab1' || activeTab === 'tab2')) {
                var tabNumber = activeTab === 'tab1' ? 1 : 2;
                var btn = document.getElementById('btnTab' + tabNumber);
                if(btn) {
                    btn.click();
                }
            }
        });

        // Handle keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt + 1 for Tab 1
            if(e.altKey && e.key === '1') {
                e.preventDefault();
                document.getElementById('btnTab1').click();
            }
            // Alt + 2 for Tab 2
            if(e.altKey && e.key === '2') {
                e.preventDefault();
                document.getElementById('btnTab2').click();
            }
        });

        // Prevent multiple form submissions
        window.addEventListener('beforeunload', function(e) {
            // Check if there are unsaved changes in iframes
            try {
                var iframe1 = document.getElementById('iframe1');
                var iframe2 = document.getElementById('iframe2');
                
                if(iframe1 && iframe1.contentWindow) {
                    var form1 = iframe1.contentWindow.document.querySelector('form');
                    if(form1 && form1.classList.contains('modified')) {
                        e.preventDefault();
                        e.returnValue = '';
                        return '';
                    }
                }
                
                if(iframe2 && iframe2.contentWindow) {
                    var form2 = iframe2.contentWindow.document.querySelector('form');
                    if(form2 && form2.classList.contains('modified')) {
                        e.preventDefault();
                        e.returnValue = '';
                        return '';
                    }
                }
            } catch(err) {
                // Cross-origin restriction - ignore
            }
        });

        // Reload iframe when network reconnects
        window.addEventListener('online', function() {
            console.log('Network reconnected. Reloading iframes...');
            var activeTab = document.querySelector('.tab-content.active');
            if(activeTab) {
                var iframe = activeTab.querySelector('iframe');
                if(iframe) {
                    iframe.contentWindow.location.reload();
                }
            }
        });

        // Show offline message
        window.addEventListener('offline', function() {
            alert('⚠️ Koneksi internet terputus. Beberapa fitur mungkin tidak berfungsi.');
        });
    </script>
</body>
</html>
