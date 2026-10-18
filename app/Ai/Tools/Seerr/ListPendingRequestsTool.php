<?php

declare(strict_types=1);

namespace App\Ai\Tools\Seerr;

use App\Ai\Concerns\AnswersSeerrRefusals;
use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListPendingRequestsTool extends BaseTool
{
    use AnswersSeerrRefusals;

    public function description(): Stringable|string
    {
        return 'List all Seerr requests currently in "pending" status (awaiting approval).';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $serviceConnection = ServiceConnection::resolveActive(ServiceType::Seerr);

        return $this->readSeerr(fn (): array => new SeerrClient($serviceConnection)->getRequests(['filter' => 'pending']));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
