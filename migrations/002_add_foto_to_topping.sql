-- Jalankan satu kali pada database seblak_bunga yang sudah berisi data.
-- Menambah kolom nama file foto topping (file diletakkan manual di assets/img/topping/).
USE seblak_bunga;

ALTER TABLE `topping` ADD COLUMN `foto` VARCHAR(100) NULL AFTER `harga_tambahan`;
