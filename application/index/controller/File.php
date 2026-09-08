<?php
namespace app\index\controller;
use think\facade\Env;
use think\Db;

class File extends Base
{
    protected $upload_path;

    protected function initialize()
    {
        parent::initialize();
        $this->upload_path = app_cfg('upload_path', Env::get('root_path') . 'uploads');
        if (!is_dir($this->upload_path)) {
            mkdir($this->upload_path, 0755, true);
        }
    }

    public function index()
    {
        $this->checkLogin();
        
        $parent_id = input('parent_id', 0);
        $keyword = input('keyword', '');
        
        $is_admin = $this->checkAdmin(false);
        
        // 确保用户有个人文件夹
        $this->ensurePersonalFolder();
        
        $query = Db::name('files')
            ->where('parent_id', $parent_id)
            ->where('status', 1);
        $this->applyAccessFilter($query);
        
        // 非管理员：显示角色文件夹和个人文件夹
        if (!$is_admin) {
            $role_folder_ids = $this->getRoleFolderIds();
            $personal_folder_id = $this->getPersonalFolderId();
            
            if ($parent_id == 0) {
                // 根目录：只显示自己的角色文件夹 + 自己的个人文件夹
                $allowed_ids = array_merge($role_folder_ids, [$personal_folder_id]);
                $allowed_ids = array_filter($allowed_ids);
                if (!empty($allowed_ids)) {
                    $query->where('id', 'in', $allowed_ids);
                } else {
                    // 如果没有角色文件夹和个人文件夹，显示空结果
                    $query->where('1=0');
                }
            } else {
                // 子目录：检查是否在角色文件夹树或个人文件夹树下
                $in_role_tree = $this->isInRoleFolderTree($parent_id);
                $in_personal_tree = $this->isInPersonalFolderTree($parent_id);
                if (!$in_role_tree && !$in_personal_tree) {
                    $query->where('1=0');
                }
            }
        }
        
        if ($keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }
        
        $files = $query->order('type', 'asc')->order('created_at', 'desc')->select();
        
        // 获取所有文件上传者的用户ID
        $user_ids = array_unique(array_column($files, 'user_id'));
        $users = [];
        if (!empty($user_ids)) {
            $users = Db::name('users')
                ->where('id', 'in', $user_ids)
                ->column('username', 'id');
        }
        
        foreach ($files as &$file) {
            if ($file['type'] == 1) {
                $file['size_text'] = format_file_size($file['size']);
            }
            $file['can_manage'] = $this->canManageFile($file);
            // 添加上传者用户名
            $file['uploader_username'] = isset($users[$file['user_id']]) ? $users[$file['user_id']] : '未知用户';
        }
        
        $parent = null;
        if ($parent_id > 0) {
            $parent = Db::name('files')
                ->where('id', $parent_id)
                ->where('status', 1)
                ->find();
            if ($parent && !$this->canAccessFile($parent)) {
                $parent = null;
            }
        }
        
        $this->assign('files', $files);
        $this->assign('parent_id', $parent_id);
        $this->assign('parent', $parent);
        $this->assign('keyword', $keyword);
        $this->assign('max_upload_text', format_file_size((int)app_cfg('max_file_size', 0)));
        $this->assign('default_parent_id', $this->getDefaultUploadParentId());
        $this->assign('is_admin', $is_admin);

        return $this->fetch();
    }

    public function createFolder()
    {
        $this->checkLogin();
        
        if ($this->request->isPost()) {
            $name = input('name', '');
            $parent_id = input('parent_id', 0);
            
            if (!$name) {
                return $this->error('目录名称不能为空');
            }
            
            if (preg_match('/[\/\\\:\*\?\"\<\>\|]/', $name)) {
                return $this->error('目录名称包含非法字符');
            }
            
            if ($parent_id > 0 && !$this->validateFolderOwner($parent_id)) {
                return $this->error('目标目录不存在');
            }

            $existsQuery = Db::name('files')
                ->where('parent_id', $parent_id)
                ->where('name', $name)
                ->where('type', 2)
                ->where('status', 1);
            $this->applyAccessFilter($existsQuery);
            $exists = $existsQuery->find();

            if ($exists) {
                return $this->error('目录已存在');
            }
            
            Db::name('files')->insert([
                'user_id'   => $this->user_id,
                'parent_id' => $parent_id,
                'name'      => $name,
                'type'      => 2,
                'path'      => '',
                'status'    => 1,
            ]);
            
            log_operation('file', 'create_folder', '创建目录:' . $name);
            
            // redirect back to the file index with the same parent_id so user stays on the folder page
            return $this->success('创建成功', url('index/file/index', ['parent_id' => $parent_id]));
        }
        
        $parent_id = input('parent_id', 0);
        $this->assign('parent_id', $parent_id);
        return $this->fetch();
    }

