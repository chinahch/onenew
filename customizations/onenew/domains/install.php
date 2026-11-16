<?php
function applyOnenewUserDomains(string $root): void
{
    replaceOnce($root.'/app/Http/Requests/Admin/ConfigSave.php',
        "        'app_url' => 'nullable|url',",
        "        'user_frontend_urls' => 'sometimes|array|max:20',\n"
        ."        'user_frontend_urls.*' => 'required|string|max:255|url:http,https|regex:~^https?://[^/?#@\\\\s]+/?$~i',\n"
        ."        'app_url' => 'nullable|url',");
    replaceOnce($root.'/app/Http/Controllers/V2/Admin/ConfigController.php',
        "                'app_url' => admin_setting('app_url'),",
        "                'user_frontend_urls' => admin_setting('user_frontend_urls', []),\n"
        ."                'app_url' => admin_setting('app_url'),");
    replaceOnce($root.'/app/Http/Controllers/V2/Admin/ConfigController.php',
        '        $data = $request->validated();',
        '        $data = $request->validated();'."\n"
        .'        if (array_key_exists(\'user_frontend_urls\', $data)) {'."\n"
        .'            $data[\'user_frontend_urls\'] = \\App\\Onenew\\UserFrontendDomains::normalize($data[\'user_frontend_urls\']);'."\n"
        .'        }');
    replaceOnce($root.'/app/Http/Requests/Admin/ConfigSave.php',
        '    public function messages()',
        <<<'PHP'
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $origins = $this->input('user_frontend_urls');
            if (!is_array($origins)) return;
            try { \App\Onenew\UserFrontendDomains::normalize($origins); }
            catch (\InvalidArgumentException $e) { $validator->errors()->add('user_frontend_urls', $e->getMessage()); }
        });
    }

    public function messages()
PHP);
}
