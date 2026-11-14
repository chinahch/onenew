<?php

namespace App\Onenew;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MachineEgressController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate(['machine_id' => 'required|integer|exists:v2_server_machine,id']);
        return $this->success(MachineEgress::settings(ServerMachine::findOrFail($params['machine_id'])));
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'machine_identity' => 'required|uuid',
            'node_identities' => 'present|array|max:10000',
            'node_identities.*' => 'required|uuid',
            'mode' => ['required', 'string', Rule::in(EgressPolicy::MODES)],
            'node_overrides' => 'present|array|max:10000',
            'node_overrides.*' => ['required', 'string', Rule::in(EgressPolicy::MODES)],
        ]);
        return $this->success(MachineEgress::save(ServerMachine::findOrFail($params['machine_id']), $params['mode'], $params['node_overrides'], $params['machine_identity'], $params['node_identities']));
    }
}
