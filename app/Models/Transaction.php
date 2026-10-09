<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Transaction extends Model
{
    use HasUuids;

    protected $table = 'transactions';
    public $timestamps = false;

    protected $fillable = [
        'nodo_id',
        'cuenta_origen',
        'cuenta_destino',
        'monto',
        'tipo',
        'idempotency_key',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
    ];
}
