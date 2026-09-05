<?php
namespace app\index\controller;
use think\Db;
use think\facade\Env;

class Share extends Base
{
    public function index()
    {
        $this->checkLogin();
        
        $shares = Db::name('file_shares')
            ->alias('s')
            ->join('files f', 'f.id = s.file_id')
            ->where('s.user_id', $this->user_id)
            ->field('s.*, f.name as file_name, f.type as file_type, f.size as file_size')
            ->order('s.created_at', 'desc')
            ->select();
        
        foreach ($shares as &$share) {
            if ($share['file_type'] == 1) {
                $share['file_size_text'] = format_file_size($share['file_size']);
            }
            $share['share_url'] = request()->domain() . url('index/share/view', ['token' => $share['token']]);
            
            if ($share['expire_time'] && strtotime($share['expire_time']) < time()) {
                $share['status'] = 2;
            }
        }
        
        $this->assign('shares', $shares);
        return $this->fetch();
    }

    public function create()
    {
        $this->checkLogin();
        
        if ($this->request->isPost()) {
            $file_id = input('file_id', 0);
            $has_password = input('has_password', 0);
            $expire_days = input('expire_days', 0);
            
            $file = Db::name('files')
                ->where('id', $file_id)
                ->where('user_id', $this->user_id)
                ->where('status', 1)
                ->find();
                
            if (!$file) {
                return $this->error('文件不存在');
            }
            
            $token = generate_token();
            $password = null;
            if ($has_password) {
                $password = generate_password();
            }
            
            $expire_time = null;
            if ($expire_days > 0) {
                $expire_time = date('Y-m-d H:i:s', strtotime("+{$expire_days} days"));
            }
            
            Db::name('file_shares')->insert([
                'user_id'     => $this->user_id,
                'file_id'     => $file_id,
                'token'       => $token,
                'password'    => $password,
                'expire_time' => $expire_time,
                'status'      => 1,
            ]);
            
            log_operation('share', 'create', '创建分享链接:' . $file['name']);
            
            $share_url = request()->domain() . url('index/share/view', ['token' => $token]);
            
            return json(['code' => 200, 'msg' => '创建成功', 'data' => [
                'token' => $token,
                'password' => $password,
                'share_url' => $share_url,
                'expire_time' => $expire_time,
            ]]);
        }
        
        $file_id = input('file_id', 0);
        $this->assign('file_id', $file_id);
        return $this->fetch();
    }

    public function delete()
    {
        $this->checkLogin();
        
        $share_id = input('share_id', 0);
        
        Db::name('file_shares')
            ->where('id', $share_id)
            ->where('user_id', $this->user_id)
            ->delete();
        
        log_operation('share', 'delete', '删除分享链接');
        
        return $this->success('删除成功');
    }

    public function toggleStatus()
    {
        $this->checkLogin();
        
        $share_id = input('share_id', 0);
        
        $share = Db::name('file_shares')
            ->where('id', $share_id)
            ->where('user_id', $this->user_id)
            ->find();
            
        if (!$share) {
            return $this->error('分享不存在');
        }
        
        $new_status = $share['status'] == 1 ? 0 : 1;
        
        Db::name('file_shares')
            ->where('id', $share_id)
            ->update(['status' => $new_status]);
        
        return $this->success('操作成功');
    }

    public function view()
    {
        $token = input('token', '');
        $password = input('password', '');
        
        if (!$token) {
            $this->error('分享链接无效');
        }
        
        $share = Db::name('file_shares')
            ->alias('s')
            ->join('files f', 'f.id = s.file_id')
            ->where('s.token', $token)
            ->field('s.*, f.name as file_name, f.type as file_type, f.size as file_size, f.path as file_path, f.mime_type, f.extension')
            ->find();
            
        if (!$share) {
            $this->error('分享不存在或已被删除');
        }
        
        if ($share['status'] == 0) {
            $this->error('分享已被禁用');
        }
        
        if ($share['expire_time'] && strtotime($share['expire_time']) < time()) {
            Db::name('file_shares')
                ->where('id', $share['id'])
                ->update(['status' => 2]);
            $this->error('分享已过期');
        }
        
        if ($share['password'] && $share['password'] !== $password) {
            if ($this->request->isPost()) {
                return json(['code' => 403, 'msg' => '提取码错误']);
            }
            $this->assign('need_password', true);
            $this->assign('token', $token);
            return $this->fetch('password');
        }
        
        Db::name('file_shares')
            ->where('id', $share['id'])
            ->inc('view_count', 1)
            ->update();
        
        if ($share['file_type'] == 1) {
            $share['file_size_text'] = format_file_size($share['file_size']);
        }
        
        $this->assign('share', $share);
        return $this->fetch('view');
    }

