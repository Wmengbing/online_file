# 文件共享系统

基于 ThinkPHP 5.1 开发的企业级文件共享系统（前后端不分离）。

## 功能特性

- **用户权限管理**: Session认证、角色权限控制(RBAC)
- **目录文件 CRUD**: 创建、读取、更新、删除文件和目录
- **分片上传**: 多文件/拖拽上传,超过 5MB 自动分片,支持断点续传与重试、秒传去重
- **断点下载**: HTTP Range 断点续传;多选/整目录打包 zip 下载
- **全局搜索**: 默认检索全部可访问文件、角色目录和内部共享目录，也可限制为当前目录
- **在线预览**: 本地 PDF.js、图片/文本/音视频预览；Office 可通过 LibreOffice 转 PDF，并提供浏览器解析降级
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
- LibreOffice（可选，用于 `.doc/.xls/.ppt` 等 Office 文件转 PDF 在线预览）

## 安装部署

生产环境统一使用宝塔 Linux 原子发布方案，不再手工覆盖线上目录。完整步骤见 [宝塔 Linux 全新部署、更新与回滚](DEPLOYMENT.md)。

部署工具包括：

- `ops/init-database.sh`：导入纯净结构并交互创建首个管理员。
- `ops/deploy.sh <版本标签>`：备份数据库、安装依赖、检查并原子发布。
- `ops/rollback.sh`：一键回滚到上一个成功版本。

生产环境没有公开的默认管理员密码，首次初始化时必须自行设置至少 12 位密码。

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
├── ops/
│   ├── schema.sql               # 纯净生产数据库结构
│   ├── init-database.sh         # 首次数据库初始化
│   ├── deploy.sh                # 原子发布
│   ├── rollback.sh              # 一键回滚
│   └── supervisor-worker.conf.example # 可选任务进程配置
├── public/
│   └── static/                  # 前端静态资源
├── route/
│   └── route.php                # 路由配置
├── uploads/                     # 上传文件目录（不进入 Git）
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
| `/file/move` `/file/copy` | 批量移动或复制文件/目录树 |
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
