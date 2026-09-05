<?php
use think\facade\Env;
return [
    'app_host'              => '',
    'app_debug'             => true,
    'app_multi_module'      => true,
    'default_return_type'   => 'json',
    'default_timezone'      => 'Asia/Shanghai',
    'jwt_secret'            => 'your-secret-key-change-in-production',
    'jwt_expire'            => 604800,
    'upload_path'           => Env::get('root_path') . 'public' . DIRECTORY_SEPARATOR . 'uploads',
    'chunk_size'            => 5242880,
    'max_file_size'         => 1073741824,
    'allowed_extensions'    => [],
    'recycle_expire_days'   => 30,
];