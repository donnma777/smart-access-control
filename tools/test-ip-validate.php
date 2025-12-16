<?php
// Simple test script to demonstrate IP/CIDR validation logic used in sanitize_ip_range_definitions
function simple_validate_ip_cidr($range) {
    $range = trim($range);
    if (empty($range)) return false;

    if (filter_var($range, FILTER_VALIDATE_IP)) {
        return $range;
    }

    if (strpos($range, '/') !== false) {
        list($ip, $mask) = explode('/', $range, 2);
        $mask = intval($mask);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $mask >= 0 && $mask <= 32) {
            return $range;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $mask >= 0 && $mask <= 128) {
            return $range;
        }
    }

    return false;
}

$tests = [
    '192.168.1.1',
    '2001:db8::1',
    '192.168.0.0/16',
    '2001:db8::/32',
    'invalid',
    '192.168.1.1/33',
    '192.168.1.1 - 192.168.1.255',
];

foreach ($tests as $t) {
    $res = simple_validate_ip_cidr($t);
    echo str_pad($t, 30) . ' => ' . var_export($res, true) . PHP_EOL;
}
