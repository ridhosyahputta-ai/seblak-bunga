<?php
require_once 'config/database.php';

$page_title = '';
$active_page = 'beranda';

// Cuma butuh harga dasar di sini buat teaser -- daftar topping
// lengkap gak di-query lagi karena udah pindah ke menu.php,
// gak perlu query data yang gak bakal ditampilin di halaman ini.
$stmt = $pdo->prepare("SELECT nilai FROM pengaturan WHERE nama_pengaturan = 'harga_dasar_seblak'");
$stmt->execute();
$harga_dasar = (int) $stmt->fetchColumn();

include 'includes/header.php';
?>

<section class="hero">
  <div class="wrap">
    <div class="hero-inner">
      <span class="eyebrow">dari dapur rumahan, langsung ke lidahmu</span>
      <h1 class="headline">pedasnya nempel,<br><span class="hl">rasanya nagih</span></h1>
      <p class="sub">Seblak Bunga — kerupuk basah pedas racikan rumahan Teh Dewi. Level pedas bisa disesuaikan, dari yang biasa aja sampai yang bikin nangis.</p>
      <div class="cta-row">
        <a href="menu.php" class="btn-primary">Lihat menu →</a>
        <a href="kontak.php" class="btn-secondary">Cara pesan</a>
      </div>
    </div>
  </div>
</section>

<svg class="wave" viewBox="0 0 1080 40" preserveAspectRatio="none" aria-hidden="true">
  <path d="M0 20 Q 30 0 60 20 T 120 20 T 180 20 T 240 20 T 300 20 T 360 20 T 420 20 T 480 20 T 540 20 T 600 20 T 660 20 T 720 20 T 780 20 T 840 20 T 900 20 T 960 20 T 1020 20 T 1080 20 V40 H0 Z" fill="var(--bg-card)"/>
</svg>

<section style="background:var(--bg-card)">
  <div class="wrap about-block">
    <div class="about-text">
      <p class="section-label">cerita singkat</p>
      <h2 class="section-title">bukan seblak pabrikan</h2>
      <p>Sejak 2022, Seblak Bunga dikelola mandiri oleh Teh Dewi — dari belanja bahan ke pasar sampai masak langsung di depan pembeli. Nama "Bunga" sendiri diambil dari nama anak Teh Dewi.</p>
      <a href="tentang.php" class="link-more">Baca cerita lengkap →</a>
    </div>
    <div class="about-badge">sejak<br>2022</div>
  </div>
</section>

<section style="background:var(--bg-card)">
  <div class="wrap">
    <p class="section-label">racik sendiri</p>
    <h2 class="section-title">bebas pilih isian</h2>

    <div class="base-price-card">
      <span class="bp-label">Harga dasar seblak</span>
      <span class="bp-value">Rp<?= formatRb($harga_dasar) ?></span>
    </div>

    <p style="font-size:13.5px;color:var(--text-secondary);margin-bottom:6px;">7 pilihan topping bisa kamu racik sendiri, plus level pedas bebas sesuai request.</p>
    <a href="menu.php" class="link-more">Lihat semua topping →</a>
  </div>
</section>

<section>
  <div class="wrap">
    <div class="order-section">
      <h2 class="section-title">mau pesan?</h2>
      <p>Chat langsung, sebutin topping + level pedas yang kamu mau. Bisa cash, QRIS, atau langganan boleh nyicil.</p>
      <a class="btn-whatsapp" href="https://wa.me/62895324228216" target="_blank" rel="noopener">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12c0 1.85.5 3.58 1.35 5.07L2 22l5.1-1.33A9.94 9.94 0 0012 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18a7.9 7.9 0 01-4.05-1.11l-.29-.17-3.02.79.8-2.95-.19-.3A7.93 7.93 0 014 12c0-4.41 3.59-8 8-8s8 3.59 8 8-3.59 8-8 8z"/></svg>
        Chat via WhatsApp
      </a>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>