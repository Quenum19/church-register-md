<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * La congrégation de l'invitant passe de la saisie libre à la liste des congrégations.
 *
 * Le champ a vécu quelques jours en texte libre : les valeurs déjà saisies sont rattachées à la
 * congrégation correspondante, en ignorant la casse et les accents (« VOIX DE LA DESTINEE » =
 * « Voix de la Destinée »). Celles qui ne correspondent à aucune congrégation sont journalisées
 * avec l'identifiant du visiteur AVANT la suppression de la colonne : un responsable peut les
 * ressaisir depuis la fiche. Sans elles, le filtre par congrégation ne voudrait rien dire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->foreignId('inviter_congregation_id')
                ->nullable()
                ->after('invited_by')
                ->constrained('congregations')
                ->nullOnDelete();
        });

        $this->transfer();

        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropColumn('inviter_congregation');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->string('inviter_congregation', 100)->nullable()->after('invited_by');
        });

        // Le nom de la congrégation reprend sa place en texte libre.
        DB::table('visitors')
            ->join('congregations', 'congregations.id', '=', 'visitors.inviter_congregation_id')
            ->update(['visitors.inviter_congregation' => DB::raw('congregations.name')]);

        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inviter_congregation_id');
        });
    }

    /**
     * Rattache chaque saisie libre à sa congrégation ; journalise ce qui reste.
     */
    private function transfer(): void
    {
        /** @var array<string, int> $byName congrégation par nom normalisé */
        $byName = [];

        foreach (DB::table('congregations')->get(['id', 'name']) as $congregation) {
            $byName[self::normalize((string) $congregation->name)] = (int) $congregation->id;
        }

        $unmatched = [];

        DB::table('visitors')
            ->whereNotNull('inviter_congregation')
            ->where('inviter_congregation', '<>', '')
            ->select(['id', 'inviter_congregation'])
            ->orderBy('id')
            ->chunk(200, function (iterable $visitors) use ($byName, &$unmatched): void {
                foreach ($visitors as $visitor) {
                    $id = $byName[self::normalize((string) $visitor->inviter_congregation)] ?? null;

                    if ($id === null) {
                        $unmatched[] = $visitor->id.' => '.$visitor->inviter_congregation;

                        continue;
                    }

                    DB::table('visitors')->where('id', $visitor->id)->update(['inviter_congregation_id' => $id]);
                }
            });

        if ($unmatched !== []) {
            Log::warning('Congrégations saisies à la main sans correspondance (à ressaisir depuis la fiche)', [
                'visiteurs' => $unmatched,
            ]);
        }
    }

    /**
     * « Voix de la Destinée » et « VOIX DE LA DESTINEE » désignent la même congrégation.
     */
    private static function normalize(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9]+/', ' ', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name));

        return mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $ascii)));
    }
};
