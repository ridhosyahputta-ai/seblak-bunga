<?php
// Laporan penjualan. "Total Omzet" dan "Kas Diterima" sengaja dipisah
// (accrual vs cash basis) -- lihat komentar di masing-masing query.

require_once '../includes/auth_check.php';
require_once '../config/database.php';

// Rentang tanggal dari filter GET. Default: hari ini. Preset dihitung
// ulang tiap request (gak disimpan), jadi otomatis maju tiap hari.
$preset = $_GET['preset'] ?? 'hari_ini';

$hariIni = new DateTime('today');

switch ($preset) {
    case 'minggu_ini':
        // Senin minggu berjalan s/d hari ini. Pakai 'monday this week'
        // relatif ke hari ini supaya benar walau hari ini hari Minggu.
        $mulai   = (clone $hariIni)->modify('monday this week');
        $selesai = $hariIni;
        break;

    case 'bulan_ini':
        $mulai   = (clone $hariIni)->modify('first day of this month');
        $selesai = $hariIni;
        break;

    case 'custom':
        // Fallback ke hari ini kalau format tanggal invalid/kosong.
        $mulai   = DateTime::createFromFormat('Y-m-d', $_GET['dari'] ?? '') ?: (clone $hariIni);
        $selesai = DateTime::createFromFormat('Y-m-d', $_GET['sampai'] ?? '') ?: (clone $hariIni);
        // Tukar posisi kalau user kebalik isi dari/sampai.
        if ($mulai > $selesai) {
            [$mulai, $selesai] = [$selesai, $mulai];
        }
        $preset = 'custom';
        break;

    case 'hari_ini':
    default:
        $mulai   = clone $hariIni;
        $selesai = clone $hariIni;
        $preset  = 'hari_ini';
        break;
}

$tglMulai   = $mulai->format('Y-m-d');
$tglSelesai = $selesai->format('Y-m-d');

// Total Omzet (accrual basis): semua transaksi yang terbentuk di
// periode ini, apapun metode/status bayarnya.
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_bayar), 0) AS total, COUNT(*) AS jumlah
    FROM transaksi
    WHERE DATE(tanggal) BETWEEN ? AND ?
");
$stmt->execute([$tglMulai, $tglSelesai]);
$omzetRow     = $stmt->fetch();
$totalOmzet   = (int) $omzetRow['total'];
$jumlahTransaksi = (int) $omzetRow['jumlah'];

// Kas Diterima (cash basis): tunai/QRIS yang terbentuk di periode ini,
// ditambah cicilan piutang yang TANGGAL BAYARNYA jatuh di periode ini
// (walau nota induknya dari periode sebelumnya).
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_bayar), 0)
    FROM transaksi
    WHERE metode_bayar IN ('tunai', 'qris')
      AND DATE(tanggal) BETWEEN ? AND ?
");
$stmt->execute([$tglMulai, $tglSelesai]);
$kasDariTunaiQris = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(jumlah_bayar), 0)
    FROM pembayaran_piutang
    WHERE DATE(tanggal_bayar) BETWEEN ? AND ?
");
$stmt->execute([$tglMulai, $tglSelesai]);
$kasDariCicilan = (int) $stmt->fetchColumn();

$kasDiterima = $kasDariTunaiQris + $kasDariCicilan;

// Piutang Baru: nilai utang yang terbentuk di periode ini (bukan sisa
// piutang total -- itu urusan piutang.php).
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_bayar), 0)
    FROM transaksi
    WHERE metode_bayar = 'utang'
      AND DATE(tanggal) BETWEEN ? AND ?
");
$stmt->execute([$tglMulai, $tglSelesai]);
$piutangBaru = (int) $stmt->fetchColumn();

