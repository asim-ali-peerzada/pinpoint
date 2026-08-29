<?php

namespace AsimAli\Pinpoint\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Stage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function package()
    {
        return $this->belongsTo(CloseoutPackage::class);
    }

    public function photos()
    {
        return $this->hasMany(Photo::class);
    }
}
