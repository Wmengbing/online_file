<?php
namespace app\index\controller;

class Admin extends Base
{
    public function users()
    {
        $this->checkAdmin();
        
        $keyword = input('keyword', '');
        $status = input('status');
        
        $query = \think\Db::name('users')
            ->where('deleted_at', null);
        
        if ($keyword) {
            $query->where('username|email|phone', 'like', '%' . $keyword . '%');
        }
        
        if ($status !== null) {
            $query->where('status', $status);
        }
        
        $users = $query->order('created_at', 'desc')->select();
        
        foreach ($users as &$user) {
            $user['storage_quota_text'] = format_file_size($user['storage_quota']);
            $user['storage_used_text'] = format_file_size($user['storage_used']);
            $user['storage_percent'] = round(($user['storage_used'] / $user['storage_quota']) * 100, 2);
            
            $user['roles'] = \think\Db::name('roles')
                ->alias('r')
                ->join('user_roles ur', 'ur.role_id = r.id')
                ->where('ur.user_id', $user['id'])
                ->column('r.name');
            $user['roles_text'] = empty($user['roles']) ? '无' : implode('、', $user['roles']);
        }
        
        $this->assign('users', $users);
        $this->assign('keyword', $keyword);
        return $this->fetch();
    }

    public function createUser()
    {
        $this->checkAdmin();
        
        if ($this->request->isPost()) {
            $username = input('username', '');
            $password = input('password', '');
            $email = input('email', '');
            $phone = input('phone', '');
            $storage_quota = input('storage_quota', 10737418240);
            $role_ids = input('role_ids/a', []);
            
            if (!$username || !$password) {
                return $this->error('用户名和密码不能为空');
            }
            
            $exists = \think\Db::name('users')->where('username', $username)->find();
            if ($exists) {
                return $this->error('用户名已存在');
            }
            
            $user_id = \think\Db::name('users')->insertGetId([
                'username'      => $username,
                'password'      => password_hash($password, PASSWORD_DEFAULT),
                'email'         => $email,
                'phone'         => $phone,
                'status'        => 1,
                'storage_quota' => $storage_quota,
            ]);
            
            if (!empty($role_ids)) {
                $insert_data = [];
                foreach ($role_ids as $role_id) {
                    $insert_data[] = ['user_id' => $user_id, 'role_id' => $role_id];
                }
                \think\Db::name('user_roles')->insertAll($insert_data);
            }
            
            log_operation('user', 'create', '创建用户:' . $username);
            
            return $this->success('创建成功', 'index/admin/users');
        }
        
        $roles = \think\Db::name('roles')->where('status', 1)->select();
        $this->assign('roles', $roles);
        return $this->fetch();
    }

