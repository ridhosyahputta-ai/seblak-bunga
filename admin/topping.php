<?php
// CRUD Topping. Beda dari query topping di menu.php/transaksi.php yang
// cuma nampilin status_aktif = 1 -- di sini SEMUA topping (aktif &
// nonaktif) sengaja ditampilkan, karena ini panel admin buat ngelola
// semuanya, bukan buat pelanggan milih topping.

require_once '../includes/auth_check.php';
require_once '../config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ------------------------------------------------------------
// Proses submit (4 aksi dibedain lewat field tersembunyi "action")
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Form kadaluarsa (token tidak cocok). Silakan coba lagi.';
        header('Location: topping.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'tambah') {
        $nama  = trim($_POST['nama_topping'] ?? '');
        $harga = $_POST['harga_tambahan'] ?? '';

        if ($nama === '' || mb_strlen($nama) > 50) {
            $_SESSION['flash_error'] = 'Nama topping wajib diisi (maksimal 50 karakter).';
        } elseif (!ctype_digit((string) $harga)) {
            $_SESSION['flash_error'] = 'Harga tambahan harus berupa angka positif.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO topping (nama_topping, harga_tambahan, status_aktif) VALUES (?, ?, 1)");
            $stmt->execute([$nama, (int) $harga]);
            $_SESSION['flash_success'] = "Topping \"$nama\" berhasil ditambahkan.";
        }
        header('Location: topping.php');
        exit;
    }

    if ($action === 'edit') {
        $id    = (int) ($_POST['id'] ?? 0);
        $nama  = trim($_POST['nama_topping'] ?? '');
        $harga = $_POST['harga_tambahan'] ?? '';

        if ($nama === '' || mb_strlen($nama) > 50) {
            $_SESSION['flash_error'] = 'Nama topping wajib diisi (maksimal 50 karakter).';
            header('Location: topping.php?edit=' . $id);
            exit;
        }
        if (!ctype_digit((string) $harga)) {
            $_SESSION['flash_error'] = 'Harga tambahan harus berupa angka positif.';
            header('Location: topping.php?edit=' . $id);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE topping SET nama_topping = ?, harga_tambahan = ? WHERE id = ?");
        $stmt->execute([$nama, (int) $harga, $id]);

        if ($stmt->rowCount() === 0) {
            // rowCount 0 juga bisa berarti "ketemu tapi nilainya sama persis",
            // jadi dicek ulang keberadaan barisnya sebelum dianggap gagal.
            $cek = $pdo->prepare("SELECT id FROM topping WHERE id = ?");
            $cek->execute([$id]);
            if (!$cek->fetch()) {
                $_SESSION['flash_error'] = 'Topping tidak ditemukan.';
                header('Location: topping.php');
                exit;
            }
        }

        $_SESSION['flash_success'] = "Topping \"$nama\" berhasil diperbarui. Harga baru berlaku untuk transaksi berikutnya -- nota lama tidak berubah (lihat catatan snapshot harga).";
        header('Location: topping.php');
        exit;
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $pdo->prepare("SELECT nama_topping, status_aktif FROM topping WHERE id = ?");
        $stmt->execute([$id]);
        $topping = $stmt->fetch();

        if (!$topping) {
            $_SESSION['flash_error'] = 'Topping tidak ditemukan.';
        } else {
            $statusBaru = $topping['status_aktif'] ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE topping SET status_aktif = ? WHERE id = ?");
            $stmt->execute([$statusBaru, $id]);
            $label = $statusBaru ? 'diaktifkan' : 'dinonaktifkan';
            $_SESSION['flash_success'] = "Topping \"{$topping['nama_topping']}\" berhasil $label.";
        }
        header('Location: topping.php');
        exit;
    }

    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $pdo->prepare("SELECT nama_topping FROM topping WHERE id = ?");
        $stmt->execute([$id]);
        $topping = $stmt->fetch();

        if (!$topping) {
            $_SESSION['flash_error'] = 'Topping tidak ditemukan.';
            header('Location: topping.php');
            exit;
        }

        // Dicek ulang fresh dari DB (bukan percaya angka "dipakai" yang
        // dirender di tabel), sama seperti prinsip "hitung ulang dari DB
        // saat submit" di piutang_detail.php -- mencegah delete lolos
        // gara-gara ada transaksi baru masuk di antara render halaman dan
        // klik tombol hapus.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transaksi_detail_topping WHERE id_topping = ?");
        $stmt->execute([$id]);
        $jumlahDipakai = (int) $stmt->fetchColumn();

        if ($jumlahDipakai > 0) {
            $_SESSION['flash_error'] = "Topping \"{$topping['nama_topping']}\" sudah pernah dipakai di $jumlahDipakai transaksi, tidak bisa dihapus permanen. Nonaktifkan saja supaya histori transaksi lama tetap utuh.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM topping WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['flash_success'] = "Topping \"{$topping['nama_topping']}\" berhasil dihapus permanen.";
        }
        header('Location: topping.php');
        exit;
    }

    header('Location: topping.php');
    exit;
}

