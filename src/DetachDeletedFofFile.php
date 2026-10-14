<?php

namespace Ernestdefoe\Backdrop;

use Illuminate\Database\ConnectionInterface;

/**
 * Deleting a cover's file in FoF Upload's media manager removes the cover.
 * Listens before the delete: the file id is still there to match on.
 */
class DetachDeletedFofFile
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function handle(object $file): void
    {
        $this->db->table('users')
            ->where('backdrop_file_id', $file->id)
            ->update(['backdrop_cover' => null, 'backdrop_small' => null, 'backdrop_file_id' => null]);
    }
}
