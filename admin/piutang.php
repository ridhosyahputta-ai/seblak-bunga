<?php
// Manajemen piutang -- pelanggan dianggap "punya piutang" kalau minimal
// 1 transaksi metode 'utang' dengan status 'belum_lunas'.

require_once '../includes/auth_check.php';
require_once '../config/database.php';

// Agregasi pembayaran di subquery (byr) dulu sebelum JOIN, biar gak fan-out
// (1 transaksi dicicil 3x bisa bikin SUM(total_bayar) kelipatan 3 kalau JOIN langsung).
$stmt = $pdo->query("
    SELECT p.id, p.nama, p.no_hp,
           SUM(t.total_bayar - COALESCE(byr.total_dibayar, 0)) AS sisa_utang,
           COUNT(t.id) AS jumlah_transaksi,
           MIN(t.tanggal) AS tanggal_tertua
    FROM pelanggan p
    JOIN transaksi t
        ON t.id_pelanggan = p.id
       AND t.metode_bayar = 'utang'
       AND t.status_bayar = 'belum_lunas'
    LEFT JOIN (
        SELECT id_transaksi, SUM(jumlah_bayar) AS total_dibayar
        FROM pembayaran_piutang
        GROUP BY id_transaksi
    ) byr ON byr.id_transaksi = t.id
    GROUP BY p.id, p.nama, p.no_hp
    ORDER BY sisa_utang DESC
");
$daftarPiutang = $stmt->fetchAll();

$totalSemuaPiutang = 0;
foreach ($daftarPiutang as $row) {
    $totalSemuaPiutang += (int) $row['sisa_utang'];
}

$page_title  = 'Manajemen Piutang';
$active_page = 'piutang';
$extra_style = <<<CSS
  .summary-total { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
  .summary-total .st-value { font-family: 'Anton', sans-serif; font-size: 24px; color: var(--accent); }
  a.detail-link {
    display: inline-flex; align-items: center; min-height: 44px; padding: 0 14px;
    color: var(--accent); text-decoration: none; font-weight: 500; font-size: 12.5px;
  }
CSS;
require_once '../includes/admin-header.php';
?>

  <div class="card">
    <div class="summary-total">
      <div>
        <h2>total piutang berjalan</h2>
        <p class="empty-note" style="margin:0;"><?= count($daftarPiutang) ?> pelanggan masih punya utang.</p>
      </div>
      <span class="st-value">Rp<?= number_format($totalSemuaPiutang, 0, ',', '.') ?></span>
    </div>
  </div>

  <div class="card">
    <h2 style="margin-bottom:14px;">daftar pelanggan berutang</h2>
    <?php if (!$daftarPiutang): ?>
      <p class="empty-note">Gak ada piutang yang masih berjalan saat ini.</p>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead>
            <tr><th>Nama</th><th>No. HP</th><th>Utang Tertua</th><th>Transaksi</th><th class="num">Sisa Utang</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($daftarPiutang as $row): ?>
              <tr>
                <td><?= htmlspecialchars($row['nama']) ?></td>
                <td><?= $row['no_hp'] ? htmlspecialchars($row['no_hp']) : '—' ?></td>
                <td><?= date('d M Y', strtotime($row['tanggal_tertua'])) ?></td>
                <td><?= (int) $row['jumlah_transaksi'] ?> nota</td>
                <td class="num">Rp<?= number_format((int) $row['sisa_utang'], 0, ',', '.') ?></td>
                <td><a class="detail-link" href="piutang_detail.php?id_pelanggan=<?= (int) $row['id'] ?>">Detail →</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php require_once '../includes/admin-footer.php'; ?>

