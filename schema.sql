-- ============================================================
-- schema.sql -- Seblak Bunga (Projek PKK)
-- ============================================================
-- File ini di-export dari database MySQL yang BENERAN JALAN
-- (pakai `mysqldump`), bukan ditulis ulang dari ingatan/dokumentasi.
-- Jadi tipe kolom, constraint, dan urutan di bawah ini dijamin cocok
-- 100% sama yang dipakai aplikasi saat ini.
--
-- Urutan tabel sengaja DIUBAH dari urutan asli mysqldump (yang
-- alfabetis) jadi urutan "cerita": master data / pengaturan dulu,
-- baru alur transaksi, baru tabel turunannya. Ini murni supaya file
-- ini enak dibaca dari atas ke bawah pas sidang -- isi tabelnya
-- sendiri sama persis dengan yang ada di database.
--
-- Isi file ini:
--   - Struktur semua tabel (CREATE TABLE)
--   - Data seed yang MEMANG harus ada supaya aplikasi jalan begitu
--     schema ini di-import ke database kosong: pengaturan (harga
--     dasar), topping, dan 1 akun admin (users).
--   - Tabel transaksi, transaksi_detail, transaksi_detail_topping,
--     dan pembayaran_piutang SENGAJA dikosongkan (strukturnya tetap
--     ada, datanya tidak) -- baris yang sempat ada di sana sebelumnya
--     cuma data uji coba pas development, bukan data bawaan aplikasi.
--
-- Cara import ke database kosong:
--   mysql -u root < schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `seblak_bunga` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `seblak_bunga`;


-- ------------------------------------------------------------
-- pengaturan
-- ------------------------------------------------------------
-- Tabel key-value generik buat nilai-nilai yang bisa berubah
-- sewaktu-waktu tapi cuma ada SATU nilai aktif per key (bukan
-- riwayat/daftar) -- sekarang isinya cuma harga dasar seblak.
-- Dibikin generik (nama_pengaturan + nilai) ketimbang bikin kolom
-- khusus `harga_dasar_seblak` di suatu tabel config, supaya kalau
-- nanti nambah pengaturan lain (mis. ongkir, jam buka default) gak
-- perlu ALTER TABLE -- tinggal INSERT baris baru.
-- Dipakai lewat: SELECT nilai FROM pengaturan WHERE nama_pengaturan = '...'
-- (lihat index.php, menu.php, admin/transaksi.php).
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `pengaturan`;
CREATE TABLE `pengaturan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_pengaturan` varchar(50) NOT NULL,
  `nilai` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nama_pengaturan` (`nama_pengaturan`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `pengaturan` (`id`, `nama_pengaturan`, `nilai`) VALUES
(1, 'harga_dasar_seblak', '3000');


-- ------------------------------------------------------------
-- topping
-- ------------------------------------------------------------
-- Master data topping beserta harga tambahannya per porsi.
-- `status_aktif` dipakai buat "soft delete": kalau Teh Dewi berhenti
-- jual satu topping, barisnya TIDAK dihapus (kalau dihapus, baris
-- transaksi_detail_topping lama yang masih mereferensikan id topping
-- itu akan kehilangan referensinya / FK jadi masalah). Cukup diubah
-- status_aktif = 0 supaya berhenti muncul di menu publik & form
-- input transaksi, tapi riwayat transaksi lama tetap utuh.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `topping`;
CREATE TABLE `topping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_topping` varchar(50) NOT NULL,
  `harga_tambahan` int(11) NOT NULL,
  `status_aktif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `topping` (`id`, `nama_topping`, `harga_tambahan`, `status_aktif`) VALUES
(1, 'Kerupuk udang', 1000, 1),
(2, 'Makaroni kuning', 1000, 1),
(3, 'Makaroni hitam', 1000, 1),
(4, 'Telur', 2000, 1),
(5, 'Kwetiau', 1000, 1),
(6, 'Kerupuk mawar', 1000, 1),
(7, 'Tahu kering', 1000, 1);


-- ------------------------------------------------------------
-- pelanggan
-- ------------------------------------------------------------
-- Data pembeli -- TAPI TIDAK SEMUA pembeli wajib tercatat di sini.
-- Pembeli "lewat" yang bayar tunai/QRIS sekali beli sengaja tidak
-- dicatat identitasnya; gak ada gunanya buat usaha rumahan sekecil
-- ini dan cuma nambah beban input buat kasir. Tabel ini baru dipakai
-- kalau ada kebutuhan buat NGAITKAN transaksi ke seseorang -- yaitu
-- pas metode_bayar = 'utang' di tabel transaksi. Itu sebabnya
-- `transaksi.id_pelanggan` nullable (lihat tabel transaksi di bawah).
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `pelanggan`;
CREATE TABLE `pelanggan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- users
-- ------------------------------------------------------------
-- Akun buat login ke panel admin. Cuma dipakai 1 orang (Teh Dewi
-- sendiri, sekaligus pemilik & kasir), dan itu ngejelasin 2
-- keputusan desain:
--   1. Gak ada kolom role/hak akses -- siapa pun yang berhasil login
--      otomatis punya akses penuh, gak ada tingkatan admin vs staff.
--   2. Tabel transaksi TIDAK punya kolom "dicatat oleh siapa" (FK ke
--      users) -- karena cuma ada 1 akun yang mungkin input transaksi,
--      kolom itu gak nambah informasi apa pun. Kalau nanti usahanya
--      berkembang dan ada lebih dari 1 kasir, baru kolom itu relevan
--      buat ditambahkan.
-- `password` menyimpan hasil password_hash() PHP (bcrypt), dicek
-- pakai password_verify() saat login (lihat admin/login.php) --
-- bukan plain text ataupun hash mentah MD5/SHA1.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` (`id`, `username`, `password`, `nama`, `created_at`) VALUES
(1, 'tehdewi', '$2y$10$7Ntsbwo5.RQrdaG.9WOUguGdd7Sai0rkjNAJ30ILJ1DYvEvV4DdwS', 'Teh Dewi', '2026-09-11 05:37:33');


