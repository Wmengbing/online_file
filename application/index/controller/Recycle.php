<?php
namespace app\index\controller;

use think\Db;

class Recycle extends Base
{
    public function index()
    {
        $this->checkLogin();

        $recycle_list = Db::name('file_recycle')
            ->alias('r')
            ->join('files f', 'f.id = r.file_id')
            ->where('r.user_id', $this->user_id)
            ->field('r.*, f.name as file_name, f.type as file_type, f.size as file_size')
            ->order('r.deleted_at', 'desc')
            ->select();

        foreach ($recycle_list as &$item) {
            $item['days_left'] = max(0, ceil((strtotime($item['expire_time']) - time()) / 86400));
            if ($item['file_type'] == 1) {
                $item['file_size_text'] = format_file_size($item['file_size']);
            }
        }

        $this->assign('recycle_list', $recycle_list);
        return $this->fetch();
    }

    /**
     * 还原:目录优先;若原父目录仍在回收站,则连同祖先目录一起还原,避免"孤儿"文件
     */
    public function restore()
    {
        $this->checkLogin();

        $recycle_ids = input('recycle_ids/a', []);

        if (empty($recycle_ids)) {
            return $this->error('请选择要还原的文件');
        }

        $recycle_list = Db::name('file_recycle')
            ->where('user_id', $this->user_id)
            ->where('id', 'in', $recycle_ids)
            ->select();

        if (empty($recycle_list)) {
            return $this->error('还原记录不存在');
        }

        $file_types = Db::name('files')
            ->where('id', 'in', array_map(function ($r) {
                return (int)$r['file_id'];
            }, $recycle_list))
            ->column('type', 'id');

        $restore_recycle_ids = [];

        foreach ($recycle_list as $recycle) {
            $restore_recycle_ids[] = (int)$recycle['id'];

            // 补全仍处于回收站的祖先目录
            $ancestor = (int)$recycle['original_parent_id'];
            while ($ancestor > 0) {
                $ancestor_row = Db::name('files')
                    ->where('id', $ancestor)
                    ->where('user_id', $this->user_id)
                    ->where('status', 0)
                    ->find();
                if (!$ancestor_row) {
                    break;
                }
                $ancestor_recycle = Db::name('file_recycle')
                    ->where('user_id', $this->user_id)
                    ->where('file_id', $ancestor)
                    ->find();
                if (!$ancestor_recycle) {
                    break; // 父目录已彻底删除,无法还原祖先
                }
                $restore_recycle_ids[] = (int)$ancestor_recycle['id'];
                $ancestor = (int)$ancestor_recycle['original_parent_id'];
            }
        }

        $restore_recycle_ids = array_unique($restore_recycle_ids);

        // 目录优先还原(还原目录时其回收站内的子孙会被递归一起还原)
        $restore_set = Db::name('file_recycle')
            ->where('user_id', $this->user_id)
            ->where('id', 'in', $restore_recycle_ids)
            ->select();

        usort($restore_set, function ($a, $b) use ($file_types) {
            $ta = isset($file_types[$a['file_id']]) ? (int)$file_types[$a['file_id']] : 1;
            $tb = isset($file_types[$b['file_id']]) ? (int)$file_types[$b['file_id']] : 1;
            return $ta === $tb ? $a['id'] - $b['id'] : ($ta === 2 ? -1 : 1);
        });

        $restored_recycle_ids = [];
        foreach ($restore_set as $node) {
            if (!in_array((int)$node['id'], $restored_recycle_ids)) {
                $this->restoreNode($node, $restored_recycle_ids);
            }
        }

        log_operation('recycle', 'restore', '还原文件:' . count($recycle_ids) . '个');

        return $this->success('还原成功');
    }

    /**
     * 递归还原节点:自身 + 回收站中所有以它为父节点的子孙记录
     */
    private function restoreNode($recycle, &$restored_recycle_ids)
    {
        Db::name('files')
            ->where('id', $recycle['file_id'])
            ->update([
                'status'    => 1,
                'parent_id' => $recycle['original_parent_id'],
            ]);

        Db::name('file_recycle')->where('id', $recycle['id'])->delete();
        $restored_recycle_ids[] = (int)$recycle['id'];

        // 若该节点为目录,递归还原其回收站中的直接子级
        $is_folder = Db::name('files')
            ->where('id', $recycle['file_id'])
            ->value('type') == 2;

        if (!$is_folder) {
            return;
        }

        $children = Db::name('file_recycle')
            ->where('user_id', $this->user_id)
            ->where('original_parent_id', $recycle['file_id'])
            ->select();

        foreach ($children as $child) {
            if (!in_array((int)$child['id'], $restored_recycle_ids)) {
                $this->restoreNode($child, $restored_recycle_ids);
            }
        }
    }

    /**
     * 彻底删除(含目录整棵子树),物理文件仅在无任何记录引用时删除(秒传去重安全)
     */
    public function permanentDelete()
    {
        $this->checkLogin();

        $recycle_ids = input('recycle_ids/a', []);

        if (empty($recycle_ids)) {
            return $this->error('请选择要删除的文件');
        }

        $recycle_list = Db::name('file_recycle')
            ->where('user_id', $this->user_id)
            ->where('id', 'in', $recycle_ids)
            ->select();

        if (empty($recycle_list)) {
            return $this->error('删除记录不存在');
        }

        $root_ids = array_map(function ($r) {
            return (int)$r['file_id'];
        }, $recycle_list);

        hard_delete_file_tree($this->user_id, $root_ids);

        log_operation('recycle', 'permanent_delete', '彻底删除文件:' . count($recycle_ids) . '个');

        return $this->success('删除成功');
    }

    /**
     * 清空回收站
     */
    public function clear()
    {
        $this->checkLogin();

        $roots = Db::name('file_recycle')
            ->where('user_id', $this->user_id)
            ->column('file_id');

        if (empty($roots)) {
            return $this->error('回收站为空');
        }

        hard_delete_file_tree($this->user_id, $roots);

        log_operation('recycle', 'clear', '清空回收站');

        return $this->success('清空成功');
    }
}
