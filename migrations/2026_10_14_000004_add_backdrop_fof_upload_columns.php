<?php

use Flarum\Database\Migration;

// A cover stored by FoF Upload: its file (so deleting it in My Media removes
// the cover) and its thumbnail, so cards need no lookup. No foreign key, since
// FoF Upload may not be installed.
return Migration::addColumns('users', [
    'backdrop_small' => ['string', 'length' => 1024, 'nullable' => true],
    'backdrop_file_id' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