    public function editUser()
    {
        $this->checkAdmin();
        
        $user_id = input('user_id', 0);
        
        if ($this->request->isPost()) {
            $data = [];
            
            $email = input('email');
            if ($email !== null) $data['email'] = $email;
            
            $phone = input('phone');
            if ($phone !== null) $data['phone'] = $phone;
            
            $status = input('status');
            if ($status !== null) $data['status'] = $status;
            
            $storage_quota = input('storage_quota');
            if ($storage_quota !== null) $data['storage_quota'] = $storage_quota;
            
            $password = input('password');
            if ($password) {
                $data['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            
            if (!empty($data)) {
                \think\Db::name('users')->where('id', $user_id)->update($data);
            }
            
            $role_ids = input('role_ids/a');
            if ($role_ids !== null) {
                \think\Db::name('user_roles')->where('user_id', $user_id)->delete();
                if (!empty($role_ids)) {
                    $insert_data = [];
                    foreach ($role_ids as $role_id) {
                        $insert_data[] = ['user_id' => $user_id, 'role_id' => $role_id];
                    }
                    \think\Db::name('user_roles')->insertAll($insert_data);
                }
            }
            
            log_operation('user', 'edit', '编辑用户');
            
            return $this->success('编辑成功', 'index/admin/users');
        }
        
        $user = \think\Db::name('users')->where('id', $user_id)->find();
        if (!$user) {
            $this->error('用户不存在');
        }

        $user['user_roles'] = \think\Db::name('user_roles')->where('user_id', $user_id)->column('role_id');

        $roles = \think\Db::name('roles')->where('status', 1)->select();

        $this->assign('user', $user);
        $this->assign('user_role_ids', $user['user_roles']);
        $this->assign('roles', $roles);
        return $this->fetch();
    }

    public function deleteUser()
    {
        $this->checkAdmin();
        
        $user_id = input('user_id', 0);
        
        if ($user_id == $this->user_id) {
            return $this->error('不能删除自己');
        }
        
        $user = \think\Db::name('users')->where('id', $user_id)->find();
        if (!$user) {
            return $this->error('用户不存在');
        }
        
        \think\Db::name('users')
            ->where('id', $user_id)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);
        
        \think\Db::name('user_roles')->where('user_id', $user_id)->delete();
        
        log_operation('user', 'delete', '删除用户:' . $user['username']);
        
        return $this->success('删除成功');
    }

    public function roles()
    {
        $this->checkAdmin();
        
        $roles = \think\Db::name('roles')->order('id', 'asc')->select();
        
        foreach ($roles as &$role) {
            $perm_names = \think\Db::name('permissions')
                ->alias('p')
                ->join('role_permissions rp', 'rp.permission_id = p.id')
                ->where('rp.role_id', $role['id'])
                ->column('p.name');
            $role['permissions']     = $perm_names;
            $role['permissions_text'] = empty($perm_names) ? '无' : implode('、', $perm_names);
            $role['user_count']      = \think\Db::name('user_roles')->where('role_id', $role['id'])->count();
        }

        $this->assign('roles', $roles);
        return $this->fetch();
    }

    public function deleteRole()
    {
        $this->checkAdmin();

        $role_id = input('role_id', 0);

        $role = \think\Db::name('roles')->where('id', $role_id)->find();
        if (!$role) {
            return $this->error('角色不存在');
        }

        if ($role['code'] == 'super_admin') {
            return $this->error('超级管理员角色不可删除');
        }

        $user_count = \think\Db::name('user_roles')->where('role_id', $role_id)->count();
        if ($user_count > 0) {
            return $this->error('该角色仍被 ' . $user_count . ' 个用户使用,请先解除关联');
        }

        \think\Db::name('role_permissions')->where('role_id', $role_id)->delete();
        \think\Db::name('roles')->where('id', $role_id)->delete();

        log_operation('user', 'delete_role', '删除角色:' . $role['name']);

        return $this->success('删除成功');
    }

    public function createRole()
    {
        $this->checkAdmin();
        
        if ($this->request->isPost()) {
            $name = input('name', '');
            $code = input('code', '');
            $description = input('description', '');
            $permission_ids = input('permission_ids/a', []);
            
            if (!$name || !$code) {
                return $this->error('角色名称和编码不能为空');
            }
            
            $exists = \think\Db::name('roles')->where('code', $code)->find();
            if ($exists) {
                return $this->error('角色编码已存在');
            }
            
            $role_id = \think\Db::name('roles')->insertGetId([
                'name'        => $name,
                'code'        => $code,
                'description' => $description,
                'status'      => 1,
            ]);
            
            if (!empty($permission_ids)) {
                $insert_data = [];
                foreach ($permission_ids as $permission_id) {
                    $insert_data[] = ['role_id' => $role_id, 'permission_id' => $permission_id];
                }
                \think\Db::name('role_permissions')->insertAll($insert_data);
            }
            
            log_operation('user', 'create_role', '创建角色:' . $name);
            
            return $this->success('创建成功', 'index/admin/roles');
        }
        
        $permissions = \think\Db::name('permissions')->where('status', 1)->order('sort', 'asc')->select();
        $this->assign('permission_groups', $this->buildPermissionGroups($permissions));
        return $this->fetch();
    }

    public function editRole()
    {
        $this->checkAdmin();
        
        $role_id = input('role_id', 0);
        
        if ($this->request->isPost()) {
            $data = [];
            
            $name = input('name');
            if ($name !== null) $data['name'] = $name;
            
            $description = input('description');
            if ($description !== null) $data['description'] = $description;
            
            $status = input('status');
            if ($status !== null) $data['status'] = $status;
            
            if (!empty($data)) {
                \think\Db::name('roles')->where('id', $role_id)->update($data);
            }
            
            $permission_ids = input('permission_ids/a');
            if ($permission_ids !== null) {
                \think\Db::name('role_permissions')->where('role_id', $role_id)->delete();
                if (!empty($permission_ids)) {
                    $insert_data = [];
                    foreach ($permission_ids as $permission_id) {
                        $insert_data[] = ['role_id' => $role_id, 'permission_id' => $permission_id];
                    }
                    \think\Db::name('role_permissions')->insertAll($insert_data);
                }
            }
            
            log_operation('user', 'edit_role', '编辑角色');
            
            return $this->success('编辑成功', 'index/admin/roles');
        }
        
        $role = \think\Db::name('roles')->where('id', $role_id)->find();
        if (!$role) {
            $this->error('角色不存在');
        }

        $role_permissions = \think\Db::name('role_permissions')->where('role_id', $role_id)->column('permission_id');

        $permissions = \think\Db::name('permissions')->where('status', 1)->order('sort', 'asc')->select();

        $this->assign('role', $role);
        $this->assign('role_permissions', $role_permissions);
        $this->assign('permission_groups', $this->buildPermissionGroups($permissions));
        return $this->fetch();
    }

    /**
     * 按父级菜单组织权限分组,便于视图勾选
     * @param array $permissions
     * @return array
     */
    private function buildPermissionGroups($permissions)
    {
        $groups = [];
        foreach ($permissions as $p) {
            if ($p['type'] == 1) {
                $groups[] = ['menu' => $p, 'items' => []];
            }
        }
        foreach ($permissions as $p) {
            if ($p['type'] != 1) {
                foreach ($groups as &$g) {
                    if ($g['menu']['id'] == $p['parent_id']) {
                        $g['items'][] = $p;
                        continue 2;
                    }
                }
                unset($g);
            }
        }
        return $groups;
    }

    public function logs()
    {
        $this->checkAdmin();
        
        $module = input('module', '');
        $action = input('action', '');
        $user_id = input('user_id');
        $start_time = input('start_time', '');
        $end_time = input('end_time', '');
        
        $query = \think\Db::name('operation_logs');
        
        if ($module) $query->where('module', $module);
        if ($action) $query->where('action', $action);
        if ($user_id) $query->where('user_id', $user_id);
        if ($start_time) $query->where('created_at', '>=', $start_time);
        if ($end_time) $query->where('created_at', '<=', $end_time . ' 23:59:59');
        
        $logs = $query->order('created_at', 'desc')->limit(100)->select();
        
        $users = \think\Db::name('users')->where('deleted_at', null)->field('id,username')->select();
        
        foreach ($logs as &$log) {
            $log['status_text'] = $log['status'] == 1 ? '成功' : '失败';
            $log['module_text'] = $this->getModuleText($log['module']);
            $log['action_text'] = $this->getActionText($log['action']);
        }
        
        $this->assign('logs', $logs);
        $this->assign('users', $users);
        $this->assign('module', $module);
        $this->assign('action', $action);
        $this->assign('start_time', $start_time);
        $this->assign('end_time', $end_time);
        
        return $this->fetch();
    }

    public function storage()
    {
        $this->checkAdmin();
        
        $total_users = \think\Db::name('users')->where('deleted_at', null)->count();
        $total_files = \think\Db::name('files')->where('status', 1)->count();
        $total_storage = \think\Db::name('files')->where('type', 1)->where('status', 1)->sum('size');
        $total_quota = \think\Db::name('users')->where('deleted_at', null)->sum('storage_quota');
        $total_used = \think\Db::name('users')->where('deleted_at', null)->sum('storage_used');
        
        $users = \think\Db::name('users')
            ->where('deleted_at', null)
            ->field('id,username,storage_quota,storage_used')
            ->order('storage_used', 'desc')
            ->select();
        
        foreach ($users as &$user) {
            $user['storage_quota_text'] = format_file_size($user['storage_quota']);
            $user['storage_used_text'] = format_file_size($user['storage_used']);
            $user['storage_percent'] = round(($user['storage_used'] / $user['storage_quota']) * 100, 2);
        }
        
        $this->assign('total_users', $total_users);
        $this->assign('total_files', $total_files);
        $this->assign('total_storage', format_file_size($total_storage));
        $this->assign('total_quota', format_file_size($total_quota));
        $this->assign('total_used', format_file_size($total_used));
        $this->assign('users', $users);
        
        return $this->fetch();
    }

    public function updateQuota()
    {
        $this->checkAdmin();
        
        $user_id = input('user_id', 0);
        $quota = input('quota', 0);
        
        if (!$user_id || !$quota) {
            return $this->error('参数错误');
        }
        
        $user = \think\Db::name('users')->where('id', $user_id)->find();
        if (!$user) {
            return $this->error('用户不存在');
        }
        
        \think\Db::name('users')
            ->where('id', $user_id)
            ->update(['storage_quota' => $quota]);
        
        log_operation('storage', 'update_quota', '修改用户' . $user['username'] . '存储配额:' . format_file_size($quota));
        
        return $this->success('修改成功');
    }

    public function tasks()
    {
        $this->checkAdmin();
        
        $tasks = \think\Db::name('async_tasks')
            ->order('created_at', 'desc')
            ->limit(100)
            ->select();
        
        foreach ($tasks as &$task) {
            $task['status_text'] = $this->getTaskStatusText($task['status']);
            $task['type_text'] = $this->getTaskTypeText($task['type']);
        }
        
        $this->assign('tasks', $tasks);
        return $this->fetch();
    }

    public function createTask()
    {
        $this->checkAdmin();
        
        if ($this->request->isPost()) {
            $type = input('type', '');
            $payload = input('payload', '');
            $scheduled_at = input('scheduled_at');
            
            if (!$type) {
                return $this->error('任务类型不能为空');
            }
            
            create_async_task($type, $payload, $scheduled_at);
            
            return $this->success('任务创建成功', 'index/admin/tasks');
        }
        
        return $this->fetch();
    }

    private function getModuleText($module)
    {
        $modules = ['auth' => '认证', 'file' => '文件', 'share' => '分享', 'user' => '用户', 'log' => '日志', 'storage' => '存储', 'recycle' => '回收站'];
        return $modules[$module] ?? $module;
    }

    private function getActionText($action)
    {
        $actions = ['login' => '登录', 'register' => '注册', 'change_password' => '修改密码', 'upload' => '上传', 'download' => '下载', 'delete' => '删除', 'create_folder' => '创建目录', 'rename' => '重命名', 'move' => '移动', 'create' => '创建分享', 'internal_share' => '内部共享', 'restore' => '还原', 'permanent_delete' => '彻底删除'];
        return $actions[$action] ?? $action;
    }

    private function getTaskStatusText($status)
    {
        $map = [0 => '待执行', 1 => '执行中', 2 => '成功', 3 => '失败'];
        return $map[$status] ?? '未知';
    }

    private function getTaskTypeText($type)
    {
        $map = ['clean_recycle' => '清理回收站', 'clean_temp' => '清理临时文件', 'generate_stats' => '生成统计'];
        return $map[$type] ?? $type;
    }
}