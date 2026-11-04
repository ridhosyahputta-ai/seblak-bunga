<?php
// Detail piutang per pelanggan + input cicilan. Pembayaran dicatat
// per-transaksi (pembayaran_piutang.id_transaksi), bukan sebagai saldo
// global per pelanggan, supaya jelas nota mana yang jadi lunas.

require_once '../includes/auth_check.php';
require_once '../config/database.php';

$id_pelanggan = isset($_GET['id_pelanggan']) ? (int) $_GET['id_pelanggan'] : 0;
if ($id_pelanggan <= 0) {
    header('Location: piutang.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, nama, no_hp FROM pelanggan WHERE id = ?");
$stmt->execute([$id_pelanggan]);
$pelanggan = $stmt->fetch();

if (!$pelanggan) {
    header('Location: piutang.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

// ------------------------------------------------------------
// Proses submit cicilan
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Form kadaluarsa (token tidak cocok). Silakan submit ulang.';
    }

    $id_transaksi = filter_var($_POST['id_transaksi'] ?? null, FILTER_VALIDATE_INT);
    $jumlah_bayar = filter_var($_POST['jumlah_bayar'] ?? null, FILTER_VALIDATE_INT);

    if (!$id_transaksi || $id_transaksi <= 0) {
        $errors[] = 'Transaksi tidak valid.';
    }
    if (!$jumlah_bayar || $jumlah_bayar <= 0) {
        $errors[] = 'Jumlah bayar harus lebih dari 0.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Kunci baris transaksi sampai commit. Ini mencegah dua tab
            // membayar sisa yang sama secara bersamaan dan membuat overpayment.
            $stmt = $pdo->prepare("
                SELECT id, total_bayar, status_bayar, metode_bayar, id_pelanggan
                FROM transaksi
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$id_transaksi]);
            $target = $stmt->fetch();

            if (!$target) {
                throw new DomainException('Transaksi tidak ditemukan.');
            }
            if ((int) $target['id_pelanggan'] !== $id_pelanggan) {
                throw new DomainException('Transaksi ini bukan milik pelanggan yang sedang dibuka.');
            }
            if ($target['metode_bayar'] !== 'utang' || $target['status_bayar'] !== 'belum_lunas') {
                throw new DomainException('Transaksi ini bukan utang yang masih berjalan (mungkin sudah lunas).');
            }

            $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah_bayar), 0) FROM pembayaran_piutang WHERE id_transaksi = ?");
            $stmt->execute([$id_transaksi]);
            $totalDibayar = (int) $stmt->fetchColumn();
            $sisaSaatIni = (int) $target['total_bayar'] - $totalDibayar;

            if ($sisaSaatIni <= 0) {
                throw new DomainException('Transaksi ini sudah lunas.');
            }
            if ($jumlah_bayar > $sisaSaatIni) {
                throw new DomainException(
                    'Jumlah bayar Rp' . number_format($jumlah_bayar, 0, ',', '.')
                    . ' melebihi sisa utang transaksi ini (Rp'
                    . number_format($sisaSaatIni, 0, ',', '.') . ').'
                );
            }

            $stmt = $pdo->prepare("INSERT INTO pembayaran_piutang (id_transaksi, jumlah_bayar) VALUES (?, ?)");
            $stmt->execute([$id_transaksi, $jumlah_bayar]);

            if ($jumlah_bayar === $sisaSaatIni) {
                $stmt = $pdo->prepare("UPDATE transaksi SET status_bayar = 'lunas' WHERE id = ?");
                $stmt->execute([$id_transaksi]);
            }

            $pdo->commit();

            unset($_SESSION['csrf_token']);
            $_SESSION['flash_success'] = 'Cicilan Rp' . number_format($jumlah_bayar, 0, ',', '.') . ' berhasil dicatat.';
            header('Location: piutang_detail.php?id_pelanggan=' . $id_pelanggan);
            exit;
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Gagal menyimpan cicilan: ' . $e->getMessage());
            $errors[] = 'Terjadi kesalahan pada sistem. Silakan coba lagi.';
        }
    }
}
$success = null;
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// ------------------------------------------------------------
// Semua transaksi utang pelanggan ini, lunas maupun belum.
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT t.id, t.kode_transaksi, t.tanggal, t.total_bayar, t.status_bayar,
           COALESCE(SUM(pp.jumlah_bayar), 0) AS total_dibayar
    FROM transaksi t
    LEFT JOIN pembayaran_piutang pp ON pp.id_transaksi = t.id
    WHERE t.id_pelanggan = ? AND t.metode_bayar = 'utang'
    GROUP BY t.id, t.kode_transaksi, t.tanggal, t.total_bayar, t.status_bayar
    ORDER BY t.tanggal DESC
");
$stmt->execute([$id_pelanggan]);
$transaksiList = $stmt->fetchAll();

