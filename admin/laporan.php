<?php
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/laporan_data.php';

$filters = getReportFilters($_GET);
$report = getReportData($pdo, $filters);

$preset = $filters['preset'];
$tglMulai = $filters['start'];
$tglSelesai = $filters['end'];
$q = $filters['q'];

$totalOmzet = $report['total_omzet'];
$jumlahTransaksi = $report['jumlah_transaksi'];
$kasDiterima = $report['kas_diterima'];
$piutangBaru = $report['piutang_baru'];
$breakdownMetode = $report['breakdown'];
$toppingTerlaris = $report['topping_terlaris'];
$daftarTransaksi = $report['transactions'];

$labelMetode = ['tunai' => 'Tunai', 'qris' => 'QRIS', 'utang' => 'Utang'];
$exportUrl = 'export_laporan.php?' . reportQueryString($filters);

$page_title = 'Laporan Penjualan';
$active_page = 'laporan';
$wrap_max = 960;
$extra_style = <<<'CSS'
  .filter-bar, .filter-form, .report-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .filter-bar { margin-bottom:14px; }
  .filter-bar a.preset {
    display:flex; align-items:center; min-height:44px; padding:0 16px; border-radius:100px;
    border:1.5px solid var(--border); background:var(--bg);
    color:var(--text); text-decoration:none; font-size:13px; font-weight:500;
  }
  .filter-bar a.preset.active { background:var(--accent); border-color:var(--accent); color:#FFF8EF; }
  .filter-form > input[type=date] { width:auto; min-width:145px; }
  .filter-form > input[type=text] { flex:1; min-width:190px; }
  .filter-form button { background:var(--accent-3); color:#FFF8EF; }
  .reset-link, .report-actions a, .report-actions button {
    display:inline-flex; align-items:center; justify-content:center; min-height:44px;
    padding:0 16px; border-radius:100px; border:1.5px solid var(--border);
    background:var(--bg-card); color:var(--text); text-decoration:none; font:500 13.5px 'Poppins';
  }
  .report-actions { margin-top:14px; }
  .report-actions .export-link { background:var(--accent-3); border-color:var(--accent-3); color:#FFF8EF; }
  .report-actions .print-button { background:var(--accent); border-color:var(--accent); color:#FFF8EF; }
  .periode-label { color:var(--text-secondary); font-size:12.5px; margin:12px 0 0; }
  .summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; }
  .summary-box { background:var(--bg); border-radius:12px; padding:14px 16px; }
  .summary-box .sb-label { font-size:11.5px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.03em; }
  .summary-box .sb-value { font-family:'Anton',sans-serif; font-size:21px; margin-top:4px; }
  .summary-box .sb-note { font-size:11px; color:var(--text-secondary); margin-top:4px; }
  .summary-box.accent .sb-value { color:var(--accent); }
  .summary-box.accent3 .sb-value { color:var(--accent-3); }
  td.num { font-weight:600; }
  .rank-no { color:var(--text-secondary); width:24px; }
  .print-heading { display:none; }

  @media (max-width:640px) {
    .filter-form > input[type=date], .filter-form > input[type=text] { width:100%; }
    .filter-form button, .filter-form .reset-link { flex:1; }
    .report-actions > * { flex:1; }
  }

  @media print {
    @page { size:A4 portrait; margin:14mm; }
    body { background:#fff; color:#000; padding:0; font-size:11px; }
    .admin-header, footer, .no-print { display:none !important; }
    .wrap { max-width:none; padding:0; }
    .print-heading { display:block; margin-bottom:16px; }
    .print-heading h1 { font-family:Arial,sans-serif; font-size:20px; margin:0 0 6px; }
    .print-heading p { margin:2px 0; font-size:11px; }
    .card { border:0; border-radius:0; padding:0; margin:0 0 18px; break-inside:avoid; }
    .card h2 { font-family:Arial,sans-serif; font-size:14px; }
    .summary-grid { grid-template-columns:repeat(4,1fr); gap:8px; }
    .summary-box { border:1px solid #bbb; background:#fff; padding:8px; }
    .summary-box .sb-value { font-family:Arial,sans-serif; font-size:14px; }
    .summary-box .sb-note { display:none; }
    .table-scroll { overflow:visible; }
    table { min-width:0; font-size:9px; }
    th, td { padding:5px 4px; border-color:#bbb; }
    .badge { padding:0; background:none !important; color:#000 !important; }
    tr { break-inside:avoid; }
  }
CSS;
require_once '../includes/admin-header.php';
?>

  <div class="print-heading">
    <h1>Laporan Penjualan Seblak Bunga</h1>
    <p>Periode: <?= date('d M Y', strtotime($tglMulai)) ?> sampai <?= date('d M Y', strtotime($tglSelesai)) ?></p>
    <?php if ($q !== ''): ?><p>Kata kunci: <?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <p>Tanggal cetak: <?= date('d M Y, H:i') ?></p>
  </div>

  <div class="card no-print">
    <h2>periode &amp; pencarian</h2>
    <div class="filter-bar">
      <a class="preset <?= $preset === 'hari_ini' ? 'active' : '' ?>" href="laporan.php?<?= htmlspecialchars(reportQueryString($filters, ['preset' => 'hari_ini', 'q' => $q]), ENT_QUOTES, 'UTF-8') ?>">Hari ini</a>
      <a class="preset <?= $preset === 'minggu_ini' ? 'active' : '' ?>" href="laporan.php?<?= htmlspecialchars(reportQueryString($filters, ['preset' => 'minggu_ini', 'q' => $q]), ENT_QUOTES, 'UTF-8') ?>">Minggu ini</a>
      <a class="preset <?= $preset === 'bulan_ini' ? 'active' : '' ?>" href="laporan.php?<?= htmlspecialchars(reportQueryString($filters, ['preset' => 'bulan_ini', 'q' => $q]), ENT_QUOTES, 'UTF-8') ?>">Bulan ini</a>
    </div>

    <form class="filter-form" method="GET" action="laporan.php">
      <input type="hidden" name="preset" value="custom">
      <input type="date" name="dari" value="<?= htmlspecialchars($tglMulai, ENT_QUOTES, 'UTF-8') ?>" aria-label="Tanggal mulai">
      <span>—</span>
      <input type="date" name="sampai" value="<?= htmlspecialchars($tglSelesai, ENT_QUOTES, 'UTF-8') ?>" aria-label="Tanggal selesai">
      <input type="text" name="q" maxlength="100" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Kode, pelanggan, metode, atau status">
      <button type="submit">Cari / Terapkan</button>
      <a class="reset-link" href="laporan.php">Reset</a>
    </form>

    <p class="periode-label">
      Menampilkan periode <strong><?= date('d M Y', strtotime($tglMulai)) ?></strong>
      s/d <strong><?= date('d M Y', strtotime($tglSelesai)) ?></strong>
      <?= $q !== '' ? ' dengan kata kunci <strong>“' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '”</strong>' : '' ?>.
    </p>

    <div class="report-actions">
      <a class="export-link" href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>">Export Excel/CSV</a>
      <button type="button" class="print-button" onclick="window.print()">Cetak / PDF</button>
    </div>
  </div>

  <div class="card">
    <h2>ringkasan</h2>
    <div class="summary-grid">
      <div class="summary-box accent"><div class="sb-label">Total Omzet</div><div class="sb-value">Rp<?= number_format($totalOmzet, 0, ',', '.') ?></div><div class="sb-note">Nilai jualan kotor, termasuk yang belum dibayar</div></div>
      <div class="summary-box accent3"><div class="sb-label">Kas Diterima</div><div class="sb-value">Rp<?= number_format($kasDiterima, 0, ',', '.') ?></div><div class="sb-note">Uang yang benar-benar masuk periode ini</div></div>
      <div class="summary-box"><div class="sb-label">Piutang Baru</div><div class="sb-value">Rp<?= number_format($piutangBaru, 0, ',', '.') ?></div><div class="sb-note">Utang baru yang terbentuk periode ini</div></div>
      <div class="summary-box"><div class="sb-label">Jumlah Transaksi</div><div class="sb-value"><?= $jumlahTransaksi ?></div><div class="sb-note">Nota terbentuk periode ini</div></div>
    </div>
  </div>

  <div class="card">
    <h2>breakdown per metode bayar</h2>
    <div class="table-scroll"><table>
      <thead><tr><th>Metode</th><th class="num">Jumlah Transaksi</th><th class="num">Total Nominal</th></tr></thead>
      <tbody><?php foreach (['tunai', 'qris', 'utang'] as $m): $row = $breakdownMetode[$m]; ?><tr>
        <td><?= $labelMetode[$m] ?></td><td class="num"><?= (int) $row['jumlah'] ?></td><td class="num">Rp<?= number_format((int) $row['total'], 0, ',', '.') ?></td>
      </tr><?php endforeach; ?></tbody>
    </table></div>
  </div>

  <div class="card">
    <h2>topping terlaris</h2>
    <?php if (!$toppingTerlaris): ?>
      <p class="empty-note">Belum ada topping yang cocok di periode ini.</p>
    <?php else: ?>
      <div class="table-scroll"><table>
        <thead><tr><th></th><th>Topping</th><th class="num">Kali Dipesan</th></tr></thead>
        <tbody><?php foreach ($toppingTerlaris as $i => $row): ?><tr>
          <td class="rank-no">#<?= $i + 1 ?></td><td><?= htmlspecialchars($row['nama_topping'], ENT_QUOTES, 'UTF-8') ?></td><td class="num"><?= (int) $row['jumlah_dipesan'] ?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>daftar transaksi</h2>
    <?php if (!$daftarTransaksi): ?>
      <p class="empty-note">Tidak ada transaksi yang cocok dengan filter dan pencarian.</p>
    <?php else: ?>
      <div class="table-scroll"><table>
        <thead><tr><th>Kode</th><th>Tanggal</th><th>Pelanggan</th><th>Bayar</th><th>Status</th><th class="num">Total</th></tr></thead>
        <tbody><?php foreach ($daftarTransaksi as $t): ?><tr>
          <td><?= htmlspecialchars($t['kode_transaksi'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= date('d M Y, H:i', strtotime($t['tanggal'])) ?></td>
          <td><?= $t['nama_pelanggan'] ? htmlspecialchars($t['nama_pelanggan'], ENT_QUOTES, 'UTF-8') : 'Pembeli lewat' ?></td>
          <td><?= htmlspecialchars($labelMetode[$t['metode_bayar']] ?? $t['metode_bayar'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="badge badge-<?= htmlspecialchars($t['status_bayar'], ENT_QUOTES, 'UTF-8') ?>"><?= $t['status_bayar'] === 'lunas' ? 'Lunas' : 'Belum Lunas' ?></span></td>
          <td class="num">Rp<?= number_format((int) $t['total_bayar'], 0, ',', '.') ?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php require_once '../includes/admin-footer.php'; ?>