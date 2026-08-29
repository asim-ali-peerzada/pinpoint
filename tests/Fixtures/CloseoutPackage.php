<?php

namespace AsimAli\Pinpoint\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class CloseoutPackage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function stages()
    {
        return $this->hasMany(Stage::class);
    }
}
