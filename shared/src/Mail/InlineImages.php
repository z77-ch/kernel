<?php

namespace Z77\Shared\Mail;

use Z77\Core\Services\ModuleManager;

/**
 * Named inline images of the HTML mails (2026-10-23, the e-mail logo) — resolved at SEND time.
 *
 * A template marks WHERE an image goes with an empty element carrying the slot attribute:
 *
 * ```html
 * <p data-z77-inline="logo" style="margin:0 0 24px;"></p>
 * ```
 *
 * {@see Mailer::send()} hands every HTML mail with a slot to {@see embedInto()}: the source
 * registered for the name answers an {@see InlineImage}; the image is embedded
 * ({@see Message::embed()}, `multipart/related`) and the element gets
 * `<img src="cid:…" width height alt>` — the attribute itself is removed. No image (nothing
 * stored, file missing, no module registers the name) → the whole element is removed, the
 * mail is what it would have been without the slot.
 *
 * Why at send time, not when the body is rendered: the outbox renders a body at QUEUE time
 * and sends it minutes or hours later (mailing.md). Resolving here — like the attachments —
 * means a logo changed in between is the one that goes out, and the stored HTML holds no
 * file path, only the slot. And it is the ONE place every sender passes (the outbox,
 * `EmailService::send()`), so a newsletter gets the same mechanism by writing the attribute.
 *
 * Registration: config key `mailInlineImages` (name => {@see InlineImageSource} class) or an
 * extension file `App/Config/mailInlineImagesConfig.inc.php` — the registry pattern of
 * `mailAttachments` / `documentMails`. A name declared by two classes is refused.
 */
final class InlineImages
{
    public const CONFIG_KEY = 'mailInlineImages';

    /** The slot attribute a template writes. */
    public const ATTRIBUTE = 'data-z77-inline';

    public const NAME_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    /** An EMPTY element with the slot attribute: <tag … data-z77-inline="name" …></tag>. */
    private const SLOT = '/<([a-z][a-z0-9]*)\b([^>]*?)\s+data-z77-inline="([a-z0-9-]+)"([^>]*)>\s*<\/\1\s*>/i';

    /** @var array<string, class-string<InlineImageSource>> */
    private array $classes = [];

    /** @var array<string, string> name => origin */
    private array $origins = [];

    /** @param array<string, class-string> $classesByName name => source class (tests, or a caller that knows them) */
    public function __construct(array $classesByName = [])
    {
        $this->add($classesByName, self::CONFIG_KEY);
    }

    /** The union of every module's `mailInlineImages` declaration and extension files. */
    public static function fromModules(ModuleManager $modules): self
    {
        $registry = new self();
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $declared = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []);
            $sources  = [];
            if ($declared !== null && $declared !== []) {
                $sources[self::CONFIG_KEY . " of module '{$moduleKey}'"] = $declared;
            }
            $sources += $modules->getConfigExtensions($moduleKey, self::CONFIG_KEY);
            foreach ($sources as $origin => $classesByName) {
                $registry->add($classesByName, $origin);
            }
        }

        return $registry;
    }

    /** Whether an HTML body carries a slot at all — cheap, so a mail without one never loads the registry. */
    public static function hasSlots(?string $html): bool
    {
        return $html !== null && str_contains($html, self::ATTRIBUTE . '="');
    }

    /** @return list<string> the registered names */
    public function names(): array
    {
        return array_keys($this->classes);
    }

    /**
     * Fills the slots of the message's HTML body: per name the image embedded once and shown
     * in every slot of that name, or the slot removed. A message without HTML is left alone.
     */
    public function embedInto(Message $message): void
    {
        $html = $message->getHtmlBody();
        if (!self::hasSlots($html)) {
            return;
        }

        /** @var array<string, array{image: InlineImage, cid: string}|null> $resolved */
        $resolved = [];
        $filled   = preg_replace_callback(self::SLOT, function (array $m) use (&$resolved, $message): string {
            $name = strtolower($m[3]);
            if (!array_key_exists($name, $resolved)) {
                $resolved[$name] = $this->embed($name, $message);
            }
            if ($resolved[$name] === null) {
                return '';
            }
            ['image' => $image, 'cid' => $cid] = $resolved[$name];

            return '<' . $m[1] . $m[2] . $m[4] . '>' . self::imgTag($image, $cid) . '</' . $m[1] . '>';
        }, (string) $html);

        $message->html($filled ?? (string) $html);
    }

    /** The `<img>` of an embedded image — its own pixel size, `display:block` against the gap below an inline image. */
    public static function imgTag(InlineImage $image, string $cid): string
    {
        return '<img src="cid:' . htmlspecialchars($cid, ENT_QUOTES, 'UTF-8') . '"'
            . ' width="' . $image->width . '" height="' . $image->height . '"'
            . ' alt="' . htmlspecialchars($image->alt, ENT_QUOTES, 'UTF-8') . '"'
            . ' style="display:block;border:0;outline:none;text-decoration:none;">';
    }

    /** @return array{image: InlineImage, cid: string}|null */
    private function embed(string $name, Message $message): ?array
    {
        if (!isset($this->classes[$name])) {
            return null;   // no module answers this name here — the mail goes out without it
        }
        try {
            $image = (new ($this->classes[$name])())->image();
            if (!$image instanceof InlineImage) {
                return null;
            }
            $cid = $name . '.' . bin2hex(random_bytes(6)) . '@z77';
            $message->embed($image->path, $cid);

            return ['image' => $image, 'cid' => $cid];
        } catch (\Throwable $e) {
            // An image never stops a mail: logged, the slot removed.
            error_log("InlineImages: '{$name}' (" . $this->classes[$name] . ') — ' . $e->getMessage());

            return null;
        }
    }

    /** Everything that would fail at the first use fails here: the shape, the class, no double name. */
    private function add(mixed $classesByName, string $origin): void
    {
        if (!is_array($classesByName) || ($classesByName !== [] && array_is_list($classesByName))) {
            throw new \RuntimeException("❌ {$origin} must be an array of name => " . InlineImageSource::class . ' class.');
        }
        foreach ($classesByName as $name => $class) {
            if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new \RuntimeException("❌ {$origin}: inline image name " . var_export($name, true) . ' must be lower-case kebab (e.g. logo).');
            }
            if (!is_string($class) || !class_exists($class)) {
                throw new \RuntimeException("❌ {$origin}: inline image '{$name}' names a non-existent class: " . var_export($class, true));
            }
            if (!is_subclass_of($class, InlineImageSource::class)) {
                throw new \RuntimeException("❌ {$origin}: inline image '{$name}' names {$class}, which does not implement " . InlineImageSource::class . '.');
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException("❌ {$origin}: inline image '{$name}' names {$class}, which cannot be instantiated without arguments — a source reaches its services through DI.");
            }
            if (isset($this->classes[$name]) && $this->classes[$name] !== $class) {
                throw new \RuntimeException("❌ {$origin}: inline image '{$name}' is already provided by {$this->classes[$name]} ({$this->origins[$name]}).");
            }
            $this->classes[$name] = $class;
            $this->origins[$name] ??= $origin;
        }
    }
}
