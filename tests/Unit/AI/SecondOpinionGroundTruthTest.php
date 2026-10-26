<?php

declare(strict_types=1);

use App\Ai\Decision\SecondOpinionGroundTruth;
use App\Enums\ActionRequestStatus;

test('only a person reviewing the action gives the expected verdict', function (ActionRequestStatus $actionRequestStatus, ?int $approvedBy, ?array $secondOpinion, ?bool $expected): void {
    expect(SecondOpinionGroundTruth::expectedAgrees($actionRequestStatus, $approvedBy, $secondOpinion))->toBe($expected);
})->with([
    'approved by a person' => [ActionRequestStatus::Approved, 7, ['agrees' => false, 'failed' => false], true],
    'approved, then executing' => [ActionRequestStatus::Executing, 7, ['agrees' => true, 'failed' => false], true],
    'approved, then completed' => [ActionRequestStatus::Completed, 7, ['agrees' => true, 'failed' => false], true],
    'approved, then failed' => [ActionRequestStatus::Failed, 7, ['agrees' => true, 'failed' => false], true],
    'rejected by a person' => [ActionRequestStatus::Rejected, 7, ['agrees' => true, 'failed' => false], false],
    'still pending' => [ActionRequestStatus::Pending, null, ['agrees' => false, 'failed' => false], null],
    'ran automatically' => [ActionRequestStatus::Completed, null, ['agrees' => true, 'failed' => false], null],
    'rejected by the second opinion' => [ActionRequestStatus::Rejected, null, ['agrees' => false, 'failed' => false], null],
    'a failed check a person approved' => [ActionRequestStatus::Completed, 7, ['agrees' => false, 'failed' => true], null],
    'no second opinion at all' => [ActionRequestStatus::Completed, 7, null, true],
]);
