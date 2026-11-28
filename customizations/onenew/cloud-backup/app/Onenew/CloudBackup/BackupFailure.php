<?php
namespace App\Onenew\CloudBackup;
final class BackupFailure extends \RuntimeException
{
    public function __construct(public string $reason){parent::__construct($reason);}
    public static function message(?string $reason):string
    {
        return ['not_configured'=>'请先保存完整的 Cloudflare R2 / S3 配置和备份密码。','invalid_endpoint'=>'R2 / S3 Endpoint 地址无效，请使用 HTTPS 公网地址。','invalid_bucket'=>'存储桶（Bucket）名称格式无效。','bucket_not_found'=>'未找到指定的存储桶（Bucket），请检查名称和 Endpoint。','authorization_expired'=>'R2 / S3 密钥认证失败，请检查 Access Key ID 和 Secret Access Key。','permission_denied'=>'无权访问此存储桶，请确认 API 令牌拥有对象读写权限。','quota_exceeded'=>'存储空间或请求配额不足。','network_error'=>'网络连接失败，已完成有限次数重试。','remote_error'=>'Cloudflare R2 / S3 服务暂时不可用，已完成有限次数重试。','protocol_error'=>'存储服务返回了无法验证的上传响应。','integrity_failed'=>'远端备份文件大小或校验值不匹配，未标记为成功。','local_error'=>'本地迁移包生成失败，请检查磁盘空间和部署状态。','busy'=>'已有迁移或备份任务正在生成快照，请稍后重试。','timeout'=>'任务超时，已停止本次尝试。','restored_paused'=>'这是迁移前的任务；新服务器已暂停自动备份。','settings_changed'=>'配置已变更，请刷新后重试。','identity_invalid'=>'本地备份身份文件不可读取，请检查持久化目录。'][$reason??'']??'备份失败，请检查部署及后台服务状态。';
    }
}
