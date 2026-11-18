#!/usr/bin/env python3
"""Real pinned OpenCode server + deterministic model: verifies session API and tool execution.
No production credentials, provider calls, or project data. Requires npm and Python 3.10+.
"""
import base64
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import uuid
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VERSION = os.environ.get("ONENEW_OPENCODE_TEST_VERSION", "2.3.2")
V2 = VERSION.startswith("2.")
PASSWORD = "onenew-disposable-contract-only"
AUTH = "Basic " + base64.b64encode(("opencode:" + PASSWORD).encode()).decode()


def port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class Model(BaseHTTPRequestHandler):
    calls = 0
    errors = []

    def log_message(self, *args):
        pass

    def do_POST(self):
        try:
            body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
            type(self).calls += 1
            tools = [t.get("function", {}).get("name") for t in body.get("tools", [])]
            has_result = any(m.get("role") == "tool" for m in body.get("messages", []))
            if not has_result:
                tool = "shell" if V2 else "bash"
                assert tool in tools, "actual agent tools: " + str(tools)
                command = "[IO.File]::WriteAllText(\"opencode-proof.txt\",\"onenew-contract-proof\")" if os.name == "nt" else "printf onenew-contract-proof > opencode-proof.txt"
                arguments = json.dumps({"command": command, "description": "Write disposable integration proof"})
                message = {"role": "assistant", "content": None, "tool_calls": [{"id": "call_contract", "type": "function", "function": {"name": tool, "arguments": arguments}}]}
                finish = "tool_calls"
            else:
                message = {"role": "assistant", "content": "Verified disposable command completed."}
                finish = "stop"
            self.send_response(200)
            self.send_header("Content-Type", "text/event-stream" if body.get("stream") else "application/json")
            self.end_headers()
            if body.get("stream"):
                delta = dict(message)
                if "tool_calls" in delta:
                    delta["tool_calls"] = [dict(x, index=i) for i, x in enumerate(delta["tool_calls"])]
                for d, f in [(delta, None), ({}, finish)]:
                    event = {"id": "chatcmpl-contract", "object": "chat.completion.chunk", "created": int(time.time()), "model": "probe", "choices": [{"index": 0, "delta": d, "finish_reason": f}]}
                    self.wfile.write(("data: " + json.dumps(event) + "\n\n").encode())
                self.wfile.write(b"data: [DONE]\n\n")
                self.wfile.flush()
            else:
                self.wfile.write(json.dumps({"id": "chatcmpl-contract", "object": "chat.completion", "model": "probe", "choices": [{"index": 0, "message": message, "finish_reason": finish}], "usage": {"prompt_tokens": 1, "completion_tokens": 1, "total_tokens": 2}}).encode())
        except Exception as e:
            type(self).errors.append(type(e).__name__ + ": " + str(e))
            self.send_error(500)


