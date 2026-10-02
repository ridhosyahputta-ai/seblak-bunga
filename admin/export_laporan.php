<?php
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/laporan_data.php';

$filters = getReportFilters($_GET);
$report = getReportData($pdo, $filters);

$filename = 'laporan-seblak-bunga-' . $filters['start'] . '-sampai-' . $filters['end'] . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

function csvSafe($value): string
{
    $text = (string) $value;
    return preg_match('/^[=+-@	]/', $text) ? "'" . $text : $text;
}

$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, ['Laporan Penjualan Seblak Bunga'], ';');
fputcsv($output, ['Periode', $filters['start'] . ' sampai ' . $filters['end']], ';');
if ($filters['q'] !== '') {
    fputcsv($output, ['Kata Kunci', csvSafe($filters['q'])], ';');
}
fputcsv($output, ['Total Omzet', $report['total_omzet']], ';');
fputcsv($output, ['Kas Diterima', $report['kas_diterima']], ';');
fputcsv($output, ['Piutang Baru', $report['piutang_baru']], ';');
fputcsv($output, ['Jumlah Transaksi', $report['jumlah_transaksi']], ';');
fputcsv($output, [], ';');
fputcsv($output, ['Kode Transaksi', 'Tanggal', 'Pelanggan', 'Metode Pembayaran', 'Status Pembayaran', 'Total Transaksi'], ';');

foreach ($report['transactions'] as $transaction) {
    fputcsv($output, [
        csvSafe($transaction['kode_transaksi']),
        date('Y-m-d H:i:s', strtotime($transaction['tanggal'])),
        csvSafe($transaction['nama_pelanggan'] ?: 'Pembeli lewat'),
        strtoupper($transaction['metode_bayar']),
        $transaction['status_bayar'] === 'lunas' ? 'Lunas' : 'Belum Lunas',
        (int) $transaction['total_bayar'],
    ], ';');
}

fclose($output);
exit;