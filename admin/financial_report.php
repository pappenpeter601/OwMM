<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

ensure_financial_reporting_support();
ensure_expense_request_support();

if (!is_logged_in() || !has_permission('financial_report.php')) {
    redirect('dashboard.php');
}

function add_report_bucket(&$buckets, $key, $name, $color, $direction, $amount) {
    $amount = (float) $amount;
    if ($amount <= 0) {
        return;
    }

    if (!isset($buckets[$key])) {
        $buckets[$key] = [
            'name' => $name ?: 'Ohne Zuordnung',
            'color' => $color ?: '#78909c',
            'income' => 0.0,
            'expense' => 0.0
        ];
    }

    if ($direction === 'income') {
        $buckets[$key]['income'] += $amount;
    } else {
        $buckets[$key]['expense'] += $amount;
    }
}

$db = getDBConnection();
$mode = $_GET['mode'] ?? 'year';
$year = (int) ($_GET['year'] ?? date('Y'));
$date_from = trim((string) ($_GET['date_from'] ?? ''));
$date_to = trim((string) ($_GET['date_to'] ?? ''));

if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

if ($mode !== 'range') {
    $mode = 'year';
    $date_from = $year . '-01-01';
    $date_to = $year . '-12-31';
} else {
    if ($date_from === '') {
        $date_from = date('Y-01-01');
    }
    if ($date_to === '') {
        $date_to = date('Y-m-d');
    }
}

if ($date_from > $date_to) {
    $swap = $date_from;
    $date_from = $date_to;
    $date_to = $swap;
}

$stmt = $db->prepare("SELECT t.*, tc.name AS cat_name, tc.color AS cat_color
                      FROM transactions t
                      LEFT JOIN transaction_categories tc ON t.category_id = tc.id
                      WHERE t.booking_date BETWEEN :date_from AND :date_to
                      ORDER BY t.booking_date ASC, t.id ASC");
$stmt->execute([
    ':date_from' => $date_from,
    ':date_to' => $date_to
]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_income = 0.0;
$total_expense = 0.0;
$income_count = 0;
$expense_count = 0;

foreach ($transactions as $tx) {
    $amount = (float) $tx['amount'];
    if ($amount >= 0) {
        $total_income += $amount;
        $income_count++;
    } else {
        $total_expense += abs($amount);
        $expense_count++;
    }
}

$net_total = $total_income - $total_expense;
$tx_count = count($transactions);

$overview_stmt = $db->query("SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total FROM transactions");
$finance_overview = $overview_stmt->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'total' => 0];

$stmt = $db->query("
    SELECT 
        COALESCE(SUM(CASE WHEN m.member_type = 'active' AND m.active = 1 AND o.status IN ('open', 'partial') THEN 1 ELSE 0 END), 0) AS active_count,
        COALESCE(SUM(CASE WHEN m.member_type = 'active' AND m.active = 1 AND o.status IN ('open', 'partial') THEN (o.fee_amount - o.paid_amount) ELSE 0 END), 0) AS active_amount,
        COALESCE(SUM(CASE WHEN m.member_type = 'supporter' AND m.active = 1 AND o.status IN ('open', 'partial') THEN 1 ELSE 0 END), 0) AS supporter_count,
        COALESCE(SUM(CASE WHEN m.member_type = 'supporter' AND m.active = 1 AND o.status IN ('open', 'partial') THEN (o.fee_amount - o.paid_amount) ELSE 0 END), 0) AS supporter_amount
    FROM member_fee_obligations o
    INNER JOIN members m ON o.member_id = m.id
");
$obligation_overview = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'active_count' => 0,
    'active_amount' => 0,
    'supporter_count' => 0,
    'supporter_amount' => 0
];

$range_days = 1;
try {
    $from_dt = new DateTime($date_from);
    $to_dt = new DateTime($date_to);
    $range_days = max(1, (int) $from_dt->diff($to_dt)->days + 1);
} catch (Exception $e) {
    $range_days = 1;
}

$timeline_sql = $range_days <= 62
    ? "SELECT booking_date AS period_key,
              DATE_FORMAT(booking_date, '%d.%m.%Y') AS period_label,
              SUM(CASE WHEN amount >= 0 THEN amount ELSE 0 END) AS income,
              SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) AS expense
       FROM transactions
       WHERE booking_date BETWEEN :date_from AND :date_to
       GROUP BY booking_date
       ORDER BY booking_date ASC"
    : "SELECT DATE_FORMAT(booking_date, '%Y-%m-01') AS period_key,
              DATE_FORMAT(booking_date, '%m.%Y') AS period_label,
              SUM(CASE WHEN amount >= 0 THEN amount ELSE 0 END) AS income,
              SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) AS expense
       FROM transactions
       WHERE booking_date BETWEEN :date_from AND :date_to
       GROUP BY DATE_FORMAT(booking_date, '%Y-%m')
       ORDER BY period_key ASC";