def run():
    fake = ThreadingHTTPServer(("127.0.0.1", 0), Model)
    threading.Thread(target=fake.serve_forever, daemon=True).start()
    proc = None
    stage = tempfile.TemporaryDirectory(prefix="onenew-opencode-contract-")
    tmp = stage.name
    try:
        project = Path(tmp) / "project"
        project.mkdir()
        config = {"$schema": "https://opencode.ai/config.json", "model": "fixture/probe", "permission": {"*": "allow"}, "provider": {"fixture": {"npm": "@ai-sdk/openai-compatible", "name": "Disposable model", "options": {"baseURL": f"http://127.0.0.1:{fake.server_port}/v1", "apiKey": "disposable-fixture-only"}, "models": {"probe": {"name": "probe", "limit": {"context": 32768, "output": 4096}}}}}}
        (project / "opencode.json").write_text(json.dumps(config), encoding="utf-8")
        env = dict(os.environ)
        for name in ("XDG_DATA_HOME", "XDG_CONFIG_HOME", "XDG_STATE_HOME", "XDG_CACHE_HOME"):
            env[name] = str(Path(tmp) / name)
        env["OPENCODE_SERVER_PASSWORD"] = PASSWORD
        env["OPENCODE_DISABLE_DEFAULT_PLUGINS"] = "true"
        number = port()
        executable = shutil.which("npm.cmd" if os.name == "nt" else "npm")
        assert executable, "npm is required"
        command = [executable, "exec", "--yes", f"--package={'@opencode/cli' if V2 else 'opencode-ai'}@{VERSION}", "--", "opencode", "serve", "--hostname", "127.0.0.1", "--port", str(number)]
        if os.name == "nt":
            command = [os.environ.get("COMSPEC", "cmd.exe"), "/c"] + command
        with (Path(tmp) / "server.log").open("w", encoding="utf-8") as log:
            proc = subprocess.Popen(command, cwd=project, env=env, stdout=log, stderr=subprocess.STDOUT, start_new_session=os.name != "nt")
            root = f"http://127.0.0.1:{number}"
            directory = urllib.parse.urlencode({"directory": str(project)})

            def request(path, data=None, authenticated=True):
                headers = {"Content-Type": "application/json"}
                if authenticated:
                    headers["Authorization"] = AUTH
                if "?" not in path and path.startswith(("/session", "/permission", "/question")):
                    path += "?" + directory
                r = urllib.request.urlopen(urllib.request.Request(root + path, None if data is None else json.dumps(data).encode(), headers), timeout=12)
                raw = r.read()
                return r.status, json.loads(raw) if raw else None

            deadline = time.monotonic() + 90
            while True:
                try:
                    _, health = request("/api/info" if V2 else "/global/health")
                    assert health["version"] == VERSION
                    if not V2:
                        assert health["healthy"] is True
                    break
                except (OSError, ValueError):
                    if time.monotonic() >= deadline:
                        raise AssertionError("real OpenCode server did not become healthy")
                    time.sleep(0.25)
            try:
                request("/api/info" if V2 else "/global/health", authenticated=False)
                raise AssertionError("server password did not protect health endpoint")
            except urllib.error.HTTPError as e:
                assert e.code == 401
            _, schema = request("/openapi.json" if V2 else "/doc")
            assert ("/api/session/{sessionID}/prompt" if V2 else "/session/{sessionID}/prompt_async") in schema["paths"]
            client_session = "ses_" + uuid.uuid4().hex
            client_message = "msg_" + uuid.uuid4().hex
            if V2:
                _, response = request("/api/session", {"id": client_session, "title": "Disposable Onenew contract", "location": {"directory": str(project)}, "model": {"providerID": "fixture", "id": "probe"}})
                session = response["data"]
            else:
                _, session = request("/session", {"title": "Disposable Onenew contract"})
            session_id = session["id"]
            assert session_id == client_session if V2 else session_id.startswith("ses")
            if V2:
                code, acknowledgement = request(f"/api/session/{session_id}/prompt", {"id": client_message, "text": "Write the disposable proof with the shell tool, then report completion."})
                assert code == 200
                assert acknowledgement["data"]["id"] == client_message
                assert acknowledgement["data"]["sessionID"] == session_id
                assert acknowledgement["data"]["type"] == "user"
            else:
                code, _ = request(f"/session/{session_id}/prompt_async", {"messageID": client_message, "model": {"providerID": "fixture", "modelID": "probe"}, "agent": "build", "parts": [{"type": "text", "text": "Write the disposable proof with the bash tool, then report completion."}]})
                assert code == 204
            deadline = time.monotonic() + 100
            completed = None
            while time.monotonic() < deadline:
                _, raw_messages = request(f"/api/session/{session_id}/message?limit=100&order=asc" if V2 else f"/session/{session_id}/message?{directory}&limit=20")
                messages = raw_messages["data"] if V2 else raw_messages
                for m in messages:
                    info = m if V2 else m["info"]
                    if info.get("error"):
                        raise AssertionError("real server returned assistant error: " + str(info["error"].get("name")))
                    if info.get("type" if V2 else "role") == "assistant" and info.get("finish") not in (None, "tool-calls", "unknown") and info.get("time", {}).get("completed"):
                        completed = m
                if completed:
                    break
                time.sleep(0.3)
            assert not Model.errors, Model.errors

            assert completed, "real agent did not complete"
            assert (project / "opencode-proof.txt").read_text() == "onenew-contract-proof", "actual tool did not write the proof"
            assert any(p.get("type") == "tool" and p.get("name" if V2 else "tool") == ("shell" if V2 else "bash") and p.get("state", {}).get("status") == "completed" and p.get("state", {}).get("metadata", {}).get("exit") == 0 for m in messages for p in m.get("content" if V2 else "parts", [])), "no actual completed bash tool part"
            assert Model.calls == 2, "unexpected model retries"
            if V2:
                _, active = request("/api/session/active")
                assert session_id not in active["data"]
                assert any(m.get("type") == "idle" and m.get("outcome") == "succeeded" for m in messages)
            _, permissions = request(f"/api/session/{session_id}/permission" if V2 else "/permission")
            _, questions = request(f"/api/session/{session_id}/form" if V2 else "/question")
            assert isinstance(permissions, (list, dict)) and isinstance(questions, (list, dict))
            if V2:
                code, interrupted = request(f"/api/session/{session_id}/interrupt", {})
                assert code == 200 and isinstance(interrupted, dict)
            print(f"OpenCode {VERSION}: authenticated health, session, async prompt, actual shell file write and final message verified; 2 deterministic model calls.")
    finally:
        if proc:
            if os.name == "nt":
                subprocess.run(["taskkill", "/PID", str(proc.pid), "/T", "/F"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            else:
                import signal
                try:
                    os.killpg(proc.pid, signal.SIGTERM)
                except ProcessLookupError:
                    pass
            proc.wait(timeout=10)
        fake.shutdown()
        stage.cleanup()


if __name__ == "__main__":
    run()
