import test from 'node:test';
import assert from 'node:assert/strict';
import {UpgradeCommand} from './src/upgrade-command.mjs';

test('upgrade card shows the copyable command without a token value',()=>{
 const React={createElement:(type,props,...children)=>({type,props:{...props,children}}),useState:value=>[value,()=>{}]};
 const tree=UpgradeCommand({react:React,command:'read -rsp Token NODE_TOKEN; bash install.sh --upgrade --token "$NODE_TOKEN"',loading:false});
 const nodes=[];function visit(node){if(!node||typeof node!=='object')return;nodes.push(node);for(const child of node.props?.children||[])visit(child)}visit(tree);
 assert.equal(tree.props['aria-label'],'升级 caddy 节点');
 assert.ok(nodes.some(node=>node.type==='button'&&node.props.children.includes('复制升级命令')));
 assert.ok(nodes.some(node=>node.type==='code'&&node.props.children.some(value=>String(value).includes('--upgrade'))));
 assert.ok(!JSON.stringify(tree).includes('fixture-private-token'));
});

