<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditChainVerifier;
use Illuminate\Console\Command;

final class VerifyAuditChain extends Command
{
    protected $signature = 'racklab:verify-audit-chain';

    protected $description = 'Verify RackLab audit event hash-chain integrity.';

    public function handle(AuditChainVerifier $verifier): int
    {
        $result = $verifier->verify();

        if ($result->valid) {
            $this->info('Audit chain verified.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Audit chain verification failed at event %d: %s',
            $result->eventId,
            $result->reason,
        ));

        return self::FAILURE;
    }
}
