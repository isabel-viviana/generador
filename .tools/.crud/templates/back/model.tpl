<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
{{USE_SOFT_DELETES}}
{{USE_RELATIONS}}
class {{CLASS_NAME}} extends Model
{
{{TABLE_PROPERTY}}{{KEY_PROPERTIES}}{{TIMESTAMPS_PROPERTY}}{{SOFT_DELETES_TRAIT}}    protected $fillable = [
{{FILLABLE}}
    ];
{{CASTS}}
{{RELATIONS}}
}
