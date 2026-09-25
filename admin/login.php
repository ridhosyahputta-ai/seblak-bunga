<?php

session_start();
require_once '../config/database.php';


if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        
        $stmt = $pdo->prepare("SELECT id, username, password, nama FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

      
        if ($user && password_verify($password, $user['password'])) {
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nama'] = $user['nama'];
            header('Location: dashboard.php');
            exit;
        } else {
            
            $error = 'Username atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login Admin  Seblak Bunga</title>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #F5EDE0; --text: #2B2420; --text-secondary: #6B6459;
    --accent: #C1372A; --accent-dark: #8F2A20; --border: rgba(43,36,32,0.14);
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    background: var(--bg); color: var(--text); font-family: 'Poppins', sans-serif;
  }
  .login-card {
    background: #FFFCF6; border: 1px solid var(--border); border-radius: 18px;
    padding: 36px 30px; width: 100%; max-width: 360px; margin: 20px;
  }
  .login-card h1 {
    font-family: 'Anton', sans-serif; font-size: 24px; text-transform: lowercase;
    margin: 0 0 4px; text-align: center;
  }
  .login-card p.sub { text-align: center; font-size: 13px; color: var(--text-secondary); margin: 0 0 26px; }
  label { font-size: 13px; font-weight: 500; display: block; margin-bottom: 6px; }
  input {
    width: 100%; padding: 11px 14px; border-radius: 10px; border: 1px solid var(--border);
    font-family: 'Poppins'; font-size: 14px; margin-bottom: 16px; background: var(--bg); color: var(--text);
  }
  input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
  button {
    width: 100%; padding: 12px; border: none; border-radius: 100px;
    background: var(--accent); color: #FFF8EF; font-family: 'Poppins'; font-weight: 500; font-size: 14px;
    cursor: pointer;
  }
  button:hover { background: var(--accent-dark); }
  .error-box {
    background: #FBE4E1; color: var(--accent-dark); border-radius: 10px;
    padding: 10px 14px; font-size: 13px; margin-bottom: 16px;
  }
</style>
</head>
<body>

<div class="login-card">
  <h1>seblak bunga</h1>
  <p class="sub">Panel admin  khusus Teh Dewi</p>

  <?php if ($error): ?>
    <div class="error-box"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="login.php">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= htmlspecialchars($username ?? '') ?>" autofocus>

    <label for="password">Password</label>
    <input type="password" id="password" name="password">

    <button type="submit">Masuk</button>
  </form>
</div>

</body>
</html>