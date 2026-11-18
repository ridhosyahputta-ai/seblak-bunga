<?php
// Input transaksi (kasir). Struktur 3 tingkat: transaksi (1 nota) ->
// transaksi_detail (1 porsi) -> transaksi_detail_topping (topping per
// porsi). transaksi_detail sengaja tanpa kolom qty -- 2 porsi identik
// tetap 2 baris terpisah.

require_once '../includes/auth_check.php';
require_once '../config/database.php';

// CSRF token per-session -- halaman ini INSERT data jadi perlu proteksi
// dari submit form luar.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ------------------------------------------------------------
// Data referensi (dipakai buat isi form DAN buat validasi ulang di server)
// ------------------------------------------------------------
$stmt = $pdo->prepare("SELECT nilai FROM pengaturan WHERE nama_pengaturan = 'harga_dasar_seblak'");
$stmt->execute();
$harga_dasar = (int) $stmt->fetchColumn();

$stmt = $pdo->query("SELECT id, nama_topping, harga_tambahan FROM topping WHERE status_aktif = 1 ORDER BY nama_topping ASC");
$toppings = $stmt->fetchAll();

// Map id => harga dari DB. Subtotal final selalu pakai ini, bukan
// data-harga di checkbox (itu cuma buat preview JS, gak dipercaya).
$toppingMap = [];
foreach ($toppings as $t) {
    $toppingMap[(int) $t['id']] = (int) $t['harga_tambahan'];
}

$stmt = $pdo->query("SELECT id, nama, no_hp FROM pelanggan ORDER BY nama ASC");
$pelangganList = $stmt->fetchAll();

$errors = [];

// Nilai buat isi ulang form -- baik pas pertama buka (default) maupun
// pas validasi gagal (biar kasir gak perlu ngetik ulang dari nol).
$old = [
    'metode_bayar'   => 'tunai',
    'pelanggan_mode' => 'baru',
    'id_pelanggan'   => '',
    'pelanggan_nama' => '',
    'pelanggan_hp'   => '',
    'item'           => [['level_pedas' => 'Level 1', 'topping' => []]],
];

