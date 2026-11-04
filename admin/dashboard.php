<?php
require_once '../includes/auth_check.php';
require_once '../config/database.php';

$stmt = $pdo->query("
    SELECT COUNT(*) AS jumlah, COALESCE(SUM(total_bayar), 0) AS omzet
    FROM transaksi
    WHERE DATE(tanggal) = CURDATE()
");
$hariIni = $stmt->fetch();

$stmt = $pdo->query("
    SELECT
        COALESCE((SELECT SUM(total_bayar) FROM transaksi
                  WHERE metode_bayar IN ('tunai', 'qris') AND DATE(tanggal) = CURDATE()), 0)
        +
        COALESCE((SELECT SUM(jumlah_bayar) FROM pembayaran_piutang
                  WHERE DATE(tanggal_bayar) = CURDATE()), 0)
");
$kasHariIni = (int) $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COALESCE(SUM(t.total_bayar - COALESCE(byr.total_dibayar, 0)), 0)
    FROM transaksi t
    LEFT JOIN (
        SELECT id_transaksi, SUM(jumlah_bayar) AS total_dibayar
        FROM pembayaran_piutang GROUP BY id_transaksi
    ) byr ON byr.id_transaksi = t.id
    WHERE t.metode_bayar = 'utang' AND t.status_bayar = 'belum_lunas'
");
$totalPiutang = (int) $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT t.kode_transaksi, t.tanggal, t.metode_bayar, t.status_bayar, t.total_bayar,
           p.nama AS nama_pelanggan
    FROM transaksi t
    LEFT JOIN pelanggan p ON p.id = t.id_pelanggan
    ORDER BY t.id DESC LIMIT 5
");
$transaksiTerbaru = $stmt->fetchAll();

$page_title = 'Dashboard';
$active_page = 'dashboard';
$extra_style = <<<'CSS'
  .summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:20px; }
  .summary-box { background:var(--bg-card); border:1px solid var(--border); border-radius:14px; padding:16px; }
  .summary-label { color:var(--text-secondary); font-size:12px; }
  .summary-value { font-family:'Anton', Impact, system-ui, sans-serif; font-size:22px; margin-top:4px; }
CSS;
require_once '../includes/admin-header.php';
?>
  <div class="card">
    <p style="margin:0;">Halo, <strong><?= htmlspecialchars($_SESSION['nama'], ENT_QUOTES, 'UTF-8') ?></strong>.</p>
  </div>
  <div class="summary-grid">
    <div class="summary-box"><div class="summary-label">Omzet hari ini</div><div class="summary-value">Rp<?= number_format((int) $hariIni['omzet'], 0, ',', '.') ?></div></div>
    <div class="summary-box"><div class="summary-label">Transaksi hari ini</div><div class="summary-value"><?= (int) $hariIni['jumlah'] ?> nota</div></div>
    <div class="summary-box"><div class="summary-label">Kas diterima hari ini</div><div class="summary-value">Rp<?= number_format($kasHariIni, 0, ',', '.') ?></div></div>
    <div class="summary-box"><div class="summary-label">Piutang berjalan</div><div class="summary-value">Rp<?= number_format($totalPiutang, 0, ',', '.') ?></div></div>
  </div>
  <div class="card">
    <h2>transaksi terbaru</h2>
    <?php if (!$transaksiTerbaru): ?>
      <p class="empty-note">Belum ada transaksi.</p>
    <?php else: ?>
      <div class="table-scroll"><table>
        <thead><tr><th>Kode</th><th>Waktu</th><th>Pelanggan</th><th>Metode</th><th>Status</th><th class="num">Total</th></tr></thead>
        <tbody><?php foreach ($transaksiTerbaru as $t): ?><tr>
          <td><?= htmlspecialchars($t['kode_transaksi'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= date('d M Y, H:i', strtotime($t['tanggal'])) ?></td>
          <td><?= $t['nama_pelanggan'] ? htmlspecialchars($t['nama_pelanggan'], ENT_QUOTES, 'UTF-8') : 'Pembeli lewat' ?></td>
          <td><?= htmlspecialchars(strtoupper($t['metode_bayar']), ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="badge badge-<?= htmlspecialchars($t['status_bayar'], ENT_QUOTES, 'UTF-8') ?>"><?= $t['status_bayar'] === 'lunas' ? 'Lunas' : 'Belum Lunas' ?></span></td>
          <td class="num">Rp<?= number_format((int) $t['total_bayar'], 0, ',', '.') ?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>
<?php require_once '../includes/admin-footer.php'; ?>