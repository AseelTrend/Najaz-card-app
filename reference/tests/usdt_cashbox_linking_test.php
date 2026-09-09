<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$cashbox = file_get_contents($root . '/includes/cashbox_helper.php');
$usdt = file_get_contents($root . '/includes/usdt_deposit.php');
$admin = file_get_contents($root . '/admin/usdt_deposits.php');

$start = strpos($cashbox, 'function cashboxPostUsdtDeposit');
$end = strpos($cashbox, 'function cashboxTransfer', $start === false ? 0 : $start);
$usdtFn = ($start !== false) ? substr($cashbox, $start, $end !== false ? $end - $start : null) : '';

$checks = [
    'cashbox helper exists' => $start !== false,
    'USDT tracking columns' => strpos($cashbox, "'usdt_deposit_requests'") !== false
        && strpos($cashbox, "'cashbox_journal_id'") !== false
        && strpos($cashbox, "'cashbox_movement_id'") !== false,
    'USDT movement idempotency' => strpos($usdtFn, "'usdt-deposit:' . " . '$requestId') !== false,
    'USDT reference type' => strpos($usdtFn, "'reference_type' => 'usdt_bep20'") !== false,
    'USDT request reference' => strpos($usdtFn, "'reference_id' => 'USDT#' . " . '$requestId') !== false,
    'USD customer wallet counterpart' => strpos($usdtFn, "code='1100'") !== false
        && strpos($usdtFn, "currency_code='USD'") !== false,
    'atomic posting call' => strpos($usdt, 'cashboxPostUsdtDeposit(') !== false
        && strpos($usdt, '$pdo->commit();') !== false,
    'cashbox required on new request' => strpos($usdt, 'cashbox_posting_status') !== false
        && strpos($usdt, 'CASHBOX_NOT_CONFIGURED') !== false,
    'admin setting key' => strpos($admin, "'usdt_cashbox_id'") !== false,
    'admin selector' => strpos($admin, 'name="usdt_cashbox_id"') !== false,
    'admin validates active USD box' => strpos($admin, "c.status='active'") !== false
        && strpos($admin, "c.currency_code='USD'") !== false,
    'no topup idempotency collision in USDT function' => strpos($usdtFn, "'topup-deposit:'") === false,
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}

if ($failed) {
    fwrite(STDERR, "FAILED\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo 'PASS: ' . count($checks) . " USDT cashbox-linking checks\n";
