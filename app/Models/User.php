<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'branch_id',
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Resolve the branch ID for this user.
     * Checks user.branch_id first, falls back to employee.branch_id.
     */
    public function resolveBranchId(): ?int
    {
        return $this->branch_id ?? $this->employee?->branch_id;
    }

    /** The branch resolveBranchId() points at, if any. */
    public function resolveBranch(): ?Branch
    {
        $branchId = $this->resolveBranchId();

        return $branchId === null ? null : Branch::find($branchId);
    }

    /** The `owner` role: every branch, every tab, the Branches and Users pages. */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('owner');
    }

    /** Runs one branch from the website and the POS, locked to that branch. */
    public function isBranchAdmin(): bool
    {
        return ! $this->isSuperAdmin() && $this->hasRole('admin');
    }

    /** May use the website's management tabs (a super admin or a branch admin). */
    public function canManage(): bool
    {
        return $this->hasAnyRole(['owner', 'admin']);
    }

    /**
     * The one branch this user is confined to, or null for the super admin, who
     * sees every branch. A branch admin's comes from users.branch_id.
     */
    public function lockedBranchId(): ?int
    {
        return $this->isSuperAdmin() ? null : $this->resolveBranchId();
    }

    /** How the role reads on screen. */
    public function roleLabel(): string
    {
        return match (true) {
            $this->isSuperAdmin() => 'Super Admin',
            $this->hasRole('admin') => 'Admin',
            $this->hasRole('cashier') => 'Cashier',
            default => 'No role',
        };
    }

    /**
     * Whether this user may read or write $branchId's records. Owners run every
     * branch; anyone else is confined to their own, and a user with no branch
     * has none.
     */
    public function canAccessBranch(int $branchId): bool
    {
        if ($this->hasRole('owner')) {
            return true;
        }

        $ownBranchId = $this->resolveBranchId();

        return $ownBranchId !== null && (int) $ownBranchId === $branchId;
    }
}
