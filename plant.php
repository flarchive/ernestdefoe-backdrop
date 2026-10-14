<?php
$site = require '/var/www/html/site.php'; $app = $site->bootApp();
$db = $app->getContainer()->make('db'); $s = $db->getSchemaBuilder();
if ($argv[1] === 'plant') {
  $s->create('cover_studio_user_data', function ($t) { $t->unsignedInteger('user_id')->primary(); $t->unsignedInteger('cover_file_id')->nullable(); $t->string('cover_url',500)->nullable(); $t->string('cover_thumb_url',500)->nullable(); $t->decimal('cover_focus_x',5,2)->default(50); $t->decimal('cover_focus_y',5,2)->default(50); $t->decimal('cover_zoom',4,2)->default(1); });
  $db->table('cover_studio_user_data')->insert(['user_id'=>5,'cover_file_id'=>999,'cover_url'=>'https://dev.ernestdefoe.online/assets/logo.png','cover_thumb_url'=>'https://dev.ernestdefoe.online/assets/logo.png','cover_focus_x'=>33.5,'cover_focus_y'=>60,'cover_zoom'=>1.4]);
  echo "planted\n";
} elseif ($argv[1] === 'show') {
  echo json_encode($db->table('users')->where('id',5)->first(['backdrop_cover','backdrop_x','backdrop_y','backdrop_zoom','backdrop_small','backdrop_file_id'])), "\n";
  foreach ($db->table('migrations')->where('extension','ernestdefoe-backdrop')->pluck('migration') as $m) echo "  $m\n";
  echo "done flag: ", var_export($db->table('settings')->where('key','like','backdrop%import%')->pluck('key')->all(), true), "\n";
} else {
  $s->dropIfExists('cover_studio_user_data'); echo "dropped\n";
}
