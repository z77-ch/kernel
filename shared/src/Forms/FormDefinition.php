<?php

namespace Z77\Shared\Forms;

use Z77\Core\DI;

/**
 * Declarative description of a public form — the ONLY thing a project writes for
 * a standard form mail (see docs/03-development/public-form-bauplan.md). Field
 * mechanics, validation, abuse protection and the submit flow live in the
 * framework ({@see PublicForm}, {@see PublicFormValidator},
 * {@see PublicFormHandler}); the project declares WHICH fields exist and owns
 * the template.
 *
 * A field spec (keyed by the snake_case field name = the HTML input name):
 *
 *   'email' => [
 *       'label'        => 'form.field.email',            // required (key or literal)
 *       'labelHtml'    => '<a href="/agb">AGB</a> …',      // optional, printed UNESCAPED (code only)
 *       'type'         => 'email',                       // default 'text'
 *       'options'      => ['a' => 'Label A', …],         // radio only
 *       'rules'        => ['required' => true, …],       // see RULES
 *       'messages'     => ['required' => 'my.i18n.key'], // per-rule text override
 *       'autocomplete' => 'email',                       // template attribute
 *   ]
 *
 * Rules are an associative array (no string mini-language): 'required' => true,
 * 'min' => int, 'max' => int, 'email' => true, 'accepted' => true. A declared
 * `options` map validates the value implicitly. Error texts are translated
 * (`form.error.{rule}`, placeholders `{$label}` / `{$min}` / `{$max}`) and
 * overridable per field via `messages` — that value is a TRANSLATION KEY, never
 * a literal (an unknown key would surface verbatim).
 */
abstract class FormDefinition
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_EMAIL    = 'email';
    public const TYPE_TEL      = 'tel';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_RADIO    = 'radio';
    public const TYPE_CHECKBOX = 'checkbox';

    /** @var array<string,array>|null normalized cache */
    private ?array $normalized = null;

    /**
     * The form's fields, in render order.
     *
     * @return array<string,array> field name (snake_case) => spec
     */
    abstract public function fields(): array;

    /** emailConfig `forms` key — recipients/subject/body template live there. */
    abstract public function formKey(): string;

    /** FormGuard session scope (time-trap / rate limit / PRG flag). */
    public function guardKey(): string
    {
        return $this->formKey();
    }

    /**
     * Honeypot input names — hidden from humans, must stay empty. They are
     * accepted from the POST but are never fields (no label, no validation).
     *
     * @return list<string>
     */
    public function honeypots(): array
    {
        return ['website', 'fax'];
    }

    /** Field whose value becomes the mail's Reply-To (null = none). */
    public function replyToField(): ?string
    {
        return 'email';
    }

    /** Field whose (server-validated) value selects an emailConfig `routes` entry. */
    public function routeField(): ?string
    {
        return null;
    }

    /**
     * Field whose value the geo-guard log may record as `identity` — an
     * explicit opt-in, one field, or nothing.
     *
     * ⚠️ Null is the DEFAULT and the point: the form log records technical
     * facts (ip, country, outcome, user agent) for every guarded form, but an
     * identifying value only where the definition says so. Data minimisation
     * is the ground state — a questionnaire never names who answered, a
     * registration form declares 'email' because a flood of attempts is only
     * readable evidence when the addresses can be compared.
     */
    public function identityField(): ?string
    {
        return null;
    }

    /**
     * Endpoint for the per-field blur check. null = derive it from the current
     * request (`/{module}/{group}/{controller}/check`); set it explicitly when
     * the form is reached through an alias/reserved route.
     */
    public function checkUrl(): ?string
    {
        return null;
    }

    /**
     * Submit button label (translation key or literal — rendered by the partial).
     */
    public function submitLabel(): string
    {
        return 'form.submit';
    }

    /**
     * The field specs with every optional key filled in, so consumers
     * (validator, templates, JS attributes) never have to guess defaults.
     *
     * @return array<string,array{label:string,type:string,options:array<string,string>,rules:array,messages:array<string,string>,autocomplete:string}>
     */
    final public function normalizedFields(): array
    {
        if ($this->normalized !== null) {
            return $this->normalized;
        }

        $translator = DI::getTranslator();
        $normalized = [];
        foreach ($this->fields() as $name => $spec) {
            $normalized[$name] = [
                // Labels run through the translator, so a declaration may use a
                // key ('form.field.email') OR a literal ('E-Mail') — an unknown
                // key comes back unchanged, which makes the literal work.
                'label'        => $translator->t((string) ($spec['label'] ?? $name)),
                // A label that has to carry MARKUP — a checkbox pointing at
                // terms and a privacy statement is the case this exists for.
                // The partial prints it unescaped and falls back to `label`
                // when it is empty, so `label` stays mandatory: it is what a
                // validation message and a screen reader quote.
                // ⚠️ Only ever from a FormDefinition, i.e. from CODE. Anything
                // a visitor can influence must not reach it — that would be an
                // XSS hole, and no filter here would make it safe. The rule is
                // the origin.
                'labelHtml'    => (string) ($spec['labelHtml'] ?? ''),
                'type'         => (string) ($spec['type'] ?? self::TYPE_TEXT),
                'options'      => (array)  ($spec['options'] ?? []),
                'rules'        => (array)  ($spec['rules'] ?? []),
                'messages'     => (array)  ($spec['messages'] ?? []),
                'autocomplete' => (string) ($spec['autocomplete'] ?? ''),
            ];
        }

        return $this->normalized = $normalized;
    }

    /** True when the name is a declared field (not a honeypot, not unknown). */
    final public function hasField(string $name): bool
    {
        return isset($this->normalizedFields()[$name]);
    }
}