// ------------------------------------------------------------
// Proses submit
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Form udah kadaluarsa (token tidak cocok). Silakan submit ulang.';
    }

    $metode_bayar   = isset($_POST['metode_bayar']) && is_string($_POST['metode_bayar']) ? $_POST['metode_bayar'] : '';
    $pelanggan_mode = isset($_POST['pelanggan_mode']) && is_string($_POST['pelanggan_mode']) ? $_POST['pelanggan_mode'] : '';
    $itemsRaw       = isset($_POST['item']) && is_array($_POST['item']) ? $_POST['item'] : [];

    // simpan balik ke $old buat repopulate form kalau gagal validasi
    $old['metode_bayar']   = $metode_bayar;
    $old['pelanggan_mode'] = $pelanggan_mode;
    $old['id_pelanggan']   = isset($_POST['id_pelanggan']) && is_string($_POST['id_pelanggan']) ? $_POST['id_pelanggan'] : '';
    $old['pelanggan_nama'] = isset($_POST['pelanggan_nama']) && is_string($_POST['pelanggan_nama']) ? trim($_POST['pelanggan_nama']) : '';
    $old['pelanggan_hp']   = isset($_POST['pelanggan_hp']) && is_string($_POST['pelanggan_hp']) ? trim($_POST['pelanggan_hp']) : '';
    $old['item']           = $itemsRaw ?: $old['item'];

    if (!in_array($metode_bayar, ['tunai', 'qris', 'utang'], true)) {
        $errors[] = 'Metode bayar tidak valid.';
    }
    if (!in_array($pelanggan_mode, ['baru', 'existing'], true)) {
        $errors[] = 'Pilihan tipe pelanggan tidak valid.';
    }

    // -- Tentuin & validasi pelanggan --
    // Utang wajib mencatat nama dan no_hp agar piutang dapat ditagih.
    if ($pelanggan_mode === 'baru') {
        if ($old['pelanggan_nama'] === '' || mb_strlen($old['pelanggan_nama']) > 100) {
            $errors[] = 'Nama pelanggan baru wajib diisi (maksimal 100 karakter).';
        }
        if ($metode_bayar === 'utang' && $old['pelanggan_hp'] === '') {
            $errors[] = 'Nomor HP pelanggan baru wajib diisi untuk transaksi utang.';
        } elseif ($old['pelanggan_hp'] !== '' && !preg_match('/^[0-9+(). -]{6,20}$/', $old['pelanggan_hp'])) {
            $errors[] = 'Nomor HP pelanggan tidak valid.';
        }
    } elseif ($pelanggan_mode === 'existing') {
        if (!ctype_digit((string) $old['id_pelanggan']) || (int) $old['id_pelanggan'] <= 0) {
            $errors[] = 'Pelanggan lama yang dipilih tidak valid.';
        } else {
            $stmt = $pdo->prepare("SELECT id, nama, no_hp FROM pelanggan WHERE id = ?");
            $stmt->execute([(int) $old['id_pelanggan']]);
            $pelangganExist = $stmt->fetch();
            if (!$pelangganExist) {
                $errors[] = 'Pelanggan yang dipilih tidak ditemukan.';
            } elseif ($metode_bayar === 'utang' && (empty($pelangganExist['no_hp']) || trim($pelangganExist['no_hp']) === '')) {
                $errors[] = 'Pelanggan lama ini belum memiliki nomor HP. Lengkapi nomor HP atau gunakan opsi Pelanggan baru agar piutang dapat ditagih.';
            }
        }
    }

    // -- Validasi & bersihin data porsi --
    // id topping yang gak ada di $toppingMap (nonaktif / hasil request
    // manual) diabaikan diam-diam.
    $cleanItems = [];
    foreach ($itemsRaw as $row) {
        if (!is_array($row)) {
            $errors[] = 'Data porsi tidak valid.';
            continue;
        }
        $level = trim((string) ($row['level_pedas'] ?? ''));
        if (in_array($level, ['1', '2', '3', '4'], true)) {
            $level = 'Level ' . $level;
        }
        if (!in_array($level, ['Level 1', 'Level 2', 'Level 3', 'Level 4'], true)) {
            $errors[] = 'Pilihan level pedas tidak valid (hanya Level 1 sampai Level 4).';
            continue;
        }

        $toppingIds = [];
        $rawToppings = isset($row['topping']) && is_array($row['topping']) ? $row['topping'] : [];
        foreach ($rawToppings as $tid) {
            $tid = (int) $tid;
            if (isset($toppingMap[$tid]) && !in_array($tid, $toppingIds, true)) {
                $toppingIds[] = $tid;
            }
        }
        $cleanItems[] = ['level_pedas' => $level, 'topping' => $toppingIds];
    }
    if (count($cleanItems) === 0) {
        $errors[] = 'Minimal harus ada 1 porsi dalam transaksi.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $id_pelanggan = null;
            if ($pelanggan_mode === 'baru') {
                $stmt = $pdo->prepare("INSERT INTO pelanggan (nama, no_hp) VALUES (?, ?)");
                $stmt->execute([$old['pelanggan_nama'], $old['pelanggan_hp'] !== '' ? $old['pelanggan_hp'] : null]);
                $id_pelanggan = (int) $pdo->lastInsertId();
            } elseif ($pelanggan_mode === 'existing') {
                $id_pelanggan = (int) $old['id_pelanggan'];
            }

            // utang = belum dibayar saat itu juga; tunai/qris = lunas di tempat.
            $status_bayar = ($metode_bayar === 'utang') ? 'belum_lunas' : 'lunas';

            // Insert pakai placeholder dulu, kode_transaksi "cantik" (TRX+tanggal
            // +id) baru di-UPDATE setelah tau id auto-increment-nya.
            $kodeSementara = 'TEMP-' . bin2hex(random_bytes(12));
            $stmt = $pdo->prepare("INSERT INTO transaksi (kode_transaksi, id_pelanggan, metode_bayar, status_bayar, total_bayar, catatan) VALUES (?, ?, ?, ?, 0, ?)");
            $stmt->execute([$kodeSementara, $id_pelanggan, $metode_bayar, $status_bayar, null]);
            $id_transaksi = (int) $pdo->lastInsertId();

            $grand_total = 0;
            foreach ($cleanItems as $item) {
                $subtotal = $harga_dasar;
                foreach ($item['topping'] as $tid) {
                    $subtotal += $toppingMap[$tid];
                }

                $stmt = $pdo->prepare("INSERT INTO transaksi_detail (id_transaksi, level_pedas, harga_dasar, subtotal) VALUES (?, ?, ?, ?)");
                $stmt->execute([$id_transaksi, $item['level_pedas'], $harga_dasar, $subtotal]);
                $id_detail = (int) $pdo->lastInsertId();

                // harga_saat_transaksi = snapshot harga topping saat itu,
                // biar nota lama gak ikut berubah kalau harga topping di-update.
                foreach ($item['topping'] as $tid) {
                    $stmt = $pdo->prepare("INSERT INTO transaksi_detail_topping (id_transaksi_detail, id_topping, harga_saat_transaksi) VALUES (?, ?, ?)");
                    $stmt->execute([$id_detail, $tid, $toppingMap[$tid]]);
                }

                $grand_total += $subtotal;
            }

            $kode_transaksi = 'TRX' . date('Ymd') . '-' . str_pad((string) $id_transaksi, 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("UPDATE transaksi SET kode_transaksi = ?, total_bayar = ? WHERE id = ?");
            $stmt->execute([$kode_transaksi, $grand_total, $id_transaksi]);

            $pdo->commit();

            // Post/Redirect/Get biar refresh browser gak nge-submit ulang.
            unset($_SESSION['csrf_token']);
            $_SESSION['flash_success'] = "Transaksi $kode_transaksi berhasil disimpan. Total Rp" . number_format($grand_total, 0, ',', '.') . ".";
            header('Location: transaksi.php');
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Gagal menyimpan transaksi: ' . $e->getMessage());
            $errors[] = 'Terjadi kesalahan pada sistem. Silakan coba lagi.';
        }
    }
}

