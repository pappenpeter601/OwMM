<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

ensure_financial_reporting_support();

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

$category_buckets = [];
$linked_by_transaction = [];

$stmt = $db->prepare("SELECT mp.transaction_id, mp.amount,
                             mfo.category_id,
                             tc.name AS category_name,
                             tc.color AS category_color
                      FROM member_payments mp
                      INNER JOIN transactions t ON mp.transaction_id = t.id
                      LEFT JOIN member_fee_obligations mfo ON mp.obligation_id = mfo.id
                      LEFT JOIN transaction_categories tc ON mfo.category_id = tc.id
                      WHERE t.booking_date BETWEEN :date_from AND :date_to");
$stmt->execute([
    ':date_from' => $date_from,
    ':date_to' => $date_to
]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = 'cat_' . ((int) ($row['category_id'] ?? 0));
    add_report_bucket($category_buckets, $key, $row['category_name'] ?? 'Ohne Zuordnung', $row['category_color'] ?? '#78909c', 'income', $row['amount']);
    $linked_by_transaction[(int) $row['transaction_id']] = ($linked_by_transaction[(int) $row['transaction_id']] ?? 0) + (float) $row['amount'];
}

$stmt = $db->prepare("SELECT ip.transaction_id, ip.amount,
                             io.category_id,
                             tc.name AS category_name,
                             tc.color AS category_color,
                             CASE WHEN t.amount >= 0 THEN 'income' ELSE 'expense' END AS direction
                      FROM item_obligation_payments ip
                      INNER JOIN transactions t ON ip.transaction_id = t.id
                      LEFT JOIN item_obligations io ON ip.obligation_id = io.id
                      LEFT JOIN transaction_categories tc ON io.category_id = tc.id
                      WHERE t.booking_date BETWEEN :date_from AND :date_to");
$stmt->execute([
    ':date_from' => $date_from,
    ':date_to' => $date_to
]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = 'cat_' . ((int) ($row['category_id'] ?? 0));
    add_report_bucket($category_buckets, $key, $row['category_name'] ?? 'Ohne Zuordnung', $row['category_color'] ?? '#78909c', $row['direction'], $row['amount']);
    $linked_by_transaction[(int) $row['transaction_id']] = ($linked_by_transaction[(int) $row['transaction_id']] ?? 0) + (float) $row['amount'];
}

foreach ($transactions as $tx) {
    $tx_id = (int) $tx['id'];
    $amount_abs = abs((float) $tx['amount']);
    $linked_abs = (float) ($linked_by_transaction[$tx_id] ?? 0);
    $remaining = max(0, $amount_abs - $linked_abs);

    if ($remaining > 0.0001) {
        $key = 'direct_' . ((int) ($tx['category_id'] ?? 0));
        $direction = ((float) $tx['amount'] >= 0) ? 'income' : 'expense';
        $name = $tx['cat_name'] ?? 'Ohne Zuordnung';
        $color = $tx['cat_color'] ?? '#90a4ae';
        add_report_bucket($category_buckets, $key, $name, $color, $direction, $remaining);
    }
}

$category_rows = array_values($category_buckets);
foreach ($category_rows as &$row) {
    $row['net'] = $row['income'] - $row['expense'];
    $row['volume'] = $row['income'] + $row['expense'];
}
unset($row);

usort($category_rows, function ($a, $b) {
    return $b['volume'] <=> $a['volume'];
});

$category_max = 1.0;
foreach ($category_rows as $row) {
    $category_max = max($category_max, (float) $row['volume']);
}

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

<div class="summary-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
    <div class="card"><div class="card-body"><div style="color: #666;">Zeitraum</div><div style="font-size: 1.1rem; font-weight: 700;"><?php echo date('d.m.Y', strtotime($date_from)); ?> – <?php echo date('d.m.Y', strtotime($date_to)); ?></div></div></div>
    <div class="card"><div class="card-body"><div style="color: #666;">Transaktionen</div><div style="font-size: 1.8rem; font-weight: 700;"><?php echo $tx_count; ?></div></div></div>
    <div class="card"><div class="card-body"><div style="color: #666;">Einnahmen</div><div style="font-size: 1.6rem; font-weight: 700; color: #2e7d32;"><?php echo number_format($total_income, 2, ',', '.'); ?> €</div><small><?php echo $income_count; ?> Buchungen</small></div></div>
    <div class="card"><div class="card-body"><div style="color: #666;">Ausgaben</div><div style="font-size: 1.6rem; font-weight: 700; color: #c62828;"><?php echo number_format($total_expense, 2, ',', '.'); ?> €</div><small><?php echo $expense_count; ?> Buchungen</small></div></div>
    <div class="card"><div class="card-body"><div style="color: #666;">Netto</div><div style="font-size: 1.6rem; font-weight: 700; color: <?php echo $net_total >= 0 ? '#2e7d32' : '#c62828'; ?>;"><?php echo number_format($net_total, 2, ',', '.'); ?> €</div></div></div>
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

<div class="card">
    <div class="card-header">
        <h2>3. Nach Kategorie</h2>
    </div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom: 1rem;">Die Kategorie-Auswertung basiert primär auf verknüpften Verpflichtungen. Nicht verknüpfte Restbeträge werden als direkte oder unzugeordnete Transaktionen berücksichtigt.</p>
        <?php if (empty($category_rows)): ?>
            <p class="text-muted">Keine Kategorien im gewählten Zeitraum vorhanden.</p>
        <?php else: ?>
            <div style="display: grid; gap: 0.85rem;">
                <?php foreach ($category_rows as $row): ?>
                    <?php $barWidth = (($row['volume']) / $category_max) * 100; ?>
                    <div style="display: grid; grid-template-columns: 220px 1fr 220px; gap: 0.75rem; align-items: center;">
                        <div>
                            <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: <?php echo htmlspecialchars($row['color']); ?>; margin-right: 0.4rem;"></span>
                            <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                        </div>
                        <div style="height: 12px; background: #eceff1; border-radius: 999px; overflow: hidden;">
                            <div style="height: 12px; width: <?php echo max(2, $barWidth); ?>%; background: <?php echo htmlspecialchars($row['color']); ?>;"></div>
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

<?php include 'includes/footer.php'; ?>