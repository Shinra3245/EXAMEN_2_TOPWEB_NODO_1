<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class UserAccount extends Model
{
    use HasUuids;

    protected $table = 'users_accounts';
    public $timestamps = false;

    protected $fillable = [
        'numero_cuenta',
        'nombre_titular',
        'saldo_global',
        'estado',
        'sucursal_id',
    ];

    protected $casts = [
        'saldo_global' => 'decimal:2',
    ];
}
