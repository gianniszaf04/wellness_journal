<?php
declare(strict_types=1);

test('404 σε JSON για /api και σε HTML για τα υπόλοιπα', function (): void {
    $http = new Http();

    $api = $http->get('api/does-not-exist');
    assert_same(404, $api->status);
    assert_true(str_starts_with((string) $api->header('content-type'), 'application/json'), 'content-type API');
    assert_same(['success' => false, 'error' => 'Η σελίδα δεν βρέθηκε.'], $api->json());

    $page = $http->get('does-not-exist');
    assert_same(404, $page->status);
    assert_true(str_starts_with((string) $page->header('content-type'), 'text/html'), 'content-type σελίδας');
});

test('405 με Allow όταν υπάρχει το path με άλλη μέθοδο', function (): void {
    $http = new Http();
    $cases = [
        ['PUT', 'api/entries/5', 'GET, PATCH, DELETE, HEAD', 'application/json'],
        ['POST', 'dashboard', 'GET, HEAD', 'text/html'],
        ['DELETE', 'login', 'GET, POST, HEAD', 'text/html'],
    ];
    foreach ($cases as [$method, $path, $allow, $type]) {
        $r = $http->request($method, $path);
        assert_same(405, $r->status, "{$method} {$path}");
        assert_same($allow, $r->header('allow'), "Allow {$method} {$path}");
        assert_true(str_starts_with((string) $r->header('content-type'), $type), "content-type {$method} {$path}");
    }
});

test('HEAD απαντά όπως το GET', function (): void {
    assert_same(200, (new Http())->request('HEAD', 'login')->status);
});

test('API χωρίς σύνδεση δίνει 401', function (): void {
    assert_same(401, (new Http())->get('api/entries/5')->status);
});
