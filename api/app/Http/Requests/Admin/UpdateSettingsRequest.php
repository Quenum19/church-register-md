<?php

namespace App\Http\Requests\Admin;

use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/admin/settings (contrat d'API §4) :
 * `{ church_name? ≤120, public_url? (https en production), verse?: { preset: 0|1|2 } | { preset: null, ref ≤60, text ≤500 } }`.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorisation par la route (->can('settings.update')).
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $presets = array_keys(app(SettingsService::class)->versePresets());
        $custom = fn (): bool => $this->isCustomVerse();

        return [
            'church_name' => ['sometimes', 'required', 'string', 'max:120'],
            'public_url' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                app()->isProduction() ? 'url:https' : 'url:http,https',
            ],
            'verse' => ['sometimes', 'required', 'array:preset,ref,text'],
            'verse.preset' => ['present_with:verse', 'nullable', 'integer', Rule::in($presets)],
            'verse.ref' => [Rule::requiredIf($custom), 'nullable', 'string', 'max:60'],
            'verse.text' => [Rule::requiredIf($custom), 'nullable', 'string', 'max:500'],

            // Réseaux sociaux : chaque adresse est facultative ; une chaîne vide efface le lien.
            'social_links' => ['sometimes', 'array:'.implode(',', array_keys(SettingsService::SOCIAL_NETWORKS))],
            'social_links.*' => ['nullable', 'string', 'max:255', 'url:https'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'church_name' => 'nom de l\'église',
            'public_url' => 'adresse publique',
            'verse' => 'verset',
            'verse.preset' => 'verset prédéfini',
            'verse.ref' => 'référence du verset',
            'verse.text' => 'texte du verset',
            'social_links' => 'réseaux sociaux',
            'social_links.facebook' => 'adresse Facebook',
            'social_links.youtube' => 'adresse YouTube',
            'social_links.instagram' => 'adresse Instagram',
            'social_links.tiktok' => 'adresse TikTok',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'public_url.url' => app()->isProduction()
                ? 'L\'adresse publique doit être une URL complète en https.'
                : 'L\'adresse publique doit être une URL complète (http ou https).',
            'social_links.*.url' => ':attribute doit être une URL complète commençant par https.',
        ];
    }

    /**
     * Verset personnalisé : `verse.preset` présent et null.
     */
    public function isCustomVerse(): bool
    {
        $verse = $this->input('verse');

        return is_array($verse) && array_key_exists('preset', $verse) && $verse['preset'] === null;
    }
}
