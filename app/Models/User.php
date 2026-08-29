<?php

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['nom', 'prenom', 'identifiant', 'email', 'password', 'telephone', 'photo', 'pin_code', 'statut', 'last_login_at', 'created_by', 'updated_by'])]
#[Hidden(['password', 'remember_token', 'pin_code'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    protected $guard_name = 'api';

    protected $fillable = [
        'nom',
        'prenom',
        'identifiant',
        'email',
        'password',
        'telephone',
        'photo',
        'pin_code',
        'statut',
        'last_login_at',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pin_code',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Sites auxquels l'utilisateur est rattaché. Le périmètre métier de
     * l'utilisateur est défini au niveau du site (cuisine/stock/budget partagés),
     * pas du point de vente.
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'user_site');
    }

    /**
     * Les postes de production que cette personne tient.
     *
     * Réciproque de {@see PosteCuisine::membres()}. En être détenteur d'au moins
     * un fait de quelqu'un un membre de la cuisine du point de vente concerné.
     */
    public function postesCuisine(): BelongsToMany
    {
        return $this->belongsToMany(
            PosteCuisine::class,
            'poste_cuisine_user',
            'user_id',
            'poste_cuisine_id',
        );
    }

    /**
     * Périmètre effectif de sites (actifs) de l'utilisateur : ses sites de
     * rattachement, ou **tous les sites actifs** pour le super-admin, qui n'est
     * borné par aucun périmètre (cohérent avec les Policies et le reporting).
     * Alimente le sélecteur de site et le payload de connexion.
     *
     * @return Collection<int, Site>
     */
    public function sitesPerimetre(): Collection
    {
        $query = $this->isSuperAdmin()
            ? Site::query()
            : $this->sites();

        return $query
            ->where('statut', Statut::Actif->value)
            ->orderBy('nom')
            ->get();
    }

    // Connexion par identifiant au lieu de email
    public function getAuthIdentifierName(): string
    {
        return 'identifiant';
    }

    public function isActif(): bool
    {
        return $this->statut === 'actif';
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    /**
     * Niveau de sécurité le plus élevé parmi les rôles de l'utilisateur (0 sinon).
     * Pivot de la hiérarchie anti-escalade.
     */
    public function niveauMax(): int
    {
        return (int) $this->roles->max('niveau');
    }

    /**
     * Le site fait-il partie du périmètre opérationnel de l'utilisateur ?
     * Le super-admin n'est borné par aucun périmètre, ici comme ailleurs.
     */
    public function appartientAuSite(?int $siteId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $siteId !== null && $this->sites()->whereKey($siteId)->exists();
    }

    /**
     * L'utilisateur partage-t-il au moins un site (périmètre) avec un autre ?
     */
    public function partageSiteAvec(User $autre): bool
    {
        return $this->sites->pluck('id')
            ->intersect($autre->sites->pluck('id'))
            ->isNotEmpty();
    }
}