// Query terpisah biar SUM(pp.jumlah_bayar) di atas gak fan-out, lalu
// dikelompokkan per id_transaksi di PHP.
$riwayatCicilan = [];
if ($transaksiList) {
    $ids = array_column($transaksiList, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT id_transaksi, tanggal_bayar, jumlah_bayar
        FROM pembayaran_piutang
        WHERE id_transaksi IN ($placeholders)
        ORDER BY tanggal_bayar ASC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $riwayatCicilan[$row['id_transaksi']][] = $row;
    }
}

$totalSisaPelanggan = 0;
foreach ($transaksiList as $t) {
    if ($t['status_bayar'] === 'belum_lunas') {
        $totalSisaPelanggan += (int) $t['total_bayar'] - (int) $t['total_dibayar'];
    }
}

$page_title  = 'Piutang ' . $pelanggan['nama'];
$active_page = 'piutang';
$extra_style = <<<CSS
  .pelanggan-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
  .pelanggan-head .ph-name { font-weight: 600; font-size: 15px; }
  .pelanggan-head .ph-hp { color: var(--text-secondary); font-size: 12.5px; }
  .pelanggan-head .ph-sisa { font-family: 'Anton', Impact, system-ui, sans-serif; font-size: 22px; color: var(--accent); }

  .nota-card { border: 1px dashed var(--border); border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
  .nota-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; }
  .nota-kode { font-weight: 600; font-size: 13.5px; }
  .nota-tanggal { color: var(--text-secondary); font-size: 12px; }

  .nota-angka { display: flex; gap: 20px; margin-top: 10px; font-size: 12.5px; flex-wrap: wrap; }
  .nota-angka div span { display: block; color: var(--text-secondary); font-size: 11px; }
  .nota-angka div strong { font-size: 14px; }

  .cicilan-list { margin-top: 10px; font-size: 11.5px; color: var(--text-secondary); }
  .cicilan-list ul { margin: 4px 0 0; padding-left: 16px; }

  .form-cicilan { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: stretch; }
  .form-cicilan input[type=number] { flex: 1; min-width: 180px; margin: 0; }
  .form-cicilan button { flex-shrink: 0; }
CSS;
require_once '../includes/admin-header.php';
?>

  <?php if ($success): ?>
    <div class="success-box"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="error-box">
      <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="pelanggan-head">
      <div>
        <div class="ph-name"><?= htmlspecialchars($pelanggan['nama']) ?></div>
        <div class="ph-hp"><?= $pelanggan['no_hp'] ? htmlspecialchars($pelanggan['no_hp']) : 'no HP tidak dicatat' ?></div>
      </div>
      <div style="text-align:right;">
        <span class="ph-hp">Sisa utang saat ini</span><br>
        <span class="ph-sisa">Rp<?= number_format($totalSisaPelanggan, 0, ',', '.') ?></span>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 style="margin-bottom:14px;">riwayat transaksi utang</h2>

    <?php if (!$transaksiList): ?>
      <p class="empty-note">Pelanggan ini belum pernah punya transaksi utang.</p>
    <?php else: ?>
      <?php foreach ($transaksiList as $t):
          $sisa = (int) $t['total_bayar'] - (int) $t['total_dibayar'];
      ?>
        <div class="nota-card">
          <div class="nota-head">
            <span class="nota-kode"><?= htmlspecialchars($t['kode_transaksi']) ?></span>
            <span class="badge badge-<?= $t['status_bayar'] ?>"><?= $t['status_bayar'] === 'lunas' ? 'Lunas' : 'Belum Lunas' ?></span>
          </div>
          <div class="nota-tanggal"><?= date('d M Y, H:i', strtotime($t['tanggal'])) ?></div>

          <div class="nota-angka">
            <div><span>Total nota</span><strong>Rp<?= number_format((int) $t['total_bayar'], 0, ',', '.') ?></strong></div>
            <div><span>Sudah dibayar</span><strong>Rp<?= number_format((int) $t['total_dibayar'], 0, ',', '.') ?></strong></div>
            <div><span>Sisa</span><strong>Rp<?= number_format($sisa, 0, ',', '.') ?></strong></div>
          </div>

          <?php if (!empty($riwayatCicilan[$t['id']])): ?>
            <div class="cicilan-list">
              Riwayat cicilan:
              <ul>
                <?php foreach ($riwayatCicilan[$t['id']] as $c): ?>
                  <li><?= date('d M Y', strtotime($c['tanggal_bayar'])) ?> — Rp<?= number_format((int) $c['jumlah_bayar'], 0, ',', '.') ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <?php if ($t['status_bayar'] === 'belum_lunas'): ?>
            <form method="POST" action="piutang_detail.php?id_pelanggan=<?= $id_pelanggan ?>" class="form-cicilan">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="id_transaksi" value="<?= (int) $t['id'] ?>">
              <input type="number" name="jumlah_bayar" min="1" max="<?= $sisa ?>" placeholder="Jumlah cicilan (maks Rp<?= number_format($sisa,0,',','.') ?>)" required>
              <button type="submit" class="btn-secondary" style="background:var(--accent); color:#FFF8EF; border-color:var(--accent);">Catat Cicilan</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<?php require_once '../includes/admin-footer.php'; ?>

