<?php
/**
 * MagDyn — Audit log
 * Created: 20260515_060024_IST
 * Updated: 20260515_113000_IST — datatable
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('audit', 'view');
require_once __DIR__ . '/includes/datatable.php';

$dtCfg = [
    'id'       => 'audit',
    'base_sql' => 'SELECT a.*, u.full_name AS actor_name, t.full_name AS target_name
                     FROM audit_log a
                     LEFT JOIN users u ON u.id = a.actor_id
                     LEFT JOIN users t ON t.id = a.target_id',
    'columns'  => [
        ['key'=>'at',          'label'=>'When',    'sortable'=>true, 'searchable'=>false, 'sql_col'=>'a.at',         'td_class'=>'nowrap'],
        ['key'=>'actor_name',  'label'=>'Actor',   'sortable'=>true, 'searchable'=>true,  'sql_col'=>'u.full_name'],
        ['key'=>'action',      'label'=>'Action',  'sortable'=>true, 'searchable'=>true,  'sql_col'=>'a.action'],
        ['key'=>'target_name', 'label'=>'Target',  'sortable'=>true, 'searchable'=>true,  'sql_col'=>'t.full_name'],
        ['key'=>'details',     'label'=>'Details', 'sortable'=>false,'searchable'=>true,  'sql_col'=>'a.details',     'td_class'=>'muted small'],
        ['key'=>'ip',          'label'=>'IP',      'sortable'=>false,'searchable'=>true,  'sql_col'=>'a.ip',          'td_class'=>'mono small'],
    ],
    'default_sort' => ['at', 'desc'],
];

$rowRenderer = function ($r) {
    return [
        'at'          => h(dt_display($r['at'])),
        'actor_name'  => h($r['actor_name'] ?: '—'),
        'action'      => '<code>' . h($r['action']) . '</code>',
        'target_name' => h($r['target_name'] ?: '—'),
        'details'     => h($r['details'] ?: ''),
        'ip'          => h($r['ip'] ?: '—'),
    ];
};

$dt = data_table_run($dtCfg, $rowRenderer);

$page_title  = 'Audit';
$page_module = 'audit';
$focus_id    = '';

$dtCfg['title'] = 'Audit log';

require __DIR__ . '/includes/header.php';
?>
<?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
