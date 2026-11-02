<?php
require __DIR__ . '/includes/bootstrap.php';
requireLogin();

$expenses = loadExpenses();
$filters = getFilters($_GET);
$finalList = sortExpenses(applyFilters($expenses, $filters));

// --- Totals for the selected period ---
$currentTotalAmount = array_sum(array_column($finalList, 'amount'));
$currentTotalCount = count($finalList);
$averageExpense = $currentTotalCount > 0 ? ($currentTotalAmount / $currentTotalCount) : 0;

$categoryTotals = [];
foreach ($finalList as $expense) {
    $categoryTotals[$expense['category']] = ($categoryTotals[$expense['category']] ?? 0) + $expense['amount'];
}
arsort($categoryTotals);
$topCategory = $categoryTotals ? array_key_first($categoryTotals) : null;

// Places used before, for the autocomplete list
$knownPlaces = array_values(array_unique(array_filter(array_map('trim', array_column($expenses, 'place')))));
sort($knownPlaces);

$periodLabel = t('period_' . $filters['period']);
if ($filters['period'] === 'month') {
    $periodLabel = t('months_short')[(int)substr($filters['from'], 5, 2) - 1] . ' ' . substr($filters['from'], 0, 4);
} elseif ($filters['period'] === 'custom') {
    $periodLabel = ($filters['from'] ? formatDate($filters['from']) : '…') . ' – ' . ($filters['to'] ? formatDate($filters['to']) : '…');
}

// --- Pagination ---
$expensesPerPage = 30;
$totalPages = (int)ceil($currentTotalCount / $expensesPerPage);
$currentPage = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, min($currentPage, max(1, $totalPages)));
$expensesForPage = array_slice($finalList, ($currentPage - 1) * $expensesPerPage, $expensesPerPage);

$periods = ['30days', 'thismonth', 'lastmonth', 'month', 'custom', 'all'];

