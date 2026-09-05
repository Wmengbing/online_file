<?php
// 应用公共函数库

use think\facade\Config;

/**
 * 统一JSON响应格式
 */
function json_response($code = 200, $msg = 'success', $data = [])
{
    return json([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data,
        'time' => time(),
    ]);
}

/**
 * 成功响应
 */
function success($data = [], $msg = 'success')
{
    return json_response(200, $msg, $data);
}

/**
 * 失败响应
 */
function error($msg = 'error', $code = 400, $data = [])
{
    return json_response($code, $msg, $data);
}

/**
 * 生成唯一令牌
 */
function generate_token($length = 32)
{
    return bin2hex(random_bytes($length / 2));
}

/**
 * 生成提取码
 */
function generate_password($length = 6)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

/**
 * 格式化文件大小
 */
function format_file_size($bytes)
{
    if ($bytes == 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * 获取文件MIME类型
 */
function get_mime_type($file_path)
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file_path);
        finfo_close($finfo);
        return $mime;
    }
    
    $extension = pathinfo($file_path, PATHINFO_EXTENSION);
    $mime_types = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        'mp4' => 'video/mp4',
        'mp3' => 'audio/mpeg',
    ];
    
    return $mime_types[strtolower($extension)] ?? 'application/octet-stream';
}

/**
 * 计算文件哈希
 */
function calculate_file_hash($file_path)
{
    return hash_file('sha256', $file_path);
}

/**
 * 创建目录(递归)
 */
function create_directory($path)
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    return true;
}

/**
 * 获取客户端IP
 */
function get_client_ip()
{
    $ip = request()->ip();
    return $ip ?: '0.0.0.0';
}

/**
 * 记录操作日志
 */
function log_operation($module, $action, $description = '', $status = 1, $request_data = '', $response_data = '')
{
    \think\Db::name('operation_logs')->insert([
        'user_id'      => get_current_user_id() ?: 0,
        'module'       => $module,
        'action'       => $action,
        'description'  => $description,
        'ip'           => get_client_ip(),
        'user_agent'   => request()->header('user-agent', ''),
        'request_data' => is_array($request_data) ? json_encode($request_data, JSON_UNESCAPED_UNICODE) : $request_data,
        'response_data' => is_array($response_data) ? json_encode($response_data, JSON_UNESCAPED_UNICODE) : $response_data,
        'status'       => $status,
    ]);
}

/**
 * 获取当前登录用户ID
 */
function get_current_user_id()
{
    return \app\common\Jwt::getCurrentUserId();
}

/**
 * 创建异步任务
 */
function create_async_task($type, $payload, $scheduled_at = null)
{
    return \think\Db::name('async_tasks')->insertGetId([
        'type'         => $type,
        'payload'      => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload,
        'scheduled_at' => $scheduled_at,
        'status'       => 0,
    ]);
}

/**
 * 由数据库存储的相对路径(形如 uploads/2026/09/05/xxx.txt)得到磁盘绝对路径
 */
function get_file_full_path($path)
{
    return \think\facade\Env::get('root_path') . ltrim((string)$path, '/\\');
}

/**
 * 读取 config/app.php 的应用配置。
 * 说明:ThinkPHP5.1 在常驻进程下 app 配置可能被框架以旧快照加载,直接用配置文件取值更可靠。
 */
function app_cfg($key, $default = null)
{
    static $app_config = null;
    if ($app_config === null) {
        $file = \think\facade\Env::get('root_path') . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        $app_config = is_file($file) ? include $file : [];
    }
    return isset($app_config[$key]) ? $app_config[$key] : $default;
}

/**
 * 级联彻底删除文件树(含回收站内子孙),物理文件仅在无任何记录引用时删除(秒传去重安全)
 * @param int   $user_id   所属用户
 * @param array $root_ids  根文件/目录ID(可为多个,自动合并重叠子树)
 * @return int 释放的配额字节数
 */
function hard_delete_file_tree($user_id, array $root_ids)
{
    if (empty($root_ids)) {
        return 0;
    }

    $all_ids = [];
    $collect = function ($pid, &$out) use ($user_id, &$collect) {
        $kids = \think\Db::name('files')
            ->where('user_id', $user_id)
            ->where('parent_id', $pid)
            ->column('id');
        foreach ($kids as $kid) {
            $kid = (int)$kid;
            $out[] = $kid;
            $collect($kid, $out);
        }
    };

    foreach ($root_ids as $rid) {
        $rid = (int)$rid;
        if (!in_array($rid, $all_ids)) {
            $all_ids[] = $rid;
        }
        $collect($rid, $all_ids);
    }

    if (empty($all_ids)) {
        return 0;
    }
    $all_ids = array_values(array_unique($all_ids));

    $rows = \think\Db::name('files')
        ->where('user_id', $user_id)
        ->where('id', 'in', $all_ids)
        ->select();

    $recycle_ids = \think\Db::name('file_recycle')
        ->where('user_id', $user_id)
        ->where('file_id', 'in', $all_ids)
        ->column('id');

    // 物理删除(引用计数安全)
    $total_size = 0;
    foreach ($rows as $row) {
        if ($row['type'] != 1 || !$row['path']) {
            continue;
        }
        $total_size += (int)$row['size'];

        $refs = \think\Db::name('files')
            ->where('path', $row['path'])
            ->where('id', '<>', $row['id'])
            ->count();

        if ($refs == 0) {
            $full_path = get_file_full_path($row['path']);
            if (file_exists($full_path)) {
                @unlink($full_path);
            }
            $dir = dirname($full_path);
            @rmdir($dir);
            @rmdir(dirname($dir));
            @rmdir(dirname(dirname($dir)));
        }
    }

    // 清理关联分享与内部共享
    \think\Db::name('file_shares')->where('file_id', 'in', $all_ids)->delete();
    \think\Db::name('file_internal_shares')->where('file_id', 'in', $all_ids)->delete();

    // 删除数据库记录
    if (!empty($recycle_ids)) {
        \think\Db::name('file_recycle')->where('id', 'in', $recycle_ids)->delete();
    }
    \think\Db::name('files')->where('id', 'in', $all_ids)->delete();

    // 回收配额
    if ($total_size > 0) {
        \think\Db::name('users')
            ->where('id', $user_id)
            ->dec('storage_used', $total_size)
            ->update();
    }

    return $total_size;
}

