<?php

namespace App\Models;

use App\Enums\SettingGroup;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public $timestamps = false;

    protected $fillable = ['key', 'group', 'value', 'updated_by', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'group' => SettingGroup::class,
            'value' => 'json',
            'updated_at' => 'datetime',
        ];
    }
}
