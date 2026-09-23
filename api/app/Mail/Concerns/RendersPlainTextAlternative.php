<?php

namespace App\Mail\Concerns;

use Illuminate\Support\HtmlString;

/**
 * Version texte d'un e-mail rendue depuis une vue Blade qui échappe TOUTES les données avec {{ }}.
 *
 * {{ }} encode les caractères HTML (« N'Guessan » devient « N&#039;Guessan ») : sans risque mais
 * illisible dans la partie text/plain, qui n'est jamais interprétée comme du HTML. Les entités y
 * sont donc décodées après le rendu, comme le fait Laravel pour la version texte des e-mails
 * Markdown. La partie HTML, elle, reste échappée.
 */
trait RendersPlainTextAlternative
{
    /**
     * @return array<int|string, mixed>|string
     */
    protected function buildView()
    {
        $view = parent::buildView();

        if (is_array($view) && isset($view[1]) && is_string($view[1])) {
            $textView = $view[1];

            // Rendu différé : le mailer fournit les données finales de la vue (dont `message`).
            $view[1] = static fn (array $data): HtmlString => new HtmlString(trim(html_entity_decode(
                view($textView, $data)->render(),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ))."\n");
        }

        return $view;
    }
}