    public function upload()
    {
        $this->checkLogin();
        
        if ($this->request->isPost()) {
            $file = request()->file('file');
            if (!$file) {
                return $this->error('请选择上传文件');
            }
            
            $parent_id = input('parent_id', 0);
            
            $file_info = $this->handleUpload($file, $parent_id);
            if (isset($file_info['error'])) {
                return $this->error($file_info['error']);
            }
            
            log_operation('file', 'upload', '上传文件:' . $file_info['name']);
            
            return $this->success('上传成功');
        }
        
        $parent_id = input('parent_id', 0);
        $this->assign('parent_id', $parent_id);
        $this->assign('chunk_size', (int)app_cfg('chunk_size', 5242880));
        $this->assign('chunk_size_text', format_file_size((int)app_cfg('chunk_size', 5242880)));
        return $this->fetch();
    }

    /**
     * 校验目录归属:父目录必须存在并且当前用户可访问
     */
    private function validateFolderOwner($folder_id)
    {
        $folder = Db::name('files')
            ->where('id', $folder_id)
            ->where('type', 2)
            ->where('status', 1)
            ->find();

        if (!$folder) {
            return false;
        }

        // 个人文件夹只有所有者可以访问
        if (!empty($folder['is_personal'])) {
            return (int)$folder['user_id'] === (int)$this->user_id;
        }

        // 如果是管理员，可以访问所有文件夹
        if ($this->checkAdmin(false)) {
            return true;
        }

        // 如果是自己创建的文件夹，可以访问
        if ((int)$folder['user_id'] === (int)$this->user_id) {
            return true;
        }

        // 检查是否是当前用户所属角色的文件夹
        $role_folder_ids = $this->getRoleFolderIds();
        if (in_array((int)$folder_id, $role_folder_ids)) {
            return true;
        }

        // 检查是否可以通过角色共享访问
        return $this->canAccessFile($folder);
    }

    /**
     * 获取当前用户所属角色对应的文件夹ID列表
     * @return array
     */
    private function getRoleFolderIds()
    {
        $role_ids = $this->getCurrentRoleIds();
        if (empty($role_ids)) {
            return [];
        }

        $folder_ids = Db::name('roles')
            ->where('id', 'in', $role_ids)
            ->where('status', 1)
            ->where('folder_id', '>', 0)
            ->column('folder_id');

        // 如果通过角色没找到文件夹，尝试直接查询用户关联的角色文件夹
        if (empty($folder_ids)) {
            $folder_ids = Db::name('user_roles')
                ->alias('ur')
                ->join('roles r', 'r.id = ur.role_id')
                ->where('ur.user_id', $this->user_id)
                ->where('r.status', 1)
                ->where('r.folder_id', '>', 0)
                ->column('r.folder_id');
        }

        return array_values(array_unique(array_map('intval', $folder_ids)));
    }

    /**
     * 检查指定文件夹ID是否在角色文件夹树下
     * @param int $folder_id
     * @return bool
     */
    private function isInRoleFolderTree($folder_id)
    {
        $role_folder_ids = $this->getRoleFolderIds();
        if (empty($role_folder_ids)) {
            return false;
        }

        // 如果本身就是角色文件夹
        if (in_array((int)$folder_id, $role_folder_ids)) {
            return true;
        }

        // 向上查找父级，看是否在角色文件夹树下
        $cursor = (int)$folder_id;
        $guard = 0;
        while ($cursor > 0 && $guard++ < 100) {
            $parent_id = (int)Db::name('files')
                ->where('id', $cursor)
                ->value('parent_id');
            
            if ($parent_id == 0) {
                return false;
            }
            
            if (in_array($parent_id, $role_folder_ids)) {
                return true;
            }
            
            $cursor = $parent_id;
        }

        return false;
    }

