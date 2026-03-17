<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable
{
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
