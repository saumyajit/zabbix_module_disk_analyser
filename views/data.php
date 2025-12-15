<?php
/**
 * @var CView $this
 * @var array $data
 * @var array $data['diskData']
 * @var array $data['summary']
 * @var callable $data['formatBytes']
 */

namespace Modules\diskanalyser\views;
// Use Zabbix component classes
use Zabbix\Widgets\CWidget;
use Zabbix\Widgets\CTableInfo;

// Extract variables for cleaner code
$diskData = $data['diskData'];
$summary = $data['summary'];
$formatBytes = $data['formatBytes'];

// 1. Start the main page content (CView is already handled by Zabbix framework)

// --- Storage Growth Analysis Header ---
echo '<div class="header">
    <h1>' . _('Storage Growth Analysis') . '</h1>
    <p>' . _('Monitor disk space usage and predict storage capacity needs') . '</p>
</div>';

// --- Summary Boxes (KPIs) ---
$summaryDiv = (new CDiv())
    ->addStyle('display: flex; gap: 20px; margin-bottom: 30px; text-align: center; justify-content: space-around;');

$kpis = [
    _('Total Storage') => $summary['totalStorage'],
    _('Used Storage') => $summary['usedStorage'],
    _('Average Daily Growth') => $summary['avgGrowth']
];

foreach ($kpis as $label => $value) {
    $summaryDiv->addItem(
        (new CDiv([
            new CTag('h2', true, $label),
            (new CTag('div', true, $value))->addStyle('font-size: 28px; font-weight: 600;')
        ]))->addStyle('flex: 1; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 4px;')
    );
}

echo $summaryDiv;


// 2. --- Top Risky Filesystems Widget ---
$riskyWidget = (new CWidget())
    ->setTitle(_('Top Risky Filesystems'))
    ->addStyle('margin-bottom: 20px;');

$riskyTable = (new CTableInfo())
    ->setHeader([_('Host / Filesystem'), _('Usage %'), _('Days Until Full'), _('Suggestion')]);

if (empty($summary['riskyFilesystems'])) {
    $riskyTable->addRow([_('No filesystems detected with positive growth or high usage.')], 'note');
} else {
    foreach ($summary['riskyFilesystems'] as $row) {
        $suggestion = match(true) {
            $row['growthRate'] > 3 && $row['usagePct'] > 70 => _('IMMEDIATE action: Reallocate or Clean.'),
            $row['growthRate'] > 1 && $row['usagePct'] > 50 => _('Review capacity plan.'),
            default => _('-')
        };

        $riskyTable->addRow([
            $row['host'] . ' / ' . $row['mount'],
            $row['usagePct'] . '%',
            $row['daysUntilFull'],
            $suggestion
        ], $row['fsCritical'] > 0 ? 'critical' : ($row['fsWarnings'] > 0 ? 'warning' : ''));
    }
}
$riskyWidget->addItem($riskyTable);
$riskyWidget->show();


// 3. --- Detailed Host Analysis Widget ---
$detailedWidget = (new CWidget())
    ->setTitle(_('Detailed Host Analysis'));

$detailedTable = (new CTableInfo())
    ->setHeader([
        _('Host'), _('Mount'), _('Total Space'), _('Used Space'), 
        _('Usage %'), _('Growth Rate (GB/day)'), _('Days Until Full'), 
        _('FS Warnings'), _('FS Critical'), _('Details')
    ]);

foreach ($diskData as $row) {
    $rowClass = $row['fsCritical'] > 0 ? 'critical' : ($row['fsWarnings'] > 0 ? 'warning' : '');

    $detailedTable->addRow([
        $row['host'],
        $row['mount'],
        $row['totalSpace'],
        $row['usedSpace'],
        $row['usagePct'] . '%',
        $row['growthRate'],
        $row['daysUntilFull'],
        $row['fsWarnings'],
        $row['fsCritical'],
        (new CTag('button', true, _('Details')))->addStyle('min-width: 60px;')->addClass('button button-small')
    ], $rowClass);
}

$detailedWidget->addItem($detailedTable);
$detailedWidget->show();
