# Catatan Teknis

Dokumen ini menyimpan penjelasan/reasoning teknis yang tadinya ditulis
sebagai komentar panjang di dalam kode, dipindah ke sini supaya kode
tetap ringkas tapi alasan di balik keputusan desainnya tidak hilang.
Berguna buat persiapan sidang saat ditanya "kenapa dibikin begini".

Urutan section di bawah mengikuti urutan menu navigasi admin (Input
Transaksi → Piutang → Laporan → Topping), supaya gampang dicari sambil
buka halaman yang bersangkutan.

## config/database.php — Koneksi Database

Koneksi database dipisah jadi file sendiri (bukan ditulis ulang di
tiap halaman) supaya kalau nanti pindah hosting / ganti password
database, cukup ubah 1 file ini aja — semua halaman yang `require`
file ini otomatis ikut kepake koneksi yang baru.

Pakai PDO (bukan mysqli) karena PDO mendukung prepared statement
dengan gampang, yang penting buat mencegah SQL Injection — khususnya
di form input transaksi di panel admin yang menerima input dari user.

## admin/transaksi.php — Input Transaksi (Kasir)

**Struktur tabel 3 tingkat:** `transaksi` (1 baris = 1 nota) →
`transaksi_detail` (1 baris = 1 porsi seblak dalam nota itu) →
`transaksi_detail_topping` (1 baris = 1 topping yang dipasang di 1
porsi). Kenapa gak digabung jadi 1 tabel aja? Karena 1 nota bisa
berisi beberapa porsi dengan level pedas & topping yang beda-beda
(misal: 1 porsi extra pedas + telur, 1 porsi biasa tanpa topping), dan
1 porsi bisa punya lebih dari 1 topping sekaligus. Itu hubungan
one-to-many-to-many, jadi butuh tabel detail + tabel junction, bukan
cuma nyimpan id_topping tunggal di `transaksi_detail`.

**Kenapa tidak ada kolom qty:** tabel `transaksi_detail` sengaja tidak
punya kolom qty/jumlah. Itu bukan kelupaan — artinya 1 baris detail =
1 porsi fisik. Kalau pembeli beli 2 porsi extra pedas + telur yang
identik, itu tetap jadi 2 baris `transaksi_detail` terpisah (lewat
tombol "Tambah Porsi" di form), bukan 1 baris dengan qty=2.
Konsekuensinya kalau nanti mau nambah kolom qty, itu perubahan skema,
bukan cuma perubahan form.

**CSRF token:** halaman ini mengubah data (INSERT), jadi perlu token
per-session biar form gak bisa di-submit dari luar (misal link/HTML
jahat di halaman lain yang auto-submit ke sini). Simple token dipakai
dibanding library karena cuma dipakai di form admin ini.

**Kenapa harga topping selalu dilookup ulang dari `$toppingMap`:** ini
kunci dari sisi keamanan — harga topping yang dipakai untuk hitung
subtotal selalu diambil dari data server (hasil query barusan), bukan
dari nilai yang dikirim form/JS. `data-harga` di checkbox itu cuma
untuk preview total di layar kasir; kalau ada yang iseng ubah lewat
DevTools atau kirim request manual, hitungan final tetap pakai harga
asli dari database.

**Kenapa id_pelanggan nullable:** `id_pelanggan` di tabel `transaksi`
sengaja nullable — pembeli lewat (jajan sekali, bayar tunai/QRIS) gak
perlu dicatat identitasnya. Pelanggan baru dicatat kalau memang perlu
ditelusuri lagi nanti — yang wajib itu transaksi metode 'utang',
karena Teh Dewi perlu tau piutang itu punya siapa untuk ditagih (lihat
tabel `pembayaran_piutang`).

**Validasi topping saat submit:** id topping yang gak ada di
`$toppingMap` (misal id topping yang sudah dinonaktifkan, atau id
ngasal hasil request manual) diabaikan diam-diam.

**Kenapa kode_transaksi di-generate belakangan:** `kode_transaksi`
NOT NULL + UNIQUE, tapi kode "cantik" (TRX + tanggal + nomor urut)
baru bisa dibikin setelah tau id auto-increment-nya. Makanya insert
dulu pakai placeholder, baru di-UPDATE belakangan dalam transaction
yang sama. Ini lebih aman dibanding coba tebak nomor urut lewat
`SELECT COUNT(*)+1`, karena AUTO_INCREMENT dijamin unik oleh MySQL
sendiri walau ada beberapa transaksi masuk hampir bersamaan — gak
perlu logic locking manual.

