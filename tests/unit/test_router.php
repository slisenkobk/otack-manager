<?php
use App\Routing\Router;

it('matches static path', function () {
    $r = new Router();
    $r->get('/login', 'Auth@loginForm');
    $m = $r->match('GET', '/login');
    assert_eq('Auth', $m['controller']);
    assert_eq('loginForm', $m['action']);
    assert_eq([], $m['params']);
});

it('matches param path and captures int', function () {
    $r = new Router();
    $r->get('/tasks/{id}', 'Task@show');
    $m = $r->match('GET', '/tasks/42');
    assert_eq('42', $m['params']['id']);
});

it('returns null on miss', function () {
    $r = new Router();
    $r->get('/x', 'A@b');
    assert_eq(null, $r->match('GET', '/y'));
});

it('distinguishes GET and POST', function () {
    $r = new Router();
    $r->get('/p', 'A@g');
    $r->post('/p', 'A@p');
    assert_eq('g', $r->match('GET', '/p')['action']);
    assert_eq('p', $r->match('POST', '/p')['action']);
});
