<?php

namespace Gtapps\LaravelAgentic\Pagination;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Base input for listing actions. Extend it and add filters via your own
 * constructor; spatie fills these non-constructor properties after
 * instantiation.
 *
 * Properties MUST stay plain (non-promoted): SpatieDataCompiler reads
 * inherited defaults via ReflectionProperty::hasDefaultValue(), which is
 * false for promoted properties — promotion would compile page/perPage as
 * required in subclasses.
 */
class PaginatedInput extends Data
{
    #[Min(1)]
    public int $page = 1;

    #[Min(1), Max(100)]
    public int $perPage = 15;
}