**Kenapa harga_saat_transaksi disimpan sebagai snapshot:**
`harga_saat_transaksi` = snapshot harga topping saat itu. Ini yang
bikin laporan lama tetap akurat walau nanti harga topping di tabel
`topping` berubah — kalau kita JOIN ke tabel topping tiap menampilkan
nota lama, nota bulan lalu bisa ikut berubah angkanya padahal itu
sudah closed.

**Pattern Post/Redirect/Get:** setelah sukses simpan, redirect ke GET
supaya kalau kasir refresh browser gak nge-submit ulang transaksi yang
sama dua kali. Token CSRF lama dibuang biar form POST yang sama gak
bisa dipakai ulang.

## admin/piutang.php — Manajemen Piutang

**Definisi "punya piutang":** minimal 1 transaksi dengan
`metode_bayar = 'utang'` DAN `status_bayar = 'belum_lunas'`. Begitu
semua transaksi utang seorang pelanggan lunas, dia otomatis hilang
dari daftar ini — tidak perlu logic terpisah untuk "arsipkan pelanggan
yang piutangnya sudah kelar", itu konsekuensi alami dari filter
`status_bayar`. Riwayat transaksi yang sudah lunas tetap bisa dilihat
lewat piutang_detail.php.

**Kenapa subquery agregasi sebelum JOIN (fan-out):** jumlah pembayaran
di-agregasi dulu di subquery (`byr`) sebelum di-JOIN ke transaksi,
bukan langsung JOIN transaksi ke `pembayaran_piutang` mentah-mentah.
Kalau 1 transaksi sudah dicicil 3 kali, JOIN langsung bakal bikin
baris transaksi itu muncul 3 kali (fan-out), dan `SUM(t.total_bayar)`
di GROUP BY per pelanggan jadi kelipatan yang salah. Dengan agregasi
dulu per `id_transaksi`, hasil JOIN-nya tetap 1 baris per transaksi,
baru aman di-SUM lagi per pelanggan di query luar.

## admin/piutang_detail.php — Detail Piutang & Input Cicilan

**Kenapa pembayaran dicatat per-transaksi, bukan saldo global per
pelanggan:** satu pelanggan langganan bisa punya beberapa nota utang
terpisah dari waktu ke waktu (utang tanggal 1, belum lunas, lalu utang
lagi tanggal 10). Kalau pembayaran cuma dicatat sebagai "pelanggan A
bayar Rp5rb" tanpa terikat ke nota mana, kita gak akan tau nota yang
mana yang jadi lunas — gak bisa nentuin `transaksi.status_bayar` yang
mana yang harus diubah. Dengan mengikat tiap cicilan ke satu
`id_transaksi` yang jelas, tiap nota punya riwayat pembayarannya
sendiri, persis kayak bayar cicilan ke invoice tertentu, bukan
nyumbang ke "kas pelanggan" yang gak jelas asal-usulnya.

**Kenapa sisa utang dihitung ulang dari DB saat submit:** transaksi
target + total yang sudah dibayar diambil langsung dari DB saat submit
itu juga — jangan percaya "sisa utang" yang mungkin ditampilkan di
form (bisa aja itu render dari beberapa menit lalu, atau kasir buka 2
tab sekaligus). Sisa utang harus selalu dihitung fresh sebelum
divalidasi, biar gak ada celah bikin sisa jadi minus.

