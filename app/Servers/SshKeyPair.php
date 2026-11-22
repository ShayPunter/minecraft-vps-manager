<?php

namespace App\Servers;

use phpseclib3\Crypt\EC;

/**
 * A fresh ed25519 key pair for one VPS (#5324), made in PHP (no ssh-keygen, no shell).
 * The private half is stored encrypted on the run and never shown or logged.
 */
final readonly class SshKeyPair
{
    private function __construct(
        public string $privateKey,
        public string $publicKey,
    ) {}

    /**
     * @param  string  $comment  goes at the end of the public key (`ssh-ed25519 AAAA… <comment>`)
     */
    public static function generate(string $comment): self
    {
        $key = EC::createKey('Ed25519');

        return new self(
            privateKey: $key->toString('OpenSSH'),
            publicKey: trim($key->getPublicKey()->toString('OpenSSH', ['comment' => $comment])),
        );
    }

    /**
     * Keep the private key out of var_dump(), dd() and exception traces.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['publicKey' => $this->publicKey, 'privateKey' => '[hidden]'];
    }
}
