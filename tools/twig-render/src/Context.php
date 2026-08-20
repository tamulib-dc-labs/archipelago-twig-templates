<?php

declare(strict_types=1);

namespace Tamu\ArchipelagoTwigRender;

/**
 * Thrown when a template reaches for something only Drupal can provide.
 */
final class UnsupportedByRendererException extends \RuntimeException
{
}

/**
 * A Drupal field that templates read as {{ node.uuid.value }}.
 */
final class FieldValue
{
    public function __construct(public readonly mixed $value)
    {
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}

/**
 * Stands in for the Drupal node object in the template context.
 *
 * Templates reach it in both styles -- node.uuid.value (property chain) and
 * node.id() / node.label() (method calls) -- so both are provided. Twig
 * resolves node.id to the id() method and node.uuid to the public property.
 */
final class NodeStub
{
    public FieldValue $uuid;
    public FieldValue $title;
    public FieldValue $changed;
    public FieldValue $created;
    public string $canonical;

    public function __construct(
        string $uuid,
        private readonly string $label,
        private readonly int $nid,
        string $baseUrl,
        private readonly int $changedTime = 1755000000,
    ) {
        $this->uuid = new FieldValue($uuid);
        $this->title = new FieldValue($label);
        $this->changed = new FieldValue($changedTime);
        $this->created = new FieldValue($changedTime);
        $this->canonical = rtrim($baseUrl, '/') . '/node/' . $nid;
    }

    public function id(): int
    {
        return $this->nid;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function bundle(): string
    {
        return 'digital_object';
    }

    public function getChangedTime(): int
    {
        return $this->changedTime;
    }

    public function toUrl(string $rel = 'canonical'): string
    {
        return $this->canonical;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}

/**
 * Builds the variable set an Archipelago Metadata Display is rendered with.
 *
 * The names here are the ones the templates actually read -- established by
 * grepping them rather than guessed. Anything a template {% set %}s for itself
 * is deliberately absent.
 */
final class Context
{
    public function __construct(
        private readonly string $baseUrl = 'https://example.org',
        private readonly string $iiifServer = 'https://example.org/iiif/2/',
    ) {
    }

    /**
     * @param array<mixed> $data decoded Strawberryfield JSON
     *
     * @return array<string, mixed>
     */
    public function build(array $data, string $fixtureName): array
    {
        $uuid = (string) ($data['dr:uuid'] ?? '00000000-0000-4000-8000-000000000000');
        $nid = (int) ($data['dr:nid'] ?? 1);

        // Strawberryfield stores repeatable fields as either a scalar or an
        // array depending on how the record was created, and label is one of
        // them. Casting an array straight to string emits a PHP warning and
        // yields "Array", which then shows up inside the rendered document as
        // if it were real metadata.
        $label = $data['label'] ?? $data['title'] ?? $fixtureName;
        if (is_array($label)) {
            $label = $label === [] ? $fixtureName : reset($label);
        }
        $label = is_scalar($label) ? (string) $label : $fixtureName;

        $node = new NodeStub($uuid, $label, $nid, $this->baseUrl);

        return [
            'data' => $data,
            'node' => $node,
            'nodeurl' => rtrim($this->baseUrl, '/') . '/do/' . $uuid,
            'url' => rtrim($this->baseUrl, '/') . '/do/' . $uuid,
            'IIIFserverurl' => $this->iiifServer,
            'iiif_server' => $this->iiifServer,
            'metadatadisplayentity_id' => 1,
            'metadata' => $data,
            'item' => $data,
        ];
    }
}
