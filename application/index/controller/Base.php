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

    protected function initialize()
    {
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

    protected function checkAdmin($halt = true)
    {
        if ($halt) {
            $this->checkLogin();
        } elseif (!$this->user_id) {
            return false;
        }

        // 先查询当前用户的角色ID
        $user_role_ids = Db::name('user_roles')
            ->where('user_id', $this->user_id)
            ->column('role_id');

        if (empty($user_role_ids)) {
            if ($halt) {
                $this->error('无权限访问');
            }
            return false;
        }

        // 再查询这些角色中是否有超级管理员或常见管理员角色（兼容不同编码/名称）
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

        if (!$role && $halt) {
            $this->error('无权限访问');
        }

        return !!$role;
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

        $role_ids = Db::name('user_roles')
            ->where('user_id', $this->user_id)
            ->column('role_id');

        return array_values(array_unique(array_map('intval', (array)$role_ids)));
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

        $role_ids = Db::name('user_roles')
            ->where('user_id', $this->user_id)
            ->where('is_manager', 1)
            ->column('role_id');

        return array_values(array_unique(array_map('intval', (array)$role_ids)));
    }

    /**
     * 检查当前用户是否可以管理指定文件
     * 角色管理者可以管理同组用户的文件
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

        // 文件所有者可以管理自己的文件
        if ((int)$file['user_id'] === (int)$this->user_id) {
            return true;
        }

        // 角色管理者可以管理同组用户的文件
        $managed_roles = $this->getManagedRoleIds();
        if (empty($managed_roles)) {
            return false;
        }

        $owner_roles = Db::name('user_roles')
            ->where('user_id', (int)$file['user_id'])
            ->column('role_id');

        if (empty($owner_roles)) {
            return false;
        }

        $shared_roles = array_intersect(array_map('intval', $managed_roles), array_map('intval', $owner_roles));
        return !empty($shared_roles);
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