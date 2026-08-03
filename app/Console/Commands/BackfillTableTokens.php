<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TableSalle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Attribue un jeton public aux tables créées avant l'introduction des QR codes.
 *
 * Idempotent : seules les tables sans jeton sont traitées ; relancer la commande
 * ne réattribue jamais de jeton à une table déjà équipée (ce qui invaliderait
 * ses QR déjà imprimés). Pour remplacer volontairement un jeton, passer par
 * l'endpoint de régénération.
 *
 *   php artisan inresto:backfill-table-tokens
 */
#[Signature('inresto:backfill-table-tokens')]
#[Description('Attribue un jeton public aux tables de salle qui n\'en ont pas encore.')]
class BackfillTableTokens extends Command
{
    public function handle(): int
    {
        $tables = TableSalle::query()->whereNull('public_token')->get();

        if ($tables->isEmpty()) {
            $this->info('Toutes les tables disposent déjà d\'un jeton public.');

            return self::SUCCESS;
        }

        foreach ($tables as $table) {
            $table->public_token = TableSalle::genererPublicToken();
            $table->save();
        }

        $this->info("{$tables->count()} table(s) équipée(s) d'un jeton public.");

        return self::SUCCESS;
    }
}
