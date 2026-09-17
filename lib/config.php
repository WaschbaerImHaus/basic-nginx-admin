<?php
declare(strict_types=1);

return [
    'db'            => '/var/lib/vhost-admin/vhosts.sqlite',
    'www_root'      => '/var/www',
    'www_owner'     => 'user',
    'www_group'     => 'www-data',
    'sites_avail'   => '/etc/nginx/sites-available',
    'sites_enabled' => '/etc/nginx/sites-enabled',
    'auth_dir'      => '/etc/nginx/auth',
    'le_live'       => '/etc/letsencrypt/live',
    'vhost_bin'     => '/usr/local/sbin/vhost',
    'template'      => __DIR__ . '/../templates/index.html',
    'admin_port'    => 8080,
];
