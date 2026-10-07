<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Translation;

use Laminas\I18n\Translator\TranslationCollector\PSR16CachingCollector;
use Laminas\I18n\Translator\Translator as LaminasTranslator;
use Laminas\I18n\Translator\TextDomain;
use Laminas\I18n\Translator\TranslationCollector\TranslationCollectorInterface;
use Psr\SimpleCache\CacheInterface;

/** Application file-registration boundary around the official translation engine. */
final class Translator implements TranslationCollectorInterface
{
    private FileCollector $files;
    private TranslationCollectorInterface $collector;
    private array $messages = [];
    private LaminasTranslator $translator;

    public function __construct(string $locale, private readonly ?CacheInterface $cache = null)
    {
        $this->files = new FileCollector();
        $this->collector = $cache === null ? $this->files : new PSR16CachingCollector($cache, $this->files, 'itsmng-i18n3');
        $this->translator = new LaminasTranslator($this, $locale);
    }

    public function collect(string $textDomain, string $locale): TextDomain
    {
        return $this->messages[$textDomain][$locale] = $this->collector->collect($textDomain, $locale);
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
        // V2 retried unregistered domains instead of memoizing an empty catalogue.
        // A real cached catalogue remains usable even before local registration.
        return $this->collector instanceof PSR16CachingCollector
            && $this->cache->has($this->collector->cacheKey($domain, $locale));
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
