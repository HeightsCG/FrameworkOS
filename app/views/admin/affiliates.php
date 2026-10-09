<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head"><div><h1 class="adm-head__title">Affiliates</h1><p class="adm-head__sub">Applications, the commissions ledger and payout requests</p></div></header>
<?php include __DIR__ . '/_affiliates.php'; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin-affiliates.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-affiliates.js'); ?>"></script>
