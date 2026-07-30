<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing\Data;

/**
 * A model that a pricing source adapter rejected, with a stable machine-readable
 * code so the refresh coordinator can fold it into the run audit and decide
 * whether a fallback verification is warranted.
 *
 * A rejection means the source described a model but it could not be turned into
 * a safe write candidate; it never implies deleting an existing local row.
 */
final readonly class PricingRejection
{
    /**
     * The provider entry itself is malformed (its model collection is missing or
     * not an object), so no model could be evaluated.
     */
    public const string MALFORMED_PROVIDER = 'malformed_provider';

    /**
     * A single model entry is not an object and could not be evaluated.
     */
    public const string MALFORMED_MODEL = 'malformed_model';

    /**
     * The model identifier is empty or otherwise unusable.
     */
    public const string INVALID_IDENTIFIER = 'invalid_identifier';

    /**
     * The model is flagged deprecated (boolean flag or status string) and is
     * intentionally skipped without being written or deleted.
     */
    public const string DEPRECATED = 'deprecated';

    /**
     * The model is a date-suffixed snapshot (for example
     * `claude-haiku-4-5-20251001`) of a base model present in the same provider
     * slice. The base row carries the pricing, so the snapshot is skipped to
     * keep the catalog free of duplicate dated variants.
     */
    public const string DATED_VARIANT = 'dated_variant';

    /**
     * The model declares output modalities that do not include text, so it is
     * not a text-token-priced model.
     */
    public const string NON_TEXT_OUTPUT = 'non_text_output';

    /**
     * The model has no cost object.
     */
    public const string MISSING_COST = 'missing_cost';

    /**
     * The required input rate is absent.
     */
    public const string MISSING_INPUT = 'missing_input';

    /**
     * The required output rate is absent.
     */
    public const string MISSING_OUTPUT = 'missing_output';

    /**
     * A supplied rate is non-numeric, non-finite, negative, or out of range.
     * The offending field name is carried in {@see self::$detail}.
     */
    public const string INVALID_COST = 'invalid_cost';

    public function __construct(
        public string $provider,
        public string $model,
        public string $code,
        public ?string $detail = null,
    ) {}
}
