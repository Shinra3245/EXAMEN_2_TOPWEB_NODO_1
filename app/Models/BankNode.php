<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class BankNode extends Model
{
    use HasUuids;

    protected $table = 'bank_nodes';
    
    // Supabase created_at is handled by DB default, but Eloquent will try to set it.
    public $timestamps = false; // We can handle it manually or disable updated_at

    protected $fillable = [
        'nombre',
        'tipo', // 'sucursal', 'cajero'
        'responsable',
        'api_key_hash',
        'efectivo_disponible',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'efectivo_disponible' => 'decimal:2',
    ];
}
