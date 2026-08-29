<?php

namespace AsimAli\Pinpoint\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
