<?php

use Flarum\Database\Migration;

// Columns on `users`, like core's avatar_url: a cover arrives with the user
// row every page already loads, so no query anywhere has to join or eager
// load anything for it.
return Migration::addColumns('users', [
    // Long enough for a cover brought over as a URL from another extension.
    'backdrop_cover' => ['string', 'length' => 1024, 'nullable' => true],
    'backdrop_x' => ['float', 'default' => 50],
    'backdrop_y' => ['float', 'default' => 50],
    'backdrop_zoom' => ['float', 'default' => 1],
]);