// Breakdown per metode bayar.
$stmt = $pdo->prepare("
    SELECT metode_bayar, COUNT(*) AS jumlah, COALESCE(SUM(total_bayar), 0) AS total
    FROM transaksi
    WHERE DATE(tanggal) BETWEEN ? AND ?
    GROUP BY metode_bayar
");
$stmt->execute([$tglMulai, $tglSelesai]);
$breakdownMetode = [];
foreach ($stmt->fetchAll() as $row) {
    $breakdownMetode[$row['metode_bayar']] = $row;
}
// Pastikan ketiga metode selalu tampil walau nilainya 0.
foreach (['tunai', 'qris', 'utang'] as $m) {
    if (!isset($breakdownMetode[$m])) {
        $breakdownMetode[$m] = ['metode_bayar' => $m, 'jumlah' => 0, 'total' => 0];
    }
}

// Topping terlaris. Beda kasus dari fan-out di piutang.php: di sini
// COUNT(*) dari hasil JOIN memang angka yang benar karena kita mau
// hitung banyaknya baris, bukan SUM nominal yang bisa ke-duplikat.
$stmt = $pdo->prepare("
    SELECT tp.nama_topping, COUNT(*) AS jumlah_dipesan
    FROM transaksi_detail_topping tdt
    JOIN transaksi_detail td ON td.id = tdt.id_transaksi_detail
    JOIN transaksi t ON t.id = td.id_transaksi
    JOIN topping tp ON tp.id = tdt.id_topping
    WHERE DATE(t.tanggal) BETWEEN ? AND ?
    GROUP BY tp.id, tp.nama_topping
    ORDER BY jumlah_dipesan DESC
");
$stmt->execute([$tglMulai, $tglSelesai]);
$toppingTerlaris = $stmt->fetchAll();

// Daftar transaksi di periode ini.
$stmt = $pdo->prepare("
    SELECT t.kode_transaksi, t.tanggal, t.metode_bayar, t.status_bayar, t.total_bayar,
           p.nama AS nama_pelanggan
    FROM transaksi t
    LEFT JOIN pelanggan p ON p.id = t.id_pelanggan
    WHERE DATE(t.tanggal) BETWEEN ? AND ?
    ORDER BY t.tanggal DESC
");
$stmt->execute([$tglMulai, $tglSelesai]);
$daftarTransaksi = $stmt->fetchAll();

$labelMetode = ['tunai' => 'Tunai', 'qris' => 'QRIS', 'utang' => 'Utang'];

$page_title  = 'Laporan Penjualan';
$active_page = 'laporan';
$wrap_max    = 960;
$extra_style = <<<CSS
  .filter-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
  .filter-bar a.preset {
    display: flex; align-items: center; min-height: 44px; padding: 0 16px; border-radius: 100px;
    border: 1.5px solid var(--border); background: var(--bg);
    color: var(--text); text-decoration: none; font-size: 13px; font-weight: 500;
  }
  .filter-bar a.preset.active { background: var(--accent); border-color: var(--accent); color: #FFF8EF; }
  .filter-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .filter-form input[type=date] { width: auto; min-width: 150px; }
  .filter-form button { background: var(--accent-3); color: #FFF8EF; flex-shrink: 0; }
  .filter-form button:hover { background: var(--accent-dark); }
  .periode-label { color: var(--text-secondary); font-size: 12.5px; margin: 12px 0 0; }

  .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; }
  .summary-box { background: var(--bg); border-radius: 12px; padding: 14px 16px; }
  .summary-box .sb-label { font-size: 11.5px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.03em; }
  .summary-box .sb-value { font-family: 'Anton', sans-serif; font-size: 21px; margin-top: 4px; }
  .summary-box .sb-note { font-size: 11px; color: var(--text-secondary); margin-top: 4px; }
  .summary-box.accent .sb-value { color: var(--accent); }
  .summary-box.accent3 .sb-value { color: var(--accent-3); }

  td.num { font-weight: 600; }
  .rank-no { color: var(--text-secondary); width: 24px; }
CSS;
require_once '../includes/admin-header.php';
?>

  <div class="card">
    <h2>periode</h2>
    <div class="filter-bar">
      <a class="preset <?= $preset === 'hari_ini' ? 'active' : '' ?>" href="laporan.php?preset=hari_ini">Hari ini</a>
      <a class="preset <?= $preset === 'minggu_ini' ? 'active' : '' ?>" href="laporan.php?preset=minggu_ini">Minggu ini</a>
      <a class="preset <?= $preset === 'bulan_ini' ? 'active' : '' ?>" href="laporan.php?preset=bulan_ini">Bulan ini</a>
    </div>
    <form class="filter-form" method="GET" action="laporan.php">
      <input type="hidden" name="preset" value="custom">
      <input type="date" name="dari" value="<?= htmlspecialchars($tglMulai) ?>">
      <span>—</span>
      <input type="date" name="sampai" value="<?= htmlspecialchars($tglSelesai) ?>">
      <button type="submit">Tampilkan</button>
    </form>
    <p class="periode-label">
      Menampilkan periode <strong><?= date('d M Y', strtotime($tglMulai)) ?></strong>
      s/d <strong><?= date('d M Y', strtotime($tglSelesai)) ?></strong>.
    </p>
  </div>

  <div class="card">
    <h2>ringkasan</h2>
    <div class="summary-grid">
      <div class="summary-box accent">
        <div class="sb-label">Total Omzet</div>
        <div class="sb-value">Rp<?= number_format($totalOmzet, 0, ',', '.') ?></div>
        <div class="sb-note">Nilai jualan kotor, termasuk yang belum dibayar</div>
      </div>
      <div class="summary-box accent3">
        <div class="sb-label">Kas Diterima</div>
        <div class="sb-value">Rp<?= number_format($kasDiterima, 0, ',', '.') ?></div>
        <div class="sb-note">Uang yang benar-benar masuk periode ini</div>
      </div>
      <div class="summary-box">
        <div class="sb-label">Piutang Baru</div>
        <div class="sb-value">Rp<?= number_format($piutangBaru, 0, ',', '.') ?></div>
        <div class="sb-note">Utang baru yang terbentuk periode ini</div>
      </div>
      <div class="summary-box">
        <div class="sb-label">Jumlah Transaksi</div>
        <div class="sb-value"><?= $jumlahTransaksi ?></div>
        <div class="sb-note">Nota terbentuk periode ini</div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>breakdown per metode bayar</h2>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Metode</th><th class="num">Jumlah Transaksi</th><th class="num">Total Nominal</th></tr></thead>
        <tbody>
          <?php foreach (['tunai', 'qris', 'utang'] as $m): $row = $breakdownMetode[$m]; ?>
            <tr>
              <td><?= $labelMetode[$m] ?></td>
              <td class="num"><?= (int) $row['jumlah'] ?></td>
              <td class="num">Rp<?= number_format((int) $row['total'], 0, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>topping terlaris</h2>
    <?php if (!$toppingTerlaris): ?>
      <p class="empty-note">Belum ada topping yang dipesan di periode ini.</p>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th></th><th>Topping</th><th class="num">Kali Dipesan</th></tr></thead>
          <tbody>
            <?php foreach ($toppingTerlaris as $i => $row): ?>
              <tr>
                <td class="rank-no">#<?= $i + 1 ?></td>
                <td><?= htmlspecialchars($row['nama_topping']) ?></td>
                <td class="num"><?= (int) $row['jumlah_dipesan'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>daftar transaksi</h2>
    <?php if (!$daftarTransaksi): ?>
      <p class="empty-note">Belum ada transaksi di periode ini.</p>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead>
            <tr><th>Kode</th><th>Tanggal</th><th>Pelanggan</th><th>Bayar</th><th>Status</th><th class="num">Total</th></tr>
          </thead>
          <tbody>
            <?php foreach ($daftarTransaksi as $t): ?>
              <tr>
                <td><?= htmlspecialchars($t['kode_transaksi']) ?></td>
                <td><?= date('d M Y, H:i', strtotime($t['tanggal'])) ?></td>
                <td><?= $t['nama_pelanggan'] ? htmlspecialchars($t['nama_pelanggan']) : 'Pembeli lewat' ?></td>
                <td><?= htmlspecialchars($labelMetode[$t['metode_bayar']] ?? $t['metode_bayar']) ?></td>
                <td><span class="badge badge-<?= $t['status_bayar'] ?>"><?= $t['status_bayar'] === 'lunas' ? 'Lunas' : 'Belum Lunas' ?></span></td>
                <td class="num">Rp<?= number_format((int) $t['total_bayar'], 0, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php require_once '../includes/admin-footer.php'; ?>