$stmt = $db->prepare($timeline_sql);
$stmt->execute([
    ':date_from' => $date_from,
    ':date_to' => $date_to
]);
$timeline_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$timeline_max = 1.0;
foreach ($timeline_rows as $row) {
    $timeline_max = max($timeline_max, (float) $row['income'], (float) $row['expense']);
}

$flow_category_buckets = [];
$linked_by_transaction = [];

$stmt = $db->prepare("SELECT mp.transaction_id,
                             mp.amount,
                             mfo.category_id,
                             tc.name AS category_name,
                             tc.color AS category_color
                      FROM member_payments mp
                      INNER JOIN transactions t ON mp.transaction_id = t.id
                      LEFT JOIN member_fee_obligations mfo ON mp.obligation_id = mfo.id
                      LEFT JOIN transaction_categories tc ON mfo.category_id = tc.id
                      WHERE t.booking_date BETWEEN :tx_from AND :tx_to");
$stmt->execute([
    ':tx_from' => $date_from,
    ':tx_to' => $date_to
]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = 'flow_fee_' . ((int) ($row['category_id'] ?? 0));
    add_report_bucket($flow_category_buckets, $key, $row['category_name'] ?? 'Ohne Zuordnung', $row['category_color'] ?? '#78909c', 'income', abs((float) $row['amount']));
    $linked_by_transaction[(int) $row['transaction_id']] = ($linked_by_transaction[(int) $row['transaction_id']] ?? 0) + abs((float) $row['amount']);
}

$stmt = $db->prepare("SELECT ip.transaction_id,
                             ip.amount,
                             io.category_id,
                             tc.name AS category_name,
                             tc.color AS category_color,
                             CASE WHEN t.amount >= 0 THEN 'income' ELSE 'expense' END AS direction
                      FROM item_obligation_payments ip
                      INNER JOIN transactions t ON ip.transaction_id = t.id
                      LEFT JOIN item_obligations io ON ip.obligation_id = io.id
                      LEFT JOIN transaction_categories tc ON io.category_id = tc.id
                      WHERE t.booking_date BETWEEN :tx_from AND :tx_to");
$stmt->execute([
    ':tx_from' => $date_from,
    ':tx_to' => $date_to
]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = 'flow_item_' . ((int) ($row['category_id'] ?? 0));
    add_report_bucket($flow_category_buckets, $key, $row['category_name'] ?? 'Ohne Zuordnung', $row['category_color'] ?? '#78909c', $row['direction'], abs((float) $row['amount']));
    $linked_by_transaction[(int) $row['transaction_id']] = ($linked_by_transaction[(int) $row['transaction_id']] ?? 0) + abs((float) $row['amount']);
}

$flow_category_rows = array_values($flow_category_buckets);
$flow_income_total = 0.0;
$flow_expense_total = 0.0;
foreach ($flow_category_rows as &$row) {
    $row['net'] = $row['income'] - $row['expense'];
    $row['volume'] = $row['income'] + $row['expense'];
    $flow_income_total += (float) $row['income'];
    $flow_expense_total += (float) $row['expense'];
}
unset($row);

usort($flow_category_rows, function ($a, $b) {
    return $b['volume'] <=> $a['volume'];
});

$flow_category_max = 1.0;
foreach ($flow_category_rows as $row) {
    $flow_category_max = max($flow_category_max, (float) $row['income'], (float) $row['expense']);
}
$flow_net_total = $flow_income_total - $flow_expense_total;

$open_category_buckets = [];
$fee_period_condition = $mode === 'year'
    ? "mfo.fee_year = :year"
    : "((mfo.due_date BETWEEN :due_from AND :due_to) OR DATE(mfo.created_at) BETWEEN :created_from AND :created_to)";
$category_params = $mode === 'year'
    ? [':year' => $year]
    : [
        ':due_from' => $date_from,
        ':due_to' => $date_to,
        ':created_from' => $date_from,
        ':created_to' => $date_to
    ];

$stmt = $db->prepare("SELECT mfo.category_id,
                             tc.name AS category_name,
                             tc.color AS category_color,
                             SUM(GREATEST(mfo.fee_amount - mfo.paid_amount, 0)) AS outstanding_amount
                      FROM member_fee_obligations mfo
                      LEFT JOIN transaction_categories tc ON mfo.category_id = tc.id
                      WHERE {$fee_period_condition}
                        AND mfo.status IN ('open', 'partial')
                        AND (mfo.fee_amount - mfo.paid_amount) > 0
                      GROUP BY mfo.category_id, tc.name, tc.color");
$stmt->execute($category_params);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = 'open_fee_' . ((int) ($row['category_id'] ?? 0));
    add_report_bucket($open_category_buckets, $key, $row['category_name'] ?? 'Ohne Zuordnung', $row['category_color'] ?? '#78909c', 'income', $row['outstanding_amount']);
}

$stmt = $db->prepare("SELECT io.category_id,
                             tc.name AS category_name,
                             tc.color AS category_color,
                             CASE WHEN er.id IS NULL THEN 'income' ELSE 'expense' END AS direction,
                             SUM(GREATEST(io.total_amount - io.paid_amount, 0)) AS outstanding_amount
                      FROM item_obligations io
                      LEFT JOIN expense_requests er ON er.linked_item_obligation_id = io.id
                      LEFT JOIN transaction_categories tc ON io.category_id = tc.id
                      WHERE ((io.due_date BETWEEN :due_from AND :due_to) OR DATE(io.created_at) BETWEEN :created_from AND :created_to)
                        AND (io.total_amount - io.paid_amount) > 0
                        AND (
                            (er.id IS NULL AND io.status = 'open')
                            OR (er.id IS NOT NULL AND COALESCE(er.status, '') IN ('submitted', 'approved'))
                        )
                      GROUP BY io.category_id, tc.name, tc.color, direction");
$stmt->execute([
    ':due_from' => $date_from,
    ':due_to' => $date_to,
    ':created_from' => $date_from,
    ':created_to' => $date_to
]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = 'open_item_' . ((int) ($row['category_id'] ?? 0));
    add_report_bucket($open_category_buckets, $key, $row['category_name'] ?? 'Ohne Zuordnung', $row['category_color'] ?? '#78909c', $row['direction'], $row['outstanding_amount']);
}

$open_category_rows = array_values($open_category_buckets);
$open_incoming_total = 0.0;
$open_outgoing_total = 0.0;
foreach ($open_category_rows as &$row) {
    $row['net'] = $row['income'] - $row['expense'];
    $row['volume'] = $row['income'] + $row['expense'];
    $open_incoming_total += (float) $row['income'];
    $open_outgoing_total += (float) $row['expense'];
}
unset($row);

usort($open_category_rows, function ($a, $b) {
    return $b['volume'] <=> $a['volume'];
});

$open_category_max = 1.0;
foreach ($open_category_rows as $row) {
    $open_category_max = max($open_category_max, (float) $row['income'], (float) $row['expense']);
}
$open_net_total = $open_incoming_total - $open_outgoing_total;

$transparency_buckets = [];
$transparency_cancelled_count = 0;
$transparency_rejected_count = 0;
$transparency_cancelled_total = 0.0;
$transparency_rejected_total = 0.0;
$transparency_params = [
    ':due_from' => $date_from,
    ':due_to' => $date_to,
    ':created_from' => $date_from,
    ':created_to' => $date_to
];

$stmt = $db->prepare("SELECT io.category_id,
                             tc.name AS category_name,
                             tc.color AS category_color,
                             COUNT(*) AS item_count,
                             SUM(GREATEST(io.total_amount - io.paid_amount, 0)) AS amount_total
                      FROM item_obligations io
                      LEFT JOIN expense_requests er ON er.linked_item_obligation_id = io.id
                      LEFT JOIN transaction_categories tc ON io.category_id = tc.id
                      WHERE er.id IS NULL
                        AND io.status = 'cancelled'
                        AND ((io.due_date BETWEEN :due_from AND :due_to) OR DATE(io.created_at) BETWEEN :created_from AND :created_to)
                      GROUP BY io.category_id, tc.name, tc.color");
$stmt->execute($transparency_params);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = 'cancelled_' . ((int) ($row['category_id'] ?? 0));
    add_report_bucket($transparency_buckets, $key, $row['category_name'] ?? 'Ohne Zuordnung', $row['category_color'] ?? '#78909c', 'income', $row['amount_total']);
    $transparency_buckets[$key]['cancelled_count'] = (int) ($transparency_buckets[$key]['cancelled_count'] ?? 0) + (int) $row['item_count'];
    $transparency_buckets[$key]['rejected_count'] = (int) ($transparency_buckets[$key]['rejected_count'] ?? 0);
    $transparency_cancelled_count += (int) $row['item_count'];
    $transparency_cancelled_total += (float) $row['amount_total'];
}

$stmt = $db->prepare("SELECT io.category_id,
                             tc.name AS category_name,
                             tc.color AS category_color,
                             COUNT(*) AS item_count,
                             SUM(GREATEST(io.total_amount - io.paid_amount, 0)) AS amount_total
                      FROM item_obligations io
                      INNER JOIN expense_requests er ON er.linked_item_obligation_id = io.id
                      LEFT JOIN transaction_categories tc ON io.category_id = tc.id
                      WHERE er.status = 'rejected'
                        AND ((io.due_date BETWEEN :due_from AND :due_to) OR DATE(io.created_at) BETWEEN :created_from AND :created_to)
                      GROUP BY io.category_id, tc.name, tc.color");
$stmt->execute($transparency_params);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = 'rejected_' . ((int) ($row['category_id'] ?? 0));
    add_report_bucket($transparency_buckets, $key, $row['category_name'] ?? 'Ohne Zuordnung', $row['category_color'] ?? '#78909c', 'expense', $row['amount_total']);
    $transparency_buckets[$key]['cancelled_count'] = (int) ($transparency_buckets[$key]['cancelled_count'] ?? 0);
    $transparency_buckets[$key]['rejected_count'] = (int) ($transparency_buckets[$key]['rejected_count'] ?? 0) + (int) $row['item_count'];
    $transparency_rejected_count += (int) $row['item_count'];
    $transparency_rejected_total += (float) $row['amount_total'];
}

$transparency_rows = array_values($transparency_buckets);
foreach ($transparency_rows as &$row) {
    $row['net'] = $row['income'] - $row['expense'];
    $row['volume'] = $row['income'] + $row['expense'];
    $row['cancelled_count'] = (int) ($row['cancelled_count'] ?? 0);
    $row['rejected_count'] = (int) ($row['rejected_count'] ?? 0);
}
unset($row);

usort($transparency_rows, function ($a, $b) {
    return $b['volume'] <=> $a['volume'];
});

$transparency_max = 1.0;
foreach ($transparency_rows as $row) {
    $transparency_max = max($transparency_max, (float) $row['income'], (float) $row['expense']);
}

$open_fee_count_total = (int) $obligation_overview['active_count'] + (int) $obligation_overview['supporter_count'];
$open_fee_amount_total = (float) $obligation_overview['active_amount'] + (float) $obligation_overview['supporter_amount'];
$period_label = date('d.m.Y', strtotime($date_from)) . ' – ' . date('d.m.Y', strtotime($date_to));

$page_title = 'Finanzbericht';
include 'includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
    <div>
        <h1><i class="fas fa-chart-line"></i> Finanzbericht</h1>
        <p style="margin: 0; color: #666;">Auswertung nach Zeitraum, Einnahmen/Ausgaben und Kategorien.</p>
    </div>
    <a href="kontofuehrung.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Zur Kontoführung
    </a>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <div class="card-header">
        <h2>Zeitraum wählen</h2>
    </div>
    <div class="card-body">
        <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items: end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label for="mode">Auswahltyp</label>
                <select id="mode" name="mode">
                    <option value="year" <?php echo $mode === 'year' ? 'selected' : ''; ?>>Nach Jahr</option>
                    <option value="range" <?php echo $mode === 'range' ? 'selected' : ''; ?>>Von / Bis</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label for="year">Jahr</label>
                <select id="year" name="year">
                    <?php for ($y = date('Y') - 5; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label for="date_from">Von</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label for="date_to">Bis</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0; display: flex; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Anwenden</button>
                <a href="financial_report.php" class="btn btn-secondary">Zurücksetzen</a>
            </div>
        </form>
    </div>
</div>

<div class="quick-stats" style="margin-bottom: 1rem;">
    <h2>Übersicht</h2>

    <div style="display: grid; gap: 1.25rem;">
        <div>
            <h3 style="margin: 0 0 0.75rem 0; color: #333;">Gesamtfinanzen</h3>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Transaktionen</div>
                    <div class="stat-number"><?php echo (int) $finance_overview['count']; ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Nettosaldo</div>
                    <div class="stat-number" style="color: <?php echo ((float) $finance_overview['total']) >= 0 ? '#4caf50' : '#f44336'; ?>; font-size: 2rem; font-weight: bold;"><?php echo number_format((float) $finance_overview['total'], 2, ',', '.'); ?> €</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Offene Eingänge</div>
                    <div class="stat-number" style="color: <?php echo $open_incoming_total > 0 ? '#ff9800' : '#4caf50'; ?>; font-size: 1.8rem;"><?php echo number_format($open_incoming_total, 2, ',', '.'); ?> €</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Offene Ausgänge</div>
                    <div class="stat-number" style="color: <?php echo $open_outgoing_total > 0 ? '#ff9800' : '#4caf50'; ?>; font-size: 1.8rem;"><?php echo number_format($open_outgoing_total, 2, ',', '.'); ?> €</div>
                </div>
            </div>
        </div>

        <div>
            <h3 style="margin: 0 0 0.75rem 0; color: #333;">Offene Mitgliedsbeiträge</h3>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Offene Forderungen gesamt</div>
                    <div class="stat-number" style="color: <?php echo $open_fee_count_total > 0 ? '#ff9800' : '#4caf50'; ?>;"><?php echo $open_fee_count_total; ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Ausstehend gesamt</div>
                    <div class="stat-number" style="color: <?php echo $open_fee_amount_total > 0 ? '#ff9800' : '#4caf50'; ?>; font-size: 1.8rem;"><?php echo number_format($open_fee_amount_total, 2, ',', '.'); ?> €</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Aktive offen</div>
                    <div class="stat-number" style="color: <?php echo ((int) $obligation_overview['active_count']) > 0 ? '#ff9800' : '#4caf50'; ?>;"><?php echo (int) $obligation_overview['active_count']; ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Ausstehend Aktive</div>
                    <div class="stat-number" style="color: <?php echo ((float) $obligation_overview['active_amount']) > 0 ? '#ff9800' : '#4caf50'; ?>; font-size: 1.8rem;"><?php echo number_format((float) $obligation_overview['active_amount'], 2, ',', '.'); ?> €</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Förderer offen</div>
                    <div class="stat-number" style="color: <?php echo ((int) $obligation_overview['supporter_count']) > 0 ? '#ff9800' : '#4caf50'; ?>;"><?php echo (int) $obligation_overview['supporter_count']; ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Ausstehend Förderer</div>
                    <div class="stat-number" style="color: <?php echo ((float) $obligation_overview['supporter_amount']) > 0 ? '#ff9800' : '#4caf50'; ?>; font-size: 1.8rem;"><?php echo number_format((float) $obligation_overview['supporter_amount'], 2, ',', '.'); ?> €</div>
                </div>
            </div>
        </div>

        <div>
            <h3 style="margin: 0 0 0.75rem 0; color: #333;">Gewählter Zeitraum</h3>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Zeitraum</div>
                    <div class="stat-number" style="font-size: 1.15rem;"><?php echo htmlspecialchars($period_label); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Transaktionen im Zeitraum</div>
                    <div class="stat-number"><?php echo $tx_count; ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Einnahmen</div>
                    <div class="stat-number" style="color: #4caf50; font-size: 1.8rem;"><?php echo number_format($total_income, 2, ',', '.'); ?> €</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Ausgaben</div>
                    <div class="stat-number" style="color: #f44336; font-size: 1.8rem;"><?php echo number_format($total_expense, 2, ',', '.'); ?> €</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Nettosaldo Zeitraum</div>
                    <div class="stat-number" style="color: <?php echo $net_total >= 0 ? '#4caf50' : '#f44336'; ?>; font-size: 1.8rem;"><?php echo number_format($net_total, 2, ',', '.'); ?> €</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <div class="card-header">
        <h2>1. Verlauf über die Zeit</h2>
    </div>
    <div class="card-body">
        <?php if (empty($timeline_rows)): ?>
            <p class="text-muted">Keine Transaktionen im gewählten Zeitraum.</p>
        <?php else: ?>
            <div style="display: grid; gap: 0.75rem;">
                <?php foreach ($timeline_rows as $row): ?>
                    <?php
                    $incomeWidth = ((float) $row['income'] / $timeline_max) * 100;
                    $expenseWidth = ((float) $row['expense'] / $timeline_max) * 100;
                    $net = (float) $row['income'] - (float) $row['expense'];
                    ?>
                    <div style="display: grid; grid-template-columns: 120px 1fr 120px; gap: 0.75rem; align-items: center;">
                        <strong><?php echo htmlspecialchars($row['period_label']); ?></strong>
                        <div>
                            <div style="height: 10px; background: #e8f5e9; border-radius: 999px; overflow: hidden; margin-bottom: 0.25rem;">
                                <div style="height: 10px; width: <?php echo max(2, $incomeWidth); ?>%; background: #43a047;"></div>
                            </div>
                            <div style="height: 10px; background: #ffebee; border-radius: 999px; overflow: hidden;">
                                <div style="height: 10px; width: <?php echo max(2, $expenseWidth); ?>%; background: #e53935;"></div>
                            </div>
                        </div>
                        <div style="text-align: right; font-size: 0.9rem;">
                            <div style="color: #2e7d32;">+<?php echo number_format((float) $row['income'], 2, ',', '.'); ?> €</div>
                            <div style="color: #c62828;">-<?php echo number_format((float) $row['expense'], 2, ',', '.'); ?> €</div>
                            <div style="font-weight: 700; color: <?php echo $net >= 0 ? '#2e7d32' : '#c62828'; ?>;">Netto: <?php echo number_format($net, 2, ',', '.'); ?> €</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <div class="card-header">
        <h2>2. Einnahmen und Ausgaben</h2>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem;">
            <div style="padding: 1rem; border-radius: 8px; background: #e8f5e9; border-left: 4px solid #43a047;">
                <div style="font-weight: 700; margin-bottom: 0.5rem;">Einnahmen</div>
                <div style="font-size: 2rem; color: #2e7d32; font-weight: 700;"><?php echo number_format($total_income, 2, ',', '.'); ?> €</div>
                <div><?php echo $tx_count > 0 ? number_format(($income_count / max(1, $tx_count)) * 100, 1, ',', '.'): '0,0'; ?>% der Buchungen</div>
            </div>
            <div style="padding: 1rem; border-radius: 8px; background: #ffebee; border-left: 4px solid #e53935;">
                <div style="font-weight: 700; margin-bottom: 0.5rem;">Ausgaben</div>
                <div style="font-size: 2rem; color: #c62828; font-weight: 700;"><?php echo number_format($total_expense, 2, ',', '.'); ?> €</div>
                <div><?php echo $tx_count > 0 ? number_format(($expense_count / max(1, $tx_count)) * 100, 1, ',', '.'): '0,0'; ?>% der Buchungen</div>
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <div class="card-header">
        <h2>3. Nach Kategorie</h2>
    </div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom: 1rem;">Diese Auswertung zeigt nur die im gewählten Zeitraum tatsächlich gebuchten Zahlungsanteile aus verknüpften Verpflichtungen und deren Kategorien. Nicht verknüpfte Transaktionen werden hier bewusst nicht berücksichtigt.</p>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
            <div style="padding: 1rem; border-radius: 8px; background: #e8f5e9; border-left: 4px solid #43a047;">
                <div style="font-weight: 700; margin-bottom: 0.35rem;">Eingänge</div>
                <div style="font-size: 1.6rem; color: #2e7d32; font-weight: 700;"><?php echo number_format($flow_income_total, 2, ',', '.'); ?> €</div>
            </div>
            <div style="padding: 1rem; border-radius: 8px; background: #ffebee; border-left: 4px solid #e53935;">
                <div style="font-weight: 700; margin-bottom: 0.35rem;">Ausgänge</div>
                <div style="font-size: 1.6rem; color: #c62828; font-weight: 700;"><?php echo number_format($flow_expense_total, 2, ',', '.'); ?> €</div>
            </div>
            <div style="padding: 1rem; border-radius: 8px; background: #f5f5f5; border-left: 4px solid #607d8b;">
                <div style="font-weight: 700; margin-bottom: 0.35rem;">Netto</div>
                <div style="font-size: 1.6rem; color: <?php echo $flow_net_total >= 0 ? '#2e7d32' : '#c62828'; ?>; font-weight: 700;"><?php echo number_format($flow_net_total, 2, ',', '.'); ?> €</div>
            </div>
        </div>
        <?php if (empty($flow_category_rows)): ?>
            <p class="text-muted">Keine kategorisierten Verpflichtungs-Zahlungen im gewählten Zeitraum vorhanden.</p>
        <?php else: ?>
            <div style="display: grid; gap: 0.85rem;">
                <?php foreach ($flow_category_rows as $row): ?>
                    <?php
                    $incomeWidth = ((float) $row['income'] / $flow_category_max) * 100;
                    $expenseWidth = ((float) $row['expense'] / $flow_category_max) * 100;
                    ?>
                    <div style="display: grid; grid-template-columns: 220px 1fr 220px; gap: 0.75rem; align-items: center;">
                        <div>
                            <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: <?php echo htmlspecialchars($row['color']); ?>; margin-right: 0.4rem;"></span>
                            <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                        </div>
                        <div>
                            <div style="height: 10px; background: #e8f5e9; border-radius: 999px; overflow: hidden; margin-bottom: 0.25rem;">
                                <div style="height: 10px; width: <?php echo $row['income'] > 0 ? max(2, $incomeWidth) : 0; ?>%; background: #43a047;"></div>
                            </div>
                            <div style="height: 10px; background: #ffebee; border-radius: 999px; overflow: hidden;">
                                <div style="height: 10px; width: <?php echo $row['expense'] > 0 ? max(2, $expenseWidth) : 0; ?>%; background: #e53935;"></div>
                            </div>
                        </div>
                        <div style="text-align: right; font-size: 0.9rem;">
                            <div style="color: #2e7d32;">+<?php echo number_format($row['income'], 2, ',', '.'); ?> €</div>
                            <div style="color: #c62828;">-<?php echo number_format($row['expense'], 2, ',', '.'); ?> €</div>
                            <div style="font-weight: 700; color: <?php echo $row['net'] >= 0 ? '#2e7d32' : '#c62828'; ?>;">Netto: <?php echo number_format($row['net'], 2, ',', '.'); ?> €</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top: 1rem;">
    <div class="card-header">
        <h2>4. Storniert / Abgelehnt</h2>
    </div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom: 1rem;">Dieser Block dient nur der Transparenz. Stornierte Forderungen und abgelehnte Erstattungen werden hier angezeigt, aber nicht in den aktiven offenen Summen berücksichtigt.</p>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
            <div style="padding: 1rem; border-radius: 8px; background: #fff3e0; border-left: 4px solid #fb8c00;">
                <div style="font-weight: 700; margin-bottom: 0.35rem;">Stornierte Forderungen</div>
                <div style="font-size: 1.6rem; color: #ef6c00; font-weight: 700;"><?php echo $transparency_cancelled_count; ?></div>
                <div><?php echo number_format($transparency_cancelled_total, 2, ',', '.'); ?> €</div>
            </div>
            <div style="padding: 1rem; border-radius: 8px; background: #fce4ec; border-left: 4px solid #d81b60;">
                <div style="font-weight: 700; margin-bottom: 0.35rem;">Abgelehnte Erstattungen</div>
                <div style="font-size: 1.6rem; color: #c2185b; font-weight: 700;"><?php echo $transparency_rejected_count; ?></div>
                <div><?php echo number_format($transparency_rejected_total, 2, ',', '.'); ?> €</div>
            </div>
        </div>
        <?php if (empty($transparency_rows)): ?>
            <p class="text-muted">Keine stornierten oder abgelehnten Verpflichtungen im gewählten Zeitraum.</p>
        <?php else: ?>
            <div style="display: grid; gap: 0.85rem;">
                <?php foreach ($transparency_rows as $row): ?>
                    <?php
                    $cancelledWidth = ((float) $row['income'] / $transparency_max) * 100;
                    $rejectedWidth = ((float) $row['expense'] / $transparency_max) * 100;
                    ?>
                    <div style="display: grid; grid-template-columns: 220px 1fr 240px; gap: 0.75rem; align-items: center;">
                        <div>
                            <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: <?php echo htmlspecialchars($row['color']); ?>; margin-right: 0.4rem;"></span>
                            <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                        </div>
                        <div>
                            <div style="height: 10px; background: #fff3e0; border-radius: 999px; overflow: hidden; margin-bottom: 0.25rem;">
                                <div style="height: 10px; width: <?php echo $row['income'] > 0 ? max(2, $cancelledWidth) : 0; ?>%; background: #fb8c00;"></div>
                            </div>
                            <div style="height: 10px; background: #fce4ec; border-radius: 999px; overflow: hidden;">
                                <div style="height: 10px; width: <?php echo $row['expense'] > 0 ? max(2, $rejectedWidth) : 0; ?>%; background: #d81b60;"></div>
                            </div>
                        </div>
                        <div style="text-align: right; font-size: 0.9rem;">
                            <div style="color: #ef6c00;">Storno: <?php echo $row['cancelled_count']; ?> · <?php echo number_format($row['income'], 2, ',', '.'); ?> €</div>
                            <div style="color: #c2185b;">Abgelehnt: <?php echo $row['rejected_count']; ?> · <?php echo number_format($row['expense'], 2, ',', '.'); ?> €</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>