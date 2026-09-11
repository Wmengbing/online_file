<?php
use think\facade\Route;

// 首页
Route::get('/', 'index/file/index');

// 认证模块
Route::any('login', 'index/auth/login');
Route::any('register', 'index/auth/register');
Route::get('logout', 'index/auth/logout');
Route::any('profile', 'index/auth/profile');
Route::any('changePassword', 'index/auth/changePassword');

// 文件管理
Route::get('file', 'index/file/index')->completeMatch();
Route::any('file/createFolder', 'index/file/createFolder')->completeMatch();
Route::any('file/upload', 'index/file/upload')->completeMatch();
Route::get('file/download', 'index/file/download')->completeMatch();
Route::any('file/rename', 'index/file/rename')->completeMatch();
Route::any('file/move', 'index/file/move')->completeMatch();
Route::post('file/delete', 'index/file/delete')->completeMatch();
Route::get('file/detail', 'index/file/detail')->completeMatch();
Route::get('file/preview', 'index/file/preview')->completeMatch();
Route::get('file/raw', 'index/file/raw')->completeMatch();
Route::any('file/downloadZip', 'index/file/downloadZip')->completeMatch();

// 分片上传
Route::any('file/chunkInit', 'index/file/chunkInit')->completeMatch();
Route::post('file/chunkUpload', 'index/file/chunkUpload')->completeMatch();
Route::get('file/chunkStatus', 'index/file/chunkStatus')->completeMatch();
Route::post('file/chunkMerge', 'index/file/chunkMerge')->completeMatch();

// 上传任务管理
Route::get('file/uploadTasks', 'index/file/uploadTasks')->completeMatch();
Route::get('file/getUploadTasks', 'index/file/getUploadTasks')->completeMatch();
Route::post('file/deleteUploadTask', 'index/file/deleteUploadTask')->completeMatch();

// 分享管理
Route::get('share', 'index/share/index')->completeMatch();
Route::any('share/create', 'index/share/create')->completeMatch();
Route::post('share/delete', 'index/share/delete')->completeMatch();
Route::post('share/toggleStatus', 'index/share/toggleStatus')->completeMatch();
Route::any('share/view', 'index/share/view')->completeMatch();
Route::get('share/download', 'index/share/download')->completeMatch();
Route::any('share/internalShare', 'index/share/internalShare')->completeMatch();
Route::get('share/sharedWithMe', 'index/share/sharedWithMe')->completeMatch();

// 回收站
Route::get('recycle', 'index/recycle/index')->completeMatch();
Route::post('recycle/restore', 'index/recycle/restore');
Route::post('recycle/permanentDelete', 'index/recycle/permanentDelete');
Route::post('recycle/clear', 'index/recycle/clear');

// 管理后台
Route::get('admin/users', 'index/admin/users');
Route::any('admin/createUser', 'index/admin/createUser');
Route::any('admin/editUser', 'index/admin/editUser');
Route::post('admin/deleteUser', 'index/admin/deleteUser');
Route::get('admin/roles', 'index/admin/roles');
Route::any('admin/createRole', 'index/admin/createRole');
Route::any('admin/editRole', 'index/admin/editRole');
Route::post('admin/deleteRole', 'index/admin/deleteRole');
Route::get('admin/logs', 'index/admin/logs');
Route::get('admin/storage', 'index/admin/storage');
Route::post('admin/updateQuota', 'index/admin/updateQuota');
Route::get('admin/tasks', 'index/admin/tasks');
Route::any('admin/createTask', 'index/admin/createTask');