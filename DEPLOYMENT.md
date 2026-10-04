# 宝塔 Linux 全新部署、更新与回滚

本文是当前唯一的生产部署说明，适用于把本项目作为新站点部署到宝塔 Linux。发布方式为“版本目录 + 共享数据 + 原子软链接”：代码更新不覆盖正在运行的版本，失败会自动切回，`.env`、上传文件、Session、日志和预览缓存不会随代码回滚而丢失。

## 1. 最终目录结构

```text
/www/wwwroot/online_file/
├── current -> releases/20261004123000_a1b2c3d4e5
├── previous -> releases/20260930110000_1234567890
├── releases/                 # 每次发布的独立代码和 vendor
├── shared/
│   ├── .env                  # 生产配置，不进入 Git
│   ├── uploads/              # 用户上传文件
│   └── runtime/              # Session、日志、缓存及预览转换结果
├── backups/                  # 发布前数据库备份
├── repository.git/           # 发布脚本维护的 Git 镜像
└── deployments.log           # 发布/回滚记录

/opt/online_file_deployer/    # 只用于运行发布脚本的控制仓库
```

宝塔网站目录固定设置为：

```text
/www/wwwroot/online_file/current/public
```

## 2. 宝塔准备

建议环境：

- Nginx
- MySQL 5.7 或 8.0
- PHP 7.4（至少 PHP 7.0）
- PHP 扩展：`mysqli`、`pdo_mysql`、`mbstring`、`fileinfo`、`openssl`、`json`、`curl`、`zip`
- Composer 2
- 可选 LibreOffice，用于 Office 文件转 PDF 预览

宝塔中新建数据库，例如：

```text
数据库名：file
用户名：file
字符集：utf8mb4
```

暂时不要上传代码，也不要从旧数据库导入任何测试数据。

## 3. 配置 GitHub Deploy Key

之前出现的 `Permission denied (publickey)` 表示服务器还没有获得仓库读取权限。以下命令全部在服务器执行，并使用之后运行发布命令的同一个 Linux 用户（通常是 `root`）。

```bash
install -d -m 700 ~/.ssh
ssh-keygen -t ed25519 -C "online-file-production" -f ~/.ssh/online_file_deploy -N ""
cat ~/.ssh/online_file_deploy.pub
```

复制输出的整行公钥，在 GitHub 仓库进入：

```text
Settings → Deploy keys → Add deploy key
```

名称填写 `online-file-production`，粘贴公钥，保持 `Allow write access` 未勾选。

编辑 `~/.ssh/config`，加入：

> 只复制下面代码块内部从 `Host` 开始的 5 行，不要把开头和结尾的三个反引号或 `sshconfig` 字样写入配置文件。

```sshconfig
Host github-online-file
    HostName github.com
    User git
    IdentityFile ~/.ssh/online_file_deploy
    IdentitiesOnly yes
```

设置权限并测试：

```bash
chmod 600 ~/.ssh/config ~/.ssh/online_file_deploy
chmod 644 ~/.ssh/online_file_deploy.pub
ssh -T git@github-online-file
```

首次连接出现的 GitHub ED25519 指纹应为：

```text
SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU
```

成功时会显示已通过身份验证、但 GitHub 不提供 Shell。Deploy Key 只授予这个仓库只读权限。

## 4. 下载发布工具并提交生产配置

```bash
git clone git@github-online-file:Wmengbing/online_file.git /opt/online_file_deployer
cp /opt/online_file_deployer/ops/deploy.conf.example /etc/online-file-deploy.conf
chmod 600 /etc/online-file-deploy.conf
vi /etc/online-file-deploy.conf
```

必须修改以下配置：

- `PHP_BIN`：对应宝塔 PHP 版本，例如 `/www/server/php/74/bin/php`。
- `COMPOSER_BIN` 或 `COMPOSER_PHAR`：服务器 Composer 实际位置。
- `HEALTHCHECK_HOST`：宝塔站点域名。
- `POST_SWITCH_COMMAND`：对应 PHP-FPM 版本；如果启用队列，再追加 Supervisor 重启命令。
- MySQL 客户端路径：不同宝塔版本可能不同，可用 `find /www/server -name mysql -type f` 查找。

验证 PHP 和 Composer：

```bash
/www/server/php/74/bin/php -v
composer --version
```

## 5. 创建共享目录和 `.env`

```bash
mkdir -p /www/wwwroot/online_file/shared/uploads
mkdir -p /www/wwwroot/online_file/shared/runtime
cp /opt/online_file_deployer/.env.example /www/wwwroot/online_file/shared/.env
vi /www/wwwroot/online_file/shared/.env
```

生产 `.env` 至少确认：

```ini
APP_DEBUG = false
COOKIE_SECURE = true
DB_HOST = 127.0.0.1
DB_PORT = 3306
DB_NAME = file
DB_USER = file
DB_PASS = "替换成宝塔数据库密码"
JWT_SECRET = "替换成随机密钥"
OFFICE_CONVERTER = /usr/bin/libreoffice
```

生成 JWT 密钥：

```bash
openssl rand -hex 32
```

如果暂时没有配置 HTTPS，将 `COOKIE_SECURE` 临时设为 `false`；启用证书后必须改回 `true`。

设置权限：

