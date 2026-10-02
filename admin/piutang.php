<?php
require_once '../includes/auth_check.php';
require_once '../config/database.php';

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($q) > 100) {
    $q = mb_substr($q, 0, 100);
}

$searchSql = '';
$params = [];
if ($q !== '') {
    $searchSql = "
        AND (
            p.nama LIKE ?
            OR COALESCE(p.no_hp, '') LIKE ?
            OR EXISTS (
                SELECT 1
                FROM transaksi tx
                WHERE tx.id_pelanggan = p.id
                  AND tx.metode_bayar = 'utang'
                  AND tx.status_bayar = 'belum_lunas'
                  AND tx.kode_transaksi LIKE ?
            )
        )
    ";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}

// Pembayaran diagregasi lebih dulu agar nominal transaksi tidak berlipat.
$stmt = $pdo->prepare("
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
    WHERE 1 = 1
    $searchSql
    GROUP BY p.id, p.nama, p.no_hp
    ORDER BY sisa_utang DESC
");
$stmt->execute($params);
$daftarPiutang = $stmt->fetchAll();

$totalSemuaPiutang = 0;
foreach ($daftarPiutang as $row) {
    $totalSemuaPiutang += (int) $row['sisa_utang'];
}

$page_title = 'Manajemen Piutang';
$active_page = 'piutang';
$extra_style = <<<'CSS'
  .summary-total { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
  .summary-total .st-value { font-family:'Anton',sans-serif; font-size:24px; color:var(--accent); }
  .search-form { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
  .search-form input { flex:1; min-width:200px; }
  .search-form button { background:var(--accent-3); color:#FFF8EF; }
  .reset-link {
    display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:0 16px;
    border:1.5px solid var(--border); border-radius:100px; color:var(--text); text-decoration:none;
  }
  a.detail-link {
    display:inline-flex; align-items:center; min-height:44px; padding:0 14px;
    color:var(--accent); text-decoration:none; font-weight:500; font-size:12.5px;
  }
  @media (max-width:520px) {
    .search-form input { width:100%; }
    .search-form button, .search-form .reset-link { flex:1; }
  }
CSS;
require_once '../includes/admin-header.php';
?>

  <div class="card">
    <div class="summary-total">
      <div>
        <h2><?= $q === '' ? 'total piutang berjalan' : 'hasil pencarian piutang' ?></h2>
        <p class="empty-note" style="margin:0;"><?= count($daftarPiutang) ?> pelanggan<?= $q !== '' ? ' cocok dengan “' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '”' : ' masih punya utang' ?>.</p>
      </div>
      <span class="st-value">Rp<?= number_format($totalSemuaPiutang, 0, ',', '.') ?></span>
    </div>
  </div>

  <div class="card">
    <h2 style="margin-bottom:14px;">daftar pelanggan berutang</h2>
    <form class="search-form" method="GET" action="piutang.php">
      <input type="text" name="q" maxlength="100" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Cari nama, nomor HP, atau kode transaksi">
      <button type="submit">Cari / Terapkan</button>
      <a class="reset-link" href="piutang.php">Reset</a>
    </form>

    <?php if (!$daftarPiutang): ?>
      <p class="empty-note"><?= $q === '' ? 'Tidak ada piutang yang masih berjalan saat ini.' : 'Tidak ada pelanggan atau transaksi piutang yang cocok.' ?></p>
    <?php else: ?>
      <div class="table-scroll"><table>
        <thead><tr><th>Nama</th><th>No. HP</th><th>Utang Tertua</th><th>Transaksi</th><th class="num">Sisa Utang</th><th></th></tr></thead>
        <tbody><?php foreach ($daftarPiutang as $row): ?><tr>
          <td><?= htmlspecialchars($row['nama'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= $row['no_hp'] ? htmlspecialchars($row['no_hp'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
          <td><?= date('d M Y', strtotime($row['tanggal_tertua'])) ?></td>
          <td><?= (int) $row['jumlah_transaksi'] ?> nota</td>
          <td class="num">Rp<?= number_format((int) $row['sisa_utang'], 0, ',', '.') ?></td>
          <td><a class="detail-link" href="piutang_detail.php?id_pelanggan=<?= (int) $row['id'] ?>">Detail →</a></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php require_once '../includes/admin-footer.php'; ?>