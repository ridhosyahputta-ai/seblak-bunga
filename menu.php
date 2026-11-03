<?php
require_once 'config/database.php';

$page_title = 'Menu';
$active_page = 'menu';

$stmt = $pdo->prepare("SELECT nilai FROM pengaturan WHERE nama_pengaturan = 'harga_dasar_seblak'");
$stmt->execute();
$harga_dasar = (int) $stmt->fetchColumn();

$stmt = $pdo->query("SELECT nama_topping, harga_tambahan, foto FROM topping WHERE status_aktif = 1 ORDER BY nama_topping ASC");
$toppings = $stmt->fetchAll();

include 'includes/header.php';
?>

<section class="page-header">
  <div class="wrap">
    <p class="section-label">racik sendiri</p>
    <h1 class="page-title">bebas pilih isian</h1>
  </div>
</section>

<section style="padding-top:0">
  <div class="wrap">
    <div class="base-price-card">
      <span class="bp-label">Harga dasar seblak</span>
      <span class="bp-value">Rp<?= formatRb($harga_dasar) ?></span>
    </div>

    <div class="pedas-note">
      <span class="pn-tag">level pedas?</span>
      <p>Gak pake skala angka baku — tinggal bilang aja seberapa nampol yang kamu mau, langsung diracik sesuai request.</p>
    </div>

    <div class="topping-grid">
      <?php foreach ($toppings as $t): ?>
        <?php
          $confirmed = ($t['nama_topping'] === 'Telur');
          $fotoFile  = basename((string) ($t['foto'] ?? ''));
          $fotoSrc   = 'assets/img/topping/' . (($fotoFile !== '' && is_file(__DIR__ . '/assets/img/topping/' . $fotoFile)) ? rawurlencode($fotoFile) : 'default.svg');
        ?>
        <div class="topping-chip">
          <img src="<?= htmlspecialchars($fotoSrc) ?>" alt="<?= htmlspecialchars($t['nama_topping']) ?>" loading="lazy" style="width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:8px;display:block">
          <span class="tp-name"><?= htmlspecialchars($t['nama_topping']) ?></span>
          <span class="tp-price<?= $confirmed ? ' confirmed' : '' ?>">+Rp<?= formatRb($t['harga_tambahan']) ?><?= $confirmed ? '' : '*' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="topping-note">*harga estimasi, nunggu konfirmasi harga pasti dari Teh Dewi. Total per porsi sekitar Rp3rb–Rp10rb tergantung topping yang dipilih.</p>
  </div>
</section>

<section>
  <div class="wrap">
    <div class="order-section">
      <h2 class="section-title">udah nentuin racikanmu?</h2>
      <p>Chat langsung, sebutin topping + level pedas yang kamu mau.</p>
      <a class="btn-whatsapp" href="https://wa.me/62895324228216" target="_blank" rel="noopener">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12c0 1.85.5 3.58 1.35 5.07L2 22l5.1-1.33A9.94 9.94 0 0012 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18a7.9 7.9 0 01-4.05-1.11l-.29-.17-3.02.79.8-2.95-.19-.3A7.93 7.93 0 014 12c0-4.41 3.59-8 8-8s8 3.59 8 8-3.59 8-8 8z"/></svg>
        Chat via WhatsApp
      </a>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>