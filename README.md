# 文件共享系统

基于 ThinkPHP 5.1 开发的企业级文件共享系统（前后端不分离）。

## 功能特性

- **用户权限管理**: Session认证、角色权限控制(RBAC)
- **目录文件 CRUD**: 创建、读取、更新、删除文件和目录
- **分片上传**: 多文件/拖拽上传,超过 5MB 自动分片,支持断点续传与重试、秒传去重
- **断点下载**: HTTP Range 断点续传;多选/整目录打包 zip 下载
- **在线预览**: 图片、文本、PDF、音视频浏览器内直接预览(支持 Range 拖动播放)
- **分享链接**: 支持密码保护(提取码)、有效期设置
- **内部共享**: 用户间文件共享，支持权限控制
- **回收站**: 30天自动清理，整棵目录树还原/彻底删除(物理文件引用计数安全)
- **操作日志**: 完整的操作审计日志
- **存储管理**: 存储配额、使用统计、文件类型分析
- **异步任务队列**: 支持定时任务和异步处理(`php think task:worker`)

## 环境要求

- PHP >= 7.0
- MySQL >= 5.6
- Apache/Nginx

## 安装部署

### 1. 配置数据库

编辑 `config/database.php`，配置数据库连接信息(本机 phpStudy 默认 root/root)：

```php
'database' => 'file',
'username' => 'root',
'password' => 'root',
```

### 2. 导入数据库

```bash
mysql -u root -p your_database_name < database/schema.sql
```

### 3. 配置Web服务器

#### Nginx 配置示例

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/online_file/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

#### Apache 配置

确保开启了 `mod_rewrite`，项目已包含 `.htaccess` 文件。

### 4. 设置目录权限

```bash
chmod -R 755 runtime/
chmod -R 755 public/uploads/
```

### 5. 启动异步任务工作器(可选)

```bash
php think task:worker
```

## 默认账号

- 用户名: `admin`
- 密码: `admin123`

## 目录结构

```
online_file/
├── application/
│   ├── index/
│   │   ├── controller/          # 控制器
│   │   │   ├── Base.php         # 基础控制器
│   │   │   ├── Auth.php         # 认证模块
│   │   │   ├── File.php         # 文件管理
│   │   │   ├── Share.php        # 分享管理
│   │   │   ├── Recycle.php      # 回收站
│   │   │   ├── Admin.php        # 管理后台
│   │   │   └── Index.php        # 首页
│   │   └── view/                # 视图模板
│   │       ├── common/
│   │       │   └── layout.html  # 公共布局
│   │       ├── auth/            # 认证视图
│   │       ├── file/            # 文件视图
│   │       ├── share/           # 分享视图
│   │       ├── recycle/         # 回收站视图
│   │       └── admin/           # 管理后台视图
│   ├── command/
│   │   └── TaskWorker.php       # 异步任务工作器
│   ├── common/
│   │   └── Jwt.php              # JWT认证类
│   └── common.php               # 公共函数库
├── config/
│   ├── database.php             # 数据库配置
│   ├── app.php                  # 应用配置
│   └── file.php                 # 文件上传配置
├── database/
│   └── schema.sql               # 数据库结构
├── public/
│   └── uploads/                 # 上传文件目录
├── route/
│   └── route.php                # 路由配置
└── README.md                    # 说明文档
```

## 页面路由

| 路由 | 说明 |
|------|------|
| `/` | 首页(文件列表) |
| `/login` | 登录 |
| `/register` | 注册 |
| `/profile` | 个人资料 |
| `/changePassword` | 修改密码 |
| `/file` | 文件列表 |
| `/file/createFolder` | 新建目录 |
| `/file/upload` | 上传文件 |
| `/file/detail` | 文件详情 |
| `/file/preview` | 在线预览(图片/文本/PDF/音视频) |
| `/file/downloadZip` | 多选/目录打包下载 |
| `/file/chunkInit` `chunkUpload` `chunkMerge` `chunkStatus` | 分片上传(断点续传)API |
| `/share` | 我的分享 |
| `/share/create` | 创建分享 |
| `/share/view` | 查看分享(公开) |
| `/share/sharedWithMe` | 共享给我 |
| `/recycle` | 回收站 |
| `/admin/users` | 用户管理 |
| `/admin/roles` | 角色管理 |
| `/admin/logs` | 操作日志 |
| `/admin/storage` | 存储管理 |
| `/admin/tasks` | 异步任务 |

## 技术栈

- **框架**: ThinkPHP 5.1
- **认证**: Session + JWT
- **数据库**: MySQL
- **密码加密**: password_hash (bcrypt)
- **前端**: Bootstrap 3 + jQuery + Layer

## 安全特性

- Session + JWT 双重认证
- 密码 bcrypt 加密
- 文件哈希秒传
- 操作日志审计
- 角色权限控制(RBAC)
- 分享链接密码保护
- 存储配额限制

## 许可证

Apache-2.0