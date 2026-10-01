<?php

namespace App\Services\Banking;

final class EnableBankingPsuContext
{
    private ?string $ipAddress = null;

    private ?string $userAgent = null;

    public function set(?string $ipAddress, ?string $userAgent): void
    {
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    public function clear(): void
    {
        $this->ipAddress = null;
        $this->userAgent = null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        if ($this->ipAddress === null || $this->ipAddress === ''
            || $this->userAgent === null || $this->userAgent === '') {
            return [];
        }

        return [
            'Psu-Ip-Address' => $this->ipAddress,
            'Psu-User-Agent' => $this->userAgent,
        ];
    }
}
