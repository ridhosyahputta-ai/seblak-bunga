<?php

function validReportDate(string $value): ?DateTime
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();

    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }

    return $date->format('Y-m-d') === $value ? $date : null;
}

function getReportFilters(array $query): array
{
    $presetRaw = isset($query['preset']) && is_string($query['preset']) ? $query['preset'] : 'hari_ini';
    $preset = in_array($presetRaw, ['hari_ini', 'minggu_ini', 'bulan_ini', 'custom'], true)
        ? $presetRaw
        : 'hari_ini';

    $q = isset($query['q']) && is_string($query['q']) ? trim($query['q']) : '';
    if (mb_strlen($q) > 100) {
        $q = mb_substr($q, 0, 100);
    }

    $today = new DateTime('today');

    if ($preset === 'minggu_ini') {
        $start = (clone $today)->modify('monday this week');
        $end = clone $today;
    } elseif ($preset === 'bulan_ini') {
        $start = (clone $today)->modify('first day of this month');
        $end = clone $today;
    } elseif ($preset === 'custom') {
        $startRaw = isset($query['dari']) && is_string($query['dari']) ? $query['dari'] : '';
        $endRaw = isset($query['sampai']) && is_string($query['sampai']) ? $query['sampai'] : '';
        $start = validReportDate($startRaw) ?? clone $today;
        $end = validReportDate($endRaw) ?? clone $today;
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
    } else {
        $start = clone $today;
        $end = clone $today;
    }

    return [
        'preset' => $preset,
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'q' => $q,
    ];
}

function reportSearchSql(string $transactionAlias = 't', string $customerAlias = 'p'): string
{
    return "(
        {$transactionAlias}.kode_transaksi LIKE ?
        OR COALESCE({$customerAlias}.nama, '') LIKE ?
        OR {$transactionAlias}.metode_bayar LIKE ?
        OR REPLACE({$transactionAlias}.status_bayar, '_', ' ') LIKE ?
    )";
}

function reportSearchParams(string $keyword): array
{
    $like = '%' . $keyword . '%';
    return [$like, $like, $like, $like];
}

function getReportData(PDO $pdo, array $filters): array
{
    $dateParams = [$filters['start'], $filters['end']];
    $hasSearch = $filters['q'] !== '';
    $searchSql = $hasSearch ? ' AND ' . reportSearchSql() : '';
    $params = $dateParams;
    if ($hasSearch) {
        $params = array_merge($params, reportSearchParams($filters['q']));
    }

    $stmt = $pdo->prepare("
        SELECT t.kode_transaksi, t.tanggal, t.metode_bayar, t.status_bayar, t.total_bayar,
               p.nama AS nama_pelanggan
        FROM transaksi t
        LEFT JOIN pelanggan p ON p.id = t.id_pelanggan
        WHERE DATE(t.tanggal) BETWEEN ? AND ?
        $searchSql
        ORDER BY t.tanggal DESC
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    $totalOmzet = 0;
    $jumlahTransaksi = count($transactions);
    $kasDariTunaiQris = 0;
    $piutangBaru = 0;
    $breakdown = [];
    foreach (['tunai', 'qris', 'utang'] as $method) {
        $breakdown[$method] = ['metode_bayar' => $method, 'jumlah' => 0, 'total' => 0];
    }

    foreach ($transactions as $transaction) {
        $amount = (int) $transaction['total_bayar'];
        $method = $transaction['metode_bayar'];
        $totalOmzet += $amount;
        if (isset($breakdown[$method])) {
            $breakdown[$method]['jumlah']++;
            $breakdown[$method]['total'] += $amount;
        }
        if ($method === 'tunai' || $method === 'qris') {
            $kasDariTunaiQris += $amount;
        } elseif ($method === 'utang') {
            $piutangBaru += $amount;
        }
    }

    $paymentSearchSql = $hasSearch ? ' AND ' . reportSearchSql() : '';
    $paymentParams = $dateParams;
    if ($hasSearch) {
        $paymentParams = array_merge($paymentParams, reportSearchParams($filters['q']));
    }
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(pp.jumlah_bayar), 0)
        FROM pembayaran_piutang pp
        JOIN transaksi t ON t.id = pp.id_transaksi
        LEFT JOIN pelanggan p ON p.id = t.id_pelanggan
        WHERE DATE(pp.tanggal_bayar) BETWEEN ? AND ?
        $paymentSearchSql
    ");
    $stmt->execute($paymentParams);
    $cashFromPayments = (int) $stmt->fetchColumn();

    $toppingSearchSql = $hasSearch ? ' AND ' . reportSearchSql() : '';
    $toppingParams = $dateParams;
    if ($hasSearch) {
        $toppingParams = array_merge($toppingParams, reportSearchParams($filters['q']));
    }
    $stmt = $pdo->prepare("
        SELECT tp.nama_topping, COUNT(*) AS jumlah_dipesan
        FROM transaksi_detail_topping tdt
        JOIN transaksi_detail td ON td.id = tdt.id_transaksi_detail
        JOIN transaksi t ON t.id = td.id_transaksi
        LEFT JOIN pelanggan p ON p.id = t.id_pelanggan
        JOIN topping tp ON tp.id = tdt.id_topping
        WHERE DATE(t.tanggal) BETWEEN ? AND ?
        $toppingSearchSql
        GROUP BY tp.id, tp.nama_topping
        ORDER BY jumlah_dipesan DESC, tp.nama_topping ASC
    ");
    $stmt->execute($toppingParams);

    return [
        'transactions' => $transactions,
        'total_omzet' => $totalOmzet,
        'jumlah_transaksi' => $jumlahTransaksi,
        'kas_diterima' => $kasDariTunaiQris + $cashFromPayments,
        'piutang_baru' => $piutangBaru,
        'breakdown' => $breakdown,
        'topping_terlaris' => $stmt->fetchAll(),
    ];
}

function reportQueryString(array $filters, array $overrides = []): string
{
    $params = [
        'preset' => $filters['preset'],
        'dari' => $filters['start'],
        'sampai' => $filters['end'],
        'q' => $filters['q'],
    ];

    return http_build_query(array_merge($params, $overrides));
}