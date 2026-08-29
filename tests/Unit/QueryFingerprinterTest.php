<?php

namespace AsimAli\Pinpoint\Tests\Unit;

use AsimAli\Pinpoint\QueryFingerprinter;
use PHPUnit\Framework\TestCase;

class QueryFingerprinterTest extends TestCase
{
    public function test_identical_sql_hashes_identically(): void
    {
        $this->assertSame(
            QueryFingerprinter::hash('select * from users where id = ?'),
            QueryFingerprinter::hash('select * from users where id = ?')
        );
    }

    public function test_in_lists_of_different_lengths_match(): void
    {
        $three = 'select * from users where id in (?, ?, ?)';
        $eight = 'select * from users where id in (?, ?, ?, ?, ?, ?, ?, ?)';

        $this->assertSame(
            QueryFingerprinter::hash($three),
            QueryFingerprinter::hash($eight)
        );
    }

    public function test_single_placeholder_is_not_collapsed_away(): void
    {
        $single = 'select * from users where id = ? and active = ?';

        $this->assertNotSame(
            QueryFingerprinter::hash($single),
            QueryFingerprinter::hash('select * from users where id = ?')
        );
    }

    public function test_different_queries_do_not_match(): void
    {
        $this->assertNotSame(
            QueryFingerprinter::hash('select * from users where id = ?'),
            QueryFingerprinter::hash('select * from posts where id = ?')
        );
    }

    public function test_joins_are_preserved(): void
    {
        $this->assertSame(
            QueryFingerprinter::hash('select * from users join posts on posts.user_id = users.id where users.id = ?'),
            QueryFingerprinter::hash('select * from users join posts on posts.user_id = users.id where users.id = ?')
        );
    }
}