<?php
namespace app\index\controller;

use think\Controller;
use think\exception\HttpResponseException;
use app\common\Jwt;
use think\Db;

class Base extends Controller
{
    protected $user_id;
    protected $user_info;
    protected $admin_checked = false;
    protected $admin_cache = false;
    protected $role_ids_cache = null;
    protected $managed_role_ids_cache = null;
    protected $role_folder_map_cache = null;
    protected $internal_share_map_cache = null;
    protected $parent_id_cache = [];

    protected function initialize()
    {
        $csrf_token = csrf_token_value();
        $this->assign('csrf_token', $csrf_token);

        if ($this->request->isPost()) {
            $provided_token = (string)$this->request->header('X-CSRF-Token', '');
            if ($provided_token === '') {
                $provided_token = (string)input('__csrf_token__', '');
            }
            if (!csrf_token_valid($provided_token)) {
                throw new HttpResponseException(json([
                    'code' => 419,
                    'msg'  => '页面已过期，请刷新后重试',
                    'data' => [],
                ], 419));
            }
        }

        $this->user_id = Jwt::getCurrentUserId();
        if ($this->user_id) {
            $this->user_info = Jwt::getCurrentUser();
            if (empty($this->user_info)) {
                // 会话里的用户已不存在(如被管理员删除):按未登录处理,checkLogin 会引导重新登录
                Jwt::logout();
                $this->user_id    = null;
                $this->user_info  = [];
                $this->assign('user_info', []);
                $this->assign('is_logged_in', false);
                $this->assign('is_admin', false);
                return;
            }
            $this->user_info['storage_quota_text'] = format_file_size($this->user_info['storage_quota']);
            $this->user_info['storage_used_text']  = format_file_size($this->user_info['storage_used']);
            $this->user_info['storage_percent']    = $this->user_info['storage_quota'] > 0
                ? round(($this->user_info['storage_used'] / $this->user_info['storage_quota']) * 100, 2)
                : 0;
            $this->assign('user_info', $this->user_info);
            $this->assign('is_logged_in', true);
            $this->assign('is_admin', $this->checkAdmin(false));
        } else {
            $this->assign('is_logged_in', false);
            $this->assign('is_admin', false);
            // Ensure templates referencing user_info won't throw undefined variable notices
            $this->user_info = null;
            $this->assign('user_info', []);
        }
    }

    protected function checkLogin()
    {
        if (!$this->user_id) {
            // 依赖 error() 抛出 HttpResponseException 终止执行(与框架 Jump 一致),
            // 因此调用处无需 return,未登录即中断并跳转登录页
            $this->error('请先登录', 'index/auth/login');
        }
    }

    protected function requirePost()
    {
        if (!$this->request->isPost()) {
            throw new HttpResponseException(json([
                'code' => 405,
                'msg'  => '请求方式不允许',
                'data' => [],
            ], 405));
        }
    }

    protected function checkAdmin($halt = true)
    {
        if ($halt) {
            $this->checkLogin();
        } elseif (!$this->user_id) {
            return false;
        }

        if (!$this->admin_checked) {
            $this->admin_checked = true;
            $user_role_ids = $this->getCurrentRoleIds();

            if (!empty($user_role_ids)) {
                $role = Db::name('roles')
                    ->where('id', 'in', $user_role_ids)
                    ->where('status', 1)
                    ->where(function($q) {
                        $q->where('code', 'super_admin')
                          ->whereOr('code', 'admin')
                          ->whereOr('code', 'administrator')
                          ->whereOr('name', 'like', '%管理员%')
                          ->whereOr('code', 'super-admin');
                    })
                    ->value('id');
                $this->admin_cache = !!$role;
            }
        }

        if (!$this->admin_cache && $halt) {
            $this->error('无权限访问');
        }

        return $this->admin_cache;
    }

    protected function checkPermission($permission_code)
    {
        $permission = Db::name('permissions')
            ->alias('p')
            ->join('role_permissions rp', 'rp.permission_id = p.id')
            ->join('user_roles ur', 'ur.role_id = rp.role_id')
            ->where('ur.user_id', $this->user_id)
            ->where('p.code', $permission_code)
            ->value('p.code');
             
        return !!$permission;
    }

    protected function getCurrentRoleIds()
    {
        if (!$this->user_id) {
            return [];
        }

        if ($this->role_ids_cache !== null) {
            return $this->role_ids_cache;
        }

        $role_ids = Db::name('user_roles')
            ->where('user_id', $this->user_id)
            ->column('role_id');

        $this->role_ids_cache = array_values(array_unique(array_map('intval', (array)$role_ids)));
        return $this->role_ids_cache;
    }

