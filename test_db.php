<?php
$hosts = ['db.rtfdnrwcjwovpplmfthc.supabase.co', 'aws-0-us-east-1.pooler.supabase.com'];
$password = '9u2GBjxvjBnpbB44zzISaPdawNfQq8qTr0ToI4uvJ_4';
foreach ($hosts as $host) {
    try {
        echo "Testing $host... ";
        $dsn = "pgsql:host=$host;port=6543;dbname=postgres";
        if ($host === 'db.rtfdnrwcjwovpplmfthc.supabase.co') {
            $dsn = "pgsql:host=$host;port=5432;dbname=postgres";
        }
        $pdo = new PDO($dsn, 'postgres.rtfdnrwcjwovpplmfthc', $password, [PDO::ATTR_TIMEOUT => 3]);
        echo "SUCCESS\n";
        break;
    } catch (Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
}
