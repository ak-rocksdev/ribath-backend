<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'is_active',
        'must_change_password',
        'school_id',
    ];

    /** A new account is not required to change its password unless "Beri Akses" or a reset says so. */
    protected $attributes = [
        'must_change_password' => false,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function guardianStudents(): HasMany
    {
        return $this->hasMany(Student::class, 'guardian_user_id');
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    /**
     * The id of the Ustadz linked to this account, or null when none is
     * linked or his status is nonaktif: an Ustadz who has left gives the
     * account no Cakupan Mengajar and no Jadwal Saya, also when the account
     * itself is still active (linked before status nonaktif deactivated
     * accounts, or reactivated by hand). Status cuti keeps the link.
     */
    public function activeLinkedTeacherId(): ?string
    {
        $linkedTeacher = $this->teacher;

        return $linkedTeacher !== null && $linkedTeacher->status !== Teacher::STATUS_INACTIVE
            ? $linkedTeacher->id
            : null;
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
