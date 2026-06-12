<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class MarkTokensServerToServer extends Command
{
    protected $signature = 'tokens:mark-server-to-server
                            {--tenant= : Apenas tokens de users deste tenant}
                            {--token= : Apenas o token com este ID}
                            {--apply : Aplica de fato (caso contrário, dry-run)}';

    protected $description = 'Marca tokens Sanctum existentes com a sentinel server-to-server '
        .'para que sobrevivam ao teto global de 24h (config sanctum.expiration).';

    public function handle(): int
    {
        $sentinel = AppServiceProvider::SERVER_TO_SERVER_ABILITY;

        $query = PersonalAccessToken::query()
            ->where('tokenable_type', User::class);

        if ($tenantId = $this->option('tenant')) {
            $userIds = User::where('tenant_id', $tenantId)->pluck('id');
            $query->whereIn('tokenable_id', $userIds);
        }
        if ($tokenId = $this->option('token')) {
            $query->where('id', $tokenId);
        }

        $tokens = $query->get(['id', 'tokenable_id', 'name', 'abilities', 'created_at']);
        $candidates = $tokens->filter(function ($t) use ($sentinel) {
            $abilities = is_array($t->abilities) ? $t->abilities : [];
            return ! in_array($sentinel, $abilities, true);
        });

        $this->info("Tokens encontrados: {$tokens->count()} — sem sentinel: {$candidates->count()}");

        if ($candidates->isNotEmpty()) {
            $this->table(
                ['id', 'user_id', 'name', 'created_at'],
                $candidates->map(fn ($t) => [
                    $t->id, $t->tokenable_id, $t->name, (string) $t->created_at,
                ])->all(),
            );
        }

        if (! $this->option('apply')) {
            $this->warn('Dry-run — re-execute com --apply para gravar.');
            return self::SUCCESS;
        }

        foreach ($candidates as $t) {
            $abilities = is_array($t->abilities) ? $t->abilities : [];
            $t->abilities = array_values(array_unique(array_merge($abilities, [$sentinel])));
            $t->save();
        }

        $this->info("OK — {$candidates->count()} tokens atualizados.");
        return self::SUCCESS;
    }
}
