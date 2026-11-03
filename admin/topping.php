<?php
// CRUD Topping. Beda dari query topping di menu.php/transaksi.php yang
// cuma nampilin status_aktif = 1 -- di sini SEMUA topping (aktif &
// nonaktif) sengaja ditampilkan, karena ini panel admin buat ngelola
// semuanya, bukan buat pelanggan milih topping.

require_once '../includes/auth_check.php';
require_once '../config/database.php';

const FOTO_DIR = __DIR__ . '/../assets/img/topping/';
const FOTO_MAX_BYTES = 2 * 1024 * 1024;

// Proses upload foto topping (field "foto_upload"). Return [namaFile|null, error|null].
// namaFile null + error null = tidak ada file yang diunggah.
function prosesUploadFoto(string $namaTopping): array {
    $f = $_FILES['foto_upload'] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'Ukuran foto melebihi batas (maksimal 2MB).'];
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return [null, 'Upload foto gagal. Silakan coba lagi.'];
    }
    if ($f['size'] > FOTO_MAX_BYTES) {
        return [null, 'Ukuran foto melebihi batas (maksimal 2MB).'];
    }

    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    $mimeExt = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($mimeExt[$mime]) || !in_array($ext, $mimeExt[$mime], true) || @getimagesize($f['tmp_name']) === false) {
        return [null, 'File harus berupa gambar JPG, PNG, atau WEBP yang valid.'];
    }

    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($namaTopping)), '-');
    $slug = substr($slug !== '' ? $slug : 'topping', 0, 30);
    $namaFile = 'topping_' . $slug . '_' . time() . '_' . bin2hex(random_bytes(2)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);

    if (!is_dir(FOTO_DIR) && !mkdir(FOTO_DIR, 0755, true)) {
        return [null, 'Folder foto tidak bisa dibuat.'];
    }
    if (!move_uploaded_file($f['tmp_name'], FOTO_DIR . $namaFile)) {
        return [null, 'Foto gagal disimpan ke folder assets/img/topping/.'];
    }
    return [$namaFile, null];
}

