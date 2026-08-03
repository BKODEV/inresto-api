<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutTable;
use Database\Factories\TableSalleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Table physique d'un point de vente. Le `statut` est piloté par les modules
 * commandes/réservations (Lot C) ; ici, changement manuel uniquement.
 */
class TableSalle extends Model
{
    /** @use HasFactory<TableSalleFactory> */
    use HasFactory;

    protected $table = 'tables_salle';

    protected $fillable = [
        'point_de_vente_id',
        'numero',
        'capacite',
        'statut',
        'public_token',
    ];

    protected $attributes = [
        'statut' => StatutTable::Libre->value,
    ];

    protected $casts = [
        'capacite' => 'integer',
        'statut' => StatutTable::class,
    ];

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class);
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(CommandeClient::class, 'table_id');
    }

    /**
     * Vrai dès qu'au moins une commande client (quel que soit son statut, y
     * compris l'historique) est rattachée à la table. Bloque sa suppression :
     * on préserve la traçabilité des ventes passées comme des commandes en cours.
     */
    public function aDesCommandes(): bool
    {
        return $this->commandes()->exists();
    }

    /**
     * Jeton public opaque, non devinable et sans lien avec l'id interne : il est
     * imprimé sur le QR de la table et constitue la seule clé d'accès au menu
     * public. La boucle garantit l'unicité même en cas de collision improbable.
     */
    public static function genererPublicToken(): string
    {
        do {
            $token = Str::random(32);
        } while (static::query()->where('public_token', $token)->exists());

        return $token;
    }

    /**
     * URL publique complète du menu de cette table (celle encodée dans le QR),
     * construite depuis la base configurée. Null tant que la table n'a pas de
     * jeton (tables antérieures au backfill).
     */
    public function menuUrl(): ?string
    {
        if ($this->public_token === null) {
            return null;
        }

        $base = rtrim((string) config('inresto.public_menu_base_url'), '/');

        return "{$base}/menu/{$this->public_token}";
    }
}
