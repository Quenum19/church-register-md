<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\RecipientRequest;
use App\Http\Requests\Reports\SendTestEmailRequest;
use App\Http\Requests\Reports\StoreRecipientRequest;
use App\Http\Requests\Reports\UpdateRecipientRequest;
use App\Http\Resources\Reports\RecipientResource;
use App\Mail\TestMail;
use App\Models\ReportRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Reports\ReportDispatcher;
use App\Services\SettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Destinataires des rapports mensuels (recipients.manage, contrat d'API §4).
 */
class ReportRecipientController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/admin/report-recipients — globaux d'abord, puis par famille (ordre de rotation), puis par nom.
     */
    public function index(): AnonymousResourceCollection
    {
        $recipients = ReportRecipient::query()
            ->select('report_recipients.*')
            ->leftJoin('families', 'families.id', '=', 'report_recipients.family_id')
            ->with('family:id,name')
            ->orderByRaw('report_recipients.family_id IS NOT NULL')
            ->orderBy('families.position')
            ->orderBy('families.name')
            ->orderBy('report_recipients.name')
            ->orderBy('report_recipients.id')
            ->get();

        return RecipientResource::collection($recipients);
    }

    /**
     * POST /api/admin/report-recipients — 201 { data: Recipient }.
     */
    public function store(StoreRecipientRequest $request): JsonResponse
    {
        /** @var array{family_id: int|null, name: string, email: string, active?: bool} $data */
        $data = $request->validated();

        $recipient = $this->persist(new ReportRecipient, $data);

        $this->audit->log('recipient.created', $recipient, [
            'family_id' => $recipient->family_id,
            'active' => $recipient->active,
        ]);

        return (new RecipientResource($recipient->load('family:id,name')))->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/admin/report-recipients/{recipient} — { data: Recipient }.
     */
    public function update(UpdateRecipientRequest $request, ReportRecipient $recipient): RecipientResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $recipient->fill($data);
        $fields = array_keys($recipient->getDirty());

        if ($fields !== []) {
            $this->persist($recipient, []);

            $this->audit->log('recipient.updated', $recipient, ['fields' => $fields]);
        }

        return new RecipientResource($recipient->load('family:id,name'));
    }

    /**
     * DELETE /api/admin/report-recipients/{recipient} — 204.
     */
    public function destroy(ReportRecipient $recipient): Response
    {
        $recipient->delete();

        $this->audit->log('recipient.deleted', $recipient, ['family_id' => $recipient->family_id]);

        return response()->noContent();
    }

    /**
     * POST /api/admin/report-recipients/test — e-mail de test à l'adresse fournie, sinon à celle
     * de l'utilisateur connecté. 204 ; 429 au-delà de 5 envois par heure ; 503 `mail_failed`
     * si le transport échoue. Chaque envoi est journalisé (`report.test_sent`).
     */
    public function test(SendTestEmailRequest $request, ReportDispatcher $dispatcher, SettingsService $settings): Response
    {
        /** @var User $user */
        $user = $request->user();
        $recipient = $request->email() ?? $user->email;

        $dispatcher->deliver(
            new TestMail($settings->churchName(), $user->name),
            [$recipient],
            ['mail' => 'test', 'user_id' => $user->id],
        );

        // L'adresse est journalisée : cette route envoie un e-mail vers une adresse arbitraire,
        // il faut pouvoir retracer qui a écrit à qui (comme `user.deleted` pour l'e-mail).
        $this->audit->log('report.test_sent', null, ['email' => $recipient], $user);

        return response()->noContent();
    }

    /**
     * Enregistre le destinataire ; une violation de l'index unique (requêtes simultanées)
     * devient la même erreur 422 que la validation applicative.
     *
     * @param  array<string, mixed>  $data
     */
    private function persist(ReportRecipient $recipient, array $data): ReportRecipient
    {
        try {
            $recipient->fill($data)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => RecipientRequest::DUPLICATE_MESSAGE]);
        }

        return $recipient;
    }
}
