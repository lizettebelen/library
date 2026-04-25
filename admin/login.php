<?php
session_start();
require_once '../config/db.php';

$error = '';
$username = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// If already logged in, redirect
if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'dashboard.php' : '../index.php'));
    exit();
}

// Handle login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error = 'Session expired. Please refresh and try again.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Username and password are required.';
    } else {
        // Check if user exists
        $query = "SELECT id, username, name, password, role FROM users WHERE username = ?";
        $stmt = $conn->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Login successful
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['role'] = $user['role'];
                    
                    // Redirect based on role
                    if ($user['role'] === 'admin') {
                        header('Location: dashboard.php');
                    } else {
                        header('Location: ../index.php');
                    }
                    exit();
                } else {
                    $error = 'Invalid username or password.';
                }
            } else {
                $error = 'Invalid username or password.';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Lindley's Library</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #132136;
            --muted: #5f7088;
            --line: #d6dfeb;
            --panel: #ffffff;
            --field: #f6f9fc;
            --accent: #0f766e;
            --accent-strong: #0b5f59;
            --error-bg: #fff1f2;
            --error-text: #9f1239;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: var(--ink);
            font-family: 'Manrope', sans-serif;
            background:
                radial-gradient(circle at 10% 15%, rgba(20, 184, 166, 0.18), transparent 30%),
                radial-gradient(circle at 90% 80%, rgba(14, 165, 233, 0.14), transparent 28%),
                linear-gradient(135deg, #eef3f8 0%, #f8fafc 100%);
        }

        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-card {
            background: var(--panel);
            padding: 34px 30px 28px;
            border-radius: 22px;
            border: 1px solid rgba(255, 255, 255, 0.65);
            box-shadow: 0 26px 60px rgba(15, 23, 42, 0.16);
            max-width: 460px;
            width: 100%;
            animation: card-in 260ms ease-out;
        }

        @keyframes card-in {
            from {
                opacity: 0;
                transform: translateY(10px) scale(0.99);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .brand-mark {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #ffffff;
            display: grid;
            place-items: center;
            overflow: hidden;
        }

        .brand-mark img {
            width: 26px;
            height: 26px;
            object-fit: contain;
        }

        .brand-meta {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .brand-title {
            font-size: 0.9rem;
            font-weight: 800;
            color: var(--ink);
            letter-spacing: 0.01em;
        }

        .brand-sub {
            font-size: 0.8rem;
            color: var(--muted);
            font-weight: 600;
        }

        .login-card h1 {
            margin: 8px 0 8px;
            font: 700 2.1rem/1.05 'Fraunces', serif;
            color: var(--ink);
            letter-spacing: -0.01em;
        }

        .login-card .subtitle {
            color: var(--muted);
            margin: 0 0 22px;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 7px;
            color: #2a3b53;
            font-weight: 800;
            font-size: 0.76rem;
            letter-spacing: 0.07em;
            text-transform: uppercase;
        }

        .form-group input {
            padding: 13px 14px;
            border: 1px solid var(--line);
            border-radius: 11px;
            font-size: 1rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            background: var(--field);
            color: var(--ink);
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap input {
            padding-right: 48px;
            width: 100%;
        }

        .password-toggle {
            position: absolute;
            right: 7px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #516179;
            font-size: 0.78rem;
            font-weight: 800;
            cursor: pointer;
            padding: 6px 8px;
            border-radius: 6px;
        }

        .password-toggle:hover {
            background: #e9eff7;
            color: var(--ink);
        }

        .form-group input:focus {
            outline: none;
            border-color: #0d9488;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.14);
        }

        .login-btn {
            background: linear-gradient(135deg, var(--accent) 0%, #0d9488 100%);
            color: white;
            padding: 13px 18px;
            border: none;
            border-radius: 11px;
            font-weight: 800;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            font-size: 1rem;
            letter-spacing: 0.01em;
        }

        .login-btn:hover {
            transform: translateY(-1px);
            background: linear-gradient(135deg, var(--accent-strong) 0%, #0b7f78 100%);
            box-shadow: 0 12px 28px rgba(15, 118, 110, 0.28);
        }

        .login-btn:disabled {
            opacity: 0.75;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .alert-error {
            background-color: var(--error-bg);
            color: var(--error-text);
            padding: 12px 16px;
            border-radius: 11px;
            border: 1px solid #fecdd3;
            margin-bottom: 16px;
            font-size: 0.92rem;
            font-weight: 600;
        }

        .caps-warning {
            min-height: 18px;
            font-size: 0.78rem;
            color: #b45309;
            font-weight: 700;
            margin-top: 6px;
            visibility: hidden;
        }

        .caps-warning.show {
            visibility: visible;
        }

        .back-home {
            text-align: center;
            margin-top: 18px;
        }

        .back-home a {
            color: #0e7490;
            text-decoration: none;
            font-weight: 700;
            transition: color 0.2s ease;
            font-size: 0.93rem;
        }

        .back-home a:hover {
            color: #155e75;
        }

        .demo-info {
            background: #f2f8ff;
            border: 1px solid #c7dbf4;
            padding: 12px 14px;
            border-radius: 11px;
            margin-top: 16px;
            font-size: 0.86rem;
            color: #134169;
        }

        .demo-info strong {
            display: block;
            margin-bottom: 7px;
        }

        .demo-info code {
            font-family: Consolas, Monaco, monospace;
            background: rgba(19, 65, 105, 0.08);
            padding: 1px 4px;
            border-radius: 5px;
        }

        @media (max-width: 560px) {
            .login-container {
                padding: 14px;
            }

            .login-card {
                padding: 24px 18px 20px;
                border-radius: 16px;
            }

            .login-card h1 {
                font-size: 1.75rem;
            }

            .login-card .subtitle {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="brand-row">
                <div class="brand-mark">
                    <img src="../assets/images/bdd2f027-3b4b-49f9-af69-109f1dec609b.png" alt="Library logo">
                </div>
                <div class="brand-meta">
                    <span class="brand-title">Lindley's Library</span>
                    <span class="brand-sub">Admin access</span>
                </div>
            </div>

            <h1>Welcome Back</h1>
            <p class="subtitle">Sign in to manage stories, chapters, and uploads.</p>
            
            <?php if ($error): ?>
                <div class="alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="login-form" id="adminLoginForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus value="<?php echo htmlspecialchars($username); ?>" autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrap">
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password">Show</button>
                    </div>
                    <div class="caps-warning" id="capsWarning">Caps Lock is on.</div>
                </div>
                
                <button type="submit" class="login-btn" id="loginSubmitBtn">Sign In</button>
            </form>
            
            <div class="demo-info">
                <strong>Demo Credentials:</strong>
                Username: <code>lindley</code><br>
                Password: <code>lindley123</code>
            </div>
            
            <div class="back-home">
                <a href="../index.php">← Back to Library</a>
            </div>
        </div>
    </div>

    <script>
        const loginForm = document.getElementById('adminLoginForm');
        const submitBtn = document.getElementById('loginSubmitBtn');
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('togglePassword');
        const capsWarning = document.getElementById('capsWarning');

        if (loginForm && submitBtn) {
            loginForm.addEventListener('submit', function () {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Signing in...';
            });
        }

        if (passwordInput && passwordToggle) {
            passwordToggle.addEventListener('click', function () {
                const isHidden = passwordInput.type === 'password';
                passwordInput.type = isHidden ? 'text' : 'password';
                passwordToggle.textContent = isHidden ? 'Hide' : 'Show';
                passwordToggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });

            passwordInput.addEventListener('keyup', function (event) {
                if (!capsWarning) {
                    return;
                }
                const capsOn = event.getModifierState && event.getModifierState('CapsLock');
                capsWarning.classList.toggle('show', !!capsOn);
            });

            passwordInput.addEventListener('blur', function () {
                if (capsWarning) {
                    capsWarning.classList.remove('show');
                }
            });
        }
    </script>
</body>
</html>
