<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InvalidatesCaches;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Paramètres de l'église (contrat d'API §4) — SANS enveloppe `data`, comme /stats.
 */
class SettingsController extends Controller
{
    use InvalidatesCaches;

    /** Clés comparées pour le journal (noms des champs modifiés). */
    private const FIELDS = ['church_name', 'public_url', 'verse', 'social_links'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/admin/settings (visitors.view).
     */
    public function show(): JsonResponse
    {
        return new JsonResponse($this->settings->toArray());
    }

    /**
     * PUT /api/admin/settings (settings.update) => même objet que GET.
     * Journal `settings.updated` avec les noms des champs modifiés ; invalide `public:config`.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $before = $this->settings->toArray();

        DB::transaction(function () use ($request, $before): void {
            $values = $request->safe()->only(['church_name', 'public_url']);

            if ($values !== []) {
                $this->settings->setMany(array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    $values,
                ));
            }

            if ($request->has('social_links')) {
                /** @var array<string, string|null> $submitted */
                $submitted = $request->validated('social_links') ?? [];
                $links = $this->settings->socialLinks();

                foreach ($submitted as $network => $url) {
                    // Une chaîne vide efface le lien : le réseau disparaît de la page publique.
                    $links[$network] = is_string($url) && trim($url) !== '' ? trim($url) : null;
                }

                $this->settings->set('social_links', array_filter($links, static fn (?string $url): bool => $url !== null));
            }

            if ($request->has('verse')) {
                $preset = $request->validated('verse.preset');

                if ($request->isCustomVerse()) {
                    $this->settings->setVerse(
                        null,
                        trim((string) $request->validated('verse.ref')),
                        trim((string) $request->validated('verse.text')),
                    );
                } else {
                    $this->settings->setVerse((int) $preset);
                }
            }

            $after = $this->settings->toArray();
            $fields = array_values(array_filter(
                self::FIELDS,
                static fn (string $field): bool => $before[$field] !== $after[$field],
            ));

            if ($fields !== []) {
                $this->audit->log('settings.updated', null, ['fields' => $fields]);
            }
        });

        // Relecture hors transaction : le cache des paramètres ne peut pas retenir un état annulé.
        $this->settings->flush();
        $this->forgetPublicConfig();

        return new JsonResponse($this->settings->toArray());
    }
}
