<?php

namespace Deployer;

require 'recipe/laravel.php';

// Config
set('repository', 'git@gitee.com:3Rx1NDOD/wecom-mcp-server.git');

// Project name
set('application', '/usr/share/nginx/html/wecom-mcp-server');

add('shared_files', []);
add('shared_dirs', []);
add('writable_dirs', []);

// Hosts
host('39.105.140.119')
    ->set('remote_user', 'deployer')
    ->set('identity_file', '~/.ssh/deployerkey')
    ->set('branch', 'main')
    ->set('deploy_path', '{{application}}');

// Hooks
after('deploy:failed', 'deploy:unlock');
