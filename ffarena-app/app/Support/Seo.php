<?php

namespace App\Support;

/**
 * Phase 17 — per-request SEO metadata manager.
 *
 * A request-scoped singleton that accumulates search/social metadata for the
 * current page. Public, indexable pages opt in explicitly via ->indexable();
 * every other page stays noindex by default, so SEO can never override
 * authorization or leak private content into crawlers.
 *
 * All JSON-LD is JSON-encoded with HTML-escaping flags so untrusted database
 * text can never break out of the <script type="application/ld+json"> tag.
 */
class Seo
{
    private string $title = '';

    private string $description = '';

    private bool $indexable = false;

    private ?string $canonical = null;

    private string $ogType = 'website';

    private ?string $ogImage = null;

    private ?string $ogImageAlt = null;

    /** @var array<string, mixed>|null */
    private ?array $jsonLd = null;

    public function title(string $title): self
    {
        $this->title = trim($title);

        return $this;
    }

    public function description(string $description): self
    {
        // Keep meta descriptions to a sane, snippet-friendly length.
        $this->description = mb_substr(trim($description), 0, 160);

        return $this;
    }

    public function canonical(string $url): self
    {
        $this->canonical = $url;

        return $this;
    }

    public function indexable(bool $indexable = true): self
    {
        $this->indexable = $indexable;

        return $this;
    }

    public function ogType(string $type): self
    {
        $this->ogType = $type;

        return $this;
    }

    public function ogImage(?string $url, ?string $alt = null): self
    {
        $this->ogImage = $url;
        $this->ogImageAlt = $alt;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function jsonLd(array $data): self
    {
        $this->jsonLd = $data;

        return $this;
    }

    /**
     * @return array{title:string, description:string, indexable:bool, canonical:string, og_type:string, og_image:?string, og_image_alt:?string, jsonld:?string}
     */
    public function toArray(): array
    {
        $siteName = (string) config('app.name', 'FF Arena');

        $title = $this->title !== ''
            ? $this->title
            : $siteName.' — Bangladesh Free Fire Tournaments';

        $description = $this->description !== ''
            ? $this->description
            : 'FF Arena is Bangladesh\'s Free Fire tournament platform — organize, register, compete and get paid, all in one place.';

        $json = null;
        if ($this->jsonLd !== null) {
            $json = json_encode(
                $this->jsonLd,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );

            // json_encode can return false on invalid UTF-8; never emit garbage.
            if ($json === false) {
                $json = null;
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'indexable' => $this->indexable,
            'canonical' => $this->canonical ?? url()->current(),
            'og_type' => $this->ogType,
            'og_image' => $this->ogImage,
            'og_image_alt' => $this->ogImageAlt,
            'jsonld' => $json,
        ];
    }
}
