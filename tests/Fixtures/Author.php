<?php

namespace AsimAli\Pinpoint\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Author extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function books()
    {
        return $this->belongsToMany(Book::class, 'author_book');
    }
}
