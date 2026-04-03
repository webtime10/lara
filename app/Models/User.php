<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',  // Оставляем для обратной совместимости
        'role_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Получить роль пользователя (отношение)
     */
    public function roleRelation()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Проверить, имеет ли пользователь указанную роль
     */
    public function hasRole($roleSlug)
    {
        // Проверяем через role_id (новая система)
        if ($this->role_id) {
            // Загружаем роль, если еще не загружена
            if (!$this->relationLoaded('roleRelation')) {
                $this->load('roleRelation');
            }
            if ($this->roleRelation && $this->roleRelation->slug === $roleSlug) {
                return true;
            }
        }
        
        // Fallback на старую систему (колонка role как строка)
        $roleValue = $this->attributes['role'] ?? null;
        if ($roleValue && is_string($roleValue) && $roleValue === $roleSlug) {
            return true;
        }
        
        return false;
    }

    /**
     * Проверить, является ли пользователь администратором
     */
    public function isAdmin()
    {
        return $this->hasRole('admin');
    }
}