    protected function getAccessibleUserIds()
    {
        if (!$this->user_id) {
            return [];
        }

        if ($this->checkAdmin(false)) {
            return null;
        }

        $role_ids = $this->getCurrentRoleIds();
        $user_ids = [$this->user_id];

        if (!empty($role_ids)) {
            $shared = Db::name('user_roles')
                ->where('role_id', 'in', $role_ids)
                ->column('user_id');

            foreach ((array)$shared as $shared_user_id) {
                $user_ids[] = (int)$shared_user_id;
            }
        }

        return array_values(array_unique($user_ids));
    }

    /**
     * 检查当前用户是否为某个角色的管理者
     * @param int|null $role_id 指定角色ID，null则检查所有角色
     * @return bool
     */
    protected function isRoleManager($role_id = null)
    {
        if (!$this->user_id) {
            return false;
        }

        $query = Db::name('user_roles')
            ->where('user_id', $this->user_id)
            ->where('is_manager', 1);

        if ($role_id !== null) {
            $query->where('role_id', (int)$role_id);
        }

        return $query->count() > 0;
    }

    /**
     * 获取当前用户管理的角色ID列表
     * @return array
     */
    protected function getManagedRoleIds()
    {
        if (!$this->user_id) {
            return [];
        }

        if ($this->managed_role_ids_cache !== null) {
            return $this->managed_role_ids_cache;
        }

        $role_ids = Db::name('user_roles')
            ->where('user_id', $this->user_id)
            ->where('is_manager', 1)
            ->column('role_id');

        $this->managed_role_ids_cache = array_values(array_unique(array_map('intval', (array)$role_ids)));
        return $this->managed_role_ids_cache;
    }

    /**
     * 检查当前用户是否可以管理指定文件
     * 权限规则：
     * 1. 超级管理员可以管理所有文件
     * 2. 角色管理者可以管理该角色文件夹下的所有文件
     * 3. 文件/文件夹的创建者可以管理自己创建的内容
     * 4. 其他普通用户只有查看和下载权限
     * @param array $file 文件信息
     * @return bool
     */
    protected function canManageFile($file)
    {
        if (empty($file)) {
            return false;
        }

        // 超级管理员可以管理所有文件
        if ($this->checkAdmin(false)) {
            return true;
        }

        // 个人文件夹：只有所有者可以管理
        if (!empty($file['is_personal'])) {
            return (int)$file['user_id'] === (int)$this->user_id;
        }

        // 文件/文件夹的创建者可以管理自己创建的内容
        if ((int)$file['user_id'] === (int)$this->user_id) {
            return true;
        }

        // “内部共享-可编辑”对共享节点及其子树生效。
        if ($this->getInternalSharePermission($file) >= 2) {
            return true;
        }

        // 检查文件是否在某个角色文件夹树下
        $file_role_ids = $this->getFileRoleIds($file);
        if (empty($file_role_ids)) {
            return false;
        }

        // 检查当前用户是否是这些角色的管理者
        $managed_roles = $this->getManagedRoleIds();
        if (empty($managed_roles)) {
            return false;
        }

        $shared_roles = array_intersect(array_map('intval', $managed_roles), array_map('intval', $file_role_ids));
        return !empty($shared_roles);
    }

    /**
     * 获取文件所属的角色ID列表
     * 通过向上查找父级文件夹，确定文件属于哪个角色
     * @param array $file 文件信息
     * @return array 角色ID数组
     */
    protected function getFileRoleIds($file)
    {
        if (empty($file)) {
            return [];
        }

        // 获取所有角色文件夹ID
        if ($this->role_folder_map_cache === null) {
            $this->role_folder_map_cache = Db::name('roles')
                ->where('folder_id', '>', 0)
                ->where('status', 1)
                ->column('folder_id', 'id');
        }
        $role_folders = $this->role_folder_map_cache;

        if (empty($role_folders)) {
            return [];
        }

        $role_folder_ids = array_values($role_folders);
        $role_ids = array_keys($role_folders);

        // 如果文件本身就是角色文件夹
        if (in_array((int)$file['id'], $role_folder_ids)) {
            $key = array_search($file['id'], $role_folder_ids);
            return [$role_ids[$key]];
        }

        // 向上查找父级，看是否在某个角色文件夹树下
        $cursor = (int)$file['parent_id'];
        $guard = 0;
        while ($cursor > 0 && $guard++ < 100) {
            if (in_array($cursor, $role_folder_ids)) {
                $key = array_search($cursor, $role_folder_ids);
                return [$role_ids[$key]];
            }

            $parent_id = (int)Db::name('files')
                ->where('id', $cursor)
                ->value('parent_id');

            if ($parent_id == 0) {
                break;
            }

            $cursor = $parent_id;
        }

        return [];
    }

