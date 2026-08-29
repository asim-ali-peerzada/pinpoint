<?php

use AsimAli\Pinpoint\QueryFingerprinter;

test('identical sql hashes identically', function () {
    expect(QueryFingerprinter::hash('select * from users where id = ?'))
        ->toBe(QueryFingerprinter::hash('select * from users where id = ?'));
});

test('in lists of different lengths match', function () {
    $three = 'select * from users where id in (?, ?, ?)';
    $eight = 'select * from users where id in (?, ?, ?, ?, ?, ?, ?, ?)';

    expect(QueryFingerprinter::hash($three))
        ->toBe(QueryFingerprinter::hash($eight));
});

test('single placeholders are not collapsed away', function () {
    $single = 'select * from users where id = ? and active = ?';

    expect(QueryFingerprinter::hash($single))
        ->not->toBe(QueryFingerprinter::hash('select * from users where id = ?'));
});

test('different queries do not match', function () {
    expect(QueryFingerprinter::hash('select * from users where id = ?'))
        ->not->toBe(QueryFingerprinter::hash('select * from posts where id = ?'));
});

test('joins are preserved', function () {
    expect(QueryFingerprinter::hash('select * from users join posts on posts.user_id = users.id where users.id = ?'))
        ->toBe(QueryFingerprinter::hash('select * from users join posts on posts.user_id = users.id where users.id = ?'));
});