**Kenapa status_bayar tidak pernah di-toggle manual:** `status_bayar`
tidak pernah di-set manual dari form (tidak ada checkbox "tandai
lunas" di UI). Statusnya selalu diturunkan ulang dari jumlah
pembayaran yang benar-benar tercatat — dihitung ulang dari DB (bukan
cuma total_dibayar_lama + jumlah_bayar di PHP) supaya angkanya pasti
sinkron dengan baris yang barusan di-insert. Kalau lunas boleh
di-toggle manual, gampang kejadian kasir lupa update, atau nge-klik
padahal cicilannya belum benar-benar menutupi total_bayar — data
piutang jadi gak bisa dipercaya lagi.

**Kenapa riwayat cicilan diambil di query terpisah:** riwayat cicilan
per transaksi diambil di query terpisah (bukan JOIN langsung ke query
utama) supaya `SUM(pp.jumlah_bayar)` di query utama gak kena fan-out
kalau 1 transaksi punya beberapa baris cicilan. Hasilnya dikelompokkan
per `id_transaksi` di PHP.

## admin/laporan.php — Laporan Penjualan

**Kenapa preset periode dihitung ulang tiap request:** default (tidak
ada filter sama sekali) adalah hari ini. Preset "Minggu ini"/"Bulan
ini" dihitung ulang dari tanggal hari ini setiap request, bukan
disimpan — jadi kalau kasir buka "Minggu ini" pas hari Senin, lalu
balik lagi hari Rabu, rentangnya otomatis ikut maju tanpa perlu action
apa-apa.

**Validasi filter custom:** format tanggal dari user divalidasi
sebelum dipakai ke query — kalau formatnya rusak/kosong, fallback ke
hari ini daripada mengirim string sembarangan ke
`WHERE DATE(...) BETWEEN`. Kalau user kebalik isi form (dari >
sampai), posisinya ditukar biar query BETWEEN tetap masuk akal, bukan
malah kosong.

**Kenapa Total Omzet dan Kas Diterima adalah dua angka yang berbeda
(konsep accrual vs cash basis):**

- **Total Omzet** menjumlahkan SEMUA transaksi yang terbentuk di
  periode ini, apapun metode & status bayarnya — termasuk transaksi
  utang yang belum ada uangnya masuk sama sekali. Angka ini menjawab
  "berapa nilai seblak yang laku terjual periode ini", bukan "berapa
  uang di tangan sekarang". Ini accrual basis: mencatat nilai
  penjualan begitu terjadi, meski uangnya belum tentu sudah diterima.

- **Kas Diterima** punya dua sumber uang masuk di periode ini:
  1. Transaksi tunai/QRIS yang terbentuk di periode ini — lunas
     seketika saat transaksi dibuat, jadi tanggal transaksi = tanggal
     uang masuk.
  2. Cicilan piutang (`pembayaran_piutang`) yang tanggal bayarnya
     jatuh di periode ini, walau transaksi induknya (nota utang)
     mungkin dibuat di periode SEBELUMNYA. Kalau ini tidak dihitung
     terpisah dari tanggal transaksi induk, uang yang masuk hari ini
     dari pelunasan utang bulan lalu akan "hilang" — tidak tercatat
     sebagai kas masuk periode manapun.

  Dua sumber ini punya filter tanggal sendiri-sendiri, lalu
  dijumlahkan jadi satu angka "kas diterima". Ini cash basis: mencatat
  uang begitu benar-benar diterima secara fisik/rekening.

  Kalau kedua angka ini digabung jadi satu, laporan jadi menyesatkan —
  seolah-olah kas fisik yang dipegang Teh Dewi sama dengan nilai
  jualan, padahal sebagian nilai jualan itu masih berupa piutang yang
  belum cair. Memisahkannya membuat laporan menjawab dua pertanyaan
  berbeda yang sama-sama penting: "berapa nilai bisnis yang jalan"
  (omzet) vs "berapa uang cash yang bisa dipegang/dibelanjakan
  sekarang" (kas diterima).

**Piutang Baru:** bukan sisa piutang total (itu urusan piutang.php) —
ini murni "berapa nilai jualan periode ini yang belum dibayar sama
sekali saat itu juga", yaitu `SUM(total_bayar)` transaksi
`metode_bayar = 'utang'` yang terbentuk di periode ini.

**Kenapa topping terlaris AMAN dari fan-out (beda kasus dengan
piutang.php):** di piutang.php, JOIN yang menggandakan baris itu
masalah karena kita mau MENJUMLAHKAN nominal (`SUM(total_bayar)`) —
baris ganda bikin nominal ke-double/triple. Di laporan topping
terlaris, kita justru MAU tau berapa KALI topping itu dipesan, jadi
`COUNT(*)` dari hasil JOIN (yang satu baris = satu kejadian "topping X
dipasang di satu porsi") itu memang angka yang benar, bukan bug. Tidak
perlu agregasi subquery seperti pola di piutang.php — fan-out di sini
bukan ancaman, karena definisi angka yang dicari justru "banyaknya
baris", bukan "jumlah nominal yang bisa terduplikasi".

## admin/topping.php — Manajemen Topping

**Kenapa list-nya tidak difilter `status_aktif = 1`:** query topping
di menu.php (halaman publik) dan transaksi.php (form kasir) sengaja
cuma nampilin topping aktif, karena tujuannya "topping apa yang boleh
DIPILIH sekarang". Tapi admin/topping.php tujuannya beda — ini panel
buat NGELOLA semua topping, termasuk yang sudah dinonaktifkan (supaya
bisa diaktifkan lagi atau dicek riwayat pemakaiannya). Kalau di sini
ikut difilter status_aktif, topping yang nonaktif jadi "hilang" dan
gak bisa diaktifkan lagi lewat UI.

**Apakah aman mengubah harga topping yang sudah pernah dipakai di
transaksi lama? Aman, dan ini justru alasan utama kenapa fitur EDIT
dibiarkan tanpa pembatasan tambahan.** Kuncinya ada di
`transaksi_detail_topping.harga_saat_transaksi` yang sudah dibangun di
transaksi.php: setiap kali topping dipasang ke satu porsi, harga
topping SAAT ITU langsung disalin (snapshot) ke baris
`transaksi_detail_topping`, bukan cuma nyimpan `id_topping` dan
ngandelin JOIN ke tabel `topping` tiap nota mau ditampilkan.
Konsekuensinya:

- Kolom `topping.harga_tambahan` itu representasi "harga topping
  SEKARANG / ke depan" — dipakai buat hitung transaksi BARU (lihat
  `$toppingMap` di transaksi.php) dan buat ditampilkan di form kasir
  serta menu.php publik.
- Kolom `transaksi_detail_topping.harga_saat_transaksi` itu
  representasi "harga topping WAKTU nota itu dibuat" — dipakai buat
  hitung ulang/nampilin nota LAMA (mis. di laporan.php).

Dua kolom ini sengaja independen. Jadi kalau harga topping di-edit di
sini, yang berubah cuma harga buat transaksi berikutnya; angka di nota
lama tetap seperti apa adanya karena mereka baca dari kolom snapshot,
bukan dari `topping.harga_tambahan`. Analoginya sama persis dengan
alasan snapshot yang sudah dijelasin di bagian transaksi.php di atas —
harga di struk lama tidak boleh ikut berubah cuma karena harga
sekarang berubah. (Contoh nyata: harga Telur pernah diubah ke Rp2.500
untuk uji coba fitur edit, lalu dikembalikan ke Rp2.000 setelah
dikonfirmasi ke Teh Dewi — nota-nota lama yang kebetulan terpasang
Telur tidak pernah ikut berubah selama proses itu, karena mereka baca
`harga_saat_transaksi`, bukan `topping.harga_tambahan`.)

**Kenapa NONAKTIFKAN dipakai buat topping yang sudah pernah dipakai,
bukan HAPUS PERMANEN:** ada foreign key
`transaksi_detail_topping.id_topping` → `topping.id` (lihat
`SHOW CREATE TABLE transaksi_detail_topping`). MySQL nolak DELETE
kalau masih ada baris `transaksi_detail_topping` yang menunjuk ke
topping itu (constraint RESTRICT, bukan CASCADE) — kalaupun dipaksa
lewat cara lain, hasilnya bakal ninggalin baris
`transaksi_detail_topping` yang nunjuk ke topping yang gak ada lagi
(orphan row), dan nota lama jadi gak bisa ditampilkan dengan benar
karena `nama_topping`-nya sudah hilang. Nonaktifkan (`status_aktif =
0`) menyelesaikan tujuan yang sama (topping itu gak muncul lagi buat
transaksi baru) tanpa merusak data histori — dia tetap ada di DB,
cuma disembunyikan dari pilihan topping aktif.

**Kenapa HAPUS PERMANEN hanya boleh untuk topping yang belum pernah
dipakai (COUNT = 0), dan divalidasi ulang di server (bukan cuma
disembunyikan di tampilan):** tombol hapus di tabel memang cuma
dirender kalau `jumlah_dipakai === 0` biar UI-nya bersih, tapi itu
BUKAN satu-satunya lapisan proteksi — angka yang dipakai buat render
tombol itu hasil query dari beberapa saat sebelumnya, bisa aja sudah
basi kalau kasir sempat input transaksi baru pakai topping itu di
antara halaman ini di-load dan tombol hapus di-klik (race condition),
atau kalau ada yang kirim request POST manual tanpa lewat UI sama
sekali. Makanya saat request `action=hapus` diproses, jumlah pemakaian
dihitung ULANG fresh dari DB (`COUNT(*) FROM transaksi_detail_topping
WHERE id_topping = ?`) sebelum benar-benar DELETE — persis prinsip
"hitung ulang dari DB saat submit" yang sudah dipakai di
piutang_detail.php buat validasi sisa utang. Kalau ternyata sudah ada
pemakaian, permintaan hapus ditolak dengan pesan error, bukan diam-diam
dibiarkan gagal karena constraint DB (biar pesannya jelas buat admin,
bukan error SQL mentah).

**Kenapa topping yang sempat dihapus lalu ditambah ulang dapat ID
baru (bukan ID lama):** kolom `id` di `topping` itu AUTO_INCREMENT
yang gak pernah dipakai ulang oleh MySQL walau baris lama sudah
dihapus permanen. Jadi kalau suatu topping (misal "Makaroni hitam")
sempat dihapus lalu ditambah lagi lewat form, dia otomatis dapat ID
baru yang lebih besar dari ID sebelumnya — ini bukan bug, itu memang
sifat AUTO_INCREMENT, dan gak masalah karena gak ada baris
`transaksi_detail_topping` manapun yang perlu nyambung ke ID lamanya
(kalau ada, topping itu gak akan bisa dihapus permanen — lihat aturan
di atas).

## menu.php — Menu Publik

**Kenapa cuma topping "Telur" yang ditandai `confirmed` (tanpa tanda
bintang), sedangkan topping lain dikasih tanda bintang `*` +
disclaimer "harga estimasi, nunggu konfirmasi harga pasti dari Teh
Dewi":** saat awal development, sebagian besar harga topping masih
taksiran karena belum sempat dikonfirmasi satu-satu langsung ke Teh
Dewi. Begitu satu topping harganya sudah dikonfirmasi lewat observasi
langsung (Telur = Rp2.000 — lihat catatan snapshot harga di
admin/topping.php di atas), tampilannya dibedakan (class `confirmed`,
tanpa bintang) supaya pembeli gak salah kira SEMUA harga di halaman
ini masih taksiran.

Flag ini sengaja hardcode nama topping
(`$t['nama_topping'] === 'Telur'`) di dalam `menu.php`, bukan kolom
`is_confirmed` terpisah di tabel `topping`. Ini keputusan sementara
yang masuk akal selama baru 1 topping yang statusnya beda dari yang
lain — begitu lebih banyak topping sudah dikonfirmasi harganya ke Teh
Dewi, ini sebaiknya diganti jadi kolom boolean asli di skema
`topping`, karena hardcode string match gampang typo (nama topping
diketik beda dikit, flag-nya diam-diam gak nyala) dan gak scalable
kalau daftar topping yang confirmed makin banyak.

## Migrasi 002 -- Foto Topping (`migrations/002_add_foto_to_topping.sql`)

Menambah kolom `topping.foto VARCHAR(100) NULL` (setelah `harga_tambahan`)
berisi **nama file saja** (mis. `telur.jpg`). Migrasi ini harus dijalankan
manual satu kali pada database aktif (backup dulu); `schema.sql` tidak
diubah dan database aktif tidak diimpor ulang. **Jalankan migrasi sebelum
memakai `menu.php` / `admin/topping.php` versi baru**, karena keduanya
sekarang meng-SELECT kolom `foto`.

- Foto diunggah lewat form tambah/edit di `admin/topping.php` (field `foto_upload`, form `multipart/form-data`).
  Validasi: ekstensi jpg/jpeg/png/webp, MIME asli (`finfo`) + `getimagesize`, maks 2MB.
  File disimpan sebagai `topping_<slug>_<timestamp>_<acak>.<ext>` di `assets/img/topping/`.
- Edit tanpa memilih file baru mempertahankan foto lama; checkbox "Hapus foto" mengosongkan kolom ke `NULL` (default).
  File lama hasil upload (prefix `topping_`) ikut dihapus saat diganti/dihapus; file manual tidak pernah dihapus.
- Fallback: kalau kolom `foto` kosong atau file fisik tidak ada, tampil
  `assets/img/topping/default.svg`. Pengecekan memakai `basename()` +
  `is_file()` supaya tidak bisa keluar dari folder topping.
- `menu.php`: `<img>` di kartu `topping-chip` (inline style, rasio 1:1,
  `object-fit: cover`); CSS global di `includes/header.php` tidak diubah.
- `admin/topping.php`: kolom thumbnail 40x40px, rounded, `object-fit: cover`.
- `admin/transaksi.php` tidak disentuh.