$success = $_SESSION['flash_success'] ?? null;
$error   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ------------------------------------------------------------
// Data topping yang sedang diedit (kalau ada ?edit=ID di URL)
// ------------------------------------------------------------
$editTopping = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT id, nama_topping, harga_tambahan FROM topping WHERE id = ?");
    $stmt->execute([$editId]);
    $editTopping = $stmt->fetch();
    if (!$editTopping) {
        $error = 'Topping yang mau diedit tidak ditemukan.';
    }
}

// ------------------------------------------------------------
// Daftar semua topping (aktif & nonaktif) + jumlah pemakaian di
// transaksi. LEFT JOIN + GROUP BY per topping -- aman dari fan-out
// karena yang dihitung cuma COUNT baris pemakaian, bukan SUM nominal
// (sama alasannya kayak "topping terlaris" di laporan.php).
// ------------------------------------------------------------
$stmt = $pdo->query("
    SELECT tp.id, tp.nama_topping, tp.harga_tambahan, tp.status_aktif,
           COUNT(dt.id) AS jumlah_dipakai
    FROM topping tp
    LEFT JOIN transaksi_detail_topping dt ON dt.id_topping = tp.id
    GROUP BY tp.id, tp.nama_topping, tp.harga_tambahan, tp.status_aktif
    ORDER BY tp.nama_topping ASC
");
$toppings = $stmt->fetchAll();

$page_title  = 'Manajemen Topping';
$active_page = 'topping';
$extra_style = <<<CSS
  .form-row { display: flex; gap: 12px; flex-wrap: wrap; }
  .form-row > div { flex: 1; min-width: 160px; }

  .aksi-cell { display: flex; gap: 6px; flex-wrap: wrap; }
  .btn-edit { background: var(--bg-card); border: 1.5px solid var(--border); color: var(--text); }
  .btn-toggle-on { background: #EFEAE0; color: var(--text-secondary); }
  .btn-toggle-off { background: #E4EEE0; color: var(--accent-3); }
  .aksi-cell form { margin: 0; }

  .cancel-link {
    display: inline-flex; align-items: center; min-height: 44px;
    font-size: 12.5px; color: var(--text-secondary);
  }
CSS;
require_once '../includes/admin-header.php';
?>

  <?php if ($success): ?>
    <div class="success-box"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="error-box"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($editTopping): ?>
    <div class="card">
      <h2>edit topping</h2>
      <form method="POST" action="topping.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= (int) $editTopping['id'] ?>">
        <div class="form-row">
          <div>
            <label>Nama topping</label>
            <input type="text" name="nama_topping" maxlength="50" required
                   value="<?= htmlspecialchars($editTopping['nama_topping']) ?>">
          </div>
          <div>
            <label>Harga tambahan (Rp)</label>
            <input type="number" name="harga_tambahan" min="0" step="1" required
                   value="<?= (int) $editTopping['harga_tambahan'] ?>">
          </div>
        </div>
        <button type="submit" class="btn-primary">Simpan Perubahan</button>
      </form>
      <a class="cancel-link" href="topping.php">← Batal, kembali ke daftar</a>
    </div>
  <?php else: ?>
    <div class="card">
      <h2>tambah topping baru</h2>
      <form method="POST" action="topping.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="tambah">
        <div class="form-row">
          <div>
            <label>Nama topping</label>
            <input type="text" name="nama_topping" maxlength="50" required placeholder="mis. Telur, Ceker, Kerupuk">
          </div>
          <div>
            <label>Harga tambahan (Rp)</label>
            <input type="number" name="harga_tambahan" min="0" step="1" required placeholder="mis. 3000">
          </div>
        </div>
        <button type="submit" class="btn-primary">Tambah Topping</button>
      </form>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>daftar topping</h2>
    <?php if (!$toppings): ?>
      <p class="empty-note">Belum ada topping. Tambahkan lewat form di atas.</p>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead>
            <tr><th>Nama</th><th class="num">Harga</th><th>Status</th><th>Dipakai</th><th>Aksi</th></tr>
          </thead>
          <tbody>
            <?php foreach ($toppings as $t): ?>
              <?php $aktif = (int) $t['status_aktif'] === 1; $dipakai = (int) $t['jumlah_dipakai']; ?>
              <tr>
                <td><?= htmlspecialchars($t['nama_topping']) ?></td>
                <td class="num">Rp<?= number_format((int) $t['harga_tambahan'], 0, ',', '.') ?></td>
                <td><span class="badge <?= $aktif ? 'badge-aktif' : 'badge-nonaktif' ?>"><?= $aktif ? 'Aktif' : 'Nonaktif' ?></span></td>
                <td><?= $dipakai ?> kali</td>
                <td>
                  <div class="aksi-cell">
                    <a class="btn btn-edit" href="topping.php?edit=<?= (int) $t['id'] ?>">Edit</a>

                    <form method="POST" action="topping.php">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                      <button type="submit" class="<?= $aktif ? 'btn-toggle-on' : 'btn-toggle-off' ?>">
                        <?= $aktif ? 'Nonaktifkan' : 'Aktifkan' ?>
                      </button>
                    </form>

                    <?php if ($dipakai === 0): ?>
                      <form method="POST" action="topping.php" onsubmit="return confirm('Hapus permanen topping ini? Aksi tidak bisa dibatalkan.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="hapus">
                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="btn-danger">Hapus</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php require_once '../includes/admin-footer.php'; ?>

