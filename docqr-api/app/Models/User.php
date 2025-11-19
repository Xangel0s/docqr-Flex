<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class User extends Model
{
    use HasApiTokens;
    protected $fillable = [
        'username',
        'email',
        'password',
        'name',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    /**
     * Verificar contraseña
     */
    public function checkPassword(string $password): bool
    {
        return Hash::check($password, $this->password);
    }

    /**
     * Verificar si es admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin' && $this->is_active;
    }
}
