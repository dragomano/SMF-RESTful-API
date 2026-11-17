<?php

declare(strict_types=1);

use App\ApiAdmin\MCP\McpServer;

// Run inside api-admin-fpm: php artisan tinker --execute='require "docker/gen-smf-resources.php";'
require_once __DIR__.'/gen-smf-resources-library.php';

generateSmfResources(new McpServer);
