<?php

namespace AsimAli\Pinpoint\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Node extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function parent()
    {
        return $this->belongsTo(Node::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Node::class, 'parent_id');
    }
}