    /**
     * 获取当前用户对文件的内部共享权限。
     * 共享目录的权限会继承给其全部子孙节点。
     */
    protected function getInternalSharePermission($file)
    {
        if (!$this->user_id || empty($file)) {
            return 0;
        }

        if ($this->internal_share_map_cache === null) {
            $this->internal_share_map_cache = Db::name('file_internal_shares')
                ->where('shared_user_id', $this->user_id)
                ->column('permission', 'file_id');
        }
        if (empty($this->internal_share_map_cache)) {
            return 0;
        }

        $cursor = (int)$file['id'];
        $parent_id = isset($file['parent_id']) ? (int)$file['parent_id'] : 0;
        $guard = 0;

        while ($cursor > 0 && $guard++ < 100) {
            $permission = isset($this->internal_share_map_cache[$cursor])
                ? (int)$this->internal_share_map_cache[$cursor]
                : 0;
            if ($permission > 0) {
                return $permission;
            }

            if ($cursor === (int)$file['id']) {
                $cursor = $parent_id;
            } else {
                if (!array_key_exists($cursor, $this->parent_id_cache)) {
                    $this->parent_id_cache[$cursor] = (int)Db::name('files')
                        ->where('id', $cursor)
                        ->value('parent_id');
                }
                $cursor = $this->parent_id_cache[$cursor];
            }
        }

        return 0;
    }

    protected function success($msg = '', $url = null, $data = '', $wait = 3, array $header = [])
    {
        // 路由串(如 index/auth/login)统一转成已注册的规则 URL(如 /login.html),
        // 否则浏览器会按 index/auth/login 这种默认路径访问,被框架判为"非法请求"404
        $redirect = $url;
        if (is_string($url) && $url !== '' && strpos($url, 'http') !== 0 && strpos($url, '/') !== 0) {
            try {
                $redirect = url($url);
            } catch (\Exception $e) {
                $redirect = $url;
            }
        }
        if ($this->request->isAjax()) {
            $resp = ['code' => 200, 'msg' => $msg, 'data' => $data];
            if ($redirect) {
                $resp['url'] = $redirect;
            }
            throw new HttpResponseException(json($resp));
        }
        // For non-AJAX requests, show a lightweight popup using a shared flash view
        // 必须抛出 HttpResponseException(与框架 Jump trait 一致):
        // 否则裸调用 $this->error()/checkLogin() 不会中断,未登录时请求会继续执行并渲染出错
        $this->assign('flash_msg', $msg);
        $this->assign('flash_url', $redirect ?: '');
        $this->assign('flash_wait', (int)$wait);
        $this->assign('flash_type', 'success');
        throw new HttpResponseException($this->fetch('common/flash'));
    }

    protected function error($msg = '', $url = null, $data = '', $wait = 3, array $header = [])
    {
        // 路由串(如 index/auth/login)统一转成已注册的规则 URL(如 /login.html),
        // 否则浏览器会按 index/auth/login 这种默认路径访问,被框架判为"非法请求"404
        $redirect = $url;
        if (is_string($url) && $url !== '' && strpos($url, 'http') !== 0 && strpos($url, '/') !== 0) {
            try {
                $redirect = url($url);
            } catch (\Exception $e) {
                $redirect = $url;
            }
        }
        if ($this->request->isAjax()) {
            $resp = ['code' => 400, 'msg' => $msg, 'data' => $data];
            if ($redirect) {
                $resp['url'] = $redirect;
            }
            throw new HttpResponseException(json($resp));
        }
        // For non-AJAX requests, show a lightweight popup using a shared flash view
        // 必须抛出 HttpResponseException(与框架 Jump trait 一致):
        // 否则裸调用 $this->error()/checkLogin() 不会中断,未登录时请求会继续执行并渲染出错
        $this->assign('flash_msg', $msg);
        $this->assign('flash_url', $redirect ?: '');
        $this->assign('flash_wait', (int)$wait);
        $this->assign('flash_type', 'error');
        throw new HttpResponseException($this->fetch('common/flash'));
    }
}
