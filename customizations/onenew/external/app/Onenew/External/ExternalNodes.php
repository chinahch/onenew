<?php
namespace App\Onenew\External;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ExternalNodes
{
    public const TABLE = 'v2_onenew_external_nodes';

    public static function append(array $servers, $user): array
    {
        if ($user->banned || (int)$user->u+(int)$user->d >= (int)$user->transfer_enable ||
            ($user->expired_at !== null && (int)$user->expired_at <= time())) return $servers;
        $names = array_fill_keys(array_column($servers, 'name'), true);
        foreach (DB::table(self::TABLE)->where('enabled', true)->orderBy('sort')->orderBy('id')->get() as $row) {
            $users = json_decode($row->user_ids, true, 16, JSON_THROW_ON_ERROR);
            $groups = json_decode($row->group_ids, true, 16, JSON_THROW_ON_ERROR);
            if (!in_array((int)$user->id, $users, true) && !in_array((int)$user->group_id, $groups, true)) continue;
            $link = Crypt::decryptString($row->link_ciphertext); $parsed = ShareLink::parse($link);
            $name = $row->name;
            if (isset($names[$name])) $name .= ' (外部 #'.$row->id.')';
            while (isset($names[$name])) $name .= '·';
            $names[$name] = true;
            $servers[] = ['id'=>-$row->id, 'name'=>$name, 'type'=>$parsed['type'], 'host'=>$parsed['host'],
                'port'=>(int)$parsed['port'], 'password'=>$parsed['username'], 'rate'=>1, 'tags'=>['外部节点'],
                'protocol_settings'=>['version'=>$parsed['type']==='hysteria'?2:null, 'network'=>$parsed['params']['type'] ?? 'tcp'],
                'onenew_external_link'=>$link];
        }
        return $servers;
    }

    public static function convert(array $node, string $format): array
    {
        try { return ShareLink::convert($node['onenew_external_link'], $format, $node['name']); }
        catch (\InvalidArgumentException|\JsonException $error) {
            // No credentials or full original URLs in HTTP errors/logs.
            abort(422, '外部节点「'.$node['name'].'」无法转换：'.$error->getMessage());
        }
    }

    public static function validateClient(array $nodes, string $protocolClass): void
    {
        $declaring = (new \ReflectionMethod($protocolClass, 'handle'))->getDeclaringClass()->getName();
        if (in_array($declaring, [\App\Protocols\General::class, \App\Protocols\Shadowrocket::class], true)) {
            return;
        }
        foreach ($nodes as $node) {
            if (!isset($node['onenew_external_link'])) continue;
            abort_unless(in_array($declaring, [\App\Protocols\Clash::class,
                \App\Protocols\ClashMeta::class,\App\Protocols\SingBox::class],true),422,'该订阅格式暂不支持外部节点，请使用通用链接、Mihomo 或 sing-box');
            if ($declaring === \App\Protocols\Clash::class) abort_unless(in_array($node['type'],['shadowsocks','vmess','trojan','socks','http'],true),422,'该外部节点需要 Mihomo，不支持旧版 Clash');
            self::convert($node,$declaring===\App\Protocols\SingBox::class?'singbox':'clash');
        }
    }
}
