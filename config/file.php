<?php
use think\facade\Env;
return [
    // 文件模块配置镜像；实际业务统一从 config/app.php 的 app.* 读取。
    // 保留本组供独立文件组件读取，路径必须和 app.upload_path 保持一致。
    'upload_path'           => Env::get('root_path') . 'uploads',
    'chunk_size'            => 5242880,
    'max_file_size'         => 1073741824,
    'allowed_extensions'    => [],
    'office_converter'      => env('OFFICE_CONVERTER', ''),
    'office_preview_max_size' => 209715200,
    'recycle_expire_days'   => 30,
];
