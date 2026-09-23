<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\User;
use App\Models\Visitor;
use App\Services\VisitorStatusService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Conversion brute. Pour un visiteur cohérent (3 visites + statut « membre »),
 * préférer Visitor::factory()->member().
 *
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visitor_id' => Visitor::factory()->membrePotentiel(),
            'converted_by' => User::factory()->superAdmin(),
            'converted_at' => now(),
        ];
    }

    /**
     * Le statut du visiteur passe à « membre » (seul VisitorStatusService l'écrit).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Member $member): void {
            $visitor = Visitor::query()->findOrFail($member->visitor_id);

            app(VisitorStatusService::class)->refresh($visitor);
        });
    }
}
