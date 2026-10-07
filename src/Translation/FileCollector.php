<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Translation;

use Laminas\I18n\Translator\LoaderPluginManager;
use Laminas\I18n\Translator\TextDomain;
use Laminas\I18n\Translator\TranslationCollector\FileListCollector;
use Laminas\I18n\Translator\Value\TranslationFile;
use Laminas\I18n\Translator\TranslationCollector\TranslationCollectorInterface;
use Laminas\I18n\Translator\Translator;
use Laminas\ServiceManager\ServiceManager;

/** Ordered application registrations; parsing and message merging remain Laminas-owned. */
final class FileCollector implements TranslationCollectorInterface
{
    private array $files = [];
    private LoaderPluginManager $loaders;

    public function __construct()
    {
        $this->loaders = new LoaderPluginManager(new ServiceManager());
    }

    public function add(string $type, string $filename, string $domain, ?string $locale): void
    {
        $locale ??= Translator::ANY_LOCALE;
        $this->files[$domain][$locale][] = new TranslationFile($type, $filename, $locale, $domain);
    }

    public function collect(string $textDomain, string $locale): TextDomain
    {
        return (new FileListCollector($this->files, $this->loaders))->collect($textDomain, $locale);
    }
}
