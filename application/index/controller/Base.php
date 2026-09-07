<?php
namespace app\index\controller;

use think\Controller;
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
            if ($this->user_info) {
                $this->user_info['storage_quota_text'] = format_file_size($this->user_info['storage_quota']);
                $this->user_info['storage_used_text']  = format_file_size($this->user_info['storage_used']);
                $this->user_info['storage_percent']    = $this->user_info['storage_quota'] > 0
                    ? round(($this->user_info['storage_used'] / $this->user_info['storage_quota']) * 100, 2)
                    : 0;
            }
            $this->assign('user_info', $this->user_info);
            $this->assign('is_logged_in', true);
            $this->assign('is_admin', $this->checkAdmin(false));
        } else {
            $this->assign('is_logged_in', false);
            $this->assign('is_admin', false);
        }
    }

    protected function checkLogin()
    {
        if (!$this->user_id) {
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

        $role = Db::name('roles')
            ->alias('r')
            ->join('user_roles ur', 'ur.role_id = r.id')
            ->where('ur.user_id', $this->user_id)
            ->where('r.code', 'super_admin')
            ->where('r.status', 1)
            ->value('r.id');

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
        if ($this->request->isAjax()) {
            return json(['code' => 200, 'msg' => $msg, 'data' => $data]);
        }
        return parent::success($msg, $url, $data, $wait, $header);
    }

    protected function error($msg = '', $url = null, $data = '', $wait = 3, array $header = [])
    {
        if ($this->request->isAjax()) {
            return json(['code' => 400, 'msg' => $msg, 'data' => $data]);
        }
        return parent::error($msg, $url, $data, $wait, $header);
    }
}