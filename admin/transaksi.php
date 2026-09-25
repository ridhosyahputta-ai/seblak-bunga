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
    'pelanggan_mode' => 'none',
    'id_pelanggan'   => '',
    'pelanggan_nama' => '',
    'pelanggan_hp'   => '',
    'catatan'        => '',
    'item'           => [['level_pedas' => '', 'topping' => []]],
];

// ------------------------------------------------------------
// Proses submit
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Form udah kadaluarsa (token tidak cocok). Silakan submit ulang.';
    }

    $metode_bayar   = $_POST['metode_bayar'] ?? '';
    $pelanggan_mode = $_POST['pelanggan_mode'] ?? 'none';
    $catatan        = trim($_POST['catatan'] ?? '');
    $itemsRaw       = $_POST['item'] ?? [];

    // simpan balik ke $old buat repopulate form kalau gagal validasi
    $old['metode_bayar']   = $metode_bayar;
    $old['pelanggan_mode'] = $pelanggan_mode;
    $old['id_pelanggan']   = $_POST['id_pelanggan'] ?? '';
    $old['pelanggan_nama'] = trim($_POST['pelanggan_nama'] ?? '');
    $old['pelanggan_hp']   = trim($_POST['pelanggan_hp'] ?? '');
    $old['catatan']        = $catatan;
    $old['item']           = $itemsRaw ?: $old['item'];

    if (!in_array($metode_bayar, ['tunai', 'qris', 'utang'], true)) {
        $errors[] = 'Metode bayar tidak valid.';
    }

    // -- Tentuin pelanggan --
    // id_pelanggan nullable karena pembeli lewat gak perlu dicatat.
    // Wajib diisi kalau metode 'utang', biar jelas piutangnya ke siapa.
    if ($pelanggan_mode === 'baru' && $old['pelanggan_nama'] === '') {
        $errors[] = 'Nama pelanggan baru wajib diisi.';
    }
    if ($pelanggan_mode === 'existing' && (!ctype_digit((string) $old['id_pelanggan']))) {
        $errors[] = 'Pelanggan yang dipilih tidak valid.';
    }
    if ($metode_bayar === 'utang' && $pelanggan_mode === 'none') {
        $errors[] = 'Transaksi utang wajib pilih pelanggan (existing atau baru) supaya jelas piutangnya ke siapa.';
    }

    // -- Validasi & bersihin data porsi --
    // id topping yang gak ada di $toppingMap (nonaktif / hasil request
    // manual) diabaikan diam-diam.
    $cleanItems = [];
    foreach ($old['item'] as $row) {
        $level = trim($row['level_pedas'] ?? '');
        $level = ($level === '') ? null : $level;

        $toppingIds = [];
        foreach (($row['topping'] ?? []) as $tid) {
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
            $stmt = $pdo->prepare("INSERT INTO transaksi (kode_transaksi, id_pelanggan, metode_bayar, status_bayar, total_bayar, catatan) VALUES (?, ?, ?, ?, 0, ?)");
            $stmt->execute(['TEMP', $id_pelanggan, $metode_bayar, $status_bayar, $catatan !== '' ? $catatan : null]);
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

        } catch (Exception $e) {
            $pdo->rollBack();
            // Development: pesan asli ditampilin biar gampang debug (sama
            // seperti pendekatan di config/database.php). Nanti sebelum
            // dipakai beneran sebaiknya diganti pesan generik.
            $errors[] = 'Gagal menyimpan transaksi: ' . $e->getMessage();
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

      <label>Level pedas <span class="opt">(bebas, boleh kosong)</span></label>
      <input type="text" name="item[<?= $index ?>][level_pedas]" maxlength="50"
             value="<?= htmlspecialchars($data['level_pedas'] ?? '') ?>"
             placeholder="mis. sedang, extra pedas, gak pedas">

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

$page_title  = 'Input Transaksi';
$active_page = 'transaksi';
$extra_style = <<<CSS
  .sub-block { margin-top: 10px; padding: 12px; background: var(--bg); border-radius: 10px; display: none; }
  .sub-block.show { display: block; }

  .item-row { border: 1px dashed var(--border); border-radius: 12px; padding: 14px; margin-bottom: 14px; }
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
  .grand-total .gt-value { font-family: 'Anton', sans-serif; font-size: 22px; }
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
        <label><input type="radio" name="pelanggan_mode" value="none" <?= $old['pelanggan_mode'] === 'none' ? 'checked' : '' ?>> Pembeli lewat (gak dicatat)</label>
        <label><input type="radio" name="pelanggan_mode" value="existing" <?= $old['pelanggan_mode'] === 'existing' ? 'checked' : '' ?>> Pelanggan lama</label>
        <label><input type="radio" name="pelanggan_mode" value="baru" <?= $old['pelanggan_mode'] === 'baru' ? 'checked' : '' ?>> Pelanggan baru</label>
      </div>
      <p class="empty-note" style="margin:6px 0 0;">Wajib pilih pelanggan lama/baru kalau metode bayar Utang, biar jelas piutangnya ditagih ke siapa.</p>

      <div class="sub-block" id="block-existing">
        <label>Pilih pelanggan</label>
        <select name="id_pelanggan">
          <option value="">-- pilih --</option>
          <?php foreach ($pelangganList as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= (string) $old['id_pelanggan'] === (string) $p['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['nama']) ?><?= $p['no_hp'] ? ' — ' . htmlspecialchars($p['no_hp']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="sub-block" id="block-baru">
        <label>Nama pelanggan baru</label>
        <input type="text" name="pelanggan_nama" maxlength="100" value="<?= htmlspecialchars($old['pelanggan_nama']) ?>">
        <label>No. HP <span class="opt">(opsional)</span></label>
        <input type="tel" name="pelanggan_hp" maxlength="20" value="<?= htmlspecialchars($old['pelanggan_hp']) ?>">
      </div>

      <label>Catatan <span class="opt">(opsional)</span></label>
      <input type="text" name="catatan" maxlength="255" value="<?= htmlspecialchars($old['catatan']) ?>" placeholder="mis. dibungkus, dianter ke gang sebelah">
    </div>

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

    <button type="submit" class="btn-primary" style="margin-top:18px;">Simpan Transaksi</button>
  </form>

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

  // Toggle blok pelanggan lama/baru sesuai radio yang dipilih.
  function syncPelangganBlocks() {
    var mode = document.querySelector('input[name=pelanggan_mode]:checked').value;
    document.getElementById('block-existing').classList.toggle('show', mode === 'existing');
    document.getElementById('block-baru').classList.toggle('show', mode === 'baru');
  }
  document.querySelectorAll('input[name=pelanggan_mode]').forEach(function (r) {
    r.addEventListener('change', syncPelangganBlocks);
  });

  syncPelangganBlocks();
  renumber();
  calcGrandTotal();
})();
</script>
<?php require_once '../includes/admin-footer.php'; ?>

