<?php
// Kopiraj v config.php in izpolni. config.php NE gre v git.
return [
    'base_url'   => 'https://noc.dezman.net',
    'app_secret' => 'ZAMENJAJ-z-izhodom: php -r "echo bin2hex(random_bytes(32));"',
    'timezone'   => 'Europe/Ljubljana',
    'debug'      => false,

    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=noc;charset=utf8mb4',
        'user' => 'noc',
        'pass' => 'GESLO-BAZE',
    ],

    // Javni IP strežnika NOC – nanj routerji pošiljajo traffic-flow (UDP) in z njega pride ping
    'noc_ip'    => '109.123.4.235',
    'flow_port' => 2055,
    'flow_dir'  => '/var/lib/noc/nfcapd',     // kamor piše nfcapd
    'nfdump'    => '/usr/bin/nfdump',
    'data_dir'  => '/var/lib/noc',             // zadnji surovi push vsake naprave (diagnostika)
    'ufw_list'  => '/var/lib/noc/flow-ips.txt', // seznam IP-jev routerjev za ufw (bin/ufw-sync.sh)

    // Pošta (alarmi)
    'smtp' => [
        'host' => 'smtp3.krs.net', 'port' => 465, 'secure' => 'ssl',
        'user' => 'noc@dezman.net', 'pass' => 'GESLO',
        'from' => 'noc@dezman.net', 'from_name' => 'NOC dezman.net',
    ],
];
