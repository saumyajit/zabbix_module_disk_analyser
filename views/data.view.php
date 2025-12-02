<?php
/**
 * @var CView $this
 * @var array $data
 */

require_once __DIR__.'/../../include/hostgroups.inc.php';

$filter = $data['filter'];

// Simple filter form; mirrors Monitoring → Hosts basics: group, host, tags, status.
$filter_form = (new CForm())
    ->setMethod('get')
    ->setName('disk_analyser_filter');

$filter_table = (new CTable())
    ->setAttribute('class', ZBX_STYLE_FILTER);

// Host group selector.
$filter_table->addRow([
    _('Host groups'),
    new CMultiSelect([
        'name'          => 'filter_hostgroup[]',
        'objectName'    => 'hostGroup',
        'data'          => [],
        'selectedLimit' => 0,
        'selected'      => $filter['hostgroup']
    ])
]);

// Host name pattern.
$filter_table->addRow([
    _('Host name'),
    (new CTextBox('filter_host', $filter['host']))->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
]);

// Tags – keep it minimal for now (raw JSON or simple text will be refined later).
$filter_table->addRow([
    _('Tags'),
    (new CTextBox('filter_tag', ''))->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
]);

// Status.
$status_combo = (new CComboBox('filter_status', $filter['status']))
    ->addItem(0, _('Enabled'))
    ->addItem(1, _('Disabled'))
    ->addItem(3, _('Any'));

$filter_table->addRow([
    _('Status'),
    $status_combo
]);

$filter_form->addItem($filter_table);
$filter_form->addItem(new CSubmit('filter_set', _('Apply')));

// Main widget.
$widget = (new CWidget())
    ->setTitle(_('Disk Analyser'))
    ->setControls((new CList())->addItem($filter_form));

// Placeholder result table.
$result_table = (new CTableInfo(_('No data to display')))
    ->setHeader([
        _('Host'),
        _('Filesystem'),
        _('Total'),
        _('Used'),
        _('% Used'),
        _('Growth (per day)'),
        _('Days until full')
    ]);

// Later we will loop $data['rows'] and add rows here.
foreach ($data['rows'] as $row) {
    $result_table->addRow([
        $row['host'],
        $row['fs'],
        $row['total'],
        $row['used'],
        $row['pused'],
        $row['growth'],
        $row['days_left']
    ]);
}

$widget->addItem($result_table)->show();
