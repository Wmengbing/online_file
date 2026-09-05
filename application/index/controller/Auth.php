<?php
namespace app\index\controller;

use app\common\Jwt;
use think\Db;

class Auth extends Base
{
    public function login()
    {
        if (Jwt::isLoggedIn()) {
            $this->redirect('index/file/index');
        }
        
        if ($this->request->isPost()) {
            $username = input('username', '');
            $password = input('password', '');
            
            if (!$username || !$password) {
                return $this->error('用户名和密码不能为空');
            }
            
            $user = Db::name('users')
                ->where('username', $username)
                ->where('status', 1)
                ->find();
                
            if (!$user || !password_verify($password, $user['password'])) {
                log_operation('auth', 'login', '登录失败:用户名或密码错误', 0);
                return $this->error('用户名或密码错误');
            }
            
            Jwt::login($user);
            
            Db::name('users')
                ->where('id', $user['id'])
                ->update([
                    'last_login_time' => date('Y-m-d H:i:s'),
                    'last_login_ip'   => get_client_ip(),
                ]);
            
            log_operation('auth', 'login', '用户登录成功');
            
            return $this->success('登录成功', 'index/file/index');
        }
        
        return $this->fetch();
    }

    public function register()
    {
        if (Jwt::isLoggedIn()) {
            $this->redirect('index/file/index');
        }
        
        if ($this->request->isPost()) {
            $username = input('username', '');
            $password = input('password', '');
            $email = input('email', '');
            
            if (!$username || !$password) {
                return $this->error('用户名和密码不能为空');
            }
            
            if (strlen($username) < 3 || strlen($username) > 50) {
                return $this->error('用户名长度必须在3-50个字符之间');
            }
            
            if (strlen($password) < 6) {
                return $this->error('密码长度不能少于6位');
            }
            
            $exists = Db::name('users')->where('username', $username)->find();
            if ($exists) {
                return $this->error('用户名已存在');
            }
            
            if ($email) {
                $email_exists = Db::name('users')->where('email', $email)->find();
                if ($email_exists) {
                    return $this->error('邮箱已被注册');
                }
            }
            
            $user_id = Db::name('users')->insertGetId([
                'username'      => $username,
                'password'      => password_hash($password, PASSWORD_DEFAULT),
                'email'         => $email,
                'status'        => 1,
                'storage_quota' => 10737418240,
            ]);
            
            $default_role = Db::name('roles')->where('code', 'user')->value('id');
            if ($default_role) {
                Db::name('user_roles')->insert([
                    'user_id' => $user_id,
                    'role_id' => $default_role,
                ]);
            }
            
            log_operation('auth', 'register', '用户注册成功');
            
            return $this->success('注册成功，请登录', 'index/auth/login');
        }
        
        return $this->fetch();
    }

    public function logout()
    {
        Jwt::logout();
        return $this->success('退出成功', 'index/auth/login');
    }

    public function profile()
    {
        $this->checkLogin();
        
        if ($this->request->isPost()) {
            $data = [];
            
            $email = input('email');
            if ($email !== null) $data['email'] = $email;
            
            $phone = input('phone');
            if ($phone !== null) $data['phone'] = $phone;
            
            $avatar = input('avatar');
            if ($avatar !== null) $data['avatar'] = $avatar;
            
            if (!empty($data)) {
                Db::name('users')
                    ->where('id', $this->user_id)
                    ->update($data);
            }
            
            log_operation('user', 'update_profile', '更新个人信息');
            
            return $this->success('更新成功');
        }
        
        $user = Db::name('users')
            ->where('id', $this->user_id)
            ->find();
        
        $user['storage_quota_text'] = format_file_size($user['storage_quota']);
        $user['storage_used_text'] = format_file_size($user['storage_used']);
        $user['storage_percent'] = round(($user['storage_used'] / $user['storage_quota']) * 100, 2);
        
        $this->assign('user', $user);
        return $this->fetch();
    }

    public function changePassword()
    {
        $this->checkLogin();
        
        if ($this->request->isPost()) {
            $old_password = input('old_password', '');
            $new_password = input('new_password', '');
            
            if (!$old_password || !$new_password) {
                return $this->error('旧密码和新密码不能为空');
            }
            
            if (strlen($new_password) < 6) {
                return $this->error('新密码长度不能少于6位');
            }
            
            $user = Db::name('users')->where('id', $this->user_id)->find();
            if (!password_verify($old_password, $user['password'])) {
                return $this->error('旧密码错误');
            }
            
            Db::name('users')
                ->where('id', $this->user_id)
                ->update(['password' => password_hash($new_password, PASSWORD_DEFAULT)]);
            
            log_operation('auth', 'change_password', '修改密码成功');
            
            return $this->success('密码修改成功', 'index/auth/profile');
        }
        
        return $this->fetch();
    }
}