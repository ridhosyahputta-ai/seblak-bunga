<?php
// Header khusus panel admin -- terpisah dari includes/header.php punya sisi
// publik karena kebutuhan navigasinya beda total (nav menu publik vs nav
// kerja kasir/admin yang harus selalu kelihatan & gampang di-tap).
// Reuse design token (warna, font) yang sama dari header.php publik biar
// masih terasa "satu web", tapi struktur & ukuran komponennya dioptimalkan
// buat dipakai sambil kerja: tap target gede, input jelas, tabel gak
// ngerusak layout di HP sempit.
//
// Dipanggil dari tiap halaman admin (SETELAH auth_check.php & database.php,
// setelah semua proses form selesai) dengan set dulu:
//   $page_title  = 'Nama Halaman';
//   $active_page = 'dashboard' | 'transaksi' | 'piutang' | 'laporan' | 'topping';
//   $wrap_max    = 720; // opsional, lebar max konten dalam px (default 720)
//   $extra_style = '...css tambahan khusus halaman ini...'; // opsional

if (!isset($page_title))  { $page_title = ''; }
if (!isset($active_page)) { $active_page = ''; }
if (!isset($wrap_max))    { $wrap_max = 720; }
if (!isset($extra_style)) { $extra_style = ''; }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function navClass($page, $active_page) {
    return $page === $active_page ? ' class="active"' : '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $page_title ? htmlspecialchars($page_title) . ' — ' : '' ?>Admin Seblak Bunga</title>
<link rel="stylesheet" href="../assets/css/fonts.css">
<style>
  :root {
    --bg: #F5EDE0;
    --bg-card: #FFFCF6;
    --text: #2B2420;
    --text-secondary: #6B6459;
    --accent: #C1372A;
    --accent-dark: #8F2A20;
    --accent-2: #E8A33D;
    --accent-3: #4A7A3C;
    --border: rgba(43,36,32,0.14);
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--text);
    font-family: 'Poppins', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.55; padding-bottom: 50px;
  }
  .wrap { max-width: <?= (int) $wrap_max ?>px; margin: 0 auto; padding: 0 16px; }

  /* ---------- Header & navigasi ---------- */
  .admin-header { background: var(--bg-card); border-bottom: 1px solid var(--border); margin-bottom: 22px; }
  .admin-header .wrap { padding: 16px; }
  .brand-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
  .brand { display: flex; align-items: baseline; gap: 8px; }
  .brand .brand-name { font-family: 'Anton', Impact, system-ui, -apple-system, sans-serif; font-size: 16px; text-transform: lowercase; }
  .brand .brand-tag { font-family: 'Caveat', cursive, "Segoe Print", sans-serif; font-size: 16px; color: var(--accent-3); }
  h1.page-title { font-family: 'Anton', Impact, system-ui, -apple-system, sans-serif; text-transform: lowercase; font-size: 22px; margin: 0; }

  nav.admin-nav { display: flex; gap: 8px; flex-wrap: wrap; }
  nav.admin-nav a {
    display: flex; align-items: center; justify-content: center;
    min-height: 44px; padding: 0 16px; border-radius: 12px;
    background: var(--bg); border: 1.5px solid var(--border);
    color: var(--text); text-decoration: none; font-size: 13.5px; font-weight: 500;
    white-space: nowrap;
  }
  nav.admin-nav a:hover { border-color: var(--accent); }
  nav.admin-nav a.active { background: var(--accent); border-color: var(--accent); color: #FFF8EF; }
  nav.admin-nav form.logout-form { margin: 0 0 0 auto; }
  nav.admin-nav button.logout-link {
    min-height: 44px; padding: 0 16px; border-radius: 12px;
    background: var(--bg); border: 1.5px solid var(--border);
    color: var(--accent-dark); white-space: nowrap;
  }

  /* ---------- Komponen umum dipakai di banyak halaman admin ---------- */
  .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 20px; margin-bottom: 20px; }
  .card h2 { font-family: 'Anton', Impact, system-ui, -apple-system, sans-serif; text-transform: lowercase; font-size: 17px; margin: 0 0 14px; }

  label { font-size: 13.5px; font-weight: 600; display: block; margin: 14px 0 7px; }
  label .opt { font-weight: 400; color: var(--text-secondary); }

  input[type=text], input[type=tel], input[type=number], input[type=date],
  input[type=password], select {
    width: 100%; min-height: 46px; padding: 11px 14px; border-radius: 10px;
    border: 1.5px solid var(--border); font-family: 'Poppins', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 14.5px;
    background: var(--bg); color: var(--text);
  }
  input:focus, select:focus { outline: 2px solid var(--accent); outline-offset: 1px; }

  /* Radio besar berbentuk kartu -- dipakai buat metode bayar, pelanggan_mode, dll. */
  .radio-group { display: flex; gap: 10px; flex-wrap: wrap; }
  .radio-group label {
    display: flex; align-items: center; gap: 8px; margin: 0; font-weight: 500; font-size: 14px;
    min-height: 44px; padding: 0 14px; border: 1.5px solid var(--border); border-radius: 12px;
    background: var(--bg-card);
  }
  .radio-group input[type=radio] { width: 20px; height: 20px; margin: 0; accent-color: var(--accent); }

  input[type=checkbox] { width: 20px; height: 20px; accent-color: var(--accent); }

  /* Tombol */
  button, .btn {
    border: none; border-radius: 100px; font-family: 'Poppins', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-weight: 500; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; text-decoration: none;
    min-height: 44px; padding: 0 18px; font-size: 13.5px;
  }
  .btn-primary { background: var(--accent); color: #FFF8EF; font-size: 15px; min-height: 50px; width: 100%; }
  .btn-primary:hover { background: var(--accent-dark); }
  .btn-secondary { background: var(--bg-card); border: 1.5px solid var(--border); color: var(--text); }
  .btn-secondary:hover { border-color: var(--accent); }
  .btn-danger { background: #FBE4E1; color: var(--accent-dark); }
  .btn-danger:hover { background: #F6D0CB; }

  /* Tabel -- overflow-x:auto biar tabel lebar gak ngerusak layout di HP. */
  .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  table { width: 100%; min-width: 480px; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--border); vertical-align: middle; }
  th { color: var(--text-secondary); font-weight: 600; font-size: 12px; white-space: nowrap; }
  td.num, th.num { text-align: right; }

  .badge { display: inline-block; padding: 3px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; white-space: nowrap; }
  .badge-lunas, .badge-aktif { background: #E4EEE0; color: var(--accent-3); }
  .badge-belum_lunas { background: #FBE4E1; color: var(--accent-dark); }
  .badge-nonaktif { background: #EFEAE0; color: var(--text-secondary); }

  .error-box, .success-box { border-radius: 12px; padding: 14px 16px; font-size: 13.5px; margin-bottom: 18px; }
  .error-box { background: #FBE4E1; color: var(--accent-dark); }
  .success-box { background: #E4EEE0; color: var(--accent-3); }
  .error-box ul { margin: 0; padding-left: 18px; }

  .empty-note { color: var(--text-secondary); font-size: 13.5px; }
</style>
<?php if ($extra_style !== ''): ?>
<style><?= $extra_style ?></style>
<?php endif; ?>
</head>
<body>

<div class="admin-header">
  <div class="wrap">
    <div class="brand-row">
      <div class="brand">
        <span class="brand-name">seblak bunga</span>
        <span class="brand-tag">panel admin</span>
      </div>
      <h1 class="page-title"><?= htmlspecialchars($page_title ?: 'admin') ?></h1>
    </div>
    <nav class="admin-nav">
      <a href="dashboard.php"<?= navClass('dashboard', $active_page) ?>>Dashboard</a>
      <a href="transaksi.php"<?= navClass('transaksi', $active_page) ?>>Input Transaksi</a>
      <a href="piutang.php"<?= navClass('piutang', $active_page) ?>>Piutang</a>
      <a href="laporan.php"<?= navClass('laporan', $active_page) ?>>Laporan</a>
      <a href="topping.php"<?= navClass('topping', $active_page) ?>>Topping</a>
      <form method="POST" action="logout.php" class="logout-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="logout-link">Logout</button>
      </form>
    </nav>
  </div>
</div>

<div class="wrap">
