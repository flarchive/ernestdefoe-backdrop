<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'ernestdefoe-backdrop.set' => Group::MEMBER_ID,
]);
