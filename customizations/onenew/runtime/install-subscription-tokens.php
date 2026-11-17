<?php
/** Preserve native hex tokens and imported legacy ASCII alphanumeric tokens. */
function applyOnenewSubscriptionTokens(string $root):void
{
    replaceOnce($root.'/app/Utils/Helper.php',
        "private const SUBSCRIPTION_TOKEN_PATTERN = '/\\A[a-f0-9]{32}\\z/i';",
        "private const SUBSCRIPTION_TOKEN_PATTERN = '/\\A[A-Za-z0-9]{32}\\z/';");
    // MySQL installations may have a case-insensitive token column. Never
    // authenticate a case-folded spelling of a legacy case-sensitive token.
    replaceOnce($root.'/app/Http/Middleware/Client.php',
        'if (!$user) {',
        'if (!$user || !hash_equals((string) $user->token, $token)) {');
}
