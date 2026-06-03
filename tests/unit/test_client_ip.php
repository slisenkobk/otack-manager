<?php
use App\Http\Request;

it('clientIp returns REMOTE_ADDR when TRUSTED_PROXIES is unset', function () {
    $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $_ENV['TRUSTED_PROXIES'] = '';
    putenv('TRUSTED_PROXIES=');
    assert_eq('1.2.3.4', Request::clientIp());
});

it('clientIp ignores XFF if REMOTE_ADDR is NOT in TRUSTED_PROXIES', function () {
    $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';
    putenv('TRUSTED_PROXIES=10.0.0.0/8');
    assert_eq('8.8.8.8', Request::clientIp());
});

it('clientIp returns first XFF hop when REMOTE_ADDR is a trusted proxy', function () {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 10.0.0.5';
    $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';
    putenv('TRUSTED_PROXIES=10.0.0.0/8');
    assert_eq('198.51.100.1', Request::clientIp());
});

it('clientIp accepts multiple CIDR ranges', function () {
    $_SERVER['REMOTE_ADDR'] = '172.16.5.10';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8, 172.16.0.0/12';
    putenv('TRUSTED_PROXIES=10.0.0.0/8, 172.16.0.0/12');
    assert_eq('198.51.100.1', Request::clientIp());
});

it('clientIp rejects malformed XFF (CR/LF/header injection)', function () {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = "198.51.100.1\r\nX-Injected: evil";
    $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';
    putenv('TRUSTED_PROXIES=10.0.0.0/8');
    $ip = Request::clientIp();
    assert_true($ip === '10.0.0.5' || $ip === '198.51.100.1', "got: $ip");
});

it('clientIp accepts single IP (not CIDR) in TRUSTED_PROXIES', function () {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $_ENV['TRUSTED_PROXIES'] = '203.0.113.5';
    putenv('TRUSTED_PROXIES=203.0.113.5');
    assert_eq('198.51.100.1', Request::clientIp());
});
