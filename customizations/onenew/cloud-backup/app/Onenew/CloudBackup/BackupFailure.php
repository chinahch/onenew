<?php
namespace App\Onenew\CloudBackup;
final class BackupFailure extends \RuntimeException
{
    public function __construct(public string $reason){parent::__construct($reason);}
    public static function message(?string $reason):string
    {
        return ['not_configured'=>'请先保存完整的 Google OAuth 配置和备份密码。','authorization_expired'=>'Google 授权已失效，请更新 Refresh Token。','permission_denied'=>'Google 授权无权访问此文件夹，请检查授权范围与文件夹。','quota_exceeded'=>'Google 云盘空间或上传配额不足。','network_error'=>'网络连接失败，已完成有限次数重试。','remote_error'=>'Google 服务暂时不可用，已完成有限次数重试。','protocol_error'=>'Google 返回了无法验证的上传响应。','integrity_failed'=>'云盘文件大小或校验值不匹配，未标记为成功。','local_error'=>'本地迁移包生成失败，请检查磁盘空间和部署状态。','busy'=>'已有迁移或备份任务正在生成快照，请稍后重试。','timeout'=>'任务超时，已停止本次尝试。','restored_paused'=>'这是迁移前的任务；新服务器已暂停自动备份。','settings_changed'=>'配置已变更，请刷新后重试。','identity_invalid'=>'本地备份身份文件不可读取，请检查持久化目录。'][$reason??'']??'备份失败，请检查部署及后台服务状态。';
    }
}
