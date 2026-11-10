<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use AD5jp\PhpChatwork\Message;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use RuntimeException;

/**
 * ad5jp/php-chatwork の Message に、送るときのタイムアウトを付けたもの
 *
 * Message::send() は毎回タイムアウトの無い Client を作るので、送るところだけを書き直す（本文の組み立て build() は Message のまま）。
 * php-chatwork が Client やタイムアウトを渡せるようになったら、このクラスは消して Message をそのまま使ってよい
 */
final class TimeoutMessage extends Message
{
    private float $timeout = 3.0;

    /** テストで通信を差し替える Guzzle の handler。null なら本物の通信 */
    private mixed $handler = null;

    public function withTimeout(float $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * テストで通信を差し替える。本番では使わない
     */
    public function withHandler(callable $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function send(): static
    {
        if ($this->room === null || $this->token === null) {
            throw new RuntimeException('room or token is not specified');
        }

        $options = ['timeout' => $this->timeout, 'connect_timeout' => $this->timeout];
        if (is_callable($this->handler)) {
            $options['handler'] = HandlerStack::create($this->handler);
        }

        $response = new Client($options)->post(
            "https://api.chatwork.com/v2/rooms/{$this->room}/messages",
            [
                'headers' => ['X-ChatWorkToken' => $this->token, 'accept' => 'application/json'],
                'form_params' => ['body' => $this->build()],
            ],
        );

        if ($response->getStatusCode() > 300) {
            throw new RuntimeException(sprintf('Request Failed (%s)', $response->getStatusCode()));
        }

        return $this;
    }
}