    public function download()
    {
        $token = input('token', '');
        $password = input('password', '');
        
        $share = Db::name('file_shares')
            ->alias('s')
            ->join('files f', 'f.id = s.file_id')
            ->where('s.token', $token)
            ->field('s.*, f.path as file_path, f.name as file_name, f.type as file_type')
            ->find();
            
        if (!$share || $share['file_type'] != 1) {
            $this->error('文件不存在');
        }
        
        if ($share['status'] != 1) {
            $this->error('分享不可用');
        }
        
        if ($share['password'] && $share['password'] !== $password) {
            $this->error('提取码错误');
        }
        
        $full_path = get_file_full_path($share['file_path']);
        if (!file_exists($full_path)) {
            $this->error('文件不存在');
        }

        Db::name('file_shares')
            ->where('id', $share['id'])
            ->inc('download_count', 1)
            ->update();

        return send_file_stream($full_path, $share['file_name']);
    }

    public function internalShare()
    {
        $this->checkLogin();
        
        if ($this->request->isPost()) {
            $file_id = input('file_id', 0);
            $user_ids = input('user_ids/a', []);
            $permission = input('permission', 1);
            
            if (empty($user_ids)) {
                return $this->error('请选择要共享的用户');
            }
            
            $file = Db::name('files')
                ->where('id', $file_id)
                ->where('user_id', $this->user_id)
                ->where('status', 1)
                ->find();
                
            if (!$file) {
                return $this->error('文件不存在');
            }
            
            $insert_data = [];
            foreach ($user_ids as $user_id) {
                if ($user_id == $this->user_id) continue;
                
                $exists = Db::name('file_internal_shares')
                    ->where('file_id', $file_id)
                    ->where('shared_user_id', $user_id)
                    ->find();
                    
                if ($exists) continue;
                
                $insert_data[] = [
                    'owner_id'       => $this->user_id,
                    'file_id'        => $file_id,
                    'shared_user_id' => $user_id,
                    'permission'     => $permission,
                ];
            }
            
            if (!empty($insert_data)) {
                Db::name('file_internal_shares')->insertAll($insert_data);
            }
            
            log_operation('share', 'internal_share', '内部共享文件:' . $file['name']);
            
            return $this->success('共享成功');
        }
        
        $file_id = input('file_id', 0);
        $this->assign('file_id', $file_id);
        
        $users = Db::name('users')
            ->where('id', '<>', $this->user_id)
            ->where('status', 1)
            ->field('id,username,email')
            ->select();
        
        $this->assign('users', $users);
        return $this->fetch();
    }

    public function sharedWithMe()
    {
        $this->checkLogin();
        
        $shares = Db::name('file_internal_shares')
            ->alias('s')
            ->join('files f', 'f.id = s.file_id')
            ->join('users u', 'u.id = s.owner_id')
            ->where('s.shared_user_id', $this->user_id)
            ->field('s.*, f.name as file_name, f.type as file_type, f.size as file_size, f.mime_type, u.username as owner_name')
            ->order('s.created_at', 'desc')
            ->select();
        
        foreach ($shares as &$share) {
            if ($share['file_type'] == 1) {
                $share['file_size_text'] = format_file_size($share['file_size']);
            }
        }
        
        $this->assign('shares', $shares);
        return $this->fetch();
    }
}