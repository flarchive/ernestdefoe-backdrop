<?php

use Illuminate\Database\Schema\Builder;

// Intentionally empty. The import ran here in 1.0.0, before the FoF Upload
// columns it writes existed, so enabling Backdrop over Cover Studio failed with
// "Unknown column 'backdrop_small'". It now runs last, in 000005.
return [
    'up' => function (Builder $schema) {
    },
    'down' => function (Builder $schema) {
    },
];