$success = null;
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Rekap transaksi hari ini -- feedback cepat buat kasir bahwa data
// beneran kesimpen, tanpa perlu halaman laporan terpisah dulu.
$stmt = $pdo->query("
    SELECT t.kode_transaksi, t.tanggal, t.metode_bayar, t.status_bayar, t.total_bayar,
           p.nama AS nama_pelanggan
    FROM transaksi t
    LEFT JOIN pelanggan p ON p.id = t.id_pelanggan
    WHERE DATE(t.tanggal) = CURDATE()
    ORDER BY t.id DESC
");
$transaksiHariIni = $stmt->fetchAll();

// Helper render 1 blok "porsi", dipakai buat baris existing dan buat
// <template> yang di-clone JS, biar markup gak dobel-tulis.
function renderItemRow($index, array $data, array $toppings, array $selectedToppingIds): string {
    ob_start();
    ?>
    <div class="item-row" data-index="<?= htmlspecialchars((string) $index) ?>">
      <div class="item-row-head">
        <span class="item-row-title">Porsi #<span class="item-no">1</span></span>
        <button type="button" class="btn-remove-item" title="Hapus porsi ini">&times;</button>
      </div>

      <label>Level pedas</label>
      <?php
        $curLevel = $data['level_pedas'] ?? 'Level 1';
        if ($curLevel === '' || $curLevel === null) {
            $curLevel = 'Level 1';
        }
      ?>
      <select name="item[<?= $index ?>][level_pedas]">
        <?php foreach (['Level 1', 'Level 2', 'Level 3', 'Level 4'] as $lvl): ?>
          <option value="<?= $lvl ?>" <?= ($curLevel === $lvl || $curLevel === substr($lvl, -1)) ? 'selected' : '' ?>><?= $lvl ?></option>
        <?php endforeach; ?>
      </select>

      <label>Topping</label>
      <div class="topping-check-grid">
        <?php foreach ($toppings as $t): ?>
          <label class="topping-check">
            <input type="checkbox" name="item[<?= $index ?>][topping][]" value="<?= (int) $t['id'] ?>"
                   data-harga="<?= (int) $t['harga_tambahan'] ?>"
                   <?= in_array((int) $t['id'], $selectedToppingIds, true) ? 'checked' : '' ?>>
            <?= htmlspecialchars($t['nama_topping']) ?>
            <span class="tp-add">+Rp<?= number_format((int) $t['harga_tambahan'], 0, ',', '.') ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="item-subtotal">Subtotal porsi ini: Rp<span class="subtotal-value">0</span></div>
    </div>
    <?php
    return ob_get_clean();
}

$itemRowsHtml = '';
foreach ($old['item'] as $i => $itemData) {
    $selected = array_map('intval', $itemData['topping'] ?? []);
    $itemRowsHtml .= renderItemRow($i, $itemData, $toppings, $selected);
}
$templateRowHtml = renderItemRow('__INDEX__', [], $toppings, []);
$nextIndex = count($old['item']);

// Deteksi gambar QRIS Seblak Bunga (smart fallback)
$qrisCandidates = ['qris-seblak-bunga.png', 'qris-seblak-bunga.jpg', 'qris-seblak-bunga.jpeg'];
$qrisImageSrc = '../assets/img/qris-default.svg';
foreach ($qrisCandidates as $qc) {
    if (is_file(__DIR__ . '/../assets/img/' . $qc)) {
        $qrisImageSrc = '../assets/img/' . $qc;
        break;
    }
}

$page_title  = 'Input Transaksi';
$active_page = 'transaksi';
$extra_style = <<<CSS
  .sub-block { margin-top: 10px; padding: 12px; background: var(--bg); border-radius: 10px; display: none; }
  .sub-block.show { display: block; }

  .item-row { border: 1.5px dashed #C8BC9F; border-radius: 12px; padding: 14px; margin-bottom: 14px; }
  .item-row-head { display: flex; align-items: center; justify-content: space-between; }
  .item-row-title { font-weight: 600; font-size: 14px; }
  .btn-remove-item {
    background: var(--bg-card); border: 1.5px solid var(--border); border-radius: 10px;
    width: 44px; height: 44px; min-height: 44px; padding: 0; flex-shrink: 0;
    cursor: pointer; color: var(--accent); font-size: 18px; line-height: 1;
  }
  .btn-remove-item:disabled { opacity: 0.3; cursor: not-allowed; }

  .topping-check-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 8px; }
  .topping-check {
    display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 400; margin: 0;
    background: var(--bg-card); border: 1.5px solid var(--border); border-radius: 10px;
    min-height: 44px; padding: 8px 12px;
  }
  .tp-add { color: var(--text-secondary); margin-left: auto; }

  .item-subtotal { margin-top: 10px; font-size: 12.5px; color: var(--text-secondary); text-align: right; }

  .btn-add-item {
    background: transparent; border: 1.5px dashed var(--accent-3); color: var(--accent-3);
    width: 100%; margin-top: 4px;
  }

  .grand-total { display: flex; justify-content: space-between; align-items: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border); }
  .grand-total .gt-label { font-size: 13.5px; color: var(--text-secondary); }
  .grand-total .gt-value { font-family: 'Anton', sans-serif; font-size: 22px; letter-spacing: 0.04em; }

  .form-actions { display: flex; justify-content: center; margin-top: 18px; }
  .btn-simpan-transaksi {
    width: auto;
    min-height: 44px;
    padding: 12px 28px;
    font-size: 0.95rem;
  }
  @media (max-width: 480px) {
    .btn-simpan-transaksi { width: 100%; }
  }

  /* Modal Pop-up QRIS */
  .modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(43, 36, 32, 0.65);
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
    z-index: 1000;
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
  }
  .modal-card {
    background: var(--bg-card);
    border: 1.5px solid #C8BC9F;
    border-radius: 18px;
    width: 100%; max-width: 420px;
    max-height: 90vh; overflow-y: auto;
    padding: 22px;
    box-shadow: 0 16px 40px rgba(43, 36, 32, 0.22);
    animation: modalPopIn 0.2s ease-out;
  }
  @keyframes modalPopIn {
    from { opacity: 0; transform: scale(0.96) translateY(8px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
  }
  .modal-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--border);
  }
  .modal-title {
    font-family: 'Anton', Impact, system-ui, sans-serif;
    font-size: 18px; margin: 0; color: var(--text);
    letter-spacing: 0.04em;
    text-transform: capitalize;
  }
  .modal-close {
    background: transparent; border: none; font-size: 26px; line-height: 1;
    color: var(--text-secondary); cursor: pointer; padding: 0 4px;
  }
  .modal-close:hover { color: var(--accent); }
  .qris-summary {
    background: var(--bg); border: 1.5px solid #C8BC9F;
    border-radius: 12px; padding: 12px 14px; margin-bottom: 14px;
  }
  .qris-customer {
    font-size: 13px; color: var(--text-secondary); margin-bottom: 4px;
  }
  .qris-customer strong { color: var(--text); font-weight: 600; }
  .qris-total-box {
    display: flex; justify-content: space-between; align-items: baseline;
  }
  .qris-total-label { font-size: 13.5px; font-weight: 600; color: var(--text); }
  .qris-total-value {
    font-family: 'Anton', Impact, system-ui, sans-serif;
    font-size: 22px; color: var(--accent);
    letter-spacing: 0.04em;
  }
  .qris-image-wrap {
    background: #FFFFFF; border: 1.5px solid #C8BC9F;
    border-radius: 14px; padding: 14px; text-align: center;
    margin-bottom: 12px; box-shadow: 0 4px 12px rgba(43, 36, 32, 0.05);
  }
  .qris-qr-img {
    width: 220px; max-width: 100%; height: auto; display: inline-block;
  }
  .qris-instruction {
    font-size: 12.5px; color: var(--text-secondary); text-align: center;
    margin: 0 0 18px; line-height: 1.45;
  }
  .modal-footer {
    display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;
  }
  .modal-footer .btn {
    min-height: 42px; padding: 10px 20px; font-size: 13.5px; width: auto;
  }
  .modal-footer .btn-primary { width: auto; }
  @media (max-width: 480px) {
    .modal-card { padding: 18px; }
    .modal-footer { flex-direction: column-reverse; }
    .modal-footer .btn { width: 100%; }
    .qris-qr-img { width: 190px; }
  }
