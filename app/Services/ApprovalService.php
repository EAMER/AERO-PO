<?php

namespace App\Services;

use App\Enums\ApprovalDecision as Decision;
use App\Enums\PurchaseOrderStatus as Status;
use App\Enums\Role;
use App\Events\ApprovalChainCompleted;
use App\Events\ApprovalChainStopped;
use App\Events\ApprovalQueried;
use App\Events\ApprovalQueryAnswered;
use App\Events\ApprovalStepOpened;
use App\Models\Approval;
use App\Models\ApprovalQuery;
use App\Models\Invoice;
use App\Models\PaymentStep;
use App\Models\PurchaseOrder;
use App\Models\QuoteEvaluation;
use App\Models\Shipment;
use App\Models\User;
use App\Support\ProcurementAccess as Access;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApprovalService
{
    public function actionableFor(User $user): Collection
    {
        Access::roles($user, ...Role::cases());

        return Approval::with(['approvable', 'queries'])->where('is_active', true)->where('decision', Decision::Pending)
            ->when(! $user->role->isAdmin(), fn (Builder $q) => $q->where('role', $user->role)->where(fn (Builder $q) => $q->whereNull('assigned_to')->orWhere('assigned_to', $user->id)))
            ->whereDoesntHave('queries', fn (Builder $q) => $q->whereNull('answered_at'))
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('approvals as previous')
                    ->whereColumn('previous.tenant_id', 'approvals.tenant_id')
                    ->whereColumn('previous.approvable_type', 'approvals.approvable_type')
                    ->whereColumn('previous.approvable_id', 'approvals.approvable_id')
                    ->whereColumn('previous.stage', 'approvals.stage')->whereColumn('previous.round', 'approvals.round')
                    ->whereColumn('previous.step_order', '<', 'approvals.step_order')->where('previous.decision', '!=', Decision::Approved->value);
            })->orderBy('created_at')->get()->filter(fn (Approval $a) => $this->subjectIsOpen($a))->values();
    }

    public function openQueriesFor(User $user): Collection
    {
        return ApprovalQuery::whereNull('answered_at')->where('directed_to', $user->id)
            ->whereHas('approval', fn (Builder $q) => $q->where('is_active', true))
            ->with(['approval.approvable', 'asker'])->get();
    }

    public function request(Model $subject, array $roles, string $stage = 'evaluation'): void
    {
        Access::tenant($subject);
        if ($roles === [] || collect($roles)->contains(fn ($role) => ! $role instanceof Role)) {
            throw new InvalidArgumentException('An approval chain needs valid approver roles.');
        }
        DB::transaction(function () use ($subject, $roles, $stage) {
            $subject->newQuery()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $chain = Approval::where('approvable_type', $subject->getMorphClass())->where('approvable_id', $subject->id)->where('stage', $stage);
            if ((clone $chain)->where('is_active', true)->where('decision', Decision::Pending)->exists()) {
                throw new InvalidArgumentException('This approval chain is already open.');
            }
            $round = ((clone $chain)->max('round') ?? 0) + 1;
            $chain->update(['is_active' => false]);
            $settings = app(TenantSettingsService::class);
            $hours = data_get(TenantContext::get()?->settings, 'approval_sla_hours.'.$stage);
            $first = null;
            foreach (array_values($roles) as $i => $role) {
                $approval = Approval::create([
                    'approvable_type' => $subject->getMorphClass(), 'approvable_id' => $subject->id,
                    'stage' => $stage, 'step_order' => $i + 1, 'round' => $round, 'is_active' => true,
                    'assigned_to' => $settings->approver($stage, $role), 'role' => $role, 'decision' => Decision::Pending,
                    'due_at' => $i === 0 && $hours ? now()->addMinutes((int) round($hours * 60)) : null,
                    'opened_at' => $i === 0 ? now() : null,
                    'snapshot' => ['subject_type' => $subject->getMorphClass(), 'data' => $subject->attributesToArray()],
                ]);
                if ($subject instanceof Invoice) {
                    PaymentStep::create(['invoice_id' => $subject->id, 'approval_id' => $approval->id, 'role' => $role, 'step_order' => $i + 1, 'round' => $round, 'decision' => Decision::Pending]);
                }
                $first ??= $approval;
            }
            ApprovalStepOpened::dispatch($first);
        });
    }

    public function approve(Approval $approval, User $actor, ?string $note = null): void
    {
        $this->decide($approval, Decision::Approved, $actor, $note);
    }

    public function reject(Approval $approval, User $actor, string $reason): void
    {
        $this->requireText($reason, 'A reason is required to reject.');
        $this->decide($approval, Decision::Rejected, $actor, $reason);
    }

    public function returnForCorrection(Approval $approval, User $actor, string $reason): void
    {
        $this->requireText($reason, 'A reason is required to return a submission.');
        $this->decide($approval, Decision::Returned, $actor, $reason);
    }

    public function query(Approval $approval, User $actor, User $target, string $question): ApprovalQuery
    {
        $this->requireText($question, 'A query needs a question.');
        Access::tenant($target);

        return DB::transaction(function () use ($approval, $actor, $target, $question) {
            $approval = $this->lockActionable($approval, $actor);
            if ($target->is($actor) || ! $target->is_active) {
                throw new InvalidArgumentException('Choose another active person in the chain.');
            }
            $targetInChain = $this->chain($approval)->where('role', $target->role)
                ->where(fn (Builder $q) => $q->whereNull('assigned_to')->orWhere('assigned_to', $target->id))->exists();
            if (! $targetInChain) {
                throw new InvalidArgumentException('A query can only be directed to someone in the approval chain.');
            }
            if ($approval->hasOpenQuery()) {
                throw new InvalidArgumentException('This step already has an unanswered query.');
            }
            $query = ApprovalQuery::create(['approval_id' => $approval->id, 'asked_by' => $actor->id, 'directed_to' => $target->id, 'question' => $question]);
            Access::audit($approval->approvable, $actor, 'approval.queried', [], ['query_id' => $query->id]);
            ApprovalQueried::dispatch($query);

            return $query;
        });
    }

    public function answerQuery(ApprovalQuery $query, User $actor, string $answer): void
    {
        $this->requireText($answer, 'An answer cannot be empty.');
        Access::roles($actor, ...Role::cases());
        Access::tenant($query);
        DB::transaction(function () use ($query, $actor, $answer) {
            $query = ApprovalQuery::whereKey($query->id)->lockForUpdate()->firstOrFail();
            if (! $query->isOpen() || ! $query->approval->is_active || ! $this->subjectIsOpen($query->approval)) {
                throw new InvalidArgumentException('This query is no longer open.');
            }
            if (! $actor->role->isAdmin() && $actor->id !== $query->directed_to) {
                throw new AuthorizationException('Only the person the query was directed to can answer it.');
            }
            $query->update(['answer' => $answer, 'answered_by' => $actor->id, 'answered_at' => now()]);
            Access::audit($query->approval->approvable, $actor, 'approval.query_answered', [], ['query_id' => $query->id]);
            ApprovalQueryAnswered::dispatch($query);
        });
    }

    private function decide(Approval $approval, Decision $decision, User $actor, ?string $note): void
    {
        DB::transaction(function () use ($approval, $decision, $actor, $note) {
            $approval = $this->lockActionable($approval, $actor);
            if ($approval->hasOpenQuery()) {
                throw new InvalidArgumentException('This step has an unanswered query; wait for the answer before deciding.');
            }
            $approval->update(['decision' => $decision, 'decided_by' => $actor->id, 'decided_at' => now(), 'note' => $note]);
            PaymentStep::where('approval_id', $approval->id)->update(['decision' => $decision->value]);
            Access::audit($approval->approvable, $actor, 'approval.'.$decision->value, [], ['approval_id' => $approval->id, 'round' => $approval->round], $note);
            if ($decision !== Decision::Approved) {
                $this->chain($approval)->update(['is_active' => false]);
                ApprovalChainStopped::dispatch($approval->approvable, $approval->stage, $decision, $actor, $note);

                return;
            }
            $next = $this->chain($approval)->where('decision', Decision::Pending)->orderBy('step_order')->first();
            if (! $next) {
                $this->chain($approval)->update(['is_active' => false]);
                ApprovalChainCompleted::dispatch($approval->approvable, $approval->stage, $actor);

                return;
            }
            $hours = data_get(TenantContext::get()?->settings, 'approval_sla_hours.'.$approval->stage);
            $next->update(['opened_at' => now()]);
            if ($hours) {
                $next->update(['due_at' => now()->addMinutes((int) round($hours * 60))]);
            }
            ApprovalStepOpened::dispatch($next);
        });
    }

    private function lockActionable(Approval $approval, User $actor): Approval
    {
        Access::tenant($approval);
        Access::roles($actor, $approval->role);
        $subject = $approval->approvable;
        $subject->newQuery()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
        $approval = Approval::whereKey($approval->id)->lockForUpdate()->firstOrFail();
        if (! $approval->is_active || $approval->decision !== Decision::Pending || ! $this->subjectIsOpen($approval)) {
            throw new InvalidArgumentException('This approval is no longer actionable.');
        }
        if (! $actor->role->isAdmin() && $approval->assigned_to && $approval->assigned_to !== $actor->id) {
            throw new AuthorizationException('This step is assigned to another approver.');
        }
        if ($this->chain($approval)->where('step_order', '<', $approval->step_order)->where('decision', '!=', Decision::Approved)->exists()) {
            throw new InvalidArgumentException('An earlier approval step is not approved.');
        }

        return $approval;
    }

    private function chain(Approval $approval): Builder
    {
        return Approval::where('approvable_type', $approval->approvable_type)->where('approvable_id', $approval->approvable_id)
            ->where('stage', $approval->stage)->where('round', $approval->round);
    }

    private function subjectIsOpen(Approval $approval): bool
    {
        $subject = $approval->approvable;

        return match (true) {
            $subject instanceof PurchaseOrder => $subject->status === ($approval->stage === 'cfo' ? Status::PendingCfo : Status::PendingApproval),
            $subject instanceof QuoteEvaluation => $subject->status === 'pending_approval' && $subject->purchaseOrder->status === Status::PendingApproval,
            $subject instanceof Invoice, $subject instanceof Shipment => $subject->status === 'pending_approval',
            default => false,
        };
    }

    private function requireText(string $text, string $message): void
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException($message);
        }
    }
}
