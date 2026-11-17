<?php
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_radiologi'])) {
    header('Location: index.php');
    exit();
}

// config.php defines DB connection, HOSPITAL_NAME, AES keys and role codes
require_once 'config.php';

// CSRF token for the login form
if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}

$error = '';

// Process login
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $tujuan   = $_POST['tujuan'] ?? '';
    $csrf     = $_POST['csrf'] ?? '';

    if (!hash_equals($_SESSION['csrf_login'], $csrf)) {
        $error = 'Sesi tidak valid, silakan muat ulang halaman.';
    } elseif (empty($username) || empty($password) || empty($tujuan)) {
        $error = 'Semua field harus diisi!';
    } else {
        try {
            $login_success = false;
            $username_display = '';
            $user_role = '';
            $is_radiologi_user = false;

            // CHECK 1: USER table joined to PETUGAS (to get kd_jbtn)
            $sql_user_petugas = "SELECT
                                    CAST(AES_DECRYPT(u.id_user, :k_user_1) AS CHAR) as username_decrypt,
                                    CAST(AES_DECRYPT(u.password, :k_pass_1) AS CHAR) as password_decrypt,
                                    p.kd_jbtn,
                                    p.nama as nama_petugas
                                FROM user u
                                INNER JOIN petugas p ON CAST(AES_DECRYPT(u.id_user, :k_user_2) AS CHAR) = p.nip
                                WHERE CAST(AES_DECRYPT(u.id_user, :k_user_3) AS CHAR) = :username";

            $stmt_user_petugas = $pdo->prepare($sql_user_petugas);
            $stmt_user_petugas->execute([
                'k_user_1' => AES_KEY_USER,
                'k_pass_1' => AES_KEY_PASS,
                'k_user_2' => AES_KEY_USER,
                'k_user_3' => AES_KEY_USER,
                'username' => $username
            ]);
            $user_petugas_data = $stmt_user_petugas->fetch(PDO::FETCH_ASSOC);

            if ($user_petugas_data && hash_equals((string)$user_petugas_data['password_decrypt'], $password)) {
                // User found in the petugas table
                $is_radiologi_user = ($user_petugas_data['kd_jbtn'] === ROLE_CODE_RADIOLOGY_STAFF);

                // Validate access by destination
                if ($tujuan === 'radiologi' && !$is_radiologi_user) {
                    $error = 'Anda tidak memiliki akses ke Radiologi!';
                } else if ($tujuan === 'igd_poli_ranap' && $is_radiologi_user) {
                    $error = 'Anda hanya memiliki akses ke Radiologi!';
                } else {
                    $login_success = true;
                    $username_display = $user_petugas_data['nama_petugas'];
                    $user_role = $is_radiologi_user ? 'radiologi' : 'user';
                }
            }

            // CHECK 2: USER table joined to DOKTER (to get kd_sps) - if not logged in yet
            if (!$login_success && empty($error)) {
                $sql_user_dokter = "SELECT
                                        CAST(AES_DECRYPT(u.id_user, :k_user_1) AS CHAR) as username_decrypt,
                                        CAST(AES_DECRYPT(u.password, :k_pass_1) AS CHAR) as password_decrypt,
                                        d.kd_sps,
                                        d.nm_dokter
                                    FROM user u
                                    INNER JOIN dokter d ON CAST(AES_DECRYPT(u.id_user, :k_user_2) AS CHAR) = d.kd_dokter
                                    WHERE CAST(AES_DECRYPT(u.id_user, :k_user_3) AS CHAR) = :username";

                $stmt_user_dokter = $pdo->prepare($sql_user_dokter);
                $stmt_user_dokter->execute([
                    'k_user_1' => AES_KEY_USER,
                    'k_pass_1' => AES_KEY_PASS,
                    'k_user_2' => AES_KEY_USER,
                    'k_user_3' => AES_KEY_USER,
                    'username' => $username
                ]);
                $user_dokter_data = $stmt_user_dokter->fetch(PDO::FETCH_ASSOC);

                if ($user_dokter_data && hash_equals((string)$user_dokter_data['password_decrypt'], $password)) {
                    // User found in the dokter table
                    $is_radiologi_user = ($user_dokter_data['kd_sps'] === ROLE_CODE_RADIOLOGY_DOCTOR);

                    // Validate access by destination
                    if ($tujuan === 'radiologi' && !$is_radiologi_user) {
                        $error = 'Anda tidak memiliki akses ke Radiologi!';
                    } else if ($tujuan === 'igd_poli_ranap' && $is_radiologi_user) {
                        $error = 'Anda hanya memiliki akses ke Radiologi!';
                    } else {
                        $login_success = true;
                        $username_display = $user_dokter_data['nm_dokter'];
                        $user_role = $is_radiologi_user ? 'radiologi' : 'dokter';
                    }
                }
            }

            // CHECK 3: ADMIN table (usere, passworde with AES) - can access everything
            if (!$login_success && empty($error)) {
                $sql_admin = "SELECT
                                CAST(AES_DECRYPT(usere, :k_user_1) AS CHAR) as username_decrypt,
                                CAST(AES_DECRYPT(passworde, :k_pass_1) AS CHAR) as password_decrypt
                            FROM admin
                            WHERE CAST(AES_DECRYPT(usere, :k_user_2) AS CHAR) = :username";

                $stmt_admin = $pdo->prepare($sql_admin);
                $stmt_admin->execute([
                    'k_user_1' => AES_KEY_USER,
                    'k_pass_1' => AES_KEY_PASS,
                    'k_user_2' => AES_KEY_USER,
                    'username' => $username
                ]);
                $admin_data = $stmt_admin->fetch(PDO::FETCH_ASSOC);

                if ($admin_data && hash_equals((string)$admin_data['password_decrypt'], $password)) {
                    // Admin can access every destination
                    $login_success = true;
                    $username_display = 'Admin Utama';
                    $user_role = 'superadmin';
                }
            }

            // LOGIN SUCCESS
            if ($login_success) {
                // Prevent session fixation
                session_regenerate_id(true);

                $_SESSION['user_radiologi'] = $username_display;
                $_SESSION['user_role'] = $user_role;
                $_SESSION['login_time'] = date('Y-m-d H:i:s');
                unset($_SESSION['csrf_login']);

                // Redirect by destination
                if ($tujuan === 'radiologi') {
                    header('Location: index.php');
                } else {
                    header('Location: periksa_radiologi_user.php');
                }
                exit();
            } else {
                // Default error if none was set
                if (empty($error)) {
                    $error = 'Username atau Password salah!';
                }
            }

        } catch (PDOException $e) {
            // Log details server-side, show a generic message
            error_log('Login error: ' . $e->getMessage());
            $error = 'Login gagal karena kesalahan server.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Sistem Radiologi - <?php echo htmlspecialchars(HOSPITAL_NAME); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #00630d 0%, #055f19 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgb(255, 238, 0);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            background: linear-gradient(135deg, #002857 0%, #001f3f 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .login-header .logo {
            font-size: 100px;
            margin-bottom: 5px;
            animation: pulse 2s ease infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .login-header p {
            font-size: 16px;
            opacity: 0.9;
            color: #ecf0f1;
        }

        .login-form {
            padding: 40px 35px;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group label i {
            margin-right: 8px;
            color: #3498db;
        }

        .input-wrapper {
            position: relative;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 14px 45px 14px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3498db;
            background: white;
            box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
        }

        .form-group select {
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
        }

        .form-group select option {
            padding: 10px;
            font-size: 15px;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            font-size: 18px;
            pointer-events: none;
            z-index: 1;
        }

        /* Custom dropdown arrow */
        .select-arrow {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            font-size: 14px;
            pointer-events: none;
            transition: transform 0.3s;
        }

        .form-group select:focus ~ .select-arrow {
            transform: translateY(-50%) rotate(180deg);
            color: #3498db;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            cursor: pointer;
            font-size: 18px;
            transition: color 0.3s;
            z-index: 2;
        }

        .toggle-password:hover {
            color: #3498db;
        }

        .error-message {
            background: #fadbd8;
            color: #c0392b;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            border-left: 4px solid #e74c3c;
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        .error-message i {
            margin-right: 10px;
            font-size: 16px;
        }

        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #001f3f, #001f3f);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 5px 15px rgba(52,152,219,0.4);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(52,152,219,0.6);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login:focus {
            outline: 3px solid rgba(52,152,219,0.5);
            outline-offset: 2px;
        }

        .btn-login i {
            margin-right: 8px;
        }

        .login-footer {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            font-size: 15px;
            color: #7f8c8d;
        }

        .login-footer i {
            color: #e74c3c;
            animation: heartbeat 1.5s ease infinite;
        }

        @keyframes heartbeat {
            0%, 100% { transform: scale(1); }
            25% { transform: scale(1.1); }
        }

        /* Keyboard hint */
        .keyboard-hint {
            position: absolute;
            right: 50px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 11px;
            color: #95a5a6;
            background: white;
            padding: 2px 8px;
            border-radius: 4px;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            white-space: nowrap;
            z-index: 10;
        }

        .form-group select:focus ~ .keyboard-hint {
            opacity: 1;
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 10px;
            }

            .login-header h1 {
                font-size: 20px;
            }

            .login-form {
                padding: 20px 15px;
            }

            .keyboard-hint {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">🩻</div>
            <h1>Sistem Radiologi</h1>
            <p><?php echo htmlspecialchars(HOSPITAL_NAME); ?></p>
        </div>

        <div class="login-form">
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="off" id="loginForm">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf_login']); ?>">

                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user"></i> Username
                    </label>
                    <div class="input-wrapper">
                        <i class="input-icon fas fa-user-circle"></i>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Masukkan username Anda"
                            required
                            autofocus
                            tabindex="1"
                            value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <div class="input-wrapper">
                        <i class="input-icon fas fa-key"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Masukkan password Anda"
                            required
                            tabindex="2"
                        >
                        <i class="toggle-password fas fa-eye" id="togglePassword" tabindex="-1"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="tujuan">
                        <i class="fas fa-hospital"></i> Pilih Tujuan
                    </label>
                    <div class="input-wrapper">
                        <i class="input-icon fas fa-clipboard-list"></i>
                        <select id="tujuan" name="tujuan" required tabindex="3">
                            <option value="">-- Pilih Tujuan --</option>
                            <option value="radiologi" <?php echo (isset($_POST['tujuan']) && $_POST['tujuan'] === 'radiologi') ? 'selected' : ''; ?>>Radiologi</option>
                            <option value="igd_poli_ranap" <?php echo (isset($_POST['tujuan']) && $_POST['tujuan'] === 'igd_poli_ranap') ? 'selected' : ''; ?>>IGD/Poli/Ranap</option>
                        </select>
                        <i class="select-arrow fas fa-chevron-down"></i>
                        <span class="keyboard-hint">↑↓ pilih, Enter konfirmasi</span>
                    </div>
                </div>
                <button type="submit" class="btn-login" tabindex="4" id="btnLogin">
                    <i class="fas fa-sign-in-alt"></i>Login</button>
            </form>
        </div>
        <div class="login-footer">
            Made with <i class="fas fa-heart"></i> From SIMRS
        </div>
    </div>

    <script>
        // ============================================
        // TOGGLE PASSWORD VISIBILITY
        // ============================================
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);

            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        // ============================================
        // ENHANCED KEYBOARD NAVIGATION
        // ============================================
        const usernameInput = document.getElementById('username');
        const selectTujuan = document.getElementById('tujuan');
        const btnLogin = document.getElementById('btnLogin');

        // Enter in Username -> move to Password
        usernameInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                passwordInput.focus();
            }
        });

        // Enter in Password -> move to the destination select
        passwordInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                selectTujuan.focus();

                // Auto-expand the dropdown for convenience
                setTimeout(() => {
                    if (selectTujuan.size === 0) {
                        selectTujuan.size = selectTujuan.options.length;
                        selectTujuan.classList.add('expanded');
                    }
                }, 50);
            }
        });

        // Destination select: Arrow keys + Enter
        let isSelectExpanded = false;

        selectTujuan.addEventListener('keydown', function(e) {
            // Arrow Down/Up -> navigate options (native browser behavior)
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!isSelectExpanded) {
                    isSelectExpanded = true;
                    this.size = this.options.length; // Expand dropdown
                    this.classList.add('expanded');
                }
            }

            // Enter -> confirm selection and focus the login button
            if (e.key === 'Enter') {
                e.preventDefault();

                // Close dropdown
                if (isSelectExpanded) {
                    this.size = 0;
                    this.classList.remove('expanded');
                    isSelectExpanded = false;
                }

                // If something is selected, move to the login button
                if (this.value !== '') {
                    btnLogin.focus();
                }
            }

            // Escape -> close dropdown without selecting
            if (e.key === 'Escape') {
                e.preventDefault();
                this.size = 0;
                this.classList.remove('expanded');
                isSelectExpanded = false;
                this.blur();
            }
        });

        // Auto-collapse dropdown on blur
        selectTujuan.addEventListener('blur', function() {
            setTimeout(() => {
                this.size = 0;
                this.classList.remove('expanded');
                isSelectExpanded = false;
            }, 150);
        });

        // Auto-focus the login button after selecting (mouse/keyboard)
        selectTujuan.addEventListener('change', function() {
            if (this.value !== '') {
                this.size = 0;
                this.classList.remove('expanded');
                isSelectExpanded = false;

                setTimeout(() => {
                    btnLogin.focus();
                }, 100);
            }
        });

        // Enter on the login button -> submit the form
        btnLogin.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('loginForm').submit();
            }
        });

        // ============================================
        // VALIDATION & UX ENHANCEMENTS
        // ============================================

        // Prevent accidental form submission while on the select
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const tujuanValue = selectTujuan.value;

            if (tujuanValue === '') {
                e.preventDefault();
                selectTujuan.focus();

                // Show error hint
                const hint = document.querySelector('.keyboard-hint');
                if (hint) {
                    hint.style.opacity = '1';
                    hint.style.color = '#e74c3c';
                    hint.style.fontWeight = 'bold';
                    hint.textContent = '⚠️ Pilih tujuan terlebih dahulu!';

                    setTimeout(() => {
                        hint.style.color = '#95a5a6';
                        hint.style.fontWeight = 'normal';
                        hint.textContent = '↑↓ pilih, Enter konfirmasi';
                    }, 2000);
                }
            }
        });
    </script>
</body>
</html>