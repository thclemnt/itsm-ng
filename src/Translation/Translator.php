<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Translation;

use Laminas\I18n\Translator\Translator as LaminasTranslator;
use Laminas\I18n\Translator\TextDomain;
use Laminas\I18n\Translator\TranslationCollector\TranslationCollectorInterface;
use Psr\SimpleCache\CacheInterface;

/** Application file-registration boundary around the official translation engine. */
final class Translator implements TranslationCollectorInterface
{
    private FileCollector $files;
    private array $messages = [];
    private LaminasTranslator $translator;

    public function __construct(string $locale, private readonly ?CacheInterface $cache = null)
    {
        $this->files = new FileCollector();
        $this->translator = new LaminasTranslator($this, $locale);
    }

    public function collect(string $textDomain, string $locale): TextDomain
    {
        if (isset($this->messages[$textDomain][$locale])) {
            return $this->messages[$textDomain][$locale];
        }
        $catalogue = $this->cachedCatalogue($textDomain, $locale);
        if ($catalogue === null) {
            $catalogue = $this->files->collect($textDomain, $locale);
            $this->cache?->set($this->cacheKey($textDomain, $locale), $catalogue);
        }
        return $this->messages[$textDomain][$locale] = $catalogue;
    }

    public function addTranslationFile(string $type, string $filename, string $textDomain = 'default', ?string $locale = null): self
    {
        $this->files->add($type, $filename, $textDomain, $locale);
        return $this;
    }

    public function setLocale(string $locale): self
    {
        $this->translator->setLocale($locale);
        return $this;
    }

    public function getLocale(): string
    {
        return $this->translator->getLocale();
    }

    private function hasCatalogue(string $domain, string $locale): bool
    {
        if (isset($this->messages[$domain][$locale]) || $this->files->hasFiles($domain, $locale)) {
            return true;
        }
        // A cached-only domain can load before local registration. Unknown
        // misses stay uncollected so later plugin registration can still load.
        $catalogue = $this->cachedCatalogue($domain, $locale);
        if ($catalogue === null) {
            return false;
        }
        $this->messages[$domain][$locale] = $catalogue;
        return true;
    }

    private function cacheKey(string $domain, string $locale): string
    {
        return 'itsmng-i18n3-' . $domain . '-' . $locale;
    }

    private function cachedCatalogue(string $domain, string $locale): ?TextDomain
    {
        // A PSR cache may lose or decline to decode a value after has() succeeds.
        $catalogue = $this->cache?->get($this->cacheKey($domain, $locale));
        return $catalogue instanceof TextDomain ? $catalogue : null;
    }

    public function translate(string $message, string $textDomain = 'default', ?string $locale = null): string|array
    {
        $locale = $locale === '' ? null : $locale;
        if (!$this->hasCatalogue($textDomain, $locale ?? $this->getLocale())) {
            return $message;
        }
        $translated = $this->translator->translate($message, $textDomain, $locale);
        // The legacy __() helper intentionally selects the first entry when a
        // singular call names a plural message; i18n3 otherwise drops that array.
        $messages = $this->messages[$textDomain][$locale ?? $this->getLocale()] ?? null;
        $value = $messages[$message] ?? $messages[$textDomain . "\x04" . $message] ?? null;
        return is_array($value) ? $value : $translated;
    }

    public function translatePlural(string $singular, string $plural, mixed $number, string $textDomain = 'default', ?string $locale = null): string
    {
        if (!$this->hasCatalogue($textDomain, $locale ?? $this->getLocale())) {
            return $number === 1 ? $singular : $plural;
        }
        // Laminas v2 evaluated registered catalogues with abs((int) $number).
        // Cast explicitly so null and fractional counts retain that contract.
        return $this->translator->translatePlural($singular, $plural, (int)$number, $textDomain, $locale);
    }
}