// Strings used by script.js
$jsStrings = [];
foreach (['currency', 'daily_spending', 'total', 'no_chart_data', 'sending', 'sent', 'check_inbox', 'close',
          'error', 'went_wrong', 'enter_pin', 'confirm_delete'] as $key) {
    $jsStrings[$key] = t($key);
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(t('app_title')) ?></title>
    <link rel="icon" type="image/x-icon" href="https://i.postimg.cc/SsfMBzwf/romio001.png">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php include __DIR__ . '/includes/theme-head.php'; ?>
    <link rel="stylesheet" href="assets/css/style.css?v=3">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="container">
        <header>
            <div class="topbar">
                <div class="user-info">
                    <?= e(t('welcome', $_SESSION['username'])) ?>
                </div>
                <div class="topbar-actions">
                    <a class="icon-btn" href="<?= e(urlWith(['lang' => $lang === 'ar' ? 'en' : 'ar'])) ?>"><i class="fas fa-language"></i> <?= e(t('switch_lang')) ?></a>
                    <button type="button" class="icon-btn" id="themeToggle" aria-label="<?= e(t('toggle_theme')) ?>" title="<?= e(t('toggle_theme')) ?>"><i class="fas fa-moon"></i></button>
                    <a class="icon-btn" href="logout.php"><i class="fas fa-sign-out-alt"></i> <?= e(t('logout')) ?></a>
                </div>
            </div>
            <h1 class="reveal"><i class="fas fa-wallet"></i> <?= e(t('app_title')) ?></h1>
            <p class="subtitle reveal" style="--i:1"><?= e(t('subtitle')) ?></p>
        </header>

        <div class="dashboard">
            <a class="summary-card total reveal" style="--i:1; text-decoration:none; cursor:pointer"
               href="<?= e(urlWith(['period' => $filters['period'] === 'all' ? '30days' : 'all', 'showAll' => null, 'page' => null])) ?>#history">
                <h3><?= e(t('total_expenses')) ?></h3>
                <p class="amount"><?= e(t('currency')) ?> <span data-count="<?= $currentTotalAmount ?>"><?= number_format($currentTotalAmount, 2) ?></span></p>
                <p class="info"><?= e($periodLabel) ?></p>
            </a>

            <div class="summary-card average reveal" style="--i:2">
                <h3><?= e(t('average')) ?></h3>
                <p class="amount"><?= e(t('currency')) ?> <span data-count="<?= $averageExpense ?>"><?= number_format($averageExpense, 2) ?></span></p>
                <p class="info"><?= e(t('per_expense')) ?> · <?= e($periodLabel) ?></p>
            </div>

            <div class="summary-card categories reveal" style="--i:3">
                <h3><?= e(t('transactions')) ?></h3>
                <p class="amount"><span data-count="<?= $currentTotalCount ?>" data-decimals="0"><?= $currentTotalCount ?></span></p>
                <p class="info"><?= $topCategory ? e(t('top_category', categoryLabel($topCategory))) : e(t('no_data')) ?></p>
            </div>
        </div>

        <div class="main-content">
            <div class="form-section panel reveal" style="--i:4">
                <h2><i class="fas fa-plus-circle"></i> <?= e(t('add_expense')) ?></h2>
                <form id="expenseForm" method="post" action="process.php">
                    <div class="form-group">
                        <label for="description"><?= e(t('description')) ?></label>
                        <input type="text" id="description" name="description" required placeholder="<?= e(t('description_ph')) ?>">
                    </div>

                    <div class="form-group">
                        <label for="place"><?= e(t('place')) ?></label>
                        <input type="text" id="place" name="place" list="placesList" maxlength="100" placeholder="<?= e(t('place_ph')) ?>">
                        <datalist id="placesList">
                            <?php foreach ($knownPlaces as $place): ?>
                                <option value="<?= e($place) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount"><?= e(t('amount')) ?></label>
                            <input type="number" id="amount" name="amount" min="0.01" step="0.01" required placeholder="0.00">
                        </div>

                        <div class="form-group">
                            <label for="date"><?= e(t('date')) ?></label>
                            <input type="date" id="date" name="date" required value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="category"><?= e(t('category')) ?></label>
                        <select id="category" name="category" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= e($category) ?>"><?= e(categoryLabel($category)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> <?= e(t('save_expense')) ?>
                    </button>
                </form>
            </div>

            <div class="chart-section panel reveal" style="--i:5">
                <h2><i class="fas fa-chart-pie"></i> <?= e(t('overview')) ?></h2>
                <div class="chart-container">
                    <canvas id="expenseChart"></canvas>
                    <div class="chart-empty" id="chartEmpty"><?= e(t('no_chart_data')) ?></div>
                </div>
                <div class="chart-toggles">
                    <button class="chart-btn active" data-chart-type="pie"><i class="fas fa-chart-pie"></i> <?= e(t('chart_pie')) ?></button>
                    <button class="chart-btn" data-chart-type="bar"><i class="fas fa-chart-bar"></i> <?= e(t('chart_bar')) ?></button>
                    <button class="chart-btn" data-chart-type="line"><i class="fas fa-chart-line"></i> <?= e(t('chart_line')) ?></button>
                </div>
            </div>
        </div>

        <div class="recent-expenses panel reveal" style="--i:6" id="history">
            <div class="recent-expenses-header">
                <h2><i class="fas fa-history"></i> <?= e(t('transactions_history')) ?></h2>
                <div class="btn-group-header">
                    <button class="email-btn" onclick="openReportModal()">
                        <i class="fas fa-envelope"></i> <?= e(t('email_report')) ?>
                    </button>
                    <button class="export-btn" onclick="exportWithPIN()">
                        <i class="fas fa-file-excel"></i> <?= e(t('export_excel')) ?>
                    </button>
                </div>
            </div>

            <form class="filters" id="filtersForm" method="get" action="#history">
                <div class="form-group">
                    <label for="period"><?= e(t('period')) ?></label>
                    <select id="period" name="period">
                        <?php foreach ($periods as $period): ?>
                            <option value="<?= $period ?>" <?= $filters['period'] === $period ? 'selected' : '' ?>><?= e(t('period_' . $period)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" data-show-for="month">
                    <label for="month"><?= e(t('month')) ?></label>
                    <input type="month" id="month" name="month" value="<?= e($filters['month']) ?>">
                </div>
                <div class="form-group" data-show-for="custom">
                    <label for="from"><?= e(t('from')) ?></label>
                    <input type="date" id="from" name="from" value="<?= e($_GET['from'] ?? '') ?>">
                </div>
                <div class="form-group" data-show-for="custom">
                    <label for="to"><?= e(t('to')) ?></label>
                    <input type="date" id="to" name="to" value="<?= e($_GET['to'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="filterCategory"><?= e(t('category')) ?></label>
                    <select id="filterCategory" name="category">
                        <option value=""><?= e(t('all_categories')) ?></option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e($category) ?>" <?= $filters['category'] === $category ? 'selected' : '' ?>><?= e(categoryLabel($category)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="q"><?= e(t('search')) ?></label>
                    <input type="search" id="q" name="q" value="<?= e($filters['search']) ?>" placeholder="<?= e(t('search_ph')) ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-submit"><i class="fas fa-filter"></i> <?= e(t('apply')) ?></button>
                    <a href="index.php#history" class="btn-ghost"><?= e(t('reset')) ?></a>
                </div>
            </form>

            <p class="history-summary"><?= e(t('showing', $currentTotalCount, $periodLabel)) ?></p>

            <div class="expenses-list">
                <?php if (empty($expensesForPage)): ?>
                    <p class="no-expenses"><?= e(t('no_expenses')) ?></p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th><?= e(t('date')) ?></th>
                                    <th><?= e(t('description')) ?></th>
                                    <th><?= e(t('place')) ?></th>
                                    <th><?= e(t('category')) ?></th>
                                    <th><?= e(t('amount')) ?></th>
                                    <th><?= e(t('action')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expensesForPage as $i => $expense): ?>
                                    <tr style="--i:<?= min($i, 20) ?>">
                                        <td class="date-cell"><?= e(formatDate($expense['date'])) ?></td>
                                        <td><?= e($expense['description']) ?></td>
                                        <td class="place"><?php if (!empty($expense['place'])): ?><i class="fas fa-location-dot"></i><?= e($expense['place']) ?><?php else: ?>—<?php endif; ?></td>
                                        <td><span class="category-tag" style="background-color: <?= getCategoryColor($expense['category']) ?>"><?= e(categoryLabel($expense['category'])) ?></span></td>
                                        <td class="amount"><?= e(formatMoney($expense['amount'])) ?></td>
                                        <td>
                                            <button class="delete-btn" data-id="<?= e($expense['id']) ?>" aria-label="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($currentPage > 1): ?>
                        <a href="<?= e(urlWith(['page' => $currentPage - 1])) ?>#history"><i class="fas fa-arrow-left"></i> <?= e(t('previous')) ?></a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="<?= e(urlWith(['page' => $i])) ?>#history" class="<?= $i == $currentPage ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="<?= e(urlWith(['page' => $currentPage + 1])) ?>#history"><?= e(t('next')) ?> <i class="fas fa-arrow-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="reportModal" class="modal-overlay">
        <div class="modal-content">
            <h2><i class="fas fa-paper-plane"></i> <?= e(t('send_report')) ?></h2>
            <p><?= e(t('select_period')) ?></p>

            <button class="modal-btn" onclick="sendReport('last3days')"><?= e(t('last_3_days')) ?></button>
            <button class="modal-btn" onclick="sendReport('last7days')"><?= e(t('last_7_days')) ?></button>
            <button class="modal-btn" onclick="sendReport('last30days')"><?= e(t('last_30_days')) ?></button>

            <div class="modal-close" onclick="closeReportModal()"><?= e(t('cancel')) ?></div>
        </div>
    </div>

    <script>
        window.APP = {
            lang: <?= json_encode($lang) ?>,
            i18n: <?= json_encode($jsStrings, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="assets/js/theme.js?v=3"></script>
    <script src="assets/js/script.js?v=3"></script>
</body>
</html>