/**
 * 文件是否支持浏览器内预览
 */
function is_previewable_extension($extension)
{
    static $text = ['txt', 'md', 'markdown', 'csv', 'log', 'json', 'xml', 'ini', 'sql', 'yaml', 'yml', 'conf', 'sh', 'py', 'js', 'css', 'php', 'html', 'htm'];

    $ext = strtolower($extension);
    $image  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $video  = ['mp4', 'webm', 'ogv', 'mov', 'm4v'];
    $audio  = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'opus'];

    return in_array($ext, $image) || in_array($ext, $video) || in_array($ext, $audio) || $ext == 'pdf' || in_array($ext, $text);
}

/**
 * 文件预览类型:image/video/audio/pdf/text/unsupported
 */
function preview_type_of($extension)
{
    static $text = ['txt', 'md', 'markdown', 'csv', 'log', 'json', 'xml', 'ini', 'sql', 'yaml', 'yml', 'conf', 'sh', 'py', 'js', 'css', 'php', 'html', 'htm'];

    $ext = strtolower($extension);
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) return 'image';
    if (in_array($ext, ['mp4', 'webm', 'ogv', 'mov', 'm4v']))           return 'video';
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'opus'])) return 'audio';
    if ($ext == 'pdf') return 'pdf';
    if (in_array($ext, $text)) return 'text';
    return 'unsupported';
}

/**
 * 构造支持断点续传(HTTP Range)的文件响应对象
 * @param string $full_path  磁盘文件路径
 * @param string $download_name 下载文件名(留空用原名)
 * @param bool   $inline     true=内联预览 false=附件下载
 * @param string $mime       强制 MIME(留空自动探测)
 * @param array  $cleanup    输出完成后自动删除的临时文件
 */
function send_file_stream($full_path, $download_name = '', $inline = false, $mime = '', $cleanup = [])
{
    if (!is_file($full_path)) {
        return json_response(404, '文件不存在');
    }
    return new \app\common\StreamResponse($full_path, $download_name, $inline, $mime, $cleanup);
}
/**
 * 收集用户指定根节点的完整文件树,仅返回文件(type=1)叶子,
 * rel 为该文件相对根节点的目录链(不含根节点名),根节点自身 rel 为 ''
 */
function collect_file_tree_rows($user_id, $root_id)
{
    $rows = [];
    $walk = function ($id, $rel) use ($user_id, &$rows, &$walk) {
        $node = \think\Db::name('files')
            ->where('id', $id)
            ->where('user_id', $user_id)
            ->where('status', 1)
            ->find();
        if (!$node) {
            return;
        }
        if ($node['type'] == 1) {
            $rows[] = ['node' => $node, 'rel' => $rel];
            return;
        }
        $children = \think\Db::name('files')
            ->where('user_id', $user_id)
            ->where('parent_id', $id)
            ->where('status', 1)
            ->order('type', 'asc')
            ->order('name', 'asc')
            ->select();
        foreach ($children as $child) {
            $walk($child['id'], $rel . $node['name'] . '/');
        }
    };

    $walk($root_id, '');
    return $rows;
}

/**
 * 获取文件扩展名
 */
function get_file_extension($filename)
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * 验证文件扩展名是否允许
 */
function is_allowed_extension($extension)
{
    $allowed = config('app.allowed_extensions', []);
    if (empty($allowed)) {
        return true;
    }
    return in_array(strtolower($extension), $allowed);
}

/**
 * 分页参数处理
 */
function get_pagination_params($default_page = 1, $default_page_size = 20)
{
    $page = max(1, (int)input('page', $default_page));
    $page_size = min(100, max(1, (int)input('page_size', $default_page_size)));

    return [$page, $page_size];
}

/**
 * 获取用户名(日志等场景,带请求内缓存)
 */
function getUsername($user_id)
{
    static $map = null;

    if (!$user_id) {
        return '游客';
    }
    if ($map === null) {
        // 请求内缓存:一次请求只查一次全量用户名映射
        $map = \think\Db::name('users')->column('username', 'id');
    }

    return isset($map[$user_id]) ? $map[$user_id] : '已删除用户';
}