```bash
chown -R www:www /www/wwwroot/online_file/shared/uploads
chown -R www:www /www/wwwroot/online_file/shared/runtime
chown root:www /www/wwwroot/online_file/shared/.env
chmod 640 /www/wwwroot/online_file/shared/.env
```

## 6. 配置自动数据库备份

创建仅 root 可读的 MySQL 客户端配置：

```ini
# /root/.online-file-mysql.cnf
[client]
host=127.0.0.1
port=3306
user=file
password=替换成宝塔数据库密码
default-character-set=utf8mb4
```

```bash
chmod 600 /root/.online-file-mysql.cnf
```

发布脚本会在每次代码切换前生成压缩备份，默认保存在：

```text
/www/wwwroot/online_file/backups/
```

## 7. 初始化纯净数据库

项目提供的 [ops/schema.sql](ops/schema.sql) 只有数据表、基础角色和权限，不包含本地测试用户、日志、文件记录或分享令牌。

执行一次：

```bash
cd /opt/online_file_deployer
bash ops/init-database.sh
```

脚本会要求输入第一个管理员的用户名、邮箱和至少 12 位密码。密码只用于生成 bcrypt 哈希，不会写入命令历史或日志。数据库已有用户时脚本会拒绝再次初始化。

## 8. 在宝塔创建站点

添加站点并配置域名、PHP 版本和 SSL。网站目录填写：

```text
/www/wwwroot/online_file/current/public
```

首次发布前 `current` 尚不存在，宝塔可能提示目录不存在，可以先执行：

```bash
mkdir -p /www/wwwroot/online_file/releases/bootstrap/public
ln -s /www/wwwroot/online_file/releases/bootstrap /www/wwwroot/online_file/current
```

Nginx 伪静态规则：

```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^(.*)$ /index.php?s=$1 last;
        break;
    }
}

location ~ /\. {
    deny all;
}
```

确认 PHP 上传限制满足业务需求，至少检查 `upload_max_filesize`、`post_max_size`、`max_execution_time` 和 Nginx 的 `client_max_body_size`。大文件采用分片上传，但反向代理限制仍不能小于单个分片大小。

## 9. 首次发布

先在本地提交全部项目改动并创建不可变发布标签：

```bash
git add application config public route ops .env.example .gitattributes README.md DEPLOYMENT.md composer.json composer.lock
git commit -m "release: production deployment and file workspace improvements"
git tag release-20261004-01
git push origin main
git push origin release-20261004-01
```

服务器执行：

```bash
cd /opt/online_file_deployer
git pull --ff-only
bash ops/deploy.sh release-20261004-01
```

发布脚本会自动完成：

1. 拉取指定标签并创建独立版本目录。
2. 连接共享 `.env`、上传目录和运行目录。
3. 安装生产 Composer 依赖。
4. 检查全部 PHP 文件语法。
5. 备份数据库。
6. 原子切换 `current` 软链接。
7. 重载 PHP-FPM 并通过本机 Nginx 检查 `/login`。
8. 检查失败时自动恢复上一版本。

发布后检查：

```bash
readlink -f /www/wwwroot/online_file/current
curl -I -H 'Host: 你的域名' http://127.0.0.1/login
tail -n 50 /www/wwwroot/online_file/deployments.log
```

然后登录并验证上传、下载、文件夹上传、移动、复制、全局搜索和预览。

## 10. 日常代码更新

每次上线都创建新标签，不直接发布浮动的 `main`：

```bash
# 本地
git add application config public route ops README.md DEPLOYMENT.md
git commit -m "fix: describe this release"
git tag release-20261010-01
git push origin main
git push origin release-20261010-01
```

```bash
# 服务器
cd /opt/online_file_deployer
git pull --ff-only
bash ops/deploy.sh release-20261010-01
```

若只是发布旧标签，不需要修改控制仓库；`deploy.sh` 会从 GitHub 镜像获取目标版本。

## 11. 回滚

回滚到上一个成功版本：

```bash
cd /opt/online_file_deployer
bash ops/rollback.sh
```

回滚到指定版本目录：

```bash
ls -1 /www/wwwroot/online_file/releases
bash ops/rollback.sh 20261004123000_a1b2c3d4e5
```

回滚也会清缓存、重载服务并执行健康检查；失败时会恢复回滚前的版本。

代码回滚不会自动恢复数据库。如果未来版本有不兼容的结构变更，必须先进入维护模式，再明确选择 `backups/` 中对应的 SQL 备份恢复，避免误覆盖新数据。

## 12. 运维检查

查看当前版本：

```bash
readlink -f /www/wwwroot/online_file/current
cat /www/wwwroot/online_file/current/.release-ref
cat /www/wwwroot/online_file/current/.release-commit
```

查看发布历史和备份：

```bash
tail -n 20 /www/wwwroot/online_file/deployments.log
ls -lh /www/wwwroot/online_file/backups
```

默认保留当前版本之外最近 5 个历史版本以及 14 天数据库备份，可在 `/etc/online-file-deploy.conf` 调整。

建议把以下内容加入日常维护：

- 定期把 `shared/uploads` 和 `backups` 同步到异机或对象存储。
- 监控磁盘空间、Nginx/PHP 错误日志和数据库容量。
- 定期测试回滚和数据库恢复，不只确认备份文件存在。
- 若启用 `php think task:worker`，用 Supervisor 守护，并在 `POST_SWITCH_COMMAND` 中重启它。
