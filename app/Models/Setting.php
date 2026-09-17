<?php

namespace App\Models;

use App\Enums\SettingGroup;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

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