// Hapus file foto lama hanya kalau hasil upload (prefix topping_); file manual dibiarkan.
function hapusFotoLama(?string $nama): void {
    $nama = basename((string) $nama);
    if (strpos($nama, 'topping_') === 0 && is_file(FOTO_DIR . $nama)) {
        @unlink(FOTO_DIR . $nama);
    }
}
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
        $nama  = isset($_POST['nama_topping']) && is_string($_POST['nama_topping']) ? trim($_POST['nama_topping']) : '';
        $harga = isset($_POST['harga_tambahan']) && is_string($_POST['harga_tambahan']) ? $_POST['harga_tambahan'] : '';

        if ($nama === '' || mb_strlen($nama) > 50) {
            $_SESSION['flash_error'] = 'Nama topping wajib diisi (maksimal 50 karakter).';
        } elseif (!ctype_digit((string) $harga)) {
            $_SESSION['flash_error'] = 'Harga tambahan harus berupa angka nol atau positif.';
        } else {
            [$foto, $fotoErr] = prosesUploadFoto($nama);
            if ($fotoErr) {
                $_SESSION['flash_error'] = $fotoErr;
                header('Location: topping.php');
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO topping (nama_topping, harga_tambahan, foto, status_aktif) VALUES (?, ?, ?, 1)");
            $stmt->execute([$nama, (int) $harga, $foto]);
            $_SESSION['flash_success'] = "Topping \"$nama\" berhasil ditambahkan.";
        }
        header('Location: topping.php');
        exit;
    }

    if ($action === 'edit') {
        $id    = (int) ($_POST['id'] ?? 0);
        $nama  = isset($_POST['nama_topping']) && is_string($_POST['nama_topping']) ? trim($_POST['nama_topping']) : '';
        $harga = isset($_POST['harga_tambahan']) && is_string($_POST['harga_tambahan']) ? $_POST['harga_tambahan'] : '';

        if ($nama === '' || mb_strlen($nama) > 50) {
            $_SESSION['flash_error'] = 'Nama topping wajib diisi (maksimal 50 karakter).';
            header('Location: topping.php?edit=' . $id);
            exit;
        }
        if (!ctype_digit((string) $harga)) {
            $_SESSION['flash_error'] = 'Harga tambahan harus berupa angka nol atau positif.';
            header('Location: topping.php?edit=' . $id);
            exit;
        }
        $cek = $pdo->prepare("SELECT foto FROM topping WHERE id = ?");
        $cek->execute([$id]);
        $lama = $cek->fetch();
        if (!$lama) {
            $_SESSION['flash_error'] = 'Topping tidak ditemukan.';
            header('Location: topping.php');
            exit;
        }
        $fotoLama = $lama['foto'];

        // Tanpa file baru & tanpa centang hapus: foto lama dipertahankan.
        [$fotoBaru, $fotoErr] = prosesUploadFoto($nama);
        if ($fotoErr) {
            $_SESSION['flash_error'] = $fotoErr;
            header('Location: topping.php?edit=' . $id);
            exit;
        }

        if ($fotoBaru !== null) {
            $foto = $fotoBaru;
        } elseif (!empty($_POST['hapus_foto'])) {
            $foto = null;
        } else {
            $foto = $fotoLama;
        }

        $stmt = $pdo->prepare("UPDATE topping SET nama_topping = ?, harga_tambahan = ?, foto = ? WHERE id = ?");
        $stmt->execute([$nama, (int) $harga, $foto, $id]);
        if ($foto !== $fotoLama) {
            hapusFotoLama($fotoLama);
        }

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
    $stmt = $pdo->prepare("SELECT id, nama_topping, harga_tambahan, foto FROM topping WHERE id = ?");
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
    SELECT tp.id, tp.nama_topping, tp.harga_tambahan, tp.foto, tp.status_aktif,
           COUNT(dt.id) AS jumlah_dipakai
    FROM topping tp
    LEFT JOIN transaksi_detail_topping dt ON dt.id_topping = tp.id
    GROUP BY tp.id, tp.nama_topping, tp.harga_tambahan, tp.foto, tp.status_aktif
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
  .thumb-topping { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; display: block; }

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
      <form method="POST" action="topping.php" enctype="multipart/form-data">
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
          <div>
            <label>Foto (opsional, JPG/PNG/WEBP, maks 2MB)</label>
            <?php $ef = basename((string) ($editTopping['foto'] ?? '')); $efAda = $ef !== '' && is_file(FOTO_DIR . $ef); ?>
            <?php if ($efAda): ?>
              <img class="thumb-topping" src="../assets/img/topping/<?= htmlspecialchars(rawurlencode($ef)) ?>" alt="Foto saat ini" style="margin-bottom:8px">
            <?php endif; ?>
            <input type="file" name="foto_upload" accept="image/png, image/jpeg, image/jpg, image/webp">
            <?php if ($efAda): ?>
              <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin-top:8px">
                <input type="checkbox" name="hapus_foto" value="1"> Hapus foto (kembali ke default)
              </label>
            <?php endif; ?>
          </div>
        </div>
        <button type="submit" class="btn-primary">Simpan Perubahan</button>
      </form>
      <a class="cancel-link" href="topping.php">← Batal, kembali ke daftar</a>
    </div>
  <?php else: ?>
    <div class="card">
      <h2>tambah topping baru</h2>
      <form method="POST" action="topping.php" enctype="multipart/form-data">
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
          <div>
            <label>Foto (opsional, JPG/PNG/WEBP, maks 2MB)</label>
            <input type="file" name="foto_upload" accept="image/png, image/jpeg, image/jpg, image/webp">
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
            <tr><th>Foto</th><th>Nama</th><th class="num">Harga</th><th>Status</th><th>Dipakai</th><th>Aksi</th></tr>
          </thead>
          <tbody>
            <?php foreach ($toppings as $t): ?>
              <?php $aktif = (int) $t['status_aktif'] === 1; $dipakai = (int) $t['jumlah_dipakai']; ?>
              <?php
                $fotoFile = basename((string) ($t['foto'] ?? ''));
                $fotoAda  = $fotoFile !== '' && is_file(__DIR__ . '/../assets/img/topping/' . $fotoFile);
                $fotoSrc  = '../assets/img/topping/' . ($fotoAda ? rawurlencode($fotoFile) : 'default.svg');
              ?>
              <tr>
                <td><img class="thumb-topping" src="<?= htmlspecialchars($fotoSrc) ?>" alt="" loading="lazy"></td>
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