    /**
     * 确保用户有个人文件夹
     * @return int 个人文件夹ID
     */
    private function ensurePersonalFolder()
    {
        $personal_folder = Db::name('files')
            ->where('user_id', $this->user_id)
            ->where('is_personal', 1)
            ->where('status', 1)
            ->find();

        if ($personal_folder) {
            return (int)$personal_folder['id'];
        }

        $user = Db::name('users')->where('id', $this->user_id)->find();
        $username = isset($user['username']) ? $user['username'] : ('用户' . ($this->user_id ?: ''));
        $folder_name = $username . '的个人文件夹';

        $folder_id = Db::name('files')->insertGetId([
            'user_id'     => $this->user_id,
            'parent_id'   => 0,
            'name'        => $folder_name,
            'type'        => 2,
            'path'        => '',
            'status'      => 1,
            'is_personal' => 1,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        return (int)$folder_id;
    }

    /**
     * 获取当前用户的个人文件夹ID
     * @return int|null
     */
    private function getPersonalFolderId()
    {
        $folder_id = Db::name('files')
            ->where('user_id', $this->user_id)
            ->where('is_personal', 1)
            ->where('status', 1)
            ->value('id');

        return $folder_id ? (int)$folder_id : null;
    }

    /**
     * 检查指定文件夹ID是否在个人文件夹树下
     * @param int $folder_id
     * @return bool
     */
    private function isInPersonalFolderTree($folder_id)
    {
        $personal_folder_id = $this->getPersonalFolderId();
        if (!$personal_folder_id) {
            return false;
        }

        // 如果本身就是个人文件夹
        if ((int)$folder_id === $personal_folder_id) {
            return true;
        }

        // 向上查找父级，看是否在个人文件夹树下
        $cursor = (int)$folder_id;
        $guard = 0;
        while ($cursor > 0 && $guard++ < 100) {
            $parent_id = (int)Db::name('files')
                ->where('id', $cursor)
                ->value('parent_id');
            
            if ($parent_id == 0) {
                return false;
            }
            
            if ($parent_id === $personal_folder_id) {
                return true;
            }
            
            $cursor = $parent_id;
        }

        return false;
    }

    /**
     * 获取当前用户默认上传的目标文件夹ID
     * 非管理员：优先返回个人文件夹，其次返回角色文件夹ID
     * 管理员：返回0（根目录）
     * @return int
     */
    private function getDefaultUploadParentId()
    {
        if ($this->checkAdmin(false)) {
            return 0;
        }

        // 优先使用个人文件夹
        $personal_folder_id = $this->getPersonalFolderId();
        if ($personal_folder_id) {
            return $personal_folder_id;
        }

        $role_folder_ids = $this->getRoleFolderIds();
        if (!empty($role_folder_ids)) {
            return $role_folder_ids[0];
        }

        return 0;
    }

    private function canAccessFile($file)
    {
        if (empty($file)) {
            return false;
        }

        // 个人文件夹只有所有者本人可以访问，超级管理员也不行
        if (!empty($file['is_personal'])) {
            return (int)$file['user_id'] === (int)$this->user_id;
        }

        if ($this->checkAdmin(false)) {
            return true;
        }

        if ((int)$file['user_id'] === (int)$this->user_id) {
            return true;
        }

        $current_roles = $this->getCurrentRoleIds();
        if (empty($current_roles)) {
            return false;
        }

        $owner_roles = Db::name('user_roles')
            ->where('user_id', (int)$file['user_id'])
            ->column('role_id');

        if (empty($owner_roles)) {
            return false;
        }

        $shared_roles = array_intersect(array_map('intval', $current_roles), array_map('intval', $owner_roles));
        return !empty($shared_roles);
    }

    private function applyAccessFilter($query)
    {
        // 超级管理员可以看到所有文件，但要排除其他人的个人文件夹
        if ($this->checkAdmin(false)) {
            return $query->where(function($q) {
                // 排除其他人的个人文件夹
                $q->where(function($subQ) {
                    $subQ->where('is_personal', '<>', 1)
                         ->whereOr('user_id', '=', $this->user_id);
                });
            });
        }

        $user_ids = $this->getAccessibleUserIds();
        if (empty($user_ids)) {
            return $query->where('1=0');
        }

        // 获取角色文件夹ID和个人文件夹ID
        $role_folder_ids = $this->getRoleFolderIds();
        $personal_folder_id = $this->getPersonalFolderId();
        
        $where_conditions = ['user_id', 'in', $user_ids];
        
        if (!empty($role_folder_ids)) {
            // 允许用户看到：1) 自己/同组成员的文件 2) 角色文件夹 3) 个人文件夹
            $allowed_ids = array_merge($role_folder_ids, [$personal_folder_id]);
            $allowed_ids = array_filter($allowed_ids);
            if (!empty($allowed_ids)) {
                return $query->where(function($q) use ($user_ids, $allowed_ids) {
                    $q->where('user_id', 'in', $user_ids)
                      ->whereOr('id', 'in', $allowed_ids);
                });
            }
        }
        
        // 如果没有角色文件夹，至少包含个人文件夹
        if ($personal_folder_id) {
            return $query->where(function($q) use ($user_ids, $personal_folder_id) {
                $q->where('user_id', 'in', $user_ids)
                  ->whereOr('id', '=', $personal_folder_id);
            });
        }

        return $query->where('user_id', 'in', $user_ids);
    }

    private function handleUpload($file, $parent_id = 0)
    {
        if ($parent_id > 0 && !$this->validateFolderOwner($parent_id)) {
            return ['error' => '目标目录不存在'];
        }

        $file_size = $file->getSize();
        $file_info = $file->getInfo();
        $file_name = isset($file_info['name']) ? $file_info['name'] : $file->getFilename();
        $extension = get_file_extension($file_name);
        
        if (!is_allowed_extension($extension)) {
            return ['error' => '不允许上传此类型的文件'];
        }
        
        $user = Db::name('users')->where('id', $this->user_id)->find();
        if ($user['storage_used'] + $file_size > $user['storage_quota']) {
            return ['error' => '存储空间不足'];
        }
        
        $file_hash = calculate_file_hash($file->getPathname());
        
        $exist_file = Db::name('files')
            ->where('hash', $file_hash)
            ->where('type', 1)
            ->where('status', 1)
            ->find();
        
        if ($exist_file) {
            Db::name('files')->insert([
                'user_id'   => $this->user_id,
                'parent_id' => $parent_id,
                'name'      => $file_name,
                'type'      => 1,
                'mime_type' => get_mime_type($file->getPathname()),
                'size'      => $file_size,
                'path'      => $exist_file['path'],
                'extension' => $extension,
                'hash'      => $file_hash,
                'status'    => 1,
            ]);

            Db::name('users')
                ->where('id', $this->user_id)
                ->inc('storage_used', $file_size)
                ->update();

            return ['name' => $file_name, 'quick_upload' => true];
        }
        
        $date_path = date('Y/m/d');
        $save_path = $this->upload_path . '/' . $date_path;
        create_directory($save_path);
        
        $save_name = md5(uniqid() . $file_name) . '.' . $extension;
        $full_path = $save_path . '/' . $save_name;
        
        $file->move($save_path, $save_name);
        
        $relative_path = 'uploads/' . $date_path . '/' . $save_name;
        
        Db::name('files')->insert([
            'user_id'   => $this->user_id,
            'parent_id' => $parent_id,
            'name'      => $file_name,
            'type'      => 1,
            'mime_type' => get_mime_type($full_path),
            'size'      => $file_size,
            'path'      => $relative_path,
            'extension' => $extension,
            'hash'      => $file_hash,
            'status'    => 1,
        ]);
        
        Db::name('users')
            ->where('id', $this->user_id)
            ->inc('storage_used', $file_size)
            ->update();
        
        return ['name' => $file_name, 'quick_upload' => false];
    }

    public function download()
    {
        $this->checkLogin();
        
        $file_id = input('file_id', 0);
        
        $file = Db::name('files')
            ->where('id', $file_id)
            ->where('type', 1)
            ->where('status', 1)
            ->find();
             
        if (!$file || !$this->canAccessFile($file)) {
            $this->error('文件不存在');
        }
        
        $full_path = get_file_full_path($file['path']);
        if (!file_exists($full_path)) {
            $this->error('文件物理不存在');
        }

        Db::name('files')
            ->where('id', $file_id)
            ->inc('download_count', 1)
            ->update();

        log_operation('file', 'download', '下载文件:' . $file['name']);

        return send_file_stream($full_path, $file['name']);
    }

    /**
     * 在线预览(图片/文本/PDF/音视频)
     */
    public function preview()
    {
        $this->checkLogin();

        $file_id = input('file_id', 0);

        $file = Db::name('files')
            ->where('id', $file_id)
            ->where('type', 1)
            ->where('status', 1)
            ->find();

        if (!$file || !$this->canAccessFile($file)) {
            $this->error('文件不存在');
        }

        $file['size_text'] = format_file_size($file['size']);
        $p_type = preview_type_of($file['extension']);

        $this->assign('file', $file);
        $this->assign('p_type', $p_type);
        $this->assign('raw_url', url('index/file/raw', ['file_id' => $file_id]));
        $this->assign('download_url', url('index/file/download', ['file_id' => $file_id]));

        // 文本类:读取内容展示
        if ($p_type == 'text') {
            $full_path = get_file_full_path($file['path']);
            $content = '';
            $truncated = false;
            if (file_exists($full_path)) {
                $content = @file_get_contents($full_path);
                if ($content === false) {
                    $content = '';
                }
                if (strlen($content) > 2 * 1024 * 1024) {
                    $content = substr($content, 0, 2 * 1024 * 1024);
                    $truncated = true;
                }
                // 非 UTF-8 时尝试按 GBK 转码
                if ($content !== '' && !mb_check_encoding($content, 'UTF-8')) {
                    $converted = @mb_convert_encoding($content, 'UTF-8', 'GBK');
                    if ($converted !== false) {
                        $content = $converted;
                    }
                }
            }
            $this->assign('text_content', $content);
            $this->assign('text_truncated', $truncated);
        }

        return $this->fetch();
    }

    /**
     * 内联输出文件内容(预览用,支持 Range)
     */
    public function raw()
    {
        $this->checkLogin();

        $file_id = input('file_id', 0);

        $file = Db::name('files')
            ->where('id', $file_id)
            ->where('type', 1)
            ->where('status', 1)
            ->find();

        if (!$file || !$this->canAccessFile($file)) {
            $this->error('文件不存在');
        }

        $full_path = get_file_full_path($file['path']);
        if (!file_exists($full_path)) {
            $this->error('文件物理不存在');
        }

        return send_file_stream($full_path, $file['name'], true, $file['mime_type']);
    }

    /**
     * 多选/整目录打包 zip 下载
     */
    public function downloadZip()
    {
        $this->checkLogin();

        $file_ids = input('file_ids/a', []);
        $file_ids = array_values(array_unique(array_map('intval', $file_ids)));

        if (empty($file_ids) || count($file_ids) > 500) {
            $this->error('请选择要打包下载的文件(最多500个)');
        }

        // 收集每个根节点的整树
        $entries = []; // rel_path => full_path
        $zip_root_prefix = '';
        foreach ($file_ids as $fid) {
            $root = Db::name('files')
                ->where('id', $fid)
                ->where('status', 1)
                ->find();
            if (!$root || !$this->canAccessFile($root)) {
                continue;
            }
            $tree = collect_file_tree_rows($this->user_id, $fid);
            foreach ($tree as $item) {
                $node = $item['node'];
                $full = get_file_full_path($node['path']);
                if (!file_exists($full)) {
                    continue;
                }
                // 目录树:rel 已含根目录名层级;直接选文件:自身名
                $name = $item['rel'] . $node['name'];
                // 规避重复路径
                $key = $name;
                $n = 1;
                while (isset($entries[$key])) {
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $base = $ext !== '' ? substr($name, 0, -(strlen($ext) + 1)) : $name;
                    $key = $base . "({$n})" . ($ext !== '' ? '.' . $ext : '');
                    $n++;
                }
                $entries[$key] = $full;
            }
        }

        if (empty($entries)) {
            $this->error('没有可打包的文件');
        }

        $tmp_zip = tempnam(sys_get_temp_dir(), 'dl') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmp_zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->error('创建压缩包失败');
        }

        foreach ($entries as $name => $full) {
            $zip->addFile($full, str_replace('\\', '/', $name));
        }
        $zip->close();

        log_operation('file', 'download_zip', '打包下载:' . count($entries) . '个文件');

        $name = (count($file_ids) === 1 && $file_ids[0] && ($root_name = Db::name('files')->where('id', $file_ids[0])->value('name')))
            ? pathinfo($root_name, PATHINFO_FILENAME) . '.zip'
            : 'files_' . date('Ymd_His') . '.zip';

        return send_file_stream($tmp_zip, $name, false, 'application/zip', [$tmp_zip]);
    }

    public function rename()
    {
        $this->checkLogin();
        
        if ($this->request->isPost()) {
            $file_id = input('file_id', 0);
            $new_name = input('name', '');
            
            if (!$new_name) {
                return $this->error('新名称不能为空');
            }
            
            if (preg_match('/[\/\\\:\*\?\"\<\>\|]/', $new_name)) {
                return $this->error('名称包含非法字符');
            }
            
            $file = Db::name('files')
                ->where('id', $file_id)
                ->where('status', 1)
                ->find();
                
            if (!$file || !$this->canAccessFile($file)) {
                return $this->error('文件不存在');
            }

            if (!$this->canManageFile($file)) {
                return $this->error('无权限重命名此文件');
            }
            
            $exists = Db::name('files')
                ->where('parent_id', $file['parent_id'])
                ->where('name', $new_name)
                ->where('status', 1);
            $this->applyAccessFilter($exists);
            $exists = $exists->find();
            
            if ($exists) {
                return $this->error('同名文件已存在');
            }
            
            Db::name('files')
                ->where('id', $file_id)
                ->update(['name' => $new_name]);
            
            log_operation('file', 'rename', '重命名:' . $file['name'] . ' -> ' . $new_name);
            
            return $this->success('重命名成功');
        }
        
        $file_id = input('file_id', 0);
        $file = Db::name('files')
            ->where('id', $file_id)
            ->find();
        if ($file && !$this->canAccessFile($file)) {
            $file = null;
        }
        
        $this->assign('file', $file);
        return $this->fetch();
    }

    public function move()
    {
        $this->checkLogin();
        
        if ($this->request->isPost()) {
            $file_ids = input('file_ids/a', []);
            $target_parent_id = input('target_parent_id', 0);
            
            if (empty($file_ids)) {
                return $this->error('请选择要移动的文件');
            }

            if ($target_parent_id > 0 && !$this->validateFolderOwner($target_parent_id)) {
                return $this->error('目标目录不存在');
            }

            $file_ids = array_unique(array_map('intval', $file_ids));

            $moving_files = Db::name('files')
                ->where('id', 'in', $file_ids)
                ->where('status', 1)
                ->select();
            $moving_files = array_values(array_filter($moving_files, function ($item) {
                return $this->canAccessFile($item) && $this->canManageFile($item);
            }));
            $moving_folders = array_values(array_map(function ($item) {
                return (int)$item['id'];
            }, array_filter($moving_files, function ($item) {
                return (int)$item['type'] === 2;
            })));

            if ($target_parent_id > 0 && in_array($target_parent_id, $moving_folders)) {
                return $this->error('不能将目录移动到自身内部');
            }

            foreach ($moving_folders as $folder_id) {
                if ($this->isDescendant($folder_id, $target_parent_id)) {
                    return $this->error('不能将目录移动到其子目录中');
                }
            }

            $movable_ids = array_map(function ($item) {
                return (int)$item['id'];
            }, $moving_files);

            Db::name('files')
                ->where('id', 'in', $movable_ids)
                ->where('status', 1)
                ->update(['parent_id' => $target_parent_id]);

            log_operation('file', 'move', '移动文件:' . count($movable_ids) . '个');

            return $this->success('移动成功');
        }
        
        $file_ids = input('file_ids/a', []);
        $this->assign('file_ids', $file_ids);
        
        $folders = Db::name('files')
            ->where('type', 2)
            ->where('status', 1);
        $this->applyAccessFilter($folders);
        $folders = $folders->select();
        
        $this->assign('folders', $folders);
        return $this->fetch();
    }

    public function delete()
    {
        $this->checkLogin();
        
        if ($this->request->isPost()) {
            $file_ids = input('file_ids/a', []);
            
            if (empty($file_ids)) {
                return $this->error('请选择要删除的文件');
            }
            
            $files = Db::name('files')
                ->where('id', 'in', $file_ids)
                ->where('status', 1)
                ->select();
            $files = array_values(array_filter($files, function ($file) {
                return $this->canAccessFile($file) && $this->canManageFile($file);
            }));
            
            if (empty($files)) {
                return $this->error('没有可删除的文件或无权限删除');
            }
            
            $recycle_data = [];
            $ids_to_hide = [];

            foreach ($files as $file) {
                // 递归收集整个目录树(含目录自身),目录、子孙目录、子孙文件全部一并移入回收站
                // 回收站记录的 user_id 使用当前删除操作者的 ID，这样删除者可以在回收站看到
                $this->collectTree($file, $this->user_id, $recycle_data, $ids_to_hide);
            }

            if (!empty($ids_to_hide)) {
                Db::name('files')
                    ->where('id', 'in', array_unique($ids_to_hide))
                    ->where('status', 1)
                    ->update(['status' => 0]);
            }

            if (!empty($recycle_data)) {
                Db::name('file_recycle')->insertAll($recycle_data);
            }

            log_operation('file', 'delete', '删除文件:' . count($files) . '个');

            return $this->success('已移入回收站');
        }
    }

    /**
     * 判断 $target_id 是否位于 $folder_id 目录树下
     */
    private function isDescendant($folder_id, $target_id)
    {
        $cursor = (int)$target_id;
        $guard  = 0;
        while ($cursor > 0 && $guard++ < 100) {
            if ($cursor == $folder_id) {
                return true;
            }
            $cursor = (int)Db::name('files')
                ->where('id', $cursor)
                ->value('parent_id');
        }
        return false;
    }

    /**
     * 递归收集节点及其全部子孙,生成回收站记录并登记待隐藏的ID
     * @param array $node           当前节点(files 表行)
     * @param int   $user_id        所属用户
     * @param array $recycle_data   回收站记录(引用)
     * @param array $ids_to_hide    需要置 status=0 的ID集合(引用)
     */
    private function collectTree($node, $user_id, &$recycle_data, &$ids_to_hide)
    {
        $ids_to_hide[] = $node['id'];

        if (!isset($node['recycled'])) {
            $recycle_data[] = [
                'user_id'            => $user_id,
                'file_id'            => $node['id'],
                'original_parent_id' => $node['parent_id'],
                'deleted_at'         => date('Y-m-d H:i:s'),
                'expire_time'        => date('Y-m-d H:i:s', strtotime('+30 days')),
            ];
        }

        if ($node['type'] != 2) {
            return;
        }

        $children = Db::name('files')
            ->where('parent_id', $node['id'])
            ->where('status', 1)
            ->select();

        foreach ($children as $child) {
            $this->collectTree($child, $user_id, $recycle_data, $ids_to_hide);
        }
    }

    // ============ 分片上传 ============

    /**
     * 分片上传初始化:配额/类型预检,返回 upload_id
     */
    public function chunkInit()
    {
        if (!$this->user_id) {
            return json(['code' => 401, 'msg' => '请先登录']);
        }

        $file_name  = trim((string)input('file_name', ''));
        $total_size = (int)input('total_size', 0);
        $parent_id  = (int)input('parent_id', 0);

        if ($file_name === '' || mb_strlen($file_name) > 255) {
            return json(['code' => 400, 'msg' => '文件名不合法']);
        }
        if (preg_match('/[\/\\\:\*\?\"\<\>\|]/', $file_name)) {
            return json(['code' => 400, 'msg' => '文件名包含非法字符']);
        }
        if ($total_size <= 0 || $total_size > (int)app_cfg('max_file_size', 0)) {
            return json(['code' => 400, 'msg' => '文件大小超出限制']);
        }
        if (!is_allowed_extension(get_file_extension($file_name))) {
            return json(['code' => 400, 'msg' => '不允许上传此类型的文件']);
        }
        if ($parent_id > 0 && !$this->validateFolderOwner($parent_id)) {
            return json(['code' => 400, 'msg' => '目标目录不存在']);
        }

        $user = Db::name('users')->where('id', $this->user_id)->find();
        if ($user['storage_used'] + $total_size > $user['storage_quota']) {
            return json(['code' => 400, 'msg' => '存储空间不足']);
        }

        $upload_id = bin2hex(random_bytes(16));
        $tmp_dir = $this->upload_path . '/tmp/u' . $this->user_id . '_' . $upload_id;
        create_directory($tmp_dir);

        return json(['code' => 200, 'msg' => 'ok', 'data' => ['upload_id' => $upload_id]]);
    }

    /**
     * 提交单个分片
     */
    public function chunkUpload()
    {
        if (!$this->user_id) {
            return json(['code' => 401, 'msg' => '请先登录']);
        }

        $upload_id    = (string)input('upload_id', '');
        $chunk_index  = (int)input('index', -1);
        $total_chunks = (int)input('total_chunks', 0);
        $file_name    = trim((string)input('file_name', ''));
        $total_size   = (int)input('total_size', 0);

        if (!preg_match('/^[a-f0-9]{32}$/', $upload_id)) {
            return json(['code' => 400, 'msg' => 'upload_id 无效']);
        }
        if ($chunk_index < 0 || $total_chunks <= 0 || $chunk_index >= $total_chunks || $total_chunks > 65536) {
            return json(['code' => 400, 'msg' => '分片参数无效']);
        }

        $file = request()->file('file');
        $chunk_size = $file ? (int)$file->getSize() : 0;
        if (!$file || !$chunk_size) {
            return json(['code' => 400, 'msg' => '缺少分片数据']);
        }
        if ($chunk_size > (int)app_cfg('chunk_size', 5242880)) {
            return json(['code' => 400, 'msg' => '分片超过大小限制']);
        }

        $tmp_dir = $this->upload_path . '/tmp/u' . $this->user_id . '_' . $upload_id;
        if (!is_dir($tmp_dir)) {
            return json(['code' => 400, 'msg' => '上传任务不存在或已过期']);
        }

        $chunk_path = $tmp_dir . '/' . sprintf('%06d.part', $chunk_index);

        // 已存在的分片直接跳过(断点续传/重试场景,避免重复写入)
        if (!file_exists($chunk_path)) {
            $file->move($tmp_dir, sprintf('%06d.part', $chunk_index));
        }
        $chunk_hash = hash_file('sha256', $chunk_path);
        $rel_path = 'uploads/tmp/u' . $this->user_id . '_' . $upload_id . '/' . sprintf('%06d.part', $chunk_index);

        $exist = Db::name('file_chunks')
            ->where('upload_id', $upload_id)
            ->where('chunk_index', $chunk_index)
            ->find();

        $data = [
            'user_id'     => $this->user_id,
            'upload_id'   => $upload_id,
            'file_name'   => $file_name,
            'chunk_index' => $chunk_index,
            'chunk_hash'  => $chunk_hash,
            'chunk_size'  => $chunk_size,
            'chunk_path'  => $rel_path,
            'total_chunks'=> $total_chunks,
            'total_size'  => $total_size,
            'status'      => 0,
        ];

        if ($exist) {
            Db::name('file_chunks')->where('id', $exist['id'])->update($data);
        } else {
            Db::name('file_chunks')->insert($data);
        }

        return json(['code' => 200, 'msg' => '分片上传成功', 'data' => ['index' => $chunk_index]]);
    }

    /**
     * 查询已接收分片(断点续传时跳过已传分片)
     */
    public function chunkStatus()
    {
        if (!$this->user_id) {
            return json(['code' => 401, 'msg' => '请先登录']);
        }

        $upload_id    = (string)input('upload_id', '');
        $total_chunks = (int)input('total_chunks', 0);

        if (!preg_match('/^[a-f0-9]{32}$/', $upload_id)) {
            return json(['code' => 400, 'msg' => 'upload_id 无效']);
        }

        $rows = Db::name('file_chunks')
            ->where('upload_id', $upload_id)
            ->where('user_id', $this->user_id)
            ->column('chunk_path', 'chunk_index');

        $received = [];
        foreach ($rows as $idx => $path) {
            if (file_exists(get_file_full_path($path))) {
                $received[] = (int)$idx;
            }
        }

        return json(['code' => 200, 'msg' => 'ok', 'data' => [
            'received'  => $received,
            'remaining' => $total_chunks > 0 ? array_values(array_diff(range(0, $total_chunks - 1), $received)) : [],
        ]]);
    }

    /**
     * 合并分片并落库(含秒传去重、配额占用)
     */
    public function chunkMerge()
    {
        if (!$this->user_id) {
            return json(['code' => 401, 'msg' => '请先登录']);
        }

        $upload_id    = (string)input('upload_id', '');
        $file_name    = trim((string)input('file_name', ''));
        $parent_id    = (int)input('parent_id', 0);
        $total_chunks = (int)input('total_chunks', 0);
        $total_size   = (int)input('total_size', 0);

        if (!preg_match('/^[a-f0-9]{32}$/', $upload_id)) {
            return json(['code' => 400, 'msg' => 'upload_id 无效']);
        }
        if ($total_chunks <= 0 || $file_name === '') {
            return json(['code' => 400, 'msg' => '参数无效']);
        }
        if ($parent_id > 0 && !$this->validateFolderOwner($parent_id)) {
            return json(['code' => 400, 'msg' => '目标目录不存在']);
        }

        $tmp_dir = $this->upload_path . '/tmp/u' . $this->user_id . '_' . $upload_id;
        if (!is_dir($tmp_dir)) {
            return json(['code' => 400, 'msg' => '上传任务不存在']);
        }

        // 校验分片齐全且物理存在
        $chunk_rows = Db::name('file_chunks')
            ->where('upload_id', $upload_id)
            ->where('user_id', $this->user_id)
            ->select();

        $idx_map = [];
        foreach ($chunk_rows as $row) {
            $idx_map[(int)$row['chunk_index']] = $row['chunk_path'];
        }
        if (count($idx_map) < $total_chunks) {
            return json(['code' => 400, 'msg' => '分片不完整,请重新上传缺失分片', 'data' => ['missing' => array_values(array_diff(range(0, $total_chunks - 1), array_keys($idx_map)))]]);
        }

        $date_path = date('Y/m/d');
        $save_path = $this->upload_path . '/' . $date_path;
        create_directory($save_path);

        $extension = get_file_extension($file_name);
        $save_name = md5(uniqid() . $file_name . random_bytes(8)) . ($extension ? '.' . $extension : '');
        $final_path = $save_path . '/' . $save_name;

        // 逐块合并(流式,内存安全)
        $out = @fopen($final_path, 'wb');
        if (!$out) {
            return json(['code' => 500, 'msg' => '写入失败,请检查服务器磁盘权限']);
        }
        try {
            for ($i = 0; $i < $total_chunks; $i++) {
                $part = $tmp_dir . '/' . sprintf('%06d.part', $i);
                if (!file_exists($part)) {
                    throw new \Exception('分片缺失:' . $i);
                }
                $in = fopen($part, 'rb');
                if (!$in) {
                    throw new \Exception('分片读取失败:' . $i);
                }
                while (!feof($in)) {
                    $buf = fread($in, 8 * 1024 * 1024);
                    if ($buf === false || ($buf !== '' && fwrite($out, $buf) === false)) {
                        fclose($in);
                        throw new \Exception('合并写入失败');
                    }
                }
                fclose($in);
            }
        } catch (\Exception $e) {
            fclose($out);
            @unlink($final_path);
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
        fclose($out);

        $real_size = (int)filesize($final_path);
        if ($real_size <= 0) {
            @unlink($final_path);
            return json(['code' => 400, 'msg' => '文件内容为空']);
        }

        // 配额二次校验
        $user = Db::name('users')->where('id', $this->user_id)->find();
        if ($user['storage_used'] + $real_size > $user['storage_quota']) {
            @unlink($final_path);
            $this->cleanupChunkTask($upload_id);
            return json(['code' => 400, 'msg' => '存储空间不足']);
        }

        $file_hash = hash_file('sha256', $final_path);
        $rel_path = 'uploads/' . $date_path . '/' . $save_name;

        // 秒传去重
        $exist_file = Db::name('files')
            ->where('hash', $file_hash)
            ->where('type', 1)
            ->where('status', 1)
            ->find();

        if ($exist_file) {
            @unlink($final_path);
            $rel_path = $exist_file['path'];
        }

        $file_id = Db::name('files')->insertGetId([
            'user_id'   => $this->user_id,
            'parent_id' => $parent_id,
            'name'      => $file_name,
            'type'      => 1,
            'mime_type' => get_mime_type(get_file_full_path($rel_path)),
            'size'      => $real_size,
            'path'      => $rel_path,
            'extension' => $extension,
            'hash'      => $file_hash,
            'status'    => 1,
        ]);

        Db::name('users')
            ->where('id', $this->user_id)
            ->inc('storage_used', $real_size)
            ->update();

        $this->cleanupChunkTask($upload_id);

        log_operation('file', 'upload', '分片上传文件:' . $file_name);

        return json(['code' => 200, 'msg' => '上传完成', 'data' => [
            'file_id'    => $file_id,
            'quick_upload' => !empty($exist_file),
        ]]);
    }

    /**
     * 清理分片任务(数据库记录 + 物理分片)
     */
    private function cleanupChunkTask($upload_id)
    {
        $rows = Db::name('file_chunks')
            ->where('upload_id', $upload_id)
            ->where('user_id', $this->user_id)
            ->select();
        foreach ($rows as $row) {
            if ($row['chunk_path']) {
                @unlink(get_file_full_path($row['chunk_path']));
            }
        }
        Db::name('file_chunks')
            ->where('upload_id', $upload_id)
            ->where('user_id', $this->user_id)
            ->delete();

        $tmp_dir = $this->upload_path . '/tmp/u' . $this->user_id . '_' . $upload_id;
        if (is_dir($tmp_dir)) {
            @rmdir($tmp_dir);
        }
    }

    public function detail()
    {
        $this->checkLogin();

        $file_id = input('file_id', 0);
        
        $file = Db::name('files')
            ->where('id', $file_id)
            ->where('status', 1)
            ->find();
            
        if (!$file || !$this->canAccessFile($file)) {
            $this->error('文件不存在');
        }
        
        if ($file['type'] == 1) {
            $file['size_text'] = format_file_size($file['size']);
        }
        
        $this->assign('file', $file);
        return $this->fetch();
    }
}