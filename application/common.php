<?php
// 应用公共函数库

use think\facade\Config;
use think\Db;

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
    Db::name('operation_logs')->insert([
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
    return Db::name('async_tasks')->insertGetId([
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
 * @param int   $user_id   所属用户(用于回收配额)
 * @param array $root_ids  根文件/目录ID(可为多个,自动合并重叠子树)
 * @return int 释放的配额字节数
 */
function hard_delete_file_tree($user_id, array $root_ids)
{
    if (empty($root_ids)) {
        return 0;
    }

    $all_ids = [];
    $collect = function ($pid, &$out) use (&$collect) {
        $kids = Db::name('files')
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

    // 不再使用 user_id 过滤，直接根据 ID 查询
    $rows = Db::name('files')
        ->where('id', 'in', $all_ids)
        ->select();

    // 查询回收站记录时，使用 file_id 匹配，不再限制 user_id
    $recycle_ids = Db::name('file_recycle')
        ->where('file_id', 'in', $all_ids)
        ->column('id');

    // 物理删除(引用计数安全)
    $total_size = 0;
    $file_owners = [];
    foreach ($rows as $row) {
        if ($row['type'] != 1 || !$row['path']) {
            continue;
        }
        $total_size += (int)$row['size'];
        $file_owners[] = (int)$row['user_id'];

        $refs = Db::name('files')
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
    Db::name('file_shares')->where('file_id', 'in', $all_ids)->delete();
    Db::name('file_internal_shares')->where('file_id', 'in', $all_ids)->delete();

    // 删除数据库记录
    if (!empty($recycle_ids)) {
        Db::name('file_recycle')->where('id', 'in', $recycle_ids)->delete();
    }
    Db::name('files')->where('id', 'in', $all_ids)->delete();

    // 回收配额 - 根据文件实际所有者回收
    if ($total_size > 0 && !empty($file_owners)) {
        $owner_counts = array_count_values(array_map('strval', $file_owners));
        foreach ($owner_counts as $owner_id => $count) {
            $owner_size = (int)($total_size * $count / count($file_owners));
            Db::name('users')
                ->where('id', $owner_id)
                ->dec('storage_used', $owner_size)
                ->update();
        }
    }

    return $total_size;
}

/**
 * 清理已过期回收站记录(含目录整棵子树,物理文件引用计数安全)。
 * 原 TaskWorker::cleanRecycle 的实现下沉为公共函数,供常驻 worker 与 cron 调度共用。
 */
function clean_expired_recycle($days = 30)
{
    $expire_time = date('Y-m-d H:i:s', strtotime('-' . ((int)$days) . ' days'));

    $expired = Db::name('file_recycle')
        ->alias('r')
        ->join('files f', 'f.id = r.file_id', 'LEFT')
        ->where('r.expire_time', '<=', $expire_time)
        ->where('f.id', 'not null')
        ->field('r.user_id, r.file_id, r.id as recycle_id')
        ->select();

    if (empty($expired)) {
        return ['deleted' => 0];
    }

    // 按用户分组,每用户一批执行(自动合并重叠子树)
    $by_user = [];
    foreach ($expired as $row) {
        $by_user[(int)$row['user_id']][] = (int)$row['file_id'];
    }

    $total = 0;
    foreach ($by_user as $user_id => $root_ids) {
        $total += hard_delete_file_tree($user_id, $root_ids) > 0 ? 1 : 0;
    }

    // 兜底:硬删后仍残留的过期记录(指向已不存在文件等)直接清理
    Db::name('file_recycle')->where('expire_time', '<=', $expire_time)->delete();

    return ['deleted_trees' => count($by_user), 'users' => count($by_user)];
}

/**
 * 清理超期未完成的分片(数据库记录 + 物理文件)
 */
function clean_stale_chunks($days = 7)
{
    $expire_time = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $chunks = Db::name('file_chunks')
        ->where('created_at', '<', $expire_time)
        ->where('status', 0)
        ->select();

    $paths = [];
    foreach ($chunks as $chunk) {
        if ($chunk['chunk_path']) {
            $paths[$chunk['chunk_path']] = 1;
        }
    }

    foreach (array_keys($paths) as $path) {
        $full_path = get_file_full_path($path);
        if (file_exists($full_path)) {
            @unlink($full_path);
        }
    }

    Db::name('file_chunks')
        ->where('created_at', '<', $expire_time)
        ->where('status', 0)
        ->delete();

    // 清理遗留的空分片目录
    $tmp_root = \think\facade\Env::get('root_path') . 'uploads' . DIRECTORY_SEPARATOR . 'tmp';
    if (is_dir($tmp_root)) {
        foreach (glob($tmp_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $entries = @scandir($dir);
            if ($entries !== false && count($entries) <= 2) {
                @rmdir($dir);
            }
        }
    }

    // 清理中断后遗留的上传任务记录(仍标记为上传中,但已长时间无进展)
    $stale_tasks = Db::name('upload_tasks')
        ->where('status', 0)
        ->where('updated_at', '<', $expire_time)
        ->delete();

    return ['deleted_chunks' => count($chunks), 'deleted_tasks' => $stale_tasks];
}

/**
 * 生成用户每日存储统计(重复执行时先清当日旧数据)
 */
function generate_daily_stats($date = null)
{
    $stat_date = $date ?: date('Y-m-d');

    Db::name('storage_stats')->where('stat_date', $stat_date)->delete();

    $users = Db::name('users')
        ->where('deleted_at', null)
        ->column('id');

    $count = 0;
    foreach ($users as $user_id) {
        $total_files = Db::name('files')
            ->where('user_id', $user_id)
            ->where('type', 1)
            ->where('status', 1)
            ->count();

        $total_size = (int)Db::name('files')
            ->where('user_id', $user_id)
            ->where('type', 1)
            ->where('status', 1)
            ->sum('size');

        $file_type_stats = Db::name('files')
            ->where('user_id', $user_id)
            ->where('type', 1)
            ->where('status', 1)
            ->field('extension, COUNT(*) as count, SUM(size) as total_size')
            ->group('extension')
            ->select();

        Db::name('storage_stats')->insert([
            'user_id'         => $user_id,
            'total_files'     => $total_files,
            'total_size'      => $total_size,
            'file_type_stats' => json_encode($file_type_stats, JSON_UNESCAPED_UNICODE),
            'stat_date'       => $stat_date,
        ]);
        $count++;
    }

    return ['user_count' => $count, 'stat_date' => $stat_date];
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
    $office = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

    return in_array($ext, $image) || in_array($ext, $video) || in_array($ext, $audio) || $ext == 'pdf' || in_array($ext, $text) || in_array($ext, $office);
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
    if (in_array($ext, ['doc', 'docx'])) return 'word';
    if (in_array($ext, ['xls', 'xlsx'])) return 'excel';
    if (in_array($ext, ['ppt', 'pptx'])) return 'ppt';
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
    $walk = function ($id, $rel) use (&$rows, &$walk) {
        $node = Db::name('files')
            ->where('id', $id)
            ->where('status', 1)
            ->find();
        if (!$node) {
            return;
        }
        if ($node['type'] == 1) {
            $rows[] = ['node' => $node, 'rel' => $rel];
            return;
        }
        $children = Db::name('files')
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
        $map = Db::name('users')->column('username', 'id');
    }

    return isset($map[$user_id]) ? $map[$user_id] : '已删除用户';
}