CSS;
require_once '../includes/admin-header.php';
?>

  <?php if ($success): ?>
    <div class="success-box"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="error-box">
      <ul>
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="POST" action="transaksi.php" id="form-transaksi">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

    <div class="card">
      <h2>porsi seblak</h2>
      <p class="empty-note" style="margin:0 0 10px;">Harga dasar saat ini: <strong>Rp<?= number_format($harga_dasar, 0, ',', '.') ?></strong> / porsi.</p>

      <div id="items-container"><?= $itemRowsHtml ?></div>

      <button type="button" class="btn-add-item" id="btn-add-item">+ Tambah Porsi</button>

      <div class="grand-total">
        <span class="gt-label">Total keseluruhan</span>
        <span class="gt-value">Rp<span id="grand-total-value">0</span></span>
      </div>
    </div>

    <div class="card">
      <h2>pembayaran</h2>

      <label>Metode bayar</label>
      <div class="radio-group">
        <?php foreach (['tunai' => 'Tunai', 'qris' => 'QRIS', 'utang' => 'Utang'] as $val => $lbl): ?>
          <label>
            <input type="radio" name="metode_bayar" value="<?= $val ?>" <?= $old['metode_bayar'] === $val ? 'checked' : '' ?>>
            <?= $lbl ?>
          </label>
        <?php endforeach; ?>
      </div>

      <label style="margin-top:16px;">Pelanggan</label>
      <div class="radio-group">
        <label><input type="radio" name="pelanggan_mode" value="baru" <?= $old['pelanggan_mode'] === 'baru' ? 'checked' : '' ?>> Pelanggan baru</label>
        <label><input type="radio" name="pelanggan_mode" value="existing" <?= $old['pelanggan_mode'] === 'existing' ? 'checked' : '' ?>> Pelanggan lama</label>
      </div>

      <div class="sub-block" id="block-baru">
        <label>Nama pelanggan baru</label>
        <input type="text" name="pelanggan_nama" maxlength="100" placeholder="mis. Teh Dewi, Kang Asep" value="<?= htmlspecialchars($old['pelanggan_nama']) ?>">
        <label>No. HP <span class="opt" id="label-hp-opt">(opsional)</span></label>
        <input type="tel" name="pelanggan_hp" maxlength="20" placeholder="mis. 081234567890" value="<?= htmlspecialchars($old['pelanggan_hp']) ?>">
      </div>

      <div class="sub-block" id="block-existing">
        <label>Pilih pelanggan lama</label>
        <select name="id_pelanggan">
          <option value="">-- pilih pelanggan --</option>
          <?php foreach ($pelangganList as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= (string) $old['id_pelanggan'] === (string) $p['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['nama']) ?><?= $p['no_hp'] ? ' — ' . htmlspecialchars($p['no_hp']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn-primary btn-simpan-transaksi">Simpan Transaksi</button>
    </div>
  </form>

  <!-- Modal Pop-up QRIS -->
  <div id="qris-modal" class="modal-overlay" style="display:none;" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="qris-modal-title">
      <div class="modal-header">
        <h3 id="qris-modal-title" class="modal-title">Pembayaran QRIS — Seblak Bunga</h3>
        <button type="button" class="modal-close" id="btn-close-qris" aria-label="Tutup modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="qris-summary">
          <div class="qris-customer">Pelanggan: <strong id="qris-pelanggan-name">Pelanggan Umum</strong></div>
          <div class="qris-total-box">
            <span class="qris-total-label">Total Tagihan</span>
            <span class="qris-total-value">Rp<span id="qris-modal-total">0</span></span>
          </div>
        </div>
        <div class="qris-image-wrap">
          <img src="<?= htmlspecialchars($qrisImageSrc) ?>" alt="QRIS Seblak Bunga" class="qris-qr-img">
        </div>
        <p class="qris-instruction">Silakan minta pembeli memindai kode QRIS di atas, lalu klik konfirmasi setelah pembayaran masuk.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="btn-batal-qris">Batal</button>
        <button type="button" class="btn btn-primary" id="btn-confirm-qris">Konfirmasi Pembayaran &amp; Simpan</button>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>transaksi hari ini</h2>
    <?php if (!$transaksiHariIni): ?>
      <p class="empty-note">Belum ada transaksi hari ini.</p>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead>
            <tr><th>Kode</th><th>Jam</th><th>Pelanggan</th><th>Bayar</th><th>Status</th><th class="num">Total</th></tr>
          </thead>
          <tbody>
            <?php foreach ($transaksiHariIni as $t): ?>
              <tr>
                <td><?= htmlspecialchars($t['kode_transaksi']) ?></td>
                <td><?= date('H:i', strtotime($t['tanggal'])) ?></td>
                <td><?= $t['nama_pelanggan'] ? htmlspecialchars($t['nama_pelanggan']) : '—' ?></td>
                <td><?= htmlspecialchars(ucfirst($t['metode_bayar'])) ?></td>
                <td><span class="badge badge-<?= $t['status_bayar'] ?>"><?= $t['status_bayar'] === 'lunas' ? 'Lunas' : 'Belum Lunas' ?></span></td>
                <td class="num">Rp<?= number_format($t['total_bayar'], 0, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<!-- Template row buat di-clone JS. Dibungkus <template> biar browser gak
     nge-render/eksekusi isinya sebelum di-clone secara eksplisit. -->
<template id="item-template"><?= $templateRowHtml ?></template>

<script>
(function () {
  var HARGA_DASAR = <?= (int) $harga_dasar ?>;
  var container   = document.getElementById('items-container');
  var addBtn      = document.getElementById('btn-add-item');
  var template    = document.getElementById('item-template');
  var counter     = <?= (int) $nextIndex ?>;

  function renumber() {
    var rows = container.querySelectorAll('.item-row');
    rows.forEach(function (row, i) {
      row.querySelector('.item-no').textContent = i + 1;
    });
    container.querySelectorAll('.btn-remove-item').forEach(function (btn) {
      btn.disabled = rows.length <= 1;
    });
  }

  function calcRowSubtotal(row) {
    var total = HARGA_DASAR;
    row.querySelectorAll('input[type=checkbox]:checked').forEach(function (cb) {
      total += parseInt(cb.dataset.harga, 10) || 0;
    });
    row.querySelector('.subtotal-value').textContent = total.toLocaleString('id-ID');
    return total;
  }

  function calcGrandTotal() {
    var grand = 0;
    container.querySelectorAll('.item-row').forEach(function (row) {
      grand += calcRowSubtotal(row);
    });
    document.getElementById('grand-total-value').textContent = grand.toLocaleString('id-ID');
  }

  addBtn.addEventListener('click', function () {
    var html = template.innerHTML.replace(/__INDEX__/g, counter);
    var wrapper = document.createElement('div');
    wrapper.innerHTML = html.trim();
    container.appendChild(wrapper.firstElementChild);
    counter++;
    renumber();
    calcGrandTotal();
  });

  container.addEventListener('click', function (e) {
    if (e.target.classList.contains('btn-remove-item')) {
      if (container.querySelectorAll('.item-row').length > 1) {
        e.target.closest('.item-row').remove();
        renumber();
        calcGrandTotal();
      }
    }
  });

  container.addEventListener('change', function (e) {
    if (e.target.type === 'checkbox') calcGrandTotal();
  });

  // Toggle blok pelanggan baru/lama sesuai radio yang dipilih.
  function syncPelangganBlocks() {
    var modeEl = document.querySelector('input[name=pelanggan_mode]:checked');
    var mode = modeEl ? modeEl.value : 'baru';
    document.getElementById('block-baru').classList.toggle('show', mode === 'baru');
    document.getElementById('block-existing').classList.toggle('show', mode === 'existing');
  }
  document.querySelectorAll('input[name=pelanggan_mode]').forEach(function (r) {
    r.addEventListener('change', syncPelangganBlocks);
  });

  // Metode bayar Utang: sesuaikan keterangan No. HP (wajib jika Utang).
  function syncMetodeBayar() {
    var metodeEl = document.querySelector('input[name=metode_bayar]:checked');
    var utang = (metodeEl ? metodeEl.value : '') === 'utang';
    var hpOpt = document.getElementById('label-hp-opt');
    if (hpOpt) {
      hpOpt.textContent = utang ? '(wajib jika Utang)' : '(opsional)';
    }
  }
  document.querySelectorAll('input[name=metode_bayar]').forEach(function (r) {
    r.addEventListener('change', syncMetodeBayar);
  });

  syncPelangganBlocks();
  syncMetodeBayar();

  // Modal Pop-up QRIS Logic
  var qrisConfirmed = false;
  var form          = document.getElementById('form-transaksi');
  var qrisModal     = document.getElementById('qris-modal');

  function getCustomerDisplayName() {
    var modeEl = document.querySelector('input[name=pelanggan_mode]:checked');
    var mode = modeEl ? modeEl.value : 'baru';
    if (mode === 'baru') {
      var namaEl = document.querySelector('input[name=pelanggan_nama]');
      var nama = namaEl ? namaEl.value.trim() : '';
      return nama || 'Pelanggan Baru';
    } else if (mode === 'existing') {
      var sel = document.querySelector('select[name=id_pelanggan]');
      if (sel && sel.selectedIndex >= 0 && sel.value !== '') {
        var optText = sel.options[sel.selectedIndex].text;
        return optText.split('—')[0].trim() || 'Pelanggan Lama';
      }
      return 'Pelanggan Lama';
    }
    return 'Pelanggan Baru';
  }

  function openQrisModal() {
    qrisModal.style.display = 'flex';
    qrisModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeQrisModal() {
    qrisModal.style.display = 'none';
    qrisModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  form.addEventListener('submit', function (e) {
    var metodeEl = document.querySelector('input[name=metode_bayar]:checked');
    var metode = metodeEl ? metodeEl.value : 'tunai';

    if (metode === 'qris' && !qrisConfirmed) {
      if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
        return;
      }
      e.preventDefault();
      document.getElementById('qris-pelanggan-name').textContent = getCustomerDisplayName();
      document.getElementById('qris-modal-total').textContent = document.getElementById('grand-total-value').textContent;
      openQrisModal();
    }
  });

  document.getElementById('btn-confirm-qris').addEventListener('click', function () {
    qrisConfirmed = true;
    closeQrisModal();
    form.submit();
  });

  document.getElementById('btn-close-qris').addEventListener('click', closeQrisModal);
  document.getElementById('btn-batal-qris').addEventListener('click', closeQrisModal);

  qrisModal.addEventListener('click', function (e) {
    if (e.target === this) {
      closeQrisModal();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.key === 'Esc') {
      if (qrisModal && qrisModal.style.display === 'flex') {
        closeQrisModal();
      }
    }
  });

  renumber();
  calcGrandTotal();
})();
</script>
<?php require_once '../includes/admin-footer.php'; ?>

