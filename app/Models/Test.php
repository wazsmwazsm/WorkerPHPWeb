<?php

namespace App\Models;

use Framework\DB\Model;

class Test extends Model
{
    protected $connection = 'con2';

    protected $table = 'ad_promote_collect';
}
