<?php

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\Role;
use App\Events\ApprovalChainCompleted;
use App\Events\ApprovalChainStopped;
use App\Events\ApprovalQueried;
use App\Events\ApprovalQueryAnswered;
use App\Models\Approval;
use App\Models\ApprovalQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sequential approval chains. At each step the approver has exactly three actions:
 *   approve  - passes the step on (the last approval completes the chain)
 *   reject   - ends the chain; a reason is required
 *   query    - asks a person in the chain a question; the step is paused until it is answered
 */
class ApprovalService
{
    /** @param list<Role> $roles in approval order */
    public function request(Model $subject, array $roles, string $stage = 'evaluation'): void
    {
        if ($roles === []) {
            throw new InvalidArgumentException('An approval chain needs at least one step.');
        }

        DB::transaction(function () use ($subject, $roles, $stage) {
            foreach (array_values($roles) as $i => $role) {
                Approval::create([
                    'approvable_type' => $subject->getMorphClass(),
                    'approvable_id' => $subject->getKey(),
                    'stage' => $stage,
                    'step_order' => $i + 1,
                    'role' => $role,
                    'decision' => ApprovalDecision::Pending,
                ]);
            }
        });
    }

    public function approve(Approval $approval, User $actor, ?string $note = null): void
    {
        $this->decide($approval, ApprovalDecision::Approved, $actor, $note);
    }

    public function reject(Approval $approval, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to reject.');
        }

        $this->decide($approval, ApprovalDecision::Rejected, $actor, $reason);
    }

    /** Ask $target (someone in this chain) a question. The step waits until it is answered. */
    public function query(Approval $approval, User $actor, User $target, string $question): ApprovalQuery
    {
        if (trim($question) === '') {
            throw new InvalidArgumentException('A query needs a question.');
        }

        return DB::transaction(function () use ($approval, $actor, $target, $question) {
            $approval = $this->lockActionable($approval, $actor);

            if ($target->is($actor)) {
                throw new InvalidArgumentException('You cannot direct a query to yourself.');
            }

            $chainRoles = Approval::where('approvable_type', $approval->approvable_type)
                ->where('approvable_id', $approval->approvable_id)
                ->where('stage', $approval->stage)
                ->get()->pluck('role')->all();

            if (! in_array($target->role, $chainRoles, true)) {
                throw new InvalidArgumentException('A query can only be directed to someone in the approval chain.');
            }

            if ($approval->hasOpenQuery()) {
                throw new InvalidArgumentException('This step already has an unanswered query.');
            }

            $query = ApprovalQuery::create([
                'approval_id' => $approval->id,
                'asked_by' => $actor->id,
                'directed_to' => $target->id,
                'question' => $question,
            ]);

            ApprovalQueried::dispatch($query);

            return $query;
        });
    }

    public function answerQuery(ApprovalQuery $query, User $actor, string $answer): void
    {
        if (trim($answer) === '') {
            throw new InvalidArgumentException('An answer cannot be empty.');
        }

        DB::transaction(function () use ($query, $actor, $answer) {
            $query = ApprovalQuery::whereKey($query->getKey())->lockForUpdate()->firstOrFail();

            if (! $query->isOpen()) {
                throw new InvalidArgumentException('This query was already answered.');
            }
            if (! $actor->role->isAdmin() && $actor->id !== $query->directed_to) {
                throw new AuthorizationException('Only the person the query was directed to can answer it.');
            }

            $query->update([
                'answer' => $answer,
                'answered_by' => $actor->id,
                'answered_at' => now(),
            ]);

            ApprovalQueryAnswered::dispatch($query);
        });
    }

    private function decide(Approval $approval, ApprovalDecision $decision, User $actor, ?string $note): void
    {
        DB::transaction(function () use ($approval, $decision, $actor, $note) {
            $approval = $this->lockActionable($approval, $actor);

            if ($approval->hasOpenQuery()) {
                throw new InvalidArgumentException('This step has an unanswered query; wait for the answer before deciding.');
            }

            $approval->update([
                'decision' => $decision,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'note' => $note,
            ]);

            $subject = $approval->approvable;

            if ($decision === ApprovalDecision::Rejected) {
                ApprovalChainStopped::dispatch($subject, $approval->stage, $decision, $actor, $note);
                return;
            }

            $stillPending = Approval::where('approvable_type', $approval->approvable_type)
                ->where('approvable_id', $approval->approvable_id)
                ->where('stage', $approval->stage)
                ->where('decision', ApprovalDecision::Pending)
                ->exists();

            if (! $stillPending) {
                ApprovalChainCompleted::dispatch($subject, $approval->stage, $actor);
            }
        });
    }

    /** Locks the step and checks it is pending, it is this person's turn, and they hold the role. */
    private function lockActionable(Approval $approval, User $actor): Approval
    {
        $approval = Approval::whereKey($approval->getKey())->lockForUpdate()->firstOrFail();

        if ($approval->decision !== ApprovalDecision::Pending) {
            throw new InvalidArgumentException('This step was already decided.');
        }
        if (! $actor->role->isAdmin() && $actor->role !== $approval->role) {
            throw new AuthorizationException('Only the '.$approval->role->value.' can act on this step.');
        }

        $earlierPending = Approval::where('approvable_type', $approval->approvable_type)
            ->where('approvable_id', $approval->approvable_id)
            ->where('stage', $approval->stage)
            ->where('step_order', '<', $approval->step_order)
            ->where('decision', ApprovalDecision::Pending)
            ->exists();

        if ($earlierPending) {
            throw new InvalidArgumentException('An earlier approval step is still pending.');
        }

        return $approval;
    }
}
