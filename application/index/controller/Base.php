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