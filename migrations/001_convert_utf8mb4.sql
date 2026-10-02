-- Jalankan satu kali pada database seblak_bunga yang sudah berisi data.
-- Backup database terlebih dahulu. CONVERT TO mengonversi data teks.
USE seblak_bunga;

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE pengaturan CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE topping CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE pelanggan CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE transaksi CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE transaksi_detail CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE transaksi_detail_topping CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE pembayaran_piutang CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;