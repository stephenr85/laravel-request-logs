<?php

namespace Rushing\RequestLogs\Tests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Rushing\RequestLogs\Models\HasRequestLogs;

class TestModel extends Model
{
    use HasRequestLogs, HasUuids;

    protected $table = 'test_models';

    protected $fillable = ['name'];
}
