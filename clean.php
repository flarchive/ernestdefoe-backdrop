<?php
$site = require '/var/www/html/site.php'; $app = $site->bootApp();
$db = $app->getContainer()->make('db');
$db->getSchemaBuilder()->dropIfExists('cover_studio_user_data');
echo $db->table('users')->where('id',5)->update(['backdrop_cover'=>null,'backdrop_small'=>null,'backdrop_file_id'=>null,'backdrop_x'=>50,'backdrop_y'=>50,'backdrop_zoom'=>1]), " user reset\n";
