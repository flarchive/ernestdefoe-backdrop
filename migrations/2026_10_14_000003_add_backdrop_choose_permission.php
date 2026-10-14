<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'ernestdefoe-backdrop.choose' => Group::MEMBER_ID,
]);
