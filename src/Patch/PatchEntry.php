<?php

declare(strict_types=1);

namespace Bounteous\Darn\Patch;

/**
 * Represents a single patch entry, normalising both legacy (string key => string url)
 * and modern (array with 'description'/'url' keys) composer.json formats.
 */
final class PatchEntry
{
    public function __construct(
        public readonly string $description,
        public readonly ?string $url = null,
        public readonly ?int $depth = null,
        public readonly ?string $issueTrackerUrl = null,
        public readonly ?string $ticket = null,
    ) {
    }

    /**
     * Creates a PatchEntry from a composer.json patches array element.
     *
     * Handles both:
     *  - Legacy format: ['Description' => 'url']  ($key = string, $value = string)
     *  - Modern format: [['description' => '...', 'url' => '...']] ($key = int, $value = array)
     *
     * @param  string|array<mixed>  $value
     */
    public static function fromComposerData(int|string $key, string|array $value): self
    {
        if (is_string($key) && is_string($value)) {
            return new self($key, $value);
        }

        return new self(
            description: $value['description'] ?? '',
            url: $value['url'] ?? null,
            depth: $value['depth'] ?? null,
            issueTrackerUrl: $value['extra']['issue-tracker-url'] ?? null,
            ticket: $value['extra']['ticket'] ?? null,
        );
    }

    /**
     * Serialises to the modern array format used in composer.json.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'description' => $this->description,
        ];

        if ($this->url !== null) {
            $data['url'] = $this->url;
        }

        if ($this->depth !== null) {
            $data['depth'] = $this->depth;
        }

        if ($this->issueTrackerUrl !== null) {
            $data['extra']['issue-tracker-url'] = $this->issueTrackerUrl;
        }

        if ($this->ticket !== null) {
            $data['extra']['ticket'] = $this->ticket;
        }

        return $data;
    }
}
