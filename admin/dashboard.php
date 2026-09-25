<?php
require_once '../includes/auth_check.php';

$page_title  = 'Dashboard';
$active_page = 'dashboard';
require_once '../includes/admin-header.php';
?>

  <div class="card">
    <p style="margin:0;">Halo, <strong><?= htmlspecialchars($_SESSION['nama']) ?></strong> — login berhasil.</p>
    <p class="empty-note" style="margin-top:14px;">
      Ini halaman placeholder buat buktiin sistem login &amp; proteksi
      halaman admin udah jalan bener. Isi dashboard beneran (ringkasan
      omzet, piutang jalan, dll) dibikin di langkah berikutnya. Sementara
      itu, semua halaman kerja sudah bisa diakses lewat menu navigasi
      di atas.
    </p>
  </div>

<?php require_once '../includes/admin-footer.php'; ?>
