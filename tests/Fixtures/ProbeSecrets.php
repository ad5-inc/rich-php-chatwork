<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests\Fixtures;

/**
 * protected・private の値を持つ作り物（print_r・serialize・var_export の書き出しを作る）
 */
final class ProbeSecrets
{
    public string $name = 'a';

    protected string $password = 'probe-pw-6';

    private string $token = 'probe-tk-6';

    public function token(): string
    {
        return $this->token;
    }
}
