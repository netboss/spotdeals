<?php

$is_ddev = !empty(getenv('IS_DDEV_PROJECT')) || !empty($_ENV['IS_DDEV_PROJECT']);

$config['config_split.config_split.local']['status'] = $is_ddev;
$config['config_split.config_split.prod']['status'] = !$is_ddev;
