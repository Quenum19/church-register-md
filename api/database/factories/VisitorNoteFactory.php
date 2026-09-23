<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitorNote>
 */
class VisitorNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visitor_id' => Visitor::factory(),
            'user_id' => User::factory()->moderateur(),
            'body' => fake()->randomElement([
                'Appelé(e) dans la semaine, très bon accueil.',
                'Souhaite rejoindre la chorale.',
                'À recontacter après le culte de dimanche prochain.',
                'Demande de prière transmise au Pasteur.',
            ]),
        ];
    }
}