-- ------------------------------------------------------------
-- transaksi
-- ------------------------------------------------------------
-- 1 baris = 1 nota/kwitansi. Ini tabel "header" dari struktur
-- header-detail (pola yang sama dipakai di tabel invoice sistem
-- kasir/akuntansi manapun) -- isi porsi seblaknya TIDAK ada di sini,
-- tapi di transaksi_detail, karena 1 nota bisa berisi beberapa porsi
-- dengan level pedas & topping yang beda-beda (mis. keluarga yang
-- beli bareng, masing-masing pesan racikan sendiri).
--
-- `id_pelanggan` NULLABLE dengan sengaja (lihat tabel pelanggan di
-- atas). Aturan "wajib diisi kalau metode_bayar = 'utang'" itu
-- aturan APLIKASI (dicek di admin/transaksi.php), bukan constraint
-- SQL -- karena constraint kondisional kayak gitu gak natural
-- diekspresikan cuma pakai NOT NULL/CHECK biasa.
--
-- `metode_bayar` punya opsi 'utang' karena usaha ini emang biasa
-- ngasih utang ke pelanggan langganan (lihat kontak.php: "Bisa juga
-- dicatat sebagai utang untuk pelanggan langganan"). `status_bayar`
-- sengaja dipisah dari metode_bayar karena dua-duanya beda konsep:
-- metode_bayar itu CARA bayar, status_bayar itu APAKAH sudah lunas.
-- Transaksi 'utang' otomatis 'belum_lunas' saat dibuat, lalu diubah
-- 'lunas' setelah cicilannya (lihat pembayaran_piutang) menutupi
-- total_bayar.
--
-- `kode_transaksi` adalah nomor nota yang gampang dibaca manusia
-- (format: TRXyyyymmdd-xxxx), dipisah dari `id` (primary key teknis)
-- karena `id` auto-increment kurang cocok ditunjukkin ke pelanggan
-- sebagai nomor struk. `id` cuma dipakai internal buat relasi antar
-- tabel; `kode_transaksi` yang dicetak/disebut ke pembeli.
--
-- `total_bayar` DISIMPAN (bukan dihitung ulang tiap ditampilkan
-- lewat SUM ke transaksi_detail) supaya nota lama tetap nunjukin
-- angka persis sama seperti pas transaksi terjadi -- konsisten
-- dengan alasan snapshot harga di transaksi_detail (lihat di bawah).
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `transaksi`;
CREATE TABLE `transaksi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kode_transaksi` varchar(30) NOT NULL,
  `id_pelanggan` int(11) DEFAULT NULL,
  `tanggal` datetime NOT NULL DEFAULT current_timestamp(),
  `metode_bayar` enum('tunai','qris','utang') NOT NULL,
  `status_bayar` enum('lunas','belum_lunas') NOT NULL DEFAULT 'lunas',
  `total_bayar` int(11) NOT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kode_transaksi` (`kode_transaksi`),
  KEY `id_pelanggan` (`id_pelanggan`),
  CONSTRAINT `transaksi_ibfk_1` FOREIGN KEY (`id_pelanggan`) REFERENCES `pelanggan` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- transaksi_detail
-- ------------------------------------------------------------
-- 1 baris = 1 PORSI seblak di dalam sebuah nota. Dipisah dari tabel
-- transaksi karena alasan yang sama dijelasin di atas: 1 nota bisa
-- berisi banyak porsi dengan konfigurasi beda-beda.
--
-- TIDAK ADA kolom qty/jumlah di sini, dan itu SENGAJA: kalau ada 2
-- porsi identik (level pedas & topping sama persis), itu tetap jadi
-- 2 baris terpisah, bukan 1 baris dengan qty=2. Konsekuensinya, form
-- input transaksi (admin/transaksi.php) punya tombol "Tambah Porsi"
-- per porsi, bukan input angka qty. Kalau nanti mau dukung qty, itu
-- perubahan skema (nambah kolom), bukan cuma perubahan form.
--
-- `level_pedas` VARCHAR bebas (bukan ENUM atau angka skala 1-5)
-- karena usaha ini memang gak pakai skala pedas baku -- pelanggan
-- tinggal bilang "sedang", "extra pedas", "gak pedas", dst pakai
-- kata-kata sendiri (lihat menu.php: "Gak pake skala angka baku").
-- Kolomnya harus bisa nampung teks bebas, bukan dipaksa masuk daftar
-- tetap yang belum tentu mewakili cara ngomong pelanggan.
--
-- `harga_dasar` di sini adalah SALINAN (snapshot) dari
-- pengaturan.nilai (key 'harga_dasar_seblak') PADA SAAT transaksi
-- dibuat -- bukan referensi/JOIN live ke tabel pengaturan. Ini kunci
-- supaya kalau Teh Dewi naikkan harga dasar bulan depan, laporan nota
-- BULAN LALU gak ikut berubah angkanya. Alasan yang sama berlaku
-- buat `subtotal` (= harga_dasar + total harga topping yang dipilih,
-- dihitung & disimpan saat itu juga, bukan dihitung ulang tiap tampil).
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `transaksi_detail`;
CREATE TABLE `transaksi_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_transaksi` int(11) NOT NULL,
  `level_pedas` varchar(50) DEFAULT NULL,
  `harga_dasar` int(11) NOT NULL,
  `subtotal` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_transaksi` (`id_transaksi`),
  CONSTRAINT `transaksi_detail_ibfk_1` FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- transaksi_detail_topping
-- ------------------------------------------------------------
-- Tabel JUNCTION buat relasi many-to-many antara 1 porsi
-- (transaksi_detail) dan topping: 1 porsi bisa punya BANYAK topping
-- sekaligus (mis. extra pedas + telur + makaroni), dan 1 jenis
-- topping (mis. "Telur") jelas dipakai di banyak porsi yang berbeda-
-- beda. Relasi seperti ini gak bisa direpresentasikan cuma dengan
-- 1 kolom id_topping langsung di transaksi_detail -- makanya butuh
-- tabel terpisah dengan 1 baris per pasangan (porsi, topping).
--
-- `harga_saat_transaksi` -- alasan snapshot-nya SAMA PERSIS seperti
-- `harga_dasar` di transaksi_detail: kalau yang disimpan cuma
-- id_topping lalu di-JOIN ke tabel topping tiap kali nota
-- ditampilkan, harga topping di nota LAMA bakal ikut berubah kalau
-- harga topping di master data berubah/naik. Snapshot ini yang bikin
-- data historis (laporan bulan lalu, dst) tetap akurat selamanya,
-- bahkan kalau toppingnya nanti dinonaktifkan (status_aktif = 0).
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `transaksi_detail_topping`;
CREATE TABLE `transaksi_detail_topping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_transaksi_detail` int(11) NOT NULL,
  `id_topping` int(11) NOT NULL,
  `harga_saat_transaksi` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_transaksi_detail` (`id_transaksi_detail`),
  KEY `id_topping` (`id_topping`),
  CONSTRAINT `transaksi_detail_topping_ibfk_1` FOREIGN KEY (`id_transaksi_detail`) REFERENCES `transaksi_detail` (`id`),
  CONSTRAINT `transaksi_detail_topping_ibfk_2` FOREIGN KEY (`id_topping`) REFERENCES `topping` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- pembayaran_piutang
-- ------------------------------------------------------------
-- Nyatet riwayat CICILAN pembayaran utang. Dipisah dari tabel
-- transaksi (bukan cuma nambah 1 kolom "tanggal_lunas" di transaksi)
-- karena 1 utang bisa dibayar beberapa kali dengan jumlah berbeda-
-- beda (mis. bayar Rp10.000 hari ini, Rp5.000 minggu depan, dst)
-- sebelum lunas total. Kalau cuma 1 kolom tanggal lunas, riwayat
-- cicilan sebelumnya hilang -- padahal itu penting buat nunjukin ke
-- pelanggan "kamu udah bayar berapa aja sejauh ini".
-- `transaksi.status_bayar` diupdate jadi 'lunas' oleh aplikasi begitu
-- total SUM(jumlah_bayar) di sini menyamai transaksi.total_bayar.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `pembayaran_piutang`;
CREATE TABLE `pembayaran_piutang` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_transaksi` int(11) NOT NULL,
  `tanggal_bayar` datetime NOT NULL DEFAULT current_timestamp(),
  `jumlah_bayar` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_transaksi` (`id_transaksi`),
  CONSTRAINT `pembayaran_piutang_ibfk_1` FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Catatan jujur (bukan disembunyikan biar rapi):
--
-- 1. Semua tabel memakai utf8mb4_general_ci agar konsisten dengan
--    database dan charset koneksi PDO. Untuk database lama, jalankan
--    migrations/001_convert_utf8mb4.sql setelah membuat backup.
--
-- 2. FOREIGN_KEY_CHECKS dimatikan sementara cuma supaya urutan
--    CREATE TABLE di atas (yang disusun buat enak dibaca manusia,
--    bukan alfabetis) tetap bisa di-import tanpa error "table
--    doesn't exist yet" -- bukan buat mengakali/melewati validasi FK
--    pas aplikasi jalan sehari-hari.
-- ============================================